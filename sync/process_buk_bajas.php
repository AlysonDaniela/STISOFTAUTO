<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    echo "Este script debe ejecutarse desde CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../conexion/db.php';
require_once __DIR__ . '/../includes/runtime_config.php';
$db = new clsConexion();

$bukCfg = runtime_buk_config();
define('BUK_API_BASE', $bukCfg['base']);
define('BUK_TOKEN', $bukCfg['token']);
const LOG_DIR = __DIR__ . '/../bajas/logs_buk_terminate';

$REASON_MAP_BY_CODE = [
    1  => 'mutuo_acuerdo',
    2  => 'renuncia',
    3  => 'muerte',
    4  => 'vencimiento_plazo',
    5  => 'vencimiento_plazo',
    6  => 'caso_fortuito',
    11 => 'faltas_seguridad',
    14 => 'necesidades_empresa',
    15 => 'desahucio_gerente',
];
$REASON_MAP_BY_TEXT = [
    'mutuo acuerdo' => 'mutuo_acuerdo',
    'renuncia' => 'renuncia',
    'muerte' => 'muerte',
    'vencimiento' => 'vencimiento_plazo',
    'caso fortuito' => 'caso_fortuito',
    'fuerza mayor' => 'caso_fortuito',
    'faltas seguridad' => 'faltas_seguridad',
    'necesidades de la empresa' => 'necesidades_empresa',
    'desahucio' => 'desahucio_gerente',
];

function ejson(array $data): void {
    echo "SYNC_RESULT=" . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
}

function rut_norm($rut): string {
    $rut = strtoupper(trim((string)$rut));
    return preg_replace('/[^0-9K]/', '', $rut);
}

function date_to_iso($s): ?string {
    $s = trim((string)$s);
    if ($s === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
        $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        return $m[3] . "-{$mo}-{$d}";
    }
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $s, $m)) {
        $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        return $m[3] . "-{$mo}-{$d}";
    }
    return null;
}

function map_reason_to_buk($motivoCode, $motivoDesc, array $byCode, array $byText): ?string {
    $code = null;
    if ($motivoCode !== null && $motivoCode !== '') {
        $tmp = preg_replace('/[^0-9]/', '', (string)$motivoCode);
        if ($tmp !== '') $code = (int)$tmp;
    }
    if ($code !== null && isset($byCode[$code])) return $byCode[$code];

    $t = mb_strtolower(trim((string)$motivoDesc));
    if ($t !== '') {
        foreach ($byText as $needle => $api) {
            if (strpos($t, $needle) !== false) return $api;
        }
    }
    return null;
}

function save_log(string $prefix, array $data): void {
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
    $fn = LOG_DIR . '/' . $prefix . '_' . date('Ymd_His') . '.json';
    file_put_contents($fn, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function buk_terminate_job(int $buk_job_id, array $payload): array {
    $url = BUK_API_BASE . "/jobs/{$buk_job_id}/terminate";
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "PATCH",
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Accept: application/json",
            "auth_token: " . BUK_TOKEN
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => 60
    ]);

    $respBody = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    return ['http' => $http, 'body' => $respBody, 'curl_error' => $curlErr, 'url' => $url];
}

function mark_baja_ok_by_rut(clsConexion $db, string $rutNorm): void {
    $rutEsc = $db->real_escape_string($rutNorm);
    $db->ejecutar("
        UPDATE adp_empleados
        SET `DocDetectaBaja`=2
        WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ',''))='{$rutEsc}'
        LIMIT 1
    ");
}

function parse_error_message($body): string {
    $raw = trim((string)$body);
    if ($raw === '') return 'Sin respuesta de Buk.';
    $j = json_decode($raw, true);
    if (!is_array($j)) return substr($raw, 0, 500);
    if (isset($j['errors'])) return substr(json_encode($j['errors'], JSON_UNESCAPED_UNICODE), 0, 500);
    if (isset($j['message'])) return substr((string)$j['message'], 0, 500);
    return substr($raw, 0, 500);
}

try {
    $rows = $db->consultar("
        SELECT
            Rut, Nombres, Apaterno, Amaterno,
            buk_job_id, buk_emp_id,
            `Estado`, `DocDetectaBaja`,
            `Fecha de Retiro` AS fecha_retiro,
            `Motivo de Retiro` AS motivo_retiro,
            `Descripcion Motivo de Retiro` AS motivo_desc
        FROM adp_empleados
        WHERE `DocDetectaBaja` = 1
        ORDER BY `Fecha de Retiro` DESC
    ") ?: [];

    $requested = count($rows);
    $ok = 0;
    $failed = 0;
    $failedItems = [];
    $okRuts = [];
    $errorRuts = [];

    foreach ($rows as $emp) {
        $rutNorm = rut_norm($emp['Rut'] ?? '');
        $bukJobId = (int)($emp['buk_job_id'] ?? 0);
        $fechaRetiro = date_to_iso($emp['fecha_retiro'] ?? '');
        $reason = map_reason_to_buk(
            $emp['motivo_retiro'] ?? '',
            $emp['motivo_desc'] ?? '',
            $REASON_MAP_BY_CODE,
            $REASON_MAP_BY_TEXT
        );

        if ($rutNorm === '') {
            $failed++;
            $failedItems[] = ['rut' => '', 'stage' => 'PRECHECK', 'http' => 0, 'msg' => 'RUT inválido'];
            continue;
        }

        if ($bukJobId <= 0) {
            $failed++;
            $errorRuts[] = $rutNorm;
            $failedItems[] = ['rut' => $rutNorm, 'stage' => 'PRECHECK', 'http' => 0, 'msg' => 'Sin buk_job_id'];
            continue;
        }

        if (!$fechaRetiro) {
            $failed++;
            $errorRuts[] = $rutNorm;
            $failedItems[] = ['rut' => $rutNorm, 'stage' => 'PRECHECK', 'http' => 0, 'msg' => 'Sin Fecha de Retiro'];
            continue;
        }

        if (!$reason) {
            $failed++;
            $errorRuts[] = $rutNorm;
            $failedItems[] = ['rut' => $rutNorm, 'stage' => 'PRECHECK', 'http' => 0, 'msg' => 'Motivo ADP sin mapeo a Buk'];
            continue;
        }

        $payload = [
            "start_date" => $fechaRetiro,
            "end_date" => $fechaRetiro,
            "termination_reason" => $reason
        ];

        $res = buk_terminate_job($bukJobId, $payload);
        save_log("auto_sync_job{$bukJobId}", [
            'rut' => $rutNorm,
            'buk_job_id' => $bukJobId,
            'payload' => $payload,
            'http' => $res['http'],
            'curl_error' => $res['curl_error'],
            'response' => $res['body'],
            'url' => $res['url'],
        ]);

        if (!empty($res['curl_error'])) {
            $failed++;
            $errorRuts[] = $rutNorm;
            $failedItems[] = ['rut' => $rutNorm, 'stage' => 'API', 'http' => 0, 'msg' => 'cURL: ' . (string)$res['curl_error']];
            continue;
        }

        if ((int)$res['http'] >= 200 && (int)$res['http'] < 300) {
            mark_baja_ok_by_rut($db, $rutNorm);
            $ok++;
            $okRuts[] = $rutNorm;
        } else {
            $failed++;
            $errorRuts[] = $rutNorm;
            $failedItems[] = [
                'rut' => $rutNorm,
                'stage' => 'API',
                'http' => (int)$res['http'],
                'msg' => parse_error_message($res['body']),
            ];
        }
    }

    $result = [
        'status' => ($failed > 0 ? 'error' : 'ok'),
        'tipo' => 'buk_bajas',
        'message' => "Bajas Buk procesadas. Solicitadas: {$requested}, OK: {$ok}, ERROR: {$failed}",
        'requested' => $requested,
        'done' => $ok,
        'failed' => $failed,
        'bajas_buk_ok_ruts' => array_values(array_unique($okRuts)),
        'bajas_buk_error_ruts' => array_values(array_unique($errorRuts)),
        'failed_items' => $failedItems,
    ];

    echo "Proceso Buk bajas finalizado.\n";
    echo $result['message'] . "\n";
    ejson($result);
    exit(($failed > 0) ? 1 : 0);
} catch (Throwable $e) {
    $result = [
        'status' => 'error',
        'tipo' => 'buk_bajas',
        'message' => $e->getMessage(),
        'requested' => 0,
        'done' => 0,
        'failed' => 0,
        'bajas_buk_ok_ruts' => [],
        'bajas_buk_error_ruts' => [],
        'failed_items' => [],
    ];
    ejson($result);
    exit(1);
}

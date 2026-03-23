<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    echo "Este script debe ejecutarse desde CLI.\n";
    exit(1);
}

$filePath = $argv[1] ?? null;
if ($filePath === null || trim((string)$filePath) === '') {
    echo "Uso: php process_vacaciones.php /ruta/al/archivo.txt\n";
    exit(1);
}

if (!is_file($filePath)) {
    echo "SYNC_RESULT=" . json_encode([
        'status' => 'error',
        'tipo' => 'vacaciones',
        'message' => 'Archivo no encontrado: ' . $filePath,
        'file' => basename((string)$filePath),
        'requested' => 0,
        'done' => 0,
        'failed' => 0,
        'missing_buk' => 0,
        'not_found_bd' => 0,
        'invalid_rows' => 0,
        'failed_items' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

require_once __DIR__ . '/../conexion/db.php';
require_once __DIR__ . '/../includes/runtime_config.php';
require_once __DIR__ . '/../vacaciones/lib/adp_vac.php';

$bukCfg = runtime_buk_config();
define('BUK_API_BASE_VAC', $bukCfg['base'] . '/employees');
define('BUK_TOKEN_VAC', $bukCfg['token']);
const BUK_VAC_ATTR_KEY = 'Saldo Vacaciones';
const BUK_VAC_LOG_DIR = __DIR__ . '/../vacaciones/logs_buk_vacaciones';

if (!is_dir(BUK_VAC_LOG_DIR)) {
    @mkdir(BUK_VAC_LOG_DIR, 0775, true);
}

function vac_db(): clsConexion
{
    static $db = null;
    if (!$db) {
        $db = new clsConexion();
    }
    return $db;
}

function vac_esc(string $value): string
{
    return vac_db()->real_escape_string($value);
}

function vac_rut_norm(string $rut): string
{
    $rut = strtoupper(trim($rut));
    return preg_replace('/[^0-9K]/', '', $rut);
}

function vac_save_log(string $prefix, array $data): void
{
    $path = BUK_VAC_LOG_DIR . '/' . $prefix . '_' . date('Ymd_His') . '.json';
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function vac_find_employee_by_rut(string $rutInput): ?array
{
    $rn = vac_rut_norm($rutInput);
    if ($rn === '') {
        return null;
    }

    $sql = "SELECT
                UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ','')) AS rut_norm,
                Rut,
                Nombres,
                Apaterno,
                Amaterno,
                buk_emp_id
            FROM adp_empleados
            WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ','')) = '" . vac_esc($rn) . "'
            LIMIT 1";
    $rows = vac_db()->consultar($sql);
    if (!$rows || empty($rows[0])) {
        return null;
    }

    $row = $rows[0];
    return [
        'rut_norm' => vac_rut_norm((string)($row['rut_norm'] ?? $rn)),
        'rut' => (string)($row['Rut'] ?? ''),
        'nombre' => trim((string)($row['Nombres'] ?? '') . ' ' . (string)($row['Apaterno'] ?? '') . ' ' . (string)($row['Amaterno'] ?? '')),
        'buk_emp_id' => ($row['buk_emp_id'] === null || $row['buk_emp_id'] === '') ? null : (int)$row['buk_emp_id'],
    ];
}

function vac_buk_patch(int $employeeId, float $saldo): array
{
    $url = BUK_API_BASE_VAC . '/' . $employeeId;
    $payload = [
        'custom_attributes' => [
            BUK_VAC_ATTR_KEY => $saldo,
        ],
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_HTTPHEADER => [
            'auth_token: ' . BUK_TOKEN_VAC,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 60,
    ]);

    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => !$err && $http >= 200 && $http < 300,
        'http' => $http,
        'error' => $err ?: null,
        'body' => (string)$resp,
        'sent' => $payload,
    ];
}

try {
    [, $rows] = read_csv_assoc($filePath, ';');
} catch (Throwable $e) {
    echo "SYNC_RESULT=" . json_encode([
        'status' => 'error',
        'tipo' => 'vacaciones',
        'message' => 'No se pudo leer archivo de vacaciones: ' . $e->getMessage(),
        'file' => basename($filePath),
        'requested' => 0,
        'done' => 0,
        'failed' => 0,
        'missing_buk' => 0,
        'not_found_bd' => 0,
        'invalid_rows' => 0,
        'failed_items' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$requested = 0;
$done = 0;
$failed = 0;
$missingBuk = 0;
$notFoundBd = 0;
$invalidRows = 0;
$failedItems = [];

foreach ($rows as $index => $row) {
    $rut = (string)($row['Rut'] ?? $row['RUT'] ?? '');
    $saldo = adp_normalize_decimal($row['Dias habiles (Normales + Progresivos+ Adicionales)'] ?? null);

    if (trim($rut) === '' || $saldo === null) {
        $invalidRows++;
        continue;
    }

    $requested++;
    $employee = vac_find_employee_by_rut($rut);
    if (!$employee) {
        $notFoundBd++;
        $failedItems[] = [
            'rut' => vac_rut_norm($rut),
            'linea' => $index + 2,
            'error' => 'RUT no encontrado en BD',
        ];
        continue;
    }

    if (empty($employee['buk_emp_id'])) {
        $missingBuk++;
        $failedItems[] = [
            'rut' => (string)$employee['rut_norm'],
            'linea' => $index + 2,
            'error' => 'Empleado sin buk_emp_id',
        ];
        continue;
    }

    $res = vac_buk_patch((int)$employee['buk_emp_id'], $saldo);
    vac_save_log('patch_' . (int)$employee['buk_emp_id'], [
        'rut' => $employee['rut_norm'],
        'nombre' => $employee['nombre'],
        'buk_emp_id' => $employee['buk_emp_id'],
        'saldo' => $saldo,
        'http_code' => $res['http'],
        'body' => $res['body'],
        'payload' => $res['sent'],
    ]);

    if (!empty($res['ok'])) {
        $done++;
    } else {
        $failed++;
        $failedItems[] = [
            'rut' => (string)$employee['rut_norm'],
            'linea' => $index + 2,
            'error' => 'Buk HTTP ' . (int)($res['http'] ?? 0) . ($res['error'] ? ' · ' . $res['error'] : ''),
        ];
    }
}

$message = 'Vacaciones procesadas. Detectados: ' . $requested . ', OK: ' . $done . ', ERROR: ' . $failed;
if ($requested === 0) {
    $message = 'Archivo de vacaciones sin filas validas para procesar.';
}

echo "SYNC_RESULT=" . json_encode([
    'status' => $failed > 0 ? 'error' : 'ok',
    'tipo' => 'vacaciones',
    'file' => basename($filePath),
    'requested' => $requested,
    'done' => $done,
    'failed' => $failed,
    'missing_buk' => $missingBuk,
    'not_found_bd' => $notFoundBd,
    'invalid_rows' => $invalidRows,
    'failed_items' => $failedItems,
    'message' => $message,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

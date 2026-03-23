<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    echo "Este script debe ejecutarse desde CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../conexion/db.php';
require_once __DIR__ . '/../includes/runtime_config.php';

$bukCfg = runtime_buk_config();
define('BUK_API_BASE', $bukCfg['base']);
define('BUK_TOKEN', $bukCfg['token']);
const BUK_EMP_CREATE_PATH = '/employees.json';
const BUK_JOB_CREATE_PATH = '/employees/%d/jobs';
const BUK_PLAN_CREATE_PATH = '/employees/%d/plans';
const COMPANY_ID_FOR_JOBS = 1;
const DEFAULT_LOCATION_ID = 407;
const FALLBACK_BOSS_RUT = '15871627-5';
const LOG_DIR = __DIR__ . '/../empleados/logs_buk';
const ATTR_KEY_ACTUAL = 'Sindicato Actual';
const ATTR_KEY_ANTERIOR = 'Sindicato Anterior';

function pick(array $row, array $cands): string {
    foreach ($cands as $c) {
        if (array_key_exists($c, $row) && $row[$c] !== '' && $row[$c] !== null) {
            return (string)$row[$c];
        }
    }
    return '';
}

function norm_txt(?string $s): string {
    $s = trim((string)$s);
    $s = mb_strtoupper($s, 'UTF-8');
    $s = preg_replace('/\s+/', ' ', $s);
    return $s ?: '';
}

function rut_key(?string $rut): string {
    $rut = trim((string)$rut);
    if ($rut === '') return '';
    $rut = preg_replace('/[^0-9kK\-]/', '', $rut);
    return strtoupper($rut);
}

function to_iso(?string $s): ?string {
    if ($s === null) return null;
    $s = trim($s);
    if ($s === '') return null;

    $s = str_replace(['.','/'], '-', $s);
    $s = preg_replace('/\s+/', ' ', $s);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

    foreach (['d-m-Y','d-m-y','Y-m-d','Y/m/d','d/m/Y','d/m/y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt && $dt->format($fmt) === $s) {
            $year = (int)$dt->format('Y');
            if ($year > 1900 && $year < 2100) return $dt->format('Y-m-d');
        }
    }

    $s2 = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?(\.\d+)?$/', '', $s);
    if ($s2 !== null && $s2 !== $s) return to_iso($s2);

    $ts = strtotime($s);
    if ($ts !== false) {
        $year = (int)date('Y', $ts);
        if ($year > 1900 && $year < 2100) return date('Y-m-d', $ts);
    }
    return null;
}

function money_to_int($v): ?int {
    if ($v === null) return null;
    $s = str_replace(['.', ' '], '', (string)$v);
    $s = str_replace(',', '.', $s);
    if ($s === '' || !is_numeric($s)) return null;
    return (int)round((float)$s);
}

function norm_gender(?string $g): ?string {
    $g = mb_strtolower(trim((string)$g), 'UTF-8');
    if ($g === '' || $g === '0') return null;
    if (in_array($g, ['m','masculino','hombre'], true)) return 'M';
    if (in_array($g, ['f','femenino','mujer'], true)) return 'F';
    return null;
}

function norm_payment_period(?string $p): ?string {
    $p = mb_strtolower(trim((string)$p), 'UTF-8');
    if ($p === '') return null;
    if ($p === 'm' || strpos($p, 'mensual') !== false) return 'mensual';
    if ($p === 'q' || strpos($p, 'quincena') !== false) return 'quincenal';
    if ($p === 's' || strpos($p, 'semanal') !== false) return 'semanal';
    return null;
}

function norm_account_type(?string $t): ?string {
    $t = mb_strtolower(trim((string)$t), 'UTF-8');
    if ($t === '') return null;
    if (str_contains($t, 'vista') || str_contains($t, 'rut')) return 'Vista';
    if (str_contains($t, 'corriente') || str_contains($t, 'cte')) return 'Corriente';
    if (str_contains($t, 'ahorro')) return 'Ahorro';
    return null;
}

function norm_bank(?string $raw): ?string {
    if ($raw === null) return null;
    $t = trim($raw);
    if ($t === '' || ctype_digit($t)) return null;

    $u = mb_strtoupper($t, 'UTF-8');
    if (str_contains($u, 'SIN BANCO')) return null;
    if (str_contains($u, 'A. EDWARDS') || str_contains($u, 'EDWARDS')) return 'Banco de Chile';
    if (str_contains($u, 'BBVA')) return 'BBVA';
    if (str_contains($u, 'BCI')) return 'BCI';
    if (str_contains($u, 'BICE')) return 'BICE';
    if (str_contains($u, 'CONSORCIO')) return 'Consorcio';
    if (str_contains($u, 'COOPEUCH')) return 'COOPEUCH';
    if (str_contains($u, 'CORPBANCA')) return 'Corpbanca';
    if (str_contains($u, 'SANTANDER')) return 'Santander';
    if (str_contains($u, 'SCOTIABANK')) return 'Scotiabank';
    if (str_contains($u, 'SECURITY')) return 'Security';
    if (str_contains($u, 'FALABELLA')) return 'Falabella';
    if (str_contains($u, 'RIPLEY')) return 'Ripley';
    if (str_contains($u, 'ITAU') || str_contains($u, 'ITAÚ')) return 'Itau';
    if ((str_contains($u, 'MERCADO') && str_contains($u, 'PAGO')) || str_contains($u, 'MERCADOPAGO')) return 'Mercadopago Emisora S.A.';
    if (str_contains($u, 'ESTADO')) return 'Banco Estado';
    if (str_contains($u, 'BANCO DE CHILE') || $u === 'CHILE' || str_contains($u, ' CHILE')) return 'Banco de Chile';
    if (str_contains($u, 'HSBC')) return 'HSBC';
    if (str_contains($u, 'DEUTSCHE')) return 'Banco Deutsche';
    if (str_contains($u, 'JP MORGAN')) return 'JP Morgan Chase Bank';
    return null;
}

function ensure_logs_dir(): void {
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
}

function save_log(string $type, string $key, $data): string {
    ensure_logs_dir();
    $ts = date('Ymd_His');
    $safeKey = preg_replace('/[^a-zA-Z0-9_\-:.|]/', '_', $key);
    $file = sprintf('%s/%s_%s_%s.json', LOG_DIR, $type, $safeKey, $ts);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $file;
}

function parse_msg(string $body): string {
    $t = trim($body);
    if (substr($t, 0, 15) === 'Error de cURL:') return $t;
    $j = json_decode($body, true);
    if (is_array($j)) {
        if (isset($j['errors'])) return json_encode($j['errors'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (isset($j['message'])) return (string)$j['message'];
        if (isset($j['error'])) return is_string($j['error']) ? $j['error'] : json_encode($j['error'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    return (strlen($body) > 5000) ? substr($body, 0, 5000) : $body;
}

function buk_api_request(string $method, string $path, ?array $payload = null): array {
    $url = rtrim(BUK_API_BASE, '/') . $path;

    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return [
                'ok' => false,
                'code' => 0,
                'error' => 'JSON_ENCODE_FAIL',
                'body' => 'JSON_ENCODE_FAIL: ' . json_last_error_msg(),
                'headers' => [],
                'url' => $url,
                'variant' => 'json_fail',
            ];
        }
    }

    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'Accept-Language: es',
            'auth_token: ' . BUK_TOKEN,
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
    ];

    $method = strtoupper($method);
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $body ?? '{}';
    } elseif ($method === 'PATCH') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'PATCH';
        $opts[CURLOPT_POSTFIELDS] = $body ?? '{}';
    } elseif ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($err) {
        return [
            'ok' => false,
            'code' => 0,
            'error' => $err,
            'body' => 'Error de cURL: ' . $err,
            'headers' => [],
            'url' => $url,
            'variant' => 'curl_error',
        ];
    }

    $rawHeaders = substr((string)$raw, 0, $headerSize);
    $respBody = substr((string)$raw, $headerSize);
    $headersOut = [];
    foreach (preg_split("/\r\n\r\n|\n\n|\r\r/", trim($rawHeaders)) as $block) {
        $lines = preg_split("/\r\n|\n|\r/", trim($block));
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $headersOut[trim($k)] = trim($v);
            }
        }
    }

    $ok = ($code >= 200 && $code < 300);
    return [
        'ok' => $ok,
        'code' => $code,
        'error' => null,
        'body' => ($respBody !== false ? $respBody : ''),
        'headers' => $headersOut,
        'url' => $url,
        'variant' => $ok ? 'ok' : 'http_error',
    ];
}

function header_value(array $headers, string $name): ?string {
    foreach ($headers as $k => $v) {
        if (strcasecmp($k, $name) === 0) return $v;
    }
    return null;
}

function plan_id_from_location(?string $location): int {
    if (!$location) return 0;
    if (preg_match('~/plans/(\d+)~', $location, $m)) return (int)$m[1];
    return 0;
}

function find_plan_id_in_list(array $listJson, array $needle): int {
    $items = [];
    if (isset($listJson['data']) && is_array($listJson['data'])) {
        $items = array_values($listJson['data']);
        if (isset($listJson['data']['plans']) && is_array($listJson['data']['plans'])) {
            $items = $listJson['data']['plans'];
        }
    } elseif (is_array($listJson)) {
        $items = $listJson;
    }

    $keys = ['start_date','pension_scheme','fund_quote','health_company','afc','disability','invalidity','retired'];
    foreach ($items as $p) {
        if (!is_array($p)) continue;
        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) continue;

        $ok = true;
        foreach ($keys as $k) {
            if (!array_key_exists($k, $needle)) continue;
            if (!array_key_exists($k, $p) || (string)$p[$k] !== (string)$needle[$k]) {
                $ok = false;
                break;
            }
        }
        if ($ok) return $id;
    }
    return 0;
}

function build_sindicato_custom_attrs(array $r): array {
    $actual = trim(pick($r, ['Sindicato Actual', 'Descripción Categoria', 'Descripcion Categoria', 'Sindicato', 'sindicato_actual', 'sindicato']));
    $anterior = trim(pick($r, ['Sindicato Anterior', 'Descripción Sindicato', 'Descripcion Sindicato', 'sindicato_anterior']));
    $attrs = [];
    if ($actual !== '') $attrs[ATTR_KEY_ACTUAL] = $actual;
    if ($anterior !== '') $attrs[ATTR_KEY_ANTERIOR] = $anterior;
    return $attrs;
}

function buk_patch_custom_attributes(int $employee_id, array $customAttrs): array {
    return buk_api_request('PATCH', sprintf('/employees/%d.json', $employee_id), [
        'custom_attributes' => $customAttrs,
    ]);
}

function table_has_column(clsConexion $db, string $table, string $col): bool {
    $tableEsc = $db->real_escape_string($table);
    $res = $db->consultar("SHOW COLUMNS FROM `{$tableEsc}` LIKE '".$db->real_escape_string($col)."'");
    return !empty($res);
}

function load_employee_row_by_rut(clsConexion $db, string $rut): ?array {
    $rutEsc = $db->real_escape_string($rut);
    $rows = $db->consultar("SELECT * FROM adp_empleados WHERE Rut = '{$rutEsc}' LIMIT 1");
    return (is_array($rows) && !empty($rows[0]) && is_array($rows[0])) ? $rows[0] : null;
}

function load_employee_min_by_rut(clsConexion $db, string $rut): ?array {
    $rutEsc = $db->real_escape_string($rut);
    $rows = $db->consultar("
        SELECT Rut, Estado, buk_emp_id
        FROM adp_empleados
        WHERE Rut = '{$rutEsc}'
        LIMIT 1
    ");
    return (is_array($rows) && !empty($rows[0]) && is_array($rows[0])) ? $rows[0] : null;
}

function load_area_map_from_db(clsConexion $db): array {
    $table = 'buk_jerarquia';
    $colNivel = table_has_column($db, $table, 'nivel') ? 'nivel' : (table_has_column($db, $table, 'profundidad') ? 'profundidad' : null);
    $colCodigo = table_has_column($db, $table, 'codigo_adp') ? 'codigo_adp' : (table_has_column($db, $table, 'unidad_adp') ? 'unidad_adp' : null);
    $colEstado = table_has_column($db, $table, 'estado') ? 'estado' : null;
    $colBukId = table_has_column($db, $table, 'buk_area_id') ? 'buk_area_id' : (table_has_column($db, $table, 'id_buk_area') ? 'id_buk_area' : null);
    if (!$colNivel || !$colCodigo || !$colBukId) return [];

    $nivelUnidad = ($colNivel === 'profundidad') ? 2 : 3;
    $where = "WHERE `{$colBukId}` IS NOT NULL AND `{$colBukId}` > 0 AND `{$colNivel}` = " . (int)$nivelUnidad;
    if ($colEstado) $where .= " AND `{$colEstado}`='mapeado'";
    if (table_has_column($db, $table, 'tipo_origen_adp')) $where .= " AND tipo_origen_adp='unidad'";

    $rows = $db->consultar("SELECT `{$colCodigo}` AS codigo_unidad, `{$colBukId}` AS buk_area_id FROM `{$table}` {$where}");
    $map = [];
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $u = (int)($r['codigo_unidad'] ?? 0);
            $b = (int)($r['buk_area_id'] ?? 0);
            if ($u > 0 && $b > 0) $map[$u] = $b;
        }
    }
    return $map;
}

function load_role_map_from_db(clsConexion $db): array {
    $rows = $db->consultar("
        SELECT cargo_adp_id, buk_role_id
        FROM stisoft_mapeo_cargos
        WHERE estado='mapeado' AND buk_role_id IS NOT NULL
    ");
    $map = [];
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $c = (int)($r['cargo_adp_id'] ?? 0);
            $b = (int)($r['buk_role_id'] ?? 0);
            if ($c > 0 && $b > 0) $map[$c] = $b;
        }
    }
    return $map;
}

function map_area_id_from_row_db(array $r, array $areaMap): ?int {
    $unidad = (int)pick($r, ['Unidad', 'unidad']);
    return $unidad > 0 ? ($areaMap[$unidad] ?? null) : null;
}

function map_role_id_from_row_db(array $r, array $roleMap): ?int {
    $cargoId = (int)pick($r, ['Cargo', 'cargo']);
    return $cargoId > 0 ? ($roleMap[$cargoId] ?? null) : null;
}

function map_empresa_sistema_a_buk(?int $empresaSistema): ?int {
    if ($empresaSistema === null) return null;
    if ($empresaSistema === 3) return 2;
    if ($empresaSistema === 2) return 3;
    if ($empresaSistema === 1) return 1;
    return null;
}

function resolver_company_id_buk(?int $empresaAdp, $credencial): ?int {
    if ($empresaAdp === null) return null;
    if ((int)$empresaAdp === 101) {
        $c = is_numeric($credencial) ? (int)$credencial : null;
        return map_empresa_sistema_a_buk($c);
    }
    return map_empresa_sistema_a_buk((int)$empresaAdp);
}

function db_set_company_buk(clsConexion $db, string $rut, ?int $bukCompanyId, string $estado): void {
    if (!table_has_column($db, 'adp_empleados', 'buk_company_id')) return;
    if (!table_has_column($db, 'adp_empleados', 'company_buk')) return;

    $rutEsc = $db->real_escape_string($rut);
    $bukVal = ($bukCompanyId === null) ? 'NULL' : (string)(int)$bukCompanyId;
    $estadoEsc = $db->real_escape_string($estado);
    $db->ejecutar("
        UPDATE adp_empleados
        SET buk_company_id = {$bukVal},
            company_buk = '{$estadoEsc}'
        WHERE Rut = '{$rutEsc}'
        LIMIT 1
    ");
}

function estado_buk_allowed_values(clsConexion $db): array {
    static $cache = null;
    if (is_array($cache)) return $cache;

    $rows = $db->consultar("SHOW COLUMNS FROM adp_empleados LIKE 'estado_buk'");
    if (!is_array($rows) || empty($rows[0]['Type'])) {
        $cache = [];
        return $cache;
    }

    $type = (string)$rows[0]['Type'];
    if (!preg_match('/^enum\((.*)\)$/i', $type, $m)) {
        $cache = [];
        return $cache;
    }

    $vals = str_getcsv($m[1], ',', "'", "\\");
    $cache = array_values(array_filter(array_map('trim', $vals), fn($v) => $v !== ''));
    return $cache;
}

function estado_buk_pick(clsConexion $db, string $preferred, array $fallback = []): string {
    $allowed = estado_buk_allowed_values($db);
    if (empty($allowed)) return $preferred;
    if (in_array($preferred, $allowed, true)) return $preferred;
    foreach ($fallback as $f) {
        if (in_array($f, $allowed, true)) return $f;
    }
    return $allowed[0];
}

function db_mark_emp_attr_error(clsConexion $db, string $rut): void {
    $rutEsc = $db->real_escape_string($rut);
    $estado = $db->real_escape_string(
        estado_buk_pick($db, 'emp_attr_error', ['emp_error', 'emp_ok_sin_job', 'no_enviado'])
    );
    $db->ejecutar("UPDATE adp_empleados SET estado_buk='{$estado}' WHERE Rut='{$rutEsc}'");
}

function db_mark_emp_ok(clsConexion $db, string $rut, int $bukEmpId): void {
    $rutEsc = $db->real_escape_string($rut);
    $estado = $db->real_escape_string(
        estado_buk_pick($db, 'emp_ok_sin_job', ['no_enviado', 'completo'])
    );
    $db->ejecutar("
        UPDATE adp_empleados
        SET buk_emp_id=" . (int)$bukEmpId . ",
            ficha_buk='ok',
            estado_buk='{$estado}'
        WHERE Rut='{$rutEsc}'
    ");
}

function db_mark_emp_error(clsConexion $db, string $rut): void {
    $rutEsc = $db->real_escape_string($rut);
    $estado = $db->real_escape_string(
        estado_buk_pick($db, 'emp_error', ['no_enviado', 'emp_ok_sin_job'])
    );
    $db->ejecutar("
        UPDATE adp_empleados
        SET ficha_buk='error',
            estado_buk='{$estado}'
        WHERE Rut='{$rutEsc}'
    ");
}

function db_mark_plan_ok(clsConexion $db, string $rut, ?int $bukPlanId = null): void {
    $rutEsc = $db->real_escape_string($rut);
    $planVal = ($bukPlanId && $bukPlanId > 0) ? (string)(int)$bukPlanId : 'NULL';
    $stateComplete = $db->real_escape_string(estado_buk_pick($db, 'completo', ['emp_ok_sin_job', 'no_enviado']));
    $statePlanOk = $db->real_escape_string(estado_buk_pick($db, 'emp_plan_ok', ['emp_ok_sin_job', 'no_enviado']));
    $db->ejecutar("
        UPDATE adp_empleados
        SET buk_plan_id={$planVal},
            plan_buk='ok',
            estado_buk = CASE
                WHEN estado_buk = '{$stateComplete}' THEN '{$stateComplete}'
                ELSE '{$statePlanOk}'
            END
        WHERE Rut='{$rutEsc}'
    ");
}

function db_mark_plan_error(clsConexion $db, string $rut): void {
    $rutEsc = $db->real_escape_string($rut);
    $stateComplete = $db->real_escape_string(estado_buk_pick($db, 'completo', ['emp_ok_sin_job', 'no_enviado']));
    $statePlanError = $db->real_escape_string(estado_buk_pick($db, 'emp_plan_error', ['emp_error', 'emp_ok_sin_job', 'no_enviado']));
    $db->ejecutar("
        UPDATE adp_empleados
        SET plan_buk='error',
            estado_buk = CASE
                WHEN estado_buk = '{$stateComplete}' THEN '{$stateComplete}'
                ELSE '{$statePlanError}'
            END
        WHERE Rut='{$rutEsc}'
    ");
}

function db_mark_job_ok(clsConexion $db, string $rut, ?int $bukJobId, ?int $bukCargoId): void {
    $rutEsc = $db->real_escape_string($rut);
    $jobVal = ($bukJobId && $bukJobId > 0) ? (string)(int)$bukJobId : 'NULL';
    $cargoVal = ($bukCargoId && $bukCargoId > 0) ? (string)(int)$bukCargoId : 'NULL';
    $stateComplete = $db->real_escape_string(estado_buk_pick($db, 'completo', ['emp_ok_sin_job', 'no_enviado']));
    $db->ejecutar("
        UPDATE adp_empleados
        SET buk_job_id={$jobVal},
            buk_cargo_id={$cargoVal},
            job_buk='ok',
            estado_buk='{$stateComplete}'
        WHERE Rut='{$rutEsc}'
    ");
}

function db_mark_job_error(clsConexion $db, string $rut): void {
    $rutEsc = $db->real_escape_string($rut);
    $stateComplete = $db->real_escape_string(estado_buk_pick($db, 'completo', ['emp_ok_sin_job', 'no_enviado']));
    $statePlanOk = $db->real_escape_string(estado_buk_pick($db, 'emp_plan_ok', ['emp_ok_sin_job', 'no_enviado']));
    $statePlanJobError = $db->real_escape_string(estado_buk_pick($db, 'emp_plan_ok_job_error', ['emp_ok_job_error', 'emp_error', 'emp_ok_sin_job']));
    $stateEmpJobError = $db->real_escape_string(estado_buk_pick($db, 'emp_ok_job_error', ['emp_error', 'emp_ok_sin_job', 'no_enviado']));
    $db->ejecutar("
        UPDATE adp_empleados
        SET job_buk='error',
            estado_buk = CASE
                WHEN estado_buk = '{$stateComplete}' THEN '{$stateComplete}'
                WHEN estado_buk = '{$statePlanOk}' THEN '{$statePlanJobError}'
                ELSE '{$stateEmpJobError}'
            END
        WHERE Rut='{$rutEsc}'
    ");
}

function db_mark_skip_mapping(clsConexion $db, string $rut): void {
    $rutEsc = $db->real_escape_string($rut);
    $estado = $db->real_escape_string(estado_buk_pick($db, 'emp_ok_sin_job', ['no_enviado', 'completo']));
    $db->ejecutar("UPDATE adp_empleados SET estado_buk='{$estado}' WHERE Rut='{$rutEsc}'");
}

function resolve_leader_id_for_row(clsConexion $db, array $empRow): array {
    $bossRutRaw = trim((string)($empRow['Jefe'] ?? ''));
    $bossRut = $bossRutRaw;

    if ($bossRut === '') {
        $bossRut = FALLBACK_BOSS_RUT;
        $bossRow = load_employee_min_by_rut($db, $bossRut);
        if (!$bossRow || (int)($bossRow['buk_emp_id'] ?? 0) <= 0) {
            return ['ok' => false, 'leader_id' => null, 'msg' => 'Jefe vacío y sin jefe comodín creado en Buk.'];
        }
        return ['ok' => true, 'leader_id' => (int)$bossRow['buk_emp_id'], 'msg' => 'Jefe vacío: usando jefe comodín.'];
    }

    $bossRow = load_employee_min_by_rut($db, $bossRut);
    if (!$bossRow) {
        $fallbackRow = load_employee_min_by_rut($db, FALLBACK_BOSS_RUT);
        if (!$fallbackRow || (int)($fallbackRow['buk_emp_id'] ?? 0) <= 0) {
            return ['ok' => false, 'leader_id' => null, 'msg' => "Jefe {$bossRutRaw} no existe en BD y no hay jefe comodín listo."];
        }
        return ['ok' => true, 'leader_id' => (int)$fallbackRow['buk_emp_id'], 'msg' => "Jefe {$bossRutRaw} no existe: usando jefe comodín."];
    }

    if ((string)($bossRow['Estado'] ?? '') !== 'A') {
        $fallbackRow = load_employee_min_by_rut($db, FALLBACK_BOSS_RUT);
        if (!$fallbackRow || (int)($fallbackRow['buk_emp_id'] ?? 0) <= 0) {
            return ['ok' => false, 'leader_id' => null, 'msg' => "Jefe {$bossRutRaw} inactivo y sin jefe comodín listo."];
        }
        return ['ok' => true, 'leader_id' => (int)$fallbackRow['buk_emp_id'], 'msg' => "Jefe {$bossRutRaw} inactivo: usando jefe comodín."];
    }

    $bossBukId = (int)($bossRow['buk_emp_id'] ?? 0);
    if ($bossBukId <= 0) {
        return ['ok' => false, 'leader_id' => null, 'msg' => "Jefe {$bossRutRaw} está activo pero sin buk_emp_id."];
    }

    return ['ok' => true, 'leader_id' => $bossBukId, 'msg' => 'Jefe activo y creado en Buk.'];
}

function build_employee_payload(array $r): array {
    $first = pick($r, ['Nombres','Nombre','Primer Nombre']);
    $sur1 = pick($r, ['Apaterno','Apellido Paterno','Apellido1']);
    $sur2 = pick($r, ['Amaterno','Apellido Materno','Apellido2']);
    $rut = pick($r, ['Rut','RUT','Documento','documento']);
    $birthday = to_iso(pick($r, ['Fecha Nacimiento','Fecha de Nacimiento','birthday','Fec_Nac']));
    $start_date = to_iso(pick($r, ['Fecha de Ingreso','Fec_Ingreso','start_date','active_since'])) ?: date('Y-m-d');
    $estado = strtolower((string)pick($r, ['Estado','status']));
    $active = in_array($estado, ['s','1','activo','a'], true) ? 'active' : 'inactive';

    $email = pick($r, ['Mail','Email','Correo','email']);
    $pemail = pick($r, ['personal_email','Email Personal','Correo Personal']);
    if ($email !== '' && strtolower($email) === 'correo@empresa.cl') {
        $email = 'correo' . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT) . '@empresa.cl';
    }

    $street = pick($r, ['calle','Calle','street']);
    $street_number = pick($r, ['numero','Nro','Numero','Número','street_number']);
    $address = trim(pick($r, ['Direccion','Dirección','address']));
    if ($address === '') $address = trim($street . ' ' . $street_number);

    $bank = norm_bank(pick($r, [
        'Descripcion Banco fpago1','Descripción Banco fpago1','Banco Fpago1',
        'Descripcion Banco fpago2','Descripción Banco fpago2','Banco Fpago2',
        'bank_name','Banco'
    ]));
    $acct_n = trim(pick($r, [
        'Cuenta Corriente fpago1','Cuenta Corriente',
        'Cuenta Interbancaria fpago1','Cuenta Interbancaria',
        'account_number','num_cuenta','N° Cuenta','Numero Cuenta','Número Cuenta','Cuenta'
    ]));
    $acc_type_raw = pick($r, ['Tipo Cuenta fpago1','Tipo de Cuenta','account_type']);
    if (trim($acc_type_raw) === '') {
        $acc_type_raw = pick($r, ['Descripcion Forma de Pago 1', 'Descripción Forma de Pago 1', 'Descripcion Forma', 'Descripción Forma']);
    }
    $acc_type = norm_account_type($acc_type_raw);
    $pperiod = norm_payment_period(pick($r, ['Regimen de Pago','payment_period','Régimen de Pago','Codigo de Regimen'])) ?? 'mensual';
    $hasBankData = (!empty($bank) && !empty($acc_type) && !empty($acct_n));

    $payload = [
        'first_name' => $first,
        'surname' => $sur1,
        'second_surname' => $sur2 ?: null,
        'document_type' => 'rut',
        'document_number' => $rut,
        'code_sheet' => pick($r, ['Codigo','code_sheet','Ficha','Código Ficha']) ?: null,
        'nationality' => 'CL',
        'gender' => norm_gender(pick($r, ['Sexo','gender','Genero','Género'])) ?? 'M',
        'birthday' => $birthday,
        'email' => $email ?: ($pemail ?: null),
        'personal_email' => $pemail ?: null,
        'location_id' => DEFAULT_LOCATION_ID,
        'address' => $address !== '' ? $address : 'Sin dirección',
        'street' => $street ?: null,
        'street_number' => $street_number ?: null,
        'office_number' => pick($r, ['depto','office_number','Depto','Departamento']) ?: null,
        'city' => pick($r, ['ciudad','city','Ciudad']) ?: null,
        'payment_currency' => 'CLP',
        'payment_method' => $hasBankData ? 'Transferencia Bancaria' : 'No Generar Pago',
        'payment_period' => $pperiod,
        'advance_payment' => 'sin_anticipo',
        'start_date' => $start_date,
        'active' => $active,
    ];

    if ($hasBankData) {
        $payload['bank'] = $bank;
        $payload['account_type'] = $acc_type;
        $payload['account_number'] = $acct_n;
    }

    return $payload;
}

function plan_norm_upper(?string $s): string {
    $s = trim((string)$s);
    $s = mb_strtoupper($s, 'UTF-8');
    $s = preg_replace('/\s+/', ' ', $s);
    return $s ?: '';
}

function plan_map_pension_scheme(array $r): string {
    $afp = plan_norm_upper(pick($r, ['Descripcion AFP','Descripción AFP','AFP','afp']));
    $cod = trim(pick($r, ['Codigo AFP','Código AFP','cod_afp']));
    if ($afp === '' && $cod === '') return 'no_cotiza';
    if ((str_contains($afp, 'SIN') && str_contains($afp, 'AFP')) || str_contains($afp, 'NO COTIZA')) return 'no_cotiza';
    if (str_contains($afp, 'I.N.P') || str_contains($afp, 'INP') || str_contains($afp, 'EMPART') || str_contains($afp, 'SERVICIOS DE SEGURO SOCIAL') || str_contains($afp, 'CAPREMER') || str_contains($afp, 'TRIOMAR')) return 'ips';
    foreach (['CAPITAL','CUPRUM','HABITAT','MODELO','PLANVITAL','PROVIDA','UNO'] as $x) {
        if (str_contains($afp, $x)) return 'afp';
    }
    return 'no_cotiza';
}

function plan_map_fund_quote(array $r, string $pension_scheme): ?string {
    if (!in_array($pension_scheme, ['afp', 'ips'], true)) return null;
    $afp = plan_norm_upper(pick($r, ['Descripcion AFP','Descripción AFP','AFP','afp']));
    $map = [
        'CAPITAL' => 'capital',
        'CUPRUM' => 'cuprum',
        'HABITAT' => 'habitat',
        'MODELO' => 'modelo',
        'PLANVITAL' => 'planvital',
        'PLAN VITAL' => 'planvital',
        'PROVIDA' => 'provida',
        'PRO VIDA' => 'provida',
        'UNO' => 'uno',
        'CAPREMER' => 'capremer_regimen_1',
        'TRIOMAR' => 'triomar_regimen_1',
        'EMPART' => 'empart_regimen_1',
    ];
    foreach ($map as $needle => $value) {
        if (str_contains($afp, $needle)) return $value;
    }
    return null;
}

function plan_map_health_company(array $r): string {
    $isa = plan_norm_upper(pick($r, ['Descripcion Isapre','Descripción Isapre','Isapre','isapre','Descripcion Salud','Descripción Salud']));
    if ($isa === '' || $isa === '0') return 'no_cotiza_salud';
    if (str_contains($isa, 'FONASA')) return 'fonasa';
    if (str_contains($isa, 'BANMEDICA') || str_contains($isa, 'BANMÉDICA')) return 'banmedica';
    if (str_contains($isa, 'COLMENA')) return 'colmena';
    if (str_contains($isa, 'CONSALUD')) return 'consalud';
    if (str_contains($isa, 'CRUZ BLANCA')) return 'cruz_blanca';
    if (str_contains($isa, 'MASVIDA') || str_contains($isa, 'MÁS VIDA') || str_contains($isa, 'MAS VIDA')) return 'nueva_masvida';
    if (str_contains($isa, 'VIDA TRES')) return 'vida_tres';
    if (str_contains($isa, 'BANCO ESTADO') || str_contains($isa, 'BANCOESTADO')) return 'banco_estado';
    return 'no_cotiza_salud';
}

function plan_map_afc(array $r): string {
    return to_iso(pick($r, ['Fecha Seguro Cesantía','Fecha Seguro Cesantia','Fecha Seguro Cesantía (AFC)','fecha_afc'])) ? 'normal' : 'no_cotiza';
}

function plan_map_retired(array $r): bool {
    $j = mb_strtolower(trim(pick($r, ['Jubilado','jubilado'])), 'UTF-8');
    return in_array($j, ['s','si','sí','1','true','y','yes'], true);
}

function build_plan_payload(array $r): array {
    $pension_scheme = plan_map_pension_scheme($r);
    $fund_quote = plan_map_fund_quote($r, $pension_scheme);
    $payload = [
        'pension_scheme' => $pension_scheme,
        'health_company' => plan_map_health_company($r),
        'afc' => plan_map_afc($r),
        'retired' => plan_map_retired($r),
        'disability' => false,
        'invalidity' => 'no',
        'youth_employment_subsidy' => false,
        'foreign_technician' => false,
        'quote_increase_one_percent' => false,
    ];
    if ($fund_quote) $payload['fund_quote'] = $fund_quote;
    if ($pension_scheme === 'ips' && ($payload['afc'] ?? null) !== 'no_cotiza' && $fund_quote) {
        $afpQuotes = ['capital','cuprum','habitat','modelo','planvital','provida','uno'];
        if (in_array($fund_quote, $afpQuotes, true)) $payload['afp_collector'] = 'recauda_' . $fund_quote;
    }
    return $payload;
}

function build_job_payload(array $r, int $employeeId, int $leaderId, array $areaMap, array $roleMap): array {
    $areaId = map_area_id_from_row_db($r, $areaMap);
    $roleId = map_role_id_from_row_db($r, $roleMap);
    $start_date = to_iso(pick($r, ['start_date','Fecha Inicio','Fecha de Ingreso','Fecha Ingreso','Fecha Contrato'])) ?? date('Y-m-d');
    $rawSituacion = norm_txt(pick($r, ['Descripcion Situacion Laboral', 'Descripción Situación Laboral']));
    $typeContract = 'Indefinido';
    $project = null;

    if (str_contains($rawSituacion, 'CONTRATO A PLAZO FIJO')) {
        $typeContract = 'Plazo fijo';
    } elseif (str_contains($rawSituacion, 'RENOVACION AUTOMATICA') || str_contains($rawSituacion, 'RENOVACIÓN AUTOMÁTICA')) {
        $typeContract = 'Renovación Automática';
    } elseif (str_contains($rawSituacion, 'EVENTUAL CON CPPT')) {
        $typeContract = 'Obra';
        $project = 'Eventual con Cppt';
    }

    $end_of_contract = to_iso(pick($r, ['Fecha de Retiro', 'Fecha Retiro', 'Fec_Retiro']));
    if ($end_of_contract && $start_date && $end_of_contract < $start_date) $end_of_contract = null;
    $requiresEnd = in_array($typeContract, ['Plazo fijo', 'Renovación Automática'], true);

    $rawHorario = norm_txt(pick($r, ['Descripcion Horario', 'Descripción Horario']));
    $typeWorking = 'ordinaria_art_22';
    $otherWorking = null;
    if ($rawHorario !== '' && $rawHorario !== 'NULL') {
        if (str_contains($rawHorario, 'TURNOS ROTATIVOS')) {
            $typeWorking = 'otros';
            $otherWorking = 'especial_art_38_inc_6';
        } elseif (str_contains($rawHorario, 'ART.22') || str_contains($rawHorario, 'ART 22') || str_contains($rawHorario, 'ART. 22')) {
            $typeWorking = 'exenta_art_22';
        } elseif (str_contains($rawHorario, 'ADMINISTRATIVO')) {
            $typeWorking = 'ordinaria_art_22';
        }
    }

    $salary_liq = money_to_int(pick($r, ['Sueldo Líquido','Sueldo Liquido','Liquido','Sueldo']));
    $salary_gross = money_to_int(pick($r, ['Sueldo Bruto','Bruto','Renta Bruta','Renta']));
    $wage = $salary_gross ?: $salary_liq ?: 0;
    $empresaAdp = is_numeric(pick($r, ['Empresa'])) ? (int)pick($r, ['Empresa']) : null;
    $bukCompanyId = resolver_company_id_buk($empresaAdp, pick($r, ['Credencial'])) ?: COMPANY_ID_FOR_JOBS;

    $payload = [
        '__mapped_ok' => (bool)($areaId && $roleId),
        '__map_area_id' => $areaId,
        '__map_role_id' => $roleId,
        'company_id' => (int)$bukCompanyId,
        'location_id' => (int)DEFAULT_LOCATION_ID,
        'employee_id' => (int)$employeeId,
        'project' => $project,
        'start_date' => $start_date,
        'type_of_contract' => $typeContract,
        'periodicity' => 'mensual',
        'regular_hours' => 44,
        'type_of_working_day' => $typeWorking,
        'area_id' => (int)$areaId,
        'role_id' => (int)$roleId,
        'leader_id' => (int)$leaderId,
        'wage' => (int)$wage,
        'currency' => 'peso',
        '__raw_situacion_laboral' => pick($r, ['Descripcion Situacion Laboral', 'Descripción Situación Laboral']),
        '__raw_horario' => pick($r, ['Descripcion Horario', 'Descripción Horario']),
        '__other_type_of_working_day' => $otherWorking,
    ];

    if ($requiresEnd && $end_of_contract) $payload['end_of_contract'] = $end_of_contract;
    return $payload;
}

function run_pipeline_all(clsConexion $db, array $rowFull, array $AREA_MAP, array $ROLE_MAP, string $mode = 'bulk'): array {
    ensure_logs_dir();

    $rut = trim(pick($rowFull, ['Rut', 'RUT']));
    $key = $rut !== '' ? $rut : ('ROW_' . md5(json_encode($rowFull)));
    $existingEmpId = (int)($rowFull['buk_emp_id'] ?? 0);
    $empId = $existingEmpId > 0 ? $existingEmpId : 0;

    $out = [
        'ok' => false,
        'stage' => null,
        'http' => 0,
        'msg' => '',
        'emp' => ['ok' => false, 'id' => $empId],
        'plan' => ['ok' => false, 'id' => null],
        'job' => ['ok' => false, 'id' => null, 'skip' => false],
    ];

    if ($empId <= 0) {
        $empPayload = build_employee_payload($rowFull);
        $resEmp = buk_api_request('POST', BUK_EMP_CREATE_PATH, $empPayload);
        if (!$resEmp['ok']) {
            db_mark_emp_error($db, $rut);
            save_log('bulk_fail_emp_payload', $key, $empPayload);
            save_log('bulk_fail_emp_response', $key, $resEmp);
            return ['ok' => false, 'stage' => 'EMP', 'http' => (int)$resEmp['code'], 'msg' => parse_msg($resEmp['body']) . ' [' . $resEmp['variant'] . ']'];
        }

        $j = json_decode($resEmp['body'], true);
        $empId = (int)($j['data']['id'] ?? 0);
        if ($empId <= 0) {
            db_mark_emp_error($db, $rut);
            save_log('bulk_fail_emp_response_noid', $key, $resEmp);
            return ['ok' => false, 'stage' => 'EMP', 'http' => (int)$resEmp['code'], 'msg' => 'EMP OK pero sin data.id'];
        }

        db_mark_emp_ok($db, $rut, $empId);
        $out['emp'] = ['ok' => true, 'id' => $empId];
    } else {
        $out['emp'] = ['ok' => true, 'id' => $empId];
    }

    $attrsSindicato = build_sindicato_custom_attrs($rowFull);
    if (!empty($attrsSindicato) && $empId > 0) {
        $resAttr = buk_patch_custom_attributes($empId, $attrsSindicato);
        if (!$resAttr['ok']) {
            db_mark_emp_attr_error($db, $rut);
            save_log('bulk_fail_emp_attr_payload', $key, $attrsSindicato);
            save_log('bulk_fail_emp_attr_response', $key, $resAttr);
            return ['ok' => false, 'stage' => 'EMP_ATTR', 'http' => (int)$resAttr['code'], 'msg' => parse_msg($resAttr['body']) . ' [' . $resAttr['variant'] . ']'];
        }
    }

    $planPayload = build_plan_payload($rowFull);
    $resPlan = buk_api_request('POST', sprintf(BUK_PLAN_CREATE_PATH, $empId), $planPayload);
    if (!$resPlan['ok']) {
        db_mark_plan_error($db, $rut);
        save_log('bulk_fail_plan_payload', $key, $planPayload);
        save_log('bulk_fail_plan_response', $key, $resPlan);
        return ['ok' => false, 'stage' => 'PLAN', 'http' => (int)$resPlan['code'], 'msg' => parse_msg($resPlan['body']) . ' [' . $resPlan['variant'] . ']'];
    }

    $location = header_value($resPlan['headers'] ?? [], 'Location');
    $planId = plan_id_from_location($location);
    if ($planId <= 0) {
        $resList = buk_api_request('GET', "/employees/{$empId}/plans", null);
        if (!empty($resList['ok'])) {
            $listJson = json_decode((string)($resList['body'] ?? ''), true);
            if (is_array($listJson)) $planId = find_plan_id_in_list($listJson, $planPayload);
        }
    }

    db_mark_plan_ok($db, $rut, $planId > 0 ? $planId : null);
    $out['plan'] = ['ok' => true, 'id' => $planId > 0 ? $planId : null];

    $leaderRes = resolve_leader_id_for_row($db, $rowFull);
    if (!$leaderRes['ok']) {
        save_log('bulk_fail_leader_resolve', $key, $leaderRes);
        return ['ok' => false, 'stage' => 'LEADER', 'http' => 0, 'msg' => $leaderRes['msg'] ?? 'No se pudo resolver leader_id'];
    }

    $leaderId = (int)$leaderRes['leader_id'];
    $jobPayload = build_job_payload($rowFull, $empId, $leaderId, $AREA_MAP, $ROLE_MAP);
    $bukCompanyIdForDb = isset($jobPayload['company_id']) ? (int)$jobPayload['company_id'] : null;
    db_set_company_buk($db, $rut, $bukCompanyIdForDb ?: null, $bukCompanyIdForDb ? 'pendiente' : 'error');

    if (empty($jobPayload['__mapped_ok'])) {
        db_mark_skip_mapping($db, $rut);
        save_log('bulk_skip_job_mapping', $key, $jobPayload);
        db_set_company_buk($db, $rut, $bukCompanyIdForDb ?: null, 'ok');
        return ['ok' => false, 'stage' => 'JOB_SKIP', 'http' => 0, 'msg' => 'JOB: NO_ENVIADO (mapping BD incompleto) — Unidad/Cargo sin mapeo.'];
    }

    $other = $jobPayload['__other_type_of_working_day'] ?? null;
    unset(
        $jobPayload['__mapped_ok'],
        $jobPayload['__map_area_id'],
        $jobPayload['__map_role_id'],
        $jobPayload['__raw_situacion_laboral'],
        $jobPayload['__raw_horario'],
        $jobPayload['__other_type_of_working_day']
    );
    if (($jobPayload['type_of_working_day'] ?? '') === 'otros' && $other) {
        $jobPayload['other_type_of_working_day'] = $other;
    }

    $resJob = buk_api_request('POST', sprintf(BUK_JOB_CREATE_PATH, $empId), $jobPayload);
    if (!$resJob['ok']) {
        db_mark_job_error($db, $rut);
        save_log('bulk_fail_job_payload', $key, $jobPayload);
        save_log('bulk_fail_job_response', $key, $resJob);
        db_set_company_buk($db, $rut, $bukCompanyIdForDb ?: null, 'error');
        return ['ok' => false, 'stage' => 'JOB', 'http' => (int)$resJob['code'], 'msg' => parse_msg($resJob['body']) . ' [' . $resJob['variant'] . ']'];
    }

    $jj = json_decode($resJob['body'], true);
    $jobId = is_array($jj) ? (int)($jj['data']['id'] ?? 0) : 0;
    db_mark_job_ok($db, $rut, $jobId > 0 ? $jobId : null, isset($jobPayload['role_id']) ? (int)$jobPayload['role_id'] : null);
    db_set_company_buk($db, $rut, $bukCompanyIdForDb ?: null, 'ok');

    return [
        'ok' => true,
        'stage' => 'DONE',
        'http' => (int)$resJob['code'],
        'msg' => 'EMP + PLAN + JOB OK',
        'emp' => ['ok' => true, 'id' => $empId],
        'plan' => ['ok' => true, 'id' => $planId > 0 ? $planId : null],
        'job' => ['ok' => true, 'id' => $jobId > 0 ? $jobId : null],
    ];
}

function precreate_bosses_for_employees(clsConexion $db, array $employeeRuts, array $AREA_MAP, array $ROLE_MAP): void {
    $bossSet = [];
    foreach ($employeeRuts as $rutEmp) {
        $rowEmp = load_employee_row_by_rut($db, $rutEmp);
        if (!$rowEmp) continue;
        $bossRut = trim((string)($rowEmp['Jefe'] ?? ''));
        if ($bossRut !== '') $bossSet[$bossRut] = true;
    }

    $bossRuts = array_keys($bossSet);
    $bossRuts[] = FALLBACK_BOSS_RUT;
    $bossRuts = array_values(array_unique(array_filter($bossRuts)));

    foreach ($bossRuts as $rutBoss) {
        $rowBoss = load_employee_row_by_rut($db, $rutBoss);
        if (!$rowBoss) continue;
        if (($rowBoss['Estado'] ?? '') !== 'A') continue;
        run_pipeline_all($db, $rowBoss, $AREA_MAP, $ROLE_MAP, 'bulk');
    }
}

function parse_ruts_from_argv(array $argv): array {
    $ruts = [];
    foreach (array_slice($argv, 1) as $arg) {
        foreach (preg_split('/[,\s]+/', (string)$arg) as $part) {
            $rut = rut_key($part);
            if ($rut !== '') $ruts[] = $rut;
        }
    }
    return array_values(array_unique($ruts));
}

$ruts = parse_ruts_from_argv($argv);
if (empty($ruts)) {
    $result = [
        'status' => 'ok',
        'tipo' => 'buk_empleados',
        'message' => 'Sin RUTs para enviar a Buk.',
        'requested' => 0,
        'done' => 0,
        'failed' => 0,
        'skipped_mapping' => 0,
        'altas_buk_ok_ruts' => [],
        'altas_buk_error_ruts' => [],
        'job_skip_ruts' => [],
        'failed_items' => [],
        'buk' => [
            'empleados_ok' => 0,
            'empleados_error' => 0,
            'plans_ok' => 0,
            'plans_error' => 0,
            'jobs_ok' => 0,
            'jobs_error' => 0,
        ],
    ];
    echo "SYNC_RESULT=" . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$db = new clsConexion();
$AREA_MAP = load_area_map_from_db($db);
$ROLE_MAP = load_role_map_from_db($db);

precreate_bosses_for_employees($db, $ruts, $AREA_MAP, $ROLE_MAP);

$summary = [
    'status' => 'ok',
    'tipo' => 'buk_empleados',
    'requested' => count($ruts),
    'done' => 0,
    'failed' => 0,
    'skipped_mapping' => 0,
    'altas_buk_ok_ruts' => [],
    'altas_buk_error_ruts' => [],
    'job_skip_ruts' => [],
    'failed_items' => [],
    'buk' => [
        'empleados_ok' => 0,
        'empleados_error' => 0,
        'plans_ok' => 0,
        'plans_error' => 0,
        'jobs_ok' => 0,
        'jobs_error' => 0,
    ],
];

foreach ($ruts as $rut) {
    $rowFull = load_employee_row_by_rut($db, $rut);
    if (!$rowFull) {
        $summary['failed']++;
        $summary['altas_buk_error_ruts'][] = $rut;
        $summary['failed_items'][] = ['rut' => $rut, 'stage' => 'LOAD', 'http' => 0, 'msg' => 'No encontrado en BD'];
        continue;
    }

    $hadEmp = (int)($rowFull['buk_emp_id'] ?? 0) > 0;
    $hadPlan = (int)($rowFull['buk_plan_id'] ?? 0) > 0;
    $hadJob = (int)($rowFull['buk_job_id'] ?? 0) > 0;

    $res = run_pipeline_all($db, $rowFull, $AREA_MAP, $ROLE_MAP, 'bulk');

    if ($res['ok']) {
        $summary['done']++;
        $summary['altas_buk_ok_ruts'][] = $rut;
        if (!$hadEmp) $summary['buk']['empleados_ok']++;
        if (!$hadPlan) $summary['buk']['plans_ok']++;
        if (!$hadJob) $summary['buk']['jobs_ok']++;
        continue;
    }

    if (($res['stage'] ?? '') === 'JOB_SKIP') {
        $summary['skipped_mapping']++;
        $summary['job_skip_ruts'][] = $rut;
        $summary['altas_buk_error_ruts'][] = $rut;
        if (!$hadEmp && ((int)($res['emp']['id'] ?? 0) > 0)) $summary['buk']['empleados_ok']++;
        if (($res['plan']['ok'] ?? false) && !$hadPlan) $summary['buk']['plans_ok']++;
    } else {
        $summary['failed']++;
        $summary['altas_buk_error_ruts'][] = $rut;
    }

    if (($res['stage'] ?? '') === 'EMP' || ($res['stage'] ?? '') === 'EMP_ATTR') {
        $summary['buk']['empleados_error']++;
    } elseif (($res['stage'] ?? '') === 'PLAN') {
        if (!$hadEmp && ((int)($res['emp']['id'] ?? 0) > 0)) $summary['buk']['empleados_ok']++;
        $summary['buk']['plans_error']++;
    } elseif (($res['stage'] ?? '') === 'JOB' || ($res['stage'] ?? '') === 'LEADER' || ($res['stage'] ?? '') === 'JOB_SKIP') {
        if (!$hadEmp && ((int)($res['emp']['id'] ?? 0) > 0)) $summary['buk']['empleados_ok']++;
        if (($res['plan']['ok'] ?? false) && !$hadPlan) $summary['buk']['plans_ok']++;
        $summary['buk']['jobs_error']++;
    }

    $summary['failed_items'][] = [
        'rut' => $rut,
        'stage' => $res['stage'] ?? '-',
        'http' => (int)($res['http'] ?? 0),
        'msg' => (string)($res['msg'] ?? 'Error'),
    ];
}

$summary['message'] = sprintf(
    'Buk empleados solicitado=%d ok=%d error=%d skip_job=%d',
    $summary['requested'],
    $summary['done'],
    $summary['failed'],
    $summary['skipped_mapping']
);

echo "Envío Buk empleados: {$summary['message']}\n";
echo "SYNC_RESULT=" . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";

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

$apply = false;
$managerId = 0;
$date = date('Y-m-d');
$pageSize = 100;
$onlyMismatched = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = (string)$argv[$i];
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if ($arg === '--only-mismatched') {
        $onlyMismatched = true;
        continue;
    }
    if (strpos($arg, '--manager-id=') === 0) {
        $managerId = max(0, (int)substr($arg, 13));
        continue;
    }
    if (strpos($arg, '--date=') === 0) {
        $date = substr($arg, 7);
        continue;
    }
    if (strpos($arg, '--page-size=') === 0) {
        $pageSize = max(25, min(100, (int)substr($arg, 12)));
        continue;
    }
}

$db = new clsConexion();
$dbCfg = runtime_db_config();
$cn = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($cn->connect_error) {
    echo "SYNC_RESULT=" . json_encode([
        'status' => 'error',
        'tipo' => 'reconcile_plan_ids',
        'message' => 'Error conectando BD: ' . $cn->connect_error,
    ], JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}
$cn->set_charset('utf8mb4');

function buk_get_full(string $path): array
{
    $url = rtrim(BUK_API_BASE, '/') . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'auth_token: ' . BUK_TOKEN,
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$body, true);
    return [
        'http' => $http,
        'body' => (string)$body,
        'json' => is_array($json) ? $json : null,
        'err' => (string)$err,
        'url' => $url,
    ];
}

function to_ymd_local($date): ?string
{
    $date = trim((string)$date);
    if ($date === '') return null;
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return $date;
    return null;
}

function extract_plan_items_local(array $json): array
{
    if (isset($json['data']['plans']) && is_array($json['data']['plans'])) {
        return $json['data']['plans'];
    }
    if (isset($json['data']) && is_array($json['data'])) {
        $data = $json['data'];
        if (isset($data['id'])) return [$data];
        return array_values(array_filter($data, 'is_array'));
    }
    if (isset($json['id'])) return [$json];
    if (isset($json[0]) && is_array($json)) return array_values(array_filter($json, 'is_array'));
    return [];
}

function pick_current_plan_local(array $plans): array
{
    foreach ($plans as $plan) {
        if (!is_array($plan) || (int)($plan['id'] ?? 0) <= 0) continue;
        if (!empty($plan['current'])) {
            return $plan;
        }
    }
    foreach ($plans as $plan) {
        if (!is_array($plan) || (int)($plan['id'] ?? 0) <= 0) continue;
        if (trim((string)($plan['end_date'] ?? '')) === '') {
            return $plan;
        }
    }
    $best = [];
    $bestDate = '';
    foreach ($plans as $plan) {
        if (!is_array($plan) || (int)($plan['id'] ?? 0) <= 0) continue;
        $start = to_ymd_local($plan['start_date'] ?? '') ?? '';
        if ($start >= $bestDate) {
            $bestDate = $start;
            $best = $plan;
        }
    }
    return $best;
}

function fetch_current_plan_by_employee_id(int $bukEmpId): array
{
    if ($bukEmpId <= 0) {
        return ['ok' => false, 'msg' => 'buk_emp_id inválido'];
    }
    $res = buk_get_full("employees/{$bukEmpId}/plans");
    if ($res['err'] !== '' || $res['http'] < 200 || $res['http'] >= 300 || !is_array($res['json'])) {
        return [
            'ok' => false,
            'msg' => $res['err'] !== '' ? $res['err'] : mb_substr((string)$res['body'], 0, 500),
            'http' => (int)$res['http'],
            'url' => (string)$res['url'],
        ];
    }

    $plans = extract_plan_items_local($res['json']);
    $current = pick_current_plan_local($plans);
    $currentId = (int)($current['id'] ?? 0);
    return [
        'ok' => $currentId > 0,
        'plan_id' => $currentId,
        'start_date' => to_ymd_local($current['start_date'] ?? ''),
        'health_company' => (string)($current['health_company'] ?? ''),
        'fund_quote' => (string)($current['fund_quote'] ?? ''),
        'http' => (int)$res['http'],
        'url' => (string)$res['url'],
        'msg' => $currentId > 0 ? 'ok' : 'No se encontró plan vigente',
    ];
}

function fetch_subordinates_recursive(int $managerId, string $date, int $pageSize): array
{
    $seen = [];
    $queue = [$managerId];
    $out = [];

    while (!empty($queue)) {
        $currentManagerId = (int)array_shift($queue);
        $page = 1;

        while (true) {
            $query = http_build_query([
                'date' => $date,
                'page_size' => $pageSize,
                'page' => $page,
            ]);
            $res = buk_get_full("employees/{$currentManagerId}/subordinates?{$query}");
            if ($res['err'] !== '' || $res['http'] < 200 || $res['http'] >= 300 || !is_array($res['json'])) {
                break;
            }

            $data = $res['json']['data'] ?? [];
            if (!is_array($data) || empty($data)) {
                break;
            }

            foreach ($data as $emp) {
                if (!is_array($emp)) continue;
                $empId = (int)($emp['id'] ?? 0);
                if ($empId <= 0 || isset($seen[$empId])) continue;
                $seen[$empId] = true;
                $out[] = [
                    'buk_emp_id' => $empId,
                    'rut' => (string)($emp['rut'] ?? $emp['document_number'] ?? ''),
                    'full_name' => (string)($emp['full_name'] ?? ''),
                ];
                $queue[] = $empId;
            }

            $next = trim((string)($res['json']['pagination']['next'] ?? ''));
            if ($next === '') {
                break;
            }
            $page++;
        }
    }

    return $out;
}

function db_rows_for_scope(mysqli $cn, int $managerId, string $date, int $pageSize): array
{
    if ($managerId > 0) {
        $subs = fetch_subordinates_recursive($managerId, $date, $pageSize);
        if (empty($subs)) return [];

        $ids = [];
        foreach ($subs as $sub) {
            $id = (int)($sub['buk_emp_id'] ?? 0);
            if ($id > 0) $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) return [];

        $sql = "SELECT Rut, Nombres, Apaterno, Amaterno, Estado, buk_emp_id, buk_plan_id
                FROM adp_empleados
                WHERE buk_emp_id IN (" . implode(',', array_map('intval', $ids)) . ")";
        $res = $cn->query($sql);
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->free();
        }
        return $rows;
    }

    $sql = "SELECT Rut, Nombres, Apaterno, Amaterno, Estado, buk_emp_id, buk_plan_id
            FROM adp_empleados
            WHERE Estado='A' AND buk_emp_id IS NOT NULL AND buk_emp_id > 0
            ORDER BY buk_emp_id ASC";
    $res = $cn->query($sql);
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }
    return $rows;
}

function update_local_plan_id(mysqli $cn, string $rut, int $planId): bool
{
    $stmt = $cn->prepare("UPDATE adp_empleados SET buk_plan_id=?, plan_buk='ok' WHERE Rut=? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('is', $planId, $rut);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

$rows = db_rows_for_scope($cn, $managerId, $date, $pageSize);
$checked = 0;
$updated = 0;
$unchanged = 0;
$errors = 0;
$items = [];

foreach ($rows as $row) {
    $rut = (string)($row['Rut'] ?? '');
    $bukEmpId = (int)($row['buk_emp_id'] ?? 0);
    $localPlanId = (int)($row['buk_plan_id'] ?? 0);
    if ($rut === '' || $bukEmpId <= 0) {
        continue;
    }

    $checked++;
    $planInfo = fetch_current_plan_by_employee_id($bukEmpId);
    if (empty($planInfo['ok'])) {
        $errors++;
        $items[] = [
            'rut' => $rut,
            'buk_emp_id' => $bukEmpId,
            'buk_plan_id_local' => $localPlanId,
            'status' => 'error',
            'message' => (string)($planInfo['msg'] ?? 'Error consultando Buk'),
        ];
        continue;
    }

    $remotePlanId = (int)($planInfo['plan_id'] ?? 0);
    $mismatch = ($remotePlanId > 0 && $remotePlanId !== $localPlanId);

    if ($mismatch && $apply) {
        if (update_local_plan_id($cn, $rut, $remotePlanId)) {
            $updated++;
        } else {
            $errors++;
        }
    } else {
        if ($mismatch) {
            // keep mismatch count implicit via item status
        } else {
            $unchanged++;
        }
    }

    if (!$onlyMismatched || $mismatch) {
        $items[] = [
            'rut' => $rut,
            'buk_emp_id' => $bukEmpId,
            'buk_plan_id_local' => $localPlanId,
            'buk_plan_id_remote' => $remotePlanId,
            'start_date_remote' => $planInfo['start_date'] ?? null,
            'health_company' => $planInfo['health_company'] ?? null,
            'fund_quote' => $planInfo['fund_quote'] ?? null,
            'status' => $mismatch ? ($apply ? 'updated' : 'mismatch') : 'ok',
        ];
    }
}

$reportDir = __DIR__ . '/storage/reports/' . date('Y/m');
if (!is_dir($reportDir)) {
    @mkdir($reportDir, 0775, true);
}
$reportFile = $reportDir . '/reconcile_plan_ids_' . date('Ymd_His') . '.json';
@file_put_contents($reportFile, json_encode([
    'generated_at' => date('c'),
    'apply' => $apply,
    'manager_id' => $managerId,
    'date' => $date,
    'checked' => $checked,
    'updated' => $updated,
    'unchanged' => $unchanged,
    'errors' => $errors,
    'items' => $items,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$result = [
    'status' => $errors > 0 ? 'warning' : 'ok',
    'tipo' => 'reconcile_plan_ids',
    'apply' => $apply,
    'manager_id' => $managerId,
    'date' => $date,
    'checked' => $checked,
    'updated' => $updated,
    'unchanged' => $unchanged,
    'errors' => $errors,
    'report_file' => $reportFile,
    'items' => $items,
    'message' => "Revisión de buk_plan_id finalizada. Revisados: {$checked}, Actualizados: {$updated}, Sin cambio: {$unchanged}, Errores: {$errors}.",
];

echo $result['message'] . PHP_EOL;
echo "SYNC_RESULT=" . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

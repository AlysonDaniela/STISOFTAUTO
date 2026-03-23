<?php
declare(strict_types=1);

if (!headers_sent()) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
}
ob_start();

require_once __DIR__ . '/../includes/auth.php';
require_auth();
require_once __DIR__ . '/../conexion/db.php';
require_once __DIR__ . '/../includes/runtime_config.php';

$user = current_user();
$db = new clsConexion();
$dbCfg = runtime_db_config();
$cn = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($cn->connect_error) {
    die('Error conectando BD: ' . $cn->connect_error);
}
$cn->set_charset('utf8mb4');

$bukCfg = runtime_buk_config();
define('BUK_API_BASE_RECON', $bukCfg['base']);
define('BUK_TOKEN_RECON', $bukCfg['token']);

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function buk_get_recon(string $path): array
{
    $url = rtrim(BUK_API_BASE_RECON, '/') . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'auth_token: ' . BUK_TOKEN_RECON,
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

function to_ymd_recon($date): ?string
{
    $date = trim((string)$date);
    return preg_match('~^\d{4}-\d{2}-\d{2}$~', $date) ? $date : null;
}

function extract_plan_items_recon(array $json): array
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

function pick_current_plan_recon(array $plans): array
{
    foreach ($plans as $plan) {
        if (!is_array($plan) || (int)($plan['id'] ?? 0) <= 0) continue;
        if (!empty($plan['current'])) return $plan;
    }

    $best = [];
    $bestDate = '';
    foreach ($plans as $plan) {
        if (!is_array($plan) || (int)($plan['id'] ?? 0) <= 0) continue;
        $start = to_ymd_recon($plan['start_date'] ?? '') ?? '';
        if ($start >= $bestDate) {
            $bestDate = $start;
            $best = $plan;
        }
    }

    if (!empty($best)) {
        return $best;
    }

    foreach ($plans as $plan) {
        if (!is_array($plan) || (int)($plan['id'] ?? 0) <= 0) continue;
        if (trim((string)($plan['end_date'] ?? '')) === '') return $plan;
    }

    return $best;
}

function fetch_current_plan_for_emp_recon(int $bukEmpId): array
{
    if ($bukEmpId <= 0) {
        return ['ok' => false, 'msg' => 'buk_emp_id inválido'];
    }
    $res = buk_get_recon("employees/{$bukEmpId}/plans");
    if ($res['err'] !== '' || $res['http'] < 200 || $res['http'] >= 300 || !is_array($res['json'])) {
        return [
            'ok' => false,
            'msg' => $res['err'] !== '' ? $res['err'] : mb_substr((string)$res['body'], 0, 500),
            'http' => (int)$res['http'],
        ];
    }
    $current = pick_current_plan_recon(extract_plan_items_recon($res['json']));
    $planId = (int)($current['id'] ?? 0);
    if ($planId <= 0) {
        return ['ok' => false, 'msg' => 'No se encontró plan vigente', 'http' => (int)$res['http']];
    }
    return [
        'ok' => true,
        'plan_id' => $planId,
        'start_date' => to_ymd_recon($current['start_date'] ?? ''),
        'health_company' => (string)($current['health_company'] ?? ''),
        'fund_quote' => (string)($current['fund_quote'] ?? ''),
        'pension_scheme' => (string)($current['pension_scheme'] ?? ''),
    ];
}

function read_json_file_recon(string $file): ?array
{
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function status_badge_class_recon(string $status): string
{
    $s = strtolower(trim($status));
    if ($s === 'updated') return 'bg-emerald-50 text-emerald-700 border border-emerald-200';
    if ($s === 'mismatch') return 'bg-amber-50 text-amber-800 border border-amber-200';
    if ($s === 'error') return 'bg-red-50 text-red-700 border border-red-200';
    return 'bg-gray-50 text-gray-700 border border-gray-200';
}

function update_local_plan_id_recon(mysqli $cn, string $rut, int $planId): bool
{
    $stmt = $cn->prepare("UPDATE adp_empleados SET buk_plan_id=?, plan_buk='ok' WHERE Rut=? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('is', $planId, $rut);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

function load_active_rows_recon(mysqli $cn): array
{
    $sql = "SELECT Rut, Nombres, Apaterno, Amaterno, Estado, buk_emp_id, buk_plan_id
            FROM adp_empleados
            WHERE Estado='A' AND buk_emp_id IS NOT NULL AND buk_emp_id > 0
            ORDER BY Rut ASC";
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

function run_plan_reconcile_scan(mysqli $cn, bool $onlyMismatched = false): array
{
    @set_time_limit(0);
    $rows = load_active_rows_recon($cn);
    $items = [];
    $checked = 0;
    $mismatched = 0;
    $unchanged = 0;
    $errors = 0;

    foreach ($rows as $row) {
        $rut = (string)($row['Rut'] ?? '');
        $bukEmpId = (int)($row['buk_emp_id'] ?? 0);
        $localPlanId = (int)($row['buk_plan_id'] ?? 0);
        if ($rut === '' || $bukEmpId <= 0) continue;

        $checked++;
        $remote = fetch_current_plan_for_emp_recon($bukEmpId);
        $fullName = trim((string)($row['Nombres'] ?? '') . ' ' . (string)($row['Apaterno'] ?? '') . ' ' . (string)($row['Amaterno'] ?? ''));

        if (empty($remote['ok'])) {
            $errors++;
            $items[] = [
                'rut' => $rut,
                'full_name' => $fullName,
                'buk_emp_id' => $bukEmpId,
                'buk_plan_id_local' => $localPlanId,
                'buk_plan_id_remote' => null,
                'start_date_remote' => null,
                'health_company' => null,
                'fund_quote' => null,
                'pension_scheme' => null,
                'status' => 'error',
                'message' => (string)($remote['msg'] ?? 'Error'),
            ];
            continue;
        }

        $remotePlanId = (int)($remote['plan_id'] ?? 0);
        $mismatch = ($remotePlanId > 0 && $remotePlanId !== $localPlanId);
        if ($mismatch) $mismatched++; else $unchanged++;

        if ($onlyMismatched && !$mismatch) {
            continue;
        }

        $items[] = [
            'rut' => $rut,
            'full_name' => $fullName,
            'buk_emp_id' => $bukEmpId,
            'buk_plan_id_local' => $localPlanId,
            'buk_plan_id_remote' => $remotePlanId,
            'start_date_remote' => $remote['start_date'] ?? null,
            'health_company' => $remote['health_company'] ?? null,
            'fund_quote' => $remote['fund_quote'] ?? null,
            'pension_scheme' => $remote['pension_scheme'] ?? null,
            'status' => $mismatch ? 'mismatch' : 'ok',
            'message' => $mismatch ? 'Plan vigente diferente en Buk' : 'Sin diferencias',
        ];
    }

    return [
        'generated_at' => date('c'),
        'checked' => $checked,
        'mismatched' => $mismatched,
        'unchanged' => $unchanged,
        'errors' => $errors,
        'items' => $items,
    ];
}

$reportDir = __DIR__ . '/storage/reports/' . date('Y/m');
if (!is_dir($reportDir)) {
    @mkdir($reportDir, 0775, true);
}
$latestReportFile = __DIR__ . '/storage/sync/latest_plan_reconcile.json';

$flash = null;
$flashType = 'success';
$report = read_json_file_recon($latestReportFile) ?: [
    'generated_at' => null,
    'checked' => 0,
    'mismatched' => 0,
    'unchanged' => 0,
    'errors' => 0,
    'items' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['scan_all'])) {
        $onlyMismatched = !empty($_POST['only_mismatched']);
        $report = run_plan_reconcile_scan($cn, $onlyMismatched);
        $archiveFile = $reportDir . '/plan_reconcile_' . date('Ymd_His') . '.json';
        @file_put_contents($archiveFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @file_put_contents($latestReportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $flash = "Consulta completada. Revisados: {$report['checked']}, Desfasados: {$report['mismatched']}, Errores: {$report['errors']}.";
        $flashType = 'success';
    } elseif (isset($_POST['apply_one'])) {
        $rut = trim((string)($_POST['rut'] ?? ''));
        $remotePlanId = (int)($_POST['remote_plan_id'] ?? 0);
        if ($rut !== '' && $remotePlanId > 0 && update_local_plan_id_recon($cn, $rut, $remotePlanId)) {
            foreach ($report['items'] as &$item) {
                if ((string)($item['rut'] ?? '') === $rut) {
                    $item['buk_plan_id_local'] = $remotePlanId;
                    $item['status'] = 'updated';
                    $item['message'] = 'Actualizado en BD';
                    break;
                }
            }
            unset($item);
            @file_put_contents($latestReportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $flash = "Se actualizó buk_plan_id para {$rut}.";
            $flashType = 'success';
        } else {
            $flash = 'No se pudo actualizar el registro seleccionado.';
            $flashType = 'error';
        }
    } elseif (isset($_POST['apply_all'])) {
        $updated = 0;
        foreach ($report['items'] as &$item) {
            if ((string)($item['status'] ?? '') !== 'mismatch') continue;
            $rut = (string)($item['rut'] ?? '');
            $remotePlanId = (int)($item['buk_plan_id_remote'] ?? 0);
            if ($rut !== '' && $remotePlanId > 0 && update_local_plan_id_recon($cn, $rut, $remotePlanId)) {
                $item['buk_plan_id_local'] = $remotePlanId;
                $item['status'] = 'updated';
                $item['message'] = 'Actualizado en BD';
                $updated++;
            }
        }
        unset($item);
        @file_put_contents($latestReportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $flash = "Actualización masiva completada. Registros actualizados: {$updated}.";
        $flashType = 'success';
    }
}

require_once __DIR__ . '/../partials/head.php';
?>
<div class="flex">
  <?php require_once __DIR__ . '/../partials/sidebar.php'; ?>
  <main class="flex-1 min-h-screen">
    <?php require_once __DIR__ . '/../partials/topbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 py-6">
      <div class="flex items-center justify-between gap-4 mb-6">
        <div>
          <h1 class="text-lg font-semibold flex items-center gap-2"><i class="fa-solid fa-arrows-rotate"></i> Reconciliar Plan IDs Buk</h1>
          <p class="text-sm text-gray-500 mt-1">Consulta el plan vigente real en Buk y compara contra <code>adp_empleados.buk_plan_id</code>.</p>
        </div>
        <div class="flex items-center gap-2">
          <a href="/sync/sync_detalles.php" class="px-3 py-2 rounded-lg border hover:bg-gray-50">Volver a Sync</a>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="mb-4 p-3 rounded-xl <?= $flashType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200' ?>"><?= e($flash) ?></div>
      <?php endif; ?>

      <div class="bg-white rounded-2xl p-5 shadow-sm border mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div class="font-semibold">Acciones</div>
            <div class="text-sm text-gray-500">Primero consulta, luego actualiza solo los desfasados.</div>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <form method="post" class="flex items-center gap-2">
              <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="only_mismatched" value="1" checked class="rounded border-gray-300">
                Mostrar solo desfasados
              </label>
              <button type="submit" name="scan_all" value="1" class="px-3 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
                <i class="fa-solid fa-magnifying-glass"></i> Consultar IDs actuales Buk
              </button>
            </form>
            <form method="post">
              <button type="submit" name="apply_all" value="1" class="px-3 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                <i class="fa-solid fa-database"></i> Actualizar desfasados en BD
              </button>
            </form>
          </div>
        </div>
      </div>

      <div class="grid md:grid-cols-4 gap-3 mb-6">
        <div class="rounded-xl border p-3 bg-gray-50 text-sm"><strong>Última consulta:</strong> <?= e((string)($report['generated_at'] ?? 'Sin datos')) ?></div>
        <div class="rounded-xl border p-3 bg-gray-50 text-sm"><strong>Revisados:</strong> <?= e((string)($report['checked'] ?? 0)) ?></div>
        <div class="rounded-xl border p-3 bg-amber-50 text-sm"><strong>Desfasados:</strong> <?= e((string)($report['mismatched'] ?? 0)) ?></div>
        <div class="rounded-xl border p-3 bg-red-50 text-sm"><strong>Errores:</strong> <?= e((string)($report['errors'] ?? 0)) ?></div>
      </div>

      <div class="bg-white rounded-2xl p-5 shadow-sm border">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold">Resultado</h2>
          <div class="text-xs text-gray-500"><?= e((string)count((array)($report['items'] ?? []))) ?> fila(s)</div>
        </div>

        <?php if (empty($report['items'])): ?>
          <p class="text-sm text-gray-500">Todavía no hay una consulta cargada. Usa el botón "Consultar IDs actuales Buk".</p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead>
                <tr class="text-left border-b">
                  <th class="py-2 pr-4">Estado</th>
                  <th class="py-2 pr-4">RUT</th>
                  <th class="py-2 pr-4">Nombre</th>
                  <th class="py-2 pr-4">Empleado Buk</th>
                  <th class="py-2 pr-4">Plan local</th>
                  <th class="py-2 pr-4">Plan actual Buk</th>
                  <th class="py-2 pr-4">Inicio vigente</th>
                  <th class="py-2 pr-4">Previsión</th>
                  <th class="py-2 pr-4">Salud</th>
                  <th class="py-2 pr-4">Acción</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ((array)$report['items'] as $item): ?>
                  <?php
                    $status = (string)($item['status'] ?? 'ok');
                    $rowClass = $status === 'mismatch' ? 'bg-amber-50/40' : ($status === 'error' ? 'bg-red-50/40' : '');
                  ?>
                  <tr class="border-b <?= e($rowClass) ?>">
                    <td class="py-2 pr-4">
                      <span class="text-xs px-2 py-1 rounded-lg <?= e(status_badge_class_recon($status)) ?>"><?= e(strtoupper($status)) ?></span>
                      <?php if (!empty($item['message'])): ?><div class="text-xs text-gray-500 mt-1 max-w-xs"><?= e((string)$item['message']) ?></div><?php endif; ?>
                    </td>
                    <td class="py-2 pr-4 font-medium"><?= e((string)($item['rut'] ?? '-')) ?></td>
                    <td class="py-2 pr-4"><?= e((string)($item['full_name'] ?? '-')) ?></td>
                    <td class="py-2 pr-4"><?= e((string)($item['buk_emp_id'] ?? '-')) ?></td>
                    <td class="py-2 pr-4"><?= e((string)($item['buk_plan_id_local'] ?? '-')) ?></td>
                    <td class="py-2 pr-4"><?= e((string)($item['buk_plan_id_remote'] ?? '-')) ?></td>
                    <td class="py-2 pr-4"><?= e((string)($item['start_date_remote'] ?? '-')) ?></td>
                    <td class="py-2 pr-4"><?= e((string)($item['fund_quote'] ?? '-')) ?></td>
                    <td class="py-2 pr-4"><?= e((string)($item['health_company'] ?? '-')) ?></td>
                    <td class="py-2 pr-4">
                      <?php if ($status === 'mismatch' && (int)($item['buk_plan_id_remote'] ?? 0) > 0): ?>
                        <form method="post">
                          <input type="hidden" name="rut" value="<?= e((string)$item['rut']) ?>">
                          <input type="hidden" name="remote_plan_id" value="<?= e((string)$item['buk_plan_id_remote']) ?>">
                          <button type="submit" name="apply_one" value="1" class="px-2 py-1 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 text-xs">
                            Actualizar BD
                          </button>
                        </form>
                      <?php else: ?>
                        <span class="text-xs text-gray-400">Sin acción</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
</body>
</html>
<?php
$cn->close();
ob_end_flush();

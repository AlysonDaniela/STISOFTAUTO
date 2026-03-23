<?php
/**
 * /home.php
 * STISOFT - Inicio / Dashboard
 * Enfocado en: Colaboradores (Ficha/Plan/Trabajo), Liquidaciones, Vacaciones + Logs BUK
 */

ob_start();
require_once __DIR__ . '/includes/auth.php';
require_auth();
$user = current_user();

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/conexion/db.php';
$db = new clsConexion();

$headPath    = __DIR__ . '/partials/head.php';
$sidebarPath = __DIR__ . '/partials/sidebar.php';
$topbarPath  = __DIR__ . '/partials/topbar.php';
$footerPath  = __DIR__ . '/partials/footer.php';

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function q_scalar(clsConexion $db, string $sql, $default = 0) {
  try {
    $r = $db->consultar($sql);
    if (!$r || !is_array($r) || !isset($r[0]) || !is_array($r[0])) return $default;
    $firstVal = array_values($r[0])[0] ?? $default;
    if ($firstVal === null || $firstVal === '') return $default;
    return $firstVal;
  } catch (Throwable $e) {
    return $default;
  }
}

function q_rows(clsConexion $db, string $sql): array {
  try {
    $r = $db->consultar($sql);
    return (is_array($r) ? $r : []);
  } catch (Throwable $e) {
    return [];
  }
}

function table_exists(clsConexion $db, string $table): bool {
  $r = q_rows($db, "SHOW TABLES LIKE '" . addslashes($table) . "'");
  return !empty($r);
}

function first_existing_table(clsConexion $db, array $candidates): ?string {
  foreach ($candidates as $t) {
    if (table_exists($db, $t)) return $t;
  }
  return null;
}

function column_exists(clsConexion $db, string $table, string $col): bool {
  $r = q_rows($db, "SHOW COLUMNS FROM `$table` LIKE '" . addslashes($col) . "'");
  return !empty($r);
}

function first_existing_column(clsConexion $db, string $table, array $candidates): ?string {
  foreach ($candidates as $c) {
    if (column_exists($db, $table, $c)) return $c;
  }
  return null;
}

/* =========================
   1) COLABORADORES (ADP -> BUK)
   Activos = Estado 'A'
   ========================= */

$totalEmpleados = (int) q_scalar($db, "SELECT COUNT(*) FROM adp_empleados", 0);
$activos        = (int) q_scalar($db, "SELECT COUNT(*) FROM adp_empleados WHERE Estado='A'", 0);
$inactivos      = max(0, $totalEmpleados - $activos);

$activosConBuk = (int) q_scalar($db, "
  SELECT COUNT(*)
  FROM adp_empleados
  WHERE Estado='A'
    AND buk_emp_id IS NOT NULL
    AND buk_emp_id <> '' AND buk_emp_id <> 0
", 0);

$activosPendientesBuk = max(0, $activos - $activosConBuk);
$porcSyncActivos = ($activos > 0) ? round(($activosConBuk / $activos) * 100) : 0;

// Rut ADP (para cruces futuros si lo necesitas)
$colRutAdp = first_existing_column($db, 'adp_empleados', ['rut','Rut','RUT','rut_empleado','RutEmpleado','RUT_EMPLEADO']);

/* =========================
   2) TRABAJO (Jerarquía + Cargos)
   División → CC → Unidad (+ cargos)
   ========================= */

$jerarquiaTotal = (int) q_scalar($db, "SELECT COUNT(*) FROM buk_jerarquia", 0);

$colDivision = first_existing_column($db, 'buk_jerarquia', ['division','Division','DIVISION','división','División']);
$colCC       = first_existing_column($db, 'buk_jerarquia', ['cc','CC','centro_costo','CentroCosto','centroCosto','cost_center','costCenter']);
$colUnidad   = first_existing_column($db, 'buk_jerarquia', ['unidad','Unidad','UNIDAD','unit','Unit']);

$divisiones = 0; $centrosCosto = 0; $unidades = 0;
if ($colDivision) $divisiones   = (int) q_scalar($db, "SELECT COUNT(DISTINCT `$colDivision`) FROM buk_jerarquia WHERE `$colDivision`<>''", 0);
if ($colCC)       $centrosCosto = (int) q_scalar($db, "SELECT COUNT(DISTINCT `$colCC`) FROM buk_jerarquia WHERE `$colCC`<>''", 0);
if ($colUnidad)   $unidades     = (int) q_scalar($db, "SELECT COUNT(DISTINCT `$colUnidad`) FROM buk_jerarquia WHERE `$colUnidad`<>''", 0);

$mapeoCargos = (int) q_scalar($db, "SELECT COUNT(*) FROM stisoft_mapeo_cargos", 0);

/* =========================
   3) LIQUIDACIONES (Tracking)
   - buk_liq_jobs
   - buk_liq_job_items
   ========================= */

$liqJobsExists  = table_exists($db, 'buk_liq_jobs');
$liqItemsExists = table_exists($db, 'buk_liq_job_items');

$liq_last_job = null;
$liq_last_job_items_total = 0;
$liq_last_job_send_ok = 0;
$liq_last_job_send_err = 0;
$liq_pendientes = 0;
$liq_coverage = 0;

if ($liqJobsExists && $liqItemsExists) {
  $lastJobRows = q_rows($db, "
    SELECT id, ames, tipo, created_at
    FROM buk_liq_jobs
    ORDER BY id DESC
    LIMIT 1
  ");
  if (!empty($lastJobRows)) {
    $liq_last_job = $lastJobRows[0];
    $jobId = (int)($liq_last_job['id'] ?? 0);

    $liq_last_job_items_total = (int) q_scalar($db, "SELECT COUNT(*) FROM buk_liq_job_items WHERE job_id={$jobId}", 0);
    $liq_last_job_send_ok     = (int) q_scalar($db, "SELECT COUNT(*) FROM buk_liq_job_items WHERE job_id={$jobId} AND send_ok=1", 0);
    $liq_last_job_send_err    = (int) q_scalar($db, "SELECT COUNT(*) FROM buk_liq_job_items WHERE job_id={$jobId} AND send_ok=0 AND http_code IS NOT NULL", 0);

    $liq_pendientes = max(0, $activos - $liq_last_job_send_ok);
    $liq_coverage = ($activos > 0) ? round(($liq_last_job_send_ok / $activos) * 100) : 0;
  }
}

/* =========================
   4) VACACIONES (auto-detección simple)
   ========================= */

$vacTable = first_existing_table($db, [
  'buk_vacaciones', 'buk_vacation', 'buk_vacations',
  'stisoft_vacaciones', 'stisoft_vacaciones_saldos', 'stisoft_vacaciones_saldo'
]);

$vacRegistros = 0;
if ($vacTable) $vacRegistros = (int) q_scalar($db, "SELECT COUNT(*) FROM `$vacTable`", 0);

/* =========================
   5) LOGS BUK (NO QUITAR)
   ========================= */

$logsTableExists = table_exists($db, 'stisoft_buk_logs');

$logs7d = 0; $logsHoyOk = 0; $logsHoyErr = 0;
$ultimosLogs = [];

if ($logsTableExists) {
  $logs7d = (int) q_scalar($db, "
    SELECT COUNT(*) FROM stisoft_buk_logs
    WHERE
      (
        (created_at IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))
        OR (fecha IS NOT NULL AND fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY))
        OR (fecha_creacion IS NOT NULL AND fecha_creacion >= DATE_SUB(NOW(), INTERVAL 7 DAY))
      )
  ", 0);

  $logsHoyOk = (int) q_scalar($db, "
    SELECT COUNT(*) FROM stisoft_buk_logs
    WHERE
      (
        (created_at IS NOT NULL AND DATE(created_at)=CURDATE())
        OR (fecha IS NOT NULL AND DATE(fecha)=CURDATE())
        OR (fecha_creacion IS NOT NULL AND DATE(fecha_creacion)=CURDATE())
      )
      AND (respuesta_http BETWEEN 200 AND 299)
  ", 0);

  $logsHoyErr = (int) q_scalar($db, "
    SELECT COUNT(*) FROM stisoft_buk_logs
    WHERE
      (
        (created_at IS NOT NULL AND DATE(created_at)=CURDATE())
        OR (fecha IS NOT NULL AND DATE(fecha)=CURDATE())
        OR (fecha_creacion IS NOT NULL AND DATE(fecha_creacion)=CURDATE())
      )
      AND NOT (respuesta_http BETWEEN 200 AND 299)
  ", 0);

  $ultimosLogs = q_rows($db, "
    SELECT
      tipo_entidad,
      referencia_local,
      metodo_http,
      endpoint,
      respuesta_http,
      COALESCE(created_at, fecha, fecha_creacion) AS fecha_any
    FROM stisoft_buk_logs
    ORDER BY COALESCE(created_at, fecha, fecha_creacion) DESC
    LIMIT 10
  ");
}

// =========================
// Historia de sincronización SFTP (último mes)
// =========================
$history = [];
$historyFile = __DIR__ . '/sync/storage/sync/history.json';

// si no existe, intentar con ruta alternativa de producción
if (!is_file($historyFile)) {
    $historyFile = '/var/www/html/sync/storage/sync/history.json';
}

if (is_file($historyFile)) {
    $raw = @file_get_contents($historyFile);
    if ($raw !== false) {
        $decoded = @json_decode($raw, true);
        $history = is_array($decoded) ? $decoded : [];
    }
}

$lastRun = null;
if (!empty($history) && is_array($history)) {
    $lastRun = end($history); // obtener último elemento
}

$lastRunStatus = $lastRun['status'] ?? null;
$lastRunFiles = $lastRun['files'] ?? 0;
$lastRunDate = $lastRun['date'] ?? null;

// =========================
// Sync reporte detallado
// =========================
$latestReport = [];
$latestReportFile = __DIR__ . '/storage/sync/latest_report.json';
if (!is_file($latestReportFile)) {
    $latestReportFile = '/var/www/html/sync/storage/sync/latest_report.json';
}
if (is_file($latestReportFile)) {
    $raw = @file_get_contents($latestReportFile);
    if ($raw !== false) {
        $decoded = @json_decode($raw, true);
        $latestReport = is_array($decoded) ? $decoded : [];
    }
}

$latestEvent  = is_array($latestReport['event'] ?? null) ? $latestReport['event'] : [];
$latestStats  = is_array($latestReport['processed_stats'] ?? null) ? $latestReport['processed_stats'] : [];
$latestGlobal = is_array($latestStats['global'] ?? null) ? $latestStats['global'] : [];
$latestFiles  = is_array($latestStats['files'] ?? null) ? $latestStats['files'] : [];

$syncStatus = strtoupper((string)($latestEvent['status'] ?? ($lastRunStatus ?? 'UNKNOWN')));
$syncDate = (string)($latestEvent['date'] ?? ($lastRunDate ?? ($latestReport['generated_at'] ?? '—')));
$syncDuration = (string)($latestEvent['duration'] ?? '—');
$syncMessage = (string)($latestEvent['message'] ?? 'Sin detalle');

$syncDetected = (int)($latestGlobal['files_detected'] ?? 0);
$syncProcessed = (int)($latestGlobal['files_processed'] ?? 0);
$syncSkipped = (int)($latestGlobal['files_skipped'] ?? 0);
$syncErrors = (int)($latestGlobal['errores_total'] ?? 0);

// =========================
// Cola cambios
// =========================
$colaPending = 0;
$colaError = 0;
$colaIgnoredManual = 0;
if (table_exists($db, 'stisoft_cambios_queue')) {
  $colaPending = (int) q_scalar($db, "SELECT COUNT(*) FROM stisoft_cambios_queue WHERE status='pending'", 0);
  $colaError = (int) q_scalar($db, "SELECT COUNT(*) FROM stisoft_cambios_queue WHERE status='error'", 0);
  $colaIgnoredManual = (int) q_scalar($db, "SELECT COUNT(*) FROM stisoft_cambios_queue WHERE status='ignored_manual'", 0);
}

// =========================
// Estado BUK en BD local
// =========================
$fichaOk = (int) q_scalar($db, "SELECT COUNT(*) FROM adp_empleados WHERE Estado='A' AND ficha_buk='ok'", 0);
$fichaErr = (int) q_scalar($db, "SELECT COUNT(*) FROM adp_empleados WHERE Estado='A' AND ficha_buk='error'", 0);
$planOk = (int) q_scalar($db, "SELECT COUNT(*) FROM adp_empleados WHERE Estado='A' AND plan_buk='ok'", 0);
$planErr = (int) q_scalar($db, "SELECT COUNT(*) FROM adp_empleados WHERE Estado='A' AND plan_buk='error'", 0);
$jobOk = (int) q_scalar($db, "SELECT COUNT(*) FROM adp_empleados WHERE Estado='A' AND job_buk='ok'", 0);
$jobErr = (int) q_scalar($db, "SELECT COUNT(*) FROM adp_empleados WHERE Estado='A' AND job_buk='error'", 0);

$pipelineRows = [];
foreach ($latestFiles as $f) {
  $pipelineRows[] = [
    'file' => (string)($f['file'] ?? '-'),
    'tipo' => (string)($f['tipo'] ?? '-'),
    'status' => strtoupper((string)($f['status'] ?? 'UNKNOWN')),
    'message' => (string)(is_array($f['result'] ?? null) ? (($f['result']['message'] ?? '') ?: '') : ''),
  ];
}

?>
<?php include $headPath; ?>
<body class="bg-gray-50">

<div class="min-h-screen grid grid-cols-12">
  <!-- Sidebar -->
  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php $active='home'; include $sidebarPath; ?>
  </div>

  <!-- Main -->
  <div class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col">
    <?php include $topbarPath; ?>

    <main class="flex-grow max-w-7xl mx-auto p-6 space-y-6">

      <section class="rounded-3xl border border-slate-200 bg-gradient-to-r from-slate-900 via-blue-900 to-cyan-900 text-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div class="text-xs uppercase tracking-wider text-cyan-200">STISOFT · Control Sync ADP → BUK</div>
            <h1 class="text-2xl font-semibold mt-1">Dashboard Operativo</h1>
            <p class="text-sm text-slate-200 mt-2">Visibilidad en tiempo real de Sync, cola de cambios, estado de BD local y envío a BUK.</p>
          </div>
          <div class="text-xs bg-white/10 border border-white/20 rounded-xl px-3 py-2">
            <div class="text-slate-200">Usuario</div>
            <div class="font-semibold"><?= e($user['name'] ?? $user['email'] ?? '—') ?></div>
          </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2 text-xs">
          <a href="/sync/reconcile_plan_ids.php" class="px-3 py-2 rounded-lg bg-white text-slate-900 hover:bg-slate-100">Plan IDs Buk</a>
          <a href="/sync/sync_detalles.php" class="px-3 py-2 rounded-lg bg-white text-slate-900 hover:bg-slate-100">Sync Detalles</a>
          <a href="/sync/reportes_sync.php" class="px-3 py-2 rounded-lg bg-white/10 border border-white/20 hover:bg-white/20">Reportes Sync</a>
          <a href="/sync/errores_cola.php" class="px-3 py-2 rounded-lg bg-amber-400 text-slate-900 hover:bg-amber-300">Errores de Cola</a>
          <a href="/sync/configuracion_sincronizacion.php" class="px-3 py-2 rounded-lg bg-white/10 border border-white/20 hover:bg-white/20">Configuración Sync</a>
        </div>
      </section>

      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="bg-white border rounded-2xl p-5 shadow-sm">
          <div class="text-xs text-gray-500">Última ejecución Sync</div>
          <div class="mt-2 flex items-center gap-2">
            <span class="px-2 py-1 text-xs rounded-lg border <?= ($syncStatus === 'OK' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : (($syncStatus === 'ERROR') ? 'bg-rose-50 text-rose-700 border-rose-200' : 'bg-gray-50 text-gray-700 border-gray-200')) ?>">
              <?= e($syncStatus) ?>
            </span>
            <span class="text-xs text-gray-500"><?= e($syncDate) ?></span>
          </div>
          <div class="text-xs text-gray-500 mt-2">Duración: <b><?= e($syncDuration) ?></b></div>
          <div class="text-xs text-gray-600 mt-1 break-words"><?= e($syncMessage) ?></div>
        </div>

        <div class="bg-white border rounded-2xl p-5 shadow-sm">
          <div class="text-xs text-gray-500">Sync último reporte</div>
          <div class="grid grid-cols-2 gap-2 mt-2 text-sm">
            <div class="rounded-lg bg-gray-50 border px-3 py-2">Detectados: <b><?= (int)$syncDetected ?></b></div>
            <div class="rounded-lg bg-gray-50 border px-3 py-2">Procesados: <b><?= (int)$syncProcessed ?></b></div>
            <div class="rounded-lg bg-gray-50 border px-3 py-2">Omitidos: <b><?= (int)$syncSkipped ?></b></div>
            <div class="rounded-lg bg-rose-50 border border-rose-100 px-3 py-2 text-rose-700">Errores: <b><?= (int)$syncErrors ?></b></div>
          </div>
        </div>

        <div class="bg-white border rounded-2xl p-5 shadow-sm">
          <div class="text-xs text-gray-500">Colaboradores (Estado A)</div>
          <div class="text-3xl font-semibold mt-2"><?= (int)$activos ?></div>
          <div class="text-xs text-gray-500 mt-1">Total BD: <b><?= (int)$totalEmpleados ?></b> · Inactivos: <b><?= (int)$inactivos ?></b></div>
          <div class="mt-3 w-full bg-gray-100 rounded-full h-2 overflow-hidden">
            <div class="h-2 bg-indigo-500 rounded-full" style="width: <?= (int)$porcSyncActivos ?>%"></div>
          </div>
          <div class="text-xs text-gray-500 mt-2">Vinculados BUK: <b><?= (int)$activosConBuk ?></b> · Pendientes: <b><?= (int)$activosPendientesBuk ?></b></div>
        </div>

        <div class="bg-white border rounded-2xl p-5 shadow-sm">
          <div class="text-xs text-gray-500">Cola de Cambios</div>
          <div class="grid grid-cols-3 gap-2 mt-2 text-xs">
            <div class="rounded-lg bg-amber-50 border border-amber-100 text-amber-800 px-2 py-2">Pendiente<br><b><?= (int)$colaPending ?></b></div>
            <div class="rounded-lg bg-rose-50 border border-rose-100 text-rose-700 px-2 py-2">Error<br><b><?= (int)$colaError ?></b></div>
            <div class="rounded-lg bg-gray-50 border px-2 py-2">Ignorado<br><b><?= (int)$colaIgnoredManual ?></b></div>
          </div>
          <a href="/sync/errores_cola.php" class="inline-block mt-3 text-xs px-3 py-1.5 rounded-lg bg-slate-800 text-white hover:bg-black">Gestionar cola</a>
        </div>
      </section>

      <section class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white border rounded-2xl p-5 shadow-sm">
          <h2 class="text-lg font-semibold flex items-center gap-2"><i class="fa-solid fa-diagram-project text-indigo-600"></i> Pipeline último Sync</h2>
          <?php if (empty($pipelineRows)): ?>
            <div class="text-sm text-gray-500 mt-3">Sin pipeline detallado en `latest_report.json`.</div>
          <?php else: ?>
            <div class="mt-3 overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead>
                  <tr class="text-left text-gray-500 border-b">
                    <th class="py-2 pr-3">Proceso</th>
                    <th class="py-2 pr-3">Tipo</th>
                    <th class="py-2 pr-3">Estado</th>
                    <th class="py-2 pr-3">Mensaje</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($pipelineRows as $row): ?>
                    <tr class="border-b last:border-b-0 align-top">
                      <td class="py-2 pr-3 font-medium"><?= e($row['file']) ?></td>
                      <td class="py-2 pr-3"><?= e($row['tipo']) ?></td>
                      <td class="py-2 pr-3">
                        <span class="px-2 py-1 text-xs rounded-lg border <?= ($row['status'] === 'OK' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : (($row['status'] === 'ERROR') ? 'bg-rose-50 text-rose-700 border-rose-200' : 'bg-gray-50 text-gray-700 border-gray-200')) ?>">
                          <?= e($row['status']) ?>
                        </span>
                      </td>
                      <td class="py-2 pr-3 text-gray-600 break-words"><?= e($row['message'] ?: '—') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="bg-white border rounded-2xl p-5 shadow-sm">
          <h2 class="text-lg font-semibold flex items-center gap-2"><i class="fa-solid fa-database text-cyan-700"></i> Estado BD Local vs BUK</h2>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3 text-sm">
            <div class="rounded-xl border p-3 bg-blue-50/40">
              <div class="font-semibold text-blue-900">Ficha</div>
              <div class="mt-1">OK: <b><?= (int)$fichaOk ?></b></div>
              <div>ERROR: <b><?= (int)$fichaErr ?></b></div>
            </div>
            <div class="rounded-xl border p-3 bg-indigo-50/40">
              <div class="font-semibold text-indigo-900">Plan</div>
              <div class="mt-1">OK: <b><?= (int)$planOk ?></b></div>
              <div>ERROR: <b><?= (int)$planErr ?></b></div>
            </div>
            <div class="rounded-xl border p-3 bg-violet-50/40">
              <div class="font-semibold text-violet-900">Job</div>
              <div class="mt-1">OK: <b><?= (int)$jobOk ?></b></div>
              <div>ERROR: <b><?= (int)$jobErr ?></b></div>
            </div>
          </div>
          <div class="mt-4 text-xs text-gray-500">
            Jerarquía BD: Divisiones <b><?= (int)$divisiones ?></b> · CC <b><?= (int)$centrosCosto ?></b> · Unidades <b><?= (int)$unidades ?></b> · Cargos mapeados <b><?= (int)$mapeoCargos ?></b>
          </div>
        </div>
      </section>

      <section class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <a href="/sync/sync_detalles.php" class="bg-white border rounded-2xl p-5 shadow-sm hover:shadow-md transition">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-sm text-gray-500">Operación</div>
              <div class="font-semibold mt-1">Monitorear Sync en vivo</div>
            </div>
            <i class="fa-solid fa-gauge-high text-indigo-600"></i>
          </div>
        </a>
        <a href="/sync/reportes_sync.php" class="bg-white border rounded-2xl p-5 shadow-sm hover:shadow-md transition">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-sm text-gray-500">Auditoría</div>
              <div class="font-semibold mt-1">Descargar reportes</div>
            </div>
            <i class="fa-regular fa-file-lines text-cyan-700"></i>
          </div>
        </a>
        <a href="/sync/errores_cola.php" class="bg-white border rounded-2xl p-5 shadow-sm hover:shadow-md transition">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-sm text-gray-500">Intervención</div>
              <div class="font-semibold mt-1">Gestionar errores de cola</div>
            </div>
            <i class="fa-solid fa-triangle-exclamation text-amber-600"></i>
          </div>
        </a>
      </section>

      <!-- ÚLTIMOS LOGS (tabla) -->
      <section class="bg-white border rounded-2xl p-5 shadow-sm">
        <div class="flex items-center justify-between mb-3">
          <h2 class="text-lg font-semibold flex items-center gap-2">
            <i class="fa-solid fa-clock-rotate-left text-gray-600"></i> Últimos logs BUK (STISOFT)
          </h2>
          <div class="text-xs text-gray-500">Últimas 10 ejecuciones</div>
        </div>

        <?php if (!$logsTableExists): ?>
          <div class="text-sm text-gray-500">
            No se detecta tabla <b>stisoft_buk_logs</b> (o falta permiso).
          </div>
        <?php else: ?>
          <div class="overflow-auto">
            <table class="min-w-full text-sm">
              <thead>
                <tr class="text-left text-gray-500 border-b">
                  <th class="py-2 pr-4">Fecha</th>
                  <th class="py-2 pr-4">Tipo</th>
                  <th class="py-2 pr-4">Referencia</th>
                  <th class="py-2 pr-4">Método</th>
                  <th class="py-2 pr-4">HTTP</th>
                  <th class="py-2 pr-4">Endpoint</th>
                </tr>
              </thead>
              <tbody class="text-gray-800">
                <?php if (empty($ultimosLogs)): ?>
                  <tr>
                    <td colspan="6" class="py-4 text-gray-500">No hay logs para mostrar.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($ultimosLogs as $row): ?>
                    <?php
                      $http = (int)($row['respuesta_http'] ?? 0);
                      $badge =
                        ($http >= 200 && $http < 300)
                          ? 'bg-emerald-50 text-emerald-700 border-emerald-100'
                          : 'bg-rose-50 text-rose-700 border-rose-100';
                      $fecha = $row['fecha_any'] ?? '';
                    ?>
                    <tr class="border-b last:border-b-0">
                      <td class="py-2 pr-4 text-gray-600 whitespace-nowrap"><?= e($fecha ?: '—') ?></td>
                      <td class="py-2 pr-4 whitespace-nowrap"><?= e($row['tipo_entidad'] ?? '—') ?></td>
                      <td class="py-2 pr-4 whitespace-nowrap"><?= e($row['referencia_local'] ?? '—') ?></td>
                      <td class="py-2 pr-4 whitespace-nowrap"><?= e($row['metodo_http'] ?? '—') ?></td>
                      <td class="py-2 pr-4 whitespace-nowrap">
                        <span class="inline-flex items-center px-2 py-1 rounded-lg border text-xs <?= $badge ?>">
                          <?= $http ?: '—' ?>
                        </span>
                      </td>
                      <td class="py-2 pr-4 text-gray-600"><?= e($row['endpoint'] ?? '—') ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

    </main>

    <?php include $footerPath; ?>
  </div>
</div>

</body>
</html>

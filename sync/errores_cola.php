<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_admin();

ob_start();

foreach ([
    __DIR__ . '/../includes/bootstrap.php',
    __DIR__ . '/../includes/init.php',
    __DIR__ . '/../partials/bootstrap.php',
    __DIR__ . '/../app/bootstrap.php',
] as $b) {
    if (is_file($b)) {
        require_once $b;
        break;
    }
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function read_json_file(string $file): ?array {
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function parse_ids(array $raw): array {
    $ids = [];
    foreach ($raw as $v) {
        $n = (int)$v;
        if ($n > 0) $ids[$n] = true;
    }
    return array_keys($ids);
}

function bulk_update_status(mysqli $cn, array $ids, string $newStatus, string $lastStage, string $lastError): int {
    if (empty($ids)) return 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "UPDATE `stisoft_cambios_queue`
            SET status=?, last_stage=?, last_error=?, updated_at=NOW()
            WHERE id IN ({$ph})";
    $stmt = $cn->prepare($sql);
    if (!$stmt) return 0;

    $params = array_merge([$newStatus, $lastStage, $lastError], $ids);
    $bindTypes = 'sss' . $types;
    $refs = [];
    $refs[] = &$bindTypes;
    foreach ($params as $k => $v) {
        $refs[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $ok = $stmt->execute();
    $aff = $ok ? (int)$stmt->affected_rows : 0;
    $stmt->close();
    return $aff;
}

$BASE = __DIR__;
$configFile = $BASE . '/storage/sync/config.json';
$cfg = read_json_file($configFile) ?: [];
$dbHost = (string)($cfg['db']['host'] ?? 'localhost');
$dbUser = (string)($cfg['db']['user'] ?? 'nehgryws_stiuser');
$dbPass = (string)($cfg['db']['pass'] ?? 'fs73xig9e9t0');
$dbName = (string)($cfg['db']['name'] ?? 'nehgryws_stisoft');

$msg = null;
$msgType = 'success';
$csrf = csrf_token();
$post_csrf_ok = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate($_POST['csrf_token'] ?? '')) {
    $post_csrf_ok = false;
    $msg = 'La sesión de seguridad expiró. Recarga la pantalla e inténtalo nuevamente.';
    $msgType = 'error';
}

$cn = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($cn->connect_error) {
    $msg = 'Error BD: ' . $cn->connect_error;
    $msgType = 'error';
} else {
    $cn->set_charset('utf8mb4');

    if ($post_csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ignore_selected'])) {
        $ids = parse_ids((array)($_POST['ids'] ?? []));
        $aff = bulk_update_status($cn, $ids, 'ignored_manual', 'IGNORED_MANUAL', 'Ignorado manualmente desde UI');
        $msg = "Ignorados manualmente: {$aff}";
        $msgType = 'success';
    }

    if ($post_csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unignore_selected'])) {
        $ids = parse_ids((array)($_POST['ids_ignored'] ?? []));
        $aff = bulk_update_status($cn, $ids, 'error', 'UNIGNORED_MANUAL', 'Reactivado manualmente desde UI');
        $msg = "Reactivados para reintento: {$aff}";
        $msgType = 'success';
    }
}

$errors = [];
$ignoredManual = [];
$countPending = 0;
$countError = 0;
$countIgnoredManual = 0;

if ($cn && !$cn->connect_error) {
    $resCnt = $cn->query("SELECT
        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS c_pending,
        SUM(CASE WHEN status='error' THEN 1 ELSE 0 END) AS c_error,
        SUM(CASE WHEN status='ignored_manual' THEN 1 ELSE 0 END) AS c_ignored
        FROM stisoft_cambios_queue");
    if ($resCnt) {
        $row = $resCnt->fetch_assoc() ?: [];
        $countPending = (int)($row['c_pending'] ?? 0);
        $countError = (int)($row['c_error'] ?? 0);
        $countIgnoredManual = (int)($row['c_ignored'] ?? 0);
        $resCnt->free();
    }

    $resErr = $cn->query("SELECT id, rut_bd, source_file, source_line, attempts, last_stage, last_http, last_error, updated_at
        FROM stisoft_cambios_queue
        WHERE status='error'
        ORDER BY updated_at DESC, id DESC
        LIMIT 1000");
    if ($resErr) {
        while ($r = $resErr->fetch_assoc()) $errors[] = $r;
        $resErr->free();
    }

    $resIgn = $cn->query("SELECT id, rut_bd, source_file, source_line, attempts, last_stage, last_http, last_error, updated_at
        FROM stisoft_cambios_queue
        WHERE status='ignored_manual'
        ORDER BY updated_at DESC, id DESC
        LIMIT 1000");
    if ($resIgn) {
        while ($r = $resIgn->fetch_assoc()) $ignoredManual[] = $r;
        $resIgn->free();
    }
}

require_once __DIR__ . '/../partials/head.php';
?>
<div class="min-h-screen grid grid-cols-12">
  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php require_once __DIR__ . '/../partials/sidebar.php'; ?>
  </div>
  <main class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col min-h-screen">
    <?php require_once __DIR__ . '/../partials/topbar.php'; ?>
    <div class="max-w-7xl mx-auto w-full px-4 py-6 space-y-6">
      <section class="rounded-3xl border border-slate-200 bg-gradient-to-r from-slate-900 via-amber-700 to-red-700 text-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div class="text-xs uppercase tracking-[0.18em] text-amber-100/80">Sync general</div>
            <h1 class="text-2xl md:text-3xl font-semibold mt-2 flex items-center gap-3"><i class="fa-solid fa-triangle-exclamation"></i> Errores de Cola</h1>
            <p class="text-sm text-amber-50/90 mt-3 max-w-3xl">Administra los errores activos de la cola, revisa los registros ignorados manualmente y reactiva elementos cuando quieras que vuelvan al flujo.</p>
          </div>
          <a href="/sync/sync_detalles.php" class="inline-flex items-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-900 hover:bg-slate-100 transition">
            <i class="fa-solid fa-chart-line text-amber-700"></i>
            Volver a Sync Detalles
          </a>
        </div>
      </section>

      <?php if ($msg): ?>
        <div class="p-4 rounded-2xl <?= $msgType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
          <?= e($msg) ?>
        </div>
      <?php endif; ?>

      <div class="grid md:grid-cols-3 gap-3">
        <div class="rounded-2xl border border-amber-200 p-4 bg-amber-50 text-sm"><div class="text-amber-700 text-xs uppercase tracking-wide">Pendientes</div><div class="mt-2 text-2xl font-semibold text-amber-900"><?= e((string)$countPending) ?></div></div>
        <div class="rounded-2xl border border-red-200 p-4 bg-red-50 text-sm"><div class="text-red-700 text-xs uppercase tracking-wide">Errores activos</div><div class="mt-2 text-2xl font-semibold text-red-900"><?= e((string)$countError) ?></div></div>
        <div class="rounded-2xl border border-slate-200 p-4 bg-slate-50 text-sm"><div class="text-slate-500 text-xs uppercase tracking-wide">Ignorados manualmente</div><div class="mt-2 text-2xl font-semibold text-slate-900"><?= e((string)$countIgnoredManual) ?></div></div>
      </div>

      <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-200">
        <h2 class="text-lg font-semibold text-slate-900 mb-2">Errores activos</h2>
        <p class="text-sm text-slate-500 mb-4">Estos registros se reintentan en cada ejecución. Puedes seleccionarlos para sacarlos manualmente del flujo.</p>

        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="ignore_selected" value="1">
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm border border-slate-200 rounded-2xl overflow-hidden">
              <thead class="bg-slate-50">
                <tr>
                  <th class="p-2 border">
                    <label class="inline-flex items-center gap-2 text-xs">
                      <input type="checkbox" id="select-all-errors">
                      <span>Sel</span>
                    </label>
                  </th>
                  <th class="p-2 border">ID</th>
                  <th class="p-2 border">RUT</th>
                  <th class="p-2 border">Archivo</th>
                  <th class="p-2 border">Etapa</th>
                  <th class="p-2 border">HTTP</th>
                  <th class="p-2 border">Intentos</th>
                  <th class="p-2 border">Error</th>
                  <th class="p-2 border">Actualizado</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($errors)): ?>
                  <tr><td class="p-3 border text-gray-500" colspan="9">Sin errores activos.</td></tr>
                <?php else: ?>
                  <?php foreach ($errors as $row): ?>
                    <tr class="align-top">
                      <td class="p-2 border"><input type="checkbox" class="row-error-cb" name="ids[]" value="<?= e((string)$row['id']) ?>"></td>
                      <td class="p-2 border"><?= e((string)$row['id']) ?></td>
                      <td class="p-2 border"><?= e((string)$row['rut_bd']) ?></td>
                      <td class="p-2 border"><?= e((string)$row['source_file']) ?>:<?= e((string)$row['source_line']) ?></td>
                      <td class="p-2 border"><?= e((string)$row['last_stage']) ?></td>
                      <td class="p-2 border"><?= e((string)$row['last_http']) ?></td>
                      <td class="p-2 border"><?= e((string)$row['attempts']) ?></td>
                      <td class="p-2 border break-words max-w-xl"><?= e((string)$row['last_error']) ?></td>
                      <td class="p-2 border"><?= e((string)$row['updated_at']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="mt-3">
            <button class="px-4 py-3 rounded-2xl bg-amber-500 text-slate-950 font-semibold hover:bg-amber-400 transition">Marcar seleccionados como ignorados</button>
          </div>
        </form>
      </div>

      <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-200">
        <h2 class="text-lg font-semibold text-slate-900 mb-2">Ignorados manualmente</h2>
        <p class="text-sm text-slate-500 mb-4">Estos registros no se reintentan ni aparecen en reportes o correos hasta que los reactives.</p>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="unignore_selected" value="1">
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm border border-slate-200 rounded-2xl overflow-hidden">
              <thead class="bg-slate-50">
                <tr>
                  <th class="p-2 border">
                    <label class="inline-flex items-center gap-2 text-xs">
                      <input type="checkbox" id="select-all-ignored">
                      <span>Sel</span>
                    </label>
                  </th>
                  <th class="p-2 border">ID</th>
                  <th class="p-2 border">RUT</th>
                  <th class="p-2 border">Archivo</th>
                  <th class="p-2 border">Etapa</th>
                  <th class="p-2 border">HTTP</th>
                  <th class="p-2 border">Intentos</th>
                  <th class="p-2 border">Detalle</th>
                  <th class="p-2 border">Actualizado</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($ignoredManual)): ?>
                  <tr><td class="p-3 border text-gray-500" colspan="9">Sin elementos ignorados manualmente.</td></tr>
                <?php else: ?>
                  <?php foreach ($ignoredManual as $row): ?>
                    <tr class="align-top">
                      <td class="p-2 border"><input type="checkbox" class="row-ignored-cb" name="ids_ignored[]" value="<?= e((string)$row['id']) ?>"></td>
                      <td class="p-2 border"><?= e((string)$row['id']) ?></td>
                      <td class="p-2 border"><?= e((string)$row['rut_bd']) ?></td>
                      <td class="p-2 border"><?= e((string)$row['source_file']) ?>:<?= e((string)$row['source_line']) ?></td>
                      <td class="p-2 border"><?= e((string)$row['last_stage']) ?></td>
                      <td class="p-2 border"><?= e((string)$row['last_http']) ?></td>
                      <td class="p-2 border"><?= e((string)$row['attempts']) ?></td>
                      <td class="p-2 border break-words max-w-xl"><?= e((string)$row['last_error']) ?></td>
                      <td class="p-2 border"><?= e((string)$row['updated_at']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="mt-3">
            <button class="px-4 py-3 rounded-2xl bg-cyan-700 text-white font-semibold hover:bg-cyan-800 transition">Reactivar seleccionados para reintento</button>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>
<script>
(function () {
  function wireSelectAll(masterId, itemSelector) {
    var master = document.getElementById(masterId);
    if (!master) return;

    function items() {
      return Array.prototype.slice.call(document.querySelectorAll(itemSelector));
    }

    function syncMaster() {
      var list = items();
      if (!list.length) {
        master.checked = false;
        master.indeterminate = false;
        master.disabled = true;
        return;
      }
      master.disabled = false;
      var checked = list.filter(function (el) { return el.checked; }).length;
      master.checked = checked === list.length;
      master.indeterminate = checked > 0 && checked < list.length;
    }

    master.addEventListener('change', function () {
      var on = !!master.checked;
      items().forEach(function (el) { el.checked = on; });
      syncMaster();
    });

    document.addEventListener('change', function (ev) {
      var t = ev.target;
      if (!(t instanceof HTMLInputElement)) return;
      if (t.matches(itemSelector)) syncMaster();
    });

    syncMaster();
  }

  wireSelectAll('select-all-errors', '.row-error-cb');
  wireSelectAll('select-all-ignored', '.row-ignored-cb');
})();
</script>
</body>
</html>
<?php ob_end_flush(); ?>

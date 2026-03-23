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
function ensure_dir(string $p): void { if (!is_dir($p)) @mkdir($p, 0775, true); }
function read_json(string $f): ?array {
    if (!is_file($f)) return null;
    $raw = file_get_contents($f);
    if ($raw === false || trim($raw) === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}
function map_status_chip_class(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'ok') return 'bg-emerald-50 text-emerald-700 border border-emerald-200';
    if ($s === 'error') return 'bg-red-50 text-red-700 border border-red-200';
    if ($s === 'unsupported') return 'bg-amber-50 text-amber-800 border border-amber-200';
    return 'bg-gray-50 text-gray-700 border border-gray-200';
}
function parse_sync_result_from_output(?string $output): ?array {
    $output = (string)($output ?? '');
    if ($output === '') return null;
    if (preg_match('/SYNC_RESULT=(\{.*\})/s', $output, $m)) {
        $json = json_decode($m[1], true);
        if (is_array($json)) return $json;
    }
    return null;
}

function normalize_buk_empleados_stage(string $rawStage): string {
    $s = strtoupper(trim($rawStage));
    if ($s === 'EMP' || $s === 'EMP_ATTR') return 'Ficha';
    if ($s === 'PLAN') return 'Plan';
    if ($s === 'JOB' || $s === 'LEADER' || $s === 'JOB_SKIP') return 'Job';
    return 'Job';
}

$BASE = __DIR__;
$dirSync = $BASE . '/storage/sync';
$dirLogs = $BASE . '/storage/logs';
ensure_dir($dirSync);
ensure_dir($dirLogs);
$config_file = $dirSync . '/config.json';
$latest_report_file = $dirSync . '/latest_report.json';
$log_file = $dirLogs . '/sync.log';
$config = read_json($config_file) ?: [];

$msg = null;
$msg_type = 'success';
$probe_msg = null;
$probe_ok = false;
$run_now_output = '';
$run_now_result = null;
$csrf = csrf_token();
$post_csrf_ok = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate($_POST['csrf_token'] ?? '')) {
    $post_csrf_ok = false;
    $msg = 'La sesión de seguridad expiró. Recarga la pantalla e inténtalo nuevamente.';
    $msg_type = 'error';
}

if ($post_csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['probe'])) {
    if (($config['mode'] ?? 'local') === 'local') {
        $ok_inbox = is_dir((string)($config['paths']['local_inbox'] ?? ''));
        $ok_out = is_dir((string)($config['paths']['download_dir'] ?? ''));
        $probe_ok = $ok_inbox && $ok_out;
        $probe_msg = $probe_ok ? 'Local OK · inbox y destino existen.' : 'Local con problemas · inbox o destino no existen.';
    } else {
        $vendor = dirname($BASE) . '/vendor/autoload.php';
        if (is_file($vendor)) {
            require_once $vendor;
            try {
                $s = (array)($config['sftp'] ?? []);
                $client = new \phpseclib3\Net\SFTP((string)($s['host'] ?? ''), (int)($s['port'] ?? 22), 10);
                if ($client->login((string)($s['username'] ?? ''), (string)($s['password'] ?? ''))) {
                    $probe_ok = true;
                    $probe_msg = 'SFTP OK · login correcto.';
                } else {
                    $probe_msg = 'SFTP FAIL · credenciales inválidas.';
                }
            } catch (Throwable $t) {
                $probe_msg = 'SFTP FAIL · ' . $t->getMessage();
            }
        } else {
            $probe_msg = 'SFTP en modo maqueta (phpseclib no instalado).';
        }
    }
}

if ($post_csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_now'])) {
    $run = $BASE . '/run_sync.php';
    $php = trim((string)($config['runtime']['php_path'] ?? '/usr/bin/php'));
    $beforeReportMtime = is_file($latest_report_file) ? (int)@filemtime($latest_report_file) : 0;

    if (!is_file($run)) {
        $msg = 'No se encontró run_sync.php';
        $msg_type = 'error';
    } else {
        @set_time_limit(0);
        $cmd = $php . ' ' . escapeshellarg($run) . ' 2>&1';
        $outLines = [];
        $exitCode = 0;
        exec($cmd, $outLines, $exitCode);
        $run_now_output = implode("\n", $outLines);
        $run_now_result = parse_sync_result_from_output($run_now_output);
        $afterReportMtime = is_file($latest_report_file) ? (int)@filemtime($latest_report_file) : 0;

        if (strpos($run_now_output, 'Otro proceso en ejecución. Abortando.') !== false) {
            $msg = 'Ya hay una sincronización en ejecución. Espera a que termine.';
            $msg_type = 'error';
        } elseif ($exitCode !== 0) {
            $msg = 'Sync ejecutado con error (exit code ' . $exitCode . ').';
            $msg_type = 'error';
        } elseif ($afterReportMtime <= $beforeReportMtime) {
            $msg = 'Sync ejecutado, pero el reporte no se actualizó todavía.';
            $msg_type = 'error';
        } else {
            $msg = 'Sync ejecutado manualmente. Se actualizaron detalles.';
            $msg_type = 'success';
        }
    }
}

$latest_report = read_json($latest_report_file);
$latest_event = is_array($latest_report['event'] ?? null) ? $latest_report['event'] : [];
$latest_stats = is_array($latest_report['processed_stats'] ?? null) ? $latest_report['processed_stats'] : [];
$latest_files = is_array($latest_stats['files'] ?? null) ? $latest_stats['files'] : [];
$latest_global = is_array($latest_stats['global'] ?? null) ? $latest_stats['global'] : [];

require_once __DIR__ . '/../partials/head.php';
?>
<div class="min-h-screen grid grid-cols-12">
  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php require_once __DIR__ . '/../partials/sidebar.php'; ?>
  </div>
  <main class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col min-h-screen">
    <?php require_once __DIR__ . '/../partials/topbar.php'; ?>
    <div class="max-w-7xl mx-auto w-full px-4 py-6 space-y-6">
      <section class="rounded-3xl border border-slate-200 bg-gradient-to-r from-slate-900 via-cyan-900 to-sky-800 text-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div class="text-xs uppercase tracking-[0.18em] text-cyan-100/80">Sync general</div>
            <h1 class="text-2xl md:text-3xl font-semibold mt-2 flex items-center gap-3">
              <i class="fa-solid fa-chart-line"></i>
              Sync Detalles
            </h1>
            <p class="text-sm text-cyan-50/90 mt-3 max-w-3xl">Monitorea la última ejecución completa, revisa cada etapa procesada y accede a la salida técnica cuando necesites diagnóstico.</p>
          </div>

          <div class="flex flex-wrap gap-3">
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="probe" value="1">
              <button class="inline-flex items-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-900 hover:bg-slate-100 transition">
                <i class="fa-solid fa-plug text-cyan-700"></i>
                Probar conexión
              </button>
            </form>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="run_now" value="1">
              <button class="inline-flex items-center gap-2 rounded-2xl bg-emerald-400 px-4 py-3 text-sm font-semibold text-slate-950 shadow-[0_16px_40px_rgba(16,185,129,0.35)] hover:bg-emerald-300 transition">
                <i class="fa-solid fa-play"></i>
                Ejecutar sync ahora
              </button>
            </form>
          </div>
        </div>
      </section>

      <?php if ($msg): ?>
        <div class="p-4 rounded-2xl border <?= $msg_type === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200' ?>"><?= e($msg) ?></div>
      <?php endif; ?>
      <?php if ($probe_msg !== null): ?>
        <div class="p-4 rounded-2xl border <?= $probe_ok ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-800 border-amber-200' ?>"><?= e($probe_msg) ?></div>
      <?php endif; ?>

      <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-200">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
          <div>
            <h2 class="text-lg font-semibold text-slate-900 flex items-center gap-2"><i class="fa-solid fa-wave-square text-cyan-700"></i> Resultado último sync</h2>
            <div class="text-sm text-slate-500 mt-1"><?= e((string)($latest_report['generated_at'] ?? 'Sin ejecución registrada')) ?></div>
          </div>
          <a href="/sync/reportes_sync.php" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
            <i class="fa-regular fa-file-lines"></i>
            Ver reportes sync
          </a>
        </div>

        <?php if (!$latest_report): ?>
          <p class="text-sm text-gray-500">Aún no hay un reporte detallado.</p>
        <?php else: ?>
          <?php
            $eventStatus = (string)($latest_event['status'] ?? 'unknown');
            $chipClass = map_status_chip_class($eventStatus);
            $filesDetected = (int)($latest_global['files_detected'] ?? 0);
            $filesProcessed = (int)($latest_global['files_processed'] ?? 0);
            $filesSkipped = (int)($latest_global['files_skipped'] ?? 0);
            $erroresTotal = (int)($latest_global['errores_total'] ?? 0);
          ?>
          <div class="mb-5 p-4 rounded-2xl <?= $chipClass ?>">
            <div class="font-semibold">Estado: <?= e(strtoupper($eventStatus)) ?></div>
            <div class="text-sm mt-1"><?= e((string)($latest_event['message'] ?? '-')) ?><?php if (!empty($latest_event['duration'])): ?> · Duración: <?= e((string)$latest_event['duration']) ?><?php endif; ?></div>
            <?php if (!empty($latest_event['errors'])): ?><div class="text-sm mt-2">Error global: <?= e((string)$latest_event['errors']) ?></div><?php endif; ?>
          </div>

          <div class="grid md:grid-cols-4 gap-3 mb-5">
            <div class="rounded-2xl border border-slate-200 p-4 bg-slate-50 text-sm"><div class="text-slate-500 text-xs uppercase tracking-wide">Detectados</div><div class="mt-2 text-2xl font-semibold text-slate-900"><?= e((string)$filesDetected) ?></div></div>
            <div class="rounded-2xl border border-slate-200 p-4 bg-slate-50 text-sm"><div class="text-slate-500 text-xs uppercase tracking-wide">Procesados</div><div class="mt-2 text-2xl font-semibold text-slate-900"><?= e((string)$filesProcessed) ?></div></div>
            <div class="rounded-2xl border border-slate-200 p-4 bg-slate-50 text-sm"><div class="text-slate-500 text-xs uppercase tracking-wide">Omitidos</div><div class="mt-2 text-2xl font-semibold text-slate-900"><?= e((string)$filesSkipped) ?></div></div>
            <div class="rounded-2xl border border-amber-200 p-4 bg-amber-50 text-sm"><div class="text-amber-700 text-xs uppercase tracking-wide">Errores</div><div class="mt-2 text-2xl font-semibold text-amber-900"><?= e((string)$erroresTotal) ?></div></div>
          </div>

          <div class="space-y-3">
            <?php foreach ($latest_files as $f): ?>
              <?php $fStatus = (string)($f['status'] ?? 'unknown'); $fClass = map_status_chip_class($fStatus); $fName = (string)($f['file'] ?? '-'); $fScript = (string)($f['script'] ?? '-'); $r = is_array($f['result'] ?? null) ? $f['result'] : []; ?>
              <div class="rounded-2xl border border-slate-200 p-4 bg-white">
                <div class="flex items-start justify-between gap-3">
                  <div><div class="font-semibold text-slate-900"><?= e($fName) ?></div><div class="text-xs text-slate-500 mt-1"><?= e($fScript) ?></div></div>
                  <span class="text-xs px-2 py-1 rounded-lg <?= e($fClass) ?>"><?= e(strtoupper($fStatus)) ?></span>
                </div>
                <?php if (!empty($r['message'])): ?><div class="text-sm mt-2"><strong>Mensaje:</strong> <?= e((string)$r['message']) ?></div><?php endif; ?>

                <?php if (($f['tipo'] ?? '') === 'cambios'): ?>
                  <?php
                    $dt = is_array($r['detail_totals'] ?? null) ? $r['detail_totals'] : [];
                    $isDrain = (strtoupper((string)$fName) === 'PROCESS_CAMBIOS_DRAIN');
                    $isEnqueue = (strtoupper((string)($r['mode'] ?? '')) === 'ENQUEUE_ONLY')
                        || (strpos(strtoupper((string)$fName), 'PROCESS_CAMBIOS_ENQUEUE_') === 0);
                    $maxDetailItems = $isDrain ? 10000 : 8;
                  ?>
                  <div class="grid md:grid-cols-4 gap-2 mt-3 text-sm">
                    <div class="rounded border bg-gray-50 p-2">Detectados: <strong><?= e((string)($dt['detected'] ?? ($r['requested'] ?? 0))) ?></strong></div>
                    <div class="rounded border bg-gray-50 p-2">Encolados: <strong><?= e((string)($dt['queued'] ?? ($r['queued'] ?? 0))) ?></strong></div>
                    <div class="rounded border bg-emerald-50 p-2">OK: <strong><?= e((string)($dt['ok'] ?? ($r['sent_ok'] ?? 0))) ?></strong></div>
                    <div class="rounded border bg-red-50 p-2">ERROR: <strong><?= e((string)($dt['error'] ?? ($r['sent_error'] ?? 0))) ?></strong></div>
                    <div class="rounded border bg-amber-50 p-2">Pendientes: <strong><?= e((string)($dt['pending'] ?? ($r['queue_pending'] ?? 0))) ?></strong></div>
                    <div class="rounded border bg-rose-50 p-2">Errores cola: <strong><?= e((string)($dt['queue_error'] ?? ($r['queue_error'] ?? 0))) ?></strong></div>
                    <div class="rounded border bg-gray-50 p-2">SKIP: <strong><?= e((string)($dt['skip'] ?? ($r['skip'] ?? 0))) ?></strong></div>
                    <div class="rounded border bg-gray-50 p-2">Ignorados legado: <strong><?= e((string)($dt['ignored_legacy'] ?? ($r['ignored'] ?? 0))) ?></strong></div>
                  </div>

                  <?php if ($isDrain): ?>
                    <div class="grid md:grid-cols-4 gap-2 mt-2 text-sm">
                      <div class="rounded border bg-gray-50 p-2">Procesados cola: <strong><?= e((string)($r['queue_processed'] ?? 0)) ?></strong></div>
                      <div class="rounded border bg-emerald-50 p-2">RUT OK: <strong><?= e((string)count((array)($r['changed_ruts'] ?? []))) ?></strong></div>
                      <div class="rounded border bg-red-50 p-2">RUT ERROR: <strong><?= e((string)count((array)($r['error_ruts'] ?? []))) ?></strong></div>
                      <div class="rounded border bg-amber-50 p-2">Items ignorados: <strong><?= e((string)count((array)($r['ignored_items'] ?? []))) ?></strong></div>
                    </div>
                  <?php elseif ($isEnqueue): ?>
                    <div class="grid md:grid-cols-4 gap-2 mt-2 text-sm">
                      <div class="rounded border bg-gray-50 p-2">Modo: <strong>ENQUEUE_ONLY</strong></div>
                      <div class="rounded border bg-gray-50 p-2">Detect errors: <strong><?= e((string)($r['detect_errors'] ?? 0)) ?></strong></div>
                      <div class="rounded border bg-gray-50 p-2">Muestras cambios: <strong><?= e((string)count((array)($r['column_change_samples'] ?? []))) ?></strong></div>
                      <div class="rounded border bg-gray-50 p-2">Columnas con cambio: <strong><?= e((string)count((array)($r['column_change_counts'] ?? []))) ?></strong></div>
                    </div>
                  <?php endif; ?>

                  <div class="mt-3 text-sm">
                    <div class="font-semibold text-emerald-700">OK</div>
                    <?php if (!empty($r['ok_items']) && is_array($r['ok_items'])): ?>
                      <?php foreach (array_slice($r['ok_items'], 0, $maxDetailItems) as $ok): ?>
                        <div class="mt-2 p-2 border rounded bg-emerald-50/40">
                          <div><strong>RUT:</strong> <?= e((string)($ok['rut'] ?? '-')) ?></div>
                          <?php if (!empty($ok['stages']) && is_array($ok['stages'])): ?><div><strong>Etapas:</strong> <?= e(implode(', ', $ok['stages'])) ?></div><?php endif; ?>
                          <?php if (!empty($ok['changes']) && is_array($ok['changes'])): ?>
                            <div class="mt-1"><strong>Cambios (anterior → actual):</strong></div>
                            <ul class="list-disc pl-5">
                              <?php foreach (array_slice($ok['changes'], 0, 6) as $ch): ?>
                                <li><?= e((string)($ch['column'] ?? '-')) ?>: <code><?= e((string)($ch['before'] ?? '')) ?></code> → <code><?= e((string)($ch['after'] ?? '')) ?></code></li>
                              <?php endforeach; ?>
                            </ul>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    <?php elseif (!empty($r['changed_ruts']) && is_array($r['changed_ruts'])): ?>
                      <div class="mt-1 text-gray-700 break-words"><?= e(implode(', ', (array)$r['changed_ruts'])) ?></div>
                    <?php else: ?>
                      <div class="mt-1 text-gray-500">Sin detalles OK en esta corrida.</div>
                    <?php endif; ?>
                  </div>

                  <div class="mt-3 text-sm">
                    <div class="font-semibold text-red-700">ERROR</div>
                    <?php if (!empty($r['failure_summary']) && is_array($r['failure_summary'])): ?>
                      <?php $fs = $r['failure_summary']; ?>
                      <?php if (!empty($fs['by_stage']) && is_array($fs['by_stage'])): ?>
                        <div class="mt-1 text-gray-700">
                          <?php $parts = []; foreach ($fs['by_stage'] as $stage => $cnt) { $parts[] = $stage . ': ' . (int)$cnt; } echo e(implode(' · ', $parts)); ?>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($fs['top_messages']) && is_array($fs['top_messages'])): ?>
                        <div class="mt-1 text-gray-700">
                          <?php
                            $msgParts = [];
                            foreach ($fs['top_messages'] as $tm) {
                                $msgParts[] = (string)($tm['count'] ?? 0) . 'x ' . (string)($tm['message'] ?? '');
                            }
                            echo e(implode(' | ', $msgParts));
                          ?>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($r['failed_items']) && is_array($r['failed_items'])): ?>
                      <?php foreach (array_slice($r['failed_items'], 0, $maxDetailItems) as $it): ?>
                        <div class="mt-2 p-2 border rounded bg-red-50/40">
                          <div><strong>RUT:</strong> <?= e((string)($it['rut'] ?? '-')) ?> · <strong>Etapa:</strong> <?= e((string)($it['stage'] ?? '-')) ?> · <strong>HTTP:</strong> <?= e((string)($it['http'] ?? '0')) ?></div>
                          <div class="break-words"><strong>Mensaje:</strong> <?= e((string)($it['msg'] ?? '-')) ?></div>
                          <?php if (!empty($it['changes']) && is_array($it['changes'])): ?>
                            <div class="mt-1"><strong>Intentaba cambiar:</strong></div>
                            <ul class="list-disc pl-5">
                              <?php foreach (array_slice($it['changes'], 0, 6) as $ch): ?>
                                <li><?= e((string)($ch['column'] ?? '-')) ?>: <code><?= e((string)($ch['before'] ?? '')) ?></code> → <code><?= e((string)($ch['after'] ?? '')) ?></code></li>
                              <?php endforeach; ?>
                            </ul>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="mt-1 text-gray-500">Sin errores en esta corrida.</div>
                    <?php endif; ?>
                  </div>

                  <div class="mt-3 text-sm">
                    <div class="font-semibold text-amber-700">SKIP / IGNORADOS</div>
                    <div class="mt-1 text-gray-700">
                      SKIP: <?= e((string)($r['skip'] ?? 0)) ?> · Ignorados legado: <?= e((string)($r['ignored'] ?? 0)) ?>
                    </div>
                    <?php if (!empty($r['ignored_items']) && is_array($r['ignored_items'])): ?>
                      <?php foreach (array_slice($r['ignored_items'], 0, $maxDetailItems) as $ig): ?>
                        <div class="mt-2 p-2 border rounded bg-amber-50/50">
                          <div><strong>RUT:</strong> <?= e((string)($ig['rut'] ?? '-')) ?> · <?= e((string)($ig['msg'] ?? '')) ?></div>
                          <?php if (!empty($ig['changes']) && is_array($ig['changes'])): ?>
                            <div class="mt-1"><strong>Cambios detectados:</strong></div>
                            <ul class="list-disc pl-5">
                              <?php foreach (array_slice($ig['changes'], 0, 6) as $ch): ?>
                                <li><?= e((string)($ch['column'] ?? '-')) ?>: <code><?= e((string)($ch['before'] ?? '')) ?></code> → <code><?= e((string)($ch['after'] ?? '')) ?></code></li>
                              <?php endforeach; ?>
                            </ul>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>

                  <?php if ($isDrain): ?>
                    <div class="mt-3 text-sm">
                      <div class="font-semibold text-emerald-700">RUT OK (completo)</div>
                      <?php if (!empty($r['changed_ruts']) && is_array($r['changed_ruts'])): ?>
                        <div class="mt-1 p-2 border rounded bg-emerald-50/30 text-xs break-words"><?= e(implode(', ', $r['changed_ruts'])) ?></div>
                      <?php else: ?>
                        <div class="mt-1 text-gray-500 text-xs">Sin RUT OK.</div>
                      <?php endif; ?>
                    </div>

                    <div class="mt-3 text-sm">
                      <div class="font-semibold text-red-700">RUT ERROR (completo)</div>
                      <?php if (!empty($r['error_ruts']) && is_array($r['error_ruts'])): ?>
                        <div class="mt-1 p-2 border rounded bg-red-50/30 text-xs break-words"><?= e(implode(', ', $r['error_ruts'])) ?></div>
                      <?php else: ?>
                        <div class="mt-1 text-gray-500 text-xs">Sin RUT ERROR.</div>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>

                  <?php if ($isEnqueue): ?>
                    <div class="mt-3 text-sm">
                      <div class="font-semibold text-indigo-700">Columnas con cambios detectados (enqueue)</div>
                      <?php if (!empty($r['column_change_counts']) && is_array($r['column_change_counts'])): ?>
                        <div class="mt-1 p-2 border rounded bg-indigo-50/40 text-xs break-words">
                          <?php
                            $parts = [];
                            foreach ((array)$r['column_change_counts'] as $col => $cnt) {
                                $parts[] = (string)$col . ': ' . (int)$cnt;
                            }
                            echo e(implode(' · ', $parts));
                          ?>
                        </div>
                      <?php else: ?>
                        <div class="mt-1 text-gray-500 text-xs">Sin detalle de columnas en esta corrida.</div>
                      <?php endif; ?>
                    </div>

                    <div class="mt-3 text-sm">
                      <div class="font-semibold text-indigo-700">Muestras de cambios detectados (anterior → actual)</div>
                      <?php if (!empty($r['column_change_samples']) && is_array($r['column_change_samples'])): ?>
                        <?php foreach (array_slice($r['column_change_samples'], 0, 200) as $smp): ?>
                          <div class="mt-1 p-2 border rounded bg-indigo-50/20 text-xs break-words">
                            <strong>RUT:</strong> <?= e((string)($smp['rut'] ?? '-')) ?>
                            · <strong>Columna:</strong> <?= e((string)($smp['column'] ?? '-')) ?>
                            · <strong>Antes:</strong> <code><?= e((string)($smp['before'] ?? '')) ?></code>
                            · <strong>Ahora:</strong> <code><?= e((string)($smp['after'] ?? '')) ?></code>
                          </div>
                        <?php endforeach; ?>
                        <?php if (count((array)$r['column_change_samples']) > 200): ?>
                          <div class="mt-1 text-gray-500 text-xs">Se muestran 200 muestras para mantener la carga de la pantalla.</div>
                        <?php endif; ?>
                      <?php else: ?>
                        <div class="mt-1 text-gray-500 text-xs">Sin muestras de cambio en esta corrida.</div>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                <?php elseif (($f['tipo'] ?? '') === 'buk_empleados'): ?>
                  <?php
                    $buk = is_array($r['buk'] ?? null) ? $r['buk'] : [];
                    $fichaOk = (int)($buk['empleados_ok'] ?? 0);
                    $fichaErr = (int)($buk['empleados_error'] ?? 0);
                    $planOk = (int)($buk['plans_ok'] ?? 0);
                    $planErr = (int)($buk['plans_error'] ?? 0);
                    $jobOk = (int)($buk['jobs_ok'] ?? 0);
                    $jobErr = (int)($buk['jobs_error'] ?? 0);
                    $jobSkip = (int)($r['skipped_mapping'] ?? 0);
                    $pending = 0;

                    $failedItems = is_array($r['failed_items'] ?? null) ? $r['failed_items'] : [];
                    $failedBy = ['Ficha' => [], 'Plan' => [], 'Job' => []];
                    foreach ($failedItems as $it) {
                      $grp = normalize_buk_empleados_stage((string)($it['stage'] ?? ''));
                      if (!isset($failedBy[$grp])) $failedBy[$grp] = [];
                      $failedBy[$grp][] = $it;
                    }
                    $okRuts = is_array($r['altas_buk_ok_ruts'] ?? null) ? $r['altas_buk_ok_ruts'] : [];
                    $errRuts = is_array($r['altas_buk_error_ruts'] ?? null) ? $r['altas_buk_error_ruts'] : [];
                    $skipRuts = is_array($r['job_skip_ruts'] ?? null) ? $r['job_skip_ruts'] : [];
                  ?>

                  <div class="mt-3 text-sm">
                    <div class="font-semibold">Etapas BUK Empleados</div>
                    <div class="grid md:grid-cols-3 gap-3 mt-2">
                      <div class="rounded border p-3 bg-blue-50/40">
                        <div class="font-semibold text-blue-800 mb-2">Ficha</div>
                        <div>OK: <strong><?= e((string)$fichaOk) ?></strong></div>
                        <div>ERROR: <strong><?= e((string)$fichaErr) ?></strong></div>
                        <div>PENDIENTE: <strong><?= e((string)$pending) ?></strong></div>
                      </div>
                      <div class="rounded border p-3 bg-indigo-50/40">
                        <div class="font-semibold text-indigo-800 mb-2">Plan</div>
                        <div>OK: <strong><?= e((string)$planOk) ?></strong></div>
                        <div>ERROR: <strong><?= e((string)$planErr) ?></strong></div>
                        <div>PENDIENTE: <strong><?= e((string)$pending) ?></strong></div>
                      </div>
                      <div class="rounded border p-3 bg-violet-50/40">
                        <div class="font-semibold text-violet-800 mb-2">Job</div>
                        <div>OK: <strong><?= e((string)$jobOk) ?></strong></div>
                        <div>ERROR: <strong><?= e((string)$jobErr) ?></strong></div>
                        <div>SKIP: <strong><?= e((string)$jobSkip) ?></strong></div>
                        <div>PENDIENTE: <strong><?= e((string)$pending) ?></strong></div>
                      </div>
                    </div>
                  </div>

                  <div class="mt-3 text-sm">
                    <div class="font-semibold text-red-700">Errores por etapa</div>
                    <?php foreach (['Ficha', 'Plan', 'Job'] as $stageLabel): ?>
                      <div class="mt-2 p-2 border rounded bg-red-50/30">
                        <div class="font-semibold"><?= e($stageLabel) ?> · <?= e((string)count($failedBy[$stageLabel] ?? [])) ?></div>
                        <?php if (!empty($failedBy[$stageLabel])): ?>
                          <?php foreach ($failedBy[$stageLabel] as $it): ?>
                            <div class="mt-1 text-xs">
                              <strong>RUT:</strong> <?= e((string)($it['rut'] ?? '-')) ?>
                              · <strong>Etapa:</strong> <?= e((string)($it['stage'] ?? '-')) ?>
                              · <strong>HTTP:</strong> <?= e((string)($it['http'] ?? 0)) ?>
                              · <strong>Mensaje:</strong> <span class="break-words"><?= e((string)($it['msg'] ?? '-')) ?></span>
                            </div>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <div class="mt-1 text-gray-500 text-xs">Sin errores.</div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <div class="mt-3 text-sm">
                    <div class="font-semibold text-emerald-700">RUT OK</div>
                    <?php if (!empty($okRuts)): ?>
                      <div class="mt-1 p-2 border rounded bg-emerald-50/30 text-xs break-words"><?= e(implode(', ', $okRuts)) ?></div>
                    <?php else: ?>
                      <div class="mt-1 text-gray-500 text-xs">Sin RUT OK en esta corrida.</div>
                    <?php endif; ?>
                  </div>

                  <div class="mt-3 text-sm">
                    <div class="font-semibold text-red-700">RUT ERROR</div>
                    <?php if (!empty($errRuts)): ?>
                      <div class="mt-1 p-2 border rounded bg-red-50/30 text-xs break-words"><?= e(implode(', ', $errRuts)) ?></div>
                    <?php else: ?>
                      <div class="mt-1 text-gray-500 text-xs">Sin RUT ERROR en esta corrida.</div>
                    <?php endif; ?>
                  </div>

                  <div class="mt-3 text-sm">
                    <div class="font-semibold text-amber-700">RUT JOB SKIP</div>
                    <?php if (!empty($skipRuts)): ?>
                      <div class="mt-1 p-2 border rounded bg-amber-50/40 text-xs break-words"><?= e(implode(', ', $skipRuts)) ?></div>
                    <?php else: ?>
                      <div class="mt-1 text-gray-500 text-xs">Sin JOB SKIP en esta corrida.</div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php if (!empty($f['raw_output'])): ?>
                  <details class="mt-3">
                    <summary class="cursor-pointer text-sm text-gray-600">Ver salida técnica</summary>
                    <pre class="mt-2 text-xs bg-gray-900 text-gray-100 rounded-lg p-3 overflow-x-auto max-h-64"><?= e((string)$f['raw_output']) ?></pre>
                  </details>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($run_now_output !== ''): ?>
        <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-200">
          <h2 class="text-lg font-semibold text-slate-900 mb-3">Salida ejecución manual</h2>
          <pre class="text-xs bg-gray-900 text-gray-100 rounded-lg p-3 overflow-x-auto max-h-72"><?= e($run_now_output) ?></pre>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>
<?php ob_end_flush(); ?>

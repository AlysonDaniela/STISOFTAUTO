<?php
// /sync/configuracion_sincronizacion.php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_admin();

ob_start();

// Bootstrap opcional
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

function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function ensure_dir(string $p): void {
    if (!is_dir($p)) {
        @mkdir($p, 0775, true);
    }
}

function read_json(string $f): ?array {
    if (!is_file($f)) return null;
    $raw = file_get_contents($f);
    if ($raw === false || trim($raw) === '') return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function write_json(string $f, array $d): void {
    ensure_dir(dirname($f));
    file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function get_bool($v, bool $default = false): bool {
    if ($v === null) return $default;
    if (is_bool($v)) return $v;
    $s = strtolower(trim((string)$v));
    if (in_array($s, ['1', 'true', 'yes', 'si', 'on'], true)) return true;
    if (in_array($s, ['0', 'false', 'no', 'off'], true)) return false;
    return $default;
}

function sync_file_source_defaults(): array {
    return [
        'empleados_maipo' => [
            'enabled' => true,
            'remote_dir' => '/',
            'prefix' => 'EMPLEADOS_MAIPO_',
            'alt_prefix' => 'EMPLEADOS_MAIPO_TEST',
            'label' => 'Empleados MAIPO',
        ],
        'empleados_sti' => [
            'enabled' => true,
            'remote_dir' => '/RENTA_FIJA',
            'prefix' => 'EMPLEADOS_STI_',
            'alt_prefix' => '',
            'label' => 'Empleados STI',
        ],
        'vacaciones' => [
            'enabled' => true,
            'remote_dir' => '/',
            'prefix' => 'VACACIONES_TERM_',
            'alt_prefix' => '',
            'label' => 'Vacaciones',
        ],
        'eventuales_maipo' => [
            'enabled' => false,
            'remote_dir' => '/',
            'prefix' => 'EVENTUALES_MAIPO_',
            'alt_prefix' => '',
            'label' => 'Eventuales MAIPO',
        ],
    ];
}

function merge_file_sources(?array $current): array {
    $defaults = sync_file_source_defaults();
    $current = is_array($current) ? $current : [];
    foreach ($defaults as $key => $def) {
        $row = is_array($current[$key] ?? null) ? $current[$key] : [];
        $defaults[$key] = [
            'enabled' => get_bool($row['enabled'] ?? $def['enabled'], $def['enabled']),
            'remote_dir' => trim((string)($row['remote_dir'] ?? $def['remote_dir'])),
            'prefix' => trim((string)($row['prefix'] ?? $def['prefix'])),
            'alt_prefix' => trim((string)($row['alt_prefix'] ?? $def['alt_prefix'])),
            'label' => $def['label'],
        ];
    }
    return $defaults;
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

function build_cron_expr(string $preset, string $daily, string $custom): string {
    if ($preset === 'DAILY_AT') {
        [$h, $m] = array_pad(explode(':', $daily), 2, '00');
        $h = max(0, min(23, (int)$h));
        $m = max(0, min(59, (int)$m));
        return $m . ' ' . $h . ' * * *';
    }

    if ($preset === 'CUSTOM') {
        $custom = trim($custom);
        return $custom !== '' ? $custom : '*/5 * * * *';
    }

    return $preset;
}

function build_cron_block(array $config, string $baseDir, string $logFile): string {
    $cron = trim((string)($config['schedule']['cron'] ?? '*/5 * * * *'));
    $php  = trim((string)($config['runtime']['php_path'] ?? '/usr/bin/php'));
    $tz   = trim((string)($config['schedule']['timezone'] ?? 'America/Santiago'));
    $run  = rtrim($baseDir, '/') . '/run_sync.php';

    return implode("\n", [
        '# >>> STISOFT_SYNC >>>',
        'CRON_TZ=' . $tz,
        $cron . ' ' . $php . ' ' . escapeshellarg($run) . ' >> ' . escapeshellarg($logFile) . ' 2>&1',
        '# <<< STISOFT_SYNC <<<',
    ]);
}

function install_crontab_block(array $config, string $baseDir, string $logFile): array {
    $newBlock = build_cron_block($config, $baseDir, $logFile);

    $current = shell_exec('crontab -l 2>/dev/null');
    $current = is_string($current) ? trim($current) : '';

    $pattern = '/\# >>> STISOFT_SYNC >>>.*?\# <<< STISOFT_SYNC <<</s';
    if ($current !== '' && preg_match($pattern, $current)) {
        $updated = preg_replace($pattern, $newBlock, $current);
    } else {
        $updated = trim($current);
        if ($updated !== '') {
            $updated .= "\n\n";
        }
        $updated .= $newBlock;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'stisoft_cron_');
    if ($tmp === false) {
        return ['ok' => false, 'message' => 'No se pudo crear archivo temporal para crontab.'];
    }

    file_put_contents($tmp, $updated . "\n");
    $out = shell_exec('crontab ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);

    return [
        'ok' => true,
        'message' => 'Crontab actualizado correctamente.',
        'output' => trim((string)$out),
        'cron_block' => $newBlock,
    ];
}

$BASE         = __DIR__;
$dirSync      = $BASE . '/storage/sync';
$dirLogs      = $BASE . '/storage/logs';
$dirInbox     = $BASE . '/storage/sftp_inbox';
$dirDescargas = $BASE . '/storage/descargas';

foreach ([$dirSync, $dirLogs, $dirInbox, $dirDescargas] as $d) {
    ensure_dir($d);
}

$config_file = $dirSync . '/config.json';
$log_file    = $dirLogs . '/sync.log';
$latest_report_file = $dirSync . '/latest_report.json';

$config = read_json($config_file);
if (!$config) {
    $config = [
        'mode' => 'sftp',
        'sftp' => [
            'host' => 'sftp.mock-ejemplo.com',
            'port' => 22,
            'username' => 'mockuser',
            'password' => 'mockpass',
            'remote_path' => '/out'
        ],
        'paths' => [
            'local_inbox' => $dirInbox,
            'download_dir' => $dirDescargas
        ],
        'file_sources' => sync_file_source_defaults(),
        'schedule' => [
            'preset' => '*/5 * * * *',
            'custom' => '',
            'daily_time' => '02:00',
            'timezone' => 'America/Santiago',
            'cron' => '*/5 * * * *'
        ],
        'runtime' => [
            'php_path' => '/usr/bin/php',
            'project_path' => $BASE,
            'log_file' => $log_file
        ],
        'retention' => [
            'report_days' => 90,
            'backup_days' => 60,
            'rliquid_report_days' => 90,
            'rliquid_pdf_days' => 90,
            'log_days' => 30,
        ],
        'backup' => [
            'enabled' => true,
            'before_sync' => true,
            'dir' => $BASE . '/storage/backups/db',
            'db_host' => 'localhost',
            'db_user' => 'nehgryws_stiuser',
            'db_pass' => 'fs73xig9e9t0',
            'db_name' => 'nehgryws_stisoft',
        ],
        'db' => [
            'host' => 'localhost',
            'user' => 'nehgryws_stiuser',
            'pass' => 'fs73xig9e9t0',
            'name' => 'nehgryws_stisoft',
        ],
        'buk' => [
            'base_url' => 'https://sti.buk.cl/api/v1/chile',
            'token' => 'bAVH6fNSraVT17MBv1ECPrfW',
        ],
    ];
    write_json($config_file, $config);
}

$config['file_sources'] = merge_file_sources($config['file_sources'] ?? null);

$config['retention'] = is_array($config['retention'] ?? null) ? $config['retention'] : [];
$config['retention']['report_days'] = max(1, (int)($config['retention']['report_days'] ?? 90));
$config['retention']['backup_days'] = max(1, (int)($config['retention']['backup_days'] ?? 60));
$config['retention']['rliquid_report_days'] = max(1, (int)($config['retention']['rliquid_report_days'] ?? 90));
$config['retention']['rliquid_pdf_days'] = max(1, (int)($config['retention']['rliquid_pdf_days'] ?? 90));
$config['retention']['log_days'] = max(1, (int)($config['retention']['log_days'] ?? 30));

$config['backup'] = is_array($config['backup'] ?? null) ? $config['backup'] : [];
$config['backup']['enabled'] = get_bool($config['backup']['enabled'] ?? true, true);
$config['backup']['before_sync'] = get_bool($config['backup']['before_sync'] ?? true, true);
$config['backup']['dir'] = trim((string)($config['backup']['dir'] ?? ($BASE . '/storage/backups/db')));
$config['backup']['db_host'] = trim((string)($config['backup']['db_host'] ?? 'localhost'));
$config['backup']['db_user'] = trim((string)($config['backup']['db_user'] ?? 'nehgryws_stiuser'));
$config['backup']['db_pass'] = (string)($config['backup']['db_pass'] ?? 'fs73xig9e9t0');
$config['backup']['db_name'] = trim((string)($config['backup']['db_name'] ?? 'nehgryws_stisoft'));
$config['db'] = is_array($config['db'] ?? null) ? $config['db'] : [];
$config['db']['host'] = trim((string)($config['db']['host'] ?? 'localhost'));
$config['db']['user'] = trim((string)($config['db']['user'] ?? 'nehgryws_stiuser'));
$config['db']['pass'] = (string)($config['db']['pass'] ?? 'fs73xig9e9t0');
$config['db']['name'] = trim((string)($config['db']['name'] ?? 'nehgryws_stisoft'));
$config['buk'] = is_array($config['buk'] ?? null) ? $config['buk'] : [];
$config['buk']['base_url'] = trim((string)($config['buk']['base_url'] ?? 'https://sti.buk.cl/api/v1/chile'));
$config['buk']['token'] = (string)($config['buk']['token'] ?? 'bAVH6fNSraVT17MBv1ECPrfW');

if (!is_file($log_file)) {
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . "] Log iniciado\n");
}

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

// Guardar configuración + instalar crontab real
if ($post_csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $mode = 'sftp';

    $paths_local_inbox = trim($_POST['paths_local_inbox'] ?? $config['paths']['local_inbox']);
    $paths_download    = trim($_POST['paths_download'] ?? $config['paths']['download_dir']);
    $fileSources = merge_file_sources($config['file_sources'] ?? null);
    foreach ($fileSources as $key => $row) {
        $fileSources[$key]['enabled'] = isset($_POST['file_sources'][$key]['enabled']) ? get_bool($_POST['file_sources'][$key]['enabled'], true) : false;
        $fileSources[$key]['remote_dir'] = trim((string)($_POST['file_sources'][$key]['remote_dir'] ?? $row['remote_dir']));
        $fileSources[$key]['prefix'] = trim((string)($_POST['file_sources'][$key]['prefix'] ?? $row['prefix']));
        $fileSources[$key]['alt_prefix'] = trim((string)($_POST['file_sources'][$key]['alt_prefix'] ?? $row['alt_prefix']));
    }

    $sftp_host = trim($_POST['sftp_host'] ?? $config['sftp']['host']);
    $sftp_port = (int)($_POST['sftp_port'] ?? $config['sftp']['port']);
    $sftp_user = trim($_POST['sftp_user'] ?? $config['sftp']['username']);
    $sftp_pass = (string)($_POST['sftp_pass'] ?? $config['sftp']['password']);
    $sftp_path = trim($_POST['sftp_path'] ?? $config['sftp']['remote_path']);

    $preset   = (string)($_POST['schedule_preset'] ?? ($config['schedule']['preset'] ?? '*/5 * * * *'));
    $daily    = (string)($_POST['schedule_daily_time'] ?? ($config['schedule']['daily_time'] ?? '02:00'));
    $custom   = trim((string)($_POST['schedule_custom'] ?? ($config['schedule']['custom'] ?? '')));
    $timezone = trim((string)($_POST['schedule_timezone'] ?? ($config['schedule']['timezone'] ?? 'America/Santiago')));

    $php_path = trim((string)($_POST['php_path'] ?? ($config['runtime']['php_path'] ?? '/usr/bin/php')));
    $report_days = max(1, (int)($_POST['retention_report_days'] ?? ($config['retention']['report_days'] ?? 90)));
    $backup_days = max(1, (int)($_POST['retention_backup_days'] ?? ($config['retention']['backup_days'] ?? 60)));
    $rliquid_report_days = max(1, (int)($_POST['retention_rliquid_report_days'] ?? ($config['retention']['rliquid_report_days'] ?? 90)));
    $rliquid_pdf_days = max(1, (int)($_POST['retention_rliquid_pdf_days'] ?? ($config['retention']['rliquid_pdf_days'] ?? 90)));
    $log_days = max(1, (int)($_POST['retention_log_days'] ?? ($config['retention']['log_days'] ?? 30)));

    $backup_enabled = isset($_POST['backup_enabled']) ? get_bool($_POST['backup_enabled'], true) : false;
    $backup_before_sync = isset($_POST['backup_before_sync']) ? get_bool($_POST['backup_before_sync'], true) : false;
    $backup_dir = trim((string)($_POST['backup_dir'] ?? ($config['backup']['dir'] ?? ($BASE . '/storage/backups/db'))));
    $backup_db_host = trim((string)($_POST['backup_db_host'] ?? ($config['backup']['db_host'] ?? 'localhost')));
    $backup_db_user = trim((string)($_POST['backup_db_user'] ?? ($config['backup']['db_user'] ?? '')));
    $backup_db_pass = (string)($_POST['backup_db_pass'] ?? ($config['backup']['db_pass'] ?? ''));
    $backup_db_name = trim((string)($_POST['backup_db_name'] ?? ($config['backup']['db_name'] ?? '')));
    $app_db_host = trim((string)($_POST['app_db_host'] ?? ($config['db']['host'] ?? 'localhost')));
    $app_db_user = trim((string)($_POST['app_db_user'] ?? ($config['db']['user'] ?? '')));
    $app_db_pass = (string)($_POST['app_db_pass'] ?? ($config['db']['pass'] ?? ''));
    $app_db_name = trim((string)($_POST['app_db_name'] ?? ($config['db']['name'] ?? '')));
    $buk_base_url = trim((string)($_POST['buk_base_url'] ?? ($config['buk']['base_url'] ?? 'https://sti.buk.cl/api/v1/chile')));
    $buk_token = (string)($_POST['buk_token'] ?? ($config['buk']['token'] ?? ''));

    $cron = build_cron_expr($preset, $daily, $custom);

    $config['mode'] = $mode;
    $config['paths']['local_inbox']  = $paths_local_inbox ?: $config['paths']['local_inbox'];
    $config['paths']['download_dir'] = $paths_download ?: $config['paths']['download_dir'];
    $config['file_sources'] = $fileSources;

    $config['sftp'] = [
        'host' => $sftp_host ?: $config['sftp']['host'],
        'port' => $sftp_port ?: 22,
        'username' => $sftp_user ?: $config['sftp']['username'],
        'password' => $sftp_pass,
        'remote_path' => $sftp_path ?: $config['sftp']['remote_path'],
    ];

    $config['schedule'] = [
        'preset' => $preset,
        'custom' => $custom,
        'daily_time' => $daily,
        'timezone' => $timezone,
        'cron' => $cron,
    ];

    $config['runtime']['php_path'] = $php_path;
    $config['runtime']['project_path'] = $BASE;
    $config['runtime']['log_file'] = $log_file;
    $config['retention'] = [
        'report_days' => $report_days,
        'backup_days' => $backup_days,
        'rliquid_report_days' => $rliquid_report_days,
        'rliquid_pdf_days' => $rliquid_pdf_days,
        'log_days' => $log_days,
    ];
    $config['backup'] = [
        'enabled' => $backup_enabled,
        'before_sync' => $backup_before_sync,
        'dir' => $backup_dir !== '' ? $backup_dir : ($BASE . '/storage/backups/db'),
        'db_host' => $backup_db_host !== '' ? $backup_db_host : 'localhost',
        'db_user' => $backup_db_user,
        'db_pass' => $backup_db_pass,
        'db_name' => $backup_db_name,
    ];
    $config['db'] = [
        'host' => $app_db_host !== '' ? $app_db_host : 'localhost',
        'user' => $app_db_user,
        'pass' => $app_db_pass,
        'name' => $app_db_name,
    ];
    $config['buk'] = [
        'base_url' => $buk_base_url !== '' ? rtrim($buk_base_url, '/') : 'https://sti.buk.cl/api/v1/chile',
        'token' => $buk_token,
    ];

    write_json($config_file, $config);

    $cronInstall = install_crontab_block($config, $BASE, $log_file);

    if ($cronInstall['ok']) {
        $msg = 'Configuración guardada y programación aplicada correctamente.';
        $msg_type = 'success';
    } else {
        $msg = 'Configuración guardada, pero no se pudo actualizar el crontab: ' . ($cronInstall['message'] ?? '');
        $msg_type = 'error';
    }
}

// Probar conexión
if ($post_csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['probe'])) {
    if (($config['mode'] ?? 'local') === 'local') {
        $ok_inbox = is_dir($config['paths']['local_inbox']);
        $ok_out   = is_dir($config['paths']['download_dir']);
        $probe_ok = $ok_inbox && $ok_out;
        $probe_msg = $probe_ok
            ? 'Local OK · inbox y destino existen.'
            : 'Local con problemas · inbox o destino no existen.';
    } else {
        $vendor = dirname($BASE) . '/vendor/autoload.php';
        if (is_file($vendor)) {
            require_once $vendor;
            try {
                $s = $config['sftp'];
                $client = new \phpseclib3\Net\SFTP($s['host'], (int)$s['port'], 10);
                if ($client->login($s['username'], $s['password'])) {
                    $probe_ok = true;
                    $probe_msg = 'SFTP OK · login correcto. Ruta remota: ' . $s['remote_path'];
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

// Ejecutar sync ahora
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
            $msg = 'Ya hay una sincronización en ejecución. Espera a que termine y vuelve a actualizar la pantalla.';
            $msg_type = 'error';
        } elseif ($exitCode !== 0) {
            $msg = 'Sync ejecutado con error (exit code ' . $exitCode . '). Revisa salida técnica/log.';
            $msg_type = 'error';
        } elseif ($afterReportMtime <= $beforeReportMtime) {
            $msg = 'Sync ejecutado, pero el reporte no se actualizó todavía. Puede seguir procesando en segundo plano.';
            $msg_type = 'error';
        } else {
            $msg = 'Sync ejecutado manualmente. Se actualizaron detalles abajo.';
            $msg_type = 'success';
        }

        if ($run_now_output !== '') {
            error_log("STISOFT RUN_NOW OUTPUT:\n" . $run_now_output);
        }
    }
}

$latest_report = read_json($latest_report_file);
$latest_event = is_array($latest_report['event'] ?? null) ? $latest_report['event'] : [];
$latest_stats = is_array($latest_report['processed_stats'] ?? null) ? $latest_report['processed_stats'] : [];
$latest_files = is_array($latest_stats['files'] ?? null) ? $latest_stats['files'] : [];
$latest_global = is_array($latest_stats['global'] ?? null) ? $latest_stats['global'] : [];

$cron_line = build_cron_block($config, $BASE, $log_file);
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
              <i class="fa-solid fa-gear"></i>
              Configuración de Sincronización
            </h1>
            <p class="text-sm text-cyan-50/90 mt-3 max-w-3xl">Configura el origen del sync, la frecuencia de ejecución, la retención de reportes y los respaldos para mantener el proceso estable y ordenado.</p>
          </div>

          <div class="flex flex-wrap items-center gap-3">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="probe" value="1">
            <button class="inline-flex items-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-900 hover:bg-slate-100 transition">
              <i class="fa-solid fa-plug text-cyan-700"></i> Probar conexión
            </button>
          </form>

          <a href="/sync/sync_detalles.php" class="inline-flex items-center gap-2 rounded-2xl bg-emerald-400 px-4 py-3 text-sm font-semibold text-slate-950 shadow-[0_16px_40px_rgba(16,185,129,0.35)] hover:bg-emerald-300 transition">
            <i class="fa-solid fa-chart-line"></i> Ver Sync Detalles
          </a>
        </div>
      </section>

      <?php if ($msg): ?>
        <div class="p-4 rounded-2xl <?= $msg_type === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
          <?= e($msg) ?>
        </div>
      <?php endif; ?>

      <?php if ($probe_msg !== null): ?>
        <div class="p-4 rounded-2xl <?= $probe_ok ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-50 text-amber-800 border border-amber-200' ?>">
          <?= e($probe_msg) ?>
        </div>
      <?php endif; ?>

      <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-200">
        <h2 class="text-lg font-semibold text-slate-900 flex items-center gap-2 mb-2">
          <i class="fa-solid fa-sliders"></i> Pantalla Solo Configuración
        </h2>
        <p class="text-sm text-gray-600">Los detalles de ejecución, resultados técnicos y errores del flujo se visualizan en una pantalla independiente.</p>
        <a href="/sync/sync_detalles.php" class="inline-flex items-center gap-2 mt-4 px-4 py-3 rounded-2xl border border-slate-200 text-slate-700 font-semibold hover:bg-slate-50 transition">
          <i class="fa-solid fa-chart-line"></i> Ir a Sync Detalles
        </a>
      </div>

      <form method="post" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="save_config" value="1">

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm space-y-5">
          <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-4">
            <div>
              <h2 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
                <i class="fa-solid fa-plug-circle-bolt text-sky-700"></i> Conexiones principales
              </h2>
              <p class="text-sm text-slate-600 mt-1">Parámetros de conexión de la aplicación y sus integraciones principales.</p>
            </div>
            <div class="rounded-2xl bg-sky-50 px-4 py-2 text-xs font-semibold uppercase tracking-[0.16em] text-sky-700">
              App + Integraciones
            </div>
          </div>

          <div class="grid md:grid-cols-2 gap-6">
            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200">
              <h3 class="font-semibold mb-4 flex items-center gap-2">
                <i class="fa-regular fa-folder-open"></i> Rutas
              </h3>

              <label class="block text-sm mb-3">Carpeta origen (inbox)
                <input name="paths_local_inbox" value="<?= e($config['paths']['local_inbox']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
              </label>

              <label class="block text-sm">Carpeta destino (descargas)
                <input name="paths_download" value="<?= e($config['paths']['download_dir']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
              </label>
            </div>

            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200">
              <h3 class="font-semibold mb-4 flex items-center gap-2">
                <i class="fa-solid fa-server"></i> SFTP Archivos ADP
              </h3>

              <div class="grid grid-cols-2 gap-3">
                <label class="block text-sm col-span-2">Host
                  <input name="sftp_host" value="<?= e($config['sftp']['host']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                </label>

                <label class="block text-sm">Port
                  <input name="sftp_port" type="number" value="<?= e((int)$config['sftp']['port']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                </label>

                <label class="block text-sm">Usuario
                  <input name="sftp_user" value="<?= e($config['sftp']['username']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                </label>

                <label class="block text-sm col-span-2">Password
                  <input name="sftp_pass" value="<?= e($config['sftp']['password']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white" type="password">
                </label>

                <label class="block text-sm col-span-2">Ruta remota
                  <input name="sftp_path" value="<?= e($config['sftp']['remote_path']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                </label>
              </div>
            </div>

            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200">
              <h3 class="font-semibold mb-4 flex items-center gap-2">
                <i class="fa-solid fa-database"></i> Base de Datos App
              </h3>

              <div class="grid grid-cols-2 gap-3">
                <label class="block text-sm">DB Host
                  <input name="app_db_host" value="<?= e($config['db']['host']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                </label>
                <label class="block text-sm">DB Usuario
                  <input name="app_db_user" value="<?= e($config['db']['user']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                </label>
                <label class="block text-sm">DB Password
                  <input name="app_db_pass" type="password" value="<?= e($config['db']['pass']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                </label>
                <label class="block text-sm">DB Nombre
                  <input name="app_db_name" value="<?= e($config['db']['name']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                </label>
              </div>
            </div>

            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200">
              <h3 class="font-semibold mb-4 flex items-center gap-2">
                <i class="fa-solid fa-key"></i> API BUK
              </h3>

              <label class="block text-sm mb-3">Base URL
                <input name="buk_base_url" value="<?= e($config['buk']['base_url']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
              </label>

              <label class="block text-sm">Token
                <input name="buk_token" type="password" value="<?= e($config['buk']['token']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
              </label>
            </div>
          </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm space-y-5">
          <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-4">
            <div>
              <h2 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
                <i class="fa-regular fa-clock text-emerald-700"></i> Operación del sync
              </h2>
              <p class="text-sm text-slate-600 mt-1">Frecuencia de ejecución, programación instalada y definición de archivos a procesar.</p>
            </div>
            <div class="rounded-2xl bg-emerald-50 px-4 py-2 text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">
              Flujo diario
            </div>
          </div>

          <div class="grid md:grid-cols-2 gap-6">
            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200">
              <h3 class="font-semibold mb-4 flex items-center gap-2">
                <i class="fa-regular fa-clock"></i> Frecuencia (cron)
              </h3>

          <?php $preset = $config['schedule']['preset'] ?? '*/5 * * * *'; ?>

              <label class="block text-sm mb-3">Preset
                <select name="schedule_preset" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white" onchange="toggleCronFields(this.value)">
                  <?php
                  $opts = [
                      '*/5 * * * *'  => 'Cada 5 minutos',
                      '*/15 * * * *' => 'Cada 15 minutos',
                      '0 * * * *'    => 'Cada hora',
                      'DAILY_AT'     => 'Diario a la hora…',
                      'CUSTOM'       => 'Expresión cron personalizada'
                  ];
                  foreach ($opts as $k => $v) {
                      $sel = ($preset === $k) ? 'selected' : '';
                      echo '<option value="' . e($k) . '" ' . $sel . '>' . e($v) . '</option>';
                  }
                  ?>
                </select>
              </label>

              <div id="dailyWrap" class="<?= ($preset === 'DAILY_AT' ? '' : 'hidden') ?>">
                <label class="block text-sm mb-3">Hora diaria (HH:MM)
                  <input name="schedule_daily_time" value="<?= e($config['schedule']['daily_time'] ?? '02:00') ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white" placeholder="02:00">
                </label>

                <label class="block text-sm mb-3">Zona horaria
                  <select name="schedule_timezone" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                    <?php
                    $timezones = [
                        'America/Santiago' => '🇨🇱 Chile',
                        'America/Argentina/Buenos_Aires' => '🇦🇷 Argentina',
                        'America/Sao_Paulo' => '🇧🇷 Brasil',
                        'America/Mexico_City' => '🇲🇽 México',
                        'America/New_York' => '🇺🇸 USA New York',
                        'Europe/Madrid' => '🇪🇸 España',
                        'UTC' => 'UTC',
                    ];
                    $currentTz = $config['schedule']['timezone'] ?? 'America/Santiago';
                    foreach ($timezones as $tz => $label) {
                        $sel = ($currentTz === $tz) ? 'selected' : '';
                        echo '<option value="' . e($tz) . '" ' . $sel . '>' . e($label) . '</option>';
                    }
                    ?>
                  </select>
                </label>
              </div>

              <div id="customWrap" class="<?= ($preset === 'CUSTOM' ? '' : 'hidden') ?>">
                <label class="block text-sm mb-3">Cron personalizado
                  <input name="schedule_custom" value="<?= e($config['schedule']['custom'] ?? '') ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white" placeholder="m h dom mes dow">
                </label>
              </div>

              <label class="block text-sm">Ruta de PHP
                <input name="php_path" value="<?= e($config['runtime']['php_path']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
              </label>
            </div>

            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200">
              <h3 class="font-semibold mb-4 flex items-center gap-2">
                <i class="fa-solid fa-terminal"></i> Programación instalada
              </h3>
              <pre class="text-sm bg-white border rounded-lg p-3 overflow-x-auto"><?= e($cron_line) ?></pre>
              <p class="text-xs text-gray-500 mt-2">Al guardar, esto se instala automáticamente en crontab.</p>
            </div>

            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200 md:col-span-2">
              <h3 class="font-semibold mb-4 flex items-center gap-2">
                <i class="fa-regular fa-file-lines"></i> Archivos origen
              </h3>
              <div class="grid md:grid-cols-2 gap-4">
                <?php foreach (($config['file_sources'] ?? []) as $key => $source): ?>
                  <div class="rounded-2xl border border-slate-200 p-4 bg-white">
                    <label class="flex items-center gap-2 text-sm font-semibold text-slate-800 mb-3">
                      <input type="checkbox" name="file_sources[<?= e($key) ?>][enabled]" value="1" <?= !empty($source['enabled']) ? 'checked' : '' ?>>
                      <?= e($source['label']) ?>
                    </label>

                    <label class="block text-sm mb-3">Ruta remota
                      <input name="file_sources[<?= e($key) ?>][remote_dir]" value="<?= e($source['remote_dir']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                    </label>

                    <label class="block text-sm mb-3">Prefijo principal
                      <input name="file_sources[<?= e($key) ?>][prefix]" value="<?= e($source['prefix']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                    </label>

                    <label class="block text-sm">Prefijo alternativo
                      <input name="file_sources[<?= e($key) ?>][alt_prefix]" value="<?= e($source['alt_prefix']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white" placeholder="Opcional">
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
              <p class="text-xs text-gray-500 mt-4">Cada fuente puede habilitarse o deshabilitarse de forma independiente para el proceso de sincronización.</p>
            </div>
          </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm space-y-5">
          <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-4">
            <div>
              <h2 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
                <i class="fa-solid fa-shield-halved text-amber-700"></i> Resguardo y mantenimiento
              </h2>
              <p class="text-sm text-slate-600 mt-1">Parámetros de respaldo y conservación de información del sistema.</p>
            </div>
            <div class="rounded-2xl bg-amber-50 px-4 py-2 text-xs font-semibold uppercase tracking-[0.16em] text-amber-700">
              Soporte
            </div>
          </div>

          <div class="grid md:grid-cols-2 gap-6">
            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200">
              <h3 class="font-semibold mb-4 flex items-center gap-2">
                <i class="fa-regular fa-calendar-check"></i> Retención
              </h3>

              <label class="block text-sm mb-3">Reportes sync (días a conservar)
                <input name="retention_report_days" type="number" min="1" value="<?= e((string)$config['retention']['report_days']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
              </label>

              <label class="block text-sm">Backups DB (días a conservar)
                <input name="retention_backup_days" type="number" min="1" value="<?= e((string)$config['retention']['backup_days']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
              </label>

              <label class="block text-sm mt-3">Reportes Rliquid (días a conservar)
                <input name="retention_rliquid_report_days" type="number" min="1" value="<?= e((string)$config['retention']['rliquid_report_days']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
              </label>

              <label class="block text-sm mt-3">PDFs Rliquid (días a conservar)
                <input name="retention_rliquid_pdf_days" type="number" min="1" value="<?= e((string)$config['retention']['rliquid_pdf_days']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
              </label>

              <label class="block text-sm mt-3">Logs del sistema (días a conservar)
                <input name="retention_log_days" type="number" min="1" value="<?= e((string)$config['retention']['log_days']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
              </label>

              <p class="text-xs text-gray-500 mt-2">
                Si un reporte, PDF, log o backup supera esa antigüedad, se elimina automáticamente en el mantenimiento del sistema.
              </p>
              <p class="text-xs text-gray-500 mt-2">
                Referencia: 90 días ≈ 3 meses, 60 días ≈ 2 meses, 30 días ≈ 1 mes.
              </p>
            </div>

            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200">
              <h3 class="font-semibold mb-4 flex items-center gap-2">
                <i class="fa-solid fa-database"></i> Backup DB
              </h3>

              <label class="flex items-center gap-2 text-sm mb-2">
                <input type="checkbox" name="backup_enabled" value="1" <?= !empty($config['backup']['enabled']) ? 'checked' : '' ?>>
                Habilitar backups
              </label>

              <label class="flex items-center gap-2 text-sm mb-3">
                <input type="checkbox" name="backup_before_sync" value="1" <?= !empty($config['backup']['before_sync']) ? 'checked' : '' ?>>
                Crear backup antes de cada sync
              </label>

              <label class="block text-sm mb-3">Carpeta de backups
                <input name="backup_dir" value="<?= e($config['backup']['dir']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
              </label>

              <div class="grid grid-cols-2 gap-3">
                <label class="block text-sm">DB Host
                  <input name="backup_db_host" value="<?= e($config['backup']['db_host']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                </label>
                <label class="block text-sm">DB Usuario
                  <input name="backup_db_user" value="<?= e($config['backup']['db_user']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                </label>
                <label class="block text-sm">DB Password
                  <input name="backup_db_pass" type="password" value="<?= e($config['backup']['db_pass']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                </label>
                <label class="block text-sm">DB Nombre
                  <input name="backup_db_name" value="<?= e($config['backup']['db_name']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white">
                </label>
              </div>
            </div>
          </div>
        </section>

        <div class="flex items-center justify-end gap-2">
          <a href="/sync/index.php" class="px-3 py-2 rounded-lg border hover:bg-gray-50">Cancelar</a>
          <button class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-700">
            <i class="fa-regular fa-floppy-disk"></i> Guardar configuración
          </button>
        </div>
      </form>
    </div>
  </main>
</div>

<script>
function toggleCronFields(val) {
  document.getElementById('dailyWrap')?.classList.toggle('hidden', val !== 'DAILY_AT');
  document.getElementById('customWrap')?.classList.toggle('hidden', val !== 'CUSTOM');
}
</script>

</body>
</html>
<?php ob_end_flush(); ?>

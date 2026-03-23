<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acceso denegado.');
}

$BASE = __DIR__;
chdir($BASE);

require_once __DIR__ . '/email_report.php';
require_once __DIR__ . '/../includes/runtime_config.php';

$config_file    = $BASE . '/storage/sync/config.json';
$log_file       = $BASE . '/storage/logs/sync.log';
$lock_file      = $BASE . '/storage/sync/sync.lock';
$history_file   = $BASE . '/storage/sync/history.json';
$processed_file = $BASE . '/storage/sync/processed_files.json';
$latest_report_file = $BASE . '/storage/sync/latest_report.json';
$reports_base_dir = $BASE . '/storage/reports';
$backups_base_dir = $BASE . '/storage/backups/db';
$rliquid_pdfs_base_dir = $BASE . '/../rliquid/tmp_liq/pdfs';
$managed_log_dirs = [
    $BASE . '/storage/logs',
    $BASE . '/../logs/cambios',
    $BASE . '/../logs_php',
    $BASE . '/../sindicato/logs_buk_attr_file',
    $BASE . '/../documentos/logs_buk_docs',
    $BASE . '/../empleados/logs_buk',
    $BASE . '/../empleados/logs_buk_terminate',
    $BASE . '/../bajas/logs_buk_terminate',
    $BASE . '/../vacaciones/logs_buk_vacaciones',
    $BASE . '/../vacaciones/logs_vac',
    $BASE . '/../rliquid/tmp_liq/logs',
    $BASE . '/../rliquid/logs_buk_liq',
];
$managed_log_keep_files = [
    'sync.log',
    'roles_cache.json',
    'rut_to_empid.json',
    'step_state.json',
];

function logln(string $msg): void
{
    global $log_file;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;

    if (!is_dir(dirname($log_file))) {
        @mkdir(dirname($log_file), 0775, true);
    }

    file_put_contents($log_file, $line, FILE_APPEND);
    echo $line;
}

function rotate_log_file_daily(string $logFile): ?string
{
    if (!is_file($logFile)) return null;

    $mtime = (int)@filemtime($logFile);
    if ($mtime <= 0) return null;

    if (date('Y-m-d', $mtime) === date('Y-m-d')) {
        return null;
    }

    $dir = dirname($logFile);
    $base = pathinfo($logFile, PATHINFO_FILENAME);
    $ext = pathinfo($logFile, PATHINFO_EXTENSION);
    $suffix = date('Ymd_His', $mtime);
    $rotated = $dir . '/' . $base . '_' . $suffix . ($ext !== '' ? '.' . $ext : '');

    if (@rename($logFile, $rotated)) {
        return $rotated;
    }

    return null;
}

function read_json(string $file)
{
    if (!is_file($file)) {
        return null;
    }

    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function write_json(string $file, $data): void
{
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0775, true);
    }

    file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function get_bool($v, bool $default = false): bool
{
    if ($v === null) return $default;
    if (is_bool($v)) return $v;
    $s = strtolower(trim((string)$v));
    if (in_array($s, ['1', 'true', 'yes', 'si', 'on'], true)) return true;
    if (in_array($s, ['0', 'false', 'no', 'off'], true)) return false;
    return $default;
}

function sync_file_source_defaults(): array
{
    return [
        'empleados_maipo' => ['enabled' => true, 'remote_dir' => '/', 'prefix' => 'EMPLEADOS_MAIPO_', 'alt_prefix' => 'EMPLEADOS_MAIPO_TEST'],
        'empleados_sti' => ['enabled' => true, 'remote_dir' => '/RENTA_FIJA', 'prefix' => 'EMPLEADOS_STI_', 'alt_prefix' => ''],
        'vacaciones' => ['enabled' => true, 'remote_dir' => '/', 'prefix' => 'VACACIONES_TERM_', 'alt_prefix' => ''],
        'eventuales_maipo' => ['enabled' => false, 'remote_dir' => '/', 'prefix' => 'EVENTUALES_MAIPO_', 'alt_prefix' => ''],
    ];
}

function merge_file_sources(?array $current): array
{
    $defaults = sync_file_source_defaults();
    $current = is_array($current) ? $current : [];
    foreach ($defaults as $key => $def) {
        $row = is_array($current[$key] ?? null) ? $current[$key] : [];
        $defaults[$key] = [
            'enabled' => get_bool($row['enabled'] ?? $def['enabled'], $def['enabled']),
            'remote_dir' => trim((string)($row['remote_dir'] ?? $def['remote_dir'])),
            'prefix' => trim((string)($row['prefix'] ?? $def['prefix'])),
            'alt_prefix' => trim((string)($row['alt_prefix'] ?? $def['alt_prefix'])),
        ];
    }
    return $defaults;
}

function purge_old_files(string $baseDir, int $keepDays, string $ext = ''): int
{
    if (!is_dir($baseDir)) return 0;
    $keepDays = max(1, $keepDays);
    $cutoff = time() - ($keepDays * 86400);
    $removed = 0;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    /** @var SplFileInfo $file */
    foreach ($it as $file) {
        $path = $file->getPathname();
        if ($file->isFile()) {
            if ($ext !== '' && strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== strtolower($ext)) {
                continue;
            }
            $mtime = (int)@filemtime($path);
            if ($mtime > 0 && $mtime < $cutoff) {
                if (@unlink($path)) $removed++;
            }
        } elseif ($file->isDir()) {
            @rmdir($path);
        }
    }

    return $removed;
}

function purge_old_files_multi(string $baseDir, int $keepDays, array $extensions = [], array $keepBasenames = []): int
{
    if (!is_dir($baseDir)) return 0;
    $keepDays = max(1, $keepDays);
    $cutoff = time() - ($keepDays * 86400);
    $removed = 0;
    $allowed = array_values(array_filter(array_map(static function ($ext) {
        return strtolower(ltrim((string)$ext, '.'));
    }, $extensions)));
    $keepLookup = array_fill_keys($keepBasenames, true);

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    /** @var SplFileInfo $file */
    foreach ($it as $file) {
        $path = $file->getPathname();
        if ($file->isFile()) {
            $basename = $file->getBasename();
            if (isset($keepLookup[$basename])) {
                continue;
            }

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($allowed && !in_array($ext, $allowed, true)) {
                continue;
            }

            $mtime = (int)@filemtime($path);
            if ($mtime > 0 && $mtime < $cutoff) {
                if (@unlink($path)) $removed++;
            }
        } elseif ($file->isDir()) {
            @rmdir($path);
        }
    }

    return $removed;
}

function purge_old_rliquid_reports(int $keepDays): int
{
    $keepDays = max(1, $keepDays);
    $dbCfg = runtime_db_config();
    $mysqli = @new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
    if ($mysqli->connect_error) {
        return 0;
    }

    $mysqli->set_charset('utf8');
    $sql = "DELETE FROM buk_liq_job_runs
            WHERE created_at < (NOW() - INTERVAL {$keepDays} DAY)
              AND status NOT IN ('queued','running')";
    $deleted = 0;
    if ($mysqli->query($sql) === true) {
        $deleted = (int)$mysqli->affected_rows;
    }
    $mysqli->close();
    return $deleted;
}

function save_report_snapshot(string $baseDir, array $payload): ?string
{
    $dt = new DateTime('now');
    $dir = rtrim($baseDir, '/') . '/' . $dt->format('Y') . '/' . $dt->format('m');
    ensure_dir($dir);
    $file = $dir . '/sync_' . $dt->format('Ymd_His') . '.json';
    $ok = @file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return ($ok === false) ? null : $file;
}

function create_db_backup(array $backupCfg): array
{
    $enabled = get_bool($backupCfg['enabled'] ?? true, true);
    if (!$enabled) return ['ok' => true, 'skipped' => true, 'message' => 'Backup deshabilitado por configuración.'];

    $dir = (string)($backupCfg['dir'] ?? '');
    if ($dir === '') return ['ok' => false, 'skipped' => false, 'message' => 'Directorio de backup no configurado.'];
    ensure_dir($dir);

    $host = (string)($backupCfg['db_host'] ?? 'localhost');
    $user = (string)($backupCfg['db_user'] ?? '');
    $pass = (string)($backupCfg['db_pass'] ?? '');
    $name = (string)($backupCfg['db_name'] ?? '');
    if ($user === '' || $name === '') {
        return ['ok' => false, 'skipped' => false, 'message' => 'Falta db_user o db_name para backup.'];
    }

    $file = rtrim($dir, '/') . '/db_' . date('Ymd_His') . '.sql.gz';
    $cmd = 'mysqldump --single-transaction --quick --skip-lock-tables --host=' . escapeshellarg($host)
        . ' --user=' . escapeshellarg($user)
        . ' --password=' . escapeshellarg($pass)
        . ' ' . escapeshellarg($name)
        . ' | gzip > ' . escapeshellarg($file) . ' 2>&1';

    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    if ($code !== 0 || !is_file($file)) {
        @unlink($file);
        return [
            'ok' => false,
            'skipped' => false,
            'message' => 'Error creando backup DB.',
            'exit_code' => $code,
            'output' => implode("\n", $out),
        ];
    }

    return ['ok' => true, 'skipped' => false, 'file' => $file, 'size' => (int)@filesize($file)];
}

function extract_sync_result(?string $output): array
{
    $output = (string)($output ?? '');

    if (preg_match('/SYNC_RESULT=(\{.*\})/s', $output, $m)) {
        $json = json_decode($m[1], true);
        if (is_array($json)) {
            return $json;
        }
    }

    if (stripos($output, 'Fatal error:') !== false || stripos($output, 'PHP Fatal error:') !== false) {
        $raw = trim($output);
        if (strlen($raw) > 3000) {
            $raw = substr($raw, -3000);
        }
        return [
            'status' => 'error',
            'message' => 'Script terminó con Fatal error (sin SYNC_RESULT).',
            'raw_output' => $raw,
        ];
    }

    return [
        'status' => 'unknown',
        'raw_output' => trim($output),
    ];
}

function detect_processor(string $fileName, string $baseDir, array $fileSources = []): array
{
    $nameUpper = strtoupper($fileName);
    $fileSources = merge_file_sources($fileSources);

    foreach (['empleados_maipo', 'empleados_sti', 'eventuales_maipo'] as $sourceKey) {
        $source = (array)($fileSources[$sourceKey] ?? []);
        $prefixes = array_values(array_filter([
            strtoupper((string)($source['prefix'] ?? '')),
            strtoupper((string)($source['alt_prefix'] ?? '')),
        ]));
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && strpos($nameUpper, $prefix) === 0) {
                return [
                    'tipo'   => 'empleados',
                    'script' => $baseDir . '/process_empleados.php',
                ];
            }
        }
    }

    $vacSource = (array)($fileSources['vacaciones'] ?? []);
    $vacPrefixes = array_values(array_filter([
        strtoupper((string)($vacSource['prefix'] ?? '')),
        strtoupper((string)($vacSource['alt_prefix'] ?? '')),
    ]));
    foreach ($vacPrefixes as $prefix) {
        if ($prefix !== '' && strpos($nameUpper, $prefix) === 0) {
            return [
                'tipo'   => 'vacaciones',
                'script' => $baseDir . '/process_vacaciones.php',
            ];
        }
    }

    return [
        'tipo'   => null,
        'script' => null,
    ];
}

function processed_key(string $remoteDir, string $fileName): string
{
    return rtrim($remoteDir, '/') . '|' . $fileName;
}

function process_downloaded_file(
    string $fileName,
    string $localFile,
    string $remoteDir,
    string $baseDir,
    array &$processedStats,
    array &$pendingBukRuts,
    array &$pendingCambiosFiles,
    array $fileSources = []
): void {
    $processedStats['global']['files_detected']++;

    $det = detect_processor($fileName, $baseDir, $fileSources);
    $tipo = $det['tipo'];
    $procesador = $det['script'];

    if (!$tipo || !$procesador || !is_file($procesador)) {
        $processedStats['global']['files_unsupported']++;

        $processedStats['files'][] = [
            'file'        => $fileName,
            'remote_dir'  => $remoteDir,
            'remote_file' => rtrim($remoteDir, '/') . '/' . $fileName,
            'local_file'  => $localFile,
            'script'      => $procesador,
            'status'      => 'unsupported',
            'tipo'        => null,
            'origen'      => null,
            'result'      => [
                'status'  => 'unsupported',
                'file'    => $fileName,
                'message' => 'No existe procesador para este archivo.',
            ],
            'raw_output'   => '',
            'processed_at' => date('Y-m-d H:i:s'),
        ];

        logln("Archivo sin procesador: {$fileName}");
        return;
    }

    $cmd = "php " . escapeshellarg($procesador) . " " . escapeshellarg($localFile) . " 2>&1";
    logln("Procesando {$tipo}: {$cmd}");

    $output = shell_exec($cmd);
    if ($output === null) {
        $output = '';
    }

    logln("Output {$fileName}: " . ($output !== '' ? trim($output) : '[VACIO]'));

    $result = extract_sync_result($output);

    if (($result['status'] ?? '') === 'ok' && $tipo === 'empleados') {
        $bukRuts = array_values(array_unique(array_merge(
            (array)($result['altas_ruts'] ?? []),
            (array)($result['altas_reingreso_ruts'] ?? [])
        )));
        foreach ($bukRuts as $rut) {
            $rut = trim((string)$rut);
            if ($rut !== '') {
                $pendingBukRuts[$rut] = true;
            }
        }
        $pendingCambiosFiles[] = [
            'file' => $fileName,
            'local_file' => $localFile,
        ];
    }

    $fileEntry = [
        'file'         => $fileName,
        'remote_dir'   => $remoteDir,
        'remote_file'  => rtrim($remoteDir, '/') . '/' . $fileName,
        'local_file'   => $localFile,
        'script'       => $procesador,
        'status'       => $result['status'] ?? 'unknown',
        'tipo'         => $result['tipo'] ?? $tipo,
        'origen'       => $result['origen'] ?? null,
        'result'       => $result,
        'raw_output'   => trim($output),
        'processed_at' => date('Y-m-d H:i:s'),
    ];

    $status = $fileEntry['status'];

    if ($status === 'ok') {
        $processedStats['global']['files_processed']++;
    } elseif ($status === 'unsupported') {
        $processedStats['global']['files_unsupported']++;
    } elseif ($status === 'error') {
        $processedStats['global']['errores_total']++;
    } else {
        $processedStats['global']['files_skipped']++;
    }

    if (($fileEntry['tipo'] ?? '') === 'empleados') {
        $processedStats['global']['altas_total'] += count($result['altas_ruts'] ?? []);
        $processedStats['global']['altas_buk_ok_total'] += count($result['altas_buk_ok_ruts'] ?? []);
        $processedStats['global']['altas_buk_error_total'] += count($result['altas_buk_error_ruts'] ?? []);
        $processedStats['global']['errores_total'] += count($result['errores_ruts'] ?? []);
    }

    $processedStats['files'][] = $fileEntry;
}

function prune_history(array $hist): array
{
    $threshold = time() - (60 * 60 * 24 * 30);

    $hist = array_filter($hist, function ($e) use ($threshold) {
        $ts = strtotime($e['date'] ?? '');
        return $ts !== false && $ts >= $threshold;
    });

    return array_values($hist);
}

function find_latest_remote_file($client, string $pathRemoto, string $prefix): ?array
{
    $list = $client->rawlist($pathRemoto);
    if (!is_array($list)) {
        return null;
    }

    $latest = null;
    $prefixUpper = strtoupper($prefix);

    foreach ($list as $name => $meta) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        if ((int)($meta['type'] ?? 0) !== 1) {
            continue;
        }

        $nameUpper = strtoupper($name);
        if (strpos($nameUpper, $prefixUpper) !== 0) {
            continue;
        }

        $mtime = (int)($meta['mtime'] ?? 0);

        if ($latest === null || $mtime > $latest['mtime']) {
            $latest = [
                'name'       => $name,
                'meta'       => $meta,
                'mtime'      => $mtime,
                'remote_dir' => $pathRemoto,
            ];
        }
    }

    return $latest;
}
function find_latest_remote_file_by_patterns($client, string $pathRemoto, array $patterns): ?array
{
    $list = $client->rawlist($pathRemoto);
    if (!is_array($list)) {
        return null;
    }

    $latest = null;
    $patterns = array_map('strtoupper', $patterns);

    foreach ($list as $name => $meta) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        if ((int)($meta['type'] ?? 0) !== 1) {
            continue;
        }

        $nameUpper = strtoupper($name);
        $match = false;

        foreach ($patterns as $pattern) {
            if (strpos($nameUpper, $pattern) === 0) {
                $match = true;
                break;
            }
        }

        if (!$match) {
            continue;
        }

        $mtime = (int)($meta['mtime'] ?? 0);

        if ($latest === null || $mtime > $latest['mtime']) {
            $latest = [
                'name'       => $name,
                'meta'       => $meta,
                'mtime'      => $mtime,
                'remote_dir' => $pathRemoto,
            ];
        }
    }

    return $latest;
}

function run_standalone_sync_script(
    string $scriptPath,
    string $label,
    string $tipo,
    array &$processedStats
): array {
    logln("Procesando {$label}...");

    if (!is_file($scriptPath)) {
        logln("ERROR: No existe {$scriptPath}");
        return ['status' => 'missing'];
    }

    $cmd = "php " . escapeshellarg($scriptPath) . " 2>&1";
    logln("Ejecutando: {$cmd}");

    $output = shell_exec($cmd);
    if ($output === null) {
        $output = '';
    }

    logln("Output {$label}: " . trim($output));
    $result = extract_sync_result($output);

    $fileEntry = [
        'file'         => strtoupper($label),
        'remote_dir'   => '-',
        'remote_file'  => '-',
        'local_file'   => '-',
        'script'       => $scriptPath,
        'status'       => $result['status'] ?? 'unknown',
        'tipo'         => $tipo,
        'origen'       => null,
        'result'       => $result,
        'raw_output'   => trim($output),
        'processed_at' => date('Y-m-d H:i:s'),
    ];

    if (($fileEntry['status'] ?? '') === 'ok') {
        $processedStats['global']['files_processed']++;
    } elseif (($fileEntry['status'] ?? '') === 'error') {
        $processedStats['global']['errores_total']++;
    } else {
        $processedStats['global']['files_skipped']++;
    }

    $processedStats['files'][] = $fileEntry;
    return $result;
}

$config = read_json($config_file);
if (!$config) {
    logln('ERROR: No existe o es inválido config.json: ' . $config_file);
    exit(1);
}

function abs_path_from_base(string $path, string $base): string
{
    $path = trim($path);
    if ($path === '') {
        return $base;
    }

    if ($path[0] === '/') {
        return $path;
    }

    return rtrim($base, '/') . '/' . ltrim($path, './');
}

$config['paths']['local_inbox'] = abs_path_from_base(
    $config['paths']['local_inbox'] ?? 'storage/sftp_inbox',
    $BASE
);

$config['paths']['download_dir'] = abs_path_from_base(
    $config['paths']['download_dir'] ?? 'storage/descargas',
    $BASE
);

$config['runtime']['log_file'] = abs_path_from_base(
    $config['runtime']['log_file'] ?? 'storage/logs/sync.log',
    $BASE
);

$config['retention'] = is_array($config['retention'] ?? null) ? $config['retention'] : [];
$config['retention']['report_days'] = max(1, (int)($config['retention']['report_days'] ?? 90));
$config['retention']['backup_days'] = max(1, (int)($config['retention']['backup_days'] ?? 60));
$config['retention']['rliquid_report_days'] = max(1, (int)($config['retention']['rliquid_report_days'] ?? 90));
$config['retention']['rliquid_pdf_days'] = max(1, (int)($config['retention']['rliquid_pdf_days'] ?? 90));
$config['retention']['log_days'] = max(1, (int)($config['retention']['log_days'] ?? 30));

$config['backup'] = is_array($config['backup'] ?? null) ? $config['backup'] : [];
$config['backup']['enabled'] = get_bool($config['backup']['enabled'] ?? true, true);
$config['backup']['before_sync'] = get_bool($config['backup']['before_sync'] ?? true, true);
$config['backup']['dir'] = abs_path_from_base((string)($config['backup']['dir'] ?? 'storage/backups/db'), $BASE);
$config['backup']['db_host'] = (string)($config['backup']['db_host'] ?? 'localhost');
$config['backup']['db_user'] = (string)($config['backup']['db_user'] ?? 'nehgryws_stiuser');
$config['backup']['db_pass'] = (string)($config['backup']['db_pass'] ?? 'fs73xig9e9t0');
$config['backup']['db_name'] = (string)($config['backup']['db_name'] ?? 'nehgryws_stisoft');
$config['file_sources'] = merge_file_sources($config['file_sources'] ?? null);

ensure_dir($reports_base_dir);
ensure_dir($config['backup']['dir']);

ensure_dir(dirname($lock_file));

$fp = fopen($lock_file, 'c+');
if (!$fp) {
    logln('ERROR: No se pudo abrir lockfile');
    exit(1);
}

if (!flock($fp, LOCK_EX | LOCK_NB)) {
    logln('Otro proceso en ejecución. Abortando.');
    exit(0);
}

$start = microtime(true);

$event = [
    'date'     => date('Y-m-d H:i:s'),
    'mode'     => $config['mode'] ?? 'local',
    'status'   => 'ERROR',
    'files'    => 0,
    'duration' => null,
    'message'  => '',
    'errors'   => ''
];

$processedStats = [
    'generated_at' => date('Y-m-d H:i:s'),
    'global' => [
        'files_detected'        => 0,
        'files_processed'       => 0,
        'files_skipped'         => 0,
        'files_unsupported'     => 0,
        'altas_total'           => 0,
        'altas_buk_ok_total'    => 0,
        'altas_buk_error_total' => 0,
        'errores_total'         => 0,
    ],
    'files' => [],
];

try {
    $rotatedLog = rotate_log_file_daily($log_file);
    if ($rotatedLog !== null) {
        logln('Log rotado automáticamente: ' . basename($rotatedLog));
    }

    $mode = $config['mode'] ?? 'local';
    $dest = $config['paths']['download_dir'] ?? ($BASE . '/storage/descargas');

    ensure_dir($dest);

    logln("Inicio sync. Modo={$mode}");

    // Mantenimiento de retención
    $removedReports = purge_old_files($reports_base_dir, (int)$config['retention']['report_days'], 'json');
    $removedBackups = purge_old_files((string)$config['backup']['dir'], (int)$config['retention']['backup_days'], 'gz');
    $removedRliquidReports = purge_old_rliquid_reports((int)$config['retention']['rliquid_report_days']);
    $removedRliquidPdfs = purge_old_files($rliquid_pdfs_base_dir, (int)$config['retention']['rliquid_pdf_days'], 'pdf');
    $removedLogs = 0;
    foreach ($managed_log_dirs as $logDir) {
        $removedLogs += purge_old_files_multi(
            $logDir,
            (int)$config['retention']['log_days'],
            ['json', 'jsonl', 'log', 'txt'],
            $managed_log_keep_files
        );
    }
    if ($removedReports > 0 || $removedBackups > 0 || $removedRliquidReports > 0 || $removedRliquidPdfs > 0 || $removedLogs > 0) {
        logln("Retención aplicada. Reportes sync eliminados: {$removedReports}. Backups eliminados: {$removedBackups}. Reportes rliquid eliminados: {$removedRliquidReports}. PDFs rliquid eliminados: {$removedRliquidPdfs}. Logs eliminados: {$removedLogs}.");
    }

    // Backup DB previo al sync
    if (get_bool($config['backup']['before_sync'] ?? true, true)) {
        $backupRes = create_db_backup((array)$config['backup']);
        if (!($backupRes['ok'] ?? false)) {
            logln('WARN backup DB: ' . (string)($backupRes['message'] ?? 'Error desconocido'));
            if (!empty($backupRes['output'])) {
                logln('WARN backup DB output: ' . (string)$backupRes['output']);
            }
        } else {
            if (!($backupRes['skipped'] ?? false)) {
                logln('Backup DB OK: ' . (string)($backupRes['file'] ?? '-'));
            }
        }
    }

    run_standalone_sync_script(
        $BASE . '/process_jerarquias.php',
        'process_jerarquias',
        'jerarquias',
        $processedStats
    );

    $processed = read_json($processed_file) ?: [];
    $count = 0;
    $pendingBukRuts = [];
    $pendingCambiosFiles = [];

    if ($mode === 'local') {
        $src = $config['paths']['local_inbox'] ?? ($BASE . '/storage/sftp_inbox');

        if (!is_dir($src)) {
            $event['message'] = "Origen local no existe: {$src}";
            $event['status'] = 'ERROR';
            logln("WARN: {$event['message']}");
        } else {
            $dh = opendir($src);

            if ($dh === false) {
                throw new RuntimeException("No se pudo abrir directorio local: {$src}");
            }

            while (($f = readdir($dh)) !== false) {
                if ($f === '.' || $f === '..') {
                    continue;
                }

                $from = rtrim($src, '/') . '/' . $f;
                if (!is_file($from)) {
                    continue;
                }

                $to = rtrim($dest, '/') . '/' . $f;
                if (is_file($to)) {
                    $to = rtrim($dest, '/') . '/' . date('Ymd_His') . "_{$f}";
                }

                $ok = false;

                if (@rename($from, $to)) {
                    $ok = true;
                    logln("Movido: {$f} -> {$to}");
                } elseif (@copy($from, $to)) {
                    @unlink($from);
                    $ok = true;
                    logln("Copiado: {$f} -> {$to}");
                }

                if (!$ok) {
                    logln("ERROR moviendo/copiando archivo local: {$from}");
                    continue;
                }

                $count++;
                process_downloaded_file($f, $to, $src, $BASE, $processedStats, $pendingBukRuts, $pendingCambiosFiles, $config['file_sources'] ?? []);

                $pkey = processed_key($src, $f);
                $processed[$pkey] = date('Y-m-d H:i:s');
            }

            closedir($dh);

            $event['files'] = $count;
            $event['status'] = 'OK';
            $event['message'] = "Local OK. Archivos procesados: {$count}";
            logln($event['message']);
        }
    } else {
        $vendor = dirname($BASE) . '/vendor/autoload.php';
        if (!is_file($vendor)) {
            throw new RuntimeException('SFTP requiere phpseclib. No se encontró vendor/autoload.php');
        }

        require_once $vendor;

        $s = $config['sftp'] ?? [];
        $client = new \phpseclib3\Net\SFTP(
            $s['host'] ?? '',
            (int)($s['port'] ?? 22),
            15
        );

        if (!$client->login($s['username'] ?? '', $s['password'] ?? '')) {
            throw new RuntimeException('Login SFTP inválido');
        }

        $targets = [];
        foreach (($config['file_sources'] ?? []) as $sourceKey => $sourceCfg) {
            if (!get_bool($sourceCfg['enabled'] ?? false, false)) {
                logln("Fuente desactivada: {$sourceKey}");
                continue;
            }

            $remoteDir = trim((string)($sourceCfg['remote_dir'] ?? '/'));
            if ($remoteDir === '') {
                $remoteDir = '/';
            }

            $patterns = array_values(array_filter([
                trim((string)($sourceCfg['prefix'] ?? '')),
                trim((string)($sourceCfg['alt_prefix'] ?? '')),
            ]));

            if (empty($patterns)) {
                logln("Fuente sin prefijos configurados: {$sourceKey}");
                continue;
            }

            $latestTarget = find_latest_remote_file_by_patterns($client, $remoteDir, $patterns);
            if ($latestTarget !== null) {
                $targets[] = $latestTarget;
                logln('Objetivo detectado ' . strtoupper($sourceKey) . ': ' . $latestTarget['name'] . ' en ' . $latestTarget['remote_dir']);
                continue;
            }

            logln('No se encontró archivo para ' . strtoupper($sourceKey) . ' en ' . $remoteDir);
            if ($sourceKey === 'vacaciones') {
                $processedStats['files'][] = [
                    'file' => 'VACACIONES_NO_ENCONTRADO',
                    'remote_dir' => $remoteDir,
                    'remote_file' => '-',
                    'local_file' => '-',
                    'script' => $BASE . '/process_vacaciones.php',
                    'status' => 'ok',
                    'tipo' => 'vacaciones',
                    'origen' => null,
                    'result' => [
                        'status' => 'ok',
                        'tipo' => 'vacaciones',
                        'file' => '-',
                        'requested' => 0,
                        'done' => 0,
                        'failed' => 0,
                        'missing_buk' => 0,
                        'not_found_bd' => 0,
                        'invalid_rows' => 0,
                        'failed_items' => [],
                        'message' => 'Archivo no encontrado',
                    ],
                    'raw_output' => '',
                    'processed_at' => date('Y-m-d H:i:s'),
                ];
            }
        }

        if (empty($targets)) {
            logln('No se encontraron archivos objetivo para procesar.');
        }

        foreach ($targets as $target) {
            $name = $target['name'];
            $remoteDir = $target['remote_dir'];
            $remoteFile = rtrim($remoteDir, '/') . '/' . $name;

            // IMPORTANTE:
            // NO saltar aunque ya exista en processed_files.
            // Siempre procesar el último archivo detectado.
            logln("Archivo objetivo a procesar (aunque ya exista en historial): {$remoteFile}");

            $localFile = rtrim($dest, '/') . '/' . $name;
            if (is_file($localFile)) {
                $localFile = rtrim($dest, '/') . '/' . date('Ymd_His') . "_{$name}";
            }

            $data = $client->get($remoteFile);
            if ($data === false) {
                logln("ERROR descargando: {$remoteFile}");
                continue;
            }

            if (file_put_contents($localFile, $data) === false) {
                logln("ERROR guardando descarga local: {$localFile}");
                continue;
            }

            $count++;
            logln("Descargado: {$remoteFile} -> {$localFile}");

            process_downloaded_file($name, $localFile, $remoteDir, $BASE, $processedStats, $pendingBukRuts, $pendingCambiosFiles, $config['file_sources'] ?? []);

            $pkey = processed_key($remoteDir, $name);
            $processed[$pkey] = date('Y-m-d H:i:s');
        }

        $event['files'] = $count;
        $event['status'] = 'OK';
        $event['message'] = "SFTP OK. Archivos descargados/procesados: {$count}";
        logln($event['message']);
    }

    write_json($processed_file, $processed);

    if (!empty($pendingCambiosFiles)) {
        $cambiosScript = $BASE . '/process_cambios.php';
        if (is_file($cambiosScript)) {
            foreach ($pendingCambiosFiles as $cambioFile) {
                $localCambioFile = (string)($cambioFile['local_file'] ?? '');
                if ($localCambioFile === '' || !is_file($localCambioFile)) {
                    logln("WARN: Archivo para cambios no existe: {$localCambioFile}");
                    continue;
                }

                $cambiosCmd = "php " . escapeshellarg($cambiosScript) . " " . escapeshellarg($localCambioFile) . " --enqueue-only --max-send=100 2>&1";
                logln("Procesando process_cambios (enqueue-only): {$cambiosCmd}");

                $cambiosOutput = shell_exec($cambiosCmd);
                if ($cambiosOutput === null) {
                    $cambiosOutput = '';
                }

                logln("Output process_cambios (" . basename($localCambioFile) . "): " . ($cambiosOutput !== '' ? trim($cambiosOutput) : '[VACIO]'));
                $cambiosResult = extract_sync_result($cambiosOutput);

                $processedStats['files'][] = [
                    'file'         => 'PROCESS_CAMBIOS_ENQUEUE_' . basename($localCambioFile),
                    'remote_dir'   => '-',
                    'remote_file'  => '-',
                    'local_file'   => $localCambioFile,
                    'script'       => $cambiosScript,
                    'status'       => $cambiosResult['status'] ?? 'unknown',
                    'tipo'         => 'cambios',
                    'origen'       => null,
                    'result'       => $cambiosResult,
                    'raw_output'   => trim($cambiosOutput),
                    'processed_at' => date('Y-m-d H:i:s'),
                ];

                if (($cambiosResult['status'] ?? '') === 'ok') {
                    $processedStats['global']['files_processed']++;
                } elseif (($cambiosResult['status'] ?? '') === 'error') {
                    $processedStats['global']['errores_total']++;
                } else {
                    $processedStats['global']['files_skipped']++;
                }
            }
        } else {
            logln("ERROR: No existe process_cambios.php");
        }
    } else {
        logln("Sin archivos pendientes para process_cambios.");
    }

    run_standalone_sync_script(
        $BASE . '/process_buk_jerarquias.php',
        'process_buk_jerarquias',
        'buk_jerarquias',
        $processedStats
    );

    $rutsForBuk = array_values(array_keys($pendingBukRuts));
    if (!empty($rutsForBuk)) {
        $bukScript = $BASE . '/process_buk_empleados.php';
        if (is_file($bukScript)) {
            $bukCmd = "php " . escapeshellarg($bukScript) . ' ' .
                implode(' ', array_map('escapeshellarg', $rutsForBuk)) . " 2>&1";
            logln("Procesando process_buk_empleados: {$bukCmd}");

            $bukOutput = shell_exec($bukCmd);
            if ($bukOutput === null) {
                $bukOutput = '';
            }

            logln("Output process_buk_empleados: " . ($bukOutput !== '' ? trim($bukOutput) : '[VACIO]'));
            $bukResult = extract_sync_result($bukOutput);

            $processedStats['files'][] = [
                'file'         => 'PROCESS_BUK_EMPLEADOS',
                'remote_dir'   => '-',
                'remote_file'  => '-',
                'local_file'   => '-',
                'script'       => $bukScript,
                'status'       => $bukResult['status'] ?? 'unknown',
                'tipo'         => 'buk_empleados',
                'origen'       => null,
                'result'       => $bukResult,
                'raw_output'   => trim($bukOutput),
                'processed_at' => date('Y-m-d H:i:s'),
            ];

            $processedStats['global']['altas_buk_ok_total'] += count($bukResult['altas_buk_ok_ruts'] ?? []);
            $processedStats['global']['altas_buk_error_total'] += count($bukResult['altas_buk_error_ruts'] ?? []);
            if (($bukResult['status'] ?? '') === 'ok') {
                $processedStats['global']['files_processed']++;
            } elseif (($bukResult['status'] ?? '') === 'error') {
                $processedStats['global']['errores_total']++;
            } else {
                $processedStats['global']['files_skipped']++;
            }
        } else {
            logln("ERROR: No existe process_buk_empleados.php");
        }
    } else {
        logln("Sin RUTs pendientes para process_buk_empleados.");
    }

    run_standalone_sync_script(
        $BASE . '/process_buk_bajas.php',
        'process_buk_bajas',
        'buk_bajas',
        $processedStats
    );

    $cambiosScript = $BASE . '/process_cambios.php';
    if (is_file($cambiosScript)) {
        $drainBatch = 10;
        $drainMaxCycles = 2000;
        $drainCycle = 0;
        $drainAgg = [
            'status' => 'ok',
            'tipo' => 'cambios',
            'mode' => 'drain_only',
            'requested' => 0,
            'queued' => 0,
            'queue_processed' => 0,
            'sent_ok' => 0,
            'sent_error' => 0,
            'queue_pending' => 0,
            'queue_error' => 0,
            'skip' => 0,
            'detect_errors' => 0,
            'changed_ruts' => [],
            'error_ruts' => [],
            'failed_items' => [],
            'ok_items' => [],
            'ignored' => 0,
            'ignored_items' => [],
            'message' => '',
        ];
        $drainOutputs = [];

        while ($drainCycle < $drainMaxCycles) {
            $drainCycle++;
            $extraArgs = ($drainCycle === 1) ? ' --requeue-errors' : '';
            $cambiosDrainCmd = "php " . escapeshellarg($cambiosScript) . " --drain-only --max-send={$drainBatch}{$extraArgs} 2>&1";
            logln("Procesando process_cambios (drain-only lote {$drainCycle}): {$cambiosDrainCmd}");

            $cambiosDrainOutput = shell_exec($cambiosDrainCmd);
            if ($cambiosDrainOutput === null) {
                $cambiosDrainOutput = '';
            }
            logln("Output process_cambios drain lote {$drainCycle}: " . ($cambiosDrainOutput !== '' ? trim($cambiosDrainOutput) : '[VACIO]'));

            $cambiosDrainResult = extract_sync_result($cambiosDrainOutput);
            if ($drainCycle <= 10 && trim($cambiosDrainOutput) !== '') {
                $drainOutputs[] = trim($cambiosDrainOutput);
            }

            $drainAgg['queue_processed'] += (int)($cambiosDrainResult['queue_processed'] ?? 0);
            $drainAgg['sent_ok'] += (int)($cambiosDrainResult['sent_ok'] ?? 0);
            $drainAgg['sent_error'] += (int)($cambiosDrainResult['sent_error'] ?? 0);
            $drainAgg['skip'] += (int)($cambiosDrainResult['skip'] ?? 0);
            $drainAgg['detect_errors'] += (int)($cambiosDrainResult['detect_errors'] ?? 0);
            $drainAgg['ignored'] += (int)($cambiosDrainResult['ignored'] ?? 0);
            $drainAgg['queue_pending'] = (int)($cambiosDrainResult['queue_pending'] ?? 0);
            $drainAgg['queue_error'] = (int)($cambiosDrainResult['queue_error'] ?? 0);

            $drainAgg['changed_ruts'] = array_values(array_unique(array_merge(
                (array)$drainAgg['changed_ruts'],
                (array)($cambiosDrainResult['changed_ruts'] ?? [])
            )));
            $drainAgg['error_ruts'] = array_values(array_unique(array_merge(
                (array)$drainAgg['error_ruts'],
                (array)($cambiosDrainResult['error_ruts'] ?? [])
            )));

            foreach ((array)($cambiosDrainResult['failed_items'] ?? []) as $fi) {
                if (count($drainAgg['failed_items']) >= 60) break;
                $drainAgg['failed_items'][] = $fi;
            }
            foreach ((array)($cambiosDrainResult['ok_items'] ?? []) as $oi) {
                if (count($drainAgg['ok_items']) >= 60) break;
                $drainAgg['ok_items'][] = $oi;
            }
            foreach ((array)($cambiosDrainResult['ignored_items'] ?? []) as $ii) {
                if (count($drainAgg['ignored_items']) >= 60) break;
                $drainAgg['ignored_items'][] = $ii;
            }

            if (($cambiosDrainResult['status'] ?? 'ok') === 'error') {
                $drainAgg['status'] = 'error';
            }

            if ((int)($cambiosDrainResult['queue_pending'] ?? 0) <= 0) {
                break;
            }
        }

        $drainAgg['detail_totals'] = [
            'detected' => 0,
            'queued' => 0,
            'ok' => (int)$drainAgg['sent_ok'],
            'error' => (int)$drainAgg['sent_error'],
            'pending' => (int)$drainAgg['queue_pending'],
            'queue_error' => (int)$drainAgg['queue_error'],
            'skip' => 0,
            'ignored_legacy' => (int)$drainAgg['ignored'],
        ];
        $drainAgg['message'] = "Drain cola cambios finalizado en {$drainCycle} lote(s). "
            . "OK: {$drainAgg['sent_ok']}, ERROR: {$drainAgg['sent_error']}, "
            . "PENDIENTES: {$drainAgg['queue_pending']}, ERRORES_COLA: {$drainAgg['queue_error']}.";

        $processedStats['files'][] = [
            'file'         => 'PROCESS_CAMBIOS_DRAIN',
            'remote_dir'   => '-',
            'remote_file'  => '-',
            'local_file'   => '-',
            'script'       => $cambiosScript,
            'status'       => $drainAgg['status'] ?? 'unknown',
            'tipo'         => 'cambios',
            'origen'       => null,
            'result'       => $drainAgg,
            'raw_output'   => implode("\n\n---\n\n", $drainOutputs),
            'processed_at' => date('Y-m-d H:i:s'),
        ];

        if (($drainAgg['status'] ?? '') === 'ok') {
            $processedStats['global']['files_processed']++;
        } elseif (($drainAgg['status'] ?? '') === 'error') {
            $processedStats['global']['errores_total']++;
        } else {
            $processedStats['global']['files_skipped']++;
        }
    }

} catch (Throwable $t) {
    $event['errors'] = $t->getMessage();
    $event['status'] = 'ERROR';
    $event['message'] = 'Error en ejecución de sync';
    logln('EXCEPTION: ' . $t->getMessage());
} finally {
    $reportPayload = [
        'generated_at'    => date('Y-m-d H:i:s'),
        'event'           => $event,
        'processed_stats' => $processedStats,
    ];

    $event['duration'] = (function ($s) {
        $d = microtime(true) - $s;
        return ($d < 1) ? (round($d * 1000)) . ' ms' : number_format($d, 2) . ' s';
    })($start);

    $hist = read_json($history_file) ?: [];
    $hist[] = $event;
    $hist = prune_history($hist);
    write_json($history_file, $hist);

    $reportPayload['event'] = $event;
    write_json($latest_report_file, $reportPayload);

    $snapshotPath = save_report_snapshot($reports_base_dir, $reportPayload);
    if ($snapshotPath !== null) {
        logln("Reporte snapshot guardado: {$snapshotPath}");
    } else {
        logln('WARN: No se pudo guardar snapshot de reporte.');
    }

    $toAddresses = $config['smtp']['to_addresses'] ?? ['alysonvalenzuela94@gmail.com'];

    if (!empty($toAddresses) && is_array($toAddresses)) {
        logln("Generando reporte por email para: " . implode(', ', $toAddresses));

        $htmlContent = build_html_report(
            $event['date'],
            $event,
            $processedStats,
            $hist
        );

        $emailSubject = 'Reporte Diario STISOFT - ' . (string)$event['date'];

        if (send_report_email($toAddresses, $htmlContent, $emailSubject, $config['smtp'] ?? null)) {
            logln("Email enviado exitosamente a: " . implode(', ', $toAddresses));
        } else {
            logln("WARN: No se pudo enviar email de reporte");
        }
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    logln("Fin sync. Status={$event['status']} Duración={$event['duration']}");
}

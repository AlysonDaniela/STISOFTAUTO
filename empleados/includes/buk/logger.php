<?php
// empleados/includes/buk/logger.php
require_once __DIR__ . '/config.php';

function ensure_logs_dir(): void {
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
}

function save_log(string $type, string $key, $data): string {
    ensure_logs_dir();
    $ts = date('Ymd_His');
    $safeKey = preg_replace('/[^a-zA-Z0-9_\-:.|]/','_', $key);
    $file = sprintf('%s/%s_%s_%s.json', LOG_DIR, $type, $safeKey, $ts);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    return $file;
}

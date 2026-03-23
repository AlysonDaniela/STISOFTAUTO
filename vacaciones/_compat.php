<?php
declare(strict_types=1);

/* -------- Manejo de errores para ver el 500 en pantalla y log -------- */
ini_set('display_errors', '1');
ini_set('log_errors', '1');
if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0775, true);
ini_set('error_log', __DIR__ . '/data/error.log');
error_reporting(E_ALL);

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "FATAL: {$e['message']} in {$e['file']}:{$e['line']}\n";
    }
});

/* ----------------- Polyfills para PHP 7.x ----------------- */
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        $haystack = (string)$haystack; $needle = (string)$needle;
        if ($needle === '') return true;
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos((string)$haystack, (string)$needle) !== false;
    }
}

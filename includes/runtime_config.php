<?php
declare(strict_types=1);

function runtime_sync_config_path(): string
{
    return __DIR__ . '/../sync/storage/sync/config.json';
}

function runtime_sync_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $file = runtime_sync_config_path();
    if (!is_file($file)) {
        $config = [];
        return $config;
    }

    $raw = file_get_contents($file);
    $json = json_decode((string)$raw, true);
    $config = is_array($json) ? $json : [];
    return $config;
}

function runtime_db_config(): array
{
    $config = runtime_sync_config();
    $db = is_array($config['db'] ?? null) ? $config['db'] : [];
    return [
        'host' => (string)($db['host'] ?? 'localhost'),
        'user' => (string)($db['user'] ?? ''),
        'pass' => (string)($db['pass'] ?? ''),
        'name' => (string)($db['name'] ?? ''),
    ];
}

function runtime_buk_config(): array
{
    $config = runtime_sync_config();
    $buk = is_array($config['buk'] ?? null) ? $config['buk'] : [];
    return [
        'base' => rtrim((string)($buk['base_url'] ?? 'https://sti.buk.cl/api/v1/chile'), '/'),
        'token' => (string)($buk['token'] ?? ''),
    ];
}

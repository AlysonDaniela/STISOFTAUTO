<?php
// empleados/includes/buk/cliente.php

require_once __DIR__ . '/config.php';

function buk_api_request(string $method, string $path, ?array $payload = null): array {
    $url  = rtrim(BUK_API_BASE,'/').$path;

    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $jsonErr = json_last_error_msg();
            return [
                'ok'=>false, 'code'=>0, 'error'=>'JSON_ENCODE_FAIL',
                'body'=>'JSON_ENCODE_FAIL: '.$jsonErr,
                'url'=>$url, 'variant'=>'json_fail'
            ];
        }
    }

    $ch  = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'Accept-Language: es',
            'auth_token: '.BUK_TOKEN,
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
    ];

    $method = strtoupper($method);
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $body ?? '{}';
    } elseif ($method === 'PATCH') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'PATCH';
        $opts[CURLOPT_POSTFIELDS] = $body ?? '{}';
    } elseif ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($ch, $opts);

    $respBody = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['ok'=>false,'code'=>0,'error'=>$err,'body'=>'Error de cURL: '.$err,'url'=>$url,'variant'=>'curl_error'];
    }

    $ok = ($code >= 200 && $code < 300);
    return ['ok'=>$ok,'code'=>$code,'error'=>null,'body'=>$respBody !== false ? $respBody : '','url'=>$url,'variant'=>$ok?'ok':'http_error'];
}

function parse_msg(string $body): string {
    $t = trim($body);
    if (substr($t, 0, 15) === 'Error de cURL:') return $t;
    $j = json_decode($body, true);
    if (is_array($j)) {
        if (isset($j['errors'])) return json_encode($j['errors'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        if (isset($j['message'])) return (string)$j['message'];
        if (isset($j['error'])) return is_string($j['error']) ? $j['error'] : json_encode($j['error'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    }
    return (strlen($body) > 5000) ? substr($body, 0, 5000) : $body;
}

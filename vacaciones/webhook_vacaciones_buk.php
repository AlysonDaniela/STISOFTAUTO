<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$env = require __DIR__ . '/config.env.php';
require_once __DIR__ . '/lib/adp_vac.php';

function jout(int $code, $data){ http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit; }
function now_str(): string { return date('Y-m-d H:i:s'); }
function ensure_dir(string $d){ if(!is_dir($d)) @mkdir($d, 0775, true); }
ensure_dir($env['LOG_DIR']); ensure_dir($env['ADP_UPLOAD_DIR']);
$adpFile = rtrim($env['ADP_UPLOAD_DIR'],'/\\') . DIRECTORY_SEPARATOR . $env['ADP_FILE_NAME'];

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if(!is_array($payload)){ file_put_contents($env['LOG_DIR'].'/bad_json_'.time().'.log', $raw); jout(400, ['error'=>'JSON inválido']); }

$evt = $payload['data']['event_type'] ?? ($payload['event_type'] ?? null);
if(!$evt || stripos($evt, 'vacation_') !== 0){ jout(200, ['received'=>true, 'ignored'=>true, 'event'=>$evt]); }
$vacationId = $payload['data']['vacation_id'] ?? ($payload['vacation_id'] ?? null);
if(!$vacationId){ jout(400, ['error'=>'vacation_id faltante']); }

http_response_code(200);
echo json_encode(['received'=>true, 'event'=>$evt, 'vacation_id'=>$vacationId], JSON_UNESCAPED_UNICODE);
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

try {
    $url = rtrim($env['BUK_API_BASE'],'/') . '/vacations/' . urlencode((string)$vacationId) . '.json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60, CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $env['BUK_TOKEN'],'Accept: application/json','Accept-Language: es']]);
    $resp = curl_exec($ch); $err  = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);

    $details = ['employeeCode'=>'', 'requestedDays'=>0.0, 'periodYear'=>(int)date('Y'), 'source'=>'buk'];
    if(!($err || $code<200 || $code>=300)){
        $j = json_decode($resp,true);
        if(is_array($j)){
            $details['employeeCode']  = $j['employee']['rut'] ?? ($j['employee']['document_number'] ?? '');
            $details['requestedDays'] = (float)($j['days'] ?? 0);
            $details['periodYear']    = (int)($j['period_year'] ?? date('Y'));
            $details['raw'] = $j;
        }
    }

    [, $rows] = read_csv_assoc($adpFile);
    $saldo = compute_vacation_from_file($rows, (string)$details['employeeCode']);
    $requested = max(0.0, (float)$details['requestedDays']);
    $decision = decision_from_file_balance($saldo, $requested);

    if (!$saldo['found'])      { $noteMsg = 'ADP: RUT no encontrado en archivo. No es posible validar saldo.'; }
    elseif ($saldo['totalBalance'] === null) { $noteMsg = 'ADP: faltan columnas (Saldo o Otorgados+Tomados) para calcular saldo.'; }
    else {
        $noteMsg = $decision['ok']
            ? sprintf('ADP saldo OK. Antes: %.2f | Solic: %.2f | Después: %.2f.', $decision['balanceBefore'],$decision['requested'],$decision['balanceAfter'])
            : sprintf('ADP saldo INSUFICIENTE. Saldo: %.2f | Solic: %.2f.', $decision['balanceBefore'],$decision['requested']);
    }

    $noteUrl = rtrim($env['BUK_API_BASE'],'/') . '/vacations/' . urlencode((string)$vacationId) . '/notes';
    $body = json_encode(['message'=>$noteMsg], JSON_UNESCAPED_UNICODE);
    $ch2 = curl_init($noteUrl);
    curl_setopt_array($ch2, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $env['BUK_TOKEN'],'Accept: application/json','Content-Type: application/json; charset=utf-8','Accept-Language: es'],CURLOPT_POSTFIELDS=>$body]);
    $noteResp = curl_exec($ch2); $noteErr  = curl_error($ch2); $noteCode = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE); curl_close($ch2);

    $out = ['ts'=>now_str(), 'event'=>$evt, 'vacation_id'=>$vacationId,'details'=>$details, 'saldo'=>$saldo, 'decision'=>$decision,'note'=>['http'=>$noteCode, 'err'=>$noteErr, 'body'=>$noteResp]];
    file_put_contents($env['LOG_DIR'].'/vac_event_'.$vacationId.'_'.time().'.json', json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

} catch(Throwable $e){
    file_put_contents($env['LOG_DIR'].'/vac_event_err_'.time().'.json', json_encode(['err'=>$e->getMessage(), 'file'=>$e->getFile(), 'line'=>$e->getLine(), 'payload'=>$payload ?? null], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

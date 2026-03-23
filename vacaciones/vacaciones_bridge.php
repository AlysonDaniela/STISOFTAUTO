<?php
declare(strict_types=1);
require_once __DIR__ . '/_compat.php';
require_once __DIR__ . '/includes/auth.php';
require_auth();
$user = current_user();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$env = require __DIR__ . '/config.env.php';
require_once __DIR__ . '/lib/adp_vac.php';

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ensure_dir($d){ if(!is_dir($d)) @mkdir($d, 0775, true); }

ensure_dir($env['ADP_UPLOAD_DIR']); ensure_dir($env['LOG_DIR']);

$adpTarget  = rtrim($env['ADP_UPLOAD_DIR'],'/\\') . DIRECTORY_SEPARATOR . $env['ADP_FILE_NAME'];
$webhookFull= rtrim($env['BASE_PUBLIC_URL'],'/') . '/webhook_vacaciones_buk.php';

$alert = null;
$lastCheck = $_SESSION['vac_last_check'] ?? null;
$lastSim   = $_SESSION['vac_last_sim'] ?? null;
$lastHeaders = $_SESSION['vac_last_headers'] ?? null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action = $_POST['action'] ?? '';

    if($action==='upload_adp' && isset($_FILES['adp_file'])){
        $f = $_FILES['adp_file'];
        if($f['error']===UPLOAD_ERR_OK){
            $ok = move_uploaded_file($f['tmp_name'], $adpTarget);
            $alert = $ok ? ['type'=>'success','msg'=>'Archivo ADP subido: '. e(basename($adpTarget))]
                         : ['type'=>'error','msg'=>'No se pudo mover el archivo. Revisa permisos.'];
        } else {
            $alert = ['type'=>'error','msg'=>'Error de subida (código '.$f['error'].').'];
        }
    }

    if($action==='check_balance'){
        $rut = trim((string)($_POST['rut'] ?? ''));
        $dias = isset($_POST['dias']) && $_POST['dias'] !== '' ? (float)$_POST['dias'] : null;
        try{
            [$headers, $rows] = read_csv_assoc($adpTarget, ';');
            $saldo = compute_vacation_from_file($rows, $rut);
            $res = ['rut'=>$rut, 'saldo'=>$saldo];
            if($dias !== null && $dias >= 0){
                $res['dias']     = $dias;
                $res['decision'] = decision_from_file_balance($saldo, $dias);
            }
            $_SESSION['vac_last_check']   = $res;    $lastCheck   = $res;
            $_SESSION['vac_last_headers'] = $headers; $lastHeaders = $headers;
            $alert = ['type'=>'success','msg'=>'Consulta realizada.'];
        }catch(Throwable $ex){
            $alert = ['type'=>'error','msg'=>'Error al consultar: '.$ex->getMessage()];
        }
    }

    if($action==='simulate_webhook'){
        $vacationId = (string)($_POST['vacation_id'] ?? '9999');
        $payload = [
            'data' => [
                'event_type'  => 'vacation_create',
                'vacation_id' => $vacationId,
                'tenant_url'  => 'sti-test.buk.cl',
                'date'        => gmdate('c')
            ]
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $url  = $webhookFull;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST        => true,
            CURLOPT_RETURNTRANSFER=> true,
            CURLOPT_TIMEOUT     => 30,
            CURLOPT_HTTPHEADER  =>['Content-Type: application/json; charset=utf-8','Accept: application/json'],
            CURLOPT_POSTFIELDS  => $json
        ]);
        $resp = curl_exec($ch); $err  = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);

        $sim = ['http'=>$code,'err'=>$err,'response'=>$resp ?: '','payload'=>$payload,'ts'=>date('Y-m-d H:i:s')];
        $_SESSION['vac_last_sim'] = $sim; $lastSim = $sim;
        $alert = ['type'=> ($code>=200&&$code<300?'success':'warning'), 'msg'=>'Webhook simulado (HTTP '.$code.')'. ($err? ' — cURL: '.$err:'') ];
    }
}
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<body class="bg-gray-50">
<div class="min-h-screen grid grid-cols-12">
    <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
        <?php $active='vacaciones'; include __DIR__ . '/partials/sidebar.php'; ?>
    </div>

    <div class="col-span-12 md:grid-cols-9 lg:col-span-10">
        <?php include __DIR__ . '/partials/topbar.php'; ?>

        <main class="max-w-7xl mx-auto p-6 space-y-6">
            <section class="space-y-3">
                <div class="flex items-center justify-between">
                    <h1 class="text-xl font-semibold">Vacaciones — Bridge ADP ↔ BUK</h1>
                    <div class="text-xs text-gray-500">Archivo de saldos VACACIONES_TERM_STI (PHP 7.x)</div>
                </div>

                <?php if($alert): ?>
                    <div class="<?= $alert['type']==='success'?'bg-emerald-50 text-emerald-700 border-emerald-200':($alert['type']==='error'?'bg-rose-50 text-rose-700 border-rose-200':'bg-amber-50 text-amber-700 border-amber-200') ?> border rounded-2xl px-4 py-3 text-sm">
                        <?= e($alert['msg']) ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="bg-white border rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b flex items-center justify-between">
                    <div class="font-semibold">Archivo ADP (TXT/CSV)</div>
                    <div class="text-sm text-gray-500"><?= is_file($adpTarget) ? 'Actual: '.e(basename($adpTarget)) : 'Sin archivo cargado' ?></div>
                </div>
                <div class="p-5">
                    <form method="post" enctype="multipart/form-data" class="flex items-center gap-3">
                        <input type="hidden" name="action" value="upload_adp">
                        <label class="px-3 py-2 rounded-lg border text-sm cursor-pointer hover:bg-white">
                            <input type="file" name="adp_file" accept=".txt,.csv" class="hidden" onchange="this.form.submit()">
                            <i class="fa-solid fa-upload mr-1"></i> Subir archivo
                        </label>
                        <?php if(is_file($adpTarget)): ?>
                            <span class="text-xs text-gray-500">
                                Tamaño: <?= number_format(filesize($adpTarget)) ?> bytes ·
                                Modificado: <?= date('Y-m-d H:i', filemtime($adpTarget)) ?>
                            </span>
                        <?php endif; ?>
                    </form>
                </div>
            </section>

            <section class="bg-white border rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b flex items-center justify-between">
                    <div class="font-semibold">Consulta rápida (ID → saldo)</div>
                </div>
                <div class="p-5 space-y-4">
                    <form method="post" class="grid md:grid-cols-3 gap-3">
                        <input type="hidden" name="action" value="check_balance">
                        <input type="text" name="rut" placeholder="RUT o Código de Empleado ADP" required class="px-3 py-2 border rounded-lg">
                        <input type="number" step="0.5" min="0" name="dias" placeholder="Días solicitados (opcional)" class="px-3 py-2 border rounded-lg">
                        <button class="px-3 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">Calcular</button>
                    </form>

                    <?php if($lastCheck): ?>
                        <div class="grid md:grid-cols-2 gap-4">
                            <details class="border rounded-xl" open>
                                <summary class="cursor-pointer px-3 py-2 bg-gray-50 border-b rounded-t-xl text-sm font-medium">
                                    Saldo (desde archivo ADP)
                                </summary>
                                <div class="p-3 text-sm">
                                    <div><strong>ID Consultado:</strong> <?= e($lastCheck['rut']) ?></div>

                                    <?php if(!$lastCheck['saldo']['found']): ?>
                                        <p class="text-rose-700 mt-2">✖ El ID no existe en el archivo ADP.</p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            Probé columnas:
                                            <?= e(implode(', ', $lastCheck['saldo']['debug']['candidateColumns'] ?? [])) ?>.
                                        </p>
                                        <?php if(!empty($lastCheck['saldo']['debug']['suggestions'] ?? [])): ?>
                                            <div class="mt-2">
                                                <div class="text-xs text-gray-600 mb-1">Sugerencias encontradas:</div>
                                                <ul class="list-disc pl-5 text-xs">
                                                    <?php foreach($lastCheck['saldo']['debug']['suggestions'] as $s): ?>
                                                        <li><strong><?= e($s['col']) ?>:</strong> <?= e($s['value']) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                        <p class="text-xs text-gray-500">
                                            Variantes probadas:
                                            <?= e(implode(', ', $lastCheck['saldo']['debug']['inputVariants'] ?? [])) ?>
                                        </p>
                                    <?php else: ?>
                                        <?php
                                            $raw = $lastCheck['saldo']['rows'][0] ?? null;
                                        ?>
                                        <?php if($raw): ?>
                                            <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2 text-xs text-gray-700">
                                                <div><strong>Nombre:</strong> <?= e($raw['Nombre'] ?? '') ?></div>
                                                <div><strong>Empresa:</strong> <?= e($raw['Empresa'] ?? '') ?></div>
                                                <div><strong>Centro de Costo:</strong> <?= e($raw['Centro de Costo'] ?? '') ?></div>
                                                <div>
                                                    <strong>Fecha Vacaciones:</strong>
                                                    <?= e($raw['Fecha de Vacaciones'] ?? ($raw['Fecha Vacaciones'] ?? '')) ?>
                                                </div>
                                                <div><strong>Periodos acumulados:</strong> <?= e($raw['Periodos Acumulados'] ?? '') ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <p class="text-xs text-gray-500 mt-2">
                                            Coincidió por columna:
                                            <strong><?= e($lastCheck['saldo']['matchedBy'] ?? 'desconocida') ?></strong>
                                        </p>

                                        <table class="min-w-full text-xs mt-2">
                                            <thead class="bg-gray-50 text-gray-600">
                                                <tr>
                                                    <th class="text-left px-2 py-1">Año</th>
                                                    <th class="text-left px-2 py-1">Otorgados</th>
                                                    <th class="text-left px-2 py-1">Tomados</th>
                                                    <th class="text-left px-2 py-1">Saldo</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                <?php foreach($lastCheck['saldo']['byYear'] as $y): ?>
                                                    <tr>
                                                        <td class="px-2 py-1">
                                                            <?= isset($y['year']) && $y['year'] ? (int)$y['year'] : '-' ?>
                                                        </td>
                                                        <td class="px-2 py-1">
                                                            <?= is_null($y['granted']) ? '-' : number_format((float)$y['granted'],2,',','.') ?>
                                                        </td>
                                                        <td class="px-2 py-1">
                                                            <?= is_null($y['taken']) ? '-' : number_format((float)$y['taken'],2,',','.') ?>
                                                        </td>
                                                        <td class="px-2 py-1">
                                                            <?= is_null($y['balance']) ? '-' : number_format((float)$y['balance'],2,',','.') ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <th colspan="3" class="text-right px-2 py-1">Saldo total</th>
                                                    <th class="px-2 py-1">
                                                        <?= is_null($lastCheck['saldo']['totalBalance']) ? '-' : number_format((float)$lastCheck['saldo']['totalBalance'],2,',','.') ?>
                                                    </th>
                                                </tr>
                                            </tfoot>
                                        </table>

                                        <?php if(isset($lastCheck['decision'])): ?>
                                            <?php $d = $lastCheck['decision']; ?>
                                            <div class="mt-4 p-3 rounded-lg border text-xs <?= $d['canApprove'] ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-amber-50 border-amber-200 text-amber-700' ?>">
                                                <div class="font-semibold mb-1">
                                                    <?= e($d['label']) ?>
                                                </div>
                                                <div>
                                                    Solicitado: <?= number_format((float)$d['requested'],2,',','.') ?> días ·
                                                    Disponible: <?= number_format((float)$d['available'],2,',','.') ?> días ·
                                                    Quedarían: <?= number_format((float)$d['remaining'],2,',','.') ?> días
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </details>

                            <details class="border rounded-xl" open>
                                <summary class="cursor-pointer px-3 py-2 bg-gray-50 border-b rounded-t-xl text-sm font-medium">Diagnóstico</summary>
                                <div class="p-3 text-xs text-gray-700 space-y-2">
                                    <?php if($lastHeaders): ?>
                                        <div>
                                            <strong>Cabeceras detectadas (<?= count($lastHeaders) ?>):</strong>
                                            <?= e(implode(' | ', $lastHeaders)) ?>
                                        </div>
                                        <div class="text-[11px] text-gray-500">
                                            Esperado para archivo actual:
                                            Codigo; Rut; Nombre; Codigo Empresa; Empresa; Centro de Costo; Fecha de Vacaciones; Fecha de Retiro;
                                            Dias Progresivos; Dias Anuales; Dias Proporcionales; Dias Adicionales;
                                            Dias habiles (Normales + Progresivos+ Adicionales); Periodos Acumulados
                                        </div>
                                    <?php else: ?>
                                        <div>Corre una consulta para ver las cabeceras del archivo.</div>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Simulador de Webhook -->
            <section class="bg-white border rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b flex items-center justify-between">
                    <div class="font-semibold">Simular Webhook de BUK</div>
                    <div class="text-sm text-gray-500">Prueba end-to-end (POST a tu endpoint)</div>
                </div>
                <div class="p-5 space-y-3">
                    <form method="post" class="flex items-center gap-3">
                        <input type="hidden" name="action" value="simulate_webhook">
                        <input type="text" name="vacation_id" class="px-3 py-2 border rounded-lg" placeholder="vacation_id de prueba" value="9999">
                        <button class="px-3 py-2 rounded-lg border text-sm hover:bg-white">Enviar webhook simulado</button>
                    </form>

                    <?php if($lastSim): ?>
                        <div class="grid md:grid-cols-2 gap-4">
                            <details class="border rounded-xl" open>
                                <summary class="cursor-pointer px-3 py-2 bg-gray-50 border-b rounded-t-xl text-sm font-medium">Respuesta</summary>
                                <div class="p-3 text-sm">
                                    <div>
                                        HTTP: <strong><?= (int)$lastSim['http'] ?></strong>
                                        <?= $lastSim['err']? ' · cURL: '.e($lastSim['err']):'' ?>
                                    </div>
                                    <pre class="mt-2 p-3 text-xs overflow-auto bg-gray-50 rounded"><?= e($lastSim['response']) ?></pre>
                                </div>
                            </details>
                            <details class="border rounded-xl">
                                <summary class="cursor-pointer px-3 py-2 bg-gray-50 border-b rounded-t-xl text-sm font-medium">Payload Enviado</summary>
                                <pre class="p-3 text-xs overflow-auto bg-gray-50 rounded-b-xl">
<?= e(json_encode($lastSim['payload'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?>
                                </pre>
                            </details>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>
</body>
</html>
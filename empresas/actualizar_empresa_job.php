<?php
// /empresas/actualizar_empresa_job.php
// - Resuelve buk_company_id según reglas ADP
// - Guarda buk_company_id + company_buk en adp_empleados
// - PATCH a Buk al JOB VIGENTE (NO usa buk_job_id local): GET /employees/{id}/jobs
// - Verifica con GET /employees/{id}/jobs/{jobId}
// - Botón RESET para dejar todo pendiente y probar de nuevo

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Anti "pantalla en blanco"
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    header('Content-Type: text/html; charset=utf-8', true);
    echo "<h2 style='font-family:Arial;color:#b91c1c'>FATAL (shutdown)</h2>";
    echo "<pre style='background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;white-space:pre-wrap;'>";
    echo htmlspecialchars(print_r($e, true), ENT_QUOTES, 'UTF-8');
    echo "</pre>";
  }
});

require_once __DIR__ . '/../includes/auth.php';
require_auth();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../conexion/db.php';
$db = new clsConexion();

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function sql_escape($s): string { return addslashes((string)$s); }

// ---------- Schema helpers ----------
function get_columns(clsConexion $db, string $table): array {
  $tableSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  $rows = $db->consultar("SHOW COLUMNS FROM {$tableSafe}");
  $cols = [];
  foreach ($rows as $r) $cols[] = $r['Field'];
  return $cols;
}
function col_exists(array $cols, string $name): bool { return in_array($name, $cols, true); }
function first_existing_col(array $cols, array $candidates): ?string {
  foreach ($candidates as $c) if (col_exists($cols, $c)) return $c;
  return null;
}

// ---------- Mapeo ----------
function map_empresa_sistema_a_buk(?int $empresaSistema): ?int {
  if ($empresaSistema === null) return null;
  // 3 (sistema) -> 2 (Buk), 2 -> 3, 1 -> 1
  if ($empresaSistema === 3) return 2;
  if ($empresaSistema === 2) return 3;
  if ($empresaSistema === 1) return 1;
  return null;
}
function resolver_company_id_buk(?int $empresaAdp, $credencial): ?int {
  if ($empresaAdp === null) return null;
  // DAMEC = 101 -> usar Credencial (1/2/3)
  if ((int)$empresaAdp === 101) {
    $c = is_numeric($credencial) ? (int)$credencial : null;
    return map_empresa_sistema_a_buk($c);
  }
  return map_empresa_sistema_a_buk((int)$empresaAdp);
}

// ---------- BUK config ----------
$BUK_BASE_URL = defined('BUK_BASE_URL') ? BUK_BASE_URL : 'https://sti.buk.cl/api/v1/chile';
$BUK_TOKEN    = defined('BUK_TOKEN') ? BUK_TOKEN : 'bAVH6fNSraVT17MBv1ECPrfW';

if (!$BUK_BASE_URL || !$BUK_TOKEN) {
  die("<pre>Faltan BUK_BASE_URL / BUK_TOKEN. Usa tus constantes como en otros archivos.</pre>");
}

// ---------- HTTP ----------
function curl_call(string $method, string $url, array $headers, ?array $body = null): array {
  $ch = curl_init($url);
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 60,
  ];
  if ($body !== null) {
    $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
  }
  curl_setopt_array($ch, $opts);

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $json = null;
  if (is_string($resp) && $resp !== '') $json = json_decode($resp, true);

  return ['code'=>$code,'raw'=>$resp,'json'=>$json,'err'=>$err];
}
function buk_headers_candidates(string $token): array {
  // probamos 3 formatos comunes
  return [
    'token_token' => [
      'Authorization: Token token=' . $token,
      'Content-Type: application/json',
      'Accept: application/json',
    ],
    'bearer' => [
      'Authorization: Bearer ' . $token,
      'Content-Type: application/json',
      'Accept: application/json',
    ],
    'auth_token' => [
      'auth_token: ' . $token,
      'Content-Type: application/json',
      'Accept: application/json',
    ],
  ];
}
function buk_request_authed(string $baseUrl, string $token, string $method, string $path, ?array $body=null): array {
  $url = rtrim($baseUrl, '/') . $path;
  $candidates = buk_headers_candidates($token);

  $attempts = [];
  foreach ($candidates as $key => $headers) {
    $r = curl_call($method, $url, $headers, $body);
    $attempts[$key] = $r;

    // si no es 401/403, lo usamos
    if (!in_array((int)$r['code'], [401,403], true) && !$r['err']) {
      return [
        'ok' => ((int)$r['code'] >= 200 && (int)$r['code'] < 300),
        'code' => (int)$r['code'],
        'json' => $r['json'],
        'raw'  => $r['raw'],
        'err'  => $r['err'],
        'auth_mode' => $key,
        'url' => $url,
        'attempts' => $attempts,
      ];
    }
  }

  $lastKey = array_key_last($attempts);
  $last = $attempts[$lastKey];

  return [
    'ok' => false,
    'code' => (int)$last['code'],
    'json' => $last['json'],
    'raw'  => $last['raw'],
    'err'  => $last['err'],
    'auth_mode' => $lastKey,
    'url' => $url,
    'attempts' => $attempts,
  ];
}

// ---------- JOB actual ----------
function buk_extract_jobs($json) {
  if (!is_array($json)) return [];
  if (isset($json['data']) && is_array($json['data'])) return $json['data'];
  // fallback si viene directo
  return is_array($json) ? $json : [];
}

/**
 * Selecciona job vigente:
 * 1) current=true
 * 2) end_date null/vacío
 * 3) start_date más reciente
 */
function buk_pick_current_job_id(array $jobs): ?int {
  foreach ($jobs as $j) {
    if (isset($j['current']) && $j['current'] && isset($j['id'])) return (int)$j['id'];
  }
  foreach ($jobs as $j) {
    if (isset($j['id']) && (!isset($j['end_date']) || $j['end_date'] === null || $j['end_date'] === '')) {
      return (int)$j['id'];
    }
  }
  $bestId = null; $bestDate = '';
  foreach ($jobs as $j) {
    if (!isset($j['id'])) continue;
    $d = (string)($j['start_date'] ?? '');
    if ($d >= $bestDate) { $bestDate = $d; $bestId = (int)$j['id']; }
  }
  return $bestId;
}

function buk_get_current_job_id(string $baseUrl, string $token, $employeeId): array {
  $r = buk_request_authed($baseUrl, $token, 'GET', "/employees/{$employeeId}/jobs", null);
  if (!$r['ok']) return ['ok'=>false, 'job_id'=>null, 'resp'=>$r];

  $jobs = buk_extract_jobs($r['json']);
  $jobId = buk_pick_current_job_id($jobs);
  return ['ok'=>true, 'job_id'=>$jobId, 'resp'=>$r];
}

function buk_get_job_company_id($json): ?int {
  if (!is_array($json)) return null;
  if (isset($json['data']['company_id'])) return (int)$json['data']['company_id'];
  if (isset($json['company_id'])) return (int)$json['company_id'];
  return null;
}

// ---------- Params ----------
$dryRun = isset($_GET['dry']) ? (int)$_GET['dry'] : 1;
$limit  = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 50;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$soloPendientes = isset($_GET['solo_pendientes']) ? (int)$_GET['solo_pendientes'] : 1;

// ---------- Schema detect ----------
$table = 'adp_empleados';
$cols = get_columns($db, $table);

$colRut        = first_existing_col($cols, ['Rut','rut']);
$colEstado     = first_existing_col($cols, ['Estado','estado']);
$colEmpresa    = first_existing_col($cols, ['Empresa','empresa']);
$colCredencial = first_existing_col($cols, ['Credencial','credencial']);
$colBukEmp     = first_existing_col($cols, ['buk_emp_id','buk_employee_id']);
$colBukCompany = first_existing_col($cols, ['buk_company_id']);
$colCompanyBuk = first_existing_col($cols, ['company_buk']);

$schemaErrors = [];
foreach ([
  'Rut'=>$colRut,
  'Estado'=>$colEstado,
  'Empresa'=>$colEmpresa,
  'Credencial'=>$colCredencial,
  'buk_emp_id'=>$colBukEmp,
  'buk_company_id'=>$colBukCompany,
  'company_buk'=>$colCompanyBuk,
] as $need=>$got) {
  if ($got === null) $schemaErrors[] = "Falta columna requerida: {$need}";
}

// ---------- RESET ----------
$resetMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_reset']) && empty($schemaErrors)) {
  $db->ejecutar("
    UPDATE {$table}
    SET `{$colBukCompany}` = NULL,
        `{$colCompanyBuk}` = 'pendiente'
    WHERE `{$colEstado}`='A'
  ");
  $resetMsg = "RESET OK: activos quedaron con buk_company_id=NULL y company_buk=pendiente.";
}

// ---------- Auth check ----------
$authCheck = buk_request_authed($BUK_BASE_URL, $BUK_TOKEN, 'GET', '/employees?per_page=1', null);

// ---------- Proceso lote ----------
$results = [];
$stats = ['total'=>0,'patched'=>0,'updated_local'=>0,'skipped'=>0,'errors'=>0];

if (empty($schemaErrors)) {
  $whereExtra = '';
  if ($soloPendientes) {
    $whereExtra = " AND (`{$colCompanyBuk}` IN ('pendiente','error') OR `{$colCompanyBuk}` IS NULL) ";
  }

  $sqlLote = "
    SELECT
      `{$colRut}` AS rut,
      `{$colEmpresa}` AS adp_empresa,
      `{$colCredencial}` AS credencial,
      `{$colBukEmp}` AS buk_employee_id,
      `{$colBukCompany}` AS buk_company_id,
      `{$colCompanyBuk}` AS company_buk
    FROM {$table}
    WHERE `{$colEstado}`='A'
    {$whereExtra}
    ORDER BY `{$colEmpresa}`, `{$colRut}`
    LIMIT {$limit} OFFSET {$offset}
  ";

  $rows = $db->consultar($sqlLote);

  foreach ($rows as $r) {
    $stats['total']++;

    $rut = (string)$r['rut'];
    $rutEsc = sql_escape($rut);

    $bukEmployeeId = trim((string)$r['buk_employee_id']);
    $empresaAdp = is_numeric($r['adp_empresa']) ? (int)$r['adp_empresa'] : null;
    $credencial = $r['credencial'];

    $companyId = resolver_company_id_buk($empresaAdp, $credencial);

    // no se puede resolver -> error
    if ($companyId === null) {
      $stats['errors']++;
      $db->ejecutar("UPDATE {$table} SET `{$colBukCompany}`=NULL, `{$colCompanyBuk}`='error' WHERE `{$colRut}`='{$rutEsc}'");
      $results[] = ['rut'=>$rut,'adp_empresa'=>$empresaAdp,'company_id'=>null,'ok'=>false,'msg'=>'No se pudo resolver buk_company_id (empresa/credencial).'];
      continue;
    }

    // guardar buk_company_id si falta o cambió
    $currentBukCompany = is_numeric($r['buk_company_id']) ? (int)$r['buk_company_id'] : null;
    if ($currentBukCompany === null || $currentBukCompany !== $companyId) {
      $db->ejecutar("UPDATE {$table} SET `{$colBukCompany}`={$companyId}, `{$colCompanyBuk}`=COALESCE(`{$colCompanyBuk}`,'pendiente') WHERE `{$colRut}`='{$rutEsc}'");
      $stats['updated_local']++;
    }

    // sin buk_employee_id -> no puedo llamar a Buk
    if ($bukEmployeeId === '') {
      $stats['skipped']++;
      $db->ejecutar("UPDATE {$table} SET `{$colCompanyBuk}`='pendiente' WHERE `{$colRut}`='{$rutEsc}'");
      $results[] = ['rut'=>$rut,'buk_employee_id'=>'','job_id_real'=>'','adp_empresa'=>$empresaAdp,'company_id'=>$companyId,'ok'=>false,'msg'=>'Sin buk_emp_id. Queda pendiente.'];
      continue;
    }

    // buscar job vigente en Buk
    $jobInfo = buk_get_current_job_id($BUK_BASE_URL, $BUK_TOKEN, $bukEmployeeId);
    $jobIdReal = $jobInfo['job_id'];

    if (!$jobInfo['ok'] || !$jobIdReal) {
      $stats['errors']++;
      $db->ejecutar("UPDATE {$table} SET `{$colCompanyBuk}`='error' WHERE `{$colRut}`='{$rutEsc}'");
      $results[] = [
        'rut'=>$rut,
        'buk_employee_id'=>$bukEmployeeId,
        'job_id_real'=>null,
        'adp_empresa'=>$empresaAdp,
        'company_id'=>$companyId,
        'ok'=>false,
        'msg'=>'No pude obtener job vigente (GET /employees/{id}/jobs).',
        'raw'=>substr(json_encode($jobInfo['resp']['json'] ?? $jobInfo['resp']['raw'], JSON_UNESCAPED_UNICODE), 0, 1500),
      ];
      continue;
    }

    $path = "/employees/{$bukEmployeeId}/jobs/{$jobIdReal}";
    $body = ['data' => ['company_id' => (int)$companyId]]; // ✅ formato correcto

    if ($dryRun === 1) {
      $stats['skipped']++;
      $results[] = [
        'rut'=>$rut,
        'buk_employee_id'=>$bukEmployeeId,
        'job_id_real'=>$jobIdReal,
        'adp_empresa'=>$empresaAdp,
        'company_id'=>$companyId,
        'ok'=>true,
        'msg'=>"DRY RUN. Job vigente={$jobIdReal}.",
        'endpoint'=>$path,
      ];
      continue;
    }

    // PATCH
    $resp = buk_request_authed($BUK_BASE_URL, $BUK_TOKEN, 'PATCH', $path, $body);

    // VERIFY
    $verify = buk_request_authed($BUK_BASE_URL, $BUK_TOKEN, 'GET', $path, null);
    $actualCompanyId = ($verify['ok']) ? buk_get_job_company_id($verify['json']) : null;

    if ($resp['ok'] && $verify['ok'] && $actualCompanyId === (int)$companyId) {
      $stats['patched']++;
      $db->ejecutar("UPDATE {$table} SET `{$colCompanyBuk}`='ok' WHERE `{$colRut}`='{$rutEsc}'");
      $results[] = [
        'rut'=>$rut,
        'buk_employee_id'=>$bukEmployeeId,
        'job_id_real'=>$jobIdReal,
        'adp_empresa'=>$empresaAdp,
        'company_id'=>$companyId,
        'ok'=>true,
        'msg'=>"PATCH OK y verificado (company_id={$actualCompanyId})",
      ];
    } else {
      $stats['errors']++;
      $db->ejecutar("UPDATE {$table} SET `{$colCompanyBuk}`='error' WHERE `{$colRut}`='{$rutEsc}'");

      $results[] = [
        'rut'=>$rut,
        'buk_employee_id'=>$bukEmployeeId,
        'job_id_real'=>$jobIdReal,
        'adp_empresa'=>$empresaAdp,
        'company_id'=>$companyId,
        'ok'=>false,
        'msg'=>"NO CAMBIÓ EN BUK. Esperado={$companyId} quedó=".($actualCompanyId===null?'null':$actualCompanyId)." | PATCH_HTTP={$resp['code']} VERIFY_HTTP={$verify['code']}",
        'raw'=>substr(json_encode(['patch'=>$resp['json'] ?? $resp['raw'], 'verify'=>$verify['json'] ?? $verify['raw']], JSON_UNESCAPED_UNICODE), 0, 1500),
      ];
    }
  }
}

// ---------- Lista global ----------
$lista = [];
if (empty($schemaErrors)) {
  $lista = $db->consultar("
    SELECT
      `{$colRut}` AS rut,
      `{$colEmpresa}` AS adp_empresa,
      `{$colBukCompany}` AS buk_company_id,
      `{$colCompanyBuk}` AS company_buk
    FROM {$table}
    WHERE `{$colEstado}`='A'
    ORDER BY `{$colEmpresa}`, `{$colRut}`
  ");
}

?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="bg-gray-50">
<div class="min-h-screen grid grid-cols-12">
  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php $active='empresas'; include __DIR__ . '/../partials/sidebar.php'; ?>
  </div>

  <div class="col-span-12 md:col-span-9 lg:col-span-10">
    <div class="max-w-7xl mx-auto px-4 py-6 space-y-4">

      <div class="bg-white border rounded-xl p-4">
        <h1 class="text-lg font-semibold">Actualizar empresa en Job (Buk) — Solo Estado='A'</h1>
        <p class="text-xs text-gray-600 mt-1">
          Usa JOB vigente vía <code>GET /employees/{employee_id}/jobs</code>. PATCH con <code>{"data":{"company_id":X}}</code> y verificación posterior.
        </p>

        <?php if ($resetMsg): ?>
          <div class="mt-3 text-xs px-3 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800">
            <?= h($resetMsg) ?>
          </div>
        <?php endif; ?>

        <div class="mt-4 flex flex-wrap gap-2 items-center">
          <a class="px-3 py-1.5 rounded-full bg-gray-900 text-white inline-block"
             href="?dry=1&solo_pendientes=1&limit=<?= (int)$limit ?>&offset=<?= (int)$offset ?>">Probar (dry=1)</a>

          <a class="px-3 py-1.5 rounded-full bg-blue-600 text-white inline-block"
             href="?dry=0&solo_pendientes=1&limit=<?= (int)$limit ?>&offset=<?= (int)$offset ?>"
             onclick="return confirm('Ejecutar PATCH real en Buk para este lote. ¿Continuar?');">Ejecutar PATCH (dry=0)</a>

          <a class="px-3 py-1.5 rounded-full bg-slate-200 text-slate-800 inline-block"
             href="?dry=1&solo_pendientes=0&limit=<?= (int)$limit ?>&offset=<?= (int)$offset ?>">Ver lote sin filtro</a>

          <form method="post" style="display:inline">
            <button type="submit" name="do_reset" value="1"
              class="px-3 py-1.5 rounded-full bg-red-600 text-white inline-block"
              onclick="return confirm('RESET: pondrá buk_company_id=NULL y company_buk=pendiente para TODOS los activos. ¿Continuar?');">
              RESET (limpiar data)
            </button>
          </form>
        </div>

        <div class="mt-3 text-xs">
          <div><strong>Modo:</strong> <?= $dryRun ? '<span style="color:#b45309">DRY RUN</span>' : '<span style="color:#047857">EJECUTANDO PATCH</span>' ?></div>
          <div><strong>limit:</strong> <?= (int)$limit ?> | <strong>offset:</strong> <?= (int)$offset ?> | <strong>solo_pendientes:</strong> <?= (int)$soloPendientes ?></div>

          <div class="mt-2">
            <strong>Resumen lote:</strong>
            total=<?= (int)$stats['total'] ?> |
            patched=<?= (int)$stats['patched'] ?> |
            updated_local=<?= (int)$stats['updated_local'] ?> |
            skipped=<?= (int)$stats['skipped'] ?> |
            errors=<?= (int)$stats['errors'] ?>
          </div>

          <div class="mt-3 p-3 rounded-lg bg-gray-50 border text-[11px]">
            <div class="font-semibold mb-1">Auth check</div>
            <div><strong>URL:</strong> <?= h($authCheck['url'] ?? '') ?></div>
            <div><strong>HTTP:</strong> <?= (int)($authCheck['code'] ?? 0) ?> | <strong>Auth:</strong> <?= h($authCheck['auth_mode'] ?? '') ?></div>
          </div>
        </div>
      </div>

      <?php if (!empty($schemaErrors)): ?>
        <div class="bg-white border rounded-xl p-4">
          <div class="text-sm font-semibold text-red-700">Faltan columnas / esquema inválido</div>
          <ul class="mt-2 text-xs list-disc pl-5 text-red-700">
            <?php foreach ($schemaErrors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
          <div class="mt-3 text-xs text-gray-600">Columnas detectadas en adp_empleados:</div>
          <pre style="white-space:pre-wrap; font-size:11px; background:#f9fafb; padding:10px; border-radius:8px;"><?= h(implode(", ", $cols)) ?></pre>
        </div>
      <?php endif; ?>

      <div class="bg-white border rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b text-sm font-semibold">Resultados (lote)</div>
        <div class="overflow-x-auto">
          <table class="min-w-full text-xs">
            <thead class="bg-gray-50 text-[11px] uppercase text-gray-500">
              <tr>
                <th class="px-3 py-2 text-left">RUT</th>
                <th class="px-3 py-2 text-left">buk_emp_id</th>
                <th class="px-3 py-2 text-left">job_id vigente</th>
                <th class="px-3 py-2 text-left">Empresa ADP</th>
                <th class="px-3 py-2 text-left">Credencial</th>
                <th class="px-3 py-2 text-left">company_id objetivo</th>
                <th class="px-3 py-2 text-left">Estado</th>
                <th class="px-3 py-2 text-left">Mensaje</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <?php if (empty($results)): ?>
                <tr><td colspan="8" class="px-3 py-3 text-gray-500">Sin filas en este lote.</td></tr>
              <?php else: ?>
                <?php foreach ($results as $it): ?>
                  <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2"><?= h($it['rut'] ?? '') ?></td>
                    <td class="px-3 py-2 font-mono"><?= h($it['buk_employee_id'] ?? '') ?></td>
                    <td class="px-3 py-2 font-mono"><?= h($it['job_id_real'] ?? '') ?></td>
                    <td class="px-3 py-2"><?= h($it['adp_empresa'] ?? '') ?></td>
                    <td class="px-3 py-2"><?= h($it['credencial'] ?? '') ?></td>
                    <td class="px-3 py-2 font-mono"><?= h($it['company_id'] ?? '') ?></td>
                    <td class="px-3 py-2">
                      <?php if (!empty($it['ok'])): ?>
                        <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[10px] font-semibold">OK</span>
                      <?php else: ?>
                        <span class="px-2 py-0.5 rounded-full bg-red-100 text-red-800 text-[10px] font-semibold">ERROR</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2">
                      <div class="max-w-xl truncate" title="<?= h($it['msg'] ?? '') ?>"><?= h($it['msg'] ?? '') ?></div>
                    </td>
                  </tr>
                  <?php if (!empty($it['raw']) && empty($it['ok'])): ?>
                    <tr><td colspan="8" class="px-3 py-2 bg-gray-50">
                      <pre style="white-space:pre-wrap; font-size:11px;"><?= h($it['raw']) ?></pre>
                    </td></tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="bg-white border rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b text-sm font-semibold">Listado de activos (Estado='A')</div>
        <div class="overflow-x-auto">
          <table class="min-w-full text-xs">
            <thead class="bg-gray-50 text-[11px] uppercase text-gray-500">
              <tr>
                <th class="px-3 py-2 text-left">RUT</th>
                <th class="px-3 py-2 text-left">Empresa (ADP)</th>
                <th class="px-3 py-2 text-left">buk_company_id</th>
                <th class="px-3 py-2 text-left">company_buk</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <?php foreach ($lista as $x): ?>
                <?php
                  $st = $x['company_buk'] ?? 'pendiente';
                  $badgeClass = 'bg-yellow-50 text-yellow-800';
                  if ($st === 'ok') $badgeClass = 'bg-emerald-100 text-emerald-800';
                  elseif ($st === 'error') $badgeClass = 'bg-red-100 text-red-800';
                ?>
                <tr class="hover:bg-gray-50">
                  <td class="px-3 py-2"><?= h($x['rut']) ?></td>
                  <td class="px-3 py-2"><?= h($x['adp_empresa']) ?></td>
                  <td class="px-3 py-2 font-mono"><?= h($x['buk_company_id']) ?></td>
                  <td class="px-3 py-2">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold <?= $badgeClass ?>"><?= h($st) ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>
</body>
</html>
<?php
/**
 * /documentos/atributos_archivo.php
 *
 * Masivo de atributos personalizados (Archivo CSV -> Buk)
 *
 * CSV requerido (headers detectables):
 * - Rut
 * - ID en BUK
 * - Sindicato Anterior
 * - Sindicato Actual
 *
 * Estados en CACHE (SESSION) por fila (NO BD):
 * - pendiente  : cargado, no enviado
 * - ok         : enviado OK (2xx)
 * - error      : enviado pero falló (4xx/5xx o cURL)
 * - skip       : inválido (sin buk_id o sin atributos para enviar)
 *
 * Funcionalidades:
 * - Subir CSV -> queda en session con estado "pendiente"
 * - Enviar TODO o SELECCIONADOS, por batches
 * - Mostrar resumen OK/ERROR/PENDIENTE/SKIP
 * - Filtro por estado (ok/pendiente/error/skip/todos)
 * - En caso de error: muestra HTTP + Payload + Respuesta Buk
 * - En caso de ok: muestra al menos HTTP + Payload (y body si viene)
 */

ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_auth();
$user = current_user();

if (session_status() === PHP_SESSION_NONE) session_start();
ini_set('default_charset', 'UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

/* ===================== CONFIG ===================== */
require_once __DIR__ . '/../includes/runtime_config.php';
$bukCfg = runtime_buk_config();
define('BUK_API_BASE', $bukCfg['base'] . '/employees');
define('BUK_TOKEN', $bukCfg['token']);
const LOG_DIR      = __DIR__ . '/logs_buk_attr_file';
const CURL_TIMEOUT = 60;
const MAX_ROWS     = 20000;

/** Keys EXACTAS en Buk (tal como las creaste) */
const ATTR_KEY_ACTUAL   = 'Sindicato Actual';
const ATTR_KEY_ANTERIOR = 'Sindicato Anterior';

/* Layout (tu estructura) */
$headPath    = __DIR__ . '/../partials/head.php';
$sidebarPath = __DIR__ . '/../partials/sidebar.php';
$topbarPath  = __DIR__ . '/../partials/topbar.php';
$footerPath  = __DIR__ . '/../partials/footer.php';

if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);

/* ===================== SESSION CACHE ===================== */
if (!isset($_SESSION['buk_attr_file_rows'])) $_SESSION['buk_attr_file_rows'] = [];
if (!isset($_SESSION['buk_attr_file_meta'])) $_SESSION['buk_attr_file_meta'] = [];

/* ===================== HELPERS ===================== */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function rut_norm($rut): string {
  $rut = strtoupper(trim((string)$rut));
  return preg_replace('/[^0-9K]/', '', $rut);
}
function rut_display($rn): string {
  $rn = strtoupper(trim((string)$rn));
  if(strlen($rn) < 2) return $rn;
  return substr($rn, 0, -1) . '-' . substr($rn, -1);
}

function save_log(string $prefix, $data): string {
  if(!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
  $fn = LOG_DIR.'/'.$prefix.'_'.date('Ymd_His').'.json';
  file_put_contents($fn, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  return $fn;
}

function detect_delimiter(string $line): string {
  $candidates = [",", ";", "\t", "|"];
  $best = ",";
  $bestCount = -1;
  foreach ($candidates as $d) {
    $count = substr_count($line, $d);
    if ($count > $bestCount) { $bestCount = $count; $best = $d; }
  }
  return $best;
}

function clean_header(string $h): string {
  $h = trim($h);
  $h = preg_replace('/^\xEF\xBB\xBF/', '', $h); // BOM UTF-8
  return $h;
}

function header_key(string $h): string {
  $h = mb_strtolower(trim($h), 'UTF-8');
  $h = str_replace(['á','é','í','ó','ú','ü','ñ'], ['a','e','i','o','u','u','n'], $h);
  $h = preg_replace('/\s+/', ' ', $h);
  return $h;
}

function find_col_idx(array $headers, array $needles): ?int {
  $norm = array_map(fn($h)=>header_key((string)$h), $headers);

  // exact match
  foreach ($needles as $n) {
    $n = header_key($n);
    foreach ($norm as $i => $hh) {
      if ($hh === $n) return $i;
    }
  }
  // contains match
  foreach ($needles as $n) {
    $n = header_key($n);
    foreach ($norm as $i => $hh) {
      if (strpos($hh, $n) !== false) return $i;
    }
  }
  return null;
}

function buk_patch_custom_attributes(int $employee_id, array $customAttrs): array {
  $url = BUK_API_BASE . "/{$employee_id}";
  $payload = ["custom_attributes" => $customAttrs];
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'PATCH',
    CURLOPT_HTTPHEADER => [
      "auth_token: " . BUK_TOKEN,
      "Accept: application/json",
      "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_TIMEOUT => CURL_TIMEOUT
  ]);

  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($err) {
    return ['ok'=>false,'http'=>$http,'error'=>"cURL: $err",'body'=>$resp,'sent'=>$payload];
  }
  return ['ok'=>($http>=200 && $http<300), 'http'=>$http, 'error'=>null, 'body'=>$resp, 'sent'=>$payload];
}

function badge_html(string $st): string {
  $st = strtolower(trim($st));
  if ($st === 'ok') return '<span class="tag tag-ok">OK</span>';
  if ($st === 'error') return '<span class="tag tag-err">ERROR</span>';
  if ($st === 'pendiente') return '<span class="tag tag-pend">PENDIENTE</span>';
  if ($st === 'skip') return '<span class="tag tag-skip">SKIP</span>';
  return '<span class="tag tag-pend">'.e($st).'</span>';
}

/* ===================== UI STATE ===================== */
$msgOk = null;
$msgErr = null;
$logFile = null;

$filter = (string)($_GET['status'] ?? 'all'); // all|ok|pendiente|error|skip
$filter = in_array($filter, ['all','ok','pendiente','error','skip'], true) ? $filter : 'all';

/* ===================== ACTIONS ===================== */

// Limpiar carga
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
  $_SESSION['buk_attr_file_rows'] = [];
  $_SESSION['buk_attr_file_meta'] = [];
  $msgOk = "Listo: se limpió la carga anterior (cache).";
}

// Subir CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
  $_SESSION['buk_attr_file_rows'] = [];
  $_SESSION['buk_attr_file_meta'] = [];

  if (!isset($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $msgErr = "Debes subir un archivo CSV válido.";
  } else {
    $tmp  = $_FILES['csv']['tmp_name'];
    $name = $_FILES['csv']['name'] ?? 'archivo.csv';

    $fh = fopen($tmp, 'r');
    if (!$fh) {
      $msgErr = "No se pudo leer el archivo subido.";
    } else {
      $firstLine = fgets($fh);
      if ($firstLine === false) {
        $msgErr = "Archivo vacío.";
        fclose($fh);
      } else {
        $delimiter = detect_delimiter($firstLine);
        rewind($fh);

        $headers = fgetcsv($fh, 0, $delimiter);
        if (!$headers || count($headers) < 4) {
          $msgErr = "El CSV debe tener al menos 4 columnas: Rut, ID en BUK, Sindicato Anterior, Sindicato Actual.";
          fclose($fh);
        } else {
          $headers = array_map(fn($h)=>clean_header((string)$h), $headers);

          $rutIdx = find_col_idx($headers, ['rut']);
          $idIdx  = find_col_idx($headers, ['id en buk', 'id buk', 'buk id', 'id']);

          $antIdx = find_col_idx($headers, ['sindicato anterior']);
          $actIdx = find_col_idx($headers, ['sindicato actual']);

          if ($rutIdx === null || $idIdx === null) {
            $msgErr = "No pude detectar columnas obligatorias: 'Rut' y 'ID en BUK'.";
            fclose($fh);
          } elseif ($antIdx === null || $actIdx === null) {
            $msgErr = "No pude detectar columnas: 'Sindicato Anterior' y/o 'Sindicato Actual'.";
            fclose($fh);
          } else {

            $rows = [];
            $line = 1; // header
            while (($r = fgetcsv($fh, 0, $delimiter)) !== false) {
              $line++;
              if ($line > (MAX_ROWS + 1)) break;

              $rutRaw = (string)($r[$rutIdx] ?? '');
              $idRaw  = (string)($r[$idIdx] ?? '');
              $antVal = (string)($r[$antIdx] ?? '');
              $actVal = (string)($r[$actIdx] ?? '');

              $rn = rut_norm($rutRaw);
              $bukId = (int)preg_replace('/\D+/', '', $idRaw);

              // saltar fila completamente vacía
              if ($rn === '' && $bukId === 0 && trim($antVal)==='' && trim($actVal)==='') {
                continue;
              }

              $rows[] = [
                'idx' => count($rows),      // índice estable en cache
                'line' => $line,
                'rut_norm' => $rn,
                'rut' => rut_display($rn),
                'buk_emp_id' => $bukId,

                // attrs con KEYS exactas
                'attrs' => [
                  ATTR_KEY_ACTUAL   => trim($actVal),
                  ATTR_KEY_ANTERIOR => trim($antVal),
                ],

                // estado cache
                'status' => 'pendiente',
                'http' => null,
                'payload' => null,
                'body' => null,
                'error' => null,
                'updated_at' => null
              ];
            }
            fclose($fh);

            if (count($rows) === 0) {
              $msgErr = "No se cargaron filas válidas.";
            } else {
              $_SESSION['buk_attr_file_rows'] = $rows;
              $_SESSION['buk_attr_file_meta'] = [
                'filename' => $name,
                'loaded_at' => date('Y-m-d H:i:s'),
                'delimiter' => $delimiter,
                'headers' => $headers,
                'count' => count($rows)
              ];
              $msgOk = "✅ Archivo cargado: ".e($name)." · Filas: ".count($rows).". (Estado: PENDIENTE en cache)";
            }
          }
        }
      }
    }
  }
}

// Enviar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
  $mode = (string)($_POST['mode'] ?? 'selected'); // selected|all
  $batchSize = max(1, (int)($_POST['batch_size'] ?? 100));
  $skipEmpty = ((int)($_POST['skip_empty'] ?? 1) === 1);

  $rows = (array)($_SESSION['buk_attr_file_rows'] ?? []);
  if (!$rows || count($rows) === 0) {
    $msgErr = "No hay datos cargados. Sube el archivo primero.";
  } else {
    // indices a enviar
    if ($mode === 'all') {
      $selected = array_keys($rows);
    } else {
      $selected = array_map('intval', (array)($_POST['row'] ?? []));
      $selected = array_values(array_filter($selected, fn($i)=>isset($rows[$i])));
      if (count($selected) === 0) $msgErr = "No seleccionaste filas para enviar.";
    }

    if (!$msgErr) {
      $run = [
        'mode' => $mode,
        'batch_size' => $batchSize,
        'skip_empty' => $skipEmpty,
        'selected_count' => count($selected),
        'sent' => 0,
        'ok' => 0,
        'error' => 0,
        'skip' => 0,
        'batches' => [],
        'items' => []
      ];

      $batch = [];
      $batchNum = 0;

      $flushBatch = function() use (&$batch, &$batchNum, &$run, &$rows) {
        if (count($batch) === 0) return;

        $batchNum++;
        $bStat = ['batch'=>$batchNum,'count'=>count($batch),'ok'=>0,'error'=>0];

        foreach ($batch as $pack) {
          $i = $pack['idx'];
          $customAttrs = $pack['custom_attrs'];

          $run['sent']++;

          $res = buk_patch_custom_attributes((int)$rows[$i]['buk_emp_id'], $customAttrs);
          $ok = !empty($res['ok']);

          // update cache fila
          $rows[$i]['status'] = $ok ? 'ok' : 'error';
          $rows[$i]['http'] = (int)($res['http'] ?? 0);
          $rows[$i]['payload'] = $res['sent'] ?? null; // payload exacto
          $rows[$i]['body'] = $res['body'] ?? null;    // respuesta Buk
          $rows[$i]['error'] = $res['error'] ?? null;  // cURL si existe
          $rows[$i]['updated_at'] = date('Y-m-d H:i:s');

          if ($ok) { $run['ok']++; $bStat['ok']++; }
          else { $run['error']++; $bStat['error']++; }

          $run['items'][] = [
            'idx' => $i,
            'line' => $rows[$i]['line'],
            'rut' => $rows[$i]['rut'],
            'buk_emp_id' => $rows[$i]['buk_emp_id'],
            'status' => $rows[$i]['status'],
            'http' => $rows[$i]['http'],
            'payload' => $rows[$i]['payload'],
            'body' => $rows[$i]['body'],
            'error' => $rows[$i]['error'],
            'updated_at' => $rows[$i]['updated_at']
          ];
        }

        $run['batches'][] = $bStat;
        $batch = [];
      };

      foreach ($selected as $i) {
        $bukId = (int)($rows[$i]['buk_emp_id'] ?? 0);

        // construir attrs (opcional: saltar vacíos)
        $custom = [];
        foreach ((array)($rows[$i]['attrs'] ?? []) as $k => $v) {
          $k = trim((string)$k);
          if ($k === '') continue;
          if (is_string($v)) $v = trim($v);
          if ($skipEmpty && ($v === '' || $v === null)) continue;
          $custom[$k] = $v;
        }

        // validaciones
        if ($bukId <= 0 || count($custom) === 0) {
          $rows[$i]['status'] = 'skip';
          $rows[$i]['http'] = null;
          $rows[$i]['payload'] = ['custom_attributes' => $custom];
          $rows[$i]['body'] = null;
          $rows[$i]['error'] = ($bukId <= 0) ? 'buk_emp_id inválido' : 'sin atributos para enviar';
          $rows[$i]['updated_at'] = date('Y-m-d H:i:s');

          $run['skip']++;
          $run['items'][] = [
            'idx' => $i,
            'line' => $rows[$i]['line'],
            'rut' => $rows[$i]['rut'],
            'buk_emp_id' => $bukId,
            'status' => 'skip',
            'http' => null,
            'payload' => $rows[$i]['payload'],
            'body' => null,
            'error' => $rows[$i]['error'],
            'updated_at' => $rows[$i]['updated_at']
          ];
          continue;
        }

        $batch[] = ['idx'=>$i, 'custom_attrs'=>$custom];
        if (count($batch) >= $batchSize) $flushBatch();
      }
      $flushBatch();

      // persist cache session
      $_SESSION['buk_attr_file_rows'] = $rows;

      $logFile = save_log('send_'.$mode, [
        'meta' => $_SESSION['buk_attr_file_meta'] ?? null,
        'run' => $run
      ]);

      $msgOk = "✅ Envío terminado. OK: {$run['ok']} · ERROR: {$run['error']} · SKIP: {$run['skip']} · Enviados: {$run['sent']} · Seleccionados: {$run['selected_count']}.";
    }
  }
}




/* ===================== COMPUTE SUMMARY + FILTER ===================== */
$rows = (array)($_SESSION['buk_attr_file_rows'] ?? []);
$meta = (array)($_SESSION['buk_attr_file_meta'] ?? []);

$counts = ['total'=>0,'ok'=>0,'error'=>0,'pendiente'=>0,'skip'=>0];
foreach ($rows as $r) {
  $counts['total']++;
  $st = strtolower((string)($r['status'] ?? 'pendiente'));
  if (!isset($counts[$st])) $st = 'pendiente';
  $counts[$st]++;
}





/* ===================== PAGINACIÓN TABLA ===================== */

// filas por página
$perPage = (int)($_GET['per_page'] ?? 25);
$allowedPerPage = [25, 30, 50, 100];
if (!in_array($perPage, $allowedPerPage, true)) {
  $perPage = 25;
}

// página actual
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

// aplicar filtro por estado
$filteredRows = [];
if ($filter === 'all') {
  $filteredRows = $rows;
} else {
  foreach ($rows as $r) {
    if (strtolower((string)($r['status'] ?? 'pendiente')) === $filter) {
      $filteredRows[] = $r;
    }
  }
}

// totales
$totalFiltered = count($filteredRows);
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) $page = $totalPages;

// slice final para la tabla
$offset  = ($page - 1) * $perPage;
$viewRows = array_slice($filteredRows, $offset, $perPage);

// helper para mantener querystring
if (!function_exists('qs_link')) {
  function qs_link(array $params): string {
    $q = $_GET ?? [];
    foreach ($params as $k => $v) {
      if ($v === null) unset($q[$k]);
      else $q[$k] = $v;
    }
    return '?' . http_build_query($q);
  }
}
?>
<?php include $headPath; ?>

<style>
  /* estilos locales para tags y paneles (no rompen tu layout) */
  .tag{display:inline-flex;align-items:center;justify-content:center;padding:.15rem .55rem;border-radius:999px;font-weight:700;font-size:.75rem;border:1px solid #e5e7eb}
  .tag-ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
  .tag-err{background:#fff1f2;border-color:#fecdd3;color:#991b1b}
  .tag-pend{background:#f1f5f9;border-color:#e2e8f0;color:#334155}
  .tag-skip{background:#fffbeb;border-color:#fde68a;color:#92400e}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
</style>

<body class="bg-gray-50">
<div class="min-h-screen grid grid-cols-12">
  <!-- Sidebar -->
  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php $active='documentos'; include $sidebarPath; ?>
  </div>

  <!-- Main -->
  <div class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col">
    <?php include $topbarPath; ?>

    <main class="flex-grow max-w-7xl mx-auto p-6 space-y-6">

      <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h1 class="text-xl font-semibold flex items-center gap-2">
            <i class="fa-solid fa-file-arrow-up text-indigo-600"></i> Masivo atributos personalizados (Archivo → Buk)
          </h1>
          <div class="text-xs text-gray-500 mt-1">
            Keys enviadas: <b><?= e(ATTR_KEY_ACTUAL) ?></b> y <b><?= e(ATTR_KEY_ANTERIOR) ?></b>
            <?php if (!empty($meta['filename'])): ?>
              · Archivo: <b><?= e($meta['filename']) ?></b>
              · Cargado: <b><?= e($meta['loaded_at'] ?? '') ?></b>
              · Filas: <b><?= (int)($meta['count'] ?? 0) ?></b>
            <?php endif; ?>
          </div>
        </div>

        <!-- Filtro por estado -->
        <form method="GET" class="flex items-center gap-2">
          <label class="text-xs text-gray-500">Filtrar estado</label>
          <select name="status" onchange="this.form.submit()" class="border rounded-lg px-3 py-2 text-sm bg-white">
            <option value="all" <?= $filter==='all'?'selected':'' ?>>Todos</option>
            <option value="ok" <?= $filter==='ok'?'selected':'' ?>>OK</option>
            <option value="pendiente" <?= $filter==='pendiente'?'selected':'' ?>>Pendiente</option>
            <option value="error" <?= $filter==='error'?'selected':'' ?>>Error</option>
            <option value="skip" <?= $filter==='skip'?'selected':'' ?>>Skip</option>
          </select>
        </form>
      </div>

      <?php if ($msgErr): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl">❌ <?= e($msgErr) ?></div>
      <?php endif; ?>
      <?php if ($msgOk): ?>
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl">✅ <?= e($msgOk) ?></div>
      <?php endif; ?>

      <!-- Resumen -->
      <section class="grid md:grid-cols-5 gap-3">
        <div class="bg-white border rounded-2xl p-4">
          <div class="text-xs text-gray-500">Total</div>
          <div class="text-2xl font-semibold"><?= (int)$counts['total'] ?></div>
        </div>
        <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4">
          <div class="text-xs text-emerald-700">OK</div>
          <div class="text-2xl font-semibold text-emerald-700"><?= (int)$counts['ok'] ?></div>
        </div>
        <div class="bg-rose-50 border border-rose-200 rounded-2xl p-4">
          <div class="text-xs text-rose-700">Error</div>
          <div class="text-2xl font-semibold text-rose-700"><?= (int)$counts['error'] ?></div>
        </div>
        <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4">
          <div class="text-xs text-slate-700">Pendiente (cache)</div>
          <div class="text-2xl font-semibold text-slate-700"><?= (int)$counts['pendiente'] ?></div>
        </div>
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4">
          <div class="text-xs text-amber-800">Skip</div>
          <div class="text-2xl font-semibold text-amber-900"><?= (int)$counts['skip'] ?></div>
        </div>
      </section>

      <!-- Upload -->
      <section class="bg-white border rounded-2xl p-5 shadow-sm space-y-4">
        <div class="flex items-center justify-between">
          <div>
            <div class="font-semibold text-gray-800">1) Subir CSV</div>
            <div class="text-xs text-gray-500 mt-1">
              Requeridos: <b>Rut</b>, <b>ID en BUK</b>, <b>Sindicato Anterior</b>, <b>Sindicato Actual</b>
            </div>
          </div>
          <form method="POST">
            <input type="hidden" name="action" value="clear">
            <button class="text-xs px-3 py-2 border rounded-lg hover:bg-gray-50" type="submit">Limpiar carga</button>
          </form>
        </div>

        <form method="POST" enctype="multipart/form-data" class="grid md:grid-cols-3 gap-4">
          <input type="hidden" name="action" value="upload">
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700">Archivo CSV</label>
            <input type="file" name="csv" accept=".csv,text/csv" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm" required>
            <div class="text-xs text-gray-500 mt-2">
              Tip: Excel → Guardar como → CSV. El script detecta separador (coma/;).
            </div>
          </div>
          <div class="flex items-end">
            <button class="w-full px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg shadow text-sm" type="submit">
              <i class="fa-solid fa-upload mr-2"></i> Cargar
            </button>
          </div>
        </form>
      </section>

      <?php if ($rows && count($rows) > 0): ?>
        <!-- Envío -->
        <section class="bg-white border rounded-2xl p-5 shadow-sm space-y-4">
          <div class="flex items-center justify-between gap-3 flex-wrap">
            <div>
              <div class="font-semibold text-gray-800">2) Enviar a Buk</div>
              <div class="text-xs text-gray-500 mt-1">
                Modo: enviar todo o seleccionados. Batch evita caídas por volumen.
              </div>
            </div>
            <div class="text-xs text-gray-500">
              Vista actual: <b><?= e($filter) ?></b> · Mostrando: <b><?= count($viewRows) ?></b>
            </div>
          </div>

          <form method="POST" id="sendForm" class="grid md:grid-cols-4 gap-4 items-end">
            <input type="hidden" name="action" value="send">

            <div>
              <label class="block text-sm font-medium text-gray-700">Batch size</label>
              <input type="number" name="batch_size" value="100" min="1" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm">
              <div class="text-xs text-gray-500 mt-1">Recomendado: 50–200</div>
            </div>

            <div class="flex items-center gap-2">
              <input type="checkbox" name="skip_empty" value="1" checked>
              <label class="text-sm text-gray-700">No enviar atributos vacíos</label>
            </div>

            <div class="md:col-span-2 flex justify-end gap-2">
              <button type="submit" name="mode" value="selected"
                      class="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg shadow text-sm">
                Enviar seleccionados
              </button>
              <button type="submit" name="mode" value="all"
                      class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg shadow text-sm"
                      onclick="return confirm('¿Enviar TODAS las filas cargadas?');">
                Enviar todo
              </button>
            </div>
          </form>
        </section>
        
        <!-- Controles de tabla -->
<div class="bg-white border rounded-2xl p-4 shadow-sm flex items-center justify-between flex-wrap gap-3">
  <div class="text-xs text-gray-600">
    Mostrando <b><?= count($viewRows) ?></b> de <b><?= $totalFiltered ?></b>
    · Página <b><?= $page ?></b> de <b><?= $totalPages ?></b>
    · Estado: <b><?= e($filter) ?></b>
  </div>

  <form method="GET" class="flex items-center gap-2">
    <!-- mantener filtro de estado -->
    <input type="hidden" name="status" value="<?= e($filter) ?>">

    <label class="text-xs text-gray-500">Filas por página</label>
    <select name="per_page"
            class="border rounded-lg px-3 py-2 text-sm bg-white"
            onchange="this.form.submit()">
      <?php foreach ([25,30,50,100] as $n): ?>
        <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>>
          <?= $n ?>
        </option>
      <?php endforeach; ?>
    </select>

    <!-- al cambiar per_page volvemos a página 1 -->
    <input type="hidden" name="page" value="1">
  </form>

  <div class="flex items-center gap-2">
    <a class="px-3 py-2 text-xs border rounded-lg <?= $page <= 1 ? 'opacity-50 pointer-events-none' : '' ?>"
       href="<?= e(qs_link(['page' => $page - 1])) ?>">
      ← Anterior
    </a>

    <a class="px-3 py-2 text-xs border rounded-lg <?= $page >= $totalPages ? 'opacity-50 pointer-events-none' : '' ?>"
       href="<?= e(qs_link(['page' => $page + 1])) ?>">
      Siguiente →
    </a>
  </div>
</div>
        
        

        <!-- Tabla -->
        <section class="bg-white border rounded-2xl p-0 shadow-sm overflow-hidden">
          <div class="px-4 py-3 bg-gray-50 border-b flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" id="chkAll">
              Seleccionar todo lo visible
            </label>
            <div class="text-xs text-gray-500">
              * Los estados son cache (SESSION). No se consulta BD.
            </div>
          </div>

          <div class="overflow-auto max-h-[70vh]">
            <table class="min-w-full text-sm">
              <thead class="bg-white sticky top-0 z-10">
                <tr class="border-b">
                  <th class="p-3 text-left" style="width:70px">Sel</th>
                  <th class="p-3 text-left" style="width:140px">Rut</th>
                  <th class="p-3 text-left" style="width:110px">ID Buk</th>
                  <th class="p-3 text-left" style="width:120px">Estado</th>
                  <th class="p-3 text-left"><?= e(ATTR_KEY_ANTERIOR) ?></th>
                  <th class="p-3 text-left"><?= e(ATTR_KEY_ACTUAL) ?></th>
                  <th class="p-3 text-left" style="width:520px">Detalle (payload/errores)</th>
                </tr>
              </thead>
              <tbody class="bg-white">
                <?php foreach ($viewRows as $r): ?>
                  <?php
                    $idx  = (int)($r['idx'] ?? -1);
                    $bukId = (int)($r['buk_emp_id'] ?? 0);
                    $st = strtolower((string)($r['status'] ?? 'pendiente'));
                    $attrs = (array)($r['attrs'] ?? []);
                    $disabled = ($bukId <= 0);
                  ?>
                  <tr class="border-b">
                    <td class="p-3">
                      <input form="sendForm" type="checkbox" class="rowChk" name="row[]" value="<?= $idx ?>" <?= $disabled ? 'disabled' : '' ?>>
                    </td>
                    <td class="p-3"><?= e((string)($r['rut'] ?? '')) ?></td>
                    <td class="p-3"><?= e((string)$bukId) ?></td>
                    <td class="p-3"><?= badge_html($st) ?></td>
                    <td class="p-3"><?= e((string)($attrs[ATTR_KEY_ANTERIOR] ?? '')) ?></td>
                    <td class="p-3"><?= e((string)($attrs[ATTR_KEY_ACTUAL] ?? '')) ?></td>
                    <td class="p-3">
                      <?php if ($st === 'error'): ?>
                        <details open class="border rounded-xl p-3 bg-rose-50">
                          <summary class="cursor-pointer font-semibold text-rose-700">
                            ERROR · HTTP <?= e((string)($r['http'] ?? '')) ?> (ver payload y respuesta)
                          </summary>
                          <div class="text-xs text-gray-600 mt-2">
                            <b>updated_at:</b> <?= e((string)($r['updated_at'] ?? '')) ?>
                          </div>
                          <div class="text-xs text-gray-700 mt-2"><b>Payload enviado</b></div>
                          <pre class="mono text-xs bg-white border rounded-lg p-2 overflow-auto max-h-56 whitespace-pre-wrap"><?= e(json_encode(($r['payload'] ?? null), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>
                          <div class="text-xs text-gray-700 mt-2"><b>Respuesta Buk / Error</b></div>
                          <pre class="mono text-xs bg-white border rounded-lg p-2 overflow-auto max-h-56 whitespace-pre-wrap"><?= e((string)($r['body'] ?? ($r['error'] ?? ''))) ?></pre>
                        </details>
                      <?php elseif ($st === 'ok'): ?>
                        <details class="border rounded-xl p-3 bg-emerald-50">
                          <summary class="cursor-pointer font-semibold text-emerald-700">
                            OK · HTTP <?= e((string)($r['http'] ?? '')) ?> (ver payload)
                          </summary>
                          <div class="text-xs text-gray-600 mt-2">
                            <b>updated_at:</b> <?= e((string)($r['updated_at'] ?? '')) ?>
                          </div>
                          <div class="text-xs text-gray-700 mt-2"><b>Payload enviado</b></div>
                          <pre class="mono text-xs bg-white border rounded-lg p-2 overflow-auto max-h-56 whitespace-pre-wrap"><?= e(json_encode(($r['payload'] ?? null), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>
                          <?php if (!empty($r['body'])): ?>
                            <div class="text-xs text-gray-700 mt-2"><b>Respuesta Buk</b></div>
                            <pre class="mono text-xs bg-white border rounded-lg p-2 overflow-auto max-h-56 whitespace-pre-wrap"><?= e((string)$r['body']) ?></pre>
                          <?php endif; ?>
                        </details>
                      <?php elseif ($st === 'skip'): ?>
                        <details open class="border rounded-xl p-3 bg-amber-50">
                          <summary class="cursor-pointer font-semibold text-amber-900">
                            SKIP (no enviado)
                          </summary>
                          <div class="text-xs text-gray-700 mt-2">
                            <?= e((string)($r['error'] ?? 'Fila inválida (sin buk_id o sin attrs).')) ?>
                          </div>
                          <div class="text-xs text-gray-700 mt-2"><b>Payload que habría enviado</b></div>
                          <pre class="mono text-xs bg-white border rounded-lg p-2 overflow-auto max-h-56 whitespace-pre-wrap"><?= e(json_encode(['custom_attributes'=>($r['attrs'] ?? [])], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>
                        </details>
                      <?php else: ?>
                        <div class="text-xs text-gray-500">Pendiente (cache): aún no se ha enviado.</div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>

                <?php if (count($viewRows) === 0): ?>
                  <tr><td class="p-3 text-xs text-gray-500" colspan="7">No hay filas para el filtro seleccionado.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section class="bg-white border rounded-2xl p-5 shadow-sm space-y-2">
          <div class="font-semibold text-gray-800">Formato esperado CSV</div>
          <pre class="mono text-xs bg-gray-50 border rounded-xl p-4 overflow-auto">Rut,ID en BUK,Sindicato Anterior,Sindicato Actual
6688973-4,973,PruebaSinDAnterior,PruebaSindActual</pre>
          <div class="text-xs text-gray-500">
            Si falla, revisa el detalle: normalmente Buk devuelve <b>422</b> cuando el atributo (key) no existe o el valor no pasa validación.
          </div>
        </section>
      <?php endif; ?>

    </main>

    <?php if (is_file($footerPath)) { include $footerPath; } else { ?>
      <footer class="text-center text-xs text-gray-400 py-4 border-t bg-white">
        © <?= date('Y') ?> STI Soft — Integración ADP + Buk
      </footer>
    <?php } ?>
  </div>
</div>

<script>
(function(){
  const chkAll = document.getElementById('chkAll');
  if(!chkAll) return;

  const rowChks = () => Array.from(document.querySelectorAll('.rowChk'));

  chkAll.addEventListener('change', () => {
    rowChks().forEach(ch => { if(!ch.disabled) ch.checked = chkAll.checked; });
  });

  rowChks().forEach(ch => {
    ch.addEventListener('change', () => {
      if(!ch.checked) chkAll.checked = false;
    });
  });
})();
</script>

</body>
</html>
<?php ob_end_flush(); ?>

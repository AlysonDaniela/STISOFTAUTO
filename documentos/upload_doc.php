<?php
/**
 * upload_doc.php
 * Envío de liquidaciones PDF a empleados (Integración ADP → Buk)
 *
 * NUEVO (multi-RUT):
 * - Permite ingresar varios RUTs separados por coma / ; / saltos de línea
 *   Ej: 19523077-3,189933-1,1726662-6
 * - Búsqueda en vivo (AJAX) devuelve lista de resultados
 * - Envío: sube el MISMO PDF a todos los empleados válidos (con buk_emp_id)
 * - Muestra resultado por cada employee_id
 */

ob_start();

require_once __DIR__ . '/../includes/auth.php';
require_auth();
$user = current_user();
$csrf = csrf_token();

if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('default_charset', 'UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ================= CONFIGURACIÓN API =================
require_once __DIR__ . '/../includes/runtime_config.php';
$bukCfg = runtime_buk_config();
define('BUK_API_BASE', $bukCfg['base'] . '/employees');
define('BUK_TOKEN', $bukCfg['token']);
const LOG_DIR      = __DIR__ . '/logs_buk_docs';

if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);

// ================= DB (clsConexion) =================
$dbFile = __DIR__ . '/../conexion/db.php';
if (!is_file($dbFile)) die("No se encontró ../conexion/db.php desde " . __DIR__);
require_once $dbFile;
if (!class_exists('clsConexion')) die("db.php cargó pero no existe clsConexion.");

function DB(): clsConexion { static $db=null; if(!$db) $db=new clsConexion(); return $db; }
function esc($s){ return DB()->real_escape_string((string)$s); }

// ================= FUNCIONES AUXILIARES =================
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function rut_norm($rut): string {
  $rut = strtoupper(trim((string)$rut));
  return preg_replace('/[^0-9K]/', '', $rut);
}

function rut_display($rn): string {
  $rn = strtoupper(trim((string)$rn));
  if(strlen($rn) < 2) return $rn;
  return substr($rn,0,-1).'-'.substr($rn,-1);
}

function save_log_doc(string $prefix, array|string $data): string {
  if(!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
  $fn = LOG_DIR.'/'.$prefix.'_'.date('Ymd_His').'.json';
  file_put_contents(
    $fn,
    is_array($data)
      ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
      : $data
  );
  return $fn;
}

/**
 * Busca empleado por rut en adp_empleados y retorna:
 *  - found(bool)
 *  - rut, rut_norm, nombre
 *  - buk_emp_id (int|null)
 */
function find_employee_by_rut(string $rutInput): array {
  $rn = rut_norm($rutInput);
  if (!$rn) return ['found'=>false, 'error'=>'RUT vacío'];

  // Comparación normalizada contra la columna Rut (con puntos/guion)
  $sql = "SELECT
            Rut,
            Nombres, Apaterno, Amaterno,
            buk_emp_id
          FROM adp_empleados
          WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ','')) = '".esc($rn)."'
          LIMIT 1";

  $rows = DB()->consultar($sql) ?: [];
  if(!$rows) return ['found'=>false];

  $r = $rows[0];
  $nombre = trim((string)($r['Nombres'] ?? '').' '.(string)($r['Apaterno'] ?? '').' '.(string)($r['Amaterno'] ?? ''));
  $buk = ($r['buk_emp_id'] === null || $r['buk_emp_id'] === '') ? null : (int)$r['buk_emp_id'];

  return [
    'found'     => true,
    'rut'       => (string)($r['Rut'] ?? rut_display($rn)),
    'rut_norm'  => $rn,
    'nombre'    => $nombre,
    'buk_emp_id'=> $buk
  ];
}

/**
 * Parsea lista de RUTs.
 * Permite separadores: coma, punto y coma, saltos de línea.
 */
function parse_ruts_list(string $input): array {
  $parts = preg_split('/[,\n;\r]+/', $input);
  $out = [];
  foreach ($parts as $p) {
    $p = trim((string)$p);
    if ($p === '') continue;
    $out[] = $p;
  }
  // quitar duplicados manteniendo orden
  $out = array_values(array_unique($out));
  return $out;
}

/**
 * Busca múltiples RUTs y devuelve lista + resumen
 */
function find_employees_by_ruts(string $rutsInput): array {
  $ruts = parse_ruts_list($rutsInput);

  $items = [];
  $count_found = 0;
  $count_with_buk = 0;
  $count_without_buk = 0;
  $count_not_found = 0;

  foreach ($ruts as $rut) {
    $d = find_employee_by_rut($rut);
    $d['rut_input'] = $rut;

    if (!empty($d['found'])) {
      $count_found++;
      if (!empty($d['buk_emp_id'])) {
        $count_with_buk++;
      } else {
        $count_without_buk++;
        $d['warning'] = 'Encontrado, pero no tiene buk_emp_id (no se puede enviar a Buk).';
      }
    } else {
      $count_not_found++;
    }

    $items[] = $d;
  }

  return [
    'input' => $rutsInput,
    'items' => $items,
    'summary' => [
      'total'       => count($ruts),
      'found'       => $count_found,
      'with_buk'    => $count_with_buk,
      'without_buk' => $count_without_buk,
      'not_found'   => $count_not_found
    ]
  ];
}

// ===================== AJAX (Búsqueda en vivo MULTI-RUT) =====================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'rut') {
  header('Content-Type: application/json; charset=utf-8');

  $rut = (string)($_GET['q'] ?? '');
  $data = find_employees_by_ruts($rut);

  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

// ================= ENVÍO DEL DOCUMENTO (MULTI-RUT) =================
$response_data = null;   // string (json bonito)
$error_msg = null;
$http_code = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
  if (!csrf_validate($_POST['csrf_token'] ?? '')) {
    $error_msg = "La sesión de seguridad expiró. Recarga la pantalla e inténtalo nuevamente.";
  } else {
    $employee_ids_raw = trim((string)($_POST['employee_ids'] ?? ''));
    $rut_input        = trim((string)($_POST['rut'] ?? ''));

    $visible  = isset($_POST['visible']) ? 'true' : 'false';
    $signable = isset($_POST['signable']) ? 'true' : 'false';

    // Lista desde hidden (coma)
    $employee_ids = array_filter(array_map('intval', preg_split('/\s*,\s*/', $employee_ids_raw)));
    $employee_ids = array_values(array_unique($employee_ids));

    // Validación: debe haber al menos 1 employee_id válido
    if (count($employee_ids) === 0) {
      // fallback: revalidar por rut_input (por si front manda algo malo)
      $foundList = find_employees_by_ruts($rut_input);
      $employee_ids = [];
      foreach ($foundList['items'] as $it) {
        if (!empty($it['found']) && !empty($it['buk_emp_id'])) {
          $employee_ids[] = (int)$it['buk_emp_id'];
        }
      }
      $employee_ids = array_values(array_unique($employee_ids));

      if (count($employee_ids) === 0) {
        $error_msg = "No se puede enviar: no se encontró ningún empleado válido con buk_emp_id.";
      }
    }

    // Validación: debe haber PDF
    if (!$error_msg) {
      if (empty($_FILES['file']['tmp_name']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error_msg = "Debe seleccionar un archivo PDF para enviar.";
      }
    }

    if (!$error_msg) {
      $file_tmp  = $_FILES['file']['tmp_name'];
      $file_name = basename($_FILES['file']['name']);

      $results = [];

      foreach ($employee_ids as $employee_id) {
        // Construir URL con parámetros
        $url = BUK_API_BASE . "/{$employee_id}/docs";
        $query_params = http_build_query([
          "visible" => $visible,
          "signable_by_employee" => $signable,
          "overwrite" => "false",
          "start_signature_workflow" => "false"
        ]);
        $url .= "?$query_params";

        // Preparar archivo como multipart
        $post_fields = [
          'file' => new CURLFile($file_tmp, 'application/pdf', $file_name)
        ];

        // Ejecutar cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
          CURLOPT_URL => $url,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_HTTPHEADER => [
            "auth_token: " . BUK_TOKEN,
            "Accept: application/json"
          ],
          CURLOPT_POST => true,
          CURLOPT_POSTFIELDS => $post_fields,
          CURLOPT_TIMEOUT => 60
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        $one = [
          'employee_id' => $employee_id,
          'http_code'   => $code,
          'curl_error'  => $curl_error ?: null,
          'body'        => $body
        ];

        $results[] = $one;

        save_log_doc("response_{$employee_id}", $one);
      }

      // Respuesta multi (bonita)
      $response_data = json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
      // 207 Multi-Status (solo informativo para UI; no es de Buk)
      $http_code = 207;
    }
  }
}
?>

<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="bg-gray-50">
<div class="min-h-screen grid grid-cols-12">
  <!-- Sidebar -->
  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php $active='documentos'; include __DIR__ . '/../partials/sidebar.php'; ?>
  </div>

  <!-- Main -->
  <div class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>

    <main class="flex-grow max-w-5xl mx-auto p-6 space-y-6">
      <section class="space-y-3">

        <div class="flex items-center justify-between">
          <h1 class="text-xl font-semibold flex items-center gap-2">
            <i class="fa-solid fa-paperclip text-emerald-600"></i> Envío de Liquidación a Empleado
          </h1>
          <div class="text-xs text-gray-500">ADP → Buk · Subida directa (multi-RUT)</div>
        </div>

        <div class="bg-white border rounded-2xl p-5 shadow-sm space-y-4">

          <!-- BUSCADOR POR RUT (MULTI) -->
          <div class="grid md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">RUT(s) del empleado</label>
              <input
                id="rutInput"
                type="text"
                class="mt-1 w-full border rounded-lg px-3 py-2 text-sm"
                placeholder="Ej: 19523077-3,189933-1,1726662-6"
                autocomplete="off"
              >
              <div class="text-xs text-gray-500 mt-1">
                Puedes separar con coma, punto y coma o saltos de línea. Se busca automáticamente mientras escribes.
              </div>
            </div>

            <div class="border rounded-xl p-3 bg-gray-50">
              <div class="text-xs text-gray-500">Resultado búsqueda</div>
              <div id="empResult" class="text-sm font-semibold text-gray-800 mt-1">—</div>
              <div id="empMeta" class="text-xs text-gray-600 mt-1" style="white-space:pre-wrap;">—</div>
            </div>
          </div>

          <hr class="my-2">

          <!-- FORM ENVÍO (requiere al menos 1 empleado válido + PDF) -->
          <form method="POST" enctype="multipart/form-data" class="grid md:grid-cols-2 gap-4" id="sendForm">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="employee_ids" id="employeeIds" value="">
            <input type="hidden" name="rut" id="rutHidden" value="">

            <div>
              <label class="block text-sm font-medium text-gray-700">Archivo PDF</label>
              <input type="file" name="file" accept="application/pdf" class="mt-1 w-full text-sm" id="pdfInput">
              <div class="text-xs text-gray-500 mt-1">Se envía el mismo PDF a todos los RUTs válidos cuando presionas “Enviar”.</div>
            </div>

            <div class="flex items-end">
              <div class="w-full">
                <label class="block text-sm font-medium text-gray-700">Estado</label>
                <div id="sendState" class="mt-1 text-sm text-gray-700">Busca uno o más RUTs para habilitar el envío.</div>
              </div>
            </div>

            <div class="col-span-2 flex items-center gap-6 mt-2">
              <label class="text-sm flex items-center gap-2">
                <input type="checkbox" name="visible" checked> Visible al empleado
              </label>
              <label class="text-sm flex items-center gap-2">
                <input type="checkbox" name="signable"> Requiere firma del empleado
              </label>
            </div>

            <div class="col-span-2 flex justify-end mt-2">
              <button
                type="submit"
                id="sendBtn"
                class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg shadow text-sm disabled:opacity-50 disabled:cursor-not-allowed"
                disabled
              >
                <i class="fa-solid fa-paper-plane-top mr-2"></i> Enviar Documento
              </button>
            </div>
          </form>
        </div>

        <?php if ($error_msg): ?>
          <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl">
            ❌ <?= e($error_msg) ?>
          </div>

        <?php elseif ($response_data): ?>
          <?php
            // Detectar si hubo al menos un 201 dentro del multi
            $multi = json_decode($response_data, true);
            $okCount = 0;
            $failCount = 0;
            if (is_array($multi)) {
              foreach ($multi as $one) {
                if (($one['http_code'] ?? null) == 201) $okCount++;
                else $failCount++;
              }
            }
          ?>

          <?php if ($okCount > 0 && $failCount === 0): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl">
              ✅ Documentos subidos correctamente: <?= e($okCount) ?> (sin errores)
            </div>
          <?php elseif ($okCount > 0 && $failCount > 0): ?>
            <div class="bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded-xl">
              ⚠️ Subidos: <?= e($okCount) ?> · Con error: <?= e($failCount) ?> (revisar detalle)
            </div>
          <?php else: ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl">
              ❌ Ningún envío exitoso (revisar detalle)
            </div>
          <?php endif; ?>

          <details open class="bg-white border rounded-2xl shadow-sm p-4">
            <summary class="font-semibold cursor-pointer mb-2 flex items-center gap-2">
              <i class="fa-solid fa-arrows-rotate text-gray-500"></i> Respuesta de la API (por employee_id)
            </summary>
            <pre class="text-xs bg-gray-50 rounded-lg p-3 overflow-y-auto max-h-96 whitespace-pre-wrap"><?= e($response_data) ?></pre>
          </details>
        <?php endif; ?>

      </section>
    </main>

    <!-- Footer inline -->
    <footer class="text-center text-xs text-gray-400 py-4 border-t bg-white">
      © <?= date('Y') ?> STI Soft — Integración ADP + Buk
    </footer>
  </div>
</div>

<script>
(function(){
  const rutInput    = document.getElementById('rutInput');
  const rutHidden   = document.getElementById('rutHidden');
  const empResult   = document.getElementById('empResult');
  const empMeta     = document.getElementById('empMeta');
  const employeeIds = document.getElementById('employeeIds');

  const sendBtn    = document.getElementById('sendBtn');
  const pdfInput   = document.getElementById('pdfInput');
  const sendState  = document.getElementById('sendState');

  let t = null;
  let lastQuery = '';
  let okEmployee = false;

  function setState() {
    const hasPdf = pdfInput && pdfInput.files && pdfInput.files.length > 0;

    if (!okEmployee) {
      sendBtn.disabled = true;
      sendState.textContent = 'Ingresa 1 o más RUTs válidos (con buk_emp_id) para habilitar el envío.';
      return;
    }
    if (!hasPdf) {
      sendBtn.disabled = true;
      sendState.textContent = 'Empleados OK. Falta seleccionar el PDF.';
      return;
    }
    sendBtn.disabled = false;
    sendState.textContent = 'Listo para enviar a todos los RUTs válidos.';
  }

  async function lookupRut(q) {
    try {
      const res = await fetch(`?ajax=rut&q=${encodeURIComponent(q)}`, { credentials: 'same-origin' });
      const data = await res.json();

      rutHidden.value = q;

      if (!data || !data.items || data.items.length === 0) {
        empResult.textContent = '—';
        empMeta.textContent = '—';
        employeeIds.value = '';
        okEmployee = false;
        setState();
        return;
      }

      const valid = [];
      const lines = [];

      for (const it of data.items) {
        if (!it || !it.found) {
          lines.push(`❌ ${it?.rut_input || ''}: no encontrado`);
          continue;
        }

        const nombre = (it.nombre || '').trim() || 'Empleado';
        const rutShow = it.rut || it.rut_input;

        if (!it.buk_emp_id) {
          lines.push(`⚠️ ${nombre} (${rutShow}): sin buk_emp_id`);
          continue;
        }

        lines.push(`✅ ${nombre} (${rutShow}) → buk_emp_id ${it.buk_emp_id}`);
        valid.push(String(it.buk_emp_id));
      }

      empResult.textContent = `Resultados: ${data.summary.with_buk}/${data.summary.total} listos para enviar`;
      empMeta.textContent = lines.join('\n');

      employeeIds.value = valid.join(',');
      okEmployee = valid.length > 0;

      setState();

    } catch (err) {
      empResult.textContent = 'Error buscando';
      empMeta.textContent = String(err);
      employeeIds.value = '';
      okEmployee = false;
      setState();
    }
  }

  rutInput.addEventListener('input', () => {
    const q = rutInput.value.trim();
    rutHidden.value = q;

    clearTimeout(t);
    t = setTimeout(() => {
      const q2 = rutInput.value.trim();

      // Evitar pegarle a la BD con 1-2 chars
      if (q2.length < 3) {
        empResult.textContent = '—';
        empMeta.textContent = '—';
        employeeIds.value = '';
        okEmployee = false;
        setState();
        return;
      }
      if (q2 === lastQuery) return;

      lastQuery = q2;
      lookupRut(q2);
    }, 250);
  });

  pdfInput.addEventListener('change', setState);
  setState();
})();
</script>

</body>
</html>
<?php ob_end_flush(); ?>

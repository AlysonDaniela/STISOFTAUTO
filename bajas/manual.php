<?php
/**
 * termino_contrato.php
 * Terminar contrato en Buk: PATCH /jobs/{buk_job_id}/terminate
 * - Buscar por RUT en vivo (AJAX) contra adp_empleados
 * - Usa buk_job_id de la tabla adp_empleados
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

/* ============== LAYOUT PARTIALS ============== */
$headPath    = __DIR__ . '/../partials/head.php';
$sidebarPath = __DIR__ . '/../partials/sidebar.php';
$topbarPath  = __DIR__ . '/../partials/topbar.php';
$footerPath  = __DIR__ . '/../partials/footer.php';

/* ============== DB ============== */
require_once __DIR__ . '/../conexion/db.php';
require_once __DIR__ . '/../includes/runtime_config.php';
if (!class_exists('clsConexion')) die("No existe clsConexion (revisa ../conexion/db.php).");

function DB(): clsConexion { static $db=null; if(!$db) $db=new clsConexion(); return $db; }
function esc($s){ return DB()->real_escape_string((string)$s); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function rut_norm($rut): string {
  $rut = strtoupper(trim((string)$rut));
  return preg_replace('/[^0-9K]/','', $rut);
}
function rut_pretty($rn): string {
  $rn = strtoupper(trim((string)$rn));
  if (strlen($rn) < 2) return $rn;
  return substr($rn,0,-1).'-'.substr($rn,-1);
}

/* ============== BUK API ============== */
$bukCfg = runtime_buk_config();
define('BUK_API_BASE', $bukCfg['base']);
define('BUK_TOKEN', $bukCfg['token']);

const LOG_DIR = __DIR__ . '/logs_buk_terminate';
if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);

function save_log(string $prefix, array $data): void {
  if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
  $fn = LOG_DIR.'/'.$prefix.'_'.date('Ymd_His').'.json';
  file_put_contents($fn, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

/* ============== Reasons ============== */
$REASONS = [
  "mutuo_acuerdo","renuncia","muerte","vencimiento_plazo","fin_servicio","caso_fortuito",
  "falta_probidad","acoso_sexual","vias_de_hecho","injurias","conducta_inmoral","acoso_laboral",
  "negociaciones_prohibidas","no_concurrencia","abandonar_trabajo","faltas_seguridad",
  "perjuicio_material","incumplimiento","necesidades_empresa","desahucio_gerente"
];

/* ============== AJAX: búsqueda por RUT (buk_job_id) ============== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'rut') {
  header('Content-Type: application/json; charset=utf-8');

  $q = rut_norm($_GET['q'] ?? '');
  if ($q === '' || strlen($q) < 3) {
    echo json_encode(['ok'=>true,'items'=>[]], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $sql = "
    SELECT
      Rut,
      Nombres, Apaterno, Amaterno,
      buk_job_id
    FROM adp_empleados
    WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ','')) LIKE '".esc($q)."%'
    ORDER BY Rut
    LIMIT 10
  ";
  $rows = DB()->consultar($sql) ?: [];

  $items = [];
  foreach ($rows as $r) {
    $rn = rut_norm($r['Rut'] ?? '');
    $nombre = trim((string)($r['Nombres']??'').' '.(string)($r['Apaterno']??'').' '.(string)($r['Amaterno']??''));
    $items[] = [
      'rut_norm'   => $rn,
      'rut'        => rut_pretty($rn),
      'nombre'     => $nombre,
      'buk_job_id' => ($r['buk_job_id'] === null || $r['buk_job_id'] === '') ? null : (int)$r['buk_job_id'],
    ];
  }

  echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ============== POST: terminate ============== */
$msgOk = null; $msgErr = null; $respBody = null; $http = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate($_POST['csrf_token'] ?? '')) {
    $msgErr = "La sesión de seguridad expiró. Recarga la pantalla e inténtalo nuevamente.";
  } else {
    $rut = rut_norm($_POST['rut'] ?? '');
    $buk_job_id = (int)($_POST['buk_job_id'] ?? 0);

    $start = trim((string)($_POST['start_date'] ?? ''));
    $end   = trim((string)($_POST['end_date'] ?? ''));
    $reason = trim((string)($_POST['termination_reason'] ?? ''));

    if (!$rut) {
      $msgErr = "Debes ingresar RUT.";
    } elseif ($buk_job_id <= 0) {
      $msgErr = "Empleado no encontrado o sin buk_job_id.";
    } elseif (!$start || !$end) {
      $msgErr = "Debes ingresar start_date y end_date.";
    } elseif (!in_array($reason, $REASONS, true)) {
      $msgErr = "Razón inválida.";
    } else {
      $url = BUK_API_BASE . "/jobs/{$buk_job_id}/terminate";
      $payload = [
        "start_date" => $start,
        "end_date" => $end,
        "termination_reason" => $reason
      ];
      $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

      $ch = curl_init();
      curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "PATCH",
        CURLOPT_HTTPHEADER => [
          "Content-Type: application/json",
          "Accept: application/json",
          "auth_token: " . BUK_TOKEN
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => 60
      ]);

      $respBody = curl_exec($ch);
      $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlErr = curl_error($ch);
      curl_close($ch);

      save_log("terminate_job{$buk_job_id}", [
        'rut' => $rut,
        'buk_job_id' => $buk_job_id,
        'payload' => $payload,
        'http' => $http,
        'curl_error' => $curlErr,
        'response' => $respBody
      ]);

      if ($curlErr) $msgErr = "Error cURL: $curlErr";
      elseif (!$respBody) $msgErr = "Sin respuesta del servidor.";
      elseif ($http >= 200 && $http < 300) $msgOk = "Contrato terminado correctamente (HTTP $http)";
      else $msgErr = "Buk respondió error (HTTP $http)";
    }
  }
}
?>
<?php include $headPath; ?>
<body class="bg-gray-50">
<div class="min-h-screen grid grid-cols-12">
  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php $active='termino_contrato'; include $sidebarPath; ?>
  </div>

  <div class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col">
    <?php include $topbarPath; ?>

    <main class="flex-grow max-w-5xl mx-auto p-6 space-y-6">
      <section class="space-y-3">
        <div class="flex items-center justify-between">
          <h1 class="text-xl font-semibold flex items-center gap-2">
            <i class="fa-solid fa-user-slash text-rose-600"></i> Término de Contrato (Buk)
          </h1>
          <div class="text-xs text-gray-500">RUT → buk_job_id → /terminate</div>
        </div>

        <div class="bg-white border rounded-2xl p-5 shadow-sm space-y-4">
          <form method="POST" class="grid md:grid-cols-2 gap-4" id="formTermino">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-700">RUT (escribe y selecciona):</label>
              <input type="text" name="rut" id="rutInput"
                     class="mt-1 w-full border rounded-lg px-3 py-2 text-sm"
                     autocomplete="off" placeholder="Ej: 19523077-3">

              <div id="suggestBox" class="mt-2 border rounded-lg bg-white shadow-sm hidden"></div>

              <div class="mt-2 text-sm">
                <span class="text-gray-500">Empleado:</span>
                <span id="empLabel" class="font-semibold text-gray-800">—</span>
              </div>

              <div class="mt-1 text-sm">
                <span class="text-gray-500">buk_job_id:</span>
                <span id="jobLabel" class="font-semibold">—</span>
              </div>

              <input type="hidden" name="buk_job_id" id="bukJobId" value="">
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Start date:</label>
              <input type="date" name="start_date"
                     class="mt-1 w-full border rounded-lg px-3 py-2 text-sm" required>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">End date:</label>
              <input type="date" name="end_date"
                     class="mt-1 w-full border rounded-lg px-3 py-2 text-sm" required>
            </div>

            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-700">Razón término:</label>
              <select name="termination_reason" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm" required>
                <?php foreach ($REASONS as $r): ?>
                  <option value="<?= e($r) ?>"><?= e($r) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="md:col-span-2 flex justify-end mt-2">
              <button type="submit" id="sendBtn"
                      class="px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-lg shadow text-sm disabled:opacity-50"
                      disabled>
                Enviar término a Buk
              </button>
            </div>

          </form>
        </div>

        <?php if ($msgErr): ?>
          <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl">
            ❌ <?= e($msgErr) ?>
          </div>
        <?php elseif ($msgOk): ?>
          <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl">
            ✅ <?= e($msgOk) ?>
          </div>
        <?php endif; ?>

        <?php if ($respBody): ?>
          <details open class="bg-white border rounded-2xl shadow-sm p-4">
            <summary class="font-semibold cursor-pointer mb-2 flex items-center gap-2">
              <i class="fa-solid fa-arrows-rotate text-gray-500"></i> Respuesta de la API (HTTP <?= e((string)$http) ?>)
            </summary>
            <pre class="text-xs bg-gray-50 rounded-lg p-3 overflow-y-auto max-h-96 whitespace-pre-wrap"><?= e($respBody) ?></pre>
          </details>
        <?php endif; ?>

      </section>
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
  const rutInput = document.getElementById('rutInput');
  const suggestBox = document.getElementById('suggestBox');
  const empLabel = document.getElementById('empLabel');
  const jobLabel = document.getElementById('jobLabel');
  const bukJobId = document.getElementById('bukJobId');
  const sendBtn = document.getElementById('sendBtn');

  let t=null;
  let lastItems=[];

  function normRut(s){ return (s||'').toUpperCase().replace(/[^0-9K]/g,''); }
  function hide(){ suggestBox.classList.add('hidden'); }

  function setSelected(it){
    rutInput.value = it.rut; // pretty
    empLabel.textContent = it.nombre || '—';
    jobLabel.textContent = (it.buk_job_id !== null) ? it.buk_job_id : 'NO ENCONTRADO';
    bukJobId.value = (it.buk_job_id !== null) ? it.buk_job_id : '';
    sendBtn.disabled = !(it.buk_job_id && it.buk_job_id > 0);
    hide();
  }

  function show(items){
    lastItems = items || [];
    if (!items || items.length === 0){
      suggestBox.innerHTML = '';
      suggestBox.classList.add('hidden');
      empLabel.textContent = '—';
      jobLabel.textContent = '—';
      bukJobId.value = '';
      sendBtn.disabled = true;
      return;
    }

    suggestBox.innerHTML = items.map((it, idx) => {
      const badge = (it.buk_job_id !== null)
        ? `<span style="font-size:12px;padding:2px 8px;border-radius:999px;border:1px solid #d1fae5;background:#ecfdf5;color:#065f46">job ${it.buk_job_id}</span>`
        : `<span style="font-size:12px;padding:2px 8px;border-radius:999px;border:1px solid #fee2e2;background:#fef2f2;color:#991b1b">sin job</span>`;

      return `
        <div data-idx="${idx}" style="padding:10px 12px;cursor:pointer;border-top:1px solid #f1f5f9">
          <div style="display:flex;justify-content:space-between;gap:10px;align-items:center">
            <div>
              <div style="font-weight:800;color:#111827">${it.rut}</div>
              <div style="font-size:12px;color:#6b7280">${(it.nombre||'').replace(/</g,'&lt;')}</div>
            </div>
            ${badge}
          </div>
        </div>
      `;
    }).join('');

    suggestBox.classList.remove('hidden');

    Array.from(suggestBox.querySelectorAll('[data-idx]')).forEach(el=>{
      el.addEventListener('click', ()=>{
        const idx = parseInt(el.getAttribute('data-idx'),10);
        const it = lastItems[idx];
        if (it) setSelected(it);
      });
    });
  }

  async function searchRut(){
    const q = normRut(rutInput.value);
    if (q.length < 3){ show([]); return; }

    const url = `<?= e($_SERVER['PHP_SELF']) ?>?ajax=rut&q=${encodeURIComponent(q)}`;
    const res = await fetch(url, { headers: { 'Accept':'application/json' }});
    const data = await res.json();
    const items = (data && data.items) ? data.items : [];
    show(items);

    // auto-seleccionar exacto
    const exact = items.find(x => normRut(x.rut) === q || (x.rut_norm && x.rut_norm === q));
    if (exact) setSelected(exact);
  }

  rutInput.addEventListener('input', ()=>{
    clearTimeout(t);
    t = setTimeout(searchRut, 220);
  });

  document.addEventListener('click', (ev)=>{
    if (!suggestBox.contains(ev.target) && ev.target !== rutInput) hide();
  });
})();
</script>

</body>
</html>
<?php ob_end_flush(); ?>

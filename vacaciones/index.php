<?php
/**
 * vacaciones.php
 * Setea "Saldo Vacaciones" (custom_attributes) en Buk, buscando empleado por RUT en adp_empleados.
 * - Búsqueda en vivo por RUT (AJAX) independiente del envío.
 * - Envío PATCH a Buk solo cuando hay buk_emp_id + saldo numérico.
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

/* ============ CONFIG ============ */
require_once __DIR__ . '/../includes/runtime_config.php';
$bukCfg = runtime_buk_config();
define('BUK_API_BASE', $bukCfg['base'] . '/employees');
define('BUK_TOKEN', $bukCfg['token']);
const ATTR_KEY       = 'Saldo Vacaciones'; 
const LOG_DIR        = __DIR__ . '/logs_buk_vacaciones';

$headPath    = __DIR__ . '/../partials/head.php';
$sidebarPath = __DIR__ . '/../partials/sidebar.php';
$topbarPath  = __DIR__ . '/../partials/topbar.php';
$footerPath  = __DIR__ . '/../partials/footer.php';

if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);

/* ============ DB ============ */
require_once __DIR__ . '/../conexion/db.php';
if (!class_exists('clsConexion')) die("No existe clsConexion (revisa ../conexion/db.php).");

function DB(): clsConexion { static $db=null; if(!$db) $db=new clsConexion(); return $db; }
function esc($s){ return DB()->real_escape_string((string)$s); }
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
  file_put_contents($fn, is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) : (string)$data);
  return $fn;
}

function find_employee_by_rut(string $rutInput): ?array {
  $rn = rut_norm($rutInput);
  if(!$rn) return null;

  $sql = "SELECT
            UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ','')) AS rut_norm,
            Rut,
            Nombres, Apaterno, Amaterno,
            buk_emp_id
          FROM adp_empleados
          WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ','')) = '".esc($rn)."'
          LIMIT 1";
  $r = DB()->consultar($sql);
  if(!$r) return null;

  $row = $r[0];
  $nombre = trim((string)($row['Nombres']??'').' '.(string)($row['Apaterno']??'').' '.(string)($row['Amaterno']??''));
  return [
    'rut_norm'   => rut_norm($row['rut_norm'] ?? $rn),
    'rut'        => (string)($row['Rut'] ?? ''),
    'nombre'     => $nombre,
    'buk_emp_id' => ($row['buk_emp_id'] === null || $row['buk_emp_id'] === '') ? null : (int)$row['buk_emp_id'],
  ];
}

function buk_patch_vacaciones(int $employee_id, $saldo): array {
  $url = BUK_API_BASE . "/{$employee_id}";

  // JSON EXACTO que pide Buk
  $payload = [
    "custom_attributes" => [
      ATTR_KEY => (is_numeric($saldo) ? 0 + $saldo : $saldo)
    ]
  ];
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
    CURLOPT_TIMEOUT => 60
  ]);

  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($err) return ['ok'=>false,'http'=>$http,'error'=>"cURL: $err",'body'=>$resp,'sent'=>$payload];
  return ['ok'=>($http>=200 && $http<300), 'http'=>$http, 'error'=>null, 'body'=>$resp, 'sent'=>$payload];
}

/* ============ AJAX: búsqueda en vivo por RUT ============ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'rut') {
  header('Content-Type: application/json; charset=utf-8');
  $rut = (string)($_GET['rut'] ?? '');
  $emp = find_employee_by_rut($rut);
  if(!$emp){
    echo json_encode(['ok'=>false,'found'=>false,'msg'=>'No encontrado'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if(!$emp['buk_emp_id']){
    echo json_encode([
      'ok'=>true,
      'found'=>true,
      'has_buk'=>false,
      'rut'=>rut_display($emp['rut_norm']),
      'nombre'=>$emp['nombre'],
      'buk_emp_id'=>null,
      'msg'=>'Encontrado, pero SIN buk_emp_id'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode([
    'ok'=>true,
    'found'=>true,
    'has_buk'=>true,
    'rut'=>rut_display($emp['rut_norm']),
    'nombre'=>$emp['nombre'],
    'buk_emp_id'=>$emp['buk_emp_id'],
    'msg'=>'OK'
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ============ POST: envío PATCH ============ */
$msgOk = null;
$msgErr = null;
$response_data = null;
$http_code = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
  if (!csrf_validate($_POST['csrf_token'] ?? '')) {
    $msgErr = "La sesión de seguridad expiró. Recarga la pantalla e inténtalo nuevamente.";
  } else {
  $rutInput = trim((string)($_POST['rut'] ?? ''));
  $saldoRaw = trim((string)($_POST['saldo'] ?? ''));

  // Validaciones
  if ($rutInput === '') {
    $msgErr = "Debes ingresar un RUT.";
  } elseif ($saldoRaw === '' || !is_numeric($saldoRaw)) {
    $msgErr = "Debes ingresar un saldo numérico válido.";
  } else {
    $emp = find_employee_by_rut($rutInput);
    if (!$emp) {
      $msgErr = "RUT no encontrado en BD.";
    } elseif (!$emp['buk_emp_id']) {
      $msgErr = "Empleado encontrado, pero NO tiene buk_emp_id (no se puede enviar).";
    } else {
      $res = buk_patch_vacaciones((int)$emp['buk_emp_id'], $saldoRaw);
      $http_code = (int)($res['http'] ?? 0);
      $response_data = $res['body'] ?? null;

      save_log("patch_{$emp['buk_emp_id']}", [
        'rut' => $emp['rut_norm'],
        'nombre' => $emp['nombre'],
        'buk_emp_id' => $emp['buk_emp_id'],
        'payload' => $res['sent'] ?? null,
        'http_code' => $http_code,
        'body' => $response_data
      ]);

      if (!empty($res['ok'])) {
        $msgOk = "✅ Saldo actualizado en Buk (HTTP {$http_code}).";
      } else {
        $msgErr = "⚠️ No se pudo actualizar en Buk (HTTP {$http_code}). Revisa la respuesta.";
      }
    }
  }
  }
}
?>
<?php include $headPath; ?>
<body class="bg-gray-50">

<div class="min-h-screen grid grid-cols-12">
  <!-- Sidebar -->
  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php $active='documentos'; include $sidebarPath; ?>
  </div>

  <!-- Main -->
  <div class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col">
    <?php include $topbarPath; ?>

    <main class="flex-grow max-w-5xl mx-auto p-6 space-y-6">
      <style>
        .vac-hero{
          background:linear-gradient(135deg,#0f3d91 0%,#1f4c97 45%,#4f7fc7 100%);
          color:#fff;
          border-radius:28px;
          padding:24px;
          border:1px solid rgba(255,255,255,.18);
          box-shadow:0 18px 40px rgba(31,76,151,.18);
        }
        .vac-hero-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:16px;align-items:start}
        .vac-kicker{font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:#dbeafe;font-weight:800}
        .vac-title{font-size:32px;font-weight:950;letter-spacing:-.03em;margin-top:10px;line-height:1.05}
        .vac-text{font-size:14px;color:#e8f1ff;line-height:1.55;margin-top:10px;max-width:720px}
        .vac-hero-box{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.16);border-radius:18px;padding:14px}
        .vac-card{background:#fff;border:1px solid #e5eaf5;border-radius:22px;box-shadow:0 8px 24px rgba(15,23,42,.04)}
        .vac-card-pad{padding:20px}
        .vac-input{
          margin-top:8px;width:100%;height:48px;border:1px solid #d7e0f0;border-radius:14px;
          padding:0 14px;font-size:14px;background:#fff;transition:border-color .15s ease, box-shadow .15s ease
        }
        .vac-input:focus{
          outline:none;border-color:#4f7fc7;box-shadow:0 0 0 4px rgba(79,127,199,.14)
        }
        .vac-label{display:block;font-size:13px;font-weight:800;color:#334155}
        .vac-soft{
          background:linear-gradient(180deg,#f8fbff 0%,#f3f7fd 100%);
          border:1px solid #dbe5f3;border-radius:18px;padding:16px
        }
        .vac-employee{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
        .vac-employee-box{background:#fff;border:1px solid #e5eaf5;border-radius:16px;padding:14px}
        .vac-mini{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.06em}
        .vac-value{font-size:15px;font-weight:800;color:#1e293b;margin-top:6px;line-height:1.35;word-break:break-word}
        .vac-actions{display:flex;justify-content:flex-end;margin-top:4px}
        .vac-submit{
          min-height:50px;padding:0 20px;border:0;border-radius:16px;cursor:pointer;
          background:linear-gradient(135deg,#0f3d91 0%,#1f4c97 100%);
          color:#fff;font-size:14px;font-weight:900;box-shadow:0 14px 28px rgba(31,76,151,.22)
        }
        .vac-submit:disabled{opacity:.5;cursor:not-allowed;box-shadow:none}
        .vac-status{
          display:flex;align-items:center;justify-content:space-between;gap:12px;
          padding:12px 14px;border:1px solid #dbe5f3;border-radius:16px;background:#fff
        }
        .vac-pill{
          display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;
          background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;font-size:12px;font-weight:800
        }
        .vac-response{background:#fff;border:1px solid #e5eaf5;border-radius:22px;box-shadow:0 8px 24px rgba(15,23,42,.04);padding:18px}
        @media (max-width: 960px){
          .vac-hero-grid,.vac-employee{grid-template-columns:1fr}
        }
      </style>

      <section class="space-y-3">
        <div class="vac-hero">
          <div class="vac-hero-grid">
            <div>
              <div class="vac-kicker">Vacaciones</div>
              <div class="vac-title">Actualizar saldo de vacaciones desde ADP hacia Buk</div>
              <div class="vac-text">Busca al colaborador por RUT, valida si tiene vínculo Buk y envía el saldo al atributo personalizado correcto. La idea es que esta pantalla sea rápida, clara y segura antes de hacer el PATCH.</div>
            </div>
            <div class="space-y-3">
              <div class="vac-hero-box">
                <div class="text-xs uppercase tracking-wide text-blue-100 font-bold">Atributo Buk</div>
                <div class="text-2xl font-black mt-2"><?= e(ATTR_KEY) ?></div>
              </div>
              <div class="vac-hero-box">
                <div class="text-xs uppercase tracking-wide text-blue-100 font-bold">Flujo recomendado</div>
                <div class="text-sm mt-2 text-blue-50 leading-6">1. Buscar RUT. 2. Confirmar `buk_emp_id`. 3. Ingresar saldo. 4. Enviar a Buk.</div>
              </div>
            </div>
          </div>
        </div>

        <?php if ($msgErr): ?>
          <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-2xl shadow-sm">❌ <?= e($msgErr) ?></div>
        <?php endif; ?>
        <?php if ($msgOk): ?>
          <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-2xl shadow-sm"><?= e($msgOk) ?></div>
        <?php endif; ?>

        <div class="vac-card vac-card-pad space-y-5">
          <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
              <div class="text-xl font-black text-slate-900">Formulario de actualización</div>
              <div class="text-sm text-slate-500 mt-1">El envío solo se habilita cuando el RUT existe en BD y tiene `buk_emp_id`.</div>
            </div>
            <span class="vac-pill"><i class="fa-solid fa-shield-halved"></i> Validación previa automática</span>
          </div>

          <form method="POST" class="grid md:grid-cols-2 gap-4" id="vacForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="buk_emp_id" id="buk_emp_id" value="">

            <div>
              <label class="vac-label">RUT del colaborador</label>
              <input
                type="text"
                name="rut"
                id="rut"
                class="vac-input"
                placeholder="Ej: 19.523.077-3"
                autocomplete="off"
                required
              >
              <div class="text-xs mt-3 font-medium" id="rutStatus" style="color:#6b7280">Escribe el RUT para buscar…</div>
            </div>

            <div>
              <label class="vac-label">Saldo de vacaciones</label>
              <input
                type="number"
                name="saldo"
                id="saldo"
                class="vac-input"
                placeholder="Ej: 11111"
                step="1"
                required
              >
              <div class="text-xs mt-3 text-slate-500">
                Se enviará como: <b><?= e(ATTR_KEY) ?></b>
              </div>
            </div>

            <div class="md:col-span-2 vac-soft space-y-4">
              <div class="vac-status">
                <div>
                  <div class="text-sm font-extrabold text-slate-800">Estado del colaborador</div>
                  <div class="text-xs text-slate-500 mt-1">La búsqueda por RUT se hace en vivo antes del envío.</div>
                </div>
                <span class="vac-pill"><i class="fa-solid fa-link"></i> ADP + Buk</span>
              </div>

              <div class="vac-employee text-sm">
                <div class="vac-employee-box">
                  <div class="vac-mini">Nombre</div>
                  <div class="vac-value" id="empNombre">—</div>
                </div>
                <div class="vac-employee-box">
                  <div class="vac-mini">RUT Normalizado</div>
                  <div class="vac-value" id="empRut">—</div>
                </div>
                <div class="vac-employee-box">
                  <div class="vac-mini">buk_emp_id</div>
                  <div class="vac-value" id="empBuk">—</div>
                </div>
              </div>
            </div>

            <div class="md:col-span-2 vac-actions">
              <button
                type="submit"
                id="btnEnviar"
                class="vac-submit disabled:opacity-50 disabled:cursor-not-allowed"
                disabled
              >
                <i class="fa-solid fa-paper-plane mr-2"></i> Enviar a Buk
              </button>
            </div>
          </form>
        </div>

        <?php if ($response_data !== null): ?>
          <details open class="vac-response">
            <summary class="font-semibold cursor-pointer mb-2 flex items-center gap-2">
              <i class="fa-solid fa-arrows-rotate text-slate-500"></i> Respuesta de la API (HTTP <?= e($http_code) ?>)
            </summary>
            <pre class="text-xs bg-slate-50 border border-slate-200 rounded-xl p-4 overflow-y-auto max-h-96 whitespace-pre-wrap"><?= e($response_data) ?></pre>
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
  const rut = document.getElementById('rut');
  const saldo = document.getElementById('saldo');
  const status = document.getElementById('rutStatus');

  const empNombre = document.getElementById('empNombre');
  const empRut = document.getElementById('empRut');
  const empBuk = document.getElementById('empBuk');
  const bukHidden = document.getElementById('buk_emp_id');
  const btn = document.getElementById('btnEnviar');

  let t = null;
  let lastRut = '';

  function setEmployeeUI({nombre='—', rut='—', buk='—'}){
    empNombre.textContent = nombre || '—';
    empRut.textContent = rut || '—';
    empBuk.textContent = buk || '—';
  }

  function setCanSend(can){
    btn.disabled = !can;
  }

  function saldoOk(){
    return saldo.value !== '' && !isNaN(Number(saldo.value));
  }

  async function lookupRut(value){
    const v = (value || '').trim();
    if(!v){
      status.textContent = 'Escribe el RUT para buscar…';
      status.style.color = '#6b7280';
      bukHidden.value = '';
      setEmployeeUI({});
      setCanSend(false);
      return;
    }

    status.textContent = 'Buscando…';
    status.style.color = '#6b7280';

    try{
      const url = `?ajax=rut&rut=${encodeURIComponent(v)}`;
      const r = await fetch(url, {headers: {'Accept':'application/json'}});
      const data = await r.json();

      if(!data || !data.found){
        status.textContent = 'No encontrado en BD.';
        status.style.color = '#b91c1c';
        bukHidden.value = '';
        setEmployeeUI({});
        setCanSend(false);
        return;
      }

      // Encontrado pero sin buk_emp_id
      if(data.found && !data.has_buk){
        status.textContent = 'Encontrado, pero SIN buk_emp_id (no se puede enviar).';
        status.style.color = '#b45309';
        bukHidden.value = '';
        setEmployeeUI({nombre: data.nombre, rut: data.rut, buk: '—'});
        setCanSend(false);
        return;
      }

      // OK
      status.textContent = 'OK ✅';
      status.style.color = '#047857';
      bukHidden.value = data.buk_emp_id;
      setEmployeeUI({nombre: data.nombre, rut: data.rut, buk: data.buk_emp_id});
      setCanSend(!!data.buk_emp_id && saldoOk());

    }catch(err){
      status.textContent = 'Error buscando. Revisa consola.';
      status.style.color = '#b91c1c';
      bukHidden.value = '';
      setEmployeeUI({});
      setCanSend(false);
    }
  }

  // Debounce al escribir RUT
  rut.addEventListener('input', () => {
    const v = rut.value;
    lastRut = v;
    clearTimeout(t);
    t = setTimeout(() => lookupRut(lastRut), 300);
  });

  // Si cambia saldo, re-evalúa botón (solo si ya hay buk id)
  saldo.addEventListener('input', () => {
    setCanSend(!!bukHidden.value && saldoOk());
  });

  // Seguridad extra: no enviar si no hay buk_emp_id
  document.getElementById('vacForm').addEventListener('submit', (ev) => {
    if(!bukHidden.value){
      ev.preventDefault();
      alert('No puedes enviar: falta buk_emp_id (busca un RUT válido con buk_emp_id).');
    } else if(!saldoOk()){
      ev.preventDefault();
      alert('No puedes enviar: saldo inválido.');
    }
  });
})();
</script>

</body>
</html>
<?php ob_end_flush(); ?>

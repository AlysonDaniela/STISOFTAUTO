<?php
require_once __DIR__ . '/../includes/auth.php';
require_auth();
$user = current_user();

if (session_status() === PHP_SESSION_NONE) session_start();

// Asegura que el host no te corte en 30s durante la conversión
@set_time_limit(300);
@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '512M');

// Helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_clp_local($n){ return '$ ' . number_format((float)$n, 0, ',', '.'); }
function parseNumero($valor){
  $valor = trim((string)$valor);
  if ($valor === '' || $valor === null) return 0.0;
  $valor = str_replace(' ', '', $valor);
  $valor = str_replace('.', '', $valor);
  $valor = str_replace(',', '.', $valor);
  return is_numeric($valor) ? (float)$valor : 0.0;
}
function normalizarRut($rut){
  $rut = strtoupper(trim((string)$rut));
  $rut = str_replace([".", " "], "", $rut);
  return $rut;
}
function ensure_dir($dir){
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}

function require_vendor_autoload() {
  $cands = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
  ];
  foreach ($cands as $p) {
    if (is_file($p)) { require_once $p; return; }
  }
  throw new Exception("No se encontró vendor/autoload.php (PhpSpreadsheet).");
}

$uploadDir = ensure_dir(__DIR__ . '/tmp_uploads');
$cacheDir  = ensure_dir(__DIR__ . '/cache_csv');

$msgOk = '';
$msgErr = '';

// ============================
// NUEVO: default de combobox (tipo recuadro)
// ============================
if (!isset($_SESSION['rliquid_remun_tipo'])) {
  $_SESSION['rliquid_remun_tipo'] = 'mes'; // mes | dia25
}

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
  $oldXlsx = $_SESSION['rliquid_xlsx'] ?? '';
  $oldCsv  = $_SESSION['rliquid_csv'] ?? '';
  if ($oldXlsx && is_file($oldXlsx)) @unlink($oldXlsx);
  if ($oldCsv  && is_file($oldCsv))  @unlink($oldCsv);
  unset($_SESSION['rliquid_xlsx'], $_SESSION['rliquid_csv'], $_SESSION['rliquid_path']);

  // reset tipo recuadro
  $_SESSION['rliquid_remun_tipo'] = 'mes';

  $msgOk = "Se reinició la carga.";
}

$xlsxPath = $_SESSION['rliquid_xlsx'] ?? '';
$csvPath  = $_SESSION['rliquid_csv'] ?? '';

function xlsx_to_csv_cache(string $xlsxPath, string $csvPath): void {
  require_vendor_autoload();

  if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
    throw new Exception("PhpSpreadsheet no está disponible.");
  }

  $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($xlsxPath);
  $reader->setReadDataOnly(true);

  // TERM normalmente viene en 1 hoja
  $spreadsheet = $reader->load($xlsxPath);
  $sheet = $spreadsheet->getActiveSheet();

  $out = fopen($csvPath, 'w');
  if (!$out) throw new Exception("No se pudo crear CSV cache.");

  $highestCol = $sheet->getHighestColumn();
  $highestRow = (int)$sheet->getHighestRow();

  // header
  $header = $sheet->rangeToArray("A1:{$highestCol}1", null, true, false);
  fputcsv($out, $header[0] ?? []);

  // rows
  for ($r=2; $r <= $highestRow; $r++) {
    $row = $sheet->rangeToArray("A{$r}:{$highestCol}{$r}", null, true, false);
    if (!isset($row[0])) continue;
    fputcsv($out, $row[0]);
  }

  fclose($out);
  $spreadsheet->disconnectWorksheets();
  unset($spreadsheet);
}

function summary_from_csv_latest_TERM(string $csvPath): array {
  $h = fopen($csvPath, 'r');
  if (!$h) throw new Exception("No se pudo abrir CSV cache.");

  $firstLine = fgets($h);
  if ($firstLine === false) throw new Exception("CSV vacío.");
  $delim = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
  rewind($h);

  $header = fgetcsv($h, 0, $delim);
  if (!$header) throw new Exception("CSV sin encabezado.");

  $idx = [];
  foreach ($header as $i => $col) $idx[trim((string)$col)] = $i;

  $req = ['Codigo','Ames','Cohade','Tipo','Descitm','Monto','Inform'];
  foreach ($req as $c) if (!isset($idx[$c])) throw new Exception("CSV cache sin columna: {$c}");

  // 1) latest AMES por rut
  $latest = [];
  while (($row = fgetcsv($h, 0, $delim)) !== false) {
    $rut = normalizarRut($row[$idx['Codigo']] ?? '');
    if ($rut === '') continue;
    $ames = trim((string)($row[$idx['Ames']] ?? ''));
    if ($ames === '') continue;
    if (!isset($latest[$rut]) || strcmp($ames, $latest[$rut]) > 0) $latest[$rut] = $ames;
  }

  // 2) totales solo para ese AMES y SOLO Inform=N
  rewind($h);
  fgetcsv($h, 0, $delim); // header

  $acc = []; // rut => hab, desc, ames
  while (($row = fgetcsv($h, 0, $delim)) !== false) {
    $rut = normalizarRut($row[$idx['Codigo']] ?? '');
    if ($rut === '' || !isset($latest[$rut])) continue;

    $ames = trim((string)($row[$idx['Ames']] ?? ''));
    if ($ames === '' || $ames !== $latest[$rut]) continue;

    $inform = strtoupper(trim((string)($row[$idx['Inform']] ?? '')));
    if ($inform !== 'N') continue;

    $tipo  = (int)trim((string)($row[$idx['Tipo']] ?? 0));
    $monto = parseNumero($row[$idx['Monto']] ?? 0);

    if (!isset($acc[$rut])) $acc[$rut] = ['rut'=>$rut,'ames'=>$ames,'hab'=>0.0,'desc'=>0.0];

    if ($tipo === 1 || $tipo === 2) $acc[$rut]['hab'] += (float)$monto;
    elseif ($tipo === 3 || $tipo === 4) $acc[$rut]['desc'] += (float)$monto;
  }

  fclose($h);

  $summary = [];
  foreach ($acc as $a) {
    $summary[] = [
      'rut' => $a['rut'],
      'ames' => $a['ames'],
      'haberes' => $a['hab'],
      'descuentos' => $a['desc'],
      'neto' => $a['hab'] - $a['desc'],
    ];
  }
  usort($summary, fn($x,$y)=> strcmp($x['rut'],$y['rut']));
  return $summary;
}

// Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['rliquid'])) {

  // ============================
  // NUEVO: guardar tipo recuadro en sesión
  // ============================
  $tipo = ($_POST['remun_tipo'] ?? 'mes') === 'dia25' ? 'dia25' : 'mes';
  $_SESSION['rliquid_remun_tipo'] = $tipo;

  if ($_FILES['rliquid']['error'] !== UPLOAD_ERR_OK) {
    $msgErr = "No se recibió un archivo válido.";
  } else {
    $name = basename($_FILES['rliquid']['name']);
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
      $msgErr = "Solo se permite TERM .xlsx";
    } else {
      $destXlsx = $uploadDir . '/' . date('Ymd_His') . '__' . preg_replace('/[^a-zA-Z0-9._-]/','_',$name);
      if (!move_uploaded_file($_FILES['rliquid']['tmp_name'], $destXlsx)) {
        $msgErr = "No se pudo guardar el archivo.";
      } else {
        $destCsv = $cacheDir . '/' . date('Ymd_His') . '__TERM.csv';
        try {
          xlsx_to_csv_cache($destXlsx, $destCsv);
          $_SESSION['rliquid_xlsx'] = $destXlsx;
          $_SESSION['rliquid_csv']  = $destCsv;

          // IMPORTANTÍSIMO: liquidacion.php seguirá leyendo de rliquid_path
          // pero ahora será el CSV (rápido y compatible con la nueva lógica)
          $_SESSION['rliquid_path'] = $destCsv;

          $xlsxPath = $destXlsx;
          $csvPath  = $destCsv;
          $msgOk = "Procesado OK (XLSX→CSV cache).";
        } catch (Exception $e) {
          $msgErr = "Error convirtiendo XLSX: " . $e->getMessage();
        }
      }
    }
  }
}

$summary = [];
if ($csvPath && is_file($csvPath)) {
  try {
    $summary = summary_from_csv_latest_TERM($csvPath);
  } catch (Exception $e) {
    $msgErr = "Error leyendo CSV cache: " . $e->getMessage();
  }
}

$summaryCount = count($summary);
$summaryTotalNeto = 0.0;
$summaryLatestAmes = '';
foreach ($summary as $row) {
  $summaryTotalNeto += (float)($row['neto'] ?? 0);
  $ames = trim((string)($row['ames'] ?? ''));
  if ($ames !== '' && ($summaryLatestAmes === '' || strcmp($ames, $summaryLatestAmes) > 0)) {
    $summaryLatestAmes = $ames;
  }
}

// Layout (mismo look que tus otros index)
$headPath    = __DIR__ . '/../partials/head.php';
$sidebarPath = __DIR__ . '/../partials/sidebar.php';
$topbarPath  = __DIR__ . '/../partials/topbar.php';
$footerPath  = __DIR__ . '/../partials/footer.php';

include $headPath;
?>
<body class="bg-gray-50">
  <div class="min-h-screen grid grid-cols-12">
    <aside class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
      <?php $active='rliquid'; include $sidebarPath; ?>
    </aside>

    <main class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col">
      <?php include $topbarPath; ?>
      <div class="max-w-7xl w-full p-4 md:p-8 space-y-6">

        <section class="rounded-3xl border border-slate-200 bg-gradient-to-r from-slate-900 via-cyan-900 to-sky-800 text-white p-6 shadow-sm">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <div class="text-xs uppercase tracking-[0.18em] text-cyan-100/80">Liquidaciones</div>
              <h1 class="text-2xl md:text-3xl font-semibold mt-2">Generar PDFs de liquidaciones</h1>
              <p class="text-sm text-cyan-50/90 mt-3 max-w-3xl">Esta pantalla solo sirve para generar PDFs. No envía a Buk y está pensada para trabajar el archivo de forma simple, rápida y masiva.</p>
            </div>
            <div class="grid grid-cols-1 gap-3 min-w-[260px]">
              <div class="rounded-2xl bg-white/10 border border-white/10 px-4 py-3 text-sm">
                <div class="text-cyan-100/70 text-xs">Archivo cargado</div>
                <div class="mt-1 font-semibold">
                  <?php if ($xlsxPath && is_file($xlsxPath)): ?>
                    <?=h(basename($xlsxPath))?>
                  <?php else: ?>
                    Sin archivo
                  <?php endif; ?>
                </div>
              </div>
              <div class="rounded-2xl bg-white/10 border border-white/10 px-4 py-3 text-sm">
                <div class="text-cyan-100/70 text-xs">Tipo</div>
                <div class="mt-1 font-semibold"><?=($_SESSION['rliquid_remun_tipo'] ?? 'mes')==='dia25' ? 'REMUN. DIA 25' : 'Remuneración del mes'?></div>
              </div>
            </div>
          </div>
        </section>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">1. Cargar archivo</div>
            <div class="mt-2 text-sm text-slate-600">Sube el TERM y selecciona el tipo de recuadro.</div>
          </div>
          <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">2. Revisar resumen</div>
            <div class="mt-2 text-sm text-slate-600">Confirma trabajadores, AMES y neto total del archivo actual.</div>
          </div>
          <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">3. Generar PDFs</div>
            <div class="mt-2 text-sm text-slate-600">Hazlo individualmente o de forma masiva para todos o seleccionados.</div>
          </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
          <div class="flex items-start justify-between gap-4">
            <div>
              <h2 class="text-lg font-semibold text-slate-900">Cargar archivo</h2>
              <p class="text-sm text-slate-500 mt-1">El sistema convierte el XLSX a CSV interno para trabajar más rápido.</p>
            </div>
            <a href="/rliquid/" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
              <i class="fa-solid fa-arrow-left"></i>
              Volver a liquidaciones
            </a>
          </div>

          <?php if ($msgOk): ?>
            <div class="mt-4 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-green-800 text-sm font-semibold">
              <?=h($msgOk)?>
            </div>
          <?php endif; ?>
          <?php if ($msgErr): ?>
            <div class="mt-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-red-800 text-sm font-semibold">
              <?=h($msgErr)?>
            </div>
          <?php endif; ?>

          <form method="post" enctype="multipart/form-data" class="mt-5 grid grid-cols-1 md:grid-cols-[1fr_280px_auto_auto] gap-3 md:items-end">
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-2">Archivo TERM (.xlsx)</label>
              <input type="file" name="rliquid" accept=".xlsx"
                class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm" required>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-2">Tipo de recuadro</label>
              <select name="remun_tipo" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm">
                <option value="mes"   <?=($_SESSION['rliquid_remun_tipo'] ?? 'mes')==='mes'?'selected':''?>>Remuneración del mes</option>
                <option value="dia25" <?=($_SESSION['rliquid_remun_tipo'] ?? '')==='dia25'?'selected':''?>>REMUN. DIA 25</option>
              </select>
            </div>

            <button type="submit" class="rounded-2xl bg-cyan-700 text-white px-5 py-3 text-sm font-semibold hover:bg-cyan-800 transition">
              Procesar
            </button>

            <a href="?reset=1" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 text-center transition">
              Reiniciar
            </a>
          </form>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Trabajadores</div>
            <div class="mt-2 text-3xl font-extrabold text-slate-900"><?=h((string)$summaryCount)?></div>
            <div class="mt-1 text-sm text-slate-500">RUT detectados en el archivo actual</div>
          </div>
          <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">AMES más reciente</div>
            <div class="mt-2 text-3xl font-extrabold text-slate-900"><?=h($summaryLatestAmes ?: '—')?></div>
            <div class="mt-1 text-sm text-slate-500">Usado para el resumen por trabajador</div>
          </div>
          <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Neto total</div>
            <div class="mt-2 text-3xl font-extrabold text-slate-900"><?=h(fmt_clp_local($summaryTotalNeto))?></div>
            <div class="mt-1 text-sm text-slate-500">Suma referencial del archivo cargado</div>
          </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
              <h2 class="text-lg font-extrabold text-slate-900">Resumen por trabajador</h2>
              <p class="text-sm text-slate-500 mt-1">Selecciona personas específicas o genera todos los PDFs del archivo actual.</p>
            </div>

            <div class="flex flex-wrap gap-2">
              <button type="button"
                class="rounded-2xl bg-indigo-600 px-4 py-3 text-white text-xs font-semibold hover:bg-indigo-700 transition"
                onclick="openAllPdfs()"
                <?=(!$summary ? 'disabled style="opacity:.5;cursor:not-allowed;"' : '')?>>
                Generar PDFs (todos)
              </button>

              <button type="button"
                class="rounded-2xl bg-slate-900 px-4 py-3 text-white text-xs font-semibold hover:bg-black transition"
                onclick="openSelectedPdfs()"
                <?=(!$summary ? 'disabled style="opacity:.5;cursor:not-allowed;"' : '')?>>
                Generar PDFs (seleccionados)
              </button>
            </div>
          </div>

          <p class="text-xs text-slate-500 mt-3">
            Nota: el masivo abre PDFs en pestañas. Si el navegador bloquea popups, permite popups para este sitio.
          </p>

          <div class="mt-4 overflow-auto rounded-2xl border border-slate-200">
            <table class="min-w-full text-sm" id="tblRliquid">
              <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-600">
                <tr>
                  <th class="px-4 py-3 text-left">
                    <input type="checkbox" id="chkAll" onclick="toggleAllChecks(this)">
                  </th>
                  <th class="px-4 py-3 text-left">RUT</th>
                  <th class="px-4 py-3 text-left">AMES</th>
                  <th class="px-4 py-3 text-right">Haberes</th>
                  <th class="px-4 py-3 text-right">Descuentos</th>
                  <th class="px-4 py-3 text-right">Neto</th>
                  <th class="px-4 py-3 text-left">Acción</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100 bg-white">
                <?php if (!$summary): ?>
                  <tr><td colspan="7" class="px-4 py-6 text-center text-slate-500">No hay datos para mostrar.</td></tr>
                <?php else: ?>
                  <?php foreach ($summary as $s): ?>
                    <tr class="hover:bg-slate-50">
                      <td class="px-4 py-3">
                        <input type="checkbox" class="chkOne"
                          data-rut="<?=h($s['rut'])?>"
                          data-ames="<?=h($s['ames'])?>">
                      </td>

                      <td class="px-4 py-3 font-semibold text-slate-900"><?=h($s['rut'])?></td>
                      <td class="px-4 py-3 text-slate-700"><?=h($s['ames'])?></td>
                      <td class="px-4 py-3 text-right"><?=fmt_clp_local($s['haberes'])?></td>
                      <td class="px-4 py-3 text-right"><?=fmt_clp_local($s['descuentos'])?></td>
                      <td class="px-4 py-3 text-right font-extrabold text-slate-900"><?=fmt_clp_local($s['neto'])?></td>
                      <td class="px-4 py-3">
                        <a class="inline-flex items-center rounded-2xl bg-indigo-600 px-3 py-2 text-white text-xs font-semibold hover:bg-indigo-700 transition"
                           href="liquidacion.php?rut=<?=urlencode($s['rut'])?>&ames=<?=urlencode($s['ames'])?>"
                           target="_blank" rel="noopener">
                          Generar PDF
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>

      <?php include $footerPath; ?>
    </main>
  </div>

  <!-- NUEVO: JS para masivo -->
  <script>
    function toggleAllChecks(master){
      document.querySelectorAll('.chkOne').forEach(function(chk){
        chk.checked = !!master.checked;
      });
    }

    function openPdf(rut, ames){
      var url = 'liquidacion.php?rut=' + encodeURIComponent(rut) + '&ames=' + encodeURIComponent(ames);
      window.open(url, '_blank', 'noopener');
    }

    // Masivo TODOS
    function openAllPdfs(){
      var rows = document.querySelectorAll('.chkOne');
      if (!rows || rows.length === 0) return;

      var i = 0;
      var timer = setInterval(function(){
        if (i >= rows.length){ clearInterval(timer); return; }
        var rut = rows[i].getAttribute('data-rut');
        var ames = rows[i].getAttribute('data-ames');
        openPdf(rut, ames);
        i++;
      }, 250);
    }

    // Masivo SELECCIONADOS
    function openSelectedPdfs(){
      var rows = Array.from(document.querySelectorAll('.chkOne')).filter(function(chk){ return chk.checked; });
      if (!rows || rows.length === 0){
        alert('Selecciona al menos 1 trabajador.');
        return;
      }

      var i = 0;
      var timer = setInterval(function(){
        if (i >= rows.length){ clearInterval(timer); return; }
        var rut = rows[i].getAttribute('data-rut');
        var ames = rows[i].getAttribute('data-ames');
        openPdf(rut, ames);
        i++;
      }, 250);
    }
  </script>
</body>
</html>

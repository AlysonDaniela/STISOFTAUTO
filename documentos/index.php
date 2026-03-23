<?php
// /documentos/index.php — Maqueta UI/UX mejorada (sin BD)
// Lista /storage/sftp y /storage/liquidaciones si existen; si no, usa mock.
// Usa includes/partials globales como el resto del sitio.

require_once __DIR__ . '/../includes/auth.php';
require_auth();
$user = current_user();

// ---------- Helpers ----------
function list_files_or_mock(string $dir, array $mock) {
  if (is_dir($dir)) {
    $items = [];
    foreach (scandir($dir) as $f) {
      if ($f === '.' || $f === '..') continue;
      $p = rtrim($dir, '/').'/'.$f;
      if (is_file($p)) {
        $items[] = [
          'name' => $f,
          'size' => filesize($p),
          'mtime'=> filemtime($p),
          'path' => $p,
        ];
      }
    }
    usort($items, fn($a,$b)=> $b['mtime'] <=> $a['mtime']); // recientes primero
    return $items;
  }
  return $mock;
}
function human_size($bytes) {
  $u=['B','KB','MB','GB']; $i=0;
  while ($bytes>=1024 && $i<count($u)-1){ $bytes/=1024; $i++; }
  return number_format($bytes, $bytes<10&&$i>0?1:0).' '.$u[$i];
}

// ---------- MOCK ----------
$mock_sftp = [
  ['name'=>'empleados_2025-09-19.csv','size'=>245123,'mtime'=>strtotime('2025-09-19 03:24'),'path'=>'#'],
  ['name'=>'contratos_2025-09-19.csv','size'=>180541,'mtime'=>strtotime('2025-09-19 03:24'),'path'=>'#'],
  ['name'=>'cargos_2025-09-18.csv','size'=>120880,'mtime'=>strtotime('2025-09-18 22:10'),'path'=>'#'],
  ['name'=>'centros_costo_2025-09-18.csv','size'=>90912,'mtime'=>strtotime('2025-09-18 20:15'),'path'=>'#'],
];
$mock_liqs = [
  ['name'=>'12345678-9_2025-08_liq.pdf','size'=>98331,'mtime'=>strtotime('2025-09-01 11:00'),'path'=>'#'],
  ['name'=>'11222333-4_2025-08_liq.pdf','size'=>102004,'mtime'=>strtotime('2025-09-01 11:03'),'path'=>'#'],
  ['name'=>'12345678-9_2025-07_liq.pdf','size'=>96552,'mtime'=>strtotime('2025-08-01 10:41'),'path'=>'#'],
  ['name'=>'11222333-4_2025-07_liq.pdf','size'=>100440,'mtime'=>strtotime('2025-08-01 10:45'),'path'=>'#'],
];

// ---------- DATASETS (dir real o mock) ----------
$sftp_files = list_files_or_mock(__DIR__.'/../storage/sftp',          $mock_sftp);
$liq_files  = list_files_or_mock(__DIR__.'/../storage/liquidaciones', $mock_liqs);

// KPI contadores
$kpi = [
  'sftp' => count($sftp_files),
  'liq'  => count($liq_files),
];

// Para filtros en el front (json)
$sftp_json = json_encode($sftp_files, JSON_UNESCAPED_UNICODE);
$liq_json  = json_encode($liq_files,  JSON_UNESCAPED_UNICODE);
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="bg-gray-50">
  <div class="min-h-screen grid grid-cols-12">
    <!-- SIDEBAR -->
    <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
      <?php $active='documentos'; include __DIR__ . '/../partials/sidebar.php'; ?>
    </div>

    <!-- MAIN -->
    <div class="col-span-12 md:col-span-9 lg:col-span-10">
      <?php include __DIR__ . '/../partials/topbar.php'; ?>

      <main class="max-w-7xl mx-auto p-6 space-y-6">
        <!-- Título + Tabs con contadores -->
        <section class="space-y-3">
          <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Documentos</h1>
            <div class="text-xs text-gray-500">Maqueta · No ejecuta acciones reales</div>
          </div>

          <!-- Barra sticky: drag&drop, búsqueda, filtros y acciones -->
          <div class="sticky top-[72px] z-10">
            <div class="bg-white/90 backdrop-blur border rounded-2xl p-3 shadow-sm">
              <div class="flex flex-wrap items-center gap-3">
                <!-- Tabs -->
                <div class="flex items-center gap-2">
                  <button data-tab="sftp" class="tab-btn px-3 py-1.5 rounded-lg border bg-gray-900 text-white text-sm">
                    SFTP / ADP <span id="count-sftp" class="ml-1 text-xs opacity-80">(<?= $kpi['sftp'] ?>)</span>
                  </button>
                  <button data-tab="liq" class="tab-btn px-3 py-1.5 rounded-lg border text-sm hover:bg-white">
                    Liquidaciones <span id="count-liq" class="ml-1 text-xs opacity-60">(<?= $kpi['liq'] ?>)</span>
                  </button>
                </div>

                <!-- Drag & drop (mock) -->
                <div id="dropzone" class="flex items-center gap-2 px-3 py-1.5 rounded-lg border border-dashed text-sm text-gray-600">
                  <i class="fa-solid fa-upload"></i>
                  Arrastra archivos aquí (mock)
                </div>

                <!-- Buscador -->
                <div class="relative">
                  <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                  <input id="q" type="search" placeholder="Buscar archivo…" class="pl-9 pr-3 py-1.5 bg-white border rounded-lg text-sm w-64">
                </div>

                <!-- Filtros -->
                <select id="tipo" class="bg-white border rounded-lg text-sm py-1.5 px-2">
                  <option value="">Tipo (todos)</option>
                  <option value="csv">CSV</option>
                  <option value="pdf">PDF</option>
                </select>
                <select id="rango" class="bg-white border rounded-lg text-sm py-1.5 px-2">
                  <option value="">Últimos 90 días</option>
                  <option value="30">Últimos 30 días</option>
                  <option value="7">Últimos 7 días</option>
                </select>

                <!-- Acciones masivas (mock) -->
                <div class="ml-auto flex items-center gap-2">
                  <select id="bulkAction" class="bg-white border rounded-lg text-sm py-1.5 px-2">
                    <option value="">Acción masiva…</option>
                    <option value="download">Descargar</option>
                    <option value="send">Enviar a Buk</option>
                    <option value="delete">Eliminar</option>
                  </select>
                  <button id="bulkRun" class="px-3 py-1.5 rounded-lg border text-sm hover:bg-white">Aplicar</button>
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- Listado SFTP -->
        <section id="panel-sftp" class="bg-white border rounded-2xl shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b flex items-center justify-between">
            <div class="font-semibold">SFTP / Documentos ADP</div>
            <div class="text-sm text-gray-500">Origen: /storage/sftp</div>
          </div>

          <div class="overflow-x-auto">
            <table class="min-w-full text-sm" id="table-sftp">
              <thead class="bg-gray-50 text-gray-600">
                <tr>
                  <th class="px-5 py-3"><input type="checkbox" id="sftp-check-all"></th>
                  <th class="px-5 py-3 text-left">Archivo</th>
                  <th class="px-5 py-3 text-left">Fecha</th>
                  <th class="px-5 py-3 text-left">Tamaño</th>
                  <th class="px-5 py-3 text-left">Estado</th>
                  <th class="px-5 py-3 text-left">Acciones</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
              <?php foreach ($sftp_files as $f): 
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                $badge = '<span class="px-2 py-0.5 rounded-full text-xs bg-blue-50 text-blue-700 border border-blue-200">Respaldado</span>';
              ?>
                <tr class="hover:bg-gray-50">
                  <td class="px-5 py-3"><input type="checkbox" class="sftp-check"></td>
                  <td class="px-5 py-3">
                    <div class="flex items-center gap-3">
                      <i class="fa-solid <?php echo $ext==='csv' ? 'fa-file-csv' : 'fa-file-lines'; ?> text-gray-500"></i>
                      <span class="font-medium"><?php echo htmlspecialchars($f['name']); ?></span>
                    </div>
                  </td>
                  <td class="px-5 py-3"><?php echo date('Y-m-d H:i', $f['mtime']); ?></td>
                  <td class="px-5 py-3"><?php echo human_size($f['size']); ?></td>
                  <td class="px-5 py-3"><?php echo $badge; ?></td>
                  <td class="px-5 py-3">
                    <div class="flex items-center gap-2">
                      <button class="btn-view px-2 py-1 rounded-lg border hover:bg-white" data-name="<?php echo htmlspecialchars($f['name']); ?>" data-ext="<?php echo $ext; ?>" data-ts="<?php echo $f['mtime']; ?>" data-size="<?php echo $f['size']; ?>"><i class="fa-regular fa-eye"></i></button>
                      <button class="px-2 py-1 rounded-lg border hover:bg-white" title="Descargar (mock)"><i class="fa-solid fa-download"></i></button>
                      <button class="px-2 py-1 rounded-lg border hover:bg-white" title="Enviar a Buk (mock)"><i class="fa-solid fa-paper-plane"></i></button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Paginación (maqueta simple) -->
          <div class="px-5 py-3 border-t flex items-center justify-between text-sm">
            <div class="text-gray-500">Mostrando <span id="sftp-range">1–<?= max(1,min(10,$kpi['sftp'])) ?></span> de <?= $kpi['sftp'] ?></div>
            <div class="flex items-center gap-2">
              <button class="sftp-page px-2 py-1 rounded-lg border disabled:opacity-50" data-dir="-1" disabled>&laquo;</button>
              <button class="sftp-page px-2 py-1 rounded-lg border" data-dir="1">&raquo;</button>
            </div>
          </div>
        </section>

        <!-- Listado Liquidaciones -->
        <section id="panel-liq" class="bg-white border rounded-2xl shadow-sm overflow-hidden hidden">
          <div class="px-5 py-4 border-b flex items-center justify-between">
            <div class="font-semibold">Liquidaciones de trabajadores</div>
            <div class="text-sm text-gray-500">Origen: /storage/liquidaciones</div>
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm" id="table-liq">
              <thead class="bg-gray-50 text-gray-600">
                <tr>
                  <th class="px-5 py-3"><input type="checkbox" id="liq-check-all"></th>
                  <th class="px-5 py-3 text-left">Archivo</th>
                  <th class="px-5 py-3 text-left">Fecha</th>
                  <th class="px-5 py-3 text-left">Tamaño</th>
                  <th class="px-5 py-3 text-left">Estado</th>
                  <th class="px-5 py-3 text-left">Acciones</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
              <?php foreach ($liq_files as $f): 
                $badge = '<span class="px-2 py-0.5 rounded-full text-xs bg-amber-50 text-amber-700 border border-amber-200">Pendiente de enviar</span>';
              ?>
                <tr class="hover:bg-gray-50">
                  <td class="px-5 py-3"><input type="checkbox" class="liq-check"></td>
                  <td class="px-5 py-3">
                    <div class="flex items-center gap-3">
                      <i class="fa-regular fa-file-pdf text-gray-500"></i>
                      <span class="font-medium"><?php echo htmlspecialchars($f['name']); ?></span>
                    </div>
                  </td>
                  <td class="px-5 py-3"><?php echo date('Y-m-d H:i', $f['mtime']); ?></td>
                  <td class="px-5 py-3"><?php echo human_size($f['size']); ?></td>
                  <td class="px-5 py-3"><?php echo $badge; ?></td>
                  <td class="px-5 py-3">
                    <div class="flex items-center gap-2">
                      <button class="btn-view px-2 py-1 rounded-lg border hover:bg-white" data-name="<?php echo htmlspecialchars($f['name']); ?>" data-ext="pdf" data-ts="<?php echo $f['mtime']; ?>" data-size="<?php echo $f['size']; ?>"><i class="fa-regular fa-eye"></i></button>
                      <button class="px-2 py-1 rounded-lg border hover:bg-white" title="Descargar (mock)"><i class="fa-solid fa-download"></i></button>
                      <button class="px-2 py-1 rounded-lg border hover:bg-white" title="Enviar a Buk (mock)"><i class="fa-solid fa-paper-plane"></i></button>
                      <button class="px-2 py-1 rounded-lg border hover:bg-white" title="Eliminar (mock)"><i class="fa-regular fa-trash-can"></i></button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Paginación (maqueta simple) -->
          <div class="px-5 py-3 border-t flex items-center justify-between text-sm">
            <div class="text-gray-500">Mostrando <span id="liq-range">1–<?= max(1,min(10,$kpi['liq'])) ?></span> de <?= $kpi['liq'] ?></div>
            <div class="flex items-center gap-2">
              <button class="liq-page px-2 py-1 rounded-lg border disabled:opacity-50" data-dir="-1" disabled>&laquo;</button>
              <button class="liq-page px-2 py-1 rounded-lg border" data-dir="1">&raquo;</button>
            </div>
          </div>
        </section>

        <!-- Nota -->
        <p class="text-xs text-gray-500">
          * Maqueta: filtros, drag&drop, descargas y envíos no realizan operaciones reales.
          Si creas las carpetas <code>/storage/sftp</code> y <code>/storage/liquidaciones</code>,
          se listarán archivos reales automáticamente.
        </p>
      </main>
    </div>
  </div>

  <!-- Modal de previsualización (mock) -->
  <div id="modal" class="fixed inset-0 bg-black/40 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-lg shadow-xl">
      <div class="px-5 py-4 border-b flex items-center justify-between">
        <h3 id="m-title" class="font-semibold">Archivo</h3>
        <button id="m-close" class="p-2 rounded-md hover:bg-gray-100">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
      <div class="p-5 space-y-2 text-sm text-gray-700">
        <div id="m-meta" class="text-gray-600"></div>
        <div class="rounded-lg border p-3 bg-gray-50">
          <div id="m-preview" class="text-xs text-gray-500">
            Previsualización no disponible (maqueta).
          </div>
        </div>
      </div>
      <div class="px-5 py-4 border-t flex items-center justify-end gap-2">
        <button class="px-3 py-1.5 rounded-lg border hover:bg-white">Descargar</button>
        <button class="px-3 py-1.5 rounded-lg bg-emerald-500 text-white">Enviar a Buk</button>
      </div>
    </div>
  </div>

  <script>
  // Datos para filtros en el front (no requerido, pero útil si luego migras a render JS)
  const DATA = {
    sftp: <?= $sftp_json ?>,
    liq:  <?= $liq_json ?>
  };

  // -------- Tabs --------
  const tabs = document.querySelectorAll('.tab-btn');
  const panelS = document.getElementById('panel-sftp');
  const panelL = document.getElementById('panel-liq');
  tabs.forEach(b=>{
    b.addEventListener('click', ()=>{
      tabs.forEach(x=> x.classList.remove('bg-gray-900','text-white'));
      b.classList.add('bg-gray-900','text-white');
      const t = b.dataset.tab;
      panelS.classList.toggle('hidden', t!=='sftp');
      panelL.classList.toggle('hidden', t!=='liq');
    });
  });

  // -------- Drag & Drop (mock visual) --------
  const dz = document.getElementById('dropzone');
  ['dragenter','dragover'].forEach(ev=> dz.addEventListener(ev, e=>{ e.preventDefault(); dz.classList.add('ring-2','ring-brand'); }));
  ['dragleave','drop'].forEach(ev=> dz.addEventListener(ev, e=>{ e.preventDefault(); dz.classList.remove('ring-2','ring-brand'); }));
  dz.addEventListener('drop', e=>{
    const n = (e.dataTransfer && e.dataTransfer.files) ? e.dataTransfer.files.length : 1;
    alert(`(Maqueta) Se simula carga de ${n} archivo(s).`);
  });

  // -------- Filtros (simple: ocultan filas por texto/extension) --------
  const q = document.getElementById('q');
  const tipo = document.getElementById('tipo');
  const rango = document.getElementById('rango');

  function applyFilters(tblId) {
    const tbody = document.querySelector(`#${tblId} tbody`);
    const rows = tbody.querySelectorAll('tr');
    const query = q.value.toLowerCase();
    const ext = tipo.value;
    const days = parseInt(rango.value || '90', 10);
    const since = Date.now() - (days*24*3600*1000);

    let visible = 0;
    rows.forEach(tr=>{
      const name = tr.querySelector('td:nth-child(2) span')?.textContent.toLowerCase() || '';
      const dateText = tr.querySelector('td:nth-child(3)')?.textContent || '';
      const extGuess = name.split('.').pop();
      const ts = new Date(dateText.replace(' ', 'T')).getTime() || Date.now();

      const okText = !query || name.includes(query);
      const okExt = !ext || extGuess === ext;
      const okDate = isFinite(ts) ? (ts >= since) : true;

      const show = okText && okExt && okDate;
      tr.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    // Rango mostrado (maqueta)
    const rangeEl = document.getElementById(tblId === 'table-sftp' ? 'sftp-range' : 'liq-range');
    if (rangeEl) rangeEl.textContent = `1–${Math.max(1, Math.min(visible, 10))}`;
  }

  ['input','change'].forEach(ev=>{
    q.addEventListener(ev, ()=>{ applyFilters('table-sftp'); applyFilters('table-liq'); });
    tipo.addEventListener(ev, ()=>{ applyFilters('table-sftp'); applyFilters('table-liq'); });
    rango.addEventListener(ev, ()=>{ applyFilters('table-sftp'); applyFilters('table-liq'); });
  });

  // -------- Check all + bulk (mock) --------
  function bindCheckAll(prefix) {
    const all = document.getElementById(`${prefix}-check-all`);
    const boxes = document.querySelectorAll(`.${prefix}-check`);
    if (!all) return;
    all.addEventListener('change', ()=> boxes.forEach(b=> b.checked = all.checked));
  }
  bindCheckAll('sftp'); bindCheckAll('liq');

  document.getElementById('bulkRun').addEventListener('click', ()=>{
    const action = document.getElementById('bulkAction').value || '(ninguna)';
    alert(`(Maqueta) Ejecutar acción masiva: ${action}`);
  });

  // -------- Paginación (maqueta simple: sólo cambios del "range") --------
  function bindPager(prefix, total) {
    const prev = document.querySelector(`.${prefix}-page[data-dir="-1"]`);
    const next = document.querySelector(`.${prefix}-page[data-dir="1"]`);
    let page = 1; const per = 10; const pages = Math.max(1, Math.ceil(total/per));
    const rangeEl = document.getElementById(prefix === 'sftp' ? 'sftp-range' : 'liq-range');

    function update() {
      const from = (page-1)*per + 1;
      const to   = Math.min(total, page*per);
      rangeEl.textContent = `${from}–${to}`;
      prev.disabled = (page<=1);
      next.disabled = (page>=pages);
    }
    prev?.addEventListener('click', ()=>{ if(page>1){ page--; update(); } });
    next?.addEventListener('click', ()=>{ if(page<pages){ page++; update(); } });
    update();
  }
  bindPager('sftp', <?= $kpi['sftp'] ?>);
  bindPager('liq',  <?= $kpi['liq']  ?>);

  // -------- Modal preview (mock) --------
  const modal = document.getElementById('modal');
  const mTitle = document.getElementById('m-title');
  const mMeta  = document.getElementById('m-meta');
  const mPrev  = document.getElementById('m-preview');
  document.querySelectorAll('.btn-view').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const name = btn.dataset.name;
      const ext = btn.dataset.ext || 'file';
      const ts  = parseInt(btn.dataset.ts || Date.now(),10);
      const size= parseInt(btn.dataset.size || 0,10);
      mTitle.textContent = name;
      mMeta.textContent  = `Tipo: ${ext.toUpperCase()} · Fecha: ${new Date(ts*1000).toLocaleString()} · Tamaño: ~${(size/1024).toFixed(1)} KB`;
      mPrev.textContent  = ext==='csv' ? 'Vista previa CSV (maqueta)' :
                           ext==='pdf' ? 'Vista previa PDF (maqueta)' : 'Previsualización no disponible (maqueta)';
      modal.classList.remove('hidden');
      modal.classList.add('flex');
    });
  });
  document.getElementById('m-close').addEventListener('click', ()=>{
    modal.classList.add('hidden'); modal.classList.remove('flex');
  });
  modal.addEventListener('click', (e)=>{ if(e.target===modal){ modal.classList.add('hidden'); modal.classList.remove('flex'); } });

  // Aplicar filtros una vez al cargar
  applyFilters('table-sftp'); applyFilters('table-liq');
  </script>
</body>
</html>

<?php
require_once __DIR__ . '/includes/auth.php';
require_auth();
?>
<?php // empleados.php — Maqueta de comparación ADP vs Buk sin BD ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Empleados — Comparación ADP vs Buk</title>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />

  <!-- PapaParse (CSV) -->
  <script src="https://cdn.jsdelivr.net/npm/papaparse@5.4.1/papaparse.min.js"></script>

  <style>
    .badge { @apply px-2 py-0.5 rounded-full text-xs; }
  </style>
</head>
<body class="bg-gray-50 text-gray-900">

<!-- Topbar simple -->
<header class="sticky top-0 z-10 bg-white/90 backdrop-blur shadow-sm border-b border-gray-200">
  <div class="max-w-7xl mx-auto px-4 h-[72px] flex items-center justify-between">
    <h1 class="text-lg font-semibold leading-none">Empleados — ADP vs Buk</h1>
    <div class="flex items-center gap-2 text-sm">
      <a href="/home.php" class="px-3 py-1.5 rounded-lg border hover:bg-gray-50">Inicio</a>
      <a href="/documentos.php" class="px-3 py-1.5 rounded-lg border hover:bg-gray-50">Documentos</a>
      <a href="/logout.php" class="flex items-center gap-2 px-3 py-1.5 rounded-lg border border-gray-300 text-gray-700 hover:bg-red-50 hover:text-red-600 hover:border-red-300 transition-colors">
        <i class="fa-solid fa-right-from-bracket"></i> Salir
      </a>
    </div>
  </div>
</header>

<main class="max-w-7xl mx-auto px-4 py-6 space-y-6">

  <!-- Carga de archivos -->
  <section class="bg-white border rounded-2xl p-4">
    <div class="flex items-start gap-6 flex-wrap">
      <div class="flex-1 min-w-[260px]">
        <label class="block text-sm font-medium mb-1">CSV ADP</label>
        <input id="file-adp" type="file" accept=".csv" class="block w-full text-sm border rounded-lg p-2">
        <p class="text-xs text-gray-500 mt-1">Ej: <code>empleados_YYYY-MM-DD.csv</code></p>
      </div>
      <div class="flex-1 min-w-[260px]">
        <label class="block text-sm font-medium mb-1">CSV Buk</label>
        <input id="file-buk" type="file" accept=".csv" class="block w-full text-sm border rounded-lg p-2">
        <p class="text-xs text-gray-500 mt-1">Export de empleados desde Buk</p>
      </div>
      <div class="min-w-[220px]">
        <label class="block text-sm font-medium mb-1">Campo clave (ID)</label>
        <select id="key-select" class="w-full text-sm border rounded-lg p-2">
          <option value="">Detectar automáticamente</option>
        </select>
        <p class="text-xs text-gray-500 mt-1">Intentaremos: <code>rut</code>, <code>id</code>, <code>employee_id</code>, <code>document</code>, <code>dni</code>.</p>
      </div>
      <div class="self-end">
        <button id="btn-compare" class="px-4 py-2 rounded-lg border bg-white text-sm font-medium hover:bg-gray-50">
          <i class="fa-solid fa-arrows-rotate mr-1"></i> Comparar
        </button>
      </div>
    </div>
  </section>

  <!-- KPIs -->
  <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="bg-white border rounded-2xl p-4">
      <div class="text-sm text-gray-600">Total ADP</div>
      <div id="kpi-adp" class="text-2xl font-semibold mt-1">—</div>
      <div class="text-xs text-gray-500 mt-1">Registros en CSV ADP</div>
    </div>
    <div class="bg-white border rounded-2xl p-4">
      <div class="text-sm text-gray-600">Total Buk</div>
      <div id="kpi-buk" class="text-2xl font-semibold mt-1">—</div>
      <div class="text-xs text-gray-500 mt-1">Registros en CSV Buk</div>
    </div>
    <div class="bg-white border rounded-2xl p-4">
      <div class="text-sm text-gray-600">Solo en ADP</div>
      <div id="kpi-solo-adp" class="text-2xl font-semibold mt-1">—</div>
      <div class="text-xs text-gray-500 mt-1">No están en Buk</div>
    </div>
    <div class="bg-white border rounded-2xl p-4">
      <div class="text-sm text-gray-600">Solo en Buk</div>
      <div id="kpi-solo-buk" class="text-2xl font-semibold mt-1">—</div>
      <div class="text-xs text-gray-500 mt-1">No están en ADP</div>
    </div>
  </section>

  <!-- Tabs de resultados -->
  <section class="bg-white border rounded-2xl">
    <div class="px-4 py-3 border-b flex items-center gap-2">
      <button data-t="diff" class="tab px-3 py-1.5 rounded-lg border bg-gray-50 text-sm">Con diferencias</button>
      <button data-t="adp"  class="tab px-3 py-1.5 rounded-lg border text-sm">Solo en ADP</button>
      <button data-t="buk"  class="tab px-3 py-1.5 rounded-lg border text-sm">Solo en Buk</button>
      <button data-t="match" class="tab px-3 py-1.5 rounded-lg border text-sm">Coincidencias</button>
      <div class="ml-auto text-xs text-gray-500">*Maqueta: comparación local en tu navegador</div>
    </div>

    <!-- Paneles -->
    <div id="panel-diff" class="panel overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50">
          <tr class="text-gray-600">
            <th class="px-4 py-2 text-left">ID</th>
            <th class="px-4 py-2 text-left">Nombre (si aplica)</th>
            <th class="px-4 py-2 text-left">Campos distintos</th>
            <th class="px-4 py-2 text-left">Detalle</th>
          </tr>
        </thead>
        <tbody id="tb-diff" class="divide-y"></tbody>
      </table>
    </div>

    <div id="panel-adp" class="panel overflow-x-auto hidden">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50"><tr class="text-gray-600">
          <th class="px-4 py-2 text-left">ID</th>
          <th class="px-4 py-2 text-left">Nombre (si aplica)</th>
        </tr></thead>
        <tbody id="tb-adp" class="divide-y"></tbody>
      </table>
    </div>

    <div id="panel-buk" class="panel overflow-x-auto hidden">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50"><tr class="text-gray-600">
          <th class="px-4 py-2 text-left">ID</th>
          <th class="px-4 py-2 text-left">Nombre (si aplica)</th>
        </tr></thead>
        <tbody id="tb-buk" class="divide-y"></tbody>
      </table>
    </div>

    <div id="panel-match" class="panel overflow-x-auto hidden">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50"><tr class="text-gray-600">
          <th class="px-4 py-2 text-left">ID</th>
          <th class="px-4 py-2 text-left">Nombre (si aplica)</th>
        </tr></thead>
        <tbody id="tb-match" class="divide-y"></tbody>
      </table>
    </div>
  </section>

  <p class="text-xs text-gray-500">Tips: el sistema intenta detectar el campo clave automáticamente. Puedes elegir otro en el selector.</p>
</main>

<script>
  // ---- Helpers
  const $ = sel => document.querySelector(sel);
  const byId = id => document.getElementById(id);

  function detectKey(headers) {
    const candidates = ['rut','id','employee_id','document','dni','codigo','code'];
    const hset = headers.map(h => h.trim().toLowerCase());
    for (const c of candidates) if (hset.includes(c)) return c;
    return headers[0]; // fallback
  }

  function toMap(rows, key) {
    const map = new Map();
    rows.forEach(r => {
      const k = String((r[key] ?? '')).trim();
      if (k) map.set(k, r);
    });
    return map;
  }

  function parseCSV(file) {
    return new Promise((resolve, reject) => {
      Papa.parse(file, {
        header: true,
        skipEmptyLines: true,
        transformHeader: h => h.trim(),
        complete: res => resolve(res.data),
        error: err => reject(err)
      });
    });
  }

  function fmt(val) {
    if (val === null || val === undefined) return '';
    if (typeof val === 'object') return JSON.stringify(val);
    return String(val);
  }

  // ---- Tabs
  document.querySelectorAll('.tab').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tab').forEach(b=>b.classList.remove('bg-gray-50'));
      btn.classList.add('bg-gray-50');
      const t = btn.dataset.t;
      document.querySelectorAll('.panel').forEach(p=>p.classList.add('hidden'));
      byId('panel-'+t).classList.remove('hidden');
    });
  });

  // ---- Compare flow
  let adpRows = [], bukRows = [];

  async function compare() {
    const fADP = byId('file-adp').files[0];
    const fBUK = byId('file-buk').files[0];
    if (!fADP || !fBUK) {
      alert('Carga ambos CSV (ADP y Buk).');
      return;
    }

    adpRows = await parseCSV(fADP);
    bukRows = await parseCSV(fBUK);

    // headers y key
    const adpHeaders = Object.keys(adpRows[0] || {});
    const bukHeaders = Object.keys(bukRows[0] || {});
    let key = byId('key-select').value || detectKey(adpHeaders) || detectKey(bukHeaders);

    // poblar selector si está vacío
    const sel = byId('key-select');
    if (sel.options.length <= 1) {
      [...new Set([...adpHeaders, ...bukHeaders])].forEach(h=>{
        const opt = document.createElement('option');
        opt.value = h; opt.textContent = h;
        if (h.toLowerCase() === key.toLowerCase()) opt.selected = true;
        sel.appendChild(opt);
      });
    }

    // mapear por key
    const adpMap = toMap(adpRows, key);
    const bukMap = toMap(bukRows, key);

    // KPIs
    byId('kpi-adp').textContent = adpMap.size;
    byId('kpi-buk').textContent = bukMap.size;

    // conjuntos
    const soloADP = [];
    const soloBUK = [];
    const match = [];
    const diff = [];

    const allKeys = new Set([...adpMap.keys(), ...bukMap.keys()]);
    allKeys.forEach(k => {
      const a = adpMap.get(k);
      const b = bukMap.get(k);
      if (a && !b) soloADP.push(a);
      else if (!a && b) soloBUK.push(b);
      else if (a && b) {
        // comparar campos comunes
        const common = [...new Set([...Object.keys(a), ...Object.keys(b)])];
        const changed = [];
        common.forEach(h=>{
          const va = fmt(a[h]).trim();
          const vb = fmt(b[h]).trim();
          if (va !== vb) changed.push(h);
        });
        if (changed.length) diff.push({ id:k, a, b, changed });
        else match.push(a);
      }
    });

    byId('kpi-solo-adp').textContent = soloADP.length;
    byId('kpi-solo-buk').textContent = soloBUK.length;

    // Pintar tablas
    const getName = (row)=> row.name || row.Nombre || row.NOMBRE || row.full_name || row['primer_nombre'] || '';

    // Solo ADP
    const t1 = byId('tb-adp');
    t1.innerHTML = soloADP.map(r=>`
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-2">${fmt(r[key])}</td>
        <td class="px-4 py-2">${fmt(getName(r))}</td>
      </tr>`).join('') || `<tr><td colspan="2" class="px-4 py-4 text-center text-gray-500 text-sm">Sin diferencias</td></tr>`;

    // Solo BUK
    const t2 = byId('tb-buk');
    t2.innerHTML = soloBUK.map(r=>`
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-2">${fmt(r[key])}</td>
        <td class="px-4 py-2">${fmt(getName(r))}</td>
      </tr>`).join('') || `<tr><td colspan="2" class="px-4 py-4 text-center text-gray-500 text-sm">Sin diferencias</td></tr>`;

    // Diferencias
    const t3 = byId('tb-diff');
    t3.innerHTML = diff.map(x=>{
      const lista = x.changed.slice(0,8).map(h=>`<span class="badge bg-amber-100 text-amber-700 mr-1 mb-1 inline-block">${h}</span>`).join(' ');
      const extra = x.changed.length>8 ? ` +${x.changed.length-8} más` : '';
      // pequeño detalle de 1-2 campos
      const sample = x.changed.slice(0,3).map(h=>{
        const va = fmt(x.a[h]); const vb = fmt(x.b[h]);
        return `<div><span class="text-gray-500">${h}:</span> <span class="line-through text-red-600/70">${va || '—'}</span> → <span class="text-green-700">${vb || '—'}</span></div>`;
      }).join('');
      return `
        <tr class="align-top hover:bg-gray-50">
          <td class="px-4 py-2 whitespace-nowrap">${x.id}</td>
          <td class="px-4 py-2">${fmt(getName(x.a) || getName(x.b))}</td>
          <td class="px-4 py-2">${lista}${extra}</td>
          <td class="px-4 py-2 text-xs text-gray-700">${sample || '—'}</td>
        </tr>`;
    }).join('') || `<tr><td colspan="4" class="px-4 py-4 text-center text-gray-500 text-sm">Sin diferencias</td></tr>`;

    // Coincidencias
    const t4 = byId('tb-match');
    t4.innerHTML = match.slice(0,200).map(r=>`
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-2">${fmt(r[key])}</td>
        <td class="px-4 py-2">${fmt(getName(r))}</td>
      </tr>`).join('') || `<tr><td colspan="2" class="px-4 py-4 text-center text-gray-500 text-sm">Sin coincidencias</td></tr>`;
  }

  byId('btn-compare').addEventListener('click', compare);
</script>

</body>
</html>

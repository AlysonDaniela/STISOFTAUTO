<?php
require_once __DIR__ . '/includes/auth.php';
require_auth();
$user = current_user();
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<body class="bg-gray-50">
  <div class="min-h-screen grid grid-cols-12">
    <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
      <?php include __DIR__ . '/partials/sidebar.php'; ?>
    </div>
    <div class="col-span-12 md:col-span-9 lg:col-span-10">
      <?php include __DIR__ . '/partials/topbar.php'; ?>
      <main class="max-w-7xl mx-auto p-6 space-y-6">
        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
<?php
// documentos.php — Maqueta sin BD
// Si existen carpetas /storage/sftp y /storage/liquidaciones, las lista.
// Sino, usa datos mock.

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
    // más recientes primero
    usort($items, fn($a,$b)=> $b['mtime'] <=> $a['mtime']);
    return $items;
  }
  return $mock;
}

// --------- MOCK DATA ---------
$mock_sftp = [
  ['name'=>'empleados_2025-09-19.csv','size'=> 245_123,'mtime'=> strtotime('2025-09-19 03:24'),'path'=>'#'],
  ['name'=>'contratos_2025-09-19.csv','size'=> 180_541,'mtime'=> strtotime('2025-09-19 03:24'),'path'=>'#'],
  ['name'=>'cargos_2025-09-18.csv','size'=> 120_880,'mtime'=> strtotime('2025-09-18 22:10'),'path'=>'#'],
];
$mock_liqs = [
  ['name'=>'12345678-9_2025-08_liq.pdf','size'=> 98_331,'mtime'=> strtotime('2025-09-01 11:00'),'path'=>'#'],
  ['name'=>'11222333-4_2025-08_liq.pdf','size'=> 102_004,'mtime'=> strtotime('2025-09-01 11:03'),'path'=>'#'],
  ['name'=>'12345678-9_2025-07_liq.pdf','size'=> 96_552,'mtime'=> strtotime('2025-08-01 10:41'),'path'=>'#'],
];

// --------- DATASETS (dir o mock) ---------
$sftp_files = list_files_or_mock(__DIR__.'/storage/sftp', $mock_sftp);
$liq_files  = list_files_or_mock(__DIR__.'/storage/liquidaciones', $mock_liqs);

// helper
function human_size($bytes) {
  $u = ['B','KB','MB','GB']; $i=0;
  while ($bytes>=1024 && $i<count($u)-1){ $bytes/=1024; $i++; }
  return number_format($bytes, $bytes<10 && $i>0 ? 1 : 0).' '.$u[$i];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Documentos — STI SOFT</title>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Font Awesome (para iconos) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="bg-gray-50 text-gray-900">

<!-- Topbar (simple) -->
<header class="sticky top-0 z-10 bg-white/90 backdrop-blur shadow-sm border-b border-gray-200">
  <div class="max-w-7xl mx-auto px-4 h-[72px] flex items-center justify-between">
    <h1 class="text-lg font-semibold leading-none">Documentos</h1>
    <div class="flex items-center gap-4">
      <a href="/home.php" class="text-sm text-gray-600 hover:text-gray-900">Volver a Inicio</a>
      <a href="/logout.php"
         class="flex items-center gap-2 text-sm font-medium px-3 py-1.5 rounded-lg 
                border border-gray-300 text-gray-700 
                hover:bg-red-50 hover:text-red-600 hover:border-red-300
                transition-colors">
        <i class="fa-solid fa-right-from-bracket"></i> Salir
      </a>
    </div>
  </div>
</header>

<div class="max-w-7xl mx-auto px-4 py-6 space-y-6">

  <!-- Tabs -->
  <div class="flex items-center gap-2">
    <button data-tab="sftp" class="tab-btn px-4 py-2 rounded-xl border bg-white text-sm font-medium">SFTP / Documentos ADP</button>
    <button data-tab="liq"  class="tab-btn px-4 py-2 rounded-xl border text-sm font-medium hover:bg-white">Liquidaciones</button>
  </div>

  <!-- Barra de acciones / filtros (maqueta) -->
  <div class="flex flex-wrap items-center gap-3">
    <div class="relative">
      <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
      <input type="text" id="q" placeholder="Buscar archivo..."
             class="pl-9 pr-3 py-2 bg-white border rounded-xl text-sm w-64">
    </div>
    <select id="tipo" class="bg-white border rounded-xl text-sm py-2 px-3">
      <option value="">Tipo (todos)</option>
      <option value="csv">CSV</option>
      <option value="pdf">PDF</option>
    </select>
    <button class="px-3 py-2 text-sm rounded-xl border hover:bg-white"><i class="fa-regular fa-calendar"></i> Rango (maqueta)</button>
    <div class="ml-auto flex items-center gap-2">
      <button class="px-3 py-2 text-sm rounded-xl border hover:bg-white"><i class="fa-solid fa-upload"></i> Subir</button>
      <button class="px-3 py-2 text-sm rounded-xl border hover:bg-white"><i class="fa-solid fa-rotate-right"></i> Refrescar</button>
    </div>
  </div>

  <!-- Panel SFTP -->
  <section id="panel-sftp" class="bg-white border rounded-2xl overflow-hidden">
    <div class="px-4 py-3 border-b font-semibold">SFTP / Documentos ADP</div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50">
          <tr class="text-gray-600">
            <th class="px-4 py-2 text-left">Archivo</th>
            <th class="px-4 py-2 text-left">Fecha</th>
            <th class="px-4 py-2 text-left">Tamaño</th>
            <th class="px-4 py-2 text-left">Estado</th>
            <th class="px-4 py-2 text-left">Acciones</th>
          </tr>
        </thead>
        <tbody class="divide-y">
        <?php foreach ($sftp_files as $f): 
          $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
          $badge = '<span class="px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-700">Respaldado</span>';
        ?>
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-2">
              <div class="flex items-center gap-3">
                <i class="fa-solid fa-file<?php echo $ext==='csv' ? '-csv' : '-lines'; ?> text-gray-500"></i>
                <span class="font-medium"><?php echo htmlspecialchars($f['name']); ?></span>
              </div>
            </td>
            <td class="px-4 py-2"><?php echo date('Y-m-d H:i', $f['mtime']); ?></td>
            <td class="px-4 py-2"><?php echo human_size($f['size']); ?></td>
            <td class="px-4 py-2"><?php echo $badge; ?></td>
            <td class="px-4 py-2">
              <div class="flex items-center gap-2">
                <button class="px-2 py-1 rounded-lg border hover:bg-white" title="Ver"><i class="fa-regular fa-eye"></i></button>
                <button class="px-2 py-1 rounded-lg border hover:bg-white" title="Descargar"><i class="fa-solid fa-download"></i></button>
                <button class="px-2 py-1 rounded-lg border hover:bg-white" title="Enviar a Buk (maqueta)"><i class="fa-solid fa-paper-plane"></i></button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Panel Liquidaciones -->
  <section id="panel-liq" class="bg-white border rounded-2xl overflow-hidden hidden">
    <div class="px-4 py-3 border-b font-semibold">Liquidaciones de trabajadores</div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50">
          <tr class="text-gray-600">
            <th class="px-4 py-2 text-left">Archivo</th>
            <th class="px-4 py-2 text-left">Fecha</th>
            <th class="px-4 py-2 text-left">Tamaño</th>
            <th class="px-4 py-2 text-left">Estado</th>
            <th class="px-4 py-2 text-left">Acciones</th>
          </tr>
        </thead>
        <tbody class="divide-y">
        <?php foreach ($liq_files as $f):
          $badge = '<span class="px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">Pendiente de enviar</span>';
        ?>
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-2">
              <div class="flex items-center gap-3">
                <i class="fa-regular fa-file-pdf text-gray-500"></i>
                <span class="font-medium"><?php echo htmlspecialchars($f['name']); ?></span>
              </div>
            </td>
            <td class="px-4 py-2"><?php echo date('Y-m-d H:i', $f['mtime']); ?></td>
            <td class="px-4 py-2"><?php echo human_size($f['size']); ?></td>
            <td class="px-4 py-2"><?php echo $badge; ?></td>
            <td class="px-4 py-2">
              <div class="flex items-center gap-2">
                <button class="px-2 py-1 rounded-lg border hover:bg-white" title="Ver"><i class="fa-regular fa-eye"></i></button>
                <button class="px-2 py-1 rounded-lg border hover:bg-white" title="Descargar"><i class="fa-solid fa-download"></i></button>
                <button class="px-2 py-1 rounded-lg border hover:bg-white" title="Enviar a Buk (maqueta)"><i class="fa-solid fa-paper-plane"></i></button>
                <button class="px-2 py-1 rounded-lg border hover:bg-white" title="Eliminar (maqueta)"><i class="fa-regular fa-trash-can"></i></button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Nota de ayuda -->
  <div class="text-xs text-gray-500">
    * Esta es una maqueta. Las acciones no realizan operaciones reales.  
    Si creas <code>/storage/sftp</code> y <code>/storage/liquidaciones</code>, se listarán archivos reales automáticamente.
  </div>

</div>

<script>
// Tabs (maqueta)
const tabs = document.querySelectorAll('.tab-btn');
const panels = { sftp: document.getElementById('panel-sftp'), liq: document.getElementById('panel-liq') };
tabs.forEach(btn=>{
  btn.addEventListener('click', ()=>{
    tabs.forEach(b=> b.classList.remove('bg-white','font-medium'));
    btn.classList.add('bg-white','font-medium');
    const t = btn.dataset.tab;
    panels.sftp.classList.toggle('hidden', t!=='sftp');
    panels.liq.classList.toggle('hidden',  t!=='liq');
  });
});

// Filtros (maqueta visual)
document.getElementById('q').addEventListener('input', (e)=>{
  // solo UI; aquí iría filtro real luego
});
</script>

</body>
</html>

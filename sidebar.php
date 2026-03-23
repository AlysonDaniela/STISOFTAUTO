<?php
header('Content-Type: text/html; charset=utf-8');
$user = current_user();

// ===== Fix íconos (FontAwesome) =====
// A veces “desaparecen” porque en algunas páginas no se carga el CSS de FontAwesome.
// Esto lo fuerza a cargarse 1 vez aunque la vista no lo incluya.
if (!defined('FA_CSS_LOADED')) {
  define('FA_CSS_LOADED', true);
  echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer" />';
}

// Detectar ruta actual
$reqUri   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$currPath = rtrim($reqUri, '/');
if ($currPath === '') $currPath = '/';

// Helper para nav items
function nav_item(
  string $href,
  string $label,
  string $iconClass = null,
  array $matchPaths = [],
  bool $isChild = false
) {
  global $currPath;

  $hrefNorm = rtrim($href, '/');
  if ($hrefNorm === '') $hrefNorm = '/';

  $targets  = array_filter(array_unique(array_merge([$hrefNorm], $matchPaths)));
  $isActive = in_array($currPath, $targets, true);

  $base   = 'flex items-center gap-3 px-3 py-2 rounded-lg transition-colors';
  $idle   = 'text-gray-700 hover:bg-gray-100';
  $active = 'bg-gray-200 text-blue-600 font-semibold';

  // Estilo extra para submenús
  $childClasses = $isChild ? ' ml-6 text-sm' : ' font-medium';

  $classes = $base . ' ' . ($isActive ? $active : $idle) . $childClasses;
  $aria    = $isActive ? ' aria-current="page"' : '';
  $icon    = $iconClass ? '<i class="'.htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8').'"></i>' : '';

  echo '<a href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'" class="'.$classes.'"'.$aria.'>';
  if ($icon) echo $icon;
  echo '<span>'.$label.'</span></a>';
}

function nav_dropdown(
  string $label,
  string $iconClass = null,
  array $children = [],
  array $matchPaths = []
) {
  global $currPath;

  $isActive = in_array($currPath, $matchPaths, true);
  $isPriority = in_array($label, ['Sincronización', 'Liquidaciones'], true);
  $isOpen = $isActive || $isPriority;

  $summaryBase = 'flex items-center justify-between gap-3 px-3 py-2 rounded-lg transition-colors cursor-pointer';
  $summaryIdle = 'text-gray-700 hover:bg-gray-100';
  $summaryActive = 'bg-gray-200 text-blue-600 font-semibold';
  $priorityStyles = [
    'Sincronización' => [
      'base' => 'border border-[#9db7e5] bg-[#eaf2ff] text-[#1f4c97] font-semibold shadow-sm hover:bg-[#dfeaff]',
      'active' => 'bg-[#dfeaff] text-[#1f4c97] border border-[#9db7e5] shadow-sm font-semibold',
      'line' => 'border-l-[#1f4c97]',
      'panel' => ' rounded-xl border border-[#c9d8f0] bg-[#f5f9ff] p-2.5 shadow-sm',
    ],
    'Liquidaciones' => [
      'base' => 'border border-[#9fd7be] bg-[#eafbf1] text-[#166534] font-semibold shadow-sm hover:bg-[#dbf6e7]',
      'active' => 'bg-[#dbf6e7] text-[#166534] border border-[#9fd7be] shadow-sm font-semibold',
      'line' => 'border-l-[#166534]',
      'panel' => ' rounded-xl border border-[#cdebd9] bg-[#f3fcf6] p-2.5 shadow-sm',
    ],
  ];
  $priority = $priorityStyles[$label] ?? null;

  if ($isPriority) {
    $summaryClass = $summaryBase . ' border-l-4 ' . ($priority['line'] ?? 'border-l-[#1f4c97]') . ' ' . ($isActive ? ($priority['active'] ?? '') : ($priority['base'] ?? ''));
  } else {
    $summaryClass = $summaryBase . ' ' . ($isActive ? $summaryActive : $summaryIdle);
  }

  $icon = $iconClass ? '<i class="'.htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8').'"></i>' : '';
  $openAttr = $isOpen ? ' open' : '';

  echo '<details class="select-none"'.$openAttr.'>';
  echo '<summary class="'.$summaryClass.' list-none">';
  echo '<span class="flex items-center gap-3 min-w-0">'.$icon.'<span>'.$label.'</span></span>';
  echo '<i class="fa-solid fa-chevron-down text-xs opacity-70"></i>';
  echo '</summary>';

  echo '<div class="mt-1 space-y-1'.($isPriority ? ($priority['panel'] ?? ' rounded-xl border border-[#c9d8f0] bg-[#f5f9ff] p-2.5 shadow-sm') : '').'">';
  foreach ($children as $child) {
    // $child: [href, label, iconClass, matchPaths]
    nav_item($child[0], $child[1], $child[2] ?? null, $child[3] ?? [], true);
  }
  echo '</div>';

  echo '</details>';
}

// Helper para títulos de sección
function nav_section(string $label) {
  echo '<div class="mt-4 mb-1 px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">'
      .htmlspecialchars($label, ENT_QUOTES, 'UTF-8').
      '</div>';
}
?>

<aside class="bg-white/95 border-r border-gray-200 h-screen sticky top-0 flex flex-col backdrop-blur">
  <!-- Logo alineado a la izquierda -->
  <div class="flex items-center p-4 border-b">
    <img src="/assets/logo.png" alt="STI SOFT" class="h-10" />
  </div>

  <nav class="flex-1 overflow-y-auto p-3 space-y-1">
    <?php
      // Inicio
      nav_item('/home.php', 'Inicio', 'fa-solid fa-house', ['/', '/home.php']);

      // ===== COLABORADORES =====
      nav_section('Colaboradores');

      // EMPLEADOS (principal) - SIN “Bajas”
      nav_dropdown(
        'Empleados',
        'fa-solid fa-users',
        [
          ['/empleados', 'Listado', 'fa-solid fa-list', ['/empleados']],
          ['/documentos/upload_doc.php', 'Enviar Documento', 'fa-solid fa-paper-plane', ['/documentos/upload_doc.php']],
        ],
        ['/empleados', '/documentos/upload_doc.php']
      );

      // NUEVO NIVEL 1: BAJAS (principal)
      nav_dropdown(
        'Bajas',
        'fa-solid fa-user-slash',
        [
          ['/bajas/index.php', 'Listado de Bajas', 'fa-solid fa-clipboard-list', ['/bajas', '/bajas/index.php']],
          ['/bajas/manual.php', 'Manual', 'fa-solid fa-pen-to-square', ['/bajas/manual.php']],
        ],
        ['/bajas', '/bajas/index.php', '/bajas/manual.php']
      );

      // JERARQUÍAS (principal)
      nav_dropdown(
        'Jerarquías',
        'fa-solid fa-diagram-project',
        [
          ['/division', 'Áreas', 'fa-solid fa-sitemap', ['/division', '/divisiones']],
          ['/cargos', 'Cargos', 'fa-solid fa-id-badge', ['/cargos']],
        ],
        ['/division', '/divisiones', '/cargos']
      );

      // ===== VACACIONES =====
      nav_section('Vacaciones');
      nav_item('/vacaciones', 'Vacaciones', 'fa-solid fa-umbrella-beach', ['/vacaciones']);

      // ===== LIQUIDACIONES =====
      nav_section('Liquidaciones');
      nav_dropdown(
        'Liquidaciones',
        'fa-solid fa-file-invoice-dollar',
        [
          ['/rliquid', 'Enviar Liquidaciones', 'fa-solid fa-paper-plane', ['/rliquid']],
          ['/rliquid/reportes_lote.php', 'Reportes Liquidaciones', 'fa-solid fa-chart-line', ['/rliquid/reportes_lote.php']],
          ['/rliquid/ver_pdf.php', 'Generar PDF', 'fa-solid fa-file-pdf', ['/rliquid/ver_pdf.php']],
        ],
        ['/rliquid', '/rliquid/ver_pdf.php', '/rliquid/reportes_lote.php']
      );

      // ===== CONFIG =====
      nav_section('Config');
      nav_dropdown(
        'Sincronización',
        'fa-solid fa-cogs',
        [
          ['/sync/configuracion_sincronizacion.php', 'Configurar Sync', 'fa-solid fa-sliders', ['/sync/configuracion_sincronizacion.php', '/sync']],
          ['/sync/sync_detalles.php', 'Sync Detalles', 'fa-solid fa-chart-line', ['/sync/sync_detalles.php']],
          ['/sync/reportes_sync.php', 'Reportes Sync', 'fa-regular fa-file-lines', ['/sync/reportes_sync.php']],
          ['/sync/errores_cola.php', 'Errores de Cola', 'fa-solid fa-triangle-exclamation', ['/sync/errores_cola.php']],
          ['/sync/email_config.php', 'Configurar Correos', 'fa-solid fa-envelope', ['/sync/email_config.php']],
        ],
        ['/sync', '/sync/configuracion_sincronizacion.php', '/sync/sync_detalles.php', '/sync/reportes_sync.php', '/sync/errores_cola.php', '/sync/email_config.php']
      );
    ?>
  </nav>

  <div class="p-4 border-t text-sm text-gray-700">
    <div class="font-medium">
      <?= htmlspecialchars($user['name'] ?? 'Sin sesión', ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <div class="text-gray-500">
      Rol: <?= htmlspecialchars($user['role'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
    </div>
  </div>
</aside>

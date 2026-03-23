<?php
$current = $_SERVER['REQUEST_URI'] ?? '';
function is_active($path){ global $current; return str_contains($current, $path) ? 'bg-gray-100 text-gray-900' : 'text-gray-700'; }
?>
<aside class="bg-white/95 border-r border-gray-200 h-screen sticky top-0 flex flex-col backdrop-blur w-64">
  <div class="flex items-center justify-center p-4 border-b">
    <img src="/assets/sti-soft-logo.png" alt="STI SOFT" class="h-10" />
  </div>
  <nav class="flex-1 overflow-y-auto p-3 space-y-1">
    <a href="/sync/configuracion_sincronizacion.php" class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-100 <?php echo is_active('/sync/configuracion_sincronizacion.php'); ?>">
      <i class="fa-solid fa-rotate"></i> Sincronización
    </a>
  </nav>
  <div class="p-3 border-t text-xs text-gray-500">
    © <?php echo date('Y'); ?> STI Soft
  </div>
</aside>

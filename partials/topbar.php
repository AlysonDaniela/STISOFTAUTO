<?php $user = current_user(); ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>STI SOFT</title>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="bg-gray-50">

<?php
// Detectar ruta actual
$current_path = $_SERVER['PHP_SELF'];

// Si es carpeta/index.php → mostrar el nombre de la carpeta
if (preg_match('#/([^/]+)/index\.php$#', $current_path, $matches)) {
    $page_title = ucfirst($matches[1]);
} else {
    // Si no, mostrar el nombre del archivo sin extensión
    $page_title = ucfirst(pathinfo(basename($current_path), PATHINFO_FILENAME));
}
?>

<header class="sticky top-0 z-10 bg-white/90 backdrop-blur shadow-sm border-b border-gray-200">
  <div class="max-w-7xl mx-auto px-4 h-[72px] flex items-center justify-between">
    
    <!-- Título dinámico -->
    <h1 class="text-lg font-semibold leading-none text-gray-800 tracking-tight">
      <?php echo htmlspecialchars($page_title); ?>
    </h1>

    <!-- Usuario + Salir -->
    <div class="flex items-center gap-4">
      <button id="themeToggleBtn" type="button" class="inline-flex items-center gap-2 text-sm px-3 py-1.5 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
        <i id="themeToggleIcon" class="fa-solid fa-moon"></i>
        <span id="themeToggleLabel">Oscuro</span>
      </button>
      <span class="text-gray-700 text-sm font-medium">
        Hola, <?php echo htmlspecialchars($user['name'] ?? 'Usuario'); ?>
      </span>
      <a href="/logout.php" class="flex items-center gap-2 text-sm text-gray-600 hover:text-red-600">
        <i class="fa-solid fa-right-from-bracket"></i> Salir
      </a>
    </div>
  </div>
</header>

<script>
(function () {
  const btn = document.getElementById('themeToggleBtn');
  const icon = document.getElementById('themeToggleIcon');
  const label = document.getElementById('themeToggleLabel');
  if (!btn || !icon || !label) return;

  function paint() {
    const dark = document.documentElement.classList.contains('dark');
    icon.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
    label.textContent = dark ? 'Claro' : 'Oscuro';
  }

  btn.addEventListener('click', function () {
    const root = document.documentElement;
    const isDark = root.classList.toggle('dark');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    paint();
  });

  paint();
})();
</script>

<?php $app = require __DIR__ . '/../includes/config.php'; ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($app['app_name']) ?></title>
  <link rel="icon" href="/assets/sti-soft-isotipo.png" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkfAm4l1zN6Ee6xN8i1I1JQ1hYkqYVQ5K5j7R5K3B+8GkP2a1w8gJ4YfA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config = { darkMode: 'class' }</script>
  
<script>
  (function () {
    const saved = localStorage.getItem('theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    if ((saved === 'dark') || (!saved && prefersDark)) {
      document.documentElement.classList.add('dark');
    }
  })();
</script>

  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    html, body { font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }

    /* Dark mode global overrides for current Tailwind utility usage */
    html.dark { color-scheme: dark; }
    html.dark body { background-color: #0b1220 !important; color: #e5e7eb !important; }

    html.dark .bg-white,
    html.dark .bg-white\/95,
    html.dark .bg-white\/90 { background-color: #111827 !important; }

    html.dark .bg-gray-50 { background-color: #0f172a !important; }
    html.dark .bg-gray-100 { background-color: #1f2937 !important; }
    html.dark .border,
    html.dark .border-gray-100,
    html.dark .border-gray-200 { border-color: #374151 !important; }

    html.dark .text-gray-400 { color: #9ca3af !important; }
    html.dark .text-gray-500 { color: #9ca3af !important; }
    html.dark .text-gray-600 { color: #d1d5db !important; }
    html.dark .text-gray-700 { color: #e5e7eb !important; }
    html.dark .text-gray-800 { color: #f3f4f6 !important; }

    html.dark .shadow-sm,
    html.dark .shadow { box-shadow: none !important; }

    html.dark table thead tr { border-color: #374151 !important; }
    html.dark table tbody tr { border-color: #374151 !important; }
    html.dark code, html.dark pre { background-color: #0f172a !important; color: #e5e7eb !important; }
  </style>
</head>

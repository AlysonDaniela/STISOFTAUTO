<?php
require_once __DIR__ . '/includes/auth.php';
$errors = [];
$token = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Tu sesión expiró. Recarga la página e intenta nuevamente.';
  }
  $email = trim($_POST['email'] ?? '');
  $password = trim($_POST['password'] ?? '');
  if (auth_login_locked()) {
    $mins = (int)ceil(auth_login_lock_remaining() / 60);
    $errors[] = "Cuenta temporalmente bloqueada por intentos fallidos. Intenta en {$mins} minuto(s).";
  } elseif (!$email || !$password) {
    $errors[] = 'Ingresa tu correo y contraseña.';
  } elseif (attempt_login($email, $password)) {
    header('Location: /home.php');
    exit;
  } else {
    $errors[] = 'Credenciales inválidas.';
  }
}
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<body class="min-h-dvh">
  <style>
    /* Fondo a pantalla completa con fallback a degradado si no carga la imagen */
    body {
      background:
        url('/assets/San-Antonio-Puerto-056-scaled.jpg') center / cover no-repeat fixed,
        linear-gradient(180deg, #eef1f6 0%, #e8e2e0 100%);
    }
    @media (max-width: 1024px) {
      /* En móviles algunos navegadores ignoran background-attachment: fixed */
      body { background-attachment: scroll; }
    }
  </style>

  <!-- Overlay translúcido para dar el efecto “vidrio esmerilado” -->
<div class="min-h-dvh bg-white/30">

    <main class="flex items-center justify-center min-h-dvh px-4">
      <div class="w-full max-w-md">
        <div class="bg-white/80 rounded-3xl shadow-2xl border border-gray-200/40 p-8">

          <div class="flex items-center gap-3 mb-6">
<img src="/assets/sti-soft-logo.png" class="h-16" alt="STI SOFT">
          </div>

          <?php if ($errors): ?>
            <div class="mb-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg p-3">
              <?= implode('<br>', array_map('htmlspecialchars', $errors)); ?>
            </div>
          <?php endif; ?>

<form method="post" class="space-y-4">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Correo</label>
    <input type="email" name="email" required
           class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900
                  px-4 py-3"
           placeholder="admin@stisoft.local" />
  </div>
  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label>
    <input type="password" name="password" required
           class="w-full rounded-lg border-gray-300 focus:border-gray-900 focus:ring-gray-900
                  px-4 py-3"
           placeholder="••••••••" />
  </div>
  <button class="w-full py-3 rounded-lg bg-gray-900 text-white font-semibold hover:bg-black transition">
    Ingresar
  </button>
</form>
          <p class="text-center text-xs text-gray-700 drop-shadow mt-4">© <?= date('Y'); ?> STI SOFT - Integración ADP & Buk</p>
        </div>

     
      </div>
    </main>
  </div>
</body>
</html>

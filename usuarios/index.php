<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$user = current_user();
$csrf = csrf_token();

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function h($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function role_options(string $current): string {
  $role = strtolower($current) === 'admin' ? 'admin' : 'user';
  $adminSel = $role === 'admin' ? 'selected' : '';
  $userSel = $role === 'user' ? 'selected' : '';
  return '<option value="admin" ' . $adminSel . '>Admin</option><option value="user" ' . $userSel . '>Usuario</option>';
}

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Token inválido, recarga la pantalla.';
    header('Location: /usuarios/index.php');
    exit;
  }

  if ($action === 'create') {
    $result = create_user(
      (string)($_POST['email'] ?? ''),
      (string)($_POST['name'] ?? ''),
      (string)($_POST['password'] ?? ''),
      (string)($_POST['role'] ?? 'user')
    );
    $_SESSION[$result['ok'] ? 'flash_ok' : 'flash_error'] = $result['message'];
    header('Location: /usuarios/index.php');
    exit;
  }

  if ($action === 'update_profile') {
    $id = (int)($_POST['user_id'] ?? 0);
    $active = isset($_POST['active']) && $_POST['active'] === '1';
    $result = update_user_profile(
      $id,
      (string)($_POST['email'] ?? ''),
      (string)($_POST['name'] ?? ''),
      (string)($_POST['role'] ?? 'user'),
      $active
    );
    $_SESSION[$result['ok'] ? 'flash_ok' : 'flash_error'] = $result['message'];
    header('Location: /usuarios/index.php');
    exit;
  }

  if ($action === 'update_password') {
    $id = (int)($_POST['user_id'] ?? 0);
    $result = update_user_password($id, (string)($_POST['password'] ?? ''));
    $_SESSION[$result['ok'] ? 'flash_ok' : 'flash_error'] = $result['message'];
    header('Location: /usuarios/index.php');
    exit;
  }

  if ($action === 'delete') {
    $id = (int)($_POST['user_id'] ?? 0);
    $actorId = (int)($user['id'] ?? 0);
    $result = delete_user($id, $actorId);
    $_SESSION[$result['ok'] ? 'flash_ok' : 'flash_error'] = $result['message'];
    header('Location: /usuarios/index.php');
    exit;
  }
}

$flashOk = $_SESSION['flash_ok'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$users = all_users(true);
$headPath = __DIR__ . '/../partials/head.php';
$sidebarPath = __DIR__ . '/../partials/sidebar.php';
$topbarPath = __DIR__ . '/../partials/topbar.php';
?>
<?php include $headPath; ?>
<body class="bg-gray-50">
  <div class="min-h-screen grid grid-cols-12">
    <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
      <?php include $sidebarPath; ?>
    </div>
    <div class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col">
      <?php include $topbarPath; ?>

      <main class="w-full max-w-none p-4 md:p-6 space-y-6">
        <section class="rounded-3xl border border-slate-200 bg-gradient-to-r from-slate-900 via-cyan-900 to-sky-800 text-white p-6 shadow-sm">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <div class="text-xs uppercase tracking-[0.18em] text-cyan-100/80">Administración</div>
              <h1 class="text-2xl md:text-3xl font-semibold mt-2 flex items-center gap-3">
                <i class="fa-solid fa-user-gear"></i>
                Administración de Usuarios
              </h1>
              <p class="text-sm text-cyan-50/90 mt-3 max-w-3xl">Gestiona accesos, perfiles, contraseñas y estado de cada cuenta desde una sola pantalla con una vista más clara y ordenada.</p>
            </div>
            <div class="grid grid-cols-2 gap-3 text-sm min-w-[220px]">
              <div class="rounded-2xl bg-white/10 border border-white/10 px-4 py-3">
                <div class="text-cyan-100/70 text-xs">Total usuarios</div>
                <div class="mt-1 text-2xl font-semibold"><?= count($users) ?></div>
              </div>
              <div class="rounded-2xl bg-white/10 border border-white/10 px-4 py-3">
                <div class="text-cyan-100/70 text-xs">Activos</div>
                <div class="mt-1 text-2xl font-semibold"><?= count(array_filter($users, static fn($u) => !empty($u['active']))) ?></div>
              </div>
            </div>
          </div>
        </section>

        <?php if ($flashOk): ?>
          <div class="bg-green-50 border border-green-200 text-green-800 rounded-2xl px-4 py-3 text-sm"><?= h($flashOk) ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
          <div class="bg-red-50 border border-red-200 text-red-800 rounded-2xl px-4 py-3 text-sm"><?= h($flashError) ?></div>
        <?php endif; ?>

        <section class="grid grid-cols-1 xl:grid-cols-[0.8fr_1.2fr] gap-6">
          <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
            <div class="mb-5">
              <h2 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
                <i class="fa-solid fa-user-plus text-cyan-700"></i>
                Crear Usuario
              </h2>
              <p class="text-sm text-slate-500 mt-1">Agrega un nuevo usuario y define su nivel de acceso.</p>
            </div>

            <form method="post" class="space-y-4">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="create">

              <label class="block">
                <span class="block text-sm font-medium text-slate-700 mb-2">Nombre</span>
                <input type="text" name="name" required placeholder="Nombre completo"
                       class="w-full rounded-2xl border border-slate-200 focus:border-cyan-700 focus:ring-cyan-700 px-4 py-3">
              </label>

              <label class="block">
                <span class="block text-sm font-medium text-slate-700 mb-2">Correo</span>
                <input type="email" name="email" required placeholder="correo@empresa.cl"
                       class="w-full rounded-2xl border border-slate-200 focus:border-cyan-700 focus:ring-cyan-700 px-4 py-3">
              </label>

              <label class="block">
                <span class="block text-sm font-medium text-slate-700 mb-2">Contraseña</span>
                <input type="password" name="password" minlength="8" required placeholder="Mínimo 8 caracteres"
                       class="w-full rounded-2xl border border-slate-200 focus:border-cyan-700 focus:ring-cyan-700 px-4 py-3">
              </label>

              <label class="block">
                <span class="block text-sm font-medium text-slate-700 mb-2">Rol</span>
                <select name="role" class="w-full rounded-2xl border border-slate-200 focus:border-cyan-700 focus:ring-cyan-700 px-4 py-3">
                  <option value="user">Usuario</option>
                  <option value="admin">Admin</option>
                </select>
              </label>

              <div class="pt-2">
                <button class="inline-flex items-center gap-2 rounded-2xl bg-cyan-700 text-white px-5 py-3 font-semibold hover:bg-cyan-800 transition">
                  <i class="fa-solid fa-user-plus"></i> Crear usuario
                </button>
              </div>
            </form>
          </div>

          <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
              <div>
                <h2 class="text-lg font-semibold text-slate-900">Usuarios Registrados</h2>
                <p class="text-sm text-slate-500 mt-1">Edita perfil, rol, estado, contraseña o elimina cuentas existentes.</p>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                Total visible: <b class="text-slate-900"><?= count($users) ?></b>
              </div>
            </div>

            <div class="space-y-4">
              <?php foreach ($users as $row): ?>
                <div class="rounded-2xl border border-slate-200 p-5 bg-slate-50/60">
                  <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div>
                      <div class="font-semibold text-slate-900 break-words"><?= h($row['name']) ?></div>
                      <div class="text-sm text-slate-500 mt-1 break-all"><?= h($row['email']) ?></div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                      <span class="px-3 py-1 rounded-xl border <?= $row['active'] ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-600 border-slate-200' ?>"><?= $row['active'] ? 'Activo' : 'Inactivo' ?></span>
                      <span class="px-3 py-1 rounded-xl border bg-cyan-50 text-cyan-700 border-cyan-200"><?= h($row['role_label']) ?></span>
                    </div>
                  </div>

                  <form method="post" class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                    <label class="block">
                      <span class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">Nombre</span>
                      <input type="text" name="name" value="<?= h($row['name']) ?>" required class="w-full rounded-2xl border border-slate-200 px-4 py-3">
                    </label>
                    <label class="block">
                      <span class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">Correo</span>
                      <input type="email" name="email" value="<?= h($row['email']) ?>" required class="w-full rounded-2xl border border-slate-200 px-4 py-3">
                    </label>
                    <label class="block">
                      <span class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">Rol</span>
                      <select name="role" class="w-full rounded-2xl border border-slate-200 px-4 py-3"><?= role_options((string)$row['role']) ?></select>
                    </label>
                    <label class="block">
                      <span class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 mb-2">Estado</span>
                      <select name="active" class="w-full rounded-2xl border border-slate-200 px-4 py-3">
                        <option value="1" <?= $row['active'] ? 'selected' : '' ?>>Activo</option>
                        <option value="0" <?= !$row['active'] ? 'selected' : '' ?>>Inactivo</option>
                      </select>
                    </label>
                    <div class="lg:col-span-2 flex justify-end">
                      <button class="rounded-2xl bg-slate-900 text-white px-4 py-3 font-semibold hover:bg-black transition">Guardar perfil</button>
                    </div>
                  </form>

                  <div class="grid grid-cols-1 xl:grid-cols-[1fr_auto] gap-3">
                    <form method="post" class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-3">
                      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="update_password">
                      <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                      <input type="password" name="password" minlength="8" required placeholder="Nueva contraseña"
                             class="w-full rounded-2xl border border-slate-200 px-4 py-3">
                      <button class="rounded-2xl bg-indigo-600 text-white px-4 py-3 font-semibold hover:bg-indigo-700 transition">Cambiar contraseña</button>
                    </form>

                    <form method="post" class="xl:text-right" onsubmit="return confirm('¿Eliminar este usuario?');">
                      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                      <button class="rounded-2xl bg-red-600 text-white px-4 py-3 font-semibold hover:bg-red-700 transition">Eliminar</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      </main>
    </div>
  </div>
</body>
</html>

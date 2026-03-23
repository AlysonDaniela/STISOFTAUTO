<?php
if (session_status() === PHP_SESSION_NONE) {
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  if (!headers_sent()) {
    session_set_cookie_params([
      'lifetime' => 0,
      'path' => '/',
      'secure' => $isHttps,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
  session_start();
}

function auth_users_file_path(): string {
  return __DIR__ . '/../sync/storage/sync/users.json';
}

function auth_default_seed_users(): array {
  static $seed = null;
  if ($seed !== null) return $seed;
  $config = require __DIR__ . '/config.php';
  $rows = is_array($config['auth_seed_users'] ?? null) ? $config['auth_seed_users'] : [];
  $now = date('c');
  $out = [];
  $id = 1;
  foreach ($rows as $row) {
    $email = strtolower(trim((string)($row['email'] ?? '')));
    $name = trim((string)($row['name'] ?? ''));
    $role = strtolower(trim((string)($row['role'] ?? 'user')));
    $passwordHash = trim((string)($row['password_hash'] ?? ''));
    if ($email === '' || $name === '' || $passwordHash === '') continue;
    $out[] = [
      'id' => $id++,
      'email' => $email,
      'name' => $name,
      'role' => ($role === 'admin') ? 'admin' : 'user',
      'active' => isset($row['active']) ? (bool)$row['active'] : true,
      'password_hash' => $passwordHash,
      'created_at' => $now,
      'updated_at' => $now,
      'last_login_at' => null,
    ];
  }
  if (empty($out)) {
    $out[] = [
      'id' => 1,
      'email' => 'admin@stisoft.local',
      'name' => 'Administrador',
      'role' => 'admin',
      'active' => true,
      'password_hash' => password_hash('cambiar-esta-clave', PASSWORD_DEFAULT),
      'created_at' => $now,
      'updated_at' => $now,
      'last_login_at' => null,
    ];
  }
  $seed = $out;
  return $seed;
}

function auth_ensure_user_store(): void {
  $path = auth_users_file_path();
  $dir = dirname($path);
  if (!is_dir($dir)) {
    @mkdir($dir, 0750, true);
  }
  if (!is_file($path)) {
    $payload = ['version' => 1, 'users' => auth_default_seed_users()];
    @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @chmod($path, 0640);
  }
}

function auth_load_store(): array {
  auth_ensure_user_store();
  $path = auth_users_file_path();
  $raw = @file_get_contents($path);
  if ($raw === false || trim($raw) === '') return ['version' => 1, 'users' => auth_default_seed_users()];
  $decoded = @json_decode($raw, true);
  if (!is_array($decoded)) return ['version' => 1, 'users' => auth_default_seed_users()];
  if (!is_array($decoded['users'] ?? null)) $decoded['users'] = [];
  return $decoded;
}

function auth_save_store(array $store): bool {
  $store['version'] = 1;
  if (!isset($store['users']) || !is_array($store['users'])) $store['users'] = [];
  $path = auth_users_file_path();
  $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) return false;
  return @file_put_contents($path, $json, LOCK_EX) !== false;
}

function auth_role_label(string $role): string {
  return strtolower($role) === 'admin' ? 'Admin' : 'Usuario';
}

function auth_sanitize_user(array $u): array {
  return [
    'id' => (int)($u['id'] ?? 0),
    'email' => strtolower(trim((string)($u['email'] ?? ''))),
    'name' => trim((string)($u['name'] ?? '')),
    'role' => (strtolower((string)($u['role'] ?? 'user')) === 'admin') ? 'admin' : 'user',
    'active' => isset($u['active']) ? (bool)$u['active'] : true,
    'password_hash' => (string)($u['password_hash'] ?? ''),
    'created_at' => (string)($u['created_at'] ?? date('c')),
    'updated_at' => (string)($u['updated_at'] ?? date('c')),
    'last_login_at' => $u['last_login_at'] ?? null,
  ];
}

function auth_all_users_raw(): array {
  $store = auth_load_store();
  $users = [];
  foreach ($store['users'] as $u) {
    if (!is_array($u)) continue;
    $su = auth_sanitize_user($u);
    if ($su['email'] === '') continue;
    $users[] = $su;
  }
  usort($users, static function (array $a, array $b): int {
    return $a['id'] <=> $b['id'];
  });
  return $users;
}

function all_users(bool $includeInactive = true): array {
  $rows = auth_all_users_raw();
  $out = [];
  foreach ($rows as $u) {
    if (!$includeInactive && !$u['active']) continue;
    $out[] = [
      'id' => (int)$u['id'],
      'email' => $u['email'],
      'name' => $u['name'],
      'role' => $u['role'],
      'role_label' => auth_role_label($u['role']),
      'active' => (bool)$u['active'],
      'created_at' => $u['created_at'],
      'updated_at' => $u['updated_at'],
      'last_login_at' => $u['last_login_at'],
    ];
  }
  return $out;
}

function auth_find_user_by_email(string $email): ?array {
  $email = strtolower(trim($email));
  if ($email === '') return null;
  foreach (auth_all_users_raw() as $u) {
    if ($u['email'] === $email) return $u;
  }
  return null;
}

function auth_find_user_by_id(int $id): ?array {
  if ($id <= 0) return null;
  foreach (auth_all_users_raw() as $u) {
    if ((int)$u['id'] === $id) return $u;
  }
  return null;
}

function auth_next_user_id(array $users): int {
  $max = 0;
  foreach ($users as $u) {
    $id = (int)($u['id'] ?? 0);
    if ($id > $max) $max = $id;
  }
  return $max + 1;
}

function auth_update_store_users(array $users): bool {
  $store = auth_load_store();
  $store['users'] = $users;
  return auth_save_store($store);
}

function create_user(string $email, string $name, string $password, string $role = 'user'): array {
  $email = strtolower(trim($email));
  $name = trim($name);
  $role = strtolower(trim($role));
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'message' => 'Correo inválido.'];
  if ($name === '') return ['ok' => false, 'message' => 'Nombre requerido.'];
  if (strlen($password) < 8) return ['ok' => false, 'message' => 'La contraseña debe tener al menos 8 caracteres.'];
  if (auth_find_user_by_email($email)) return ['ok' => false, 'message' => 'Ya existe un usuario con ese correo.'];

  $users = auth_all_users_raw();
  $now = date('c');
  $users[] = [
    'id' => auth_next_user_id($users),
    'email' => $email,
    'name' => $name,
    'role' => ($role === 'admin') ? 'admin' : 'user',
    'active' => true,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'created_at' => $now,
    'updated_at' => $now,
    'last_login_at' => null,
  ];

  if (!auth_update_store_users($users)) return ['ok' => false, 'message' => 'No se pudo guardar el usuario.'];
  return ['ok' => true, 'message' => 'Usuario creado correctamente.'];
}

function update_user_profile(int $id, string $email, string $name, string $role, bool $active): array {
  $email = strtolower(trim($email));
  $name = trim($name);
  $role = strtolower(trim($role));
  if ($id <= 0) return ['ok' => false, 'message' => 'Usuario inválido.'];
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'message' => 'Correo inválido.'];
  if ($name === '') return ['ok' => false, 'message' => 'Nombre requerido.'];

  $users = auth_all_users_raw();
  $now = date('c');
  $found = false;
  foreach ($users as &$u) {
    if ((int)$u['id'] === $id) {
      $found = true;
      continue;
    }
    if ($u['email'] === $email) return ['ok' => false, 'message' => 'Otro usuario ya usa ese correo.'];
  }
  unset($u);
  if (!$found) return ['ok' => false, 'message' => 'Usuario no encontrado.'];

  foreach ($users as &$u) {
    if ((int)$u['id'] !== $id) continue;
    $u['email'] = $email;
    $u['name'] = $name;
    $u['role'] = ($role === 'admin') ? 'admin' : 'user';
    $u['active'] = $active;
    $u['updated_at'] = $now;
    break;
  }
  unset($u);

  if (!auth_update_store_users($users)) return ['ok' => false, 'message' => 'No se pudo actualizar el usuario.'];
  return ['ok' => true, 'message' => 'Usuario actualizado correctamente.'];
}

function update_user_password(int $id, string $password): array {
  if ($id <= 0) return ['ok' => false, 'message' => 'Usuario inválido.'];
  if (strlen($password) < 8) return ['ok' => false, 'message' => 'La contraseña debe tener al menos 8 caracteres.'];

  $users = auth_all_users_raw();
  $now = date('c');
  $found = false;
  foreach ($users as &$u) {
    if ((int)$u['id'] !== $id) continue;
    $u['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    $u['updated_at'] = $now;
    $found = true;
    break;
  }
  unset($u);
  if (!$found) return ['ok' => false, 'message' => 'Usuario no encontrado.'];
  if (!auth_update_store_users($users)) return ['ok' => false, 'message' => 'No se pudo actualizar la contraseña.'];
  return ['ok' => true, 'message' => 'Contraseña actualizada correctamente.'];
}

function delete_user(int $id, ?int $actorId = null): array {
  if ($id <= 0) return ['ok' => false, 'message' => 'Usuario inválido.'];
  if ($actorId !== null && $actorId === $id) return ['ok' => false, 'message' => 'No puedes eliminar tu propio usuario.'];
  $users = auth_all_users_raw();
  $admins = 0;
  foreach ($users as $u) {
    if (($u['role'] ?? 'user') === 'admin' && ($u['active'] ?? true)) $admins++;
  }
  $target = auth_find_user_by_id($id);
  if (!$target) return ['ok' => false, 'message' => 'Usuario no encontrado.'];
  if (($target['role'] ?? 'user') === 'admin' && ($target['active'] ?? true) && $admins <= 1) {
    return ['ok' => false, 'message' => 'Debe existir al menos un administrador activo.'];
  }
  $kept = [];
  foreach ($users as $u) {
    if ((int)$u['id'] !== $id) $kept[] = $u;
  }
  if (!auth_update_store_users($kept)) return ['ok' => false, 'message' => 'No se pudo eliminar el usuario.'];
  return ['ok' => true, 'message' => 'Usuario eliminado.'];
}

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return (string)$_SESSION['csrf_token'];
}

function csrf_validate(?string $token): bool {
  $current = (string)($_SESSION['csrf_token'] ?? '');
  if ($current === '' || !is_string($token) || $token === '') return false;
  return hash_equals($current, $token);
}

function auth_login_locked(): bool {
  $until = (int)($_SESSION['login_lock_until'] ?? 0);
  return $until > time();
}

function auth_login_lock_remaining(): int {
  $until = (int)($_SESSION['login_lock_until'] ?? 0);
  return max(0, $until - time());
}

function auth_register_failed_login(): void {
  $attempts = (int)($_SESSION['login_fail_count'] ?? 0) + 1;
  $_SESSION['login_fail_count'] = $attempts;
  if ($attempts >= 5) {
    $_SESSION['login_lock_until'] = time() + (15 * 60);
    $_SESSION['login_fail_count'] = 0;
  }
}

function auth_register_success_login(): void {
  unset($_SESSION['login_fail_count'], $_SESSION['login_lock_until']);
}

function attempt_login(string $email, string $password): bool {
  if (auth_login_locked()) return false;
  $email = strtolower(trim($email));
  $user = auth_find_user_by_email($email);
  if (!$user || !$user['active']) {
    auth_register_failed_login();
    return false;
  }
  $ok = password_verify($password, (string)$user['password_hash']);
  if (!$ok) {
    auth_register_failed_login();
    return false;
  }

  auth_register_success_login();
  session_regenerate_id(true);
  $_SESSION['user'] = [
    'id' => (int)$user['id'],
    'email' => $user['email'],
    'name' => $user['name'],
    'role' => $user['role'],
    'role_label' => auth_role_label($user['role']),
  ];

  $users = auth_all_users_raw();
  foreach ($users as &$u) {
    if ((int)$u['id'] !== (int)$user['id']) continue;
    $u['last_login_at'] = date('c');
    $u['updated_at'] = date('c');
    break;
  }
  unset($u);
  auth_update_store_users($users);
  return true;
}

function require_auth(): void {
  if (empty($_SESSION['user'])) {
    header('Location: /index.php');
    exit;
  }
}

function require_admin(): void {
  require_auth();
  $role = strtolower((string)($_SESSION['user']['role'] ?? ''));
  if ($role !== 'admin') {
    http_response_code(403);
    echo 'Acceso denegado.';
    exit;
  }
}

function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

function logout(): void {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
}

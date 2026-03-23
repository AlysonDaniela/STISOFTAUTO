<?php
// /sync/email_config.php — Configuración de correos para sincronización
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_admin();

ob_start();

// Bootstrap
$bootstrap_loaded = false;
foreach ([
  __DIR__ . '/../includes/bootstrap.php',
  __DIR__ . '/../includes/init.php',
  __DIR__ . '/../partials/bootstrap.php',
  __DIR__ . '/../app/bootstrap.php',
] as $b) {
  if (is_file($b)) { require_once $b; $bootstrap_loaded = true; break; }
}

// Utilidades
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ensure_dir($p){ if(!is_dir($p)) @mkdir($p, 0775, true); }
function read_json($f){ return is_file($f) ? json_decode(file_get_contents($f), true) : null; }
function write_json($f,$d){ ensure_dir(dirname($f)); file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }

// Rutas
$BASE = __DIR__;
$dirSync = $BASE.'/storage/sync';
ensure_dir($dirSync);
$config_file = $dirSync.'/config.json';

// Cargar config
$config = read_json($config_file) ?: [];

// Procesamiento POST
$msg = null;
$msg_type = 'success';
$errors = [];
$csrf = csrf_token();
$post_csrf_ok = true;

if($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate($_POST['csrf_token'] ?? '')){
  $post_csrf_ok = false;
  $msg = 'La sesión de seguridad expiró. Recarga la pantalla e inténtalo nuevamente.';
  $msg_type = 'error';
}

if($post_csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_email_config'])){
  // Validar campos
  if(empty($_POST['smtp_host'])) $errors[] = "Host SMTP es requerido";
  if(empty($_POST['smtp_port'])) $errors[] = "Puerto SMTP es requerido";
  if(empty($_POST['smtp_user'])) $errors[] = "Usuario SMTP es requerido";
  if(empty($_POST['smtp_password'])) $errors[] = "Contraseña SMTP es requerida";
  if(empty($_POST['from_address'])) $errors[] = "Correo 'De' es requerido";
  if(empty($_POST['to_addresses'])) $errors[] = "Al menos un correo destinatario es requerido";

  if(empty($errors)){
    // Procesar destinatarios
    $toAddresses = array_filter(array_map('trim', explode(PHP_EOL, $_POST['to_addresses'] ?? '')));
    
    // Validar que sean emails válidos
    $invalidEmails = array_filter($toAddresses, function($e){ return !filter_var($e, FILTER_VALIDATE_EMAIL); });
    if($invalidEmails){
      $errors[] = "Correos inválidos: " . implode(', ', $invalidEmails);
    }
  }

  if(empty($errors)){
    $config['smtp'] = [
      'enabled' => isset($_POST['smtp_enabled']) && $_POST['smtp_enabled'] === 'on',
      'host' => trim($_POST['smtp_host']),
      'port' => (int)$_POST['smtp_port'],
      'username' => trim($_POST['smtp_user']),
      'password' => trim($_POST['smtp_password']),
      'from_address' => trim($_POST['from_address']),
      'from_name' => trim($_POST['from_name'] ?? 'STISOFT Auto Sync'),
      'to_addresses' => array_values($toAddresses)
    ];
    
    write_json($config_file, $config);
    $msg = "✅ Configuración de correos guardada exitosamente";
    $msg_type = 'success';
  } else {
    $msg = implode("<br>", $errors);
    $msg_type = 'error';
  }
}

// Test SMTP
$test_result = null;
if($post_csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_smtp'])){
  if(empty($config['smtp'])){
    $test_result = ['ok' => false, 'msg' => 'Configura primero los datos SMTP'];
  } else {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    try{
      $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
      $s = $config['smtp'];
      $mail->CharSet = 'UTF-8';
      $mail->isSMTP();
      $mail->Host = $s['host'];
      $mail->Port = (int)$s['port'];
      $mail->SMTPAuth = true;
      $mail->Username = $s['username'];
      $mail->Password = $s['password'];
      $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
      $mail->setFrom($s['from_address'], $s['from_name'] ?? 'STISOFT');
      
      // Enviar a los emails configurados como destinatarios
      $toAddresses = $s['to_addresses'] ?? ['test@example.com'];
      foreach($toAddresses as $addr){
        if(filter_var($addr, FILTER_VALIDATE_EMAIL)){
          $mail->addAddress($addr);
        }
      }
      
      $mail->Subject = 'Test STISOFT Email Config';
      $mail->Body = "Este es un mensaje de prueba de la configuración SMTP de STISOFT.";
      $mail->IsHTML(false);
      $mail->send();
      $test_result = ['ok' => true, 'msg' => '✅ Correo de prueba enviado exitosamente a: ' . implode(', ', $toAddresses)];
    } catch(Exception $e){
      $test_result = ['ok' => false, 'msg' => '❌ Error: ' . $e->getMessage()];
    }
  }
}

$smtp = $config['smtp'] ?? [];
$toAddressesStr = implode("\n", $smtp['to_addresses'] ?? []);

require_once __DIR__ . '/../partials/head.php';
?>
<body class="bg-gray-50">
<div class="min-h-screen grid grid-cols-12">
    <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
        <?php require __DIR__ . '/../partials/sidebar.php'; ?>
    </div>

    <div class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col">
        <?php require __DIR__ . '/../partials/topbar.php'; ?>

        <main class="flex-grow max-w-7xl mx-auto w-full p-4 md:p-6 space-y-6">
            <section class="rounded-3xl border border-slate-200 bg-gradient-to-r from-slate-900 via-cyan-900 to-sky-800 text-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="text-xs uppercase tracking-[0.18em] text-cyan-100/80">Sync general</div>
                        <h1 class="text-2xl md:text-3xl font-semibold mt-2 flex items-center gap-3">
                            <i class="fa-solid fa-envelope"></i>
                            Configuración de Correos
                        </h1>
                        <p class="text-sm text-cyan-50/90 mt-3 max-w-3xl">Define el servidor SMTP, remitente y destinatarios para los reportes automáticos de sincronización con el mismo formato visual del resto del panel.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3 text-sm min-w-[240px]">
                        <div class="rounded-2xl bg-white/10 border border-white/10 px-4 py-3">
                            <div class="text-cyan-100/70 text-xs">SMTP</div>
                            <div class="mt-1 font-semibold"><?= !empty($smtp['enabled']) ? 'Activo' : 'Inactivo' ?></div>
                        </div>
                        <div class="rounded-2xl bg-white/10 border border-white/10 px-4 py-3">
                            <div class="text-cyan-100/70 text-xs">Destinatarios</div>
                            <div class="mt-1 font-semibold"><?= count($smtp['to_addresses'] ?? []) ?></div>
                        </div>
                    </div>
                </div>
            </section>

            <?php if($msg): ?>
            <div class="p-4 rounded-2xl border <?= $msg_type === 'success' ? 'bg-green-50 text-green-800 border-green-200' : 'bg-red-50 text-red-800 border-red-200' ?>">
                <?= $msg ?>
            </div>
            <?php endif; ?>

            <?php if($test_result): ?>
            <div class="p-4 rounded-2xl border <?= $test_result['ok'] ? 'bg-green-50 text-green-800 border-green-200' : 'bg-red-50 text-red-800 border-red-200' ?>">
                <?= e($test_result['msg']); ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="grid grid-cols-1 xl:grid-cols-[1.2fr_.8fr] gap-6">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <div class="space-y-6">
                    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
                        <div class="flex items-center justify-between gap-4 mb-6">
                            <div>
                                <h2 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
                                    <i class="fa-solid fa-server text-cyan-700"></i>
                                    Configuración SMTP
                                </h2>
                                <p class="text-sm text-slate-500 mt-1">Completa los datos de conexión del servidor de correo.</p>
                            </div>
                            <label for="smtp_enabled" class="inline-flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-700 cursor-pointer">
                                <input type="checkbox" name="smtp_enabled" id="smtp_enabled" <?= ($smtp['enabled'] ?? false) ? 'checked' : ''; ?> class="h-4 w-4 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600">
                                Habilitar envío
                            </label>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <label class="block">
                                <span class="block text-sm font-medium text-slate-700 mb-2">Host SMTP</span>
                                <input type="text" name="smtp_host" value="<?= e($smtp['host'] ?? ''); ?>" placeholder="mail.ejemplo.com" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-cyan-600">
                            </label>

                            <label class="block">
                                <span class="block text-sm font-medium text-slate-700 mb-2">Puerto</span>
                                <input type="number" name="smtp_port" value="<?= e($smtp['port'] ?? 587); ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-cyan-600">
                                <span class="block text-xs text-slate-500 mt-2">Usualmente 587 para TLS o 465 para SSL.</span>
                            </label>

                            <label class="block">
                                <span class="block text-sm font-medium text-slate-700 mb-2">Usuario SMTP</span>
                                <input type="email" name="smtp_user" value="<?= e($smtp['username'] ?? ''); ?>" placeholder="sti@ensoin.com" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-cyan-600">
                            </label>

                            <label class="block">
                                <span class="block text-sm font-medium text-slate-700 mb-2">Contraseña</span>
                                <input type="password" name="smtp_password" value="<?= e($smtp['password'] ?? ''); ?>" placeholder="••••••••" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-cyan-600">
                            </label>
                        </div>
                    </div>

                    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
                        <div class="mb-6">
                            <h2 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
                                <i class="fa-solid fa-at text-cyan-700"></i>
                                Direcciones de correo
                            </h2>
                            <p class="text-sm text-slate-500 mt-1">Define el remitente y la lista de personas que recibirán los reportes.</p>
                        </div>

                        <div class="space-y-5">
                            <label class="block">
                                <span class="block text-sm font-medium text-slate-700 mb-2">Correo desde</span>
                                <input type="email" name="from_address" value="<?= e($smtp['from_address'] ?? ''); ?>" placeholder="sti@ensoin.com" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-cyan-600">
                            </label>

                            <label class="block">
                                <span class="block text-sm font-medium text-slate-700 mb-2">Nombre del remitente</span>
                                <input type="text" name="from_name" value="<?= e($smtp['from_name'] ?? 'STISOFT Auto Sync'); ?>" placeholder="STISOFT Auto Sync" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-cyan-600">
                            </label>

                            <label class="block">
                                <span class="block text-sm font-medium text-slate-700 mb-2">Correos destinatarios</span>
                                <textarea name="to_addresses" placeholder="alysonvalenzuela94@gmail.com&#10;alyson.valenzuela@ensoin.com" class="w-full rounded-2xl border border-slate-200 px-4 py-3 font-mono text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-cyan-600" rows="7"><?= e($toAddressesStr); ?></textarea>
                                <span class="block text-xs text-slate-500 mt-2">Ingresa un correo por línea.</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
                        <h2 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
                            <i class="fa-solid fa-bolt text-amber-500"></i>
                            Acciones
                        </h2>
                        <p class="text-sm text-slate-500 mt-1">Guarda la configuración o envía un correo de prueba.</p>

                        <div class="mt-5 flex flex-col gap-3">
                            <button type="submit" name="save_email_config" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-cyan-700 px-5 py-3 text-sm font-semibold text-white hover:bg-cyan-800 transition">
                                <i class="fa-solid fa-save"></i>
                                Guardar configuración
                            </button>
                            <button type="submit" name="test_smtp" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-700 transition">
                                <i class="fa-solid fa-paper-plane"></i>
                                Enviar prueba
                            </button>
                            <a href="/sync/configuracion_sincronizacion.php" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                                <i class="fa-solid fa-clock"></i>
                                Configurar tiempo sync
                            </a>
                        </div>
                    </div>

                    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
                        <h2 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
                            <i class="fa-solid fa-circle-info text-cyan-700"></i>
                            Recomendaciones
                        </h2>
                        <ul class="mt-4 space-y-3 text-sm text-slate-600">
                            <li class="flex gap-3">
                                <span class="mt-1 h-2 w-2 rounded-full bg-cyan-600 shrink-0"></span>
                                <span>El botón de prueba enviará un correo a todos los destinatarios configurados.</span>
                            </li>
                            <li class="flex gap-3">
                                <span class="mt-1 h-2 w-2 rounded-full bg-cyan-600 shrink-0"></span>
                                <span>Puedes agregar múltiples correos usando un salto de línea por cada uno.</span>
                            </li>
                            <li class="flex gap-3">
                                <span class="mt-1 h-2 w-2 rounded-full bg-cyan-600 shrink-0"></span>
                                <span>Si usas Gmail, conviene utilizar una contraseña de aplicación y no la clave normal.</span>
                            </li>
                            <li class="flex gap-3">
                                <span class="mt-1 h-2 w-2 rounded-full bg-cyan-600 shrink-0"></span>
                                <span>Los reportes automáticos usan esta configuración junto con el horario definido en la sincronización.</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </form>
        </main>
    </div>
</div>
</body>
</html>

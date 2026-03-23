<?php
// /sync/configuracion_sincronizacion.php — Maqueta completa con layout heredado
// Hereda: /partials/head.php, /partials/topbar.php, /partials/sidebar.php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_admin();

/* ---------- Evitar errores de headers y auth del layout ---------- */
if (!headers_sent()) {
  if (session_status() === PHP_SESSION_NONE) @session_start();
}
ob_start();

// Carga bootstrap si existe (donde podrían definirse helpers de auth)
$bootstrap_loaded = false;
foreach ([
  __DIR__ . '/../includes/bootstrap.php',
  __DIR__ . '/../includes/init.php',
  __DIR__ . '/../partials/bootstrap.php',
  __DIR__ . '/../app/bootstrap.php',
] as $b) {
  if (is_file($b)) { require_once $b; $bootstrap_loaded = true; break; }
}
/* ---------- Utilidades ---------- */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ensure_dir($p){ if(!is_dir($p)) @mkdir($p, 0775, true); }
function read_json($f){ return is_file($f) ? json_decode(file_get_contents($f), true) : null; }
function write_json($f,$d){ ensure_dir(dirname($f)); file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }

/* ---------- Rutas y archivos ---------- */
$BASE         = __DIR__;
$dirSync      = $BASE.'/storage/sync';
$dirLogs      = $BASE.'/storage/logs';
$dirInbox     = $BASE.'/storage/sftp_inbox';
$dirDescargas = $BASE.'/storage/descargas';
foreach([$dirSync,$dirLogs,$dirInbox,$dirDescargas] as $d) ensure_dir($d);

$config_file  = $dirSync.'/config.json';
$log_file     = $dirLogs.'/sync.log';

/* ---------- Cargar/crear config por defecto (ficticia) ---------- */
$config = read_json($config_file);
if(!$config){
  $config = [
    'mode' => 'local', // local | sftp
    'sftp' => ['host'=>'sftp.mock-ejemplo.com','port'=>22,'username'=>'mockuser','password'=>'mockpass','remote_path'=>'/out'],
    'paths'=> ['local_inbox'=>$dirInbox, 'download_dir'=>$dirDescargas],
    'schedule'=> ['preset'=>'*/5 * * * *','custom'=>'','daily_time'=>'02:00','cron'=>'*/5 * * * *'],
    'runtime'=> ['php_path'=>'/usr/bin/php','project_path'=>$BASE,'log_file'=>$log_file]
  ];
  write_json($config_file, $config);
}
if(!is_file($log_file)) file_put_contents($log_file, '['.date('Y-m-d H:i:s')."] Log iniciado\n");

/* ---------- POST: Guardar configuración ---------- */
$msg=null; $msg_type='success';
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_config'])){
  // Recoger campos
  $mode = in_array($_POST['mode'] ?? 'local', ['local','sftp']) ? $_POST['mode'] : 'local';
  $paths_local_inbox = trim($_POST['paths_local_inbox'] ?? $config['paths']['local_inbox']);
  $paths_download    = trim($_POST['paths_download'] ?? $config['paths']['download_dir']);

  $sftp_host = trim($_POST['sftp_host'] ?? $config['sftp']['host']);
  $sftp_port = (int)($_POST['sftp_port'] ?? $config['sftp']['port']);
  $sftp_user = trim($_POST['sftp_user'] ?? $config['sftp']['username']);
  $sftp_pass = (string)($_POST['sftp_pass'] ?? $config['sftp']['password']);
  $sftp_path = trim($_POST['sftp_path'] ?? $config['sftp']['remote_path']);

  $preset = $_POST['schedule_preset'] ?? ($config['schedule']['preset'] ?? '*/5 * * * *');
  $daily  = $_POST['schedule_daily_time'] ?? ($config['schedule']['daily_time'] ?? '02:00');
  $custom = trim($_POST['schedule_custom'] ?? ($config['schedule']['custom'] ?? ''));
  $timezone = trim($_POST['schedule_timezone'] ?? ($config['schedule']['timezone'] ?? 'America/Santiago'));

  $php_path = trim($_POST['php_path'] ?? ($config['runtime']['php_path'] ?? '/usr/bin/php'));

  // Construir cron
  if($preset==='DAILY_AT'){
    [$h,$m] = array_pad(explode(':', $daily), 2, '00');
    $h = max(0,min(23,(int)$h)); $m=max(0,min(59,(int)$m));
    $cron = "$m $h * * *";
  } elseif($preset==='CUSTOM'){
    $cron = $custom !== '' ? $custom : '*/5 * * * *';
  } else {
    $cron = $preset;
  }

  // Persistir
  $config['mode'] = $mode;
  $config['paths']['local_inbox'] = $paths_local_inbox ?: $config['paths']['local_inbox'];
  $config['paths']['download_dir']= $paths_download    ?: $config['paths']['download_dir'];

  $config['sftp'] = [
    'host'=>$sftp_host ?: $config['sftp']['host'],
    'port'=>$sftp_port ?: 22,
    'username'=>$sftp_user ?: $config['sftp']['username'],
    'password'=>$sftp_pass, // permitir vacío si quieres no sobreescribir
    'remote_path'=>$sftp_path ?: $config['sftp']['remote_path']
  ];

  $config['schedule'] = [
    'frequency'=>$preset === 'DAILY_AT' ? 'daily' : 'custom',
    'daily_time'=>$daily,
    'timezone'=>$timezone,
    'cron'=>$cron
  ];
  $config['runtime']['php_path'] = $php_path;

  write_json($config_file, $config);
  $msg = 'Configuración guardada correctamente.';
}

/* ---------- Probar conexión ---------- */
$probe_msg = null; $probe_ok = false;
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['probe'])){
  if(($config['mode'] ?? 'local') === 'local'){
    $ok_inbox = is_dir($config['paths']['local_inbox']);
    $ok_out   = is_dir($config['paths']['download_dir']);
    $probe_ok = $ok_inbox && $ok_out;
    $probe_msg = $probe_ok ? "Local OK · inbox y destino existen."
                           : "Local con problemas · Inbox o destino no existen.";
  } else {
    // Intento SFTP si existe vendor, de lo contrario, maqueta
    $vendor = dirname($BASE).'/vendor/autoload.php';
    if(is_file($vendor)){
      require_once $vendor;
      try{
        $s = $config['sftp'];
        $client = new \phpseclib3\Net\SFTP($s['host'], (int)$s['port'], 10);
        if($client->login($s['username'], $s['password'])){
          $probe_ok = true;
          $probe_msg = "SFTP OK · Login correcto. Ruta remota: ".$s['remote_path'];
        } else {
          $probe_msg = "SFTP FAIL · Credenciales inválidas.";
        }
      }catch(Throwable $t){
        $probe_msg = "SFTP FAIL · ".$t->getMessage();
      }
    } else {
      $probe_msg = "SFTP en modo maqueta (phpseclib no instalado).";
    }
  }
}

/* ---------- Datos derivados ---------- */
$cron_line = ($config['schedule']['cron'] ?? '*/5 * * * *')
  .' '.($config['runtime']['php_path']??'/usr/bin/php')
  .' '.rtrim($config['runtime']['project_path']??$BASE,'/').'/run_sync.php'
  .' >> '.($config['runtime']['log_file']??$log_file).' 2>&1';

$files_inbox = array_values(array_filter(@scandir($config['paths']['local_inbox']) ?: [], fn($f)=>$f!=='.'&&$f!=='..'));

/* ---------- Layout heredado ---------- */
require_once __DIR__.'/../partials/head.php';
?>
<div class="flex">
  <?php require_once __DIR__.'/../partials/sidebar.php'; ?>

  <main class="flex-1 min-h-screen">
    <?php require_once __DIR__.'/../partials/topbar.php'; ?>

    <div class="max-w-7xl mx-auto px-4 py-6">
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-lg font-semibold flex items-center gap-2">
          <i class="fa-solid fa-gear"></i> Configuración de Sincronización
        </h1>
        <form method="post">
          <input type="hidden" name="probe" value="1">
          <button class="px-3 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
            <i class="fa-solid fa-plug"></i> Probar conexión
          </button>
        </form>
      </div>

      <?php if($msg): ?>
        <div class="mb-4 p-3 rounded-xl <?php echo $msg_type==='success'?'bg-green-50 text-green-700 border border-green-200':'bg-red-50 text-red-700 border border-red-200'; ?>">
          <?php echo e($msg); ?>
        </div>
      <?php endif; ?>
      <?php if($probe_msg!==null): ?>
        <div class="mb-4 p-3 rounded-xl <?php echo $probe_ok?'bg-emerald-50 text-emerald-700 border border-emerald-200':'bg-amber-50 text-amber-800 border border-amber-200'; ?>">
          <?php echo e($probe_msg); ?>
        </div>
      <?php endif; ?>

      <form method="post" class="grid md:grid-cols-2 gap-6">
        <input type="hidden" name="save_config" value="1">

        <!-- Estado -->
        <div class="bg-white rounded-2xl p-5 shadow-sm border">
          <h2 class="font-semibold mb-4 flex items-center gap-2">
            <i class="fa-solid fa-circle-info"></i> Estado
          </h2>

          <label class="block text-sm mb-3">Modo
            <select name="mode" class="mt-1 w-full border rounded-lg px-3 py-2">
              <option value="local" <?php echo ($config['mode']==='local'?'selected':''); ?>>Local / Mock</option>
              <option value="sftp"  <?php echo ($config['mode']==='sftp'?'selected':''); ?>>SFTP</option>
            </select>
          </label>

          <div class="text-sm mt-3 space-y-1">
            <div>PHP: <code><?php echo e($config['runtime']['php_path']); ?></code></div>
            <div>Proyecto: <code><?php echo e($config['runtime']['project_path']); ?></code></div>
            <div>Log: <code><?php echo e($config['runtime']['log_file']); ?></code></div>
          </div>
        </div>

        <!-- Rutas -->
        <div class="bg-white rounded-2xl p-5 shadow-sm border">
          <h2 class="font-semibold mb-4 flex items-center gap-2">
            <i class="fa-regular fa-folder-open"></i> Rutas
          </h2>

          <label class="block text-sm mb-3">Carpeta origen (inbox)
            <input name="paths_local_inbox" value="<?php echo e($config['paths']['local_inbox']); ?>" class="mt-1 w-full border rounded-lg px-3 py-2">
          </label>

          <label class="block text-sm">Carpeta destino (descargas)
            <input name="paths_download" value="<?php echo e($config['paths']['download_dir']); ?>" class="mt-1 w-full border rounded-lg px-3 py-2">
          </label>
        </div>

        <!-- SFTP -->
        <div class="bg-white rounded-2xl p-5 shadow-sm border">
          <h2 class="font-semibold mb-4 flex items-center gap-2">
            <i class="fa-solid fa-server"></i> SFTP (credenciales de ejemplo)
          </h2>

          <div class="grid grid-cols-2 gap-3">
            <label class="block text-sm col-span-2">Host
              <input name="sftp_host" value="<?php echo e($config['sftp']['host']); ?>" class="mt-1 w-full border rounded-lg px-3 py-2">
            </label>
            <label class="block text-sm">Port
              <input name="sftp_port" type="number" value="<?php echo e((int)$config['sftp']['port']); ?>" class="mt-1 w-full border rounded-lg px-3 py-2">
            </label>
            <label class="block text-sm">Usuario
              <input name="sftp_user" value="<?php echo e($config['sftp']['username']); ?>" class="mt-1 w-full border rounded-lg px-3 py-2">
            </label>
            <label class="block text-sm col-span-2">Password
              <input name="sftp_pass" value="<?php echo e($config['sftp']['password']); ?>" class="mt-1 w-full border rounded-lg px-3 py-2" type="password">
            </label>
            <label class="block text-sm col-span-2">Ruta remota
              <input name="sftp_path" value="<?php echo e($config['sftp']['remote_path']); ?>" class="mt-1 w-full border rounded-lg px-3 py-2">
            </label>
          </div>
          <p class="text-xs text-gray-500 mt-2">*En producción, guarda la contraseña en una variable de entorno.</p>
        </div>

        <!-- Frecuencia -->
        <div class="bg-white rounded-2xl p-5 shadow-sm border">
          <h2 class="font-semibold mb-4 flex items-center gap-2">
            <i class="fa-regular fa-clock"></i> Frecuencia (cron)
          </h2>

          <?php $preset = $config['schedule']['preset'] ?? '*/5 * * * *'; ?>
          <label class="block text-sm mb-3">Preset
            <select name="schedule_preset" class="mt-1 w-full border rounded-lg px-3 py-2" onchange="toggleCronFields(this.value)">
              <?php
                $opts = [
                  '*/5 * * * *' => 'Cada 5 minutos',
                  '*/15 * * * *'=> 'Cada 15 minutos',
                  '0 * * * *'   => 'Cada hora',
                  'DAILY_AT'    => 'Diario a la hora…',
                  'CUSTOM'      => 'Expresión cron personalizada'
                ];
                foreach($opts as $k=>$v){
                  $sel = ($preset===$k)?'selected':'';
                  echo "<option value=\"".e($k)."\" $sel>".e($v)."</option>";
                }
              ?>
            </select>
          </label>

          <div id="dailyWrap" class="<?php echo ($preset==='DAILY_AT'?'':'hidden'); ?>">
            <label class="block text-sm mb-3">Hora diaria (HH:MM)
              <input name="schedule_daily_time" value="<?php echo e($config['schedule']['daily_time'] ?? '02:00'); ?>" class="mt-1 w-full border rounded-lg px-3 py-2" placeholder="02:00">
            </label>
            
            <label class="block text-sm mb-3">Zona Horaria
              <select name="schedule_timezone" class="mt-1 w-full border rounded-lg px-3 py-2">
                <?php
                  $timezones = [
                    'America/Santiago' => '🇨🇱 Chile (America/Santiago)',
                    'America/Argentina/Buenos_Aires' => '🇦🇷 Argentina (Buenos Aires)',
                    'America/Sao_Paulo' => '🇧🇷 Brasil (São Paulo)',
                    'America/Mexico_City' => '🇲🇽 México',
                    'America/New_York' => '🇺🇸 USA (New York)',
                    'America/Los_Angeles' => '🇺🇸 USA (Los Angeles)',
                    'Europe/Madrid' => '🇪🇸 España',
                    'Europe/London' => '🇬🇧 Reino Unido',
                    'Europe/Paris' => '🇫🇷 Francia',
                    'Asia/Tokyo' => '🇯🇵 Japón',
                    'Australia/Sydney' => '🇦🇺 Australia',
                    'UTC' => 'UTC (Coordinado Universal)',
                  ];
                  $currentTz = $config['schedule']['timezone'] ?? 'America/Santiago';
                  foreach($timezones as $tz => $label){
                    $sel = ($currentTz === $tz) ? 'selected' : '';
                    echo "<option value=\"".e($tz)."\" $sel>".e($label)."</option>";
                  }
                ?>
              </select>
            </label>
          </div>

          <div id="customWrap" class="<?php echo ($preset==='CUSTOM'?'':'hidden'); ?>">
            <label class="block text-sm mb-3">Cron personalizado
              <input name="schedule_custom" value="<?php echo e($config['schedule']['custom'] ?? ''); ?>" class="mt-1 w-full border rounded-lg px-3 py-2" placeholder="m h dom mes dow">
            </label>
          </div>

          <label class="block text-sm">Ruta de PHP
            <input name="php_path" value="<?php echo e($config['runtime']['php_path']); ?>" class="mt-1 w-full border rounded-lg px-3 py-2">
          </label>
        </div>

        <!-- Cron sugerido -->
        <div class="bg-white rounded-2xl p-5 shadow-sm border md:col-span-2">
          <h2 class="font-semibold mb-4"><i class="fa-solid fa-terminal"></i> Línea crontab sugerida</h2>
          <pre class="text-sm bg-gray-50 border rounded-lg p-3 overflow-x-auto"><?php echo e($cron_line); ?></pre>
          <p class="text-xs text-gray-500 mt-2">Cópiala con <code>crontab -e</code> en el servidor.</p>
        </div>

        <!-- Archivos en inbox -->
        <div class="bg-white rounded-2xl p-5 shadow-sm border md:col-span-2">
          <h2 class="font-semibold mb-4"><i class="fa-regular fa-folder-open"></i> Archivos en el inbox (mock)</h2>
          <?php if($files_inbox): ?>
            <ul class="list-disc pl-5">
              <?php foreach($files_inbox as $f): ?><li><?php echo e($f); ?></li><?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="text-gray-500 text-sm">No hay archivos en el inbox.</p>
          <?php endif; ?>
        </div>

        <!-- Guardar -->
        <div class="md:col-span-2 flex items-center justify-end gap-2">
          <a href="/sync/index.php" class="px-3 py-2 rounded-lg border hover:bg-gray-50">Cancelar</a>
          <button class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-700">
            <i class="fa-regular fa-floppy-disk"></i> Guardar configuración
          </button>
        </div>
      </form>
    </div>
  </main>
</div>

<script>
function toggleCronFields(val){
  document.getElementById('dailyWrap')?.classList.toggle('hidden', val!=='DAILY_AT');
  document.getElementById('customWrap')?.classList.toggle('hidden', val!=='CUSTOM');
}
</script>

</body>
</html>
<?php
// Vaciar buffer al final por si los partials hacen header()
ob_end_flush();

<?php
// /sync/index.php — Panel de Sincronización (maqueta) heredando tus partials
// Arregla: current_user() indefinida y headers enviados después de output

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_admin();

ob_start();

// --- 2) Intentar cargar tu bootstrap real (si existe) para definir current_user(), etc. ---
$bootstrap_loaded = false;
$bootstrap_candidates = [
  __DIR__ . '/../includes/bootstrap.php',
  __DIR__ . '/../includes/init.php',
  __DIR__ . '/../partials/bootstrap.php',   // por si lo tienes aquí
  __DIR__ . '/../app/bootstrap.php',
];
foreach ($bootstrap_candidates as $b) {
  if (is_file($b)) { require_once $b; $bootstrap_loaded = true; break; }
}

// ===================== LÓGICA DEL PANEL (igual que la versión anterior) =====================
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ensure_dir($p){ if(!is_dir($p)) @mkdir($p, 0775, true); }
function read_json($f){ return is_file($f) ? json_decode(file_get_contents($f), true) : null; }
function write_json($f,$d){ ensure_dir(dirname($f)); file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }
function today_str(){ return date('Y-m-d'); }
function parse_ts($s){ $t=strtotime($s); return $t?:time(); }
function tail_lines(string $file, int $lines=200): string{
  if(!is_file($file)) return '';
  $f=fopen($file,"r"); $buf=''; $chunk=4096;
  fseek($f,0,SEEK_END); $pos=ftell($f); $n=0;
  while($pos>0 && $n<=$lines){
    $read=($pos-$chunk)>=0?$chunk:$pos; $pos-=$read; fseek($f,$pos);
    $buf=fread($f,$read).$buf; $n=substr_count($buf,"\n");
    if($pos===0) break;
  }
  fclose($f);
  $arr=explode("\n",$buf);
  return implode("\n", array_slice($arr, -$lines));
}

$BASE = __DIR__;
$dirSync  = $BASE.'/storage/sync';
$dirLogs  = $BASE.'/storage/logs';
$dirInbox = $BASE.'/storage/sftp_inbox';
$dirOut   = $BASE.'/storage/descargas';
foreach([$dirSync,$dirLogs,$dirInbox,$dirOut] as $d) ensure_dir($d);

$config_file  = $dirSync.'/config.json';
$history_file = $dirSync.'/history.json';
$log_file     = $dirLogs.'/sync.log';

$config = read_json($config_file);
if(!$config){
  $config = [
    'mode'=>'local',
    'sftp'=>['host'=>'sftp.mock-ejemplo.com','port'=>22,'username'=>'mockuser','password'=>'mockpass','remote_path'=>'/out'],
    'paths'=>['local_inbox'=>$dirInbox,'download_dir'=>$dirOut],
    'schedule'=>['preset'=>'*/5 * * * *','custom'=>'','daily_time'=>'02:00','cron'=>'*/5 * * * *'],
    'runtime'=>['php_path'=>'/usr/bin/php','project_path'=>$BASE,'log_file'=>$log_file]
  ];
  write_json($config_file,$config);
}
if(!is_file($log_file)) file_put_contents($log_file,'['.date('Y-m-d H:i:s')."] Log iniciado\n");

$csrf = csrf_token();
$message=null; $message_type='success';
if($_SERVER['REQUEST_METHOD']==='POST' && !csrf_validate($_POST['csrf_token'] ?? '')){
  $message='La sesión de seguridad expiró. Recarga la pantalla e inténtalo nuevamente.';
  $message_type='error';
} elseif($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['quick_config'])){
  $preset=$_POST['schedule_preset']??'*/5 * * * *';
  $daily =$_POST['schedule_daily_time']??'02:00';
  $custom=trim($_POST['schedule_custom']??'');
  if($preset==='DAILY_AT'){ [$h,$m]=array_pad(explode(':',$daily),2,'00'); $h=max(0,min(23,(int)$h)); $m=max(0,min(59,(int)$m)); $cron="$m $h * * *"; }
  elseif($preset==='CUSTOM'){ $cron = $custom!=='' ? $custom : '*/5 * * * *'; }
  else { $cron = $preset; }
  $config['schedule']=['preset'=>$preset,'custom'=>$custom,'daily_time'=>$daily,'cron'=>$cron];
  write_json($config_file,$config);
  $message='Frecuencia actualizada.';
}

$history = read_json($history_file) ?: [];
$today   = today_str();
$runs_today = array_values(array_filter($history, fn($h)=> isset($h['date']) && substr($h['date'],0,10)===$today));
$total=count($runs_today);
$oks  =count(array_filter($runs_today, fn($h)=>($h['status']??'')==='OK'));
$errs =count(array_filter($runs_today, fn($h)=>($h['status']??'')==='ERROR'));
$last_ok=$last_err=null;
foreach(array_reverse($runs_today) as $h){
  if(!$last_ok && ($h['status']??'')==='OK')   $last_ok=$h['date'];
  if(!$last_err && ($h['status']??'')==='ERROR')$last_err=$h['date'];
}

$inbox = @scandir($config['paths']['local_inbox']) ?: [];
$inbox = array_values(array_filter($inbox, fn($f)=>$f!=='.'&&$f!=='..'));
$descargas = @scandir($config['paths']['download_dir']) ?: [];
$descargas = array_values(array_filter($descargas, fn($f)=>$f!=='.'&&$f!=='..'));

// ===================== LAYOUT HEREDADO (como tus otros index) =====================
require_once __DIR__.'/../partials/head.php';
?>
<div class="flex">
  <?php require_once __DIR__.'/../partials/sidebar.php'; ?>

  <main class="flex-1 min-h-screen">
    <?php require_once __DIR__.'/../partials/topbar.php'; ?>

    <!-- CONTENIDO DEL PANEL -->
    <div class="max-w-7xl mx-auto px-4 py-6">
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-lg font-semibold flex items-center gap-2">
          <i class="fa-solid fa-rotate"></i> Sincronización — Panel
        </h1>
        <div class="flex items-center gap-2">
          <a href="/sync/configuracion_sincronizacion.php" class="px-3 py-2 rounded-lg bg-gray-800 text-white hover:bg-black">
            <i class="fa-solid fa-gear"></i> Configuración
          </a>
          <form method="post" action="/sync/configuracion_sincronizacion.php">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="probe" value="1">
            <button class="px-3 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
              <i class="fa-solid fa-plug"></i> Probar conexión
            </button>
          </form>
        </div>
      </div>

      <?php if($message): ?>
        <div class="mb-4 p-3 rounded-xl <?php echo $message_type==='success'?'bg-green-50 text-green-700 border border-green-200':'bg-red-50 text-red-700 border border-red-200'; ?>">
          <?php echo e($message); ?>
        </div>
      <?php endif; ?>

      <div class="grid lg:grid-cols-3 gap-6">
        <!-- Resumen del día -->
        <div class="bg-white rounded-2xl p-5 shadow-sm border">
          <h2 class="font-semibold mb-4 flex items-center gap-2"><i class="fa-solid fa-circle-info"></i> Resumen de hoy</h2>
          <div class="grid grid-cols-3 gap-3 text-center">
            <div class="p-3 rounded-xl bg-gray-50 border">
              <div class="text-2xl font-semibold"><?php echo $total; ?></div>
              <div class="text-xs text-gray-600">Ejecuciones</div>
            </div>
            <div class="p-3 rounded-xl bg-green-50 border border-green-200">
              <div class="text-2xl font-semibold text-green-700"><?php echo $oks; ?></div>
              <div class="text-xs text-green-700/90">OK</div>
            </div>
            <div class="p-3 rounded-xl bg-red-50 border border-red-200">
              <div class="text-2xl font-semibold text-red-700"><?php echo $errs; ?></div>
              <div class="text-xs text-red-700/90">Errores</div>
            </div>
          </div>
          <div class="text-xs text-gray-600 mt-3 space-y-1">
            <div>Última OK: <strong><?php echo $last_ok ? e(date('H:i:s', parse_ts($last_ok))) : '—'; ?></strong></div>
            <div>Último error: <strong class="text-red-700"><?php echo $last_err ? e(date('H:i:s', parse_ts($last_err))) : '—'; ?></strong></div>
          </div>
        </div>

        <!-- Frecuencia -->
        <div class="bg-white rounded-2xl p-5 shadow-sm border">
          <h2 class="font-semibold mb-4 flex items-center gap-2"><i class="fa-regular fa-clock"></i> Frecuencia (cron)</h2>
          <form method="post" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="quick_config" value="1">
            <?php $preset = $config['schedule']['preset'] ?? '*/5 * * * *'; ?>
            <label class="block text-sm">Preset
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
              <label class="block text-sm">Hora diaria (HH:MM)
                <input name="schedule_daily_time" value="<?php echo e($config['schedule']['daily_time'] ?? '02:00'); ?>" class="mt-1 w-full border rounded-lg px-3 py-2" placeholder="02:00">
              </label>
            </div>
            <div id="customWrap" class="<?php echo ($preset==='CUSTOM'?'':'hidden'); ?>">
              <label class="block text-sm">Cron personalizado
                <input name="schedule_custom" value="<?php echo e($config['schedule']['custom'] ?? ''); ?>" class="mt-1 w-full border rounded-lg px-3 py-2" placeholder="m h dom mes dow">
              </label>
            </div>
            <div class="text-xs text-gray-600">
              Línea actual: <code><?php echo e(($config['schedule']['cron'] ?? '*/5 * * * *').' '.($config['runtime']['php_path']??'/usr/bin/php').' '.rtrim($config['runtime']['project_path']??$BASE,'/').'/run_sync.php >> '.($config['runtime']['log_file']??$log_file).' 2>&1'); ?></code>
            </div>
            <button class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-700">
              <i class="fa-regular fa-floppy-disk"></i> Guardar frecuencia
            </button>
          </form>
        </div>

        <!-- Carpetas -->
        <div class="bg-white rounded-2xl p-5 shadow-sm border">
          <h2 class="font-semibold mb-4 flex items-center gap-2"><i class="fa-regular fa-folder-open"></i> Carpetas</h2>
          <div class="text-sm">
            <div>Origen (inbox): <code><?php echo e($config['paths']['local_inbox']); ?></code></div>
            <div>Destino (descargas): <code><?php echo e($config['paths']['download_dir']); ?></code></div>
            <div>Modo: <span class="font-semibold"><?php echo e($config['mode']); ?></span></div>
          </div>
          <div class="mt-3">
            <details class="text-sm">
              <summary class="cursor-pointer select-none text-gray-700">Archivos en inbox (<?php echo count($inbox); ?>)</summary>
              <ul class="mt-2 list-disc pl-5"><?php foreach($inbox as $f): ?><li><?php echo e($f); ?></li><?php endforeach; ?></ul>
            </details>
            <details class="text-sm mt-2">
              <summary class="cursor-pointer select-none text-gray-700">Descargados (<?php echo count($descargas); ?>)</summary>
              <ul class="mt-2 grid md:grid-cols-2 gap-2">
                <?php foreach($descargas as $f): ?><li class="px-3 py-2 bg-gray-50 border rounded-lg"><?php echo e($f); ?></li><?php endforeach; ?>
              </ul>
            </details>
          </div>
        </div>

        <!-- Historial -->
        <div class="bg-white rounded-2xl p-5 shadow-sm border lg:col-span-2">
          <h2 class="font-semibold mb-4 flex items-center gap-2"><i class="fa-solid fa-timeline"></i> Historial de hoy</h2>
          <?php if($runs_today): ?>
            <ol class="relative border-s border-gray-200 ms-3">
              <?php foreach($runs_today as $h):
                $ok = ($h['status']??'')==='OK';
                $badge = $ok ? 'bg-green-600' : 'bg-red-600';
                $date = e(date('H:i:s', parse_ts($h['date'] ?? 'now')));
              ?>
              <li class="mb-6 ms-6">
                <span class="absolute -start-3 flex h-6 w-6 items-center justify-center rounded-full <?php echo $badge; ?> text-white">
                  <i class="fa-solid <?php echo $ok?'fa-check':'fa-xmark'; ?>"></i>
                </span>
                <div class="flex items-center justify-between">
                  <h3 class="font-semibold"><?php echo $ok?'Ejecución OK':'Ejecución con error'; ?> <span class="text-xs text-gray-500">(<?php echo $date; ?>)</span></h3>
                  <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 border">modo: <?php echo e($h['mode'] ?? 'local'); ?></span>
                </div>
                <div class="text-sm text-gray-700 mt-1">
                  Archivos: <strong><?php echo e($h['files'] ?? 0); ?></strong>
                  <?php if(!empty($h['duration'])): ?> · Duración: <strong><?php echo e($h['duration']); ?></strong><?php endif; ?>
                  <?php if(!empty($h['message'])): ?> · Detalle: <span class="text-gray-600"><?php echo e($h['message']); ?></span><?php endif; ?>
                </div>
                <?php if(!empty($h['errors'])): ?>
                  <pre class="mt-2 text-xs bg-red-50 border border-red-200 text-red-800 rounded-lg p-2 overflow-x-auto"><?php echo e($h['errors']); ?></pre>
                <?php endif; ?>
              </li>
              <?php endforeach; ?>
            </ol>
          <?php else: ?>
            <p class="text-gray-500 text-sm">Aún no hay ejecuciones hoy.</p>
          <?php endif; ?>
        </div>

        <!-- Log -->
        <div class="bg-white rounded-2xl p-5 shadow-sm border">
          <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold flex items-center gap-2"><i class="fa-solid fa-file-lines"></i> Log (últimas 200 líneas)</h2>
            <label class="text-xs flex items-center gap-1">
              <input type="checkbox" id="autorefresh" class="accent-emerald-600"> Autorefresh
            </label>
          </div>
          <pre id="logbox" class="text-xs bg-gray-50 border rounded-lg p-3 overflow-auto max-h-80"><?php echo e(tail_lines($log_file, 200)); ?></pre>
        </div>
      </div>
    </div>
  </main>
</div>

<script>
function toggleCronFields(val){
  document.getElementById('dailyWrap')?.classList.toggle('hidden', val!=='DAILY_AT');
  document.getElementById('customWrap')?.classList.toggle('hidden', val!=='CUSTOM');
}
let timer=null;
function refreshLog(){
  fetch('/sync/log_tail.php?lines=200',{cache:'no-store'}).then(r=>r.text()).then(t=>{
    const el=document.getElementById('logbox');
    const atBottom=el.scrollTop+el.clientHeight>=el.scrollHeight-5;
    el.textContent=t; if(atBottom) el.scrollTop=el.scrollHeight;
  });
}
document.getElementById('autorefresh')?.addEventListener('change', e=>{
  if(e.target.checked){ refreshLog(); timer=setInterval(refreshLog,5000);}
  else { clearInterval(timer); timer=null;}
});
</script>

</body>
</html>
<?php
// vaciar buffer al final para permitir headers internos si sidebar los usa
ob_end_flush();

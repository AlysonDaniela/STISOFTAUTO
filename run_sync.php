<?php
// /sync/run_sync.php — Ejecutable por cron. Registra eventos en history.json para el panel.
// Mantiene lock para evitar solapes. Soporta modo local y (opcional) SFTP con phpseclib.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('Acceso denegado.');
}

$BASE = __DIR__;

// Incluir funciones de email
require_once __DIR__ . '/email_report.php';
$config_file  = $BASE.'/storage/sync/config.json';
$log_file     = $BASE.'/storage/logs/sync.log';
$lock_file    = $BASE.'/storage/sync/sync.lock';
$history_file = $BASE.'/storage/sync/history.json';
$processed_file = $BASE.'/storage/sync/processed_files.json';

function logln(string $msg){
  global $log_file;
  $line = '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL;
  if(!is_dir(dirname($log_file))) @mkdir(dirname($log_file), 0775, true);
  file_put_contents($log_file, $line, FILE_APPEND);
  echo $line;
}
function read_json($file){ return is_file($file) ? json_decode(file_get_contents($file), true) : null; }
function write_json($file, $data){ if(!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true); file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }
function ensure_dir($d){ if(!is_dir($d)) @mkdir($d, 0775, true); }

$config = read_json($config_file);
if(!$config){
  logln('ERROR: No existe config: '.$config_file);
  exit(1);
}

// Lock
ensure_dir(dirname($lock_file));
$fp = fopen($lock_file, 'c+');
if(!$fp){ logln('ERROR: No se pudo abrir lockfile'); exit(1); }
if(!flock($fp, LOCK_EX|LOCK_NB)){
  logln('Otro proceso en ejecución. Abortando.');
  exit(0);
}

$start = microtime(true);
$event = [
  'date'     => date('Y-m-d H:i:s'),
  'mode'     => $config['mode'] ?? 'local',
  'status'   => 'ERROR',
  'files'    => 0,
  'duration' => null,
  'message'  => '',
  'errors'   => ''
];

// Tracking de archivos procesados por tipo
$processedStats = [
  'empleados' => ['count' => 0, 'files' => []],
  'bajas' => ['count' => 0, 'files' => []],
  'division' => ['count' => 0, 'files' => []],
  'cargos' => ['count' => 0, 'files' => []]
];

try{
  $mode = $config['mode'] ?? 'local';
  $dest = $config['paths']['download_dir'] ?? ($BASE.'/storage/descargas');
  ensure_dir($dest);

  logln("Inicio sync. Modo=$mode");

  if($mode === 'local'){
    $src = $config['paths']['local_inbox'] ?? ($BASE.'/storage/sftp_inbox');
    if(!is_dir($src)){
      $event['message'] = "Origen local no existe: $src";
      logln("WARN: " . $event['message']);
      $event['status'] = 'ERROR';
    } else {
      $moved = 0;
      $dh = opendir($src);
      while(($f = readdir($dh)) !== false){
        if($f==='.'||$f==='..') continue;
        $from = rtrim($src,'/').'/'.$f;
        if(is_file($from)){
          // filtrar opcionalmente por extensión CSV/ZIP (maqueta: permitir todo)
          $to = rtrim($dest,'/').'/'.$f;
          if(is_file($to)) $to = rtrim($dest,'/').'/'.date('Ymd_His')."_$f";
          if(@rename($from, $to)){
            $moved++;
            logln("Movido: $f -> $to");
          } else {
            if(@copy($from,$to)){
              @unlink($from);
              $moved++;
              logln("Copiado: $f -> $to");
            } else {
              logln("ERROR moviendo: $from");
            }
          }
        }
      }
      closedir($dh);
      $event['files'] = $moved;
      $event['status'] = 'OK';
      $event['message'] = "Local/Mock OK. Archivos movidos: $moved";
      logln($event['message']);
    }
  } else {
    // SFTP real (opcional con phpseclib)
    $vendor = dirname($BASE).'/vendor/autoload.php';
    if(!is_file($vendor)){
      $msg = "SFTP requiere phpseclib. No se encontró vendor/autoload.php";
      $event['message'] = $msg;
      logln("ERROR: $msg");
      $event['status'] = 'ERROR';
    } else {
      require_once $vendor;
      $s = $config['sftp'];
      $client = new \phpseclib3\Net\SFTP($s['host'], (int)$s['port'], 15);
      if(!$client->login($s['username'], $s['password'])){
        $msg = "Login SFTP inválido";
        $event['message'] = $msg;
        logln("ERROR: $msg");
        $event['status'] = 'ERROR';
      } else {
        $remote = $s['remote_path'] ?? '/out';
        $list = $client->rawlist($remote) ?: [];
        $count = 0;
        $processed = read_json($processed_file) ?: [];

        // Si hay varios archivos de empleados tomamos sólo el más reciente de cada origen
        $latestEmps = [];
        foreach ($list as $name => $meta) {
          if (preg_match('/^(EMPLEADOS_MAIPO|EMPLEADOS_STI|EVENTUALES_MAIPO)_/i', $name, $m)) {
            $grp = strtoupper($m[1]);
            $mtime = $meta['mtime'] ?? 0;
            if (!isset($latestEmps[$grp]) || $mtime > ($latestEmps[$grp]['mtime'] ?? 0)) {
              $latestEmps[$grp] = ['name' => $name, 'mtime' => $mtime];
            }
          }
        }
        // reconstruir lista dejando sólo los últimos de cada grupo y todo lo demás (bajas/division/cargos)
        $filtered = [];
        foreach ($list as $name => $meta) {
          if (isset($latestEmps['EMPLEADOS_MAIPO']) && $latestEmps['EMPLEADOS_MAIPO']['name'] === $name) {
            $filtered[$name] = $meta;
          } elseif (isset($latestEmps['EMPLEADOS_STI']) && $latestEmps['EMPLEADOS_STI']['name'] === $name) {
            $filtered[$name] = $meta;
          } elseif (isset($latestEmps['EVENTUALES_MAIPO']) && $latestEmps['EVENTUALES_MAIPO']['name'] === $name) {
            $filtered[$name] = $meta;
          } elseif (!preg_match('/^(EMPLEADOS_MAIPO|EMPLEADOS_STI|EVENTUALES_MAIPO)_/i', $name)) {
            $filtered[$name] = $meta;
          }
        }
        $list = $filtered;

        foreach($list as $name=>$meta){
          if($name==='.'||$name==='..') continue;
          if(($meta['type'] ?? 0) !== 1) continue; // 1=file, 2=dir
          if (isset($processed[$name])) continue; // ya procesado
          $remoteFile = rtrim($remote,'/').'/'.$name;
          $localFile = rtrim($dest,'/').'/'.$name;
          if(is_file($localFile)) $localFile = rtrim($dest,'/').'/'.date('Ymd_His')."_$name";
          $data = $client->get($remoteFile);
          if($data===false){
            logln("ERROR descargando: $remoteFile");
            continue;
          }
          if(file_put_contents($localFile, $data) !== false){
            $count++;
            logln("Descargado: $remoteFile -> $localFile");
            
            // Procesar automáticamente según tipo de archivo
            $nameUpper = strtoupper($name);
            $procesador = null;
            $tipo = null;
            
            if (preg_match('/^(EMPLEADOS_MAIPO|EMPLEADOS_STI|EVENTUALES_MAIPO)_/', $nameUpper)) {
              $procesador = $BASE.'/process_empleados.php';
              $tipo = 'empleados';
            } elseif (preg_match('/^BAJAS_/', $nameUpper)) {
              $procesador = $BASE.'/process_bajas.php';
              $tipo = 'bajas';
            } elseif (preg_match('/^DIVISION_/', $nameUpper)) {
              $procesador = $BASE.'/process_division.php';
              $tipo = 'division';
            } elseif (preg_match('/^CARGOS_/', $nameUpper)) {
              $procesador = $BASE.'/process_cargos.php';
              $tipo = 'cargos';
            }
            
            if ($procesador && is_file($procesador) && $tipo) {
              $cmd = "php " . escapeshellarg($procesador) . " " . escapeshellarg($localFile) . " 2>&1";
              logln("Procesando $tipo: $cmd");
              $output = shell_exec($cmd);
              logln("Output: $output");

              // Registrar estadísticas y parsear números clave del output
              $stats = [];
              if ($tipo === 'empleados') {
                  if (preg_match('/Procesado:.*?(\d+) filas insertadas/', $output, $m)) {
                      $stats['rows'] = (int)$m[1];
                  }
                  if (preg_match('/Buk -> empleados enviados:\s*(\d+)/', $output, $m)) {
                      $stats['buk_sent'] = (int)$m[1];
                  }
                  if (preg_match('/Buk -> jobs creados:\s*(\d+)/', $output, $m)) {
                      $stats['buk_jobs'] = (int)$m[1];
                  }
              } elseif ($tipo === 'bajas') {
                  if (preg_match('/Detectadas:\s*(\d+)/', $output, $m)) {
                      $stats['detected'] = (int)$m[1];
                  }
              } elseif ($tipo === 'division') {
                  if (preg_match('/División:\s*(\d+)/', $output, $m)) {
                      $stats['count'] = (int)$m[1];
                  }
              } elseif ($tipo === 'cargos') {
                  if (preg_match('/Cargos sincronizados:\s*(\d+)/', $output, $m)) {
                      $stats['count'] = (int)$m[1];
                  }
              }

              $processedStats[$tipo]['count']++;
              $processedStats[$tipo]['files'][] = array_merge([
                'name' => $name,
                'output' => trim($output),
                'timestamp' => date('Y-m-d H:i:s')
              ], $stats);
            }
            
            $processed[$name] = date('Y-m-d H:i:s');
            // $client->delete($remoteFile); // opcional: eliminar después
          }
        }
        write_json($processed_file, $processed);
        $event['files'] = $count;
        $event['status'] = 'OK';
        $event['message'] = "SFTP OK. Archivos descargados: $count";
        logln($event['message']);
      }
    }
  }

} catch(Throwable $t){
  $event['errors'] = $t->getMessage();
  $event['status'] = 'ERROR';
  logln('EXCEPTION: '.$t->getMessage());
} finally {
  $event['duration'] = (function($s){ $d = microtime(true) - $s; return ($d<1)? (round($d*1000)).' ms' : number_format($d,2).' s'; })($start);

  // persistir historial (conservar solo últimos 30 días)
  $hist = read_json($history_file) ?: [];
  $hist[] = $event;
  $threshold = time() - 60*60*24*30; // 30 días atrás en segundos
  $hist = array_filter($hist, function($e) use ($threshold) {
      $ts = strtotime($e['date']);
      return $ts !== false && $ts >= $threshold;
  });
  // reindex y escribir
  $hist = array_values($hist);
  write_json($history_file, $hist);

  // Enviar email de reporte
  $toAddresses = $config['smtp']['to_addresses'] ?? ['alysonvalenzuela94@gmail.com'];
  if (!empty($toAddresses) && is_array($toAddresses)) {
    logln("Generando reporte por email para: " . implode(', ', $toAddresses));
    
    $htmlContent = build_html_report(
      $event['date'],
      $event,
      $processedStats,
      $hist // pasar historial recortado
    );
    
    if (send_report_email($toAddresses, $htmlContent, "Reporte Diario STISOFT", $config['smtp'] ?? null)) {
      logln("Email enviado exitosamente a: " . implode(', ', $toAddresses));
    } else {
      logln("WARN: No se pudo enviar email de reporte");
    }
  }

  // liberar lock
  flock($fp, LOCK_UN);
  fclose($fp);

  logln("Fin sync. Status={$event['status']} Duración={$event['duration']}");
}

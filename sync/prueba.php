<?php
// sync/probar_sftp_ui.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_admin();

ob_start();

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function human_ms(float $sec): string { return number_format($sec*1000, 0, '.', '')." ms"; }
function step(array &$steps, string $title, bool $ok, string $detail=''): void { $steps[] = compact('title','ok','detail'); }
function looks_like_ip(string $h): bool { return filter_var($h, FILTER_VALIDATE_IP) !== false; }
function find_by_prefix(array $names, string $prefix): array {
  $out = [];
  foreach($names as $n){
    $n = trim((string)$n);
    if($n !== '' && str_starts_with($n, $prefix)) $out[] = $n;
  }
  rsort($out);
  return $out;
}
function mask_user(string $u): string {
  if($u === '') return '';
  if(strlen($u) <= 2) return str_repeat('*', strlen($u));
  return substr($u,0,1).str_repeat('*', max(1, strlen($u)-2)).substr($u,-1);
}

// Busca vendor/autoload.php subiendo directorios
function find_autoload(string $startDir, int $maxUp = 8): ?string {
  $dir = $startDir;
  for ($i=0; $i<=$maxUp; $i++){
    $cand = $dir.'/vendor/autoload.php';
    if (is_file($cand)) return $cand;
    $parent = dirname($dir);
    if ($parent === $dir) break;
    $dir = $parent;
  }
  return null;
}

// --- defaults ---
$defaults = [
  'host' => $_POST['host'] ?? '',
  'port' => (int)($_POST['port'] ?? 22),
  'user' => $_POST['user'] ?? '',
  'pass' => $_POST['pass'] ?? '',
  'root_path'  => $_POST['root_path'] ?? '/',
  'renta_path' => $_POST['renta_path'] ?? '/RENTA_FIJA',
  'limit' => (int)($_POST['limit'] ?? 10),
];

$result = [
  'ran' => false,
  'ok' => false,
  'method' => '—',
  'steps' => [],
  'errors' => [],
  'lists' => [
    'root'  => ['EVENTUALES_MAIPO'=>[], 'EMPLEADOS_MAIPO'=>[]],
    'renta' => ['exists'=>null, 'EMPLEADOS_STI'=>[]],
  ],
];

$badge = fn(bool $ok) => $ok
  ? '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-emerald-100 text-emerald-800 border border-emerald-200">OK</span>'
  : '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-rose-100 text-rose-800 border border-rose-200">FAIL</span>';

$autoload = find_autoload(__DIR__);

$has_phpseclib = false;
if ($autoload) {
  require_once $autoload;
  $has_phpseclib = class_exists('\phpseclib3\Net\SFTP');
}

$csrf = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validate($_POST['csrf_token'] ?? '')) {
  $result['ran'] = true;
  $t0 = microtime(true);

  $host = trim((string)$defaults['host']);
  $port = max(1, (int)$defaults['port']);
  $user = trim((string)$defaults['user']);
  $pass = (string)$defaults['pass'];
  $rootPath  = trim((string)$defaults['root_path']) ?: '/';
  $rentaPath = trim((string)$defaults['renta_path']) ?: '/RENTA_FIJA';
  $limit = max(1, min(50, (int)$defaults['limit']));

  if ($host === '' || $user === '') {
    $result['errors'][] = "Debes ingresar Host/IP y Usuario.";
  }

  // DNS / IP
  if (!$result['errors']) {
    if (!looks_like_ip($host)) {
      $d0 = microtime(true);
      $ip = gethostbyname($host);
      $d1 = microtime(true);
      $dnsOk = ($ip !== $host);
      step($result['steps'], "Resolver DNS", $dnsOk, $dnsOk ? "{$host} → {$ip} · ".human_ms($d1-$d0) : "No se pudo resolver {$host}");
    } else {
      step($result['steps'], "Host (IP)", true, $host);
    }
  }

  // Test TCP
  if (!$result['errors']) {
    $c0 = microtime(true);
    $errno = 0; $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, 8);
    $c1 = microtime(true);

    if (!$fp) {
      step($result['steps'], "Conexión TCP", false, "No se pudo abrir socket a {$host}:{$port} · errno={$errno} · {$errstr}");
      $result['errors'][] = "El servidor NO puede salir por SFTP a {$host}:{$port}. Esto suele ser firewall/egress en tu hosting o allowlist en el destino.";
    } else {
      fclose($fp);
      step($result['steps'], "Conexión TCP", true, "{$host}:{$port} · ".human_ms($c1-$c0));
    }
  }

  // phpseclib disponible?
  if (!$result['errors']) {
    if (!$has_phpseclib) {
      step($result['steps'], "phpseclib", false, $autoload ? "autoload OK pero phpseclib3 no está" : "No se encontró vendor/autoload.php (se buscó hacia arriba)");
      $result['errors'][] = $autoload
        ? "autoload encontrado pero falta phpseclib3. Reinstala: composer require phpseclib/phpseclib:^3.0"
        : "No se encontró vendor/autoload.php. Composer instaló en otro directorio.";
    } else {
      step($result['steps'], "phpseclib", true, "autoload: {$autoload}");
    }
  }

  // SFTP login + listados (FIX: no usar isConnected() como condición de error)
  if (!$result['errors']) {
    try {
      $result['method'] = 'phpseclib';

      $l0 = microtime(true);
      $sftp = new \phpseclib3\Net\SFTP($host, $port, 12);

      // Legacy SSH negotiation (como tu comando sftp)
      $sftp->setPreferredAlgorithms([
        'kex' => [
          'diffie-hellman-group14-sha1',
          'diffie-hellman-group1-sha1',
        ],
        'hostkey' => [
          'ssh-rsa',
        ],
        // Servidores viejos suelen requerir CBC / SHA1
        'cipher' => [
          'aes128-cbc',
          'aes256-cbc',
          '3des-cbc',
          'aes128-ctr',
          'aes256-ctr',
        ],
        'mac' => [
          'hmac-sha1',
          'hmac-sha1-96',
        ],
      ]);

      $l1 = microtime(true);
      step($result['steps'], "SFTP init()", true, human_ms($l1-$l0));

      // Aquí se concreta la sesión
      $a0 = microtime(true);
      $okLogin = $sftp->login($user, $pass);
      $a1 = microtime(true);

      if (!$okLogin) {
        step($result['steps'], "Login SFTP", false, "Usuario ".mask_user($user)." · credenciales inválidas / permiso denegado");
        $result['errors'][] = "Login falló. Revisa user/pass o permisos.";
      } else {
        step($result['steps'], "Login SFTP", true, "Usuario ".mask_user($user)." · ".human_ms($a1-$a0));

        // Listar root
        $rootList = $sftp->nlist($rootPath);
        if (!is_array($rootList)) {
          step($result['steps'], "Listar {$rootPath}", false, "No se pudo listar (ruta no existe o sin permisos)");
        } else {
          step($result['steps'], "Listar {$rootPath}", true, "Items: ".count($rootList));
          $ev = find_by_prefix($rootList, 'EVENTUALES_MAIPO_');
          $em = find_by_prefix($rootList, 'EMPLEADOS_MAIPO_');
          $result['lists']['root']['EVENTUALES_MAIPO'] = array_slice($ev, 0, $limit);
          $result['lists']['root']['EMPLEADOS_MAIPO']  = array_slice($em, 0, $limit);
          step($result['steps'], "Buscar EVENTUALES_MAIPO_*", count($ev)>0, count($ev)>0 ? "Encontrados: ".count($ev) : "No hay");
          step($result['steps'], "Buscar EMPLEADOS_MAIPO_*", count($em)>0, count($em)>0 ? "Encontrados: ".count($em) : "No hay");
        }

        // Listar renta
        $rentaList = $sftp->nlist($rentaPath);
        if (!is_array($rentaList)) {
          $result['lists']['renta']['exists'] = false;
          step($result['steps'], "Acceder {$rentaPath}", false, "No existe o sin permisos");
        } else {
          $result['lists']['renta']['exists'] = true;
          step($result['steps'], "Acceder {$rentaPath}", true, "Items: ".count($rentaList));
          $sti = find_by_prefix($rentaList, 'EMPLEADOS_STI_');
          $result['lists']['renta']['EMPLEADOS_STI'] = array_slice($sti, 0, $limit);
          step($result['steps'], "Buscar {$rentaPath}/EMPLEADOS_STI_*", count($sti)>0, count($sti)>0 ? "Encontrados: ".count($sti) : "No hay");
        }

        $result['ok'] = true;
      }
    } catch (Throwable $t) {
      // Aquí veremos el error real del handshake si falta algo
      step($result['steps'], "Excepción", false, $t->getMessage());
      $result['errors'][] = $t->getMessage();
    }
  }

  $t1 = microtime(true);
  $result['elapsed'] = number_format($t1-$t0,2)."s";
}

?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Probar SFTP</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50">
  <div class="max-w-6xl mx-auto p-6">
    <div class="flex items-start justify-between gap-4 mb-6">
      <div>
        <h1 class="text-xl font-semibold">🖥️ Probar conexión SFTP (tipo FileZillaaaaa)</h1>
        <p class="text-sm text-slate-600">Diagnóstico real: DNS → TCP → SFTP → Listados → Prefijos.</p>
      </div>
      <div class="text-sm text-slate-500">
        Autoload: <span class="font-mono"><?= e($autoload ?: 'NO ENCONTRADO') ?></span>
      </div>
    </div>

    <form method="post" class="bg-white rounded-2xl border p-5 mb-6">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <div class="grid md:grid-cols-4 gap-4">
        <div>
          <label class="text-xs text-slate-500">Host / IP</label>
          <input name="host" value="<?= e($defaults['host']) ?>" class="mt-1 w-full rounded-lg border px-3 py-2 font-mono" placeholder="200.54.73.219">
        </div>
        <div>
          <label class="text-xs text-slate-500">Puerto</label>
          <input name="port" type="number" value="<?= (int)$defaults['port'] ?>" class="mt-1 w-full rounded-lg border px-3 py-2 font-mono">
        </div>
        <div>
          <label class="text-xs text-slate-500">Usuario</label>
          <input name="user" value="<?= e($defaults['user']) ?>" class="mt-1 w-full rounded-lg border px-3 py-2 font-mono" placeholder="buk">
        </div>
        <div>
          <label class="text-xs text-slate-500">Contraseña</label>
          <input name="pass" type="password" value="<?= e($defaults['pass']) ?>" class="mt-1 w-full rounded-lg border px-3 py-2 font-mono">
        </div>
      </div>

      <div class="grid md:grid-cols-3 gap-4 mt-4">
        <div>
          <label class="text-xs text-slate-500">Ruta raíz</label>
          <input name="root_path" value="<?= e($defaults['root_path']) ?>" class="mt-1 w-full rounded-lg border px-3 py-2 font-mono" placeholder="/">
        </div>
        <div>
          <label class="text-xs text-slate-500">Ruta RENTA_FIJA</label>
          <input name="renta_path" value="<?= e($defaults['renta_path']) ?>" class="mt-1 w-full rounded-lg border px-3 py-2 font-mono" placeholder="/RENTA_FIJA">
        </div>
        <div>
          <label class="text-xs text-slate-500">Mostrar máximo</label>
          <input name="limit" type="number" value="<?= (int)$defaults['limit'] ?>" class="mt-1 w-full rounded-lg border px-3 py-2 font-mono">
        </div>
      </div>

      <div class="flex items-center justify-between mt-5">
        <div class="text-xs text-slate-500">
          Se buscará: <span class="font-mono">EVENTUALES_MAIPO_*</span>, <span class="font-mono">EMPLEADOS_MAIPO_*</span>, <span class="font-mono">/RENTA_FIJA/EMPLEADOS_STI_*</span>
        </div>
        <button class="px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">Probar conexión</button>
      </div>
    </form>

    <?php if($result['ran']): ?>
      <div class="mb-6 p-4 rounded-2xl border <?= $result['errors'] ? 'bg-rose-50 border-rose-200' : 'bg-emerald-50 border-emerald-200' ?>">
        <div class="flex items-center justify-between gap-3">
          <div class="font-semibold">
            <?= $result['errors'] ? '❌ Falló' : '✅ Conectó (y avanzó)' ?>
          </div>
          <div class="text-sm text-slate-600">Tiempo: <?= e($result['elapsed'] ?? '—') ?></div>
        </div>
        <?php if($result['errors']): ?>
          <ul class="mt-2 list-disc pl-5 text-sm">
            <?php foreach($result['errors'] as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="bg-white rounded-2xl border p-5 mb-6">
        <h2 class="font-semibold mb-3">🧪 Pasos</h2>
        <div class="space-y-2">
          <?php foreach($result['steps'] as $st): ?>
            <div class="flex items-start justify-between gap-3 p-3 rounded-xl border <?= $st['ok'] ? 'bg-emerald-50 border-emerald-200' : 'bg-rose-50 border-rose-200' ?>">
              <div>
                <div class="font-medium"><?= e($st['title']) ?></div>
                <?php if(($st['detail'] ?? '') !== ''): ?>
                  <div class="text-sm text-slate-700 mt-0.5 break-words"><?= e($st['detail']) ?></div>
                <?php endif; ?>
              </div>
              <div><?= $badge((bool)$st['ok']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="grid md:grid-cols-2 gap-6">
        <div class="bg-white rounded-2xl border p-5">
          <h2 class="font-semibold mb-3">📁 / (MAIPO)</h2>
          <div class="mb-4">
            <div class="flex items-center justify-between">
              <div class="font-medium">EVENTUALES_MAIPO_*</div>
              <?= $badge(count($result['lists']['root']['EVENTUALES_MAIPO'])>0) ?>
            </div>
            <?php if($result['lists']['root']['EVENTUALES_MAIPO']): ?>
              <ul class="mt-2 text-sm font-mono list-disc pl-5">
                <?php foreach($result['lists']['root']['EVENTUALES_MAIPO'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
              </ul>
            <?php else: ?><div class="text-sm text-slate-500 mt-1">No encontrado</div><?php endif; ?>
          </div>

          <div>
            <div class="flex items-center justify-between">
              <div class="font-medium">EMPLEADOS_MAIPO_*</div>
              <?= $badge(count($result['lists']['root']['EMPLEADOS_MAIPO'])>0) ?>
            </div>
            <?php if($result['lists']['root']['EMPLEADOS_MAIPO']): ?>
              <ul class="mt-2 text-sm font-mono list-disc pl-5">
                <?php foreach($result['lists']['root']['EMPLEADOS_MAIPO'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
              </ul>
            <?php else: ?><div class="text-sm text-slate-500 mt-1">No encontrado</div><?php endif; ?>
          </div>
        </div>

        <div class="bg-white rounded-2xl border p-5">
          <h2 class="font-semibold mb-3">📂 /RENTA_FIJA (STI)</h2>
          <div class="mb-4 flex items-center justify-between">
            <div class="font-medium">Acceso carpeta</div>
            <?php
              $ex = $result['lists']['renta']['exists'];
              echo $ex === null ? '<span class="text-sm text-slate-500">—</span>' : $badge((bool)$ex);
            ?>
          </div>

          <div>
            <div class="flex items-center justify-between">
              <div class="font-medium">EMPLEADOS_STI_*</div>
              <?= $badge(count($result['lists']['renta']['EMPLEADOS_STI'])>0) ?>
            </div>
            <?php if($result['lists']['renta']['EMPLEADOS_STI']): ?>
              <ul class="mt-2 text-sm font-mono list-disc pl-5">
                <?php foreach($result['lists']['renta']['EMPLEADOS_STI'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
              </ul>
            <?php else: ?><div class="text-sm text-slate-500 mt-1">No encontrado</div><?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
<?php ob_end_flush(); ?>

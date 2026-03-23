<?php
/**
 * bajas.php
 * - Flujo manual (existente): buscar por RUT (AJAX) y terminar en Buk (PATCH /jobs/{buk_job_id}/terminate)
 * - NUEVO flujo automático:
 *   1) Subir archivo ADP (CSV/XLSX) de EMPLEADOS_MAIPO / EMPLEADOS_STI / EVENTUALES_MAIPO
 *   2) Detectar bajas: si en BD estaba Estado='A' y en archivo viene distinto de 'A' => DocDetectaBaja=1
 *   3) Pantalla lista DocDetectaBaja=1 (Pendiente) y DocDetectaBaja=2 (OK), con botón masivo y por fila
 *   4) Si termina OK en Buk => DocDetectaBaja=2
 */

ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_auth();
$user = current_user();
$csrf = csrf_token();

if (session_status() === PHP_SESSION_NONE) session_start();
ini_set('default_charset', 'UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

/* ============== LAYOUT PARTIALS ============== */
$headPath    = __DIR__ . '/../partials/head.php';
$sidebarPath = __DIR__ . '/../partials/sidebar.php';
$topbarPath  = __DIR__ . '/../partials/topbar.php';
$footerPath  = __DIR__ . '/../partials/footer.php';

/* ============== DB ============== */
require_once __DIR__ . '/../conexion/db.php';
require_once __DIR__ . '/../includes/runtime_config.php';
if (!class_exists('clsConexion')) die("No existe clsConexion (revisa ../conexion/db.php).");

function DB(): clsConexion { static $db=null; if(!$db) $db=new clsConexion(); return $db; }
function esc($s){ return DB()->real_escape_string((string)$s); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function rut_norm($rut): string {
  $rut = strtoupper(trim((string)$rut));
  return preg_replace('/[^0-9K]/','', $rut);
}
function rut_pretty($rn): string {
  $rn = strtoupper(trim((string)$rn));
  if (strlen($rn) < 2) return $rn;
  return substr($rn,0,-1).'-'.substr($rn,-1);
}
function date_to_iso($s): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  // yyyy-mm-dd
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  // dd/mm/yyyy
  if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
    $d=str_pad($m[1],2,'0',STR_PAD_LEFT);
    $mo=str_pad($m[2],2,'0',STR_PAD_LEFT);
    return $m[3]."-{$mo}-{$d}";
  }
  // dd-mm-yyyy
  if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $s, $m)) {
    $d=str_pad($m[1],2,'0',STR_PAD_LEFT);
    $mo=str_pad($m[2],2,'0',STR_PAD_LEFT);
    return $m[3]."-{$mo}-{$d}";
  }
  return null;
}

/* ============== BUK API ============== */
$bukCfg = runtime_buk_config();
define('BUK_API_BASE', $bukCfg['base']);
define('BUK_TOKEN', $bukCfg['token']);

const LOG_DIR = __DIR__ . '/logs_buk_terminate';
if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);

function save_log(string $prefix, array $data): void {
  if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
  $fn = LOG_DIR.'/'.$prefix.'_'.date('Ymd_His').'.json';
  file_put_contents($fn, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

function buk_terminate_job(int $buk_job_id, array $payload): array {
  $url = BUK_API_BASE . "/jobs/{$buk_job_id}/terminate";
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => "PATCH",
    CURLOPT_HTTPHEADER => [
      "Content-Type: application/json",
      "Accept: application/json",
      "auth_token: " . BUK_TOKEN
    ],
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_TIMEOUT => 60
  ]);

  $respBody = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlErr = curl_error($ch);
  curl_close($ch);

  return ['http'=>$http,'body'=>$respBody,'curl_error'=>$curlErr,'url'=>$url];
}

/* ============== Reasons (API Buk) ============== */
$REASONS = [
  "mutuo_acuerdo","renuncia","muerte","vencimiento_plazo","fin_servicio","caso_fortuito",
  "falta_probidad","acoso_sexual","vias_de_hecho","injurias","conducta_inmoral","acoso_laboral",
  "negociaciones_prohibidas","no_concurrencia","abandonar_trabajo","faltas_seguridad",
  "perjuicio_material","incumplimiento","necesidades_empresa","desahucio_gerente"
];

/**
 * Mapeo ADP -> Buk (según Excel entregado).
 * - Prioridad: Cod ADP (numérico) si existe
 * - Fallback: texto (descripcion) con contains
 */
$REASON_MAP_BY_CODE = [
  1  => 'mutuo_acuerdo',
  2  => 'renuncia',
  3  => 'muerte',
  4  => 'vencimiento_plazo',
  5  => 'vencimiento_plazo',
  6  => 'caso_fortuito',
  11 => 'faltas_seguridad',
  14 => 'necesidades_empresa',
  15 => 'desahucio_gerente',
];
$REASON_MAP_BY_TEXT = [
  'mutuo acuerdo' => 'mutuo_acuerdo',
  'renuncia' => 'renuncia',
  'muerte' => 'muerte',
  'vencimiento' => 'vencimiento_plazo',
  'caso fortuito' => 'caso_fortuito',
  'fuerza mayor' => 'caso_fortuito',
  'faltas seguridad' => 'faltas_seguridad',
  'necesidades de la empresa' => 'necesidades_empresa',
  'desahucio' => 'desahucio_gerente',
];

function map_reason_to_buk($motivoCode, $motivoDesc, array $byCode, array $byText): ?string {
  $code = null;
  if ($motivoCode !== null && $motivoCode !== '') {
    $tmp = preg_replace('/[^0-9]/','', (string)$motivoCode);
    if ($tmp !== '') $code = (int)$tmp;
  }
  if ($code !== null && isset($byCode[$code])) return $byCode[$code];

  $t = mb_strtolower(trim((string)$motivoDesc));
  if ($t !== '') {
    foreach ($byText as $needle => $api) {
      if (strpos($t, $needle) !== false) return $api;
    }
  }
  return null;
}

/* ============== CSV/XLSX parser ============== */
function parse_uploaded_file_rows(array $file): array {
  $tmp = $file['tmp_name'] ?? '';
  $name = $file['name'] ?? '';
  if (!$tmp || !is_uploaded_file($tmp)) return ['ok'=>false,'err'=>'Archivo no recibido correctamente.'];

  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

  // ===== CSV (preferido) =====
  if ($ext === 'csv' || $ext === 'txt') {
    $fh = fopen($tmp, 'r');
    if (!$fh) return ['ok'=>false,'err'=>'No se pudo abrir el CSV.'];

    // Detect delimiter using first line
    $first = fgets($fh);
    if ($first === false) return ['ok'=>false,'err'=>'CSV vacío.'];
    $delims = [','=>substr_count($first, ','), ';'=>substr_count($first,';'), '\t'=>substr_count($first, "\t")];
    arsort($delims);
    $delim = array_key_first($delims);
    if ($delim === '\t') $delim = "\t";
    rewind($fh);

    $rows = [];
    $headers = null;
    while (($cols = fgetcsv($fh, 0, $delim)) !== false) {
      // Limpia BOM/espacios
      $cols = array_map(function($v){
        $v = (string)$v;
        $v = preg_replace('/^\xEF\xBB\xBF/', '', $v); // BOM
        return trim($v);
      }, $cols);

      if ($headers === null) {
        $headers = $cols;
        continue;
      }
      if (count(array_filter($cols, fn($x)=>$x!=='')) === 0) continue;

      $row = [];
      foreach ($headers as $i=>$h) $row[$h] = $cols[$i] ?? '';
      $rows[] = $row;
    }
    fclose($fh);
    return ['ok'=>true,'rows'=>$rows,'filename'=>$name];
  }

  // ===== XLSX (si existe PhpSpreadsheet) =====
  if ($ext === 'xlsx') {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
      return ['ok'=>false,'err'=>'Subiste XLSX pero no existe vendor/autoload.php (PhpSpreadsheet). Sube CSV o instala PhpSpreadsheet.'];
    }
    require_once $autoload;

    try {
      $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
      $spreadsheet = $reader->load($tmp);
      $sheet = $spreadsheet->getActiveSheet();
      $data = $sheet->toArray(null, true, true, true);

      if (!$data || count($data) < 2) return ['ok'=>false,'err'=>'XLSX vacío o sin datos.'];

      $headers = array_values($data[1]);
      $rows = [];
      for ($r=2; $r<=count($data); $r++) {
        $vals = array_values($data[$r]);
        if (count(array_filter($vals, fn($x)=>trim((string)$x)!=='')) === 0) continue;
        $row=[];
        foreach ($headers as $i=>$h) $row[trim((string)$h)] = trim((string)($vals[$i] ?? ''));
        $rows[]=$row;
      }
      return ['ok'=>true,'rows'=>$rows,'filename'=>$name];
    } catch (Throwable $e) {
      return ['ok'=>false,'err'=>'Error leyendo XLSX: '.$e->getMessage()];
    }
  }

  return ['ok'=>false,'err'=>'Formato no soportado. Usa CSV (recomendado) o XLSX.'];
}

/* ============== AJAX: búsqueda por RUT (manual) ============== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'rut') {
  header('Content-Type: application/json; charset=utf-8');

  $q = rut_norm($_GET['q'] ?? '');
  if ($q === '' || strlen($q) < 3) {
    echo json_encode(['ok'=>true,'items'=>[]], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $sql = "
    SELECT
      Rut,
      Nombres, Apaterno, Amaterno,
      buk_job_id
    FROM adp_empleados
    WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ','')) LIKE '".esc($q)."%'
    ORDER BY Rut
    LIMIT 10
  ";
  $rows = DB()->consultar($sql) ?: [];

  $items = [];
  foreach ($rows as $r) {
    $rn = rut_norm($r['Rut'] ?? '');
    $nombre = trim((string)($r['Nombres']??'').' '.(string)($r['Apaterno']??'').' '.(string)($r['Amaterno']??''));
    $items[] = [
      'rut_norm'   => $rn,
      'rut'        => rut_pretty($rn),
      'nombre'     => $nombre,
      'buk_job_id' => ($r['buk_job_id'] === null || $r['buk_job_id'] === '') ? null : (int)$r['buk_job_id'],
    ];
  }

  echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ============== HANDLERS ============== */
$flash = ['ok'=>[], 'err'=>[]];
function flash_ok($m){ global $flash; $flash['ok'][]=$m; }
function flash_err($m){ global $flash; $flash['err'][]=$m; }
$post_csrf_ok = $_SERVER['REQUEST_METHOD'] !== 'POST' || csrf_validate($_POST['csrf_token'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$post_csrf_ok) {
  flash_err('La sesión de seguridad expiró. Recarga la pantalla e inténtalo nuevamente.');
}

/* ----------- 1) UPLOAD + DETECT BAJAS ----------- */
if ($post_csrf_ok && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'upload_detect') {
  $file = $_FILES['adp_file'] ?? null;
  if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    flash_err('Debes seleccionar un archivo.');
  } else {
    $fname = $file['name'] ?? '';
    $upper = strtoupper($fname);
    $isEmpleados = (strpos($upper,'EMPLEADOS_MAIPO') !== false || strpos($upper,'EMPLEADOS_STI') !== false);
    $isEventuales = (strpos($upper,'EVENTUALES_MAIPO') !== false);

    // Para EMPLEADOS_*, restringimos la detección/actualización SOLO al origen correspondiente.
    // Para EVENTUALES_MAIPO se usa la lógica por ausencia en el archivo.
    $targetOrigin = null;
    if (strpos($upper,'EMPLEADOS_MAIPO') !== false) $targetOrigin = 'EMPLEADOS_MAIPO';
    if (strpos($upper,'EMPLEADOS_STI') !== false)   $targetOrigin = 'EMPLEADOS_STI';
    if (strpos($upper,'EVENTUALES_MAIPO') !== false) $targetOrigin = 'EVENTUALES_MAIPO';

    if (!$isEmpleados && !$isEventuales) {
      flash_err("El archivo debe llamarse/contener EMPLEADOS_MAIPO, EMPLEADOS_STI o EVENTUALES_MAIPO (nombre actual: {$fname}).");
    } else {
      $parsed = parse_uploaded_file_rows($file);
      if (!$parsed['ok']) {
        flash_err($parsed['err']);
      } else {
        $rows = $parsed['rows'];
        // Normaliza headers conocidos
        $headerMap = [
          'rut' => ['rut','RUT','Rut','RUT Trabajador','Rut Trabajador'],
          'estado' => ['Estado','estado','ESTADO'],
          'fecha_retiro' => ['Fecha de Retiro','fecha retiro','Fecha Retiro','FECHA DE RETIRO'],
          'fecha_ingreso' => ['Fecha de Ingreso','fecha ingreso','Fecha Ingreso','FECHA DE INGRESO'],
          'motivo' => ['Motivo de Retiro','Motivo Retiro','motivo de retiro','Motivo','MOTIVO'],
          'motivo_desc' => ['Descripcion Motivo de Retiro','Descripción Motivo de Retiro','Descripcion Motivo Retiro','Descripción Motivo Retiro','Descripcion','DESCRIPCION'],
        ];
        $getVal = function(array $row, array $keys){
          foreach ($keys as $k) {
            foreach ($row as $rk=>$rv) {
              if (mb_strtolower(trim((string)$rk)) === mb_strtolower(trim((string)$k))) return $rv;
            }
          }
          return '';
        };

        // Detecta nombre real de la columna "Origen ADP" en adp_empleados (compatibilidad)
        $originField = null;
        $cols = DB()->consultar("SHOW COLUMNS FROM adp_empleados") ?: [];
        foreach ($cols as $c) {
          $f = (string)($c['Field'] ?? '');
          $fLow = strtolower($f);
          if (in_array($fLow, ['origenadp','origen_adp','tipo_origen_adp','origen adp'], true)) { $originField = $f; break; }
        }
        if (!$originField) $originField = 'tipo_origen_adp';

        
        // ========= EVENTUALES_MAIPO =========
        // Regla: si en BD existe un colaborador EVENTUALES_MAIPO con Estado='A' y NO viene en el nuevo archivo,
        //        entonces se marca baja y se termina en Buk con:
        //        - Fecha de baja: hoy
        //        - Motivo fijo ADP: 159 N°5 (Cod ADP 5) => Buk: vencimiento_plazo
        if ($isEventuales) {
          $present = [];
          $skipped = 0;

          foreach ($rows as $rEv) {
            $rutRaw = $getVal($rEv, $headerMap['rut']);
            $rut = rut_norm($rutRaw);
            if (!$rut) { $skipped++; continue; }
            $present[$rut] = true;
          }

          $today = date('Y-m-d');

          // Trae eventuales activos desde BD
          $sqlAct = "
            SELECT Rut, `Estado` AS EstadoDB, `DocDetectaBaja` AS DocDetectaBajaDB
            FROM adp_empleados
            WHERE UPPER(TRIM(`".$originField."`)) = 'EVENTUALES_MAIPO'
              AND UPPER(TRIM(`Estado`)) = 'A'
          ";
          $activos = DB()->consultar($sqlAct) ?: [];

          $detected = 0; $updatedEstado = 0;

          foreach ($activos as $empEv) {
            $rutDb = rut_norm($empEv['Rut'] ?? '');
            if (!$rutDb) continue;

            if (!isset($present[$rutDb])) {
              $docBajaDB = (int)($empEv['DocDetectaBajaDB'] ?? 0);
              if ($docBajaDB < 2) {
                DB()->ejecutar("
                  UPDATE adp_empleados
                  SET
                    `DocDetectaBaja`=1,
                    `Estado`='E',
                    `Motivo de Retiro`='5',
                    `Descripcion Motivo de Retiro`='N°5: Conclusión del Trabajo o Servicio',
                    `Fecha de Retiro`='".$today."'
                  WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ',''))='".esc($rutDb)."'
                  LIMIT 1
                ");
                $detected++;
                $updatedEstado++;
              }
            }
          }

          flash_ok("Procesado EVENTUALES_MAIPO: {$parsed['filename']}. Bajas detectadas (por ausencia): {$detected}. Estado actualizado: {$updatedEstado}. Omitidos (RUT inválido): {$skipped}.");
        } else {

$detected = 0; $updatedEstado = 0; $notFound = 0; $skipped = 0;

        foreach ($rows as $r) {
          $rutRaw = $getVal($r, $headerMap['rut']);
          $rut = rut_norm($rutRaw);
          if (!$rut) { $skipped++; continue; }

          $newEstado = trim((string)$getVal($r, $headerMap['estado']));
          $newEstado = strtoupper($newEstado);

          $fechaRetiro = date_to_iso($getVal($r, $headerMap['fecha_retiro']));
          $fechaIngreso = date_to_iso($getVal($r, $headerMap['fecha_ingreso']));
          $motivo = trim((string)$getVal($r, $headerMap['motivo']));
          $motivoDesc = trim((string)$getVal($r, $headerMap['motivo_desc']));

          // Busca en BD
          $sqlFind = "
            SELECT Rut, `Estado` AS EstadoDB, `DocDetectaBaja` AS DocDetectaBajaDB
            FROM adp_empleados
            WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ','')) = '".esc($rut)."'
              AND UPPER(TRIM(`".$originField."`)) = '".esc($targetOrigin ?: '')."'
            LIMIT 1
          ";
          $rowDb = DB()->consultar($sqlFind);
          $rowDb = $rowDb && isset($rowDb[0]) ? $rowDb[0] : null;
          if (!$rowDb) { $notFound++; continue; }

          $estadoDB = strtoupper(trim((string)($rowDb['EstadoDB'] ?? '')));
          $docBajaDB = (int)($rowDb['DocDetectaBajaDB'] ?? 0);

          // Actualiza Estado en BD si viene distinto y no vacío
          if ($newEstado !== '' && $newEstado !== $estadoDB) {
            DB()->ejecutar("UPDATE adp_empleados SET `Estado`='".esc($newEstado)."' WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ',''))='".esc($rut)."' AND UPPER(TRIM(`".$originField."`))='".esc($targetOrigin ?: '')."' LIMIT 1");
            $updatedEstado++;
          }

          // Detecta baja: venía activo y ahora no
          if ($estadoDB === 'A' && $newEstado !== '' && $newEstado !== 'A') {
            if ($docBajaDB < 2) {
              DB()->ejecutar("
                UPDATE adp_empleados
                SET
                  `DocDetectaBaja`=1,
                  `Motivo de Retiro`='".esc($motivo)."',
                  `Descripcion Motivo de Retiro`='".esc($motivoDesc)."',
                  `Fecha de Retiro`=".( $fechaRetiro ? "'".esc($fechaRetiro)."'" : "NULL" ).",
                  `Fecha de Ingreso`=".( $fechaIngreso ? "'".esc($fechaIngreso)."'" : "NULL" )."
                WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ',''))='".esc($rut)."'
                  AND UPPER(TRIM(`".$originField."`)) = '".esc($targetOrigin ?: '')."'
                LIMIT 1
              ");
              $detected++;
            }
          }
        }

        flash_ok("Procesado archivo: {$parsed['filename']}. Detectadas: {$detected}. Estado actualizado: {$updatedEstado}. No encontrados: {$notFound}. Omitidos: {$skipped}.");
        }
      }
    }
  }
}

/* ----------- 2) TERMINAR (AUTO) POR FILA / MASIVO ----------- */
function mark_baja_ok_by_rut(string $rut): void {
  DB()->ejecutar("UPDATE adp_empleados SET `DocDetectaBaja`=2 WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ',''))='".esc($rut)."' LIMIT 1");
}

function get_empleado_by_rut_norm(string $rut): ?array {
  $sql = "
    SELECT
      Rut, Nombres, Apaterno, Amaterno,
      buk_job_id, buk_emp_id,
      `Estado`, `DocDetectaBaja`,
      `Fecha de Retiro`, `Fecha de Ingreso`,
      `Motivo de Retiro`, `Descripcion Motivo de Retiro`
    FROM adp_empleados
    WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ',''))='".esc($rut)."'
    LIMIT 1
  ";
  $rows = DB()->consultar($sql) ?: [];
  return $rows[0] ?? null;
}

if ($post_csrf_ok && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'terminate_one') {
  $rut = rut_norm($_POST['rut_norm'] ?? '');
  if (!$rut) {
    flash_err('RUT inválido.');
  } else {
    $emp = get_empleado_by_rut_norm($rut);
    if (!$emp) {
      flash_err('No se encontró el empleado en BD.');
    } else {
      $buk_job_id = (int)($emp['buk_job_id'] ?? 0);
      if ($buk_job_id <= 0) {
        flash_err('Empleado sin buk_job_id (no se puede terminar).');
      } else {
        $fechaRetiro = date_to_iso($emp['Fecha de Retiro'] ?? '');
        $start = trim((string)($_POST['start_date'] ?? ($fechaRetiro ?: '')));
        $end   = trim((string)($_POST['end_date'] ?? ($fechaRetiro ?: '')));
        $reason = trim((string)($_POST['termination_reason'] ?? ''));

        if (!$start || !$end) {
          flash_err("{$emp['Rut']}: falta start_date/end_date (Fecha de Retiro vacía).");
        } elseif (!in_array($reason, $REASONS, true)) {
          flash_err("{$emp['Rut']}: razón inválida.");
        } else {
          $payload = ["start_date"=>$start, "end_date"=>$end, "termination_reason"=>$reason];
          $res = buk_terminate_job($buk_job_id, $payload);

          save_log("auto_terminate_job{$buk_job_id}", [
            'rut' => $rut,
            'buk_job_id' => $buk_job_id,
            'payload' => $payload,
            'http' => $res['http'],
            'curl_error' => $res['curl_error'],
            'response' => $res['body'],
            'url' => $res['url'],
          ]);

          if ($res['curl_error']) {
            flash_err("{$emp['Rut']}: Error cURL: {$res['curl_error']}");
          } elseif (!$res['body']) {
            flash_err("{$emp['Rut']}: Sin respuesta del servidor.");
          } elseif ($res['http'] >= 200 && $res['http'] < 300) {
            mark_baja_ok_by_rut($rut);
            flash_ok("{$emp['Rut']}: OK terminado en Buk (HTTP {$res['http']}).");
          } else {
            flash_err("{$emp['Rut']}: Buk error (HTTP {$res['http']}). Revisa log.");
          }
        }
      }
    }
  }
}

if ($post_csrf_ok && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'terminate_mass') {
  $items = $_POST['items'] ?? [];
  if (!is_array($items) || count($items) === 0) {
    flash_err('Debes seleccionar al menos 1 pendiente.');
  } else {
    $ok=0; $err=0;
    foreach ($items as $rutItem) {
      $rut = rut_norm($rutItem);
      if (!$rut) { $err++; continue; }
      $emp = get_empleado_by_rut_norm($rut);
      if (!$emp) { $err++; continue; }
      if ((int)($emp['DocDetectaBaja'] ?? 0) !== 1) continue; // solo pendientes

      $buk_job_id = (int)($emp['buk_job_id'] ?? 0);
      if ($buk_job_id <= 0) { $err++; continue; }

      $fechaRetiro = date_to_iso($emp['Fecha de Retiro'] ?? '');
      $start = $fechaRetiro ?: '';
      $end   = $fechaRetiro ?: '';

      $reason = map_reason_to_buk($emp['Motivo de Retiro'] ?? '', $emp['Descripcion Motivo de Retiro'] ?? '', $GLOBALS['REASON_MAP_BY_CODE'], $GLOBALS['REASON_MAP_BY_TEXT']);
      if (!$start || !$end || !$reason) { $err++; continue; } // si no hay data, queda para manual por fila

      $payload = ["start_date"=>$start, "end_date"=>$end, "termination_reason"=>$reason];
      $res = buk_terminate_job($buk_job_id, $payload);

      save_log("auto_mass_job{$buk_job_id}", [
        'rut' => $rut,
        'buk_job_id' => $buk_job_id,
        'payload' => $payload,
        'http' => $res['http'],
        'curl_error' => $res['curl_error'],
        'response' => $res['body'],
        'url' => $res['url'],
      ]);

      if (!$res['curl_error'] && $res['body'] && $res['http'] >= 200 && $res['http'] < 300) {
        mark_baja_ok_by_rut($rut);
        $ok++;
      } else {
        $err++;
      }
    }
    flash_ok("Masivo terminado. OK: {$ok}. Con error / pendientes por completar (sin fecha/motivo mapeado): {$err}.");
  }
}



/* ============== DATA: LISTADO BAJAS DETECTADAS ============== */
$bajas = DB()->consultar("
  SELECT
    Rut, Nombres, Apaterno, Amaterno,
    buk_job_id, buk_emp_id,
    `Estado`, `DocDetectaBaja`,
    `Fecha de Retiro` AS fecha_retiro,
    `Motivo de Retiro` AS motivo_retiro,
    `Descripcion Motivo de Retiro` AS motivo_desc
  FROM adp_empleados
  WHERE `DocDetectaBaja` IN (1,2)
  ORDER BY
    CASE WHEN `DocDetectaBaja`=1 THEN 0 ELSE 1 END,
    `Fecha de Retiro` DESC
") ?: [];

/* Precalcula razón sugerida por fila */
foreach ($bajas as &$b) {
  $b['rut_norm'] = rut_norm($b['Rut'] ?? '');
  $b['full_name'] = trim((string)($b['Nombres']??'').' '.(string)($b['Apaterno']??'').' '.(string)($b['Amaterno']??''));
  $b['fecha_iso'] = date_to_iso($b['fecha_retiro'] ?? '') ?: '';
  $b['reason_suggest'] = map_reason_to_buk($b['motivo_retiro'] ?? '', $b['motivo_desc'] ?? '', $REASON_MAP_BY_CODE, $REASON_MAP_BY_TEXT);
}
unset($b);
?>
<?php include $headPath; ?>
<body class="bg-gray-50">
<div class="min-h-screen grid grid-cols-12">
  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php $active='bajas'; include $sidebarPath; ?>
  </div>

  <div class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col">
    <?php include $topbarPath; ?>

    <main class="flex-grow max-w-6xl mx-auto p-6 space-y-6">

      <!-- FLASH -->
      <?php if (!empty($flash['ok'])): ?>
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-900 rounded-xl p-4">
          <?php foreach ($flash['ok'] as $m): ?>
            <div>✅ <?=e($m)?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($flash['err'])): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-900 rounded-xl p-4">
          <?php foreach ($flash['err'] as $m): ?>
            <div>⚠️ <?=e($m)?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- HEADER -->
      <section class="space-y-2">
        <div class="flex items-center justify-between">
          <h1 class="text-xl font-semibold flex items-center gap-2">
            <i class="fa-solid fa-user-slash text-rose-600"></i> Bajas (detección + término en Buk)
          </h1>
          <div class="text-xs text-gray-500">DocDetectaBaja: 1 = Pendiente · 2 = OK</div>
        </div>
      </section>

      <!-- UPLOAD DETECCIÓN -->
      <section class="bg-white border rounded-2xl p-5 shadow-sm space-y-4">
        <div class="flex items-start justify-between gap-4 flex-wrap">
          <div>
            <div class="font-semibold">1) Subir archivo ADP y detectar bajas</div>
            <div class="text-sm text-gray-500">
              Solo procesa archivos cuyo nombre contenga <b>EMPLEADOS_MAIPO</b> o <b>EMPLEADOS_STI</b>.
              Detecta baja cuando en BD estaba <b>Estado = A</b> y en el archivo viene distinto.
            </div>
          </div>
          <form method="POST" enctype="multipart/form-data" class="flex items-center gap-3">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="upload_detect">
            <input type="file" name="adp_file" accept=".csv,.txt,.xlsx" class="text-sm">
            <button class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm hover:bg-slate-800">
              Detectar bajas
            </button>
          </form>
        </div>
      </section>

      <!-- LISTADO BAJAS DETECTADAS -->
      <section class="bg-white border rounded-2xl p-5 shadow-sm space-y-4">
        <div class="flex items-center justify-between flex-wrap gap-3">
          <div>
            <div class="font-semibold">2) Listado de bajas detectadas</div>
            <div class="text-sm text-gray-500">Pendientes (DocDetectaBaja=1) y OK (DocDetectaBaja=2).</div>
          </div>

          <button type="button" id="btnMass" class="px-4 py-2 rounded-lg bg-rose-600 text-white text-sm hover:bg-rose-700">
              Terminar masivo (seleccionados)
            </button>
        </div>

        <div class="overflow-auto border rounded-xl">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-700">
              <tr>
                <th class="p-3 text-left w-10">Sel</th>
                <th class="p-3 text-left">RUT</th>
                <th class="p-3 text-left">Empleado</th>
                <th class="p-3 text-left">Estado</th>
                <th class="p-3 text-left">Fecha retiro</th>
                <th class="p-3 text-left">Motivo (ADP)</th>
                <th class="p-3 text-left">Razón Buk sugerida</th>
                <th class="p-3 text-left">buk_job_id</th>
                <th class="p-3 text-left">Acción</th>
                <th class="p-3 text-left">Resultado</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($bajas)): ?>
                <tr><td colspan="10" class="p-4 text-gray-500">No hay bajas detectadas (DocDetectaBaja=1/2).</td></tr>
              <?php endif; ?>

              <?php foreach ($bajas as $b): 
                $isPend = ((int)$b['DocDetectaBaja']===1);
                $badge = $isPend ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-900';
                $badgeTxt = $isPend ? 'PENDIENTE' : 'OK';
                $reasonSuggest = $b['reason_suggest'] ?? null;
                $hasAllForMass = ($isPend && $b['fecha_iso'] && $reasonSuggest && (int)$b['buk_job_id']>0);
              ?>
                <tr class="border-t">
                  <td class="p-3">
                    <?php if ($isPend): ?>
                      <input type="checkbox" name="items[]" value="<?=e($b['rut_norm'])?>" form="massForm"
                             <?= $hasAllForMass ? '' : 'disabled' ?>
                             title="<?= $hasAllForMass ? 'Listo para masivo' : 'Falta fecha/motivo mapeado o buk_job_id' ?>">
                    <?php endif; ?>
                  </td>
                  <td class="p-3 whitespace-nowrap">
                    <div class="font-medium"><?=e(rut_pretty($b['rut_norm']))?></div>
                    <div class="text-xs inline-block px-2 py-0.5 rounded-full <?=$badge?>"><?=$badgeTxt?></div>
                  </td>
                  <td class="p-3"><?=e($b['full_name'])?></td>
                  <td class="p-3"><?=e($b['Estado'] ?? '')?></td>
                  <td class="p-3 whitespace-nowrap">
                    <?= $b['fecha_iso'] ? e($b['fecha_iso']) : '<span class="text-rose-600">Sin fecha</span>' ?>
                  </td>
                  <td class="p-3">
                    <div><?=e($b['motivo_retiro'] ?? '')?></div>
                    <div class="text-xs text-gray-500"><?=e($b['motivo_desc'] ?? '')?></div>
                  </td>
                  <td class="p-3">
                    <?php if ($reasonSuggest): ?>
                      <span class="text-xs px-2 py-1 rounded-full bg-slate-100 text-slate-800"><?=e($reasonSuggest)?></span>
                    <?php else: ?>
                      <span class="text-rose-600">Sin mapeo</span>
                    <?php endif; ?>
                  </td>
                  <td class="p-3"><?=e((string)($b['buk_job_id'] ?? ''))?></td>
                  <td class="p-3">
                    <?php if ($isPend): ?>
                      <form method="POST" class="flex items-center gap-2">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="terminate_one">
                        <input type="hidden" name="rut_norm" value="<?=e($b['rut_norm'])?>">
                        <input type="date" name="start_date" class="border rounded px-2 py-1 text-xs" value="<?=e($b['fecha_iso'])?>">
                        <input type="date" name="end_date" class="border rounded px-2 py-1 text-xs" value="<?=e($b['fecha_iso'])?>">
                        <select name="termination_reason" class="border rounded px-2 py-1 text-xs">
                          <option value="">-- Razón --</option>
                          <?php foreach ($REASONS as $rr): ?>
                            <option value="<?=e($rr)?>" <?= ($reasonSuggest===$rr ? 'selected' : '') ?>><?=e($rr)?></option>
                          <?php endforeach; ?>
                        </select>
                        <button class="px-3 py-1 rounded bg-rose-600 text-white text-xs hover:bg-rose-700">Terminar</button>
                      </form>
                    <?php else: ?>
                      <span class="text-gray-500">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="p-3 text-xs text-gray-500">
                    <?php if ($isPend && !$hasAllForMass): ?>
                      Falta: 
                      <?php if ((int)$b['buk_job_id']<=0): ?>buk_job_id<?php endif; ?>
                      <?php if (!$b['fecha_iso']): ?><?= ((int)$b['buk_job_id']<=0) ? ', ' : '' ?>fecha<?php endif; ?>
                      <?php if (!$reasonSuggest): ?><?= (((int)$b['buk_job_id']<=0)||(!$b['fecha_iso'])) ? ', ' : '' ?>mapeo motivo<?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Form real para checkboxes masivo -->
        <form method="POST" id="massForm" class="hidden">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="terminate_mass">
        </form>

        <div class="text-xs text-gray-500">
          Nota: el botón masivo solo procesa filas pendientes que tienen <b>Fecha de Retiro</b>, <b>Motivo mapeado</b> y <b>buk_job_id</b>.
          Las demás quedan para ejecutar por fila (seleccionando razón).
        </div>
      </section>

      

    </main>

  </div>
</div>

<script>
(function(){
  const input = document.getElementById('rutInput');
  const box = document.getElementById('suggestBox');
  const empLabel = document.getElementById('empLabel');
  const jobLabel = document.getElementById('jobLabel');
  const jobHidden = document.getElementById('bukJobId');

  if(!input) return;

  let timer = null;

  function clearBox(){
    box.innerHTML = '';
    box.classList.add('hidden');
  }

  function pick(item){
    input.value = item.rut;
    empLabel.textContent = item.nombre || '—';
    jobLabel.textContent = item.buk_job_id ? item.buk_job_id : '—';
    jobHidden.value = item.buk_job_id ? item.buk_job_id : '';
    clearBox();
  }

  input.addEventListener('input', function(){
    const q = input.value || '';
    empLabel.textContent = '—';
    jobLabel.textContent = '—';
    jobHidden.value = '';
    if(timer) clearTimeout(timer);

    timer = setTimeout(async ()=>{
      const qq = q.trim();
      if(qq.length < 3){ clearBox(); return; }

      try{
        const res = await fetch('?ajax=rut&q=' + encodeURIComponent(qq));
        const data = await res.json();
        if(!data.ok){ clearBox(); return; }
        const items = data.items || [];
        if(items.length === 0){ clearBox(); return; }

        box.innerHTML = items.map(it => `
          <button type="button" class="w-full text-left px-3 py-2 hover:bg-gray-50"
                  data-rut="${it.rut}" data-rut_norm="${it.rut_norm}"
                  data-nombre="${(it.nombre||'').replace(/"/g,'&quot;')}"
                  data-job="${it.buk_job_id||''}">
            <div class="font-medium">${it.rut}</div>
            <div class="text-xs text-gray-500">${it.nombre || ''} ${it.buk_job_id ? '· job_id ' + it.buk_job_id : ''}</div>
          </button>
        `).join('');
        box.classList.remove('hidden');

        box.querySelectorAll('button').forEach(btn=>{
          btn.addEventListener('click', ()=>{
            pick({
              rut: btn.dataset.rut,
              rut_norm: btn.dataset.rut_norm,
              nombre: btn.dataset.nombre,
              buk_job_id: btn.dataset.job ? parseInt(btn.dataset.job,10) : null
            });
          });
        });

      }catch(e){
        clearBox();
      }
    }, 250);
  });

  
  const btnMass = document.getElementById('btnMass');
  const massForm = document.getElementById('massForm');
  if(btnMass && massForm){
    btnMass.addEventListener('click', ()=>{
      massForm.submit();
    });
  }

  document.addEventListener('click', function(ev){
    if(!box.contains(ev.target) && ev.target !== input) clearBox();
  });
})();
</script>
</body>
</html>

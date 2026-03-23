<?php
/**
 * rliquid/index.php - Lotes de Liquidaciones (ADP -> PDFs -> Buk)
 * Todo en 1 archivo. Sin liquidacion.php. Sin cURL interno.
 *
 * Requiere:
 *  - ../conexion/db.php (clsConexion)
 *  - /rliquid/vendor/autoload.php con FPDF (y opcional PhpSpreadsheet)
 */

register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "FATAL: {$e['message']}\nFILE: {$e['file']}\nLINE: {$e['line']}\n";
  }
});

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) session_start();

ob_start();

if (!defined('RLIQUID_BOOTSTRAP_ONLY') || RLIQUID_BOOTSTRAP_ONLY !== true) {
  require_once __DIR__ . '/../includes/auth.php';
  require_auth();
  $user = current_user();

  // Layout (mismo look que tus otros index)
  $headPath   = __DIR__ . '/../partials/head.php';
  $sidebarPath= __DIR__ . '/../partials/sidebar.php';
  $topbarPath = __DIR__ . '/../partials/topbar.php';
  $footerPath = __DIR__ . '/../partials/footer.php';
} else {
  $user = ['name' => 'Worker', 'email' => null];
  $headPath = $sidebarPath = $topbarPath = $footerPath = null;
}
@set_time_limit(900);
@ini_set('max_execution_time', '900');
@ini_set('memory_limit', '1024M');

/* ===================== CONFIG ===================== */
const RLIQUID_PDF_THROTTLE_US = 250000;
const RLIQUID_SEND_THROTTLE_US = 500000;
const RLIQUID_PDF_BATCH_SIZE = 10;
const RLIQUID_SEND_BATCH_SIZE = 10;

/* ===================== PATHS ===================== */
$baseTmp   = __DIR__ . '/tmp_liq';
$uploadDir = $baseTmp . '/uploads';
$cacheDir  = $baseTmp . '/csv_cache';
$pdfRoot   = $baseTmp . '/pdfs';     // pdfs/job_<id>/<rut>.pdf
$logDir    = $baseTmp . '/logs';

foreach ([$baseTmp,$uploadDir,$cacheDir,$pdfRoot,$logDir] as $d) {
  if (!is_dir($d)) @mkdir($d, 0775, true);
}

/* ===================== DB ===================== */
$dbFile = __DIR__ . '/../conexion/db.php';
if (!is_file($dbFile)) die("No se encontró ../conexion/db.php desde ". __DIR__);
require_once $dbFile;
if (!class_exists('clsConexion')) die("db.php cargó pero no existe clsConexion.");

$bukRuntimeCfg = function_exists('runtime_buk_config') ? runtime_buk_config() : [];
define('BUK_API_BASE', rtrim((string)($bukRuntimeCfg['base'] ?? 'https://sti.buk.cl/api/v1/chile'), '/') . '/employees');
define('BUK_TOKEN', (string)($bukRuntimeCfg['token'] ?? ''));

function DB(): clsConexion { static $db=null; if(!$db) $db=new clsConexion(); return $db; }
function esc($s){ return DB()->real_escape_string((string)$s); }

/* ===================== HELPERS ===================== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function rut_norm($rut): string { $rut=strtoupper(trim((string)$rut)); return preg_replace('/[^0-9K]/','',$rut); }
function rut_display($rn): string { $rn=strtoupper(trim((string)$rn)); if(strlen($rn)<2) return $rn; return substr($rn,0,-1).'-'.substr($rn,-1); }
function ames_to_label($ames): string { $ames=preg_replace('/[^0-9]/','',(string)$ames); return (strlen($ames)===6)?(substr($ames,0,4).'-'.substr($ames,4,2)):$ames; }


function month_name_only($ames): string {
  $ames = preg_replace('/[^0-9]/','', (string)$ames);
  if (strlen($ames) !== 6) return $ames;
  $m = (int)substr($ames,4,2);
  $months = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
  return $months[$m] ?? 'Mes';
}

function parse_monto($v): float {
  $v = trim((string)$v);
  if ($v==='') return 0.0;
  $v = str_replace(' ', '', $v);
  $v = str_replace('.', '', $v);
  $v = str_replace(',', '.', $v);
  return is_numeric($v) ? (float)$v : 0.0;
}
function fmt_clp($n): string {
  return '$ ' . number_format((float)$n, 0, ',', '.');
}
function upload_error_message(int $code): string {
  $map = [
    UPLOAD_ERR_OK => 'OK',
    UPLOAD_ERR_INI_SIZE => 'El archivo supera el tamaño máximo permitido por el servidor.',
    UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño máximo permitido por el formulario.',
    UPLOAD_ERR_PARTIAL => 'El archivo se cargó de forma parcial.',
    UPLOAD_ERR_NO_FILE => 'No seleccionaste ningún archivo.',
    UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal del servidor.',
    UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo en disco.',
    UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la carga del archivo.',
  ];
  return $map[$code] ?? ('Error de carga desconocido (código '.$code.').');
}
function ini_bytes(string $value): int {
  $value = trim($value);
  if ($value === '') return 0;
  $unit = strtolower(substr($value, -1));
  $num = (float)$value;
  switch ($unit) {
    case 'g': return (int)round($num * 1024 * 1024 * 1024);
    case 'm': return (int)round($num * 1024 * 1024);
    case 'k': return (int)round($num * 1024);
    default: return (int)round((float)$value);
  }
}
function fmt_bytes_short(int $bytes): string {
  if ($bytes >= 1024 * 1024 * 1024) return round($bytes / (1024 * 1024 * 1024), 1).' GB';
  if ($bytes >= 1024 * 1024) return round($bytes / (1024 * 1024), 1).' MB';
  if ($bytes >= 1024) return round($bytes / 1024, 1).' KB';
  return $bytes.' B';
}
function month_label($ames): string {
  $ames = preg_replace('/[^0-9]/','',(string)$ames);
  if (strlen($ames)!==6) return $ames;
  $y = (int)substr($ames,0,4);
  $m = (int)substr($ames,4,2);
  $meses = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
  $mn = $meses[$m] ?? 'Mes';
  return $mn . " del " . $y;
}
function rliquid_retention_days(): int {
  $defaultDays = 90;
  $cfg = __DIR__ . '/../sync/storage/sync/config.json';
  if (!is_file($cfg)) return $defaultDays;
  $json = json_decode((string)file_get_contents($cfg), true);
  if (!is_array($json)) return $defaultDays;
  $days = (int)($json['retention']['rliquid_pdf_days'] ?? $defaultDays);
  return max(1, $days);
}
function cleanup_old_pdfs(string $pdfRoot, int $days): int {
  $cut = time() - ($days * 86400);
  $deleted = 0;
  if (!is_dir($pdfRoot)) return 0;
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pdfRoot, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'pdf') {
      if ($file->getMTime() < $cut) { @unlink($file->getPathname()); $deleted++; }
    }
  }
  return $deleted;
}
$rliquidRetentionDays = rliquid_retention_days();
$deletedOld = cleanup_old_pdfs($pdfRoot, $rliquidRetentionDays);

/* ===================== TABLES (Jobs + Items) ===================== */
function ensure_tables() {
  $db = DB();
  $db->ejecutar("CREATE TABLE IF NOT EXISTS buk_liq_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ames VARCHAR(6) NOT NULL,
    tipo ENUM('mes','dia25') NOT NULL DEFAULT 'mes',
    source_filename VARCHAR(255) NULL,
    xlsx_path VARCHAR(500) NULL,
    csv_path VARCHAR(500) NULL,
    created_by VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

  $db->ejecutar("CREATE TABLE IF NOT EXISTS buk_liq_job_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    rut_norm VARCHAR(12) NOT NULL,
    ames VARCHAR(6) NOT NULL,
    buk_emp_id INT NULL,

    nombre VARCHAR(150) NULL,
    cargo VARCHAR(150) NULL,
    ubicacion VARCHAR(150) NULL,
    ccosto VARCHAR(150) NULL,
    fecha_ingreso VARCHAR(30) NULL,
    dias VARCHAR(20) NULL,
    zona VARCHAR(120) NULL,
    forma_pago VARCHAR(120) NULL,
    banco VARCHAR(120) NULL,
    cta VARCHAR(120) NULL,

    neto DECIMAL(12,2) NOT NULL DEFAULT 0,

    pdf_path VARCHAR(700) NULL,
    pdf_ok TINYINT(1) NOT NULL DEFAULT 0,
    pdf_error MEDIUMTEXT NULL,

    visible TINYINT(1) NOT NULL DEFAULT 0,
    signable TINYINT(1) NOT NULL DEFAULT 0,
    http_code INT NULL,
    send_ok TINYINT(1) NOT NULL DEFAULT 0,
    response_body MEDIUMTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_job_rut (job_id, rut_norm),
    INDEX idx_job (job_id),
    INDEX idx_job_pdf (job_id, pdf_ok),
    INDEX idx_job_send (job_id, send_ok)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

  $db->ejecutar("CREATE TABLE IF NOT EXISTS buk_liq_job_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    run_type ENUM('process_all') NOT NULL DEFAULT 'process_all',
    status ENUM('queued','running','done','error') NOT NULL DEFAULT 'queued',
    requested_by VARCHAR(150) NULL,
    requested_email VARCHAR(190) NULL,
    notify_email VARCHAR(500) NULL,
    options_json LONGTEXT NULL,
    summary_json LONGTEXT NULL,
    error_message MEDIUMTEXT NULL,
    log_path VARCHAR(700) NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_runs_job (job_id),
    INDEX idx_job_runs_status (status),
    INDEX idx_job_runs_created (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

  // Tu tabla histórica por rut+ames (si existe, se usa también). No la tocamos.
}
ensure_tables();

/* ===================== Vendor (FPDF + optional PhpSpreadsheet) ===================== */
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
  die("Falta /rliquid/vendor/autoload.php (FPDF).");
}
require_once $autoload;
if (!class_exists('FPDF')) die("No se encontró FPDF (revisa vendor/autoload.php).");

$hasSpreadsheet = class_exists('\PhpOffice\PhpSpreadsheet\IOFactory');

/* ===================== CSV PARSE: summary + latest ames per rut ===================== */
function csv_delim_detect(string $path): string {
  $h = fopen($path, 'r'); if(!$h) return ',';
  $first = fgets($h); fclose($h);
  if ($first === false) return ',';
  return (substr_count($first,';') > substr_count($first,',')) ? ';' : ',';
}

function build_summary_from_csv(string $csvPath): array {
  $delim = csv_delim_detect($csvPath);
  $h = fopen($csvPath,'r'); if(!$h) throw new Exception("No se pudo abrir CSV.");

  $header = fgetcsv($h, 0, $delim);
  if(!$header) throw new Exception("CSV sin encabezado.");

  $idx=[]; foreach($header as $i=>$c) $idx[trim((string)$c)]=$i;
  foreach(['Codigo','Ames','Tipo','Monto','Inform'] as $c) if(!isset($idx[$c])) throw new Exception("CSV sin columna: $c");

  // 1) latest ames por rut
  $latest=[];
  $amesCount=[];
  while(($row=fgetcsv($h,0,$delim))!==false){
    $rut = rut_norm($row[$idx['Codigo']] ?? '');
    if(!$rut) continue;
    $ames = preg_replace('/[^0-9]/','',(string)($row[$idx['Ames']] ?? ''));
    if(!$ames) continue;
    $amesCount[$ames]=($amesCount[$ames]??0)+1;
    if(!isset($latest[$rut]) || strcmp($ames, $latest[$rut])>0) $latest[$rut]=$ames;
  }
  $ames_global='';
  if($amesCount){ arsort($amesCount); $ames_global=array_key_first($amesCount); }

  // 2) compute net for latest only
  rewind($h);
  fgetcsv($h,0,$delim);

  $acc=[];
  while(($row=fgetcsv($h,0,$delim))!==false){
    $rut = rut_norm($row[$idx['Codigo']] ?? '');
    if(!$rut || !isset($latest[$rut])) continue;
    $ames = preg_replace('/[^0-9]/','',(string)($row[$idx['Ames']] ?? ''));
    if($ames !== $latest[$rut]) continue;

    $inform = strtoupper(trim((string)($row[$idx['Inform']] ?? '')));
    if($inform !== 'N') continue;

    $tipo = (int)trim((string)($row[$idx['Tipo']] ?? 0));
    $monto = parse_monto($row[$idx['Monto']] ?? 0);

    if(!isset($acc[$rut])) $acc[$rut]=['rut'=>$rut,'ames'=>$ames,'hab'=>0.0,'desc'=>0.0];
    if($tipo===1 || $tipo===2) $acc[$rut]['hab'] += $monto;
    if($tipo===3 || $tipo===4) $acc[$rut]['desc'] += $monto;
  }
  fclose($h);

  $summary=[];
  foreach($acc as $a){
    $summary[]=['rut'=>$a['rut'],'ames'=>$a['ames'],'neto'=>($a['hab']-$a['desc'])];
  }
  usort($summary, fn($x,$y)=>strcmp($x['rut'],$y['rut']));
  return ['summary'=>$summary,'ames_global'=>$ames_global];
}

/* ===================== EMPLOYEE PREFETCH (adp_empleados) ===================== */
function prefetch_employees(array $rutNormList): array {
  $db=DB();
  $rutNormList=array_values(array_unique(array_filter($rutNormList)));
  if(!$rutNormList) return [];

  $chunks=array_chunk($rutNormList, 700);
  $map=[];
  foreach($chunks as $part){
    $in = "'" . implode("','", array_map('addslashes',$part)) . "'";
    $sql = "SELECT
      UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ','')) AS rut_norm,
      Rut,
      Nombres, Apaterno, Amaterno,
      `Descripcion Cargo` as Cargo,
      `Descripcion Ubicacion` as Ubicacion,
      `Descripcion Centro de Costo` as CC,
      `Fecha de Ingreso` as FechaIngreso,
      `Descripcion Zona Asignacion` as Zona,
      `Descripcion Forma de Pago 1` as FormaPago,
      `Descripcion Banco fpago1` as Banco,
      `Cuenta Corriente fpago1` as Cta,
      Empresa,
      `Descripcion Empresa` as EmpresaDesc,
      buk_emp_id
    FROM adp_empleados
    WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ','')) IN ($in)";
    $rows = $db->consultar($sql) ?: [];
    foreach($rows as $r){
      $rn = rut_norm($r['rut_norm'] ?? '');
      $nombre = trim((string)($r['Nombres']??'').' '.(string)($r['Apaterno']??'').' '.(string)($r['Amaterno']??''));
      $map[$rn]=[
        'buk_emp_id'=> ($r['buk_emp_id']===null || $r['buk_emp_id']==='') ? null : (int)$r['buk_emp_id'],
        'nombre'=>$nombre,
        'cargo'=>(string)($r['Cargo']??''),
        'ubicacion'=>(string)($r['Ubicacion']??''),
        'ccosto'=>(string)($r['CC']??''),
        'fecha_ingreso'=>(string)($r['FechaIngreso']??''),
        'zona'=>(string)($r['Zona']??''),
        'forma_pago'=>(string)($r['FormaPago']??''),
        'banco'=>(string)($r['Banco']??''),
        'cta'=>(string)($r['Cta']??''),
        'empresa_id'=>(string)($r['Empresa']??''),
        'empresa_desc'=>(string)($r['EmpresaDesc']??''),
      ];
    }
  }
  return $map;
}

function rliquid_item_needs_employee_data(array $item): bool {
  if (($item['buk_emp_id'] ?? null) !== null && (string)($item['buk_emp_id'] ?? '') !== '') return false;
  foreach (['nombre','cargo','ubicacion','ccosto','fecha_ingreso','zona','forma_pago','banco','cta'] as $field) {
    if (trim((string)($item[$field] ?? '')) !== '') return false;
  }
  return true;
}

function rliquid_hydrate_items_employee_data(int $jobId, array $items): array {
  if (!$items) return [];

  $rutsToFetch = [];
  foreach ($items as $item) {
    if (rliquid_item_needs_employee_data($item)) {
      $rutsToFetch[] = (string)($item['rut_norm'] ?? '');
    }
  }

  $empMap = prefetch_employees($rutsToFetch);
  if (!$empMap) return $items;

  foreach ($items as &$item) {
    $rut = (string)($item['rut_norm'] ?? '');
    if ($rut === '' || !isset($empMap[$rut])) continue;

    $emp = $empMap[$rut];
    $item['buk_emp_id'] = $emp['buk_emp_id'] ?? null;
    $item['nombre'] = $emp['nombre'] ?? '';
    $item['cargo'] = $emp['cargo'] ?? '';
    $item['ubicacion'] = $emp['ubicacion'] ?? '';
    $item['ccosto'] = $emp['ccosto'] ?? '';
    $item['fecha_ingreso'] = $emp['fecha_ingreso'] ?? '';
    $item['zona'] = $emp['zona'] ?? '';
    $item['forma_pago'] = $emp['forma_pago'] ?? '';
    $item['banco'] = $emp['banco'] ?? '';
    $item['cta'] = $emp['cta'] ?? '';
    $item['empresa_id'] = $emp['empresa_id'] ?? '';
    $item['empresa_desc'] = $emp['empresa_desc'] ?? '';

    DB()->ejecutar("UPDATE buk_liq_job_items SET
      buk_emp_id=".($item['buk_emp_id']===null ? "NULL" : (int)$item['buk_emp_id']).",
      nombre='".esc((string)$item['nombre'])."',
      cargo='".esc((string)$item['cargo'])."',
      ubicacion='".esc((string)$item['ubicacion'])."',
      ccosto='".esc((string)$item['ccosto'])."',
      fecha_ingreso='".esc((string)$item['fecha_ingreso'])."',
      zona='".esc((string)$item['zona'])."',
      forma_pago='".esc((string)$item['forma_pago'])."',
      banco='".esc((string)$item['banco'])."',
      cta='".esc((string)$item['cta'])."'
      WHERE job_id=".(int)$jobId." AND id=".(int)$item['id']);
  }
  unset($item);

  return $items;
}

/* ===================== PDF BUILD (FPDF) - embebido ===================== */
function calcular_antiguedad($fechaIngreso, $ames) {
  if (!$fechaIngreso || !$ames) return '';
  $ingreso = DateTime::createFromFormat('d/m/Y', $fechaIngreso);
  if (!$ingreso) $ingreso = DateTime::createFromFormat('Y-m-d', $fechaIngreso);
  if (!$ingreso) return '';
  $year = substr($ames, 0, 4);
  $month = substr($ames, 4, 2);
  $liq = new DateTime("$year-$month-01"); $liq->modify('last day of this month');
  $diff = $ingreso->diff($liq);
  $txt = [];
  if ($diff->y > 0) $txt[] = $diff->y . " años";
  if ($diff->m > 0) $txt[] = $diff->m . " meses";
  return $txt ? implode(", ", $txt) : "Menos de 1 mes";
}
function _tramo_1_999($n){
  $u=['','uno','dos','tres','cuatro','cinco','seis','siete','ocho','nueve','diez','once','doce','trece','catorce','quince','dieciséis','diecisiete','dieciocho','diecinueve'];
  $d=['','', 'veinte','treinta','cuarenta','cincuenta','sesenta','setenta','ochenta','noventa'];
  $c=['','ciento','doscientos','trescientos','cuatrocientos','quinientos','seiscientos','setecientos','ochocientos','novecientos'];
  if($n==0) return 'cero';
  if($n==100) return 'cien';
  $txt=''; $cent=intdiv($n,100); $n%=100;
  if($cent) $txt.=$c[$cent].' ';
  if($n<20) $txt.=$u[$n];
  else{
    $dec=intdiv($n,10); $uni=$n%10;
    if($dec==2 && $uni>0) $txt.='veinti'.$u[$uni];
    else { $txt.=$d[$dec]; if($uni) $txt.=' y '.$u[$uni]; }
  }
  return trim($txt);
}
function numero_a_letras($num){
  $num=(int)round($num);
  if($num==0) return 'cero pesos';
  $parts=[];
  $mill=intdiv($num,1000000); $num%=1000000;
  $mil=intdiv($num,1000); $num%=1000;
  $res=$num;
  if($mill) $parts[] = ($mill==1?'un millón': _tramo_1_999($mill).' millones');
  if($mil)  $parts[] = ($mil==1?'mil': _tramo_1_999($mil).' mil');
  if($res){
    $r=_tramo_1_999($res);
    $r=preg_replace('/\buno\b/u','un',$r);
    $parts[]=$r;
  }
  return mb_convert_case(trim(implode(' ',$parts)).' pesos', MB_CASE_LOWER,'UTF-8');
}

function get_empresa_data($empresaId) {
  // si CSV trae Empresa, aquí puedes ajustar. Default MUELLAJE DEL MAIPO
  $empresaId = (int)$empresaId;
  $data = [
    'NOMBRE'=>'MUELLAJE DEL MAIPO S.A.',
    'RUT'=>'99506030-2',
    'DIR'=>'AV. Bernardo Ohiggins 2263',
    'LOGO'=> __DIR__ . '/img/LogoMuellajedelMaipo.jpg',
    'LOGO_X'=>1.6,'LOGO_Y'=>-4,'LOGO_W'=>26
  ];
  if ($empresaId === 1) {
    $data['NOMBRE']='SAN ANTONIO TERMINAL';
    $data['RUT']='96908970-K';
    $data['DIR']='Avda Bernardo Ohiggins 2263';
    $data['LOGO']= __DIR__ . '/img/LogoSTI.jpg';
    $data['LOGO_X']=5; $data['LOGO_Y']=-8; $data['LOGO_W']=19;
  } elseif ($empresaId === 2) {
    $data['NOMBRE']='MUELLAJE STI S.A.';
    $data['RUT']='96915770-5';
    $data['DIR']='Avda. Ramón Barros Luco 1613 13 1301';
    $data['LOGO']= __DIR__ . '/img/LogoMuellaje.jpg';
    $data['LOGO_X']=3; $data['LOGO_Y']=-8; $data['LOGO_W']=21;
  }
  return $data;
}

function get_empresa_data_from_hint($empresaId, string $empresaDesc = ''): array {
  $empresaIdNorm = (int)preg_replace('/[^0-9]/', '', (string)$empresaId);
  if ($empresaIdNorm > 0) {
    return get_empresa_data($empresaIdNorm);
  }

  $desc = mb_strtolower(trim($empresaDesc), 'UTF-8');
  if ($desc !== '') {
    if (strpos($desc, 'san antonio terminal') !== false || preg_match('/\bsti\b/u', $desc)) {
      return get_empresa_data(1);
    }
    if (strpos($desc, 'muellaje sti') !== false) {
      return get_empresa_data(2);
    }
    if (strpos($desc, 'muellaje del maipo') !== false || strpos($desc, 'maipo') !== false) {
      return get_empresa_data(0);
    }
  }

  return get_empresa_data(0);
}

function term_row_monto($row, $idx) { return parse_monto($row[$idx['Monto']] ?? ''); }

function build_sections_from_term_csv($csvPath, $rut, $ames) {
  $sections = [
    'HABERES AFECTOS'=>[],
    'OTROS HABERES'=>[],
    'DESCUENTOS LEGALES'=>[],
    'OTROS DESCUENTOS'=>[],
  ];
  $tot=['hab'=>0,'desc'=>0];
  $info=[
    'Empresa'=>'',
    'EmpresaDesc'=>'',
    'Nombre'=>'','Cargo'=>'','Lugar'=>'','Ccosto'=>'',
    'Codigo'=>'','Rut'=>'','FechaIng'=>'','Dias'=>'',
    'Antig'=>'','Zona'=>'','Pago'=>'','Banco'=>'','Cta'=>''
  ];

  $delim = csv_delim_detect($csvPath);
  $h=fopen($csvPath,'r'); if(!$h) return [$sections,$tot,$info];
  $header=fgetcsv($h,0,$delim); if(!$header){ fclose($h); return [$sections,$tot,$info]; }
  $idx=[]; foreach($header as $i=>$col) $idx[trim((string)$col)]=$i;

  $pick=function($row,$names) use($idx){
    foreach($names as $n){
      if(isset($idx[$n])){
        $v=trim((string)($row[$idx[$n]]??''));
        if($v!=='') return $v;
      }
    }
    return '';
  };

  while(($row=fgetcsv($h,0,$delim))!==false){
  $rCodigoN = rut_norm($row[$idx['Codigo']] ?? '');
  $rAmesN   = preg_replace('/[^0-9]/','', (string)($row[$idx['Ames']] ?? ''));

  // $rut y $ames ya vienen normalizados desde el item
  if($rCodigoN !== $rut) continue;
  if($rAmesN !== $ames) continue;

  $inform = strtoupper(trim((string)($row[$idx['Inform']] ?? '')));
  if($inform !== 'N') continue;

  $tipo = trim((string)($row[$idx['Tipo']] ?? ''));
  $desc = trim((string)($row[$idx['Descitm']] ?? ''));
  $m    = parse_monto($row[$idx['Monto']] ?? 0);

  $vo='';
  if(isset($idx['VO'])) $vo=trim((string)($row[$idx['VO']]??''));
  elseif(isset($idx['V.O'])) $vo=trim((string)($row[$idx['V.O']]??''));

  if ($info['Empresa'] === '') {
    $info['Empresa'] = $pick($row, ['Empresa', 'empresa', 'empresa_id', 'company_id']);
  }
  if ($info['EmpresaDesc'] === '') {
    $info['EmpresaDesc'] = $pick($row, ['Descripcion Empresa', 'Descripción Empresa', 'Empresa Desc', 'EmpresaDescripcion', 'Razon Social', 'Razón Social', 'Empresa Nombre']);
  }

  $out=['detalle'=>$desc,'vo'=>$vo,'hab'=>0,'desc'=>0];

  if($tipo==='1'){ $out['hab']=$m; $sections['HABERES AFECTOS'][]=$out; $tot['hab']+=$m; }
  elseif($tipo==='2'){ $out['hab']=$m; $sections['OTROS HABERES'][]=$out; $tot['hab']+=$m; }
  elseif($tipo==='3'){ $out['desc']=$m; $sections['DESCUENTOS LEGALES'][]=$out; $tot['desc']+=$m; }
  elseif($tipo==='4'){ $out['desc']=$m; $sections['OTROS DESCUENTOS'][]=$out; $tot['desc']+=$m; }
}
  fclose($h);

  foreach($sections as $k=>$arr){
    usort($arr,function($a,$b){
      $am = ($b['hab'] ?: $b['desc']) <=> ($a['hab'] ?: $a['desc']);
      return $am ?: strcmp($a['detalle'],$b['detalle']);
    });
    $sections[$k]=$arr;
  }
  return [$sections,$tot,$info];
}

function pdf_section_header($pdf, $x, $w, $h, $label) {
  $pdf->SetFillColor(248,248,248);
  $pdf->SetX($x);
  $pdf->SetFont('Times','B',9);
  $pdf->Cell($w['det']+$w['vo']+$w['hab']+$w['desc'], $h, utf8_decode((string)$label), 1, 1, 'L', true);
  $pdf->SetFont('Arial','',10);
}
function pdf_row_item($pdf, $x, $w, $h, $row, $borders='') {
  $pdf->SetX($x);
  $pdf->Cell($w['det'],  $h, utf8_decode((string)$row['detalle']), $borders, 0, 'L');
  $pdf->Cell($w['vo'],   $h, $row['vo']!=='' ? (string)$row['vo'] : '', $borders, 0, 'C');
  $pdf->Cell($w['hab'],  $h, $row['hab'] ? fmt_clp($row['hab']) : '', $borders, 0, 'R');
  $pdf->Cell($w['desc'], $h, $row['desc']? fmt_clp($row['desc']) : '', $borders, 1, 'R');
}

class MyPDF extends FPDF {
  public $empresaNombre,$empresaRut,$empresaDir,$logoPath,$periodo;
  public $hdrNombre='',$hdrCargo='',$hdrLugar='',$hdrCcosto='';
  public $hdrCodigo='',$hdrRut='',$hdrFechaIng='',$hdrDias='';
  public $footerNombre='',$footerAntig='',$footerZona='',$footerPago='',$footerBanco='',$footerCta='';
  public $footerEmpresaNombre='MUELLAJE DEL MAIPO S.A.';
  public $logoX=2,$logoY=-8,$logoW=22;
  public $remunLabel='Remuneración del mes';
  
public function LM(){ return $this->lMargin; }
public function RM(){ return $this->rMargin; }
public function PW(){ return $this->w; }
public function PH(){ return $this->h; }

  function RoundedRect($x, $y, $w, $h, $r, $style='') {
    $k = $this->k; $hp = $this->h;
    $op = ($style=='F') ? 'f' : (($style=='FD' || $style=='DF') ? 'B' : 'S');
    $MyArc = 4/3 * (M_SQRT2 - 1);
    $this->_out(sprintf('%.2F %.2F m', ($x+$r)*$k, ($hp-$y)*$k ));
    $this->_out(sprintf('%.2F %.2F l', ($x+$w-$r)*$k, ($hp-$y)*$k ));
    $this->_Arc($x+$w-$r+$r*$MyArc, $y, $x+$w, $y+$r-$r*$MyArc, $x+$w, $y+$r);
    $this->_out(sprintf('%.2F %.2F l', ($x+$w)*$k, ($hp-($y+$h-$r))*$k ));
    $this->_Arc($x+$w, $y+$h-$r+$r*$MyArc, $x+$w-$r+$r*$MyArc, $y+$h, $x+$w-$r, $y+$h);
    $this->_out(sprintf('%.2F %.2F l', ($x+$r)*$k, ($hp-($y+$h))*$k ));
    $this->_Arc($x+$r-$r*$MyArc, $y+$h, $x, $y+$h-$r+$r*$MyArc, $x, $y+$h-$r);
    $this->_out(sprintf('%.2F %.2F l', $x*$k, ($hp-($y+$r))*$k ));
    $this->_Arc($x, $y+$r-$r*$MyArc, $x+$r-$r*$MyArc, $y, $x+$r, $y);
    $this->_out($op);
  }
  function _Arc($x1,$y1,$x2,$y2,$x3,$y3){
    $h=$this->h;
    $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ',
      $x1*$this->k, ($h-$y1)*$this->k, $x2*$this->k, ($h-$y2)*$this->k, $x3*$this->k, ($h-$y3)*$this->k));
  }
  function Header() {
    $L=$this->lMargin; $R=$this->rMargin; $T=12;

    if (is_file($this->logoPath)) $this->Image($this->logoPath, $L + $this->logoX, $T + $this->logoY, $this->logoW);

    $this->SetXY($L+28, $T-2.7);
    $this->SetFont('Times','B',9);
    $this->Cell(100,5,utf8_decode((string)$this->empresaNombre),0,2,'L');
    $this->Cell(100,5,utf8_decode('Rut: ').$this->empresaRut,0,2,'L');
    $this->Cell(100,5,utf8_decode((string)$this->empresaDir),0,2,'L');

    $boxW=55; $boxH=16; $r=2;
    $boxX=$this->w-$this->rMargin-$boxW; $boxY=9;
    $this->RoundedRect($boxX,$boxY,$boxW,$boxH,$r,'');
    $this->Line($boxX,$boxY+($boxH/2),$boxX+$boxW,$boxY+($boxH/2));

    $this->SetFont('Arial','',11.2);
    $this->SetXY($boxX, $boxY+2);
    $this->Cell($boxW,5,utf8_decode((string)$this->remunLabel),0,2,'C');
    $this->SetXY($boxX, $boxY+($boxH/2)+1.5);
    $this->Cell($boxW,5,utf8_decode((string)$this->periodo),0,2,'C');

    $this->SetFont('Courier','B',11);
    $this->SetXY($L, $T+15);
    $this->Cell($this->w-$L-$R,7,utf8_decode('LIQUIDACION DE REMUNERACIONES'),0,2,'C');

    $bx=$L; $bw=$this->w-$L-$R; $by=$T+22; $bh=32; $rr=3;
    $this->RoundedRect($bx,$by,$bw,$bh,$rr,'');

    $pad=3; $leftX=$bx+$pad; $rightX=$bx+($bw/2)+$pad+25; $yRow=$by+3; $sep=7;

    $linesL=[
      ['lbl'=>'Nombre:','val'=>$this->hdrNombre],
      ['lbl'=>'Cargo:','val'=>$this->hdrCargo],
      ['lbl'=>'L. de trabajo:','val'=>$this->hdrLugar],
      ['lbl'=>'C. de costo:','val'=>$this->hdrCcosto],
    ];
    foreach($linesL as $i=>$it){
      $this->SetXY($leftX, $yRow+$sep*$i);
      $this->SetFont('Times','B',10); $this->Cell(28,6,utf8_decode($it['lbl']),0,0,'L');
      $this->SetFont('Arial','',10);  $this->Cell(10,6,utf8_decode((string)$it['val']),0,0,'L');
    }

    $linesR=[
      ['lbl'=>'Código:','val'=>$this->hdrCodigo],
      ['lbl'=>'Rut:','val'=>$this->hdrRut],
      ['lbl'=>'Fecha ingreso:','val'=>$this->hdrFechaIng],
      ['lbl'=>'Días trabajados:','val'=>$this->hdrDias],
    ];
    foreach($linesR as $i=>$it){
      $this->SetXY($rightX, $yRow+$sep*$i);
      $this->SetFont('Times','B',10); $this->Cell(31,6,utf8_decode($it['lbl']),0,0,'L');
      $this->SetFont('Arial','',10);  $this->Cell(10,6,utf8_decode((string)$it['val']),0,0,'L');
    }

    $tableY=$by+$bh+1.5;
    $this->SetFont('Times','B',10); $this->SetFillColor(245,245,245); $this->SetXY($L,$tableY);
    $totalW=$this->w-$L-$R;
    $this->Cell($totalW*0.54,8,utf8_decode('Detalle'),1,0,'C',true);
    $this->Cell($totalW*0.10,8,'V.O',1,0,'C',true);
    $this->Cell($totalW*0.18,8,utf8_decode('Haberes'),1,0,'C',true);
    $this->Cell($totalW*0.18,8,utf8_decode('Descuentos'),1,1,'C',true);
  }

  function Footer() {
    $L=$this->lMargin; $R=$this->rMargin;
    $yTop=$this->h-70+6; $boxH=46;
    $this->Rect($L,$yTop,$this->w-$L-$R,$boxH,'D');

    $this->SetXY($L+4,$yTop+2); $this->SetFont('Arial','',9);
    $this->MultiCell($this->w-$L-$R-8,4,utf8_decode(
      "Certifico haber recibido en este acto a mi entera satisfacción, el total de haberes en la presente liquidación.Asimismo, declaro que nada se me adeuda y no tener reclamo alguno en contra de la empresa ".$this->footerEmpresaNombre.", por concepto de Remuneraciones."
    ),0,'L');

    $yData=$this->GetY()+4; $sep=4;
    $items=[
      'Nombre'=>$this->footerNombre,
      'Antigüedad'=>$this->footerAntig,
      'Zona Extrema'=>$this->footerZona,
      'Forma de Pago'=>$this->footerPago,
      'Banco'=>$this->footerBanco,
      'N° cta cte'=>$this->footerCta
    ];
    $i=0;
    foreach($items as $lbl=>$val){
      $this->SetXY($L+5,$yData+($i*$sep));
      $this->SetFont('Times','B',9); $this->Cell(38,4,utf8_decode($lbl.':'),0,0,'L');
      $this->SetFont('Arial','',9);  $this->Cell(100,4,utf8_decode((string)$val),0,0,'L');
      $i++;
    }

    $fx=$this->w-$R-78; $fy=$yData+23;
    $this->Line($fx,$fy,$fx+70,$fy);
    $this->SetXY($fx,$fy); $this->SetFont('Times','B',9);
    $this->Cell(70,4.5,utf8_decode('Firma Trabajador'),0,1,'C');

    $this->SetY(-12); $this->SetFont('Arial','',9);
    $this->Cell(0,6,utf8_decode('Página ').$this->PageNo().utf8_decode(' de {nb}'),0,0,'R');
  }
}

function generate_pdf_to_file(array $item, string $csvPath, string $tipo, string $outPath): array {
  // $item: rut_norm, ames + employee fields
  $rut = $item['rut_norm'];
  $ames = $item['ames'];

  list($sections, $tot, $infoTerm) = build_sections_from_term_csv($csvPath, $rut, $ames);

  // BD info viene en $item (ya prefetched)
  $info = [
    'Empresa' => $infoTerm['Empresa'] ?? '',
    'EmpresaDesc' => $infoTerm['EmpresaDesc'] ?? '',
    'Nombre' => (string)($item['nombre'] ?? ''),
    'Cargo' => (string)($item['cargo'] ?? ''),
    'Lugar' => (string)($item['ubicacion'] ?? ''),
    'Ccosto' => (string)($item['ccosto'] ?? ''),
    'Rut' => rut_display($rut),
    'FechaIng' => (string)($item['fecha_ingreso'] ?? ''),
    'Dias' => (string)($item['dias'] ?? ($infoTerm['Dias'] ?? '')),
    'Zona' => (string)($item['zona'] ?? ''),
    'Pago' => (string)($item['forma_pago'] ?? ''),
    'Banco' => (string)($item['banco'] ?? ''),
    'Cta' => (string)($item['cta'] ?? ''),
  ];
  $info['Antig'] = calcular_antiguedad($info['FechaIng'], $ames);

  $empresaDocId = (string)($infoTerm['Empresa'] ?? '');
  $empresaDocDesc = (string)($infoTerm['EmpresaDesc'] ?? '');
  $empresaTrabId = (string)($item['empresa_id'] ?? '');
  $empresaTrabDesc = (string)($item['empresa_desc'] ?? '');
  $empresaData = ($empresaDocId !== '' || $empresaDocDesc !== '')
    ? get_empresa_data_from_hint($empresaDocId, $empresaDocDesc)
    : get_empresa_data_from_hint($empresaTrabId, $empresaTrabDesc);

  $pdf = new MyPDF('P','mm','Letter');
  $pdf->AliasNbPages();
  $bottomReserve = 70;
  $pdf->SetAutoPageBreak(true, $bottomReserve);

  $pdf->empresaNombre = $empresaData['NOMBRE'];
  $pdf->empresaRut    = $empresaData['RUT'];
  $pdf->empresaDir    = $empresaData['DIR'];
  $pdf->logoPath      = $empresaData['LOGO'];
  $pdf->logoX = (float)($empresaData['LOGO_X'] ?? 2);
  $pdf->logoY = (float)($empresaData['LOGO_Y'] ?? -8);
  $pdf->logoW = (float)($empresaData['LOGO_W'] ?? 22);

  $pdf->periodo = month_label($ames);
  $pdf->remunLabel = ($tipo === 'dia25') ? 'REMUN. DIA 25' : 'Remuneración del mes';

  $pdf->hdrNombre   = $info['Nombre'];
  $pdf->hdrCargo    = $info['Cargo'];
  $pdf->hdrLugar    = $info['Lugar'];
  $pdf->hdrCcosto   = $info['Ccosto'];
  $pdf->hdrCodigo   = $info['Rut'];
  $pdf->hdrRut      = $info['Rut'];
  $pdf->hdrFechaIng = $info['FechaIng'];
  $pdf->hdrDias     = $info['Dias'];

  $pdf->footerNombre = $info['Nombre'];
  $pdf->footerAntig  = $info['Antig'];
  $pdf->footerZona   = $info['Zona'];
  $pdf->footerPago   = $info['Pago'];
  $pdf->footerBanco  = $info['Banco'];
  $pdf->footerCta    = $info['Cta'];
  $pdf->footerEmpresaNombre = (string)$empresaData['NOMBRE'];

  $pdf->AddPage();

$L = $pdf->LM(); 
$R = $pdf->RM();
$totalW = $pdf->PW() - $L - $R;
  $w = ['det'=>$totalW*0.54, 'vo'=>$totalW*0.10, 'hab'=>$totalW*0.18, 'desc'=>$totalW*0.18];

  $orden=['HABERES AFECTOS','OTROS HABERES','DESCUENTOS LEGALES','OTROS DESCUENTOS'];
  foreach($orden as $sec){
    if(empty($sections[$sec])) continue;
    pdf_section_header($pdf, $L, $w, 5, $sec);
    foreach($sections[$sec] as $row){
     if ($pdf->GetY() > ($pdf->PH() - $bottomReserve - 8)) {
        $pdf->AddPage();
        pdf_section_header($pdf, $L, $w, 5, $sec);
      }
      pdf_row_item($pdf, $L, $w, 4, $row, 'LR');
    }
  }

  $tot_hab = $tot['hab']; $tot_desc=$tot['desc']; $neto=$tot_hab-$tot_desc;

  $pdf->SetX($L); $pdf->SetFont('Times','B',11); $pdf->SetFillColor(255,255,255);
  $pdf->Cell($w['det']+$w['vo'], 9, utf8_decode('TOTALES'), 1, 0, 'L', false);
  $pdf->Cell($w['hab'],  9, fmt_clp($tot_hab),  1, 0, 'R', false);
  $pdf->Cell($w['desc'], 9, fmt_clp($tot_desc), 1, 1, 'R', false);

  $pdf->SetX($L); $pdf->SetFillColor(240,240,240);
  $pdf->Cell($w['det']+$w['vo']+$w['hab'], 9, utf8_decode('TOTAL A PAGAR'), 1, 0, 'L', true);
  $pdf->SetFont('Arial','B',11);
  $pdf->Cell($w['desc'], 9, fmt_clp($neto), 1, 1, 'R', true);

  $pdf->SetX($L); $pdf->SetFont('Times','B',11);
  $pdf->Cell(10, 9, utf8_decode('Son:'), 1, 0, 'L');
  $pdf->SetFont('Arial','',9);
  $pdf->Cell($totalW - 10, 9, utf8_decode(ucfirst(numero_a_letras($neto))), 1, 1, 'L');

  // Output as string
  $bytes = $pdf->Output('S');
  if (!$bytes || strncmp($bytes, '%PDF', 4) !== 0) {
    return ['ok'=>false,'error'=>'FPDF no devolvió PDF válido.'];
  }

  $dir = dirname($outPath);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  file_put_contents($outPath, $bytes);

  return ['ok'=>true,'neto'=>$neto];
}

/* ===================== BUK SEND ===================== */
function buk_send_doc(int $employee_id, string $pdf_path, bool $visible, bool $signable): array {
  $url = BUK_API_BASE . "/{$employee_id}/docs";
  $query_params = http_build_query([
    "visible" => $visible ? 'true' : 'false',
    "signable_by_employee" => $signable ? 'true' : 'false',
    "overwrite" => "false",
    "start_signature_workflow" => "false"
  ]);
  $url .= "?$query_params";

  $post_fields = ['file' => new CURLFile($pdf_path, 'application/pdf', basename($pdf_path))];

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["auth_token: " . BUK_TOKEN, "Accept: application/json"],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post_fields,
    CURLOPT_TIMEOUT => 120
  ]);

  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($err) return ['ok'=>false,'http'=>$http,'error'=>"cURL: $err",'body'=>$resp];
  return ['ok'=>($http === 201), 'http'=>$http, 'error'=>null, 'body'=>$resp];
}

/* ===================== JOB LOADERS ===================== */
function get_latest_job_id(): ?int {
  $r = DB()->consultar("SELECT id FROM buk_liq_jobs ORDER BY id DESC LIMIT 1");
  return $r ? (int)$r[0]['id'] : null;
}
function get_latest_pending_job_id(): ?int {
  $r = DB()->consultar("
    SELECT j.id
    FROM buk_liq_jobs j
    LEFT JOIN buk_liq_job_runs r ON r.job_id = j.id
    LEFT JOIN buk_liq_job_items i ON i.job_id = j.id
    GROUP BY j.id
    HAVING COUNT(r.id) = 0
       AND COALESCE(SUM(i.pdf_ok), 0) = 0
       AND COALESCE(SUM(i.send_ok), 0) = 0
       AND COALESCE(SUM(CASE WHEN i.http_code IS NOT NULL THEN 1 ELSE 0 END), 0) = 0
       AND COALESCE(SUM(CASE WHEN i.pdf_error IS NOT NULL AND i.pdf_error <> '' THEN 1 ELSE 0 END), 0) = 0
    ORDER BY j.id DESC
    LIMIT 1
  ");
  return $r ? (int)$r[0]['id'] : null;
}

function job_has_activity(int $job_id): bool {
  $rows = DB()->consultar("
    SELECT
      COALESCE(SUM(pdf_ok), 0) AS pdf_ok_count,
      COALESCE(SUM(send_ok), 0) AS send_ok_count,
      COALESCE(SUM(CASE WHEN http_code IS NOT NULL THEN 1 ELSE 0 END), 0) AS http_count,
      COALESCE(SUM(CASE WHEN pdf_error IS NOT NULL AND pdf_error <> '' THEN 1 ELSE 0 END), 0) AS pdf_error_count
    FROM buk_liq_job_items
    WHERE job_id=".(int)$job_id."
  ");
  $row = $rows[0] ?? [];
  return
    (int)($row['pdf_ok_count'] ?? 0) > 0 ||
    (int)($row['send_ok_count'] ?? 0) > 0 ||
    (int)($row['http_count'] ?? 0) > 0 ||
    (int)($row['pdf_error_count'] ?? 0) > 0;
}

function rliquid_delete_file_if_exists(string $path): void {
  if ($path !== '' && is_file($path)) {
    @unlink($path);
  }
}

function rliquid_remove_empty_tree(string $dir, string $stopAt): void {
  $dir = rtrim($dir, DIRECTORY_SEPARATOR);
  $stopAt = rtrim($stopAt, DIRECTORY_SEPARATOR);
  while ($dir !== '' && strpos($dir, $stopAt) === 0 && $dir !== $stopAt) {
    if (!is_dir($dir)) {
      $dir = dirname($dir);
      continue;
    }
    $items = @scandir($dir);
    if (!is_array($items) || count(array_diff($items, ['.', '..'])) > 0) {
      break;
    }
    @rmdir($dir);
    $dir = dirname($dir);
  }
}

function delete_pending_job(int $job_id): bool {
  global $uploadDir, $cacheDir, $pdfRoot, $logDir;

  $job = get_job($job_id);
  if (!$job) return false;
  if (job_has_activity($job_id)) return false;
  if (rliquid_get_runs($job_id, 1)) return false;

  $items = get_job_items($job_id);
  foreach ($items as $item) {
    rliquid_delete_file_if_exists((string)($item['pdf_path'] ?? ''));
  }

  rliquid_delete_file_if_exists((string)($job['xlsx_path'] ?? ''));
  rliquid_delete_file_if_exists((string)($job['csv_path'] ?? ''));

  $jobPdfDir = rtrim($pdfRoot, '/') . '/job_' . $job_id;
  if (is_dir($jobPdfDir)) {
    $it = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($jobPdfDir, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
      $path = $file->getPathname();
      if ($file->isFile()) @unlink($path);
      elseif ($file->isDir()) @rmdir($path);
    }
    @rmdir($jobPdfDir);
  }

  DB()->ejecutar("DELETE FROM buk_liq_job_items WHERE job_id=".(int)$job_id);
  DB()->ejecutar("DELETE FROM buk_liq_jobs WHERE id=".(int)$job_id." LIMIT 1");

  rliquid_remove_empty_tree(dirname((string)($job['xlsx_path'] ?? '')), rtrim($uploadDir, '/'));
  rliquid_remove_empty_tree(dirname((string)($job['csv_path'] ?? '')), rtrim($cacheDir, '/'));
  rliquid_remove_empty_tree($jobPdfDir, rtrim($pdfRoot, '/'));
  rliquid_remove_empty_tree($logDir, rtrim($logDir, '/'));

  return true;
}

function get_job(int $job_id): ?array {
  $r = DB()->consultar("SELECT * FROM buk_liq_jobs WHERE id=".(int)$job_id." LIMIT 1");
  return $r ? $r[0] : null;
}
function get_job_items(int $job_id): array {
  return DB()->consultar("SELECT * FROM buk_liq_job_items WHERE job_id=".(int)$job_id." ORDER BY rut_norm") ?: [];
}
function job_stats(int $job_id): array {
  $r = DB()->consultar("SELECT
    COUNT(*) total,
    SUM(buk_emp_id IS NOT NULL) con_buk_id,
    SUM(buk_emp_id IS NULL) sin_buk_id,
    SUM(pdf_ok=1) pdf_ok,
    SUM(pdf_ok=0) pdf_pend,
    SUM(send_ok=1) send_ok,
    SUM(send_ok=0) send_pend
  FROM buk_liq_job_items WHERE job_id=".(int)$job_id);
  return $r ? $r[0] : ['total'=>0,'con_buk_id'=>0,'sin_buk_id'=>0,'pdf_ok'=>0,'pdf_pend'=>0,'send_ok'=>0,'send_pend'=>0];
}

function rliquid_json_encode($data): string {
  return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function rliquid_json_decode(?string $json): array {
  if (!$json) return [];
  $data = json_decode($json, true);
  return is_array($data) ? $data : [];
}

function rliquid_get_runs(int $job_id, int $limit = 10): array {
  return DB()->consultar("
    SELECT * FROM buk_liq_job_runs
    WHERE job_id=".(int)$job_id."
    ORDER BY id DESC
    LIMIT ".max(1, (int)$limit)
  ) ?: [];
}

function rliquid_get_run(int $run_id): ?array {
  $r = DB()->consultar("SELECT * FROM buk_liq_job_runs WHERE id=".(int)$run_id." LIMIT 1");
  return $r ? $r[0] : null;
}

function rliquid_run_status_label(string $status): string {
  if ($status === 'done') return 'OK';
  if ($status === 'error') return 'ERROR';
  if ($status === 'queued') return 'Pendiente';
  if ($status === 'running') return 'En proceso';
  return strtoupper($status);
}

function rliquid_run_status_class(string $status): string {
  if ($status === 'done') return 'tag-ok';
  if ($status === 'error') return 'tag-err';
  return 'tag-warn';
}

function rliquid_run_resume(array $run): string {
  $runSummary = rliquid_json_decode($run['summary_json'] ?? '');
  if (!$runSummary) return '-';
  $resume = 'PDF '.(int)($runSummary['pdf_ok'] ?? 0).' OK · Buk '.(int)($runSummary['send_ok'] ?? 0).' OK';
  if (!empty($runSummary['send_error']) || !empty($runSummary['pdf_error'])) {
    $resume .= ' · Err '.(int)($runSummary['pdf_error'] ?? 0).'/'.(int)($runSummary['send_error'] ?? 0);
  }
  return $resume;
}

function rliquid_has_open_run(int $job_id): bool {
  $r = DB()->consultar("
    SELECT id FROM buk_liq_job_runs
    WHERE job_id=".(int)$job_id." AND status IN ('queued','running')
    ORDER BY id DESC LIMIT 1
  ");
  return !empty($r);
}

function rliquid_has_running_run(): bool {
  $r = DB()->consultar("
    SELECT id FROM buk_liq_job_runs
    WHERE status='running'
    ORDER BY id ASC
    LIMIT 1
  ");
  return !empty($r);
}

function rliquid_sleep_between_items(int $microseconds): void {
  if ($microseconds > 0) {
    usleep($microseconds);
  }
  if (function_exists('gc_collect_cycles')) {
    gc_collect_cycles();
  }
}

function rliquid_pdf_batch_query(int $jobId, int $limit): string {
  return "SELECT * FROM buk_liq_job_items
    WHERE job_id={$jobId}
      AND pdf_ok=0
      AND (pdf_error IS NULL OR pdf_error='')
    ORDER BY id
    LIMIT " . max(1, $limit);
}

function rliquid_send_batch_query(int $jobId, int $limit): string {
  return "SELECT * FROM buk_liq_job_items
    WHERE job_id={$jobId}
      AND pdf_ok=1
      AND buk_emp_id IS NOT NULL
      AND buk_emp_id <> ''
      AND send_ok=0
      AND http_code IS NULL
    ORDER BY id
    LIMIT " . max(1, $limit);
}

function rliquid_run_summary_for_job(int $jobId): array {
  $jobId = (int)$jobId;
  $r = DB()->consultar("SELECT
      COUNT(*) AS total_items,
      SUM(pdf_ok=1) AS pdf_ok,
      SUM(pdf_ok=0 AND pdf_error IS NOT NULL AND pdf_error<>'') AS pdf_error,
      SUM(pdf_ok=0 AND (pdf_error IS NULL OR pdf_error='')) AS pdf_pending,
      SUM(send_ok=1) AS send_ok,
      SUM(
        pdf_ok=1
        AND buk_emp_id IS NOT NULL
        AND buk_emp_id <> ''
        AND send_ok=0
        AND http_code IS NOT NULL
      ) AS send_error,
      SUM(pdf_ok=1 AND (buk_emp_id IS NULL OR buk_emp_id='')) AS send_no_buk,
      SUM(
        pdf_ok=1
        AND buk_emp_id IS NOT NULL
        AND buk_emp_id <> ''
        AND send_ok=0
        AND http_code IS NULL
      ) AS send_pending
    FROM buk_liq_job_items
    WHERE job_id={$jobId}") ?: [];

  $base = $r[0] ?? [];
  return [
    'total_items' => (int)($base['total_items'] ?? 0),
    'pdf_ok' => (int)($base['pdf_ok'] ?? 0),
    'pdf_error' => (int)($base['pdf_error'] ?? 0),
    'pdf_pending' => (int)($base['pdf_pending'] ?? 0),
    'pdf_skip' => 0,
    'send_ok' => (int)($base['send_ok'] ?? 0),
    'send_error' => (int)($base['send_error'] ?? 0),
    'send_pending' => (int)($base['send_pending'] ?? 0),
    'send_skip' => 0,
    'send_no_pdf' => 0,
    'send_no_buk' => (int)($base['send_no_buk'] ?? 0),
  ];
}

function rliquid_queue_run(int $job_id, string $requestedBy, ?string $requestedEmail, array $notifyEmails, array $options): int {
  global $logDir;
  $notifyEmails = array_values(array_unique(array_filter(array_map('trim', $notifyEmails))));
  $logPath = $logDir . '/run_' . date('Ymd_His') . '_job_' . (int)$job_id . '.log';
  DB()->ejecutar("INSERT INTO buk_liq_job_runs
    (job_id, run_type, status, requested_by, requested_email, notify_email, options_json, log_path)
    VALUES
    (".(int)$job_id.", 'process_all', 'queued', '".esc($requestedBy)."', ".($requestedEmail ? "'".esc($requestedEmail)."'" : "NULL").",
     '".esc(implode(',', $notifyEmails))."', '".esc(rliquid_json_encode($options))."', '".esc($logPath)."')");
  return (int)(DB()->consultar("SELECT LAST_INSERT_ID() AS id")[0]['id'] ?? 0);
}

function rliquid_launch_run_async(int $run_id): bool {
  $php = PHP_BINARY ?: 'php';
  $worker = __DIR__ . '/worker_run.php';
  $cmd = escapeshellcmd($php) . ' ' . escapeshellarg($worker) . ' ' . (int)$run_id . ' > /dev/null 2>&1 &';
  @exec($cmd, $out, $code);
  return true;
}

function rliquid_log_line(?string $logPath, string $line): void {
  if (!$logPath) return;
  @file_put_contents($logPath, '['.date('Y-m-d H:i:s')."] ".$line.PHP_EOL, FILE_APPEND);
}

function rliquid_load_smtp_config(): ?array {
  $cfg = __DIR__ . '/../sync/storage/sync/config.json';
  if (!is_file($cfg)) return null;
  $json = json_decode((string)file_get_contents($cfg), true);
  $smtp = is_array($json) ? ($json['smtp'] ?? null) : null;
  return is_array($smtp) && !empty($smtp['enabled']) ? $smtp : null;
}

function rliquid_recipient_options(?array $smtp, array $user): array {
  $options = [];
  foreach (($smtp['to_addresses'] ?? []) as $email) {
    $email = trim((string)$email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    $options[$email] = $email;
  }
  return $options;
}

function rliquid_collect_run_details(int $jobId): array {
  $items = DB()->consultar("SELECT rut_norm, nombre, buk_emp_id, pdf_ok, pdf_error, pdf_path, send_ok, http_code, response_body
    FROM buk_liq_job_items
    WHERE job_id=".(int)$jobId."
    ORDER BY rut_norm") ?: [];

  $details = [
    'send_ok_ruts' => [],
    'send_error_items' => [],
    'pdf_error_items' => [],
    'missing_buk_ruts' => [],
    'pending_ruts' => [],
  ];

  foreach ($items as $item) {
    $rut = (string)($item['rut_norm'] ?? '');
    $nombre = trim((string)($item['nombre'] ?? ''));
    $rutLabel = $rut !== '' ? rut_display($rut) : '-';
    $rowLabel = $nombre !== '' ? ($rutLabel . ' · ' . $nombre) : $rutLabel;
    $pdfOk = (int)($item['pdf_ok'] ?? 0) === 1;
    $sendOk = (int)($item['send_ok'] ?? 0) === 1;
    $bukId = $item['buk_emp_id'] ?? null;
    $pdfError = trim((string)($item['pdf_error'] ?? ''));
    $responseBody = trim((string)($item['response_body'] ?? ''));
    $httpCode = (int)($item['http_code'] ?? 0);

    if ($pdfOk && $sendOk) {
      $details['send_ok_ruts'][] = $rowLabel;
      continue;
    }

    if (!$pdfOk && $pdfError !== '') {
      $details['pdf_error_items'][] = [
        'rut' => $rutLabel,
        'nombre' => $nombre,
        'error' => $pdfError,
      ];
      continue;
    }

    if ($pdfOk && ($bukId === null || (string)$bukId === '')) {
      $details['missing_buk_ruts'][] = $rowLabel;
      continue;
    }

    if ($pdfOk && !$sendOk && ($httpCode > 0 || $responseBody !== '')) {
      $details['send_error_items'][] = [
        'rut' => $rutLabel,
        'nombre' => $nombre,
        'http_code' => $httpCode,
        'error' => $responseBody !== '' ? mb_substr($responseBody, 0, 400) : 'Error al enviar a Buk',
      ];
      continue;
    }

    $details['pending_ruts'][] = $rowLabel;
  }

  return $details;
}

function rliquid_build_summary_email(array $run, array $job, array $summary): array {
  $rawRecipients = array_filter(array_map('trim', explode(',', (string)($run['notify_email'] ?? ''))));
  if (!$rawRecipients) return ['ok' => false];
  $recipients = array_values(array_unique(array_filter($rawRecipients, function ($email) {
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
  })));
  if (!$recipients) return ['ok' => false];

  $smtp = rliquid_load_smtp_config();
  if (is_array($smtp)) {
    $smtp['from_name'] = 'STISOFT Liquidaciones';
  }
  require_once __DIR__ . '/../sync/email_report.php';

  $status = strtolower((string)($run['status'] ?? 'unknown'));
  $statusLabel = $status === 'done' ? 'Completado' : ($status === 'error' ? 'Con errores' : strtoupper($status));
  $boxColor = $status === 'done' ? '#ecfdf5' : '#fef2f2';
  $borderColor = $status === 'done' ? '#a7f3d0' : '#fecaca';
  $statusTextColor = $status === 'done' ? '#065f46' : '#991b1b';
  $statusIcon = $status === 'done' ? '✅' : '❌';
  $periodo = month_label((string)($job['ames'] ?? ''));
  $tipo = ((string)($job['tipo'] ?? 'mes')) === 'dia25' ? 'Día 25' : 'Fin de mes';
  $errores = (int)($summary['pdf_error'] ?? 0) + (int)($summary['send_error'] ?? 0);
  $runDate = (string)($run['finished_at'] ?? $run['started_at'] ?? $run['created_at'] ?? '');

  $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Resumen Liquidaciones</title></head><body style="margin:0;padding:20px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">';
  $html .= '<div style="max-width:820px;margin:0 auto;">';
  $html .= '<div style="margin-bottom:12px;font-size:12px;color:#6b7280;text-align:center;">STISOFT · Reporte automático</div>';
  $html .= '<div style="background:#ffffff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;">';
  $html .= '<div style="background:#111827;color:#ffffff;padding:24px 28px;">';
  $html .= '<div style="font-size:12px;color:#cbd5e1;letter-spacing:.3px;margin-bottom:6px;">STISOFT · LIQUIDACIONES</div>';
  $html .= '<div style="font-size:28px;font-weight:700;">Resumen de liquidaciones</div>';
  $html .= '<div style="margin-top:8px;font-size:14px;color:#d1d5db;">Periodo: ' . h($periodo) . ' · ' . h($tipo) . '</div>';
  $html .= '</div>';
  $html .= '<div style="padding:24px 28px;">';
  $html .= '<div style="background:'.$boxColor.';border:1px solid '.$borderColor.';color:'.$statusTextColor.';border-radius:12px;padding:14px 16px;margin-bottom:18px;">';
  $html .= '<div style="font-size:18px;font-weight:700;">'.$statusIcon.' Estado: '.$statusLabel.'</div>';
  $html .= '<div style="margin-top:6px;font-size:14px;">Archivo: <b>'.h((string)($job['source_filename'] ?? '-')).'</b></div>';
  $html .= '</div>';
  $html .= '<div style="margin-bottom:24px;">';
  $html .= '<div style="font-size:18px;font-weight:700;margin-bottom:10px;">Resumen</div>';
  $html .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
  $rows = [
    'Periodo' => (string)$periodo,
    'Tipo de proceso' => (string)$tipo,
    'Total registros' => (string)($summary['total_items'] ?? 0),
    'PDF listos' => (string)($summary['pdf_ok'] ?? 0),
    'Enviados a Buk' => (string)($summary['send_ok'] ?? 0),
    'Errores' => (string)$errores,
    'Sin buk_id' => (string)($summary['send_no_buk'] ?? 0),
    'Sin PDF' => (string)($summary['send_no_pdf'] ?? 0),
    'Ejecución' => h((string)$runDate),
    'Inicio' => h((string)($run['started_at'] ?? '-')),
    'Fin' => h((string)($run['finished_at'] ?? '-')),
  ];
  foreach ($rows as $k => $v) {
    $html .= '<tr><td style="padding:10px;border-bottom:1px solid #e5e7eb;background:#f8fafc;font-weight:700;width:220px;">'.h($k).'</td><td style="padding:10px;border-bottom:1px solid #e5e7eb;">'.h((string)$v).'</td></tr>';
  }
  $html .= '</table></div>';
  if (!empty($summary['error_message'])) {
    $html .= '<div style="margin-top:16px;padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;color:#991b1b;"><b>Detalle del error:</b> '.h((string)$summary['error_message']).'</div>';
  }
  $html .= '</div></div></body></html>';

  $subject = 'Liquidaciones · '.$periodo.' · '.$tipo.' · '.$statusLabel;
  return [
    'ok' => true,
    'recipients' => $recipients,
    'smtp' => $smtp,
    'subject' => $subject,
    'html' => $html,
  ];
}

function rliquid_send_summary_email(array $run, array $job, array $summary): bool {
  $payload = rliquid_build_summary_email($run, $job, $summary);
  if (empty($payload['ok'])) {
    return false;
  }
  return send_report_email(
    (array)$payload['recipients'],
    (string)$payload['html'],
    (string)$payload['subject'],
    is_array($payload['smtp'] ?? null) ? $payload['smtp'] : null
  );
}

function rliquid_process_run(int $run_id): array {
  global $pdfRoot;

  $run = rliquid_get_run($run_id);
  if (!$run) {
    throw new RuntimeException("Run {$run_id} no existe.");
  }

  $job = get_job((int)$run['job_id']);
  if (!$job) {
    throw new RuntimeException("Lote asociado a run {$run_id} no existe.");
  }

  $jobCsv = (string)($job['csv_path'] ?? '');
  if ($jobCsv === '' || !is_file($jobCsv)) {
    throw new RuntimeException("CSV del lote no existe en disco.");
  }

  $options = rliquid_json_decode($run['options_json'] ?? '');
  $visible = !empty($options['visible']);
  $signable = !empty($options['signable']);
  $force = !empty($options['force']);
  $logPath = $run['log_path'] ?? null;

  DB()->ejecutar("UPDATE buk_liq_job_runs
    SET status='running', started_at=COALESCE(started_at, NOW()), error_message=NULL
    WHERE id=".(int)$run_id);
  rliquid_log_line($logPath, "Inicio worker lote #".(int)$job['id']);
  $tipo = $job['tipo'] ?? 'mes';
  $jobDir = $pdfRoot . '/job_' . (int)$job['id'];
  if (!is_dir($jobDir)) @mkdir($jobDir, 0775, true);

  $pdfItems = DB()->consultar(rliquid_pdf_batch_query((int)$job['id'], RLIQUID_PDF_BATCH_SIZE)) ?: [];
  $pdfItems = rliquid_hydrate_items_employee_data((int)$job['id'], $pdfItems);

  foreach ($pdfItems as $it) {
    $rut = (string)$it['rut_norm'];
    $ames = (string)$it['ames'];
    $mesNombre = month_name_only($ames);
    $tipoTag   = ($tipo === 'dia25') ? '25Mes' : 'Mes';
    $rutFile   = rut_display($rut);
    $filename  = "Liq_{$mesNombre}_{$tipoTag}_{$rutFile}.pdf";
    $out       = $jobDir . '/' . $filename;
    $res = generate_pdf_to_file($it, $jobCsv, $tipo, $out);
    if (!empty($res['ok'])) {
      DB()->ejecutar("UPDATE buk_liq_job_items
        SET pdf_ok=1, pdf_error=NULL, pdf_path='".esc($out)."'
        WHERE id=".(int)$it['id']);
      rliquid_log_line($logPath, "PDF OK {$rut}");
    } else {
      $summary['pdf_error']++;
      $err = $res['error'] ?? 'Error desconocido al generar PDF';
      DB()->ejecutar("UPDATE buk_liq_job_items
        SET pdf_ok=0, pdf_error='".esc($err)."', pdf_path='".esc($out)."'
        WHERE id=".(int)$it['id']);
      rliquid_log_line($logPath, "PDF ERROR {$rut}: {$err}");
    }
    rliquid_sleep_between_items(RLIQUID_PDF_THROTTLE_US);
  }

  $sendItems = DB()->consultar(rliquid_send_batch_query((int)$job['id'], RLIQUID_SEND_BATCH_SIZE)) ?: [];
  foreach ($sendItems as $it) {
    $rut = (string)$it['rut_norm'];
    $res = buk_send_doc((int)$it['buk_emp_id'], (string)$it['pdf_path'], $visible, $signable);
    if (!empty($res['ok'])) {
      rliquid_log_line($logPath, "Buk OK {$rut} HTTP ".(int)($res['http'] ?? 0));
    } else {
      rliquid_log_line($logPath, "Buk ERROR {$rut} HTTP ".(int)($res['http'] ?? 0));
    }

    DB()->ejecutar("UPDATE buk_liq_job_items SET
      visible=".(int)$visible.",
      signable=".(int)$signable.",
      http_code=".(int)($res['http'] ?? 0).",
      send_ok=".(int)(!empty($res['ok']) ? 1 : 0).",
      response_body='".esc((string)($res['body'] ?? $res['error'] ?? ''))."'
      WHERE id=".(int)$it['id']);
    rliquid_sleep_between_items(RLIQUID_SEND_THROTTLE_US);
  }

  $summary = rliquid_run_summary_for_job((int)$job['id']);
  $remainingPdf = (int)$summary['pdf_pending'];
  $remainingSend = (int)$summary['send_pending'];

  if ($remainingPdf > 0 || $remainingSend > 0) {
    $summary = array_merge($summary, rliquid_collect_run_details((int)$job['id']));
    DB()->ejecutar("UPDATE buk_liq_job_runs
      SET status='running', summary_json='".esc(rliquid_json_encode($summary))."'
      WHERE id=".(int)$run_id);
    rliquid_log_line($logPath, "Lote continúa. Pendientes PDF={$remainingPdf} · Buk={$remainingSend}");
    rliquid_launch_run_async($run_id);
    return $summary;
  }

  $summary = array_merge($summary, rliquid_collect_run_details((int)$job['id']));
  DB()->ejecutar("UPDATE buk_liq_job_runs
    SET status='done', finished_at=NOW(), summary_json='".esc(rliquid_json_encode($summary))."'
    WHERE id=".(int)$run_id);
  $finalRun = rliquid_get_run($run_id) ?: $run;
  $mailPayload = rliquid_build_summary_email($finalRun, $job, $summary);
  $summary['email_subject'] = (string)($mailPayload['subject'] ?? '');
  $summary['email_html'] = (string)($mailPayload['html'] ?? '');
  $summary['email_sent'] = !empty($mailPayload['ok']) && send_report_email(
    (array)($mailPayload['recipients'] ?? []),
    (string)($mailPayload['html'] ?? ''),
    (string)($mailPayload['subject'] ?? ''),
    is_array($mailPayload['smtp'] ?? null) ? $mailPayload['smtp'] : null
  ) ? 1 : 0;
  rliquid_log_line($logPath, $summary['email_sent'] ? 'Correo resumen OK' : 'Correo resumen ERROR');
  DB()->ejecutar("UPDATE buk_liq_job_runs
    SET summary_json='".esc(rliquid_json_encode($summary))."'
    WHERE id=".(int)$run_id);
  rliquid_log_line($logPath, "Fin worker OK");
  return $summary;
}

if (defined('RLIQUID_BOOTSTRAP_ONLY') && RLIQUID_BOOTSTRAP_ONLY === true) {
  return;
}

/* ===================== ACTIONS ===================== */
$msgOk=''; $msgErr=''; $results=[];
$smtpCfg = rliquid_load_smtp_config();
$recipientOptions = rliquid_recipient_options($smtpCfg, $user);
$defaultRecipientSelection = array_keys($recipientOptions);
$uploadMax = min(ini_bytes((string)ini_get('upload_max_filesize')), ini_bytes((string)ini_get('post_max_size')));

$dismissedPendingJobId = (int)($_SESSION['rliquid_dismissed_job_id'] ?? 0);
$sessionPendingJobId = (int)($_SESSION['rliquid_pending_job_id'] ?? 0);

if (isset($_GET['run_started']) && isset($_GET['run'])) {
  $msgOk = "Proceso iniciado. Corrida #".(int)$_GET['run'].". Puedes salir de la página; el sistema seguirá trabajando y avisará por correo al finalizar.";
}
if (isset($_GET['package_created']) && isset($_GET['job'])) {
  $msgOk = "Lote #".(int)$_GET['job']." creado correctamente. Siguiente paso: revisa el lote y pulsa \"Iniciar proceso\".";
}
if (isset($_GET['cancel']) && $_GET['cancel'] === '1') {
  $dismissTarget = isset($_GET['job']) ? (int)$_GET['job'] : $sessionPendingJobId;
  unset($_SESSION['rliquid_pending_job_id']);
  $sessionPendingJobId = 0;
  if ($dismissTarget > 0) {
    if (delete_pending_job($dismissTarget)) {
      unset($_SESSION['rliquid_dismissed_job_id']);
      $dismissedPendingJobId = 0;
      header("Location: /rliquid/index.php?cancelled=1");
      exit;
    }
    $_SESSION['rliquid_dismissed_job_id'] = $dismissTarget;
    $dismissedPendingJobId = $dismissTarget;
  }
  header("Location: /rliquid/index.php?cancelled=1");
  exit;
}
if (isset($_GET['cancelled']) && $_GET['cancelled'] === '1') {
  $msgOk = "Lote descartado. La pantalla quedó lista para una nueva carga.";
}

$job_id = isset($_GET['job']) ? (int)$_GET['job'] : (int)($_POST['job_id'] ?? 0);
$job = $job_id ? get_job($job_id) : null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';

  // Create job from upload (XLSX/CSV)
  if ($action === 'upload') {
    $tipo = ($_POST['tipo'] ?? 'mes') === 'dia25' ? 'dia25' : 'mes';
    $created_by = $_POST['created_by'] ?? '';

    $file = $_FILES['archivo'] ?? null;
    if (!$file || !is_array($file)) {
      $msgErr = "No se recibió el campo de archivo. Intenta seleccionarlo nuevamente.";
    } elseif (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $msgErr = upload_error_message((int)($file['error'] ?? UPLOAD_ERR_NO_FILE));
    } elseif (empty($file['tmp_name'])) {
      $msgErr = "El archivo no llegó correctamente al servidor. Intenta subirlo otra vez.";
    } else{
      $name = basename($file['name']);
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      $dest = $uploadDir.'/'.date('Ymd_His').'__'.preg_replace('/[^a-zA-Z0-9._-]/','_',$name);
      if(!is_uploaded_file($file['tmp_name'])) {
        $msgErr = "El archivo temporal no es válido. Vuelve a intentarlo desde el navegador.";
      } elseif(!move_uploaded_file($file['tmp_name'], $dest)){ $msgErr="No se pudo guardar el archivo subido."; }
      else{
        $csvPath='';
        $xlsxPath='';
        try{
          if($ext==='csv'){
            $csvPath = $dest;
          } elseif($ext==='xlsx'){
            $xlsxPath = $dest;
            if(!$GLOBALS['hasSpreadsheet']){
              throw new Exception("Servidor sin PhpSpreadsheet. Sube CSV en vez de XLSX o instala composer phpoffice/phpspreadsheet.");
            }
            $csvPath = $cacheDir.'/'.date('Ymd_His').'__TERM.csv';

            // convert xlsx -> csv
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($xlsxPath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($xlsxPath);
            $sheet = $spreadsheet->getActiveSheet();
            $out = fopen($csvPath, 'w');
            $highestCol = $sheet->getHighestColumn();
            $highestRow = (int)$sheet->getHighestRow();
            $header = $sheet->rangeToArray("A1:{$highestCol}1", null, true, false);
            fputcsv($out, $header[0] ?? []);
            for($r=2;$r<=$highestRow;$r++){
              $row = $sheet->rangeToArray("A{$r}:{$highestCol}{$r}", null, true, false);
              if(isset($row[0])) fputcsv($out, $row[0]);
            }
            fclose($out);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
          } else {
            throw new Exception("Formato no soportado. Sube XLSX o CSV.");
          }

          // parse to determine ames_global and items
          $parsed = build_summary_from_csv($csvPath);
          $ames_global = $parsed['ames_global'] ?: '';
          if(!$ames_global) throw new Exception("No pude detectar AMES del archivo.");

          // create job
          $sql = "INSERT INTO buk_liq_jobs (ames,tipo,source_filename,xlsx_path,csv_path,created_by)
                  VALUES ('".esc($ames_global)."','".esc($tipo)."','".esc($name)."','".esc($xlsxPath)."','".esc($csvPath)."','".esc($created_by)."')";
          DB()->ejecutar($sql);
          $newId = (int)(DB()->consultar("SELECT LAST_INSERT_ID() AS id")[0]['id'] ?? 0);
          if(!$newId) throw new Exception("No pude obtener id del lote.");
          $_SESSION['rliquid_pending_job_id'] = $newId;
          $sessionPendingJobId = $newId;
          if (!empty($_SESSION['rliquid_dismissed_job_id'])) {
            unset($_SESSION['rliquid_dismissed_job_id']);
            $dismissedPendingJobId = 0;
          }

          // build items: al crear lote solo guardamos RUT/AMES/neto.
          $summary = $parsed['summary'];

          foreach($summary as $s){
            $rut = $s['rut'];
            $ames = $s['ames'];
            $neto = (float)$s['neto'];

            $sqlI = "INSERT INTO buk_liq_job_items
              (job_id,rut_norm,ames,buk_emp_id,nombre,cargo,ubicacion,ccosto,fecha_ingreso,zona,forma_pago,banco,cta,neto)
              VALUES
              ($newId,'".esc($rut)."','".esc($ames)."',NULL,'','','','','','','','','',".(float)$neto.")
              ON DUPLICATE KEY UPDATE
                ames=VALUES(ames),
                buk_emp_id=VALUES(buk_emp_id),
                nombre=VALUES(nombre), cargo=VALUES(cargo), ubicacion=VALUES(ubicacion), ccosto=VALUES(ccosto),
                fecha_ingreso=VALUES(fecha_ingreso), zona=VALUES(zona), forma_pago=VALUES(forma_pago),
                banco=VALUES(banco), cta=VALUES(cta),
                neto=VALUES(neto)";
            DB()->ejecutar($sqlI);
          }

          $msgOk = "Lote #$newId creado. Periodo {$ames_global}. Registros: ".count($summary);
          header("Location: ".$_SERVER['PHP_SELF']."?job=".$newId."&package_created=1");
          exit;

        } catch(Exception $e){
          $msgErr = $e->getMessage();
        }
      }
    }
  }

  // Load current job for other actions
  if (!$job_id) {
    if ($action !== 'upload' && $action !== '') {
      $msgErr = $msgErr ?: "No hay lote seleccionado.";
    }
  } else {
    $job = get_job($job_id);
    if(!$job) { $msgErr = $msgErr ?: "Lote no existe."; }
    else {
      $jobCsv = $job['csv_path'] ?? '';
      if(!$jobCsv || !is_file($jobCsv)) { $msgErr = $msgErr ?: "CSV del lote no existe en disco."; }

      if ($action === 'process_all' && !$msgErr) {
        if (rliquid_has_open_run((int)$job_id)) {
          $msgErr = "Ya hay una corrida en progreso para este lote. Espera a que termine antes de iniciar otra.";
        } elseif (rliquid_has_running_run()) {
          $msgErr = "Ya hay otro proceso ejecutándose en segundo plano. Para no saturar el servidor solo permitimos una corrida activa a la vez.";
        } else {
          $visible = !empty($_POST['visible']);
          $signable = !empty($_POST['signable']);
          $force = !empty($_POST['force']);
          if ($force) {
            DB()->ejecutar("UPDATE buk_liq_job_items
              SET
                pdf_ok=0,
                pdf_error=NULL,
                send_ok=0,
                http_code=NULL,
                response_body=NULL
              WHERE job_id=".(int)$job_id);
          }
          $requestedBy = trim((string)($user['name'] ?? $user['email'] ?? 'Usuario STISOFT'));
          $requestedEmail = trim((string)($user['email'] ?? ''));
          $notifyEmails = $_POST['notify_emails'] ?? [];
          if (!is_array($notifyEmails)) {
            $notifyEmails = [];
          }
          $notifyEmails = array_values(array_unique(array_filter(array_map('trim', $notifyEmails), function ($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL);
          })));
          $notifyRaw = trim((string)($_POST['notify_email_other'] ?? ''));
          if ($notifyRaw !== '') {
            $extraEmails = array_filter(array_map('trim', preg_split('/[\s,;]+/', $notifyRaw)), function ($email) {
              return filter_var($email, FILTER_VALIDATE_EMAIL);
            });
            $notifyEmails = array_values(array_unique(array_merge($notifyEmails, $extraEmails)));
          }
          if (!$notifyEmails) {
            $msgErr = "Selecciona al menos un correo para recibir el resumen del lote.";
          }

          if (!$msgErr) {
            $runId = rliquid_queue_run(
              (int)$job_id,
              $requestedBy,
              $requestedEmail !== '' ? $requestedEmail : null,
              $notifyEmails,
              ['visible' => $visible, 'signable' => $signable, 'force' => $force]
            );

            if ($runId <= 0) {
              $msgErr = "No se pudo crear la corrida en segundo plano.";
            } else {
              unset($_SESSION['rliquid_pending_job_id']);
              $sessionPendingJobId = 0;
              if (!empty($_SESSION['rliquid_dismissed_job_id'])) {
                unset($_SESSION['rliquid_dismissed_job_id']);
                $dismissedPendingJobId = 0;
              }
              rliquid_launch_run_async($runId);
              header("Location: /rliquid/reportes_lote.php?job=".(int)$job_id."&run_started=1&run=".(int)$runId);
              exit;
            }
          }
        }
      }

      $selected = $_POST['selected'] ?? [];
      if(!is_array($selected)) $selected = [];

      // helper to get target item ids
      $targets = [];
      if (in_array($action, ['pdf_one','send_one'], true)) {
        $id = (int)($_POST['item_id'] ?? 0);
        if($id>0) $targets = [$id];
      } elseif (in_array($action, ['pdf_selected','send_selected','reset_selected'], true)) {
  $targets = array_map('intval', $selected);

        
      } elseif (in_array($action, ['pdf_pending','send_pending','send_all_pdf','pdf_all'], true)) {
        $targets = []; // resolved by query
      }

      // ---------- PDF actions ----------
      if (in_array($action, ['pdf_selected','pdf_pending','pdf_all'], true) && !$msgErr) {
        $tipo = $job['tipo'] ?? 'mes';
        $jobDir = $pdfRoot . '/job_' . (int)$job_id;
        if (!is_dir($jobDir)) @mkdir($jobDir, 0775, true);

        if ($action === 'pdf_selected') {
          $in = $targets ? implode(',', array_map('intval', $targets)) : '0';
          $items = DB()->consultar("SELECT * FROM buk_liq_job_items
                                    WHERE job_id=".(int)$job_id."
                                      AND id IN ($in)") ?: [];
        } else {
          $items = DB()->consultar("SELECT * FROM buk_liq_job_items
                                    WHERE job_id=".(int)$job_id."
                                      AND pdf_ok=0") ?: [];
        }
        $items = rliquid_hydrate_items_employee_data((int)$job_id, $items);

        $ok = 0; $fail = 0; $skip = 0;

        foreach ($items as $it) {
          $rut  = $it['rut_norm'];
          $ames = $it['ames'];

          $mesNombre = month_name_only($ames);
          $tipoTag   = ($tipo === 'dia25') ? '25Mes' : 'Mes';
          $rutFile   = rut_display($rut);

          $filename  = "Liq_{$mesNombre}_{$tipoTag}_{$rutFile}.pdf";
          $out       = $jobDir . '/' . $filename;

          if ((int)$it['pdf_ok'] === 1 && !empty($it['pdf_path']) && is_file($it['pdf_path'])) {
            $skip++;
            continue;
          }

          $res = generate_pdf_to_file($it, $jobCsv, $tipo, $out);

          if (!empty($res['ok'])) {
            $ok++;
            DB()->ejecutar("UPDATE buk_liq_job_items
                            SET pdf_ok=1, pdf_error=NULL, pdf_path='".esc($out)."'
                            WHERE id=".(int)$it['id']);
          } else {
            $fail++;
            $err = $res['error'] ?? 'Error desconocido al generar PDF';
            DB()->ejecutar("UPDATE buk_liq_job_items
                            SET pdf_ok=0, pdf_error='".esc($err)."', pdf_path='".esc($out)."'
                            WHERE id=".(int)$it['id']);
          }
        }

        $msgOk = "PDFs: OK=$ok | Fallos=$fail | Omitidos=$skip";
      }

// ---------- RESET selected ----------
if ($action === 'reset_selected' && !$msgErr) {
  if (!$targets) {
    $msgErr = "No seleccionaste registros para limpiar.";
  } else {
    $in = implode(',', array_map('intval', $targets));
    $rows = DB()->consultar("SELECT id, pdf_path FROM buk_liq_job_items WHERE job_id=".(int)$job_id." AND id IN ($in)") ?: [];

    // borrar PDFs físicos
    $deleted = 0;
    foreach ($rows as $r) {
      $p = $r['pdf_path'] ?? '';
      if ($p && is_file($p)) { @unlink($p); $deleted++; }
    }

    // reset campos (para que puedas re-generar y re-enviar)
    DB()->ejecutar("
      UPDATE buk_liq_job_items
      SET
        pdf_ok=0,
        pdf_error=NULL,
        pdf_path=NULL,
        send_ok=0,
        http_code=NULL,
        response_body=NULL
      WHERE job_id=".(int)$job_id." AND id IN ($in)
    ");

    $msgOk = "Se limpiaron ".count($rows)." registros. PDFs borrados: $deleted. Ya puedes reintentar.";
  }
}
      // ---------- SEND actions ----------
      if (in_array($action, ['send_one','send_selected','send_pending','send_all_pdf'], true) && !$msgErr) {
        $visible  = !empty($_POST['visible']);
        $signable = !empty($_POST['signable']);
        $force    = !empty($_POST['force']);

        if ($action === 'send_one' || $action === 'send_selected') {
          $in = $targets ? implode(',', array_map('intval',$targets)) : '0';
          $items = DB()->consultar("SELECT * FROM buk_liq_job_items WHERE job_id=".(int)$job_id." AND id IN ($in)") ?: [];
        } elseif ($action === 'send_pending') {
          $items = DB()->consultar("SELECT * FROM buk_liq_job_items WHERE job_id=".(int)$job_id." AND pdf_ok=1 AND (send_ok=0)") ?: [];
        } else { // send_all_pdf
          $items = DB()->consultar("SELECT * FROM buk_liq_job_items WHERE job_id=".(int)$job_id." AND pdf_ok=1") ?: [];
        }
        $items = rliquid_hydrate_items_employee_data((int)$job_id, $items);

        $ok=0;$fail=0;$skip=0;$noPdf=0;$noBuk=0;
        foreach($items as $it){
          if((int)$it['send_ok']===1 && !$force){ $skip++; continue; }
          if((int)$it['pdf_ok']!==1 || !is_file($it['pdf_path'] ?? '')){ $noPdf++; continue; }
          $buk = $it['buk_emp_id'];
          if($buk===null || $buk===''){ $noBuk++; continue; }

          $res = buk_send_doc((int)$buk, $it['pdf_path'], $visible, $signable);
          if($res['ok']) $ok++; else $fail++;

          DB()->ejecutar("UPDATE buk_liq_job_items SET
            visible=".(int)$visible.",
            signable=".(int)$signable.",
            http_code=".(int)($res['http'] ?? 0).",
            send_ok=".(int)($res['ok']?1:0).",
            response_body='".esc((string)($res['body'] ?? $res['error'] ?? ''))."'
            WHERE id=".(int)$it['id']);
        }
        $msgOk = "Envíos Buk: OK=$ok | Fallos=$fail | Omitidos=$skip | Sin PDF=$noPdf | Sin buk_id=$noBuk";
      }
    }
  }
}

/* ===================== DATA for UI ===================== */
$cancelView = isset($_GET['cancel']) && $_GET['cancel'] === '1';
$job_id = isset($_GET['job']) ? (int)$_GET['job'] : (int)($_POST['job_id'] ?? 0);
if ($job_id <= 0 && !$cancelView) {
  $job_id = $sessionPendingJobId;
  if ($dismissedPendingJobId > 0 && $job_id === $dismissedPendingJobId) {
    $job_id = 0;
  }
}
$job = $job_id ? get_job($job_id) : null;
if ($job) {
  $existingRuns = rliquid_get_runs((int)$job['id'], 1);
  if (!empty($existingRuns) || job_has_activity((int)$job['id'])) {
    if ($sessionPendingJobId === (int)$job['id']) {
      unset($_SESSION['rliquid_pending_job_id']);
      $sessionPendingJobId = 0;
    }
    $job = null;
    $job_id = 0;
  }
}

$jobs = DB()->consultar("
  SELECT
    j.id, j.ames, j.tipo, j.source_filename, j.created_at,
    COUNT(i.id) AS total_items,
    SUM(i.pdf_ok=1) AS pdf_ok,
    SUM(i.send_ok=1) AS send_ok
  FROM buk_liq_jobs j
  LEFT JOIN buk_liq_job_items i ON i.job_id = j.id
  GROUP BY j.id
  ORDER BY j.id DESC
  LIMIT 30
") ?: [];

$items = $job ? get_job_items((int)$job['id']) : [];
$stats = $job ? job_stats((int)$job['id']) : ['total'=>0,'con_buk_id'=>0,'sin_buk_id'=>0,'pdf_ok'=>0,'pdf_pend'=>0,'send_ok'=>0,'send_pend'=>0];
$runs = $job ? rliquid_get_runs((int)$job['id'], 8) : [];
$hasRunHistory = !empty($runs);

?>

<?php include $headPath; ?>
<body class="bg-gray-50">

<?php
date_default_timezone_set('America/Santiago');

function fmt_dt_cl($dt){
  if(!$dt) return '';
  $t = strtotime($dt);
  return $t ? date('d/m/Y H:i', $t) : (string)$dt;
}
function month_label_es($ames){
  $ames = preg_replace('/[^0-9]/','', (string)$ames);
  if(strlen($ames)!==6) return $ames;
  $y=(int)substr($ames,0,4); $m=(int)substr($ames,4,2);
  $meses=[1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
  $mes = $meses[$m] ?? 'Mes';
  return $mes . ' ' . $y;
}
function tipo_label($t){
  return ($t==='dia25') ? 'Día 25' : 'Remuneración del Mes';
}
?>

<div class="min-h-screen grid grid-cols-12">

  <!-- Sidebar -->
  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php $active='liquidaciones'; include $sidebarPath; ?>
  </div>

  <!-- Main -->
  <div class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col">
    <?php include $topbarPath; ?>

    <!-- menos margen lateral -->
    <main class="flex-grow max-w-[1320px] p-4 md:p-5 space-y-4">

      <style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial; background:#f6f7fb; margin:0}
        .wrap{max-width:1320px;margin:0 auto;padding:0}
        .card{background:#fff;border:1px solid #e6e8f0;border-radius:16px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
        .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
        .small{font-size:12px;color:#6b7280}
        .msg{padding:12px 14px;border-radius:12px;border:1px solid;margin-bottom:12px}
        .ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
        .bad{background:#fef2f2;border-color:#fecaca;color:#991b1b}

        /* botones mas finos */
        .btn{border:1px solid transparent;padding:9px 12px;border-radius:10px;cursor:pointer;font-weight:600;font-size:13px}
        .btn-primary{background:#4f46e5;color:#fff}
        .btn-dark{background:#111827;color:#fff}
        .btn-ghost{background:#fff;border-color:#e6e8f0;color:#111827}
        .btn-soft{background:#f3f4f6;border-color:#e5e7eb;color:#111827}

        /* input/select */
        select, input[type=text]{padding:10px;border:1px solid #e6e8f0;border-radius:12px;width:100%;height:44px;background:#fff}
        table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid #e6e8f0;border-radius:14px;overflow:hidden}
        th,td{padding:10px;border-bottom:1px solid #eef0f6;text-align:left;font-size:13px}
        th{background:#fafafa;color:#4b5563;font-weight:800}
        tr:last-child td{border-bottom:none}

        /* tags */
        .tag{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;font-size:12px;border:1px solid #e6e8f0;background:#fafafa;font-weight:700}
        .tag-ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
        .tag-warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
        .tag-err{background:#fef2f2;border-color:#fecaca;color:#991b1b}

        /* layout grids */
        .grid2{display:grid;grid-template-columns:1.2fr .8fr;gap:12px}
        .grid2b{display:grid;grid-template-columns:1.2fr .8fr;gap:12px}
        @media (max-width: 980px){
          .grid2,.grid2b,.hero-grid{grid-template-columns:1fr}
        }

        /* dropzone */
        .dz{
          border:2px dashed #d7dbea;
          background:#fbfcff;
          border-radius:16px;
          padding:14px;
          display:flex;
          gap:12px;
          align-items:center;
          min-height:64px;
        }
        .dz .file-btn{
          display:inline-flex;align-items:center;justify-content:center;
          height:38px;padding:0 12px;border-radius:12px;
          border:1px solid #e6e8f0;background:#fff;font-weight:700;cursor:pointer;
        }
        .dz .name{font-size:13px;color:#6b7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}
        .dz.dragover{border-color:#4f46e5; box-shadow:0 0 0 4px rgba(79,70,229,.10)}

        /* estado: barras con “punta roja” si hay pendientes */
        .metric{border:1px solid #eef0f6;border-radius:16px;padding:7px;display:flex;gap:7px;align-items:center}
        .metric .title{font-weight:800;color:#111827}
        .metric .sub{font-size:12px;color:#6b7280;margin-top:1px}
        .bar{
          position:relative;
          height:12px;
          border-radius:999px;
          background:#eef2ff;
          border:1px solid #e6e8f0;
          overflow:hidden;
          flex:1;
        }
        .bar .okfill{height:100%;border-radius:999px;background:#10b981}
        .bar .warnfill{height:100%;border-radius:999px;background:#f59e0b}
        .bar .tip{
          position:absolute;right:0;top:0;height:100%;
          width:10px; /* punta roja fija */
          background:#ef4444;
        }
        .pct{
          min-width:74px;
          text-align:center;
          padding:5px 6px;
          border-radius:18px;
          border:1px solid #e6e8f0;
          font-weight:900;
          color:#111827;
          background:#fff;
        }
        .pct.ok{border-color:#a7f3d0;background:#ecfdf5;color:#065f46}
        .pct.warn{border-color:#fde68a;background:#fffbeb;color:#92400e}
        .pct.err{border-color:#fecaca;background:#fef2f2;color:#991b1b}

        /* encabezados más limpios */
        .h1{font-size:34px;font-weight:950;letter-spacing:-0.02em;color:#111827;margin:0}
        .h2{font-size:14px;font-weight:800;color:#111827;margin:0}
        .muted{color:#6b7280}

        /* acciones arriba de tabla */
        .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
        .toolbar .left{display:flex;gap:14px;flex-wrap:wrap;align-items:center}
        .toolbar .right{display:flex;gap:10px;flex-wrap:wrap;align-items:center}

        /* paginación */
        .pager{display:flex;gap:10px;align-items:center;justify-content:flex-end;margin-top:10px}
        .pager a{display:inline-flex;align-items:center;justify-content:center;height:36px;padding:0 12px;border-radius:10px;border:1px solid #e6e8f0;background:#fff;color:#111827;text-decoration:none;font-weight:700;font-size:13px}
        .pager a.disabled{opacity:.5;pointer-events:none}
        .mail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
        .mail-opt{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e6e8f0;border-radius:12px;background:#fff;font-size:13px}
        .hero{background:linear-gradient(135deg,#0f172a 0%,#155e75 52%,#0284c7 100%);color:#fff;border-radius:28px;padding:24px;border:1px solid rgba(255,255,255,.12);box-shadow:0 12px 34px rgba(15,23,42,.14)}
        .hero-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:16px}
        .hero-stat{background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:12px}
        .hero-kicker{font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:#ccfbf1;font-weight:800}
        .hero-title{font-size:32px;font-weight:950;letter-spacing:-0.03em;margin-top:10px}
        .hero-text{font-size:14px;color:#e6fffb;margin-top:10px;max-width:760px;line-height:1.5}
        .quick-guide{background:#fff;border:1px solid #e6e8f0;border-radius:24px;padding:18px}
        .guide-list{display:flex;flex-direction:column;gap:12px}
        .guide-item{display:flex;gap:12px;align-items:flex-start}
        .guide-dot{display:inline-flex;align-items:center;justify-content:center;min-width:34px;width:34px;height:34px;border-radius:999px;background:#f0f9ff;color:#0369a1;font-weight:900;font-size:13px;border:1px solid #bae6fd}
        .guide-title{font-size:15px;font-weight:800;color:#111827;margin-top:1px}
        .guide-text{font-size:13px;color:#6b7280;margin-top:4px;line-height:1.45}
        .soft-panel{background:#f8fafc;border:1px solid #e6e8f0;border-radius:18px;padding:16px}
        .section-title{font-size:19px;font-weight:900;color:#111827;margin:0}
        .section-sub{font-size:13px;color:#6b7280;margin-top:6px;line-height:1.45}
        .hint{padding:13px 14px;border-radius:16px;border:1px solid #dbeafe;background:#eff6ff;color:#1e3a8a;font-size:13px}
        .toolbar-card{background:#fcfcfd;border:1px solid #e6e8f0;border-radius:22px;padding:16px}
        .inline-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .btn-xl{padding:13px 18px;border-radius:14px;font-size:14px}
        .card-tall{height:100%}
        details.advanced{border:1px solid #e6e8f0;border-radius:14px;background:#fff;padding:12px}
        details.advanced summary{cursor:pointer;font-weight:800;color:#111827;list-style:none}
        details.advanced summary::-webkit-details-marker{display:none}
        details.advanced[open]{background:#fbfcff}
        .action-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:12px}
        @media (max-width: 980px){
          .action-grid{grid-template-columns:1fr}
        }
        .action-card{border:1px solid #e6e8f0;border-radius:24px;padding:18px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%)}
        .play-btn{
          display:inline-flex;align-items:center;justify-content:center;gap:12px;
          min-height:58px;padding:0 24px;border-radius:18px;border:0;
          background:linear-gradient(135deg,#0f172a 0%,#1d4ed8 100%);color:#fff;
          font-size:16px;font-weight:900;cursor:pointer;box-shadow:0 14px 30px rgba(29,78,216,.22)
        }
        .play-btn:hover{transform:translateY(-1px);box-shadow:0 18px 34px rgba(29,78,216,.28)}
        .play-btn .play-icon{
          width:0;height:0;border-top:10px solid transparent;border-bottom:10px solid transparent;border-left:16px solid #fff;
          margin-left:4px;
        }
        .cancel-btn{
          display:inline-flex;align-items:center;justify-content:center;gap:10px;
          min-height:58px;padding:0 22px;border-radius:18px;
          border:1px solid #fecaca;background:linear-gradient(180deg,#fff1f2 0%,#ffe4e6 100%);
          color:#9f1239;text-decoration:none;font-size:16px;font-weight:900;
          box-shadow:0 10px 24px rgba(190,24,93,.10)
        }
        .cancel-btn:hover{transform:translateY(-1px);box-shadow:0 14px 28px rgba(190,24,93,.16)}
        .cancel-btn .cancel-icon{
          display:inline-flex;align-items:center;justify-content:center;
          width:24px;height:24px;border-radius:999px;background:#e11d48;color:#fff;
          font-size:16px;line-height:1;font-weight:900;
        }
        .status-stack{display:flex;flex-direction:column;gap:10px;margin-top:14px}
        .status-chip{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px 14px;border:1px solid #e6e8f0;border-radius:16px;background:#fff}
        .quick-link{
          display:inline-flex;align-items:center;justify-content:center;height:46px;padding:0 16px;border-radius:14px;
          border:1px solid #dbeafe;background:#eff6ff;color:#1d4ed8;text-decoration:none;font-weight:800
        }
      </style>

      <div class="wrap">

        <!-- Mensajes -->
        <?php if($msgErr): ?><div class="msg bad">❌ <?=h($msgErr)?></div><?php endif; ?>
        <?php if($msgOk): ?><div class="msg ok">✅ <?=h($msgOk)?></div><?php endif; ?>

        <div class="hero">
          <div class="hero-grid">
            <div>
              <div class="hero-kicker">Liquidaciones</div>
              <div class="hero-title">Subir archivo, generar PDFs y enviar a Buk</div>
              <div class="hero-text">Esta es la pantalla principal de trabajo. El flujo recomendado es simple: crea el lote desde tu archivo, revisa el resumen y luego inicia el proceso para generar y enviar.</div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;align-self:start">
              <div class="hero-stat">
                <div class="small" style="color:#ccfbf1">Lote actual</div>
                <div style="font-size:24px;font-weight:900;margin-top:6px"><?= $job ? h(month_label_es($job['ames'])) : 'Sin lote' ?></div>
                <?php if($job): ?>
                  <div class="small" style="color:#dbeafe;margin-top:6px"><?=h(tipo_label($job['tipo'] ?? 'mes'))?></div>
                <?php endif; ?>
              </div>
              <div class="hero-stat">
                <div class="small" style="color:#ccfbf1">Máximo sugerido</div>
                <div style="font-size:24px;font-weight:900;margin-top:6px"><?=h(fmt_bytes_short($uploadMax))?></div>
              </div>
            </div>
          </div>
        </div>

        <?php if(!$job): ?>
        <div class="grid2" style="margin-top:18px;align-items:stretch">
          <div>
            <div class="card card-tall">
            <div class="row" style="justify-content:space-between;align-items:flex-start">
              <div>
                <div class="section-title">Paso 1. Crear lote</div>
                <div class="section-sub">Sube el archivo y crea el lote base. Aquí todavía no se generan PDFs ni se envía a Buk.</div>
              </div>
              <div class="small muted" style="margin-top:10px">
                Limpieza PDFs &gt; <?=h($rliquidRetentionDays)?> días · hoy: <b><?=h($deletedOld)?></b>
              </div>
            </div>

            <div style="margin-top:14px">
              <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="created_by" value="<?=h($user['name'] ?? $user['email'] ?? 'Usuario STISOFT')?>">

                <input id="archivo" type="file" name="archivo" accept=".xlsx,.csv" required style="display:none">

                <div class="dz" id="dropzone">
                  <label class="file-btn" for="archivo">Seleccionar archivo</label>
                  <div class="name" id="archivoName">Sin archivos seleccionados</div>
                  <div class="small muted" style="white-space:nowrap">Arrastra aquí</div>
                </div>

                <div class="row" style="margin-top:12px;gap:10px;align-items:center">
                  <div style="flex:1;min-width:260px">
                    <select name="tipo">
                      <option value="mes">Remuneración del mes</option>
                      <option value="dia25">REMUN. DÍA 25</option>
                    </select>
                  </div>

                  <div style="min-width:160px">
                    <button class="btn btn-primary btn-xl" type="submit" style="height:48px;width:100%">Crear lote</button>
                  </div>
                </div>
              </form>
            </div>
          </div>
          </div>

          <div class="quick-guide card-tall">
            <div class="section-title" style="font-size:18px">Guía rápida</div>
            <div class="section-sub">Sigue este orden para trabajar sin enredarte.</div>
            <div class="guide-list" style="margin-top:14px">
              <div class="guide-item">
                <span class="guide-dot">1</span>
                <div>
                  <div class="guide-title">Crear lote</div>
                  <div class="guide-text">Sube el archivo del periodo. Este paso solo prepara la información.</div>
                </div>
              </div>
              <div class="guide-item">
                <span class="guide-dot">2</span>
                <div>
                  <div class="guide-title">Revisar resumen</div>
                  <div class="guide-text">Confirma cuántos registros hay y revisa si existe algún colaborador sin vínculo Buk.</div>
                </div>
              </div>
              <div class="guide-item">
                <span class="guide-dot">3</span>
                <div>
                  <div class="guide-title">Iniciar proceso</div>
                  <div class="guide-text">Genera los PDFs y envía el lote. El sistema sigue trabajando aunque cierres la página.</div>
                </div>
              </div>
            </div>
          </div>

        </div>
        <?php endif; ?>

        <?php if(!$job): ?>
        <div class="card" style="margin-top:12px">
          <div class="section-title">Paso 2. Revisar lote</div>
        <?php if($job): ?>
          <div class="section-sub">Periodo <b><?=h(ames_to_label($job['ames']))?></b> · Archivo <b><?=h($job['source_filename'] ?? '')?></b></div>
          <div class="inline-actions" style="margin-top:12px">
            <a class="quick-link" href="/rliquid/index.php">Nueva carga</a>
            <a class="quick-link" href="/rliquid/reportes_lote.php?job=<?= (int)$job['id'] ?>">Ver corridas del lote</a>
          </div>
        <?php else: ?>
          <div class="section-sub">Cuando crees o abras un lote verás aquí el resumen del proceso.</div>
        <?php endif; ?>

          <?php if($job): ?>
            <?php
              $total = (int)$stats['total'];

              $pdfOk = (int)$stats['pdf_ok'];
              $pdfPend = (int)$stats['pdf_pend'];
              $pdfPct = $total>0 ? (int)floor(($pdfOk/$total)*100) : 0;

              $sendOk = (int)$stats['send_ok'];
              $sendPend = (int)$stats['send_pend'];
              $sendPct = $total>0 ? (int)floor(($sendOk/$total)*100) : 0;

              $sinBuk = (int)$stats['sin_buk_id'];
            ?>

            <div style="margin-top:12px;display:flex;flex-direction:column;gap:10px">
              <div class="metric">
                <div style="min-width:130px">
                  <div class="title">PDFs generados</div>
                  <div class="sub"><?= $pdfOk ?> de <?= $total ?> · Pendientes: <?= $pdfPend ?></div>
                </div>
                <div class="bar" aria-label="Progreso PDF">
                  <div class="okfill" style="width:<?= max(0, min(100, $pdfPct)) ?>%"></div>
                  <?php if($pdfPend>0): ?><div class="tip" title="Pendientes"></div><?php endif; ?>
                </div>
                <div class="pct <?= ($pdfPend>0?'warn':'ok') ?>"><?= $pdfPct ?>%</div>
              </div>

              <div class="metric">
                <div style="min-width:130px">
                  <div class="title">Enviados a Buk</div>
                  <div class="sub"><?= $sendOk ?> de <?= $total ?> · Pendientes: <?= $sendPend ?></div>
                </div>
                <div class="bar" aria-label="Progreso Envío Buk">
                  <div class="warnfill" style="width:<?= max(0, min(100, $sendPct)) ?>%"></div>
                  <?php if($sendPend>0): ?><div class="tip" title="Pendientes"></div><?php endif; ?>
                </div>
                <div class="pct <?= ($sendPend>0?'warn':'ok') ?>"><?= $sendPct ?>%</div>
              </div>

              <div class="metric">
                <div style="min-width:130px">
                  <div class="title">Sin vínculo Buk</div>
                  <div class="sub"><?= $sinBuk ?> · <?= $hasRunHistory ? 'No se enviarán hasta corregir buk_id' : 'Pendientes de validar buk_id' ?></div>
                </div>
                <div style="flex:1"></div>
                <div class="pct <?= ($sinBuk>0?'err':'ok') ?>"><?= $sinBuk ?></div>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if($job): ?>
          <?php
            $hasOpenRun = false;
            foreach ($runs as $rr) {
              if (in_array((string)($rr['status'] ?? ''), ['queued','running'], true)) { $hasOpenRun = true; break; }
            }
            $lastRun = $runs[0] ?? null;
          ?>

          <?php
            // ---- Filtros + paginación (solo UI + slicing de $items) ----
            $filter = $_GET['filter'] ?? 'all'; // all | pdf_pending | pdf_ok | buk_pending | buk_ok | error
            $perPage = (int)($_GET['per_page'] ?? 25);
            if(!in_array($perPage, [25,50,100], true)) $perPage = 25;
            $page = (int)($_GET['page'] ?? 1);
            if($page < 1) $page = 1;

            $filtered = $items;

            $filtered = array_values(array_filter($filtered, function($it) use($filter){
              $pdfOk = (int)($it['pdf_ok'] ?? 0) === 1;
              $sendOk = (int)($it['send_ok'] ?? 0) === 1;
              $buk = $it['buk_emp_id'] ?? null;
              $hasBuk = !($buk===null || $buk==='');

              if($filter==='pdf_pending') return !$pdfOk;
              if($filter==='pdf_ok') return $pdfOk;
              if($filter==='buk_pending') return $pdfOk && !$sendOk && $hasBuk;
              if($filter==='buk_ok') return $sendOk;
              if($filter==='error') return !$hasBuk; // Error = sin buk_id
              return true;
            }));

            $totalRows = count($filtered);
            $pages = max(1, (int)ceil($totalRows / $perPage));
            if($page > $pages) $page = $pages;
            $offset = ($page-1) * $perPage;
            $pageRows = array_slice($filtered, $offset, $perPage);

            // helper para mantener params en links
            function qmerge($arr){
              $base = $_GET;
              foreach($arr as $k=>$v){
                if($v===null) unset($base[$k]);
                else $base[$k] = $v;
              }
              return http_build_query($base);
            }
          ?>

          <!-- ACCIONES + TABLA -->
          <div class="card" style="margin-top:8px">
            <form method="POST" id="bulkForm">
              <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">

              <div class="action-card" style="margin-bottom:14px">
                <div class="section-title">Paso 3. Ejecutar lote</div>
                <div class="section-sub">Periodo <b><?=h(ames_to_label($job['ames']))?></b> · Archivo <b><?=h($job['source_filename'] ?? '')?></b></div>
                <div style="margin-top:10px" class="small muted">
                  Estado actual: <b><?= $hasOpenRun ? 'Proceso en curso' : 'Listo para ejecutar' ?></b>
                  <?php if($lastRun): ?>
                    · Última corrida: <b>#<?=h($lastRun['id'])?> · <?=h(rliquid_run_resume($lastRun))?></b>
                  <?php endif; ?>
                </div>
                <div class="advanced" style="margin-top:12px">
                  <div style="font-weight:800;color:#111827;margin-bottom:12px">Opciones avanzadas</div>
                  <div style="display:flex;flex-direction:column;gap:14px">
                    <div class="inline-actions">
                      <label class="small" style="display:flex;gap:8px;align-items:center">
                        <input type="checkbox" name="visible" checked>
                        Mostrar documento en Buk
                      </label>
                      <label class="small" style="display:flex;gap:8px;align-items:center">
                        <input type="checkbox" name="signable">
                        Solicitar firma
                      </label>
                      <label class="small" style="display:flex;gap:8px;align-items:center">
                        <input type="checkbox" name="force">
                        Reintentar aunque ya esté enviado
                      </label>
                    </div>

                    <div style="min-width:320px;max-width:640px">
                      <div class="small muted" style="margin-bottom:6px">Enviar resumen a</div>
                      <?php if($recipientOptions): ?>
                        <div class="mail-grid" style="margin-bottom:8px">
                          <?php foreach($recipientOptions as $email => $label): ?>
                            <label class="mail-opt">
                              <input type="checkbox" name="notify_emails[]" value="<?=h($email)?>" <?=in_array($email, $defaultRecipientSelection, true) ? 'checked' : ''?>>
                              <span><?=h($label)?></span>
                            </label>
                          <?php endforeach; ?>
                        </div>
                        <div class="small muted">Servidor y remitente según configuración general de correo.</div>
                      <?php else: ?>
                        <div class="small muted" style="margin-bottom:8px">No hay destinatarios configurados en correo de reportes.</div>
                      <?php endif; ?>
                      <input type="text" name="notify_email_other" value="" placeholder="Agregar otro correo opcional" style="width:100%" title="Correo adicional para el resumen">
                    </div>
                  </div>
                </div>
                <div style="margin-top:16px" class="inline-actions">
                  <button class="play-btn" name="action" value="process_all" type="submit">
                    <span class="play-icon" aria-hidden="true"></span>
                    <span>Iniciar proceso</span>
                  </button>
                  <a class="cancel-btn" href="/rliquid/index.php?cancel=1&job=<?= (int)$job['id'] ?>">
                    <span class="cancel-icon" aria-hidden="true">×</span>
                    <span>Cancelar</span>
                  </a>
                  <button class="btn btn-soft" name="action" value="reset_selected" type="submit"
                    onclick="return confirm('¿Seguro? Esto limpiará PDF/estado envío de los seleccionados para reintentar.');">
                    Limpiar seleccionados
                  </button>
                </div>
                <div class="hint" style="margin-top:14px">
                  Puedes salir de la página después de iniciar. La corrida sigue en segundo plano y el resumen llega por correo al finalizar.
                </div>
              </div>

              <div class="toolbar-card">
                <div class="toolbar">
                  <div class="left">
                    <div style="width:220px">
                      <select onchange="location.href='?'+this.value">
                        <?php
                          $opts = [
                            'all'         => 'Todos los registros',
                            'pdf_pending' => 'Pendientes de PDF',
                            'pdf_ok'      => 'PDF listo',
                            'buk_pending' => 'Pendientes de envío',
                            'buk_ok'      => 'Enviados a Buk',
                            'error'       => 'Sin buk_id',
                          ];
                          foreach($opts as $k=>$lbl){
                            $qs = qmerge(['filter'=>$k,'page'=>1]);
                            $sel = ($filter===$k) ? 'selected' : '';
                            echo "<option value=\"{$qs}\" {$sel}>".h($lbl)."</option>";
                          }
                        ?>
                      </select>
                    </div>

                    <div style="width:160px">
                      <select onchange="location.href='?'+this.value">
                        <?php
                          foreach([25,50,100] as $n){
                            $qs = qmerge(['per_page'=>$n,'page'=>1]);
                            $sel = ($perPage===$n) ? 'selected' : '';
                            echo "<option value=\"{$qs}\" {$sel}>{$n} por página</option>";
                          }
                        ?>
                      </select>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Tabla -->
              <div style="margin-top:14px;overflow:auto">
                <table>
                  <thead>
                    <tr>
                      <th style="width:40px"><input type="checkbox" id="chk_all"></th>
                      <th>RUT</th>
                      <th>Nombre</th>
                      <th>Vínculo Buk</th>
                      <th>PDF</th>
                      <th>Envío</th>
                      <th style="text-align:right">Neto</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($pageRows as $it): ?>
                      <?php
                        $buk = $it['buk_emp_id'];
                        $hasBuk = !($buk===null || $buk==='');
                        $pdfOk = (int)$it['pdf_ok']===1;
                        $sendOk = (int)$it['send_ok']===1;
                        $bukResolved = $hasBuk || $hasRunHistory || !empty($it['pdf_error']) || $pdfOk || $sendOk;

                        if($hasBuk){
                          $bukBadge = '<span class="tag tag-ok">'.h($buk).'</span>';
                        } elseif($bukResolved){
                          $bukBadge = '<span class="tag tag-err">Sin buk_id</span>';
                        } else {
                          $bukBadge = '<span class="tag">Pendiente</span>';
                        }

                        $pdfBadge = $pdfOk ? '<span class="tag tag-ok">OK</span>' : '<span class="tag">Pendiente</span>';

                        if(!$hasBuk){
                          $sendBadge = $bukResolved ? '<span class="tag tag-err">No enviado</span>' : '<span class="tag">Pendiente</span>';
                        } else {
                          $sendBadge = $sendOk ? '<span class="tag tag-ok">OK</span>' : '<span class="tag">Pendiente</span>';
                        }
                      ?>
                      <tr>
                        <td><input class="chk" type="checkbox" name="selected[]" value="<?=h($it['id'])?>"></td>
                        <td><b><?=h(rut_display($it['rut_norm']))?></b></td>
                        <td><?=h($it['nombre'] ?? '')?></td>
                        <td><?=$bukBadge?></td>
                        <td><?=$pdfBadge?></td>
                        <td><?=$sendBadge?></td>
                        <td style="text-align:right">$ <?=h(number_format((float)$it['neto'],0,',','.'))?></td>
                      </tr>
                    <?php endforeach; ?>

                    <?php if(!$pageRows): ?>
                      <tr><td colspan="7" class="small muted">No hay registros para el filtro actual.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <!-- Paginación -->
              <div class="pager">
                <?php
                  $prev = $page-1;
                  $next = $page+1;
                ?>
                <a class="<?= ($page<=1?'disabled':'') ?>" href="?<?=h(qmerge(['page'=>$prev]))?>">Anterior</a>
                <span class="small muted">Página <b><?= $page ?></b> de <b><?= $pages ?></b> · <?= $totalRows ?> registros</span>
                <a class="<?= ($page>=$pages?'disabled':'') ?>" href="?<?=h(qmerge(['page'=>$next]))?>">Siguiente</a>
              </div>

              <input type="hidden" id="item_id" name="item_id" value="">
            </form>
          </div>

        <?php endif; ?>

      </div>
    </main>

    <script>
      // --- dropzone (arrastrar y soltar) ---
      (function(){
        const input = document.getElementById('archivo');
        const name = document.getElementById('archivoName');
        const dz = document.getElementById('dropzone');
        if(!input || !name || !dz) return;

        function setName(){
          name.textContent = (input.files && input.files[0]) ? input.files[0].name : 'Sin archivos seleccionados';
        }
        input.addEventListener('change', setName);

        dz.addEventListener('dragover', (e)=>{ e.preventDefault(); dz.classList.add('dragover'); });
        dz.addEventListener('dragleave', ()=> dz.classList.remove('dragover'));
        dz.addEventListener('drop', (e)=>{
          e.preventDefault();
          dz.classList.remove('dragover');
          if(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length){
            input.files = e.dataTransfer.files;
            setName();
          }
        });
      })();

      // --- check all + botones seleccionados (mantengo tu lógica base) ---
      (function(){
        const form = document.getElementById('bulkForm');
        if(!form) return;

        const chkAll = document.getElementById('chk_all');

        function refresh(){
          if(chkAll){
            const all = form.querySelectorAll('.chk');
            const checked = form.querySelectorAll('.chk:checked');
            chkAll.checked = all.length>0 && checked.length===all.length;
            chkAll.indeterminate = checked.length>0 && checked.length<all.length;
          }
        }

        if(chkAll){
          chkAll.addEventListener('change', () => {
            form.querySelectorAll('.chk').forEach(c => { c.checked = chkAll.checked; });
            refresh();
          });
        }

        form.addEventListener('change', (e) => {
          if(e.target && e.target.classList && e.target.classList.contains('chk')) refresh();
        });

        refresh();
      })();

      <?php if(!empty($runs)): ?>
      (function(){
        const active = <?= json_encode((bool)array_filter($runs, fn($r) => in_array((string)($r['status'] ?? ''), ['queued','running'], true))) ?>;
        if (!active) return;
        setTimeout(function(){ window.location.reload(); }, 15000);
      })();
      <?php endif; ?>
    </script>

    <?php if (is_file($footerPath)) { include $footerPath; } else { ?>
      <footer class="text-center text-xs text-gray-400 py-4 border-t bg-white">
        © <?= date('Y') ?> STI Soft — Integración ADP + Buk
      </footer>
    <?php } ?>

  </div>
</div>

</body>
</html>
<?php ob_end_flush(); ?>

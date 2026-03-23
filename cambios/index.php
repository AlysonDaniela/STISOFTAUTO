<?php
/**
 * /cambios/index.php  (standalone)
 * - Sube CSV/TXT ADP
 * - Compara vs BD adp_empleados
 * - Detecta cambios: PROFILE / PLAN / JOB
 * - Envía PATCH a Buk
 * - Incluye Leader/Jefe en custom_attributes
 *
 * REQUISITOS:
 * - PHP con mysqli + curl
 * - Tabla: adp_empleados con columna `Rut` (tal como en tu BD)
 *
 * CONFIG DB:
 * - Ideal por variables de entorno en cPanel:
 *   DB_HOST, DB_USER, DB_PASS, DB_NAME
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../includes/auth.php'; // tu login / sesión
require_auth();

date_default_timezone_set('America/Santiago');

/* =========================
 * CONFIG BUK
 * ========================= */
if (!defined('BUK_API_BASE')) define('BUK_API_BASE', 'https://sti.buk.cl/api/v1/chile');
if (!defined('BUK_TOKEN'))    define('BUK_TOKEN', getenv('BUK_TOKEN') ?: 'bAVH6fNSraVT17MBv1ECPrfW');

/* =========================
 * CONFIG DB (INTEGRADA)
 * ========================= */
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'nehgryws_stiuser');
define('DB_PASS', getenv('DB_PASS') ?: 'fs73xig9e9t0');
define('DB_NAME', getenv('DB_NAME') ?: 'nehgryws_stisoft');

$LOG_DIR = __DIR__ . '/../logs/cambios';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);

/* =========================
 * HELPERS
 * ========================= */
 
 //
 function str_norm_db($v): string {
    $v = is_null($v) ? '' : (string)$v;
    // quita NBSP y similares
    $v = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $v);
    $v = trim($v);
    $v = preg_replace('/\s+/', ' ', $v);
    return $v;
}

function dbv(array $row, array $candidates, string $default = ''): string {
    foreach ($candidates as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null) {
            return str_norm_db($row[$k]);
        }
    }
    return $default;
}
 
 
 //
function e($str): string { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }

function cambios_log(string $event, array $data = []): void {
    global $LOG_DIR;
    $row = ['ts' => date('c'), 'event' => $event, 'data' => $data];
    $file = $LOG_DIR . '/cambios_' . date('Ymd') . '.jsonl';
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
}

function str_norm($v): string {
    $v = is_null($v) ? '' : (string)$v;
    $v = trim($v);
    $v = preg_replace('/\s+/', ' ', $v);
    return $v;
}

/**
 * Normaliza RUT en PHP:
 * - borra puntos, espacios, tabs, saltos
 * - borra guiones normales y guiones unicode (– — −)
 * - deja solo 0-9 y K
 */
function rut_norm(string $rut): string {
    $rut = strtoupper(str_norm($rut));
    // reemplaza varios tipos de guion por nada
    $rut = str_replace(["-", "–", "—", "−"], "", $rut);
    // borra puntos y espacios raros
    $rut = str_replace([".", " ", "\t", "\r", "\n", "\xC2\xA0"], "", $rut); // incluye NBSP
    // deja solo 0-9 y K
    return preg_replace('/[^0-9K]/', '', $rut);
}

/** Base sin DV: si viene con DV, lo quita; si no, retorna igual */
function rut_base_from_norm(string $rutNorm): string {
    if ($rutNorm === '') return '';
    // si termina en K o dígito y tiene largo >= 2, asumimos DV al final
    if (strlen($rutNorm) >= 2) return preg_replace('/\D/', '', substr($rutNorm, 0, -1));
    return preg_replace('/\D/', '', $rutNorm);
}

/* =========================
 * DB (mysqli)
 * ========================= */
function db(): mysqli {
    static $cn = null;
    if ($cn instanceof mysqli) return $cn;

    $cn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($cn->connect_error) {
        cambios_log('db_connect_error', ['err' => $cn->connect_error, 'host' => DB_HOST, 'db' => DB_NAME, 'user' => DB_USER]);
        throw new RuntimeException('Error conectando a la BD: ' . $cn->connect_error);
    }
    $cn->set_charset('utf8mb4');
    return $cn;
}

/**
 * Retorna el "expr normalizador" para Rut en SQL.
 * Preferencia: REGEXP_REPLACE(UPPER(Rut), '[^0-9K]', '')
 * Fallback: REPLACE encadenados incluyendo guiones unicode.
 */
function db_rut_expr(mysqli $cn, string $field = 'Rut'): string {
    static $useRegex = null;

    if ($useRegex === null) {
        // probamos REGEXP_REPLACE
        $useRegex = false;
        $test = @$cn->query("SELECT REGEXP_REPLACE('A-1', '[^0-9K]', '') AS x");
        if ($test) {
            $useRegex = true;
            $test->free();
        }
    }

    if ($useRegex) {
        // MySQL8/MariaDB con REGEXP_REPLACE
        return "REGEXP_REPLACE(UPPER(`{$field}`), '[^0-9K]', '')";
    }

    // Fallback: REPLACE en cascada (incluye guiones unicode)
    // Nota: ponemos los guiones unicode literal dentro del SQL.
    $expr = "UPPER(`{$field}`)";
    $repls = [
        '.', '', ' ', '', CHAR(9), '', CHAR(10), '', CHAR(13), '',
        '-', '', '–', '', '—', '', '−', '',
    ];

    // construimos: REPLACE(REPLACE(...))
    // ojo: CHAR(9/10/13) ya son funciones, se manejan aparte
    $expr = "REPLACE({$expr}, '.', '')";
    $expr = "REPLACE({$expr}, ' ', '')";
    $expr = "REPLACE({$expr}, '-', '')";
    $expr = "REPLACE({$expr}, '–', '')";
    $expr = "REPLACE({$expr}, '—', '')";
    $expr = "REPLACE({$expr}, '−', '')";
    $expr = "REPLACE({$expr}, CHAR(9), '')";
    $expr = "REPLACE({$expr}, CHAR(10), '')";
    $expr = "REPLACE({$expr}, CHAR(13), '')";
    $expr = "REPLACE({$expr}, '.', '')"; // repetimos por seguridad
    return $expr;
}

/**
 * Busca empleado por rut:
 * - intenta match por rut_norm completo
 * - intenta match por base (sin dv) también
 *
 * Esto cubre:
 * - BD con guion normal o guion raro
 * - BD con DV pegado / con guion / sin DV
 */
function db_get_emp_by_rut(string $rut): ?array {
    $cn = db();

    $norm = rut_norm($rut);                // ej: 170804252
    $base = rut_base_from_norm($norm);     // ej: 17080425

    if ($norm === '' && $base === '') return null;

    $expr = db_rut_expr($cn, 'Rut');

    // OJO: si la BD guarda "17080425-2" -> expr => "170804252"
    // si guarda "17080425–2" -> expr => "170804252" (por reemplazo de guion unicode)
    // si guarda solo base "17080425" -> expr => "17080425"
    $sql = "SELECT *
            FROM adp_empleados
            WHERE {$expr} = ? OR {$expr} = ?
            LIMIT 1";

    $stmt = $cn->prepare($sql);
    if (!$stmt) return null;

    // bind: norm y base
    $stmt->bind_param('ss', $norm, $base);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

/* =========================
 * CSV/TXT
 * ========================= */
function detect_delimiter(string $filePath): string {
    $delims = ["," => 0, ";" => 0, "\t" => 0, "|" => 0];
    $line = '';
    $fh = fopen($filePath, 'r');
    if ($fh) { $line = (string)fgets($fh); fclose($fh); }
    foreach ($delims as $d => $c) $delims[$d] = substr_count($line, $d);
    arsort($delims);
    return (string)array_key_first($delims);
}

function header_key(string $s): string {
    $s = trim($s, " \t\n\r\0\x0B\xEF\xBB\xBF");
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = trim($s, '_');

    $map = [
        'rut'=>'rut','r_u_t'=>'rut','rut_empleado'=>'rut','rutbase'=>'rut','rut_base'=>'rut',
        'estado'=>'estado','status'=>'estado',

        'nombres'=>'nombres','nombre'=>'nombres',
        'apaterno'=>'apaterno','apellido_paterno'=>'apaterno','paterno'=>'apaterno',
        'amaterno'=>'amaterno','apellido_materno'=>'amaterno','materno'=>'amaterno',

        'sexo'=>'sexo',
        'mail'=>'mail','email'=>'mail','correo'=>'mail',

        'fecha_nacimiento'=>'fecha_nacimiento','fecha_de_nacimiento'=>'fecha_nacimiento',
        'fecha_de_ingreso'=>'fecha_de_ingreso','fecha_ingreso'=>'fecha_de_ingreso',

        'descripcion_cargo'=>'descripcion_cargo',
        'codigo_afp'=>'codigo_afp',
        'descripcion_afp'=>'descripcion_afp',
        'descripcion_isapre'=>'descripcion_isapre',
        'fecha_seguro_cesantia'=>'fecha_seguro_cesantia',

        'descripcion_forma_de_pago_1'=>'descripcion_forma_de_pago_1',
        'descripcion_banco_fpago1'=>'descripcion_banco_fpago1',
        'cta_corriente_fpago1'=>'cta_corriente_fpago1',

        'descripcion_sindicato'=>'descripcion_sindicato',
        'descripcion_categoria'=>'descripcion_categoria',

        // leader/jefe
        'leader'=>'leader','lider'=>'leader','jefe'=>'leader','supervisor'=>'leader','manager'=>'leader','encargado'=>'leader',
    ];

    return $map[$s] ?? $s;
}

function read_csv_rows(string $filePath): array {
    $rows = [];
    $delimiter = detect_delimiter($filePath);

    $h = fopen($filePath, 'r');
    if (!$h) return [null, []];

    $rawHeader = fgetcsv($h, 0, $delimiter);
    if (!$rawHeader) { fclose($h); return [null, []]; }

    $header = array_map(fn($c) => header_key((string)$c), $rawHeader);

    while (($data = fgetcsv($h, 0, $delimiter)) !== false) {
        if (!$data || (count($data) === 1 && trim((string)$data[0]) === '')) continue;
        if (count($data) < count($header)) $data = array_pad($data, count($header), null);
        if (count($data) > count($header)) $data = array_slice($data, 0, count($header));

        $row = [];
        foreach ($header as $i => $col) {
            if ($col === '') continue;
            $row[$col] = $data[$i] ?? null;
        }
        $rows[] = $row;
    }
    fclose($h);

    return [$header, $rows];
}

function to_ymd($date): ?string {
    $date = str_norm($date);
    if ($date === '') return null;
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $date, $m)) return $m[3].'-'.$m[2].'-'.$m[1];
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return $date;
    if (preg_match('~^\d{8}$~', $date)) return substr($date,0,4).'-'.substr($date,4,2).'-'.substr($date,6,2);
    return null;
}

/* =========================
 * MAPEOS (conservadores)
 * ========================= */
function map_payment_method($v): ?string {
    $v = strtoupper(str_norm($v));
    if ($v === '') return null;
    if (strpos($v, 'TRANSFER') !== false) return 'Transferencia Bancaria';
    if (strpos($v, 'CHEQUE') !== false) return 'Cheque';
    if (strpos($v, 'SERVIPAG') !== false) return 'Servipag';
    if (strpos($v, 'VALE VISTA') !== false || strpos($v, 'VALEVISTA') !== false) return 'Vale Vista';
    if (strpos($v, 'NO GENER') !== false) return 'No Generar Pago';
    return null;
}
function map_bank($v): ?string {
    $v = strtoupper(str_norm($v));
    if ($v === '') return null;
    if (strpos($v, 'BANCO ESTADO') !== false || $v === 'ESTADO') return 'Banco Estado';
    if (strpos($v, 'SCOTIA') !== false) return 'Scotiabank';
    if (strpos($v, 'FALABELLA') !== false) return 'Falabella';
    if (strpos($v, 'BCI') !== false) return 'BCI';
    if (strpos($v, 'CORPBANCA') !== false) return 'Corpbanca';
    if (strpos($v, 'SANTANDER') !== false) return 'Santander';
    if (strpos($v, 'BANCO DE CHILE') !== false || $v === 'CHILE') return 'Banco de Chile';
    if (strpos($v, 'BBVA') !== false) return 'BBVA';
    if (strpos($v, 'ITAU') !== false) return 'Itau';
    if (strpos($v, 'RIPLEY') !== false) return 'Ripley';
    if (strpos($v, 'SECURITY') !== false) return 'Security';
    if (strpos($v, 'BICE') !== false) return 'BICE';
    return null;
}
function map_fund_quote($v): ?string {
    $v = strtolower(str_norm($v));
    if ($v === '') return null;
    if (strpos($v, 'capital') !== false) return 'capital';
    if (strpos($v, 'cuprum') !== false) return 'cuprum';
    if (strpos($v, 'habitat') !== false) return 'habitat';
    if (strpos($v, 'modelo') !== false) return 'modelo';
    if (strpos($v, 'planvital') !== false || strpos($v, 'plan vital') !== false) return 'planvital';
    if (strpos($v, 'provida') !== false || strpos($v, 'pro vida') !== false) return 'provida';
    if (strpos($v, 'uno') !== false) return 'uno';
    return null;
}
function map_health_company($v): ?string {
    $v = strtolower(str_norm($v));
    if ($v === '') return null;
    if (strpos($v, 'fonasa') !== false) return 'fonasa';
    if (strpos($v, 'consalud') !== false) return 'consalud';
    if (strpos($v, 'colmena') !== false) return 'colmena';
    if (strpos($v, 'banmedica') !== false) return 'banmedica';
    if (strpos($v, 'cruz') !== false && strpos($v, 'blanca') !== false) return 'cruz_blanca';
    if (strpos($v, 'nueva') !== false && strpos($v, 'masvida') !== false) return 'nueva_masvida';
    if (strpos($v, 'vida') !== false && strpos($v, 'tres') !== false) return 'vida_tres';
    return null;
}

/* =========================
 * BUK HTTP (PATCH)
 * ========================= */
function buk_patch(string $path, array $payload): array {
    $url = rtrim(BUK_API_BASE, '/') . '/' . ltrim($path, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . BUK_TOKEN,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 60,
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$http, (string)$body, (string)$err, $url];
}



/* =========================
 * DETECTOR DE CAMBIOS
 * ========================= */
function build_changes_for_row(array $csvRow, array $dbRow): array {
    $csvEstado = str_norm($csvRow['estado'] ?? '');
    if ($csvEstado !== '' && strtoupper($csvEstado) !== 'A') {
        return ['skip' => "Estado en CSV distinto de A ('{$csvEstado}')"];
    }

    $bukEmpId  = (int)($dbRow['buk_emp_id'] ?? 0);
    $bukPlanId = (int)($dbRow['buk_plan_id'] ?? 0);
    $bukJobId  = (int)($dbRow['buk_job_id'] ?? 0);

    if ($bukEmpId <= 0) return ['skip' => 'Sin buk_emp_id en BD (no se puede actualizar)'];

    $diffs = ['PROFILE'=>[], 'PLAN'=>[], 'JOB'=>[], 'meta'=>[], 'warnings'=>[]];

    // PROFILE
    $newFirst = str_norm($csvRow['nombres'] ?? '');
    $newSur   = str_norm($csvRow['apaterno'] ?? '');
    $newSur2  = str_norm($csvRow['amaterno'] ?? '');

    if ($newFirst !== '' && str_norm($dbRow['Nombres'] ?? '') !== $newFirst)  $diffs['PROFILE']['first_name'] = $newFirst;
    if ($newSur   !== '' && str_norm($dbRow['Apaterno'] ?? '') !== $newSur)  $diffs['PROFILE']['surname'] = $newSur;
    if ($newSur2  !== '' && str_norm($dbRow['Amaterno'] ?? '') !== $newSur2) $diffs['PROFILE']['second_surname'] = $newSur2;

    $newGender = strtoupper(str_norm($csvRow['sexo'] ?? ''));
    if (in_array($newGender, ['M','F'], true) && str_norm($dbRow['Sexo'] ?? '') !== $newGender) {
        $diffs['PROFILE']['gender'] = $newGender;
    }

    $newEmail = str_norm($csvRow['mail'] ?? '');
    $oldEmail = str_norm($dbRow['Mail'] ?? '');
    if ($newEmail !== '' && strtoupper($newEmail) !== strtoupper($oldEmail)) {
        $diffs['PROFILE']['email'] = $newEmail;
    }

    $newBirth = to_ymd($csvRow['fecha_nacimiento'] ?? '');
    $oldBirth = to_ymd($dbRow['Fecha Nacimiento'] ?? '');
    if ($newBirth && $newBirth !== $oldBirth) $diffs['PROFILE']['birthday'] = $newBirth;

   $newPayMethodRaw = str_norm_db($csvRow['descripcion_forma_de_pago_1'] ?? '');
$oldPayMethodRaw = dbv($dbRow, [
  'Descripcion Forma de Pago 1',
  'Descripción Forma de Pago 1',
  'Descripcion Forma de Pago 1 ',
  'Descripción Forma de Pago 1 ',
]);    if ($newPayMethodRaw !== '' && $newPayMethodRaw !== $oldPayMethodRaw) {
        $mapped = map_payment_method($newPayMethodRaw);
        if ($mapped) $diffs['PROFILE']['payment_method'] = $mapped;
        else $diffs['warnings'][] = "Forma de pago sin mapeo Buk: '{$newPayMethodRaw}'";
    }

    $newBankRaw = str_norm($csvRow['descripcion_banco_fpago1'] ?? '');
    $oldBankRaw = str_norm($dbRow['Descripcion Banco fpago1'] ?? '');
    if ($newBankRaw !== '' && $newBankRaw !== $oldBankRaw) {
        $mapped = map_bank($newBankRaw);
        if ($mapped) $diffs['PROFILE']['bank'] = $mapped;
        else $diffs['warnings'][] = "Banco sin mapeo Buk: '{$newBankRaw}'";
    }

    $newAcc = str_norm($csvRow['cta_corriente_fpago1'] ?? '');
    $oldAcc = str_norm($dbRow['Cta Corriente fpago1'] ?? '');
    if ($newAcc !== '' && $newAcc !== $oldAcc) $diffs['PROFILE']['account_number'] = $newAcc;

    // custom_attributes: sindicatos + leader/jefe
    $custom = [];

    $newSindAnt = str_norm($csvRow['descripcion_sindicato'] ?? '');
    $oldSindAnt = str_norm($dbRow['Descripción Sindicato'] ?? '');
    if ($newSindAnt !== '' && $newSindAnt !== $oldSindAnt) $custom['Sindicato Anterior'] = $newSindAnt;

    $newSindAct = str_norm($csvRow['descripcion_categoria'] ?? '');
    $oldSindAct = str_norm($dbRow['Descripción Categoria'] ?? '');
    if ($newSindAct !== '' && $newSindAct !== $oldSindAct) $custom['Sindicato Actual'] = $newSindAct;

    $leader = str_norm($csvRow['leader'] ?? '');
    if ($leader !== '') $custom['Leader/Jefe'] = $leader;

    if (!empty($custom)) $diffs['PROFILE']['custom_attributes'] = $custom;

    // PLAN (si existe id)
    if ($bukPlanId > 0) {
        $newAfpDesc = str_norm($csvRow['descripcion_afp'] ?? '');
        $oldAfpDesc = str_norm($dbRow['Descripcion AFP'] ?? '');
        if ($newAfpDesc !== '' && $newAfpDesc !== $oldAfpDesc) {
            $mapped = map_fund_quote($newAfpDesc);
            if ($mapped) $diffs['PLAN']['fund_quote'] = $mapped;
            else $diffs['warnings'][] = "AFP sin mapeo fund_quote: '{$newAfpDesc}'";
        }

        $newIsapre = str_norm($csvRow['descripcion_isapre'] ?? '');
        $oldIsapre = str_norm($dbRow['Descripcion Isapre'] ?? '');
        if ($newIsapre !== '' && $newIsapre !== $oldIsapre) {
            $mapped = map_health_company($newIsapre);
            if ($mapped) $diffs['PLAN']['health_company'] = $mapped;
            else $diffs['warnings'][] = "Isapre/Salud sin mapeo health_company: '{$newIsapre}'";
        }

        $newAfcDate = str_norm($csvRow['fecha_seguro_cesantia'] ?? '');
        $oldAfcDate = str_norm($dbRow['Fecha Seguro Cesantia'] ?? '');
        if ($newAfcDate !== '' && $newAfcDate !== $oldAfcDate) $diffs['PLAN']['afc'] = 'normal';
    } else {
        $diffs['warnings'][] = "Sin buk_plan_id, no se enviarán cambios de PLAN.";
    }

    // JOB (si existe id)
    if ($bukJobId > 0) {
        $newStart = to_ymd($csvRow['fecha_de_ingreso'] ?? '');
        $oldStart = to_ymd($dbRow['Fecha de Ingreso'] ?? '');
        if ($newStart && $newStart !== $oldStart) $diffs['JOB']['start_date'] = $newStart;

        $newCargo = str_norm($csvRow['descripcion_cargo'] ?? '');
        $oldCargo = str_norm($dbRow['Descripcion Cargo'] ?? '');
        if ($newCargo !== '' && $newCargo !== $oldCargo) {
            $diffs['warnings'][] = "Cambio de Cargo ('{$oldCargo}' -> '{$newCargo}'): requiere mapear a role_id.";
        }
    } else {
        $diffs['warnings'][] = "Sin buk_job_id, no se enviarán cambios de JOB.";
    }

    if (empty($diffs['PROFILE']) && empty($diffs['PLAN']) && empty($diffs['JOB'])) {
        return ['skip' => 'Sin cambios relevantes'];
    }

    $diffs['meta'] = ['buk_emp_id'=>$bukEmpId, 'buk_plan_id'=>$bukPlanId, 'buk_job_id'=>$bukJobId];
    return $diffs;
}

/* =========================
 * SESSION STATE
 * ========================= */
$_SESSION['cambios_rows']      = $_SESSION['cambios_rows']      ?? [];
$_SESSION['cambios_status']    = $_SESSION['cambios_status']    ?? [];
$_SESSION['cambios_last_file'] = $_SESSION['cambios_last_file'] ?? null;

/* =========================
 * ACTIONS
 * ========================= */
$action = $_POST['action'] ?? null;

// Detectar
if ($action === 'detectar' && isset($_FILES['archivo']) && is_uploaded_file($_FILES['archivo']['tmp_name'])) {
    $tmp  = $_FILES['archivo']['tmp_name'];
    $name = $_FILES['archivo']['name'] ?? 'archivo.csv';

    cambios_log('upload', ['file' => $name]);

    [$header, $rows] = read_csv_rows($tmp);
    if (!$header) {
        $_SESSION['flash_error'] = 'No se pudo leer el CSV/TXT (cabecera vacía).';
        header('Location: index.php'); exit;
    }

    $_SESSION['cambios_rows'] = [];
    $_SESSION['cambios_status'] = [];
    $_SESSION['cambios_last_file'] = $name;

    $pend = 0; $skips = 0;

    foreach ($rows as $r) {
        $rut = $r['rut'] ?? null;
        if (!$rut) { $skips++; continue; }

        $dbRow = db_get_emp_by_rut((string)$rut);
        if (!$dbRow) {
            $norm = rut_norm((string)$rut);
            $_SESSION['cambios_rows'][] = [
                'rut' => (string)$rut,
                'nombre' => '',
                'msg' => "No existe en BD (adp_empleados). buscado norm={$norm} base=" . rut_base_from_norm($norm),
            ];
            $_SESSION['cambios_status'][] = 'skip';
            $skips++; continue;
        }

        $diff = build_changes_for_row($r, $dbRow);
        if (isset($diff['skip'])) {
            $_SESSION['cambios_rows'][] = ['rut'=>(string)$rut, 'nombre'=>'', 'msg'=>$diff['skip']];
            $_SESSION['cambios_status'][] = 'skip';
            $skips++; continue;
        }

        $_SESSION['cambios_rows'][] = [
            'rut' => (string)($dbRow['Rut'] ?? $rut),
            'nombre' => str_norm(($r['nombres'] ?? '') . ' ' . ($r['apaterno'] ?? '') . ' ' . ($r['amaterno'] ?? '')),
            'meta' => $diff['meta'] ?? [],
            'PROFILE' => $diff['PROFILE'] ?? [],
            'PLAN' => $diff['PLAN'] ?? [],
            'JOB' => $diff['JOB'] ?? [],
            'warnings' => $diff['warnings'] ?? [],
        ];
        $_SESSION['cambios_status'][] = 'pendiente';
        $pend++;
    }

    $_SESSION['flash_ok'] = "Detección lista. Pendientes: {$pend} | Skip: {$skips}";
    cambios_log('detect_done', ['file'=>$name, 'pendientes'=>$pend, 'skip'=>$skips]);

    header('Location: index.php'); exit;
}

// Enviar
if ($action === 'enviar') {
    $modo = $_POST['modo'] ?? 'seleccionados';
    $selected = $_POST['sel'] ?? [];

    $rows = $_SESSION['cambios_rows'] ?? [];
    $status = $_SESSION['cambios_status'] ?? [];

    $toSend = [];
    if ($modo === 'todos') {
        foreach ($rows as $i => $_r) if (($status[$i] ?? '') === 'pendiente') $toSend[] = $i;
    } else {
        foreach ($selected as $idx) {
            $i = (int)$idx;
            if (($status[$i] ?? '') === 'pendiente') $toSend[] = $i;
        }
    }

    $ok = 0; $err = 0;

    foreach ($toSend as $i) {
        $row = $rows[$i] ?? null;
        if (!$row) continue;

        $bukEmpId  = (int)($row['meta']['buk_emp_id'] ?? 0);
        $bukPlanId = (int)($row['meta']['buk_plan_id'] ?? 0);
        $bukJobId  = (int)($row['meta']['buk_job_id'] ?? 0);

        $reqs = [];
        if (!empty($row['PROFILE'])) $reqs[] = ['type'=>'PROFILE', 'path'=>"employees/{$bukEmpId}", 'payload'=>$row['PROFILE']];
        if (!empty($row['PLAN']) && $bukPlanId > 0) $reqs[] = ['type'=>'PLAN', 'path'=>"employees/{$bukEmpId}/plans/{$bukPlanId}", 'payload'=>$row['PLAN']];
        if (!empty($row['JOB']) && $bukJobId > 0) $reqs[] = ['type'=>'JOB', 'path'=>"employees/{$bukEmpId}/jobs/{$bukJobId}", 'payload'=>$row['JOB']];

        if (empty($reqs)) { $_SESSION['cambios_status'][$i] = 'skip'; continue; }

        $allOk = true;
        $respPack = [];

        foreach ($reqs as $rq) {
            [$http, $body, $curlErr, $url] = buk_patch($rq['path'], $rq['payload']);

            $respPack[] = ['type'=>$rq['type'], 'url'=>$url, 'http'=>$http, 'err'=>$curlErr, 'payload'=>$rq['payload'], 'body'=>$body];

            cambios_log('buk_patch', [
                'rut'=>$row['rut'] ?? '',
                'type'=>$rq['type'],
                'url'=>$url,
                'http'=>$http,
                'err'=>$curlErr,
                'payload'=>$rq['payload'],
                'body'=>$body,
            ]);

            if ($curlErr || $http < 200 || $http >= 300) $allOk = false;
        }

        $_SESSION['cambios_rows'][$i]['last_response'] = $respPack;

        if ($allOk) { $_SESSION['cambios_status'][$i] = 'ok'; $ok++; }
        else { $_SESSION['cambios_status'][$i] = 'error'; $err++; }
    }

    $_SESSION['flash_ok'] = "Envío terminado. OK: {$ok} | ERROR: {$err}";
    header('Location: index.php'); exit;
}

/* =========================
 * VIEW
 * ========================= */
$flash_ok = $_SESSION['flash_ok'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$rows = $_SESSION['cambios_rows'] ?? [];
$status = $_SESSION['cambios_status'] ?? [];
$lastFile = $_SESSION['cambios_last_file'] ?? null;

/* DIAGNÓSTICO DB */
$dbInfo = null;
$dbErr  = null;
try {
    $cn = db();
    $cntRes = $cn->query("SELECT COUNT(*) c FROM adp_empleados");
    $cnt = $cntRes ? (int)($cntRes->fetch_assoc()['c'] ?? 0) : 0;
    if ($cntRes) $cntRes->free();
    $dbInfo = [
        'host' => DB_HOST,
        'db'   => DB_NAME,
        'user' => DB_USER,
        'rows' => $cnt,
        'expr' => db_rut_expr($cn, 'Rut'),
    ];
} catch (Throwable $ex) {
    $dbErr = $ex->getMessage();
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cambios (ADP → Buk)</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:0;background:#f6f7fb;}
    .wrap{max-width:1200px;margin:24px auto;padding:0 16px;}
    .card{background:#fff;border:1px solid #e6e8ef;border-radius:12px;padding:16px;margin-bottom:16px;}
    .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
    .btn{border:0;border-radius:10px;padding:10px 14px;cursor:pointer;font-weight:600}
    .btn-dark{background:#111;color:#fff}
    .btn-green{background:#12a150;color:#fff}
    .btn-blue{background:#2b6cff;color:#fff}
    .btn-gray{background:#e9edf7}
    .msg{padding:10px 12px;border-radius:10px;margin:10px 0}
    .ok{background:#e9f8ef;border:1px solid #bfe8cc}
    .err{background:#ffeceb;border:1px solid #ffbdb7}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid #eef0f6;padding:10px;text-align:left;vertical-align:top}
    th{background:#fafbff;font-size:13px;color:#3b4256}
    .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700}
    .b-skip{background:#e9e9ee}
    .b-pend{background:#fff1b8}
    .b-ok{background:#bff0cf}
    .b-err{background:#ffbdb7}
    details pre{background:#0b1020;color:#e7eaff;padding:10px;border-radius:10px;overflow:auto}
    code{background:#eef0f6;padding:2px 6px;border-radius:8px}
    .small{color:#5a6175;font-size:13px}
  </style>
</head>
<body>
<div class="wrap">

  <div class="card">
    <h2 style="margin:0 0 6px 0;">Cambios (ADP → Buk)</h2>
    <div class="small">Sube CSV/TXT ADP, detecta diferencias vs <code>adp_empleados</code> y envía PATCH a Buk.</div>

    <?php if ($dbErr): ?>
      <div class="msg err"><b>DB ERROR:</b> <?= e($dbErr) ?></div>
    <?php else: ?>
      <div class="msg ok">
        <b>DB OK</b> host=<code><?= e($dbInfo['host']) ?></code>
        db=<code><?= e($dbInfo['db']) ?></code>
        user=<code><?= e($dbInfo['user']) ?></code>
        filas=<code><?= (int)$dbInfo['rows'] ?></code>
      </div>
      <details>
        <summary class="small">Ver normalizador SQL usado</summary>
        <pre><?= e((string)$dbInfo['expr']) ?></pre>
      </details>
    <?php endif; ?>

    <?php if ($flash_ok): ?><div class="msg ok"><?= e($flash_ok) ?></div><?php endif; ?>
    <?php if ($flash_error): ?><div class="msg err"><?= e($flash_error) ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="row" style="margin-top:12px;">
      <input type="hidden" name="action" value="detectar">
      <input type="file" name="archivo" accept=".csv,.txt,text/plain,text/csv" required>
      <button class="btn btn-dark" type="submit">Detectar cambios</button>
      <?php if ($lastFile): ?><span class="small">Último: <b><?= e($lastFile) ?></b></span><?php endif; ?>
    </form>

    <div style="margin-top:10px" class="small">
      Token actual: <?= (BUK_TOKEN === 'PON_TU_TOKEN_REAL' ? '<b style="color:#b00020">NO CONFIGURADO</b>' : '<b>OK</b>') ?>
    </div>
  </div>

  <div class="card">
    <div class="row" style="justify-content:space-between;">
      <h3 style="margin:0;">Listado</h3>

      <form method="post" class="row" style="margin:0;">
        <input type="hidden" name="action" value="enviar">
        <input type="hidden" name="modo" value="todos">
        <button class="btn btn-green" type="submit">Enviar TODO (pendientes)</button>
      </form>
    </div>

    <div style="margin:10px 0" class="small">
      Estados:
      <span class="badge b-skip">skip</span>
      <span class="badge b-pend">pendiente</span>
      <span class="badge b-ok">ok</span>
      <span class="badge b-err">error</span>
    </div>

    <form method="post">
      <input type="hidden" name="action" value="enviar">
      <input type="hidden" name="modo" value="seleccionados">

      <table>
        <thead>
          <tr>
            <th style="width:36px;"></th>
            <th>RUT</th>
            <th>Nombre</th>
            <th>Estado</th>
            <th>Detectado</th>
            <th>Warnings</th>
            <th>Respuesta</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="small">Aún no hay detecciones.</td></tr>
        <?php endif; ?>

        <?php foreach ($rows as $i => $r): ?>
          <?php
            $st = $status[$i] ?? 'skip';
            $b = ($st==='pendiente')?'b-pend':(($st==='ok')?'b-ok':(($st==='error')?'b-err':'b-skip'));
            $countProfile = !empty($r['PROFILE']) ? count($r['PROFILE']) : 0;
            $countPlan    = !empty($r['PLAN']) ? count($r['PLAN']) : 0;
            $countJob     = !empty($r['JOB']) ? count($r['JOB']) : 0;
          ?>
          <tr>
            <td>
              <?php if ($st === 'pendiente'): ?>
                <input type="checkbox" name="sel[]" value="<?= (int)$i ?>">
              <?php endif; ?>
            </td>
            <td><b><?= e($r['rut'] ?? '') ?></b></td>
            <td><?= e($r['nombre'] ?? '') ?></td>
            <td><span class="badge <?= e($b) ?>"><?= e($st) ?></span></td>
            <td>
              <?php if (!empty($r['msg'])): ?>
                <span class="small"><?= e($r['msg']) ?></span>
              <?php else: ?>
                PROFILE: <b><?= (int)$countProfile ?></b> |
                PLAN: <b><?= (int)$countPlan ?></b> |
                JOB: <b><?= (int)$countJob ?></b>
                <details style="margin-top:6px;">
                  <summary>Ver payloads</summary>
                  <pre><?= e(json_encode([
                      'PROFILE'=>$r['PROFILE'] ?? [],
                      'PLAN'=>$r['PLAN'] ?? [],
                      'JOB'=>$r['JOB'] ?? [],
                      'meta'=>$r['meta'] ?? []
                  ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
                </details>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($r['warnings'])): ?>
                <ul style="margin:0;padding-left:16px;color:#7a2b00">
                  <?php foreach ($r['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?>
                </ul>
              <?php else: ?>
                <span class="small">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($r['last_response'])): ?>
                <?php foreach ($r['last_response'] as $resp): ?>
                  <div style="margin-bottom:8px;">
                    <b><?= e($resp['type']) ?></b> | HTTP <b><?= (int)$resp['http'] ?></b>
                    <?php if (!empty($resp['err'])): ?>
                      <div style="color:#b00020">cURL: <?= e($resp['err']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($resp['body'])): ?>
                      <details>
                        <summary>body</summary>
                        <pre><?= e($resp['body']) ?></pre>
                      </details>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="small">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="row" style="margin-top:12px;">
        <button class="btn btn-blue" type="submit">Enviar seleccionados</button>
        <a class="btn btn-gray" href="index.php">Refrescar</a>
      </div>
    </form>
  </div>

</div>
</body>
</html>

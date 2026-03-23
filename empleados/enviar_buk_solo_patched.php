<?php
// enviar_buk.php — Carga empleados ADP (BD) → Buk con:
// - Lectura desde tabla `adp_empleados` (BD)
// - Envío individual / masivo
// - Logs por registro en logs_buk
// - Creación EMP y luego JOB (trabajo) con jerarquías
// - Mapeos: Centro de Costo → Área, Cargo ADP → Role Buk, Jefe (RUT) → leader_id
// - Si falta área/cargo/jefe: EMP OK pero JOB se salta, se registra EMP_OK_JOB_SKIP

// ================== FIX: headers already sent ==================
ob_start();
// ===============================================================

require_once __DIR__ . '/../includes/auth.php';
require_auth();
$user = current_user();
require_once __DIR__ . '/../conexion/db.php';
$db = new clsConexion();

session_start();
ini_set('default_charset', 'UTF-8');

// ===== DEBUG (desactívalo en prod) =====
error_reporting(E_ALL);
ini_set('display_errors', '1');
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "FATAL: " . htmlspecialchars($e['message'], ENT_QUOTES, 'UTF-8') .
             " in " . htmlspecialchars($e['file'], ENT_QUOTES, 'UTF-8') . ":" . $e['line'];
    }
});
// =======================================

// ================= CONFIG =================
const BUK_API_BASE         = 'https://sti.buk.cl/api/v1/chile';
const BUK_EMP_CREATE_PATH  = '/employees.json';
const BUK_JOB_CREATE_PATH  = '/employees/%d/jobs'; // POST
const BUK_ROLES_PATH       = '/roles';             // GET/POST
const BUK_TOKEN            = 'bAVH6fNSraVT17MBv1ECPrfW'; // ⚠️ mover a .env en prod

// Para job
const COMPANY_ID_FOR_JOBS  = 1;            // ajusta si corresponde
const DEFAULT_LOCATION_ID  = 407;

const LOG_DIR              = __DIR__ . '/logs_buk';
const RUT_MAP_FILE         = LOG_DIR . '/rut_to_empid.json';  // rut limpio → employee_id
const ROLES_CACHE_FILE     = LOG_DIR . '/roles_cache.json';   // cache de /roles

const PAGE_SIZES           = [10,30,50,100]; // Paginación UI
// =========================================

// Reinicia dataset al entrar por GET sin parámetros (botón Reiniciar)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && count($_GET) === 0) {
    unset($_SESSION['rows'], $_SESSION['headers']);
}

if(!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// =============================================================
// Helpers genéricos
// =============================================================
function detect_separator(string $file): string {
    $fh = fopen($file, 'r');
    $first = '';
    if ($fh) {
        $first = fgets($fh, 4096) ?: '';
        fclose($fh);
    }
    if (strpos($first, ';') !== false) return ';';
    if (strpos($first, ',') !== false) return ',';
    if (strpos($first, "\t") !== false) return "\t";
    return ';';
}

function normalize_key(string $s): string {
    $s = trim($s);
    if ($s === '') return '';

    // Forzar UTF-8 "razonable"
    $s = (string)$s;

    // Quitar BOM si viene en el primer header
    $s = preg_replace('/^\xEF\xBB\xBF/u', '', $s);

    // Pasar a ASCII (quita tildes/acentos): "DESCRIPCIÓN" -> "DESCRIPCION"
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false && $t !== null) {
        $s = $t;
    }

    $s = strtolower($s);

    // Unificar separadores: espacios, guiones, puntos, etc.
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}


// ===== FUNCIÓN read_csv_assoc (CON ENCODING FORZADO SIN MBSTRING) ===
// ===================================================================

function read_csv_assoc(string $file): array {
    $sep = detect_separator($file);
    $fh = fopen($file,'r'); 
    if(!$fh) throw new RuntimeException('No se pudo abrir el archivo.');

    $headers_raw = fgetcsv($fh, 0, $sep); 
    if(!$headers_raw) throw new RuntimeException('No se detectaron columnas.');

    $headers = [];
    foreach ($headers_raw as $h) {
        $val = utf8_encode((string)$h);
        $val = trim($val);
        if ($val === '') continue;
        $headers[] = $val;
    }

    $rows=[];
    while(($row = fgetcsv($fh,0,$sep)) !== false){
        if (count($row) === 1 && (trim((string)$row[0]) === '' || $row[0] === null)) continue;

        $assoc=[];
        foreach($headers as $i=>$c){
            $val = isset($row[$i]) ? (string)$row[$i] : '';
            $val = utf8_encode($val);
            $val = trim($val);

            // Guardar con header original
            $assoc[$c] = $val;

            // Guardar también con header "normalizado" (sin tildes, minúsculas, etc.)
            $cn = normalize_key($c);
            if ($cn !== '' && !array_key_exists($cn, $assoc)) {
                $assoc[$cn] = $val;
            }
        }

        // Si toda la fila está vacía, omitir
        $nonEmpty = false;
        foreach ($assoc as $v) {
            if ($v !== '') { $nonEmpty = true; break; }
        }
        if($nonEmpty) $rows[]=$assoc;
    }
    fclose($fh);
    return [$headers,$rows,$sep];
}

// ===================================================================

function pick(array $row, array $cands): string {
    foreach ($cands as $c) {
        // 1) Match exacto (como venía antes)
        if (array_key_exists($c, $row) && $row[$c] !== '' && $row[$c] !== null) {
            return (string)$row[$c];
        }

        // 2) Match flexible: ignora tildes, mayúsculas, dobles espacios, puntos, etc.
        $cn = normalize_key((string)$c);
        if ($cn !== '' && array_key_exists($cn, $row) && $row[$cn] !== '' && $row[$cn] !== null) {
            return (string)$row[$cn];
        }
    }
    // Siempre devolver string (nunca null)
    return '';
}

function to_iso(?string $s): ?string {
    if($s===null) return null;
    $s=trim($s);
    if($s==='') return null;

    $s = str_replace(['.','/'], '-', $s);
    $s = preg_replace('/\s+/', ' ', $s);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $s;
    }

    $fmts = ['d-m-Y','d-m-y','Y-m-d','Y/m/d','d/m/Y','d/m/y'];
    foreach($fmts as $fmt){
        $dt = DateTime::createFromFormat($fmt, $s);
        if($dt && $dt->format($fmt)===$s) {
             $year = (int)$dt->format('Y');
             if ($year > 1900 && $year < 2100) {
                 return $dt->format('Y-m-d');
             }
        }
    }
    $s2 = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?(\.\d+)?$/','',$s);
    if ($s2 !== null && $s2 !== $s) {
         return to_iso($s2);
    }
    $ts = strtotime($s);
    if ($ts !== false) {
        $year = (int)date('Y', $ts);
        if ($year > 1900 && $year < 2100) {
            return date('Y-m-d', $ts);
        }
    }
    return null;
}

function norm_gender(?string $g): ?string {
    $g = mb_strtolower(trim((string)$g), 'UTF-8');
    if ($g === '' || $g === '0') return null;

    // Buk espera algo tipo "M" / "F" (como en el JSON que pegaste)
    if (in_array($g, ['m','masculino','hombre'], true)) return 'M';
    if (in_array($g, ['f','femenino','mujer'], true)) return 'F';

    return null;
}

function norm_payment_method(?string $m): ?string {
    $m = mb_strtolower(trim((string)$m), 'UTF-8');
    if ($m === '') return null;

    if (
        str_contains($m, 'transfer') ||
        str_contains($m, 'dep') ||
        str_contains($m, 'cta') ||
        str_contains($m, 'banc')
    ) {
        return 'Transferencia Bancaria';
    }

    if (str_contains($m, 'cheque')) {
        return 'Cheque';
    }

    if (str_contains($m, 'efectivo') || str_contains($m, 'cash')) {
        return 'Efectivo';
    }

    return null;
}

function norm_bank(?string $b): ?string {
    $b = mb_strtolower(trim((string)$b), 'UTF-8');
    if ($b === '') return null;

    // Mapear variantes de ADP a los nombres que usa Buk
    if (str_contains($b, 'chile'))      return 'Banco de Chile';
    if (str_contains($b, 'estado'))     return 'Banco Estado';
    if (str_contains($b, 'itau'))       return 'Itau';
    if (str_contains($b, 'santander'))  return 'Santander';
    if (str_contains($b, 'bci'))        return 'BCI';
    if (str_contains($b, 'bbva'))       return 'BBVA';
    if (str_contains($b, 'scotia'))     return 'Scotiabank';
    if (str_contains($b, 'falabella'))  return 'Banco Falabella';
    if (str_contains($b, 'ripley'))     return 'Banco Ripley';
    if (str_contains($b, 'security'))   return 'Security';

    // Si no lo reconocemos, devolvemos null para NO mandarlo
    return null;
}

function norm_account_type(?string $t): ?string {
    $t = mb_strtolower(trim((string)$t), 'UTF-8');
    if ($t === '') return null;

    if (str_contains($t, 'vista') || str_contains($t, 'rut')) {
        return 'Vista';
    }
    if (str_contains($t, 'corriente') || str_contains($t, 'cte')) {
        return 'Corriente';
    }
    if (str_contains($t, 'ahorro')) {
        return 'Ahorro';
    }

    return null;
}



function norm_payment_period(?string $p): ?string {
    $p = mb_strtolower(trim((string)$p), 'UTF-8');
    if($p==='') return null;
    if($p==='m' || strpos($p,'mensual')!==false) return 'mensual';
    if($p==='q' || strpos($p,'quincena')!==false) return 'quincenal';
    if($p==='s' || strpos($p,'semanal')!==false) return 'semanal';
    return null;
}

function money_to_int($v): ?int {
    if($v===null) return null;
    $s = (string)$v;
    $s = str_replace(['.', ' '], '', $s);
    $s = str_replace(',', '.', $s);
    if($s==='') return null;
    if(!is_numeric($s)) return null;
    return (int)round((float)$s);
}

// ============================================================
// Mapeo Centro de Costo ADP -> área Buk (mapa estático original)
// ============================================================
function map_area_id_from_row(array $r): ?int {
    $cc_raw = pick($r, [
        'Centro de Costo', 'Centro Costo', 'CC', 'Cencos', 'cost_center'
    ]);

    if ($cc_raw === '' || $cc_raw === null) {
        return null;
    }

    $cc_raw = trim((string)$cc_raw);

    // Para códigos alfanuméricos como "N", no limpiamos
    $onlyDigits = preg_replace('/\D+/', '', $cc_raw);
    $cc = $onlyDigits !== '' ? $onlyDigits : strtoupper($cc_raw);

    static $MAP_BY_CC = [
        // 1111: centro de costo genérico. Elige el área que definas como "base".
        // Aquí usamos Administración (121) solo como ejemplo:
        '1111'    => 121,

        // MAIPO
        '6090000' => 198, // Bono Produccion
        '6095000' => 199, // Eventual

        // STI - Muellaje
        '6010000' => 205, // División Operaciones
        '6020000' => 210, // División Terminales
        '6030000' => 216, // División Comercial
        '6040000' => 221, // División de Apoyo
        '6050000' => 226, // Operaciones Agua
        '6060000' => 231, // Operaciones Muellaje
        '6070000' => 236, // División Mantención
        '6080000' => 241, // División Servicios Generales

        // Agrega aquí todos los demás CC que tengas en tu mapa real...
    ];

    if (isset($MAP_BY_CC[$cc])) {
        return $MAP_BY_CC[$cc];
    }

    return null;
}

// ============================================================
// Mapeo Cargo ADP -> role_id Buk (mapa estático + creación en Buk)
// ============================================================
function map_role_id_from_row(array $r, ?int $areaId): ?int {
    $cargo = pick($r, ['Descripcion Cargo','Cargo','Cargo Nombre','role_name']);
    $cargo = trim(mb_strtolower($cargo ?? '', 'UTF-8'));
    if ($cargo === '') return null;

    static $MAP = [
        'analista de remuneraciones'                          => 17,
        'analista senior de remuneraciones'                   => 36,
        'analista de rrhh'                                    => 35,
        'encargada de remuneraciones de muellaje del maipo'   => 24,
        'gerente de operaciones'                              => 95,
        'gerente general'                                     => 87,
        'jefe de relaciones laborales'                        => 92,
        'jefe desarrollo de negocios'                         => 91,
        'prevencionista de riesgos'                           => 76,
        'administrativo(a)'                                   => 13,
        'auxiliar administrativo(a)'                          => 82,
        'secretaria'                                          => 65,
        'secretaria gerencia'                                 => 28,
        // ... agrega aquí el resto de tus cargos ADP → role_id Buk
    ];

    if (isset($MAP[$cargo])) {
        return $MAP[$cargo];
    }

    // Si no está en el mapa, intentamos encontrar o crear rol en Buk
    $roleId = ensure_role_exists_in_buk($cargo, $areaId);
    return $roleId;
}

// ============================================================
// Normalización de RUT para mapa rut → employee_id
// ============================================================
function normalize_rut(?string $rut): ?string {
    if($rut===null) return null;
    $rut = preg_replace('/[^0-9kK]/','',$rut);
    if($rut==='') return null;
    $rut = strtolower($rut);
    if(!preg_match('/^\d{6,9}[0-9k]$/',$rut)) return $rut;
    return $rut;
}

// ============================================================
// Logs
// ============================================================
function save_log(string $type, $idx, $data): string {
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
    $ts = date('Ymd_His');
    $file = sprintf('%s/%s_%s_%s.json', LOG_DIR, $type, str_pad((string)$idx, 4, '0', STR_PAD_LEFT), $ts);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    return $file;
}

// ============================================================
// Mapa rut → employee_id (cache local)
// ============================================================
function load_rut_empid_map(): array {
    if (file_exists(RUT_MAP_FILE)) {
        $raw = file_get_contents(RUT_MAP_FILE);
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
    }
    return [];
}

function save_rut_empid_map(array $map): void {
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
    file_put_contents(RUT_MAP_FILE, json_encode($map, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

function map_leader_id_from_row(array $r, array $rutMap): ?int {
    $rutJefe = pick($r, ['Jefe','Rut Jefe','RUT Jefe','rut_jefe']);
    $rutJefeNorm = normalize_rut($rutJefe);
    if (!$rutJefeNorm) return null;
    return isset($rutMap[$rutJefeNorm]) ? (int)$rutMap[$rutJefeNorm] : null;
}

// ============================================================
// Buk API (roles / employees / jobs)
// ============================================================
function buk_api_request(string $method, string $path, ?array $payload = null): array {
    $url  = rtrim(BUK_API_BASE,'/').$path;

    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $jsonErr = json_last_error_msg();
            save_log('json_fail_data', $path.'_'.($payload['rut'] ?? 'no_rut'), $payload);
            return [
                'ok'=>false, 'code'=>0, 'error'=>'JSON_ENCODE_FAIL',
                'body'=>'Error de cURL: Fallo al codificar JSON (Error: ' . $jsonErr . ').',
                'url'=>$url, 'variant'=>'json_fail'
            ];
        }
    }

    $ch  = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'Accept-Language: es',
            'auth_token: '.BUK_TOKEN,
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
    ];

    $method = strtoupper($method);
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $body ?? '{}';
    } elseif ($method === 'PATCH') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'PATCH';
        $opts[CURLOPT_POSTFIELDS] = $body ?? '{}';
    } elseif ($method === 'GET') {
        // nada extra
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
    }

    curl_setopt_array($ch, $opts);

    $respBody = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return [
            'ok'=>false, 'code'=>0,
            'error'=>$err,
            'body'=>'Error de cURL: '.$err,
            'url'=>$url, 'variant'=>'curl_error'
        ];
    }

    $ok = ($code >= 200 && $code < 300);
    return [
        'ok'=>$ok,
        'code'=>$code,
        'error'=>null,
        'body'=>$respBody !== false ? $respBody : '',
        'url'=>$url,
        'variant'=>$ok ? 'ok' : 'http_error',
    ];
}

function get_roles_cache(): array {
    if (file_exists(ROLES_CACHE_FILE)) {
        $raw = file_get_contents(ROLES_CACHE_FILE);
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
    }

    // si no hay cache, lo descargamos (hasta 5 páginas máx)
    $all = [];
    $page = 1;
    $loops = 0;
    $next = BUK_ROLES_PATH.'?page='.$page.'&page_size=1000';
    while ($next && $loops < 5) {
        $res = buk_api_request('GET', $next, null);
        if (!$res['ok']) break;
        $j = json_decode($res['body'], true);
        if (!is_array($j) || !isset($j['data'])) break;
        $all = array_merge($all, (array)$j['data']);
        $next = $j['pagination']['next'] ?? null;
        if ($next) {
            $next = str_replace(BUK_API_BASE, '', $next);
        }
        $loops++;
    }
    file_put_contents(ROLES_CACHE_FILE, json_encode($all, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    return $all;
}

function find_role_id_in_cache(string $cargo): ?int {
    $cargo = trim(mb_strtolower($cargo, 'UTF-8'));
    if ($cargo === '') return null;
    $roles = get_roles_cache();
    foreach ($roles as $r) {
        $name = isset($r['name']) ? mb_strtolower($r['name'], 'UTF-8') : '';
        $code = isset($r['code']) ? mb_strtolower($r['code'], 'UTF-8') : '';
        if ($cargo === $name || $cargo === $code) {
            return (int)$r['id'];
        }
    }
    return null;
}

function slugify_code(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9]+/u', '_', $s);
    $s = trim($s, '_');
    if ($s === '') $s = 'role_'.time();
    if (strlen($s) > 50) $s = substr($s, 0, 50);
    return $s;
}

function ensure_role_exists_in_buk(string $cargo, ?int $areaId): ?int {
    $cargo = trim($cargo);
    if ($cargo === '') return null;

    // 1) ¿Existe ya en cache?
    $found = find_role_id_in_cache($cargo);
    if ($found) return $found;

    // 2) Crear rol en Buk
    $code = slugify_code($cargo);
    $payload = [
        'name'        => $cargo,
        'code'        => $code,
        'description' => '',
        'requirements'=> '',
        'area_ids'    => $areaId ? [$areaId] : [],
        'custom_attributes' => (object)[],
    ];

    $res = buk_api_request('POST', BUK_ROLES_PATH, $payload);
    if (!$res['ok']) {
        // si falla la creación del rol, devolvemos null
        save_log('role_create_error', $code, [
            'cargo'=>$cargo,
            'area_id'=>$areaId,
            'response'=>$res
        ]);
        return null;
    }

    $j = json_decode($res['body'], true);
    $id = $j['data']['id'] ?? null;
    if ($id) {
        // refrescamos cache rápido (añadimos al archivo)
        $roles = get_roles_cache();
        $roles[] = $j['data'];
        file_put_contents(ROLES_CACHE_FILE, json_encode($roles, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        return (int)$id;
    }

    return null;
}

// ============================================================
// Payload EMPLEADO (sin current_job)
// ============================================================
if (!function_exists('build_employee_payload')) {
    function build_employee_payload(array $r): array {
        // ------------ Datos básicos ------------
        $first  = pick($r,['Nombres','Nombre','Primer Nombre']);
        $sur1   = pick($r,['Apaterno','Apellido Paterno','Apellido1']);
        $sur2   = pick($r,['Amaterno','Apellido Materno','Apellido2']);
        $rut    = pick($r,['Rut','RUT','Documento','documento']);
        $email  = pick($r,['Mail','Email','Correo','email']);
        $pemail = pick($r,['personal_email','Email Personal','Correo Personal']);

        // Correo dummy si viene "correo@empresa.cl"
        if ($email !== '' && strtolower($email) === 'correo@empresa.cl') {
            static $usedEmails = [];
            do {
                $rand6 = str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
                $email = "correo{$rand6}@empresa.cl";
            } while (isset($usedEmails[$email]));
            $usedEmails[$email] = true;
        }

        $phone  = pick($r,['Telefono','Teléfono','phone']);
        $mobile = pick($r,['Celular','Mobile','celular']);

        $addr   = trim(pick($r,['calle','Direccion','Dirección','address']));
        $num    = pick($r,['numero','Nro','Numero','Número']);
        if ($num !== '') {
            $addr = trim($addr . ' ' . $num);
        }
        if ($addr === '') {
            $addr = pick($r, ['address','Address','Direccion','Dirección']);
        }

        $office = pick($r,['depto','office_number','Depto','Departamento']);
        $city   = pick($r,['ciudad','city','Ciudad']);
        $district_code = pick($r,['Comuna']);
        $district_name = pick($r,['Dcomuna']);
        $district = $district_name ?: ($district_code ?: null);
        $region_code   = pick($r,['region','Region','Región']);
        $region_name   = pick($r, ['Dregion']);
        $region = $region_name ?: ($region_code ?: null);

        $birthday = to_iso(pick($r,['Fecha Nacimiento','birthday','Fecha de Nacimiento','Fec_Nac']));
        $ing      = to_iso(pick($r,['Fecha de Ingreso','active_since','Fec_Ingreso','start_date']));
        $estado   = pick($r,['Estado','status']);
        $estadoL  = strtolower((string)$estado);
        $status   = in_array($estadoL, ['s','1','activo','a'], true) ? 'activo' : 'inactivo';

        // ⚠️ IMPORTANTE: norm_gender debe devolver "M"/"F"
        $gender   = norm_gender(pick($r,['Sexo','gender','Genero','Género']));

        $code_sheet = pick($r,['Codigo','code_sheet','Ficha','Código Ficha']);

        // ------------ DATOS DE PAGO ------------
        $pmeth_raw   = pick($r,['Descripcion Forma de Pago 1','Forma de Pago 1','payment_method']);
        $pmeth       = norm_payment_method($pmeth_raw);    // mapea a "Transferencia Bancaria", etc.

       $bank_raw    = pick($r,['Descripcion Banco fpago1','Banco Fpago1','bank_name']);
$bank        = norm_bank($bank_raw);  // mapeamos a nombres válidos para Buk

$acct_n      = pick($r,[
    'Cuenta Corriente fpago1','account_number','Cuenta Corriente',
    'Cuenta Interbancaria fpago1','Cuenta Interbancaria',
    'num_cuenta','N° Cuenta','Numero Cuenta','Número Cuenta'
]);

// Tipo de cuenta (CTA CTE, VISTA, AHORRO, etc.)
$acc_type_raw = pick($r, ['Tipo Cuenta fpago1','Tipo de Cuenta','account_type']);
$acc_type     = norm_account_type($acc_type_raw);

$pcur        = 'CLP';

        $pperiod_raw = pick($r,['Regimen de Pago','payment_period','Régimen de Pago','Codigo de Regimen']);
        $pperiod     = norm_payment_period($pperiod_raw);  // mapea a "mensual", etc.

        $adv         = 'sin_anticipo';

        // Si en ADP viene vacío, forzamos un método por defecto aceptado por Buk
        if (!$pmeth) {
            $pmeth = 'Transferencia Bancaria';
        }
        if (!$pperiod) {
            $pperiod = 'mensual';
        }

        // ------------ SUELDOS ------------
        $salary_liq   = money_to_int(pick($r, ['Sueldo Líquido','Sueldo Liquido','Liquido','Sueldo']));
        $salary_base  = money_to_int(pick($r, ['Sueldo Base','Sueldo Contrato','Sueldo Imponible']));
        $salary       = $salary_base ?? $salary_liq;

        $companyId = COMPANY_ID_FOR_JOBS;

        // ====================================================
        //          PAYLOAD PRINCIPAL EMPLEADO
        // ====================================================
        $payload = [
            'first_name'         => $first,
            'last_name'          => trim($sur1.' '.$sur2),
            'rut'                => $rut,
            'email'              => $email ?: $pemail,
            'personal_email'     => $pemail ?: null,
            'phone'              => $phone ?: null,
            'mobile_phone'       => $mobile ?: null,
            'status'             => $status,  // "activo"/"inactivo"
            'gender'             => $gender,  // "M"/"F"
            'birthday'           => $birthday,
            'active_since'       => $ing,
            'address'            => $addr ?: null,
            'office_number'      => $office ?: null,
            'city'               => $city ?: null,
            'district'           => $district ?: null,
            'region'             => $region ?: null,

            // Campos que la API está exigiendo
            'country_code'       => 'CL',
            'location_id'        => DEFAULT_LOCATION_ID,
            'nationality'        => 'Chilena',

            'company_id'         => $companyId,
            'code_sheet'         => $code_sheet ?: null,
        ];

        // --- Campos de pago a nivel RAÍZ (como en el JSON de Buk) ---
       // --- Campos de pago a nivel RAÍZ (como en el JSON de Buk) ---
if ($pmeth) {
    $payload['payment_method'] = $pmeth;        // "Transferencia Bancaria"
}
if ($pperiod) {
    $payload['payment_period'] = $pperiod;      // "mensual"
}
$payload['advance_payment'] = $adv;            // "sin_anticipo"

// Banco: solo si lo pudimos mapear; si no, lo omitimos
if ($bank) {
    $payload['bank'] = $bank;                  // "Banco de Chile", "Santander", etc.
}

if ($acct_n) {
    $payload['account_number'] = $acct_n;
}

// Tipo de cuenta ES OBLIGATORIO para Buk
if (!$acc_type) {
    // default razonable si no lo reconoce ADP
    $acc_type = 'Vista';
}
$payload['account_type'] = $acc_type;

$payload['payment_currency'] = $pcur;          // "CLP"

        // --- Sueldo opcional dentro del empleado (Buk lo acepta) ---
        if ($salary !== null) {
            $payload['salary'] = [
                'base_salary' => $salary,
                'currency'    => 'CLP',
                'period'      => $pperiod ?: 'mensual',
            ];
        }

        return $payload;
    }
}
// ============================================================
// Payload JOB
// ============================================================
function build_job_payload(array $r, int $employeeId, ?int $leaderId): ?array {
    $areaId = map_area_id_from_row($r);
    $roleId = map_role_id_from_row($r, $areaId);

    if (!$areaId || !$roleId || !$leaderId) {
        // Si falta algo crítico, no enviamos job
        return null;
    }

    $start_date = to_iso(pick($r, ['Fecha de Ingreso','start_date'])) ?? date('Y-m-d');
    $end_date   = to_iso(pick($r, ['Fecha de Retiro','end_date']));
    $salary_liq   = money_to_int(pick($r, ['Sueldo Líquido','Sueldo Liquido','Liquido','Sueldo']));
    $salary_gross = money_to_int(pick($r, ['Sueldo Bruto','Bruto','Renta Bruta','Renta']));
    $wage = $salary_gross ?: $salary_liq ?: 0;

    $pperiod = norm_payment_period(pick($r,['Regimen de Pago','payment_period','Régimen de Pago','Codigo de Regimen'])) ?? 'mensual';

    $job = [
        'company_id'   => COMPANY_ID_FOR_JOBS,
        'location_id'  => DEFAULT_LOCATION_ID,
        'employee_id'  => $employeeId,
        'area_id'      => $areaId,
        'role_id'      => $roleId,
        'leader_id'    => $leaderId,
        'start_date'   => $start_date,
        'end_date'     => $end_date,
        'salary'       => [
            'base_salary' => $wage,
            'currency'    => 'CLP',
            'period'      => $pperiod,
        ],
    ];

    return $job;
}

// ============================================================
// Helpers para parsear respuesta Buk
// ============================================================
function parse_msg(string $body): string {
    $t = trim($body);
    if (substr($t, 0, 15) === 'Error de cURL:') { return $t; }
    $j = json_decode($body, true);
    if (is_array($j)) {
        if (isset($j['errors'])) { return json_encode($j['errors'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); }
        if (isset($j['message'])) return (string)$j['message'];
        if (isset($j['error'])) { return is_string($j['error']) ? $j['error'] : json_encode($j['error'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); }
    }
    return (strlen($body) > 5000) ? substr($body, 0, 5000) : $body;
}

// ============================================================
// Carga de empleados desde la BD (tabla adp_empleados)
// ============================================================
function load_empleados_from_db(clsConexion $db): array {
    // Traemos sólo registros no enviados a Buk; ajusta el filtro si lo necesitas.
    $sql = "SELECT * FROM adp_empleados WHERE estado_buk = 'no_enviado'";
    $rows = $db->consultar($sql);
    if (!is_array($rows)) {
        return [];
    }
    return $rows;
}

// ===== Controller =====
$alert = null;
$rows  = load_empleados_from_db($db);
$last_result = null;
$bulk_result = null;
$result_log  = $_SESSION['result_log'] ?? [];

// Parámetros de paginación
$perPage = isset($_REQUEST['per_page']) ? (int)$_REQUEST['per_page'] : 30;
if(!in_array($perPage, PAGE_SIZES, true)) $perPage = 30;
$page    = isset($_REQUEST['p']) ? max(1, (int)$_REQUEST['p']) : 1;

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action = $_POST['action'] ?? '';

    if ($action==='clear_log') {
        unset($_SESSION['result_log']);
        $result_log = [];
    }

    // (acción upload queda inactiva; los datos vienen ahora desde BD)
    if($action==='upload' && isset($_FILES['archivo'])){
        $alert = ['type'=>'error','msg'=>'Esta pantalla ahora toma los datos desde la BD (tabla adp_empleados). Usa la importación ADP para cargar la información.'];
    }

    // === Enviar un registro ===
    if($action==='send_one' && isset($_POST['idx'])){
        $idx=(int)$_POST['idx'];
        $rows = load_empleados_from_db($db);
        if(isset($rows[$idx])){
            $row = $rows[$idx];
            $emp = build_employee_payload($row);
            $payloadFile = save_log('payload_emp',$idx,$emp);
            $resEmp = buk_api_request('POST', BUK_EMP_CREATE_PATH, $emp);
            $respEmpFile = save_log('response_emp',$idx,[
                'http_code'=>$resEmp['code'], 'variant'=>$resEmp['variant'], 'url'=>$resEmp['url'], 'body'=>$resEmp['body'], 'curl_error' => $resEmp['error']
            ]);

            $rutMap = load_rut_empid_map();
            $jobRes = null;
            $jobStatus = 'NO_JOB';

            if ($resEmp['ok']) {
                $empJson = json_decode($resEmp['body'], true);
                $empId = $empJson['data']['id'] ?? null;
                $rutEmp = normalize_rut($empJson['data']['rut'] ?? pick($row, ['Rut','RUT','Documento','documento']));
                if ($rutEmp && $empId) {
                    $rutMap[$rutEmp] = (int)$empId;
                    save_rut_empid_map($rutMap);
                }

                if ($empId) {
                    $leaderId = map_leader_id_from_row($row, $rutMap);
                    $jobPayload = build_job_payload($row, $empId, $leaderId);

                    if ($jobPayload) {
                        $payloadJobFile = save_log('payload_job',$idx,$jobPayload);
                        $jobRes = buk_api_request('POST', sprintf(BUK_JOB_CREATE_PATH, $empId), $jobPayload);
                        $respJobFile = save_log('response_job',$idx,[
                            'http_code'=>$jobRes['code'], 'variant'=>$jobRes['variant'], 'url'=>$jobRes['url'], 'body'=>$jobRes['body'], 'curl_error' => $jobRes['error']
                        ]);
                        $jobStatus = $jobRes['ok'] ? 'JOB_OK' : 'JOB_ERROR';
                    } else {
                        $jobStatus = 'JOB_SKIP';
                    }
                }
            }

            $empPayloadJson = json_encode($emp, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
            if ($empPayloadJson === false) $empPayloadJson = "Error: No se pudo generar el JSON de empleado.";

            $msgEmp = parse_msg($resEmp['body']).' ['.$resEmp['variant'].']';
            $msgJob = '';
            if ($jobStatus === 'JOB_OK') {
                $msgJob = ' | JOB: OK';
            } elseif ($jobStatus === 'JOB_ERROR') {
                $msgJob = ' | JOB: ERROR '.parse_msg($jobRes['body']).' ['.$jobRes['variant'].']';
            } elseif ($jobStatus === 'JOB_SKIP') {
                $msgJob = ' | JOB: NO_ENVIADO (falta área/cargo/supervisor)';
            }

            $title = $resEmp['ok'] ? 'Enviado a BUK (EMP OK)' : 'Fallo envío EMP';
            if ($resEmp['variant'] === 'json_fail' || $resEmp['variant'] === 'curl_error') { $title = 'Error Crítico'; }

            $last_result = [
                'ok'=>$resEmp['ok'] && ($jobStatus === 'JOB_OK' || $jobStatus === 'NO_JOB'),
                'code'=>$resEmp['code'],
                'title'=> $title,
                'msg'=> $msgEmp.$msgJob,
                'payload'=> $empPayloadJson,
                'response'=>$resEmp['body'],
                'payload_file'=>$payloadFile,
                'response_file'=>$respEmpFile,
            ];

            $nombreRow = trim((pick($row, ['Nombres','Nombre']).' '.pick($row, ['Apaterno']).' '.pick($row, ['Amaterno'])));
            if (!$resEmp['ok']) {
                $statusTxt = 'EMP_ERROR';
            } else {
                if ($jobStatus === 'JOB_OK' || $jobStatus === 'NO_JOB') {
                    $statusTxt = 'AGREGADO';
                } elseif ($jobStatus === 'JOB_ERROR') {
                    $statusTxt = 'EMP_OK_JOB_ERROR';
                } else {
                    $statusTxt = 'EMP_OK_JOB_SKIP';
                }
            }

            // Marcar en BD como enviado si EMP y JOB están OK (o no se creó JOB)
            if ($resEmp['ok'] && ($jobStatus === 'JOB_OK' || $jobStatus === 'NO_JOB')) {
                if (!empty($row['Codigo'])) {
                    $codigoEsc = $db->real_escape_string($row['Codigo']);
                    $db->ejecutar("UPDATE adp_empleados SET estado_buk='completo' WHERE Codigo = '{$codigoEsc}'");
                }
            }

            $result_log[] = [
                'idx'    => $idx,
                'name'   => $nombreRow !== '' ? $nombreRow : (pick($row, ['full_name']) ?: '-'),
                'status' => $statusTxt,
                'http'   => (int)$resEmp['code'],
                'msg'    => $msgEmp.$msgJob,
                'ts'     => date('Y-m-d H:i:s'),
            ];
            $_SESSION['result_log'] = $result_log;
        } else {
            $last_result = ['ok'=>false,'code'=>0,'title'=>'Error Interno','msg'=>'Índice de fila inválido.'];
        }
    }

    // === Envío masivo ===
    if($action==='send_bulk' && !empty($_POST['idx'])){
        $indices = array_map('intval', (array)$_POST['idx']);
        $rows    = load_empleados_from_db($db);
        $summary = ['total' => count($indices), 'ok' => 0, 'fail' => 0, 'items' => []];

        $rutMap = load_rut_empid_map();

        foreach($indices as $idx){
            if(!isset($rows[$idx])){ continue; }
            $row = $rows[$idx];
            $emp = build_employee_payload($row);
            $payloadFile = save_log('payload_emp',$idx,$emp);
            $resEmp = buk_api_request('POST', BUK_EMP_CREATE_PATH, $emp);
            $respEmpFile = save_log('response_emp',$idx,[
                'http_code'=>$resEmp['code'], 'variant'=>$resEmp['variant'], 'url'=>$resEmp['url'], 'body'=>$resEmp['body'], 'curl_error' => $resEmp['error']
            ]);

            $jobRes = null;
            $jobStatus = 'NO_JOB';

            if ($resEmp['ok']) {
                $empJson = json_decode($resEmp['body'], true);
                $empId = $empJson['data']['id'] ?? null;
                $rutEmp = normalize_rut($empJson['data']['rut'] ?? pick($row, ['Rut','RUT','Documento','documento']));
                if ($rutEmp && $empId) {
                    $rutMap[$rutEmp] = (int)$empId;
                }

                if ($empId) {
                    $leaderId = map_leader_id_from_row($row, $rutMap);
                    $jobPayload = build_job_payload($row, $empId, $leaderId);

                    if ($jobPayload) {
                        $payloadJobFile = save_log('payload_job',$idx,$jobPayload);
                        $jobRes = buk_api_request('POST', sprintf(BAK_JOB_CREATE_PATH ?? BUK_JOB_CREATE_PATH, $empId), $jobPayload);
                        $respJobFile = save_log('response_job',$idx,[
                            'http_code'=>$jobRes['code'], 'variant'=>$jobRes['variant'], 'url'=>$jobRes['url'], 'body'=>$jobRes['body'], 'curl_error' => $jobRes['error']
                        ]);
                        $jobStatus = $jobRes['ok'] ? 'JOB_OK' : 'JOB_ERROR';
                    } else {
                        $jobStatus = 'JOB_SKIP';
                    }
                }
            }

            $okForSummary = $resEmp['ok'] && ($jobStatus === 'JOB_OK' || $jobStatus === 'NO_JOB');
            $summary[$okForSummary?'ok':'fail']++;

            $msgEmp = parse_msg($resEmp['body']).' ['.$resEmp['variant'].']';
            $msgJob = '';
            if ($jobStatus === 'JOB_OK') {
                $msgJob = ' | JOB: OK';
            } elseif ($jobStatus === 'JOB_ERROR') {
                $msgJob = ' | JOB: ERROR '.parse_msg($jobRes['body']).' ['.$jobRes['variant'].']';
            } elseif ($jobStatus === 'JOB_SKIP') {
                $msgJob = ' | JOB: NO_ENVIADO (falta área/cargo/supervisor)';
            }

            $summary['items'][] = [
                'idx'=>$idx,
                'http_code'=>$resEmp['code'],
                'ok'=>$okForSummary,
                'msg'=>$msgEmp.$msgJob,
                'payload_file'=>basename($payloadFile),
                'response_file'=>basename($respEmpFile),
            ];

            $nombreRow = trim((pick($row, ['Nombres','Nombre']).' '.pick($row, ['Apaterno']).' '.pick($row, ['Amaterno'])));
            if (!$resEmp['ok']) {
                $statusTxt = 'EMP_ERROR';
            } else {
                if ($jobStatus === 'JOB_OK' || $jobStatus === 'NO_JOB') {
                    $statusTxt = 'AGREGADO';
                } elseif ($jobStatus === 'JOB_ERROR') {
                    $statusTxt = 'EMP_OK_JOB_ERROR';
                } else {
                    $statusTxt = 'EMP_OK_JOB_SKIP';
                }
            }

            // Marcar en BD como enviado si EMP y JOB están OK (o no se creó JOB)
            if ($resEmp['ok'] && ($jobStatus === 'JOB_OK' || $jobStatus === 'NO_JOB')) {
                if (!empty($row['Codigo'])) {
                    $codigoEsc = $db->real_escape_string($row['Codigo']);
                    $db->ejecutar("UPDATE adp_empleados SET estado_buk='completo' WHERE Codigo = '{$codigoEsc}'");
                }
            }

            $result_log[] = [
                'idx'    => $idx,
                'name'   => $nombreRow !== '' ? $nombreRow : (pick($row, ['full_name']) ?: '-'),
                'status' => $statusTxt,
                'http'   => (int)$resEmp['code'],
                'msg'    => $msgEmp.$msgJob,
                'ts'     => date('Y-m-d H:i:s'),
            ];
        }

        // Guardar mapa rut->employee_id actualizado
        save_rut_empid_map($rutMap);

        $_SESSION['result_log'] = $result_log;
        $bulk_result = $summary;
    }
}

// Cálculo de paginación
$total = is_array($rows) ? count($rows) : 0;
$pages = $total>0 ? (int)ceil($total / $perPage) : 1;
if($page > $pages) $page = $pages;
$offset = ($page-1)*$perPage;
$pageRows = $total>0 ? array_slice($rows, $offset, $perPage, true) : [];

?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="bg-gray-50">
    <div class="min-h-screen grid grid-cols-12">
        <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
            <?php $active='empleados'; include __DIR__ . '/../partials/sidebar.php'; ?>
        </div>

        <div class="col-span-12 md:col-span-9 lg:col-span-10">
            <?php include __DIR__ . '/../partials/topbar.php'; ?>

            <main class="max-w-7xl mx-auto p-6 space-y-6">
                <section class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h1 class="text-xl font-semibold">Empleados — Envío a Buk (BD)</h1>
                        <div class="text-xs text-gray-500">ADP → Buk · EMP + JOB (jerarquías)</div>
                    </div>

                    <?php if($last_result): ?>
                        <div class="<?= $last_result['ok'] ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?> border rounded-2xl px-4 py-3 text-sm space-y-1">
                            <div class="font-semibold"><?= e($last_result['title']) ?></div>
                            <div><?= nl2br(e($last_result['msg'])) ?></div>
                            <?php if(!empty($last_result['code'])): ?>
                                <div class="text-xs text-gray-500">HTTP: <?= (int)$last_result['code'] ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if($bulk_result): ?>
                        <div class="bg-blue-50 text-blue-700 border border-blue-200 rounded-2xl px-4 py-3 text-sm space-y-1">
                            <div class="font-semibold">Resultado envío masivo</div>
                            <div>Total: <?= (int)$bulk_result['total'] ?> · OK: <?= (int)$bulk_result['ok'] ?> · Error: <?= (int)$bulk_result['fail'] ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if($alert): ?>
                        <div class="<?= $alert['type']==='success'?'bg-emerald-50 text-emerald-700 border-emerald-200':($alert['type']==='error'?'bg-rose-50 text-rose-700 border-rose-200':'bg-amber-50 text-amber-700 border-amber-200') ?> border rounded-2xl px-4 py-3 text-sm">
                            <?= e($alert['msg']) ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="bg-white border rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b flex items-center justify-between">
                        <div class="font-semibold">Previsualización</div>
                        <div class="text-sm text-gray-500">Página <?= $page ?> de <?= $pages ?> (<?= $perPage ?> por página)</div>
                    </div>

                    <form method="post" id="bulkForm">
                        <input type="hidden" name="action" value="send_bulk">
                        <input type="hidden" name="per_page" value="<?= $perPage ?>">
                        <input type="hidden" name="p" value="<?= $page ?>">

                        <div class="px-5 py-3 border-b bg-gray-50/60">
                            <div class="sticky top-[72px] z-10">
                                <div class="bg-white/90 backdrop-blur border rounded-2xl p-3 shadow-sm">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <div class="text-xs text-gray-500">Origen datos: tabla <code>adp_empleados</code> (BD)</div>

                                        <div class="text-sm text-gray-500">Total: <?= number_format($total) ?> registros</div>

                                        <form method="get" class="ml-auto flex items-center gap-2">
                                            <input type="hidden" name="p" value="1">
                                            <label class="text-sm text-gray-600">Por página</label>
                                            <select name="per_page" class="px-2 py-1.5 border rounded-lg text-sm" onchange="this.form.submit()">
                                                <?php foreach(PAGE_SIZES as $s): ?>
                                                    <option value="<?= $s ?>" <?= $perPage===$s?'selected':'' ?>><?= $s ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="px-2.5 py-1.5 border rounded-lg text-xs text-gray-600 hover:bg-gray-50" onclick="location.href=location.pathname">Reiniciar</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="text-left px-5 py-3"><input type="checkbox" id="checkAll"></th>
                                        <th class="text-left px-5 py-3">#</th>
                                        <th class="text-left px-5 py-3">RUT</th>
                                        <th class="text-left px-5 py-3">Nombre</th>
                                        <th class="text-left px-5 py-3">Gender</th>
                                        <th class="text-left px-5 py-3">Birthday</th>
                                        <th class="text-left px-5 py-3">Start Date</th>
                                        <th class="text-left px-5 py-3">Address</th>
                                        <th class="text-left px-5 py-3">Sueldo (líquido)</th>
                                        <th class="text-right px-5 py-3">Acción</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php if(is_array($pageRows) && count($pageRows)>0): ?>
                                        <?php foreach($pageRows as $origIdx=>$r):
                                            $rut    = pick($r, ['Rut','RUT','Documento','documento']);
                                            $nombre = trim(pick($r, ['Nombres','Nombre']).' '.pick($r, ['Apaterno']).' '.pick($r, ['Amaterno']));
                                            $gender = pick($r, ['Sexo','gender']);
                                            $fnac   = pick($r, ['Fecha Nacimiento','birthday']);
                                            $rawSueldo  = pick($r, [
                                                'Sueldo Líquido','Sueldo Liquido','Sueldo_Líquido','Sueldo_Liquido',
                                                'Liquido','Líquido','Sueldo','Sueldo Base','Sueldo Contrato','Sueldo Neto'
                                            ]);
                                            $sueldoPrev = money_to_int($rawSueldo);

                                            $fing   = pick($r, ['Fecha de Ingreso','Fec_Ingreso','active_since']);
                                            $addr   = trim(pick($r, ['Direccion','Dirección','address']).' '.pick($r,['numero','Nro','Numero','Número']));
                                        ?>
                                            <tr class="hover:bg-gray-50/80">
                                                <td class="px-5 py-2 align-top">
                                                    <input type="checkbox" name="idx[]" value="<?= $origIdx ?>" class="rowCheck">
                                                </td>
                                                <td class="px-5 py-2 align-top text-xs text-gray-500"><?= $origIdx+1+$offset ?></td>
                                                <td class="px-5 py-2 align-top font-mono text-xs"><?= e($rut) ?></td>
                                                <td class="px-5 py-2 align-top">
                                                    <div class="font-medium"><?= e($nombre) ?></div>
                                                </td>
                                                <td class="px-5 py-2 align-top text-xs text-gray-600"><?= e($gender) ?></td>
                                                <td class="px-5 py-2 align-top text-xs text-gray-600"><?= e($fnac) ?></td>
                                                <td class="px-5 py-2 align-top text-xs text-gray-600"><?= e($fing) ?></td>
                                                <td class="px-5 py-2 align-top text-xs text-gray-600"><?= e($addr) ?></td>
                                                <td class="px-5 py-2 align-top text-xs text-gray-900">
                                                    <?= $sueldoPrev !== null ? '$'.number_format($sueldoPrev,0,',','.') : '-' ?>
                                                </td>
                                                <td class="px-5 py-2 align-top text-right">
                                                    <form method="post" class="inline">
                                                        <input type="hidden" name="action" value="send_one">
                                                        <input type="hidden" name="idx" value="<?= $origIdx ?>">
                                                        <input type="hidden" name="per_page" value="<?= $perPage ?>">
                                                        <input type="hidden" name="p" value="<?= $page ?>">
                                                        <button type="submit" class="inline-flex items-center px-2.5 py-1.5 rounded-lg bg-blue-600 text-white text-xs hover:bg-blue-700">
                                                            <i class="fa-solid fa-paper-plane mr-1"></i> Enviar
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="px-5 py-6 text-center text-sm text-gray-500">
                                                No hay registros pendientes de envío (estado_buk = 'no_enviado').
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="px-5 py-3 border-t flex items-center justify-between bg-gray-50/80">
                            <div class="flex items-center gap-2">
                                <button type="submit" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs hover:bg-emerald-700">
                                    <i class="fa-solid fa-paper-plane mr-1"></i> Enviar seleccionados a Buk
                                </button>
                            </div>
                            <div class="text-xs text-gray-500">
                                Página <?= $page ?> de <?= $pages ?> · Total: <?= number_format($total) ?> registros
                            </div>
                        </div>
                    </form>
                </section>

                <section class="bg-white border rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b flex items-center justify-between">
                        <div class="font-semibold">Historial de resultados</div>
                        <form method="post">
                            <input type="hidden" name="action" value="clear_log">
                            <button type="submit" class="text-xs px-2 py-1.5 border rounded-lg hover:bg-gray-50">
                                Limpiar log
                            </button>
                        </form>
                    </div>
                    <div class="overflow-x-auto max-h-80">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="px-3 py-2 text-left">#</th>
                                    <th class="px-3 py-2 text-left">Fecha</th>
                                    <th class="px-3 py-2 text-left">Nombre</th>
                                    <th class="px-3 py-2 text-left">Estado</th>
                                    <th class="px-3 py-2 text-left">HTTP</th>
                                    <th class="px-3 py-2 text-left">Mensaje</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if(!empty($result_log)): ?>
                                    <?php foreach(array_reverse($result_log) as $i=>$log): ?>
                                        <tr>
                                            <td class="px-3 py-2"><?= $i+1 ?></td>
                                            <td class="px-3 py-2"><?= e($log['ts'] ?? '-') ?></td>
                                            <td class="px-3 py-2"><?= e($log['name'] ?? '-') ?></td>
                                            <td class="px-3 py-2"><?= e($log['status'] ?? '-') ?></td>
                                            <td class="px-3 py-2"><?= (int)($log['http'] ?? 0) ?></td>
                                            <td class="px-3 py-2 whitespace-pre-line"><?= e($log['msg'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-3 py-4 text-center text-gray-500">
                                            No hay resultados registrados aún.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <script>
        const checkAll = document.getElementById('checkAll');
        if (checkAll) {
            checkAll.addEventListener('change', function () {
                document.querySelectorAll('.rowCheck').forEach(cb => cb.checked = checkAll.checked);
            });
        }
    </script>
</body>
</html>
<?php
ob_end_flush();
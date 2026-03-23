<?php
// enviar_buk.php — Carga empleados ADP (BD) → Buk con NUEVA LÓGICA POR PASOS:
// PASO 1: Crear Colaborador (EMP)
// PASO 2: Crear Plan (PLAN)
// PASO 3: Crear Trabajo (JOB)
// - NO pierde payloads: guarda payload + respuesta + archivos log por cada paso
// - Mantiene diseño actual, paginación, historial y checks de mapeo área/cargo
// - Mapeos REALES DESDE BD:
//    * Área: stisoft_mapeo_areas (unidad_adp -> buk_area_id) usando adp_empleados.Unidad
//    * Cargo: stisoft_mapeo_cargos (cargo_adp_id -> buk_role_id) usando adp_empleados.Cargo
// - NO inventa datos: si no hay mapping mapeado => JOB_SKIP (no se envía JOB)
// - Jefe por defecto SIEMPRE: DEFAULT_LEADER_ID

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);



ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_auth();
$user = current_user();
require_once __DIR__ . '/../conexion/db.php';
$db = new clsConexion();

if (session_status() === PHP_SESSION_NONE) session_start();
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
const BUK_JOB_CREATE_PATH  = '/employees/%d/jobs';     // POST
// ⚠️ AJUSTA si tu endpoint de plan difiere:
const BUK_PLAN_CREATE_PATH = '/employees/%d/plans';    // POST  (PlanInputCountry)

const BUK_ROLES_PATH       = '/roles';
const BUK_TOKEN            = 'KbAVH6fNSraVT17MBv1ECPrfW'; // ⚠️ mover a .env en prod

const COMPANY_ID_FOR_JOBS  = 1;
const DEFAULT_LOCATION_ID  = 407;
const DEFAULT_LEADER_ID    = 313;

const LOG_DIR              = __DIR__ . '/logs_buk';
const RUT_MAP_FILE         = LOG_DIR . '/rut_to_empid.json';
const STEP_STATE_FILE      = LOG_DIR . '/step_state.json'; // 🔥 estado persistente (no solo sesión)

const PAGE_SIZES           = [10,30,50,100];
// =========================================

// Reinicia dataset al entrar por GET sin parámetros
if ($_SERVER['REQUEST_METHOD'] === 'GET' && count($_GET) === 0) {
    unset($_SESSION['rows'], $_SESSION['headers']);
}

if(!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// =============================================================
// Helpers genéricos
// =============================================================
function pick(array $row, array $cands): string {
    foreach ($cands as $c) {
        if (array_key_exists($c, $row) && $row[$c] !== '' && $row[$c] !== null) {
            return (string)$row[$c];
        }
    }
    return '';
}



function norm_txt(?string $s): string {
  $s = trim((string)$s);
  $s = mb_strtoupper($s, 'UTF-8');
  $s = preg_replace('/\s+/', ' ', $s);
  return $s ?: '';
}

function map_type_of_contract(array $r): ?string {
    $raw = norm_txt(pick($r, ['Descripcion Situacion Laboral', 'Descripción Situación Laboral']));
    if ($raw === '' || $raw === 'NULL') {
        // Opción A (recomendada para no perder gente): default
       // return 'Indefinido';

        // Opción B (estricta): no enviar Job si viene NULL
         return null;
    }

    if (str_contains($raw, 'CONTRATO INDEFINIDO')) return 'Indefinido';
    if (str_contains($raw, 'CONTRATO A PLAZO FIJO')) return 'Plazo fijo';
    if (str_contains($raw, 'EVENTUAL CON CPPT')) return 'Obra';

    return null;
}


function map_end_of_contract(array $r): ?string {
  return to_iso(pick($r, [
    'Termino Contrato','Término Contrato','Fecha Termino Contrato','Fecha Término Contrato',
    'Fecha de Retiro','end_date'
  ]));
}

function map_regular_hours(array $r): ?float {
  $raw = trim(pick($r, ['Horas Semanales','Horario Semanal','Horas Jornada','Horas Contrato','Jornada']));
  if ($raw==='') return null;
  $raw = str_replace(',', '.', $raw);
  if (!is_numeric($raw)) return null;
  $h = (float)$raw;
  if ($h <= 0 || $h > 80) return null;
  return $h;
}

function map_type_of_working_day(array $r): ?string {
    $raw = norm_txt(pick($r, ['Descripcion Horario', 'Descripción Horario']));
    if ($raw === '' || $raw === 'NULL') return null;

    if (str_contains($raw, 'TURNOS ROTATIVOS')) return 'otros';
    if (str_contains($raw, 'ART.22') || str_contains($raw, 'ART 22') || str_contains($raw, 'ART. 22')) return 'exenta_art_22';
    if (str_contains($raw, 'ADMINISTRATIVO')) return 'ordinaria_art_22';

    return null;
}

function map_other_type_of_working_day(array $r): ?string {
    $raw = norm_txt(pick($r, ['Descripcion Horario', 'Descripción Horario']));
    if ($raw === '' || $raw === 'NULL') return null;

    if (str_contains($raw, 'TURNOS ROTATIVOS')) return 'especial_art_38_inc_6';
    return null;
}




function to_iso(?string $s): ?string {
    if($s===null) return null;
    $s=trim($s);
    if($s==='') return null;

    $s = str_replace(['.','/'], '-', $s);
    $s = preg_replace('/\s+/', ' ', $s);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

    $fmts = ['d-m-Y','d-m-y','Y-m-d','Y/m/d','d/m/Y','d/m/y'];
    foreach($fmts as $fmt){
        $dt = DateTime::createFromFormat($fmt, $s);
        if($dt && $dt->format($fmt)===$s) {
             $year = (int)$dt->format('Y');
             if ($year > 1900 && $year < 2100) return $dt->format('Y-m-d');
        }
    }

    $s2 = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?(\.\d+)?$/','',$s);
    if ($s2 !== null && $s2 !== $s) return to_iso($s2);

    $ts = strtotime($s);
    if ($ts !== false) {
        $year = (int)date('Y', $ts);
        if ($year > 1900 && $year < 2100) return date('Y-m-d', $ts);
    }
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

function norm_gender(?string $g): ?string {
    $g = mb_strtolower(trim((string)$g), 'UTF-8');
    if ($g === '' || $g === '0') return null;
    if (in_array($g, ['m','masculino','hombre'], true)) return 'M';
    if (in_array($g, ['f','femenino','mujer'], true)) return 'F';
    return null;
}

function norm_payment_method(?string $m): ?string {
    $m = mb_strtolower(trim((string)$m), 'UTF-8');
    if ($m === '') return null;

    if (str_contains($m, 'transfer') || str_contains($m, 'dep') || str_contains($m, 'cta') || str_contains($m, 'banc')) {
        return 'Transferencia Bancaria';
    }
    if (str_contains($m, 'cheque')) return 'Cheque';
    if (str_contains($m, 'efectivo') || str_contains($m, 'cash')) return 'Efectivo';

    return null;
}

function norm_account_type(?string $t): ?string {
    $t = mb_strtolower(trim((string)$t), 'UTF-8');
    if ($t === '') return null;
    if (str_contains($t, 'vista') || str_contains($t, 'rut')) return 'Vista';
    if (str_contains($t, 'corriente') || str_contains($t, 'cte')) return 'Corriente';
    if (str_contains($t, 'ahorro')) return 'Ahorro';
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

/**
 * Normaliza bancos ADP → EXACTO string permitido por BUK.
 * (Dejo tu lógica tal cual)
 */
function norm_bank(?string $raw): ?string {
    if ($raw === null) return null;
    $t = trim($raw);
    if ($t === '') return null;
    if (ctype_digit($t)) return null;

    $u = mb_strtoupper($t, 'UTF-8');
    if (str_contains($u, 'SIN BANCO')) return null;

    if (str_contains($u, 'A. EDWARDS') || str_contains($u, 'EDWARDS')) return 'Banco de Chile';

    if (str_contains($u, 'BBVA')) return 'BBVA';
    if (str_contains($u, 'BCI')) return 'BCI';
    if (str_contains($u, 'BICE')) return 'BICE';
    if (str_contains($u, 'CONSORCIO')) return 'Consorcio';
    if (str_contains($u, 'COOPEUCH')) return 'COOPEUCH';
    if (str_contains($u, 'CORPBANCA')) return 'Corpbanca';
    if (str_contains($u, 'SANTANDER')) return 'Santander';
    if (str_contains($u, 'SCOTIABANK')) return 'Scotiabank';
    if (str_contains($u, 'SECURITY')) return 'Security';
    if (str_contains($u, 'FALABELLA')) return 'Falabella';
    if (str_contains($u, 'RIPLEY')) return 'Ripley';
    if (str_contains($u, 'ITAU') || str_contains($u, 'ITAÚ')) return 'Itau';

    if ((str_contains($u, 'MERCADO') && str_contains($u, 'PAGO')) || str_contains($u, 'MERCADOPAGO')) {
        return 'Mercadopago Emisora S.A.';
    }

    if (str_contains($u, 'ESTADO')) return 'Banco Estado';
    if (str_contains($u, 'BANCO DE CHILE') || $u === 'CHILE' || str_contains($u, ' CHILE')) return 'Banco de Chile';

    if (str_contains($u, 'HSBC')) return 'HSBC';
    if (str_contains($u, 'DEUTSCHE')) return 'Banco Deutsche';
    if (str_contains($u, 'JP MORGAN')) return 'JP Morgan Chase Bank';

    return null;
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
// Estado por pasos (persistente)
// ============================================================
function step_key_from_row(array $r): string {
    $codigo = trim(pick($r, ['Codigo','Código','code_sheet']));
    $rut    = trim(pick($r, ['Rut','RUT','Documento','documento']));
    $unidad = trim(pick($r, ['Unidad','unidad']));
    // Key estable: si hay Codigo úsalo; si no, RUT+Unidad
    if ($codigo !== '') return 'COD:' . $codigo;
    if ($rut !== '') return 'RUT:' . $rut . '|U:' . $unidad;
    // Fallback
    return 'ROW:' . md5(json_encode($r));
}

function load_step_state(): array {
    if (file_exists(STEP_STATE_FILE)) {
        $raw = file_get_contents(STEP_STATE_FILE);
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
    }
    return [];
}

function save_step_state(array $state): void {
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
    file_put_contents(STEP_STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

function init_step_state_if_missing(array &$state, string $key): void {
    if (!isset($state[$key]) || !is_array($state[$key])) {
        $state[$key] = [
            'emp'  => ['ok'=>false,'id'=>null,'http'=>null,'msg'=>null,'payload_file'=>null,'resp_file'=>null,'ts'=>null],
            'plan' => ['ok'=>false,'id'=>null,'http'=>null,'msg'=>null,'payload_file'=>null,'resp_file'=>null,'ts'=>null],
            'job'  => ['ok'=>false,'id'=>null,'http'=>null,'msg'=>null,'payload_file'=>null,'resp_file'=>null,'ts'=>null,'skip'=>false,'skip_reason'=>null],
        ];
    }
}

// ============================================================
// Mapa rut → employee_id (cache local)
// ============================================================
function normalize_rut(?string $rut): ?string {
    if($rut===null) return null;
    $rut = preg_replace('/[^0-9kK]/','',$rut);
    if($rut==='') return null;
    $rut = strtolower($rut);
    if(!preg_match('/^\d{6,9}[0-9k]$/',$rut)) return $rut;
    return $rut;
}

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

function map_leader_id_from_row(array $r, array $rutMap): int {
    $rutJefe = pick($r, ['Jefe','Rut Jefe','RUT Jefe','rut_jefe']);
    $rutJefeNorm = normalize_rut($rutJefe);
    if ($rutJefeNorm && isset($rutMap[$rutJefeNorm])) return (int)$rutMap[$rutJefeNorm];
    return DEFAULT_LEADER_ID;
}

// ============================================================
// 🔥 MAPEOS DESDE BD (SIN MAPAS ESTÁTICOS)
// ============================================================
function table_has_column(clsConexion $db, string $table, string $col): bool {
    $tableEsc = $db->real_escape_string($table);
    $res = $db->consultar("SHOW COLUMNS FROM `$tableEsc` LIKE '".$db->real_escape_string($col)."'");
    return !empty($res);
}

/**
 * ✅ NUEVA LÓGICA:
 * areaMap[UnidadADP] = buk_area_id (pero tomado desde buk_jerarquia NIVEL 3)
 */
function load_area_map_from_db(clsConexion $db): array {
    $table = 'buk_jerarquia';

    // Detectar nombres de columnas “clave”
    $colNivel = table_has_column($db, $table, 'nivel') ? 'nivel' :
                (table_has_column($db, $table, 'profundidad') ? 'profundidad' : null);

    // Dónde está el código ADP de unidad
    // (ideal: codigo_adp; si tu tabla se llama distinto, agrega aquí el nombre real)
    $colCodigo = table_has_column($db, $table, 'codigo_adp') ? 'codigo_adp' :
                 (table_has_column($db, $table, 'unidad_adp') ? 'unidad_adp' : null);

    // Estado mapeado (si existe)
    $colEstado = table_has_column($db, $table, 'estado') ? 'estado' : null;

    // ID buk del área
    $colBukId = table_has_column($db, $table, 'buk_area_id') ? 'buk_area_id' :
                (table_has_column($db, $table, 'id_buk_area') ? 'id_buk_area' : null);

    if (!$colNivel || !$colCodigo || !$colBukId) {
        // Si cae acá, tu tabla no tiene esos nombres → dime columnas reales y lo adapto.
        return [];
    }

    // Nivel 3: si usas (0,1,2) entonces nivel3=2; si usas (1,2,3) entonces nivel3=3
    // Heurística: si existe “1” como primer nivel común, preferimos 3; si no, 2.
    // (Si quieres fijo, deja $nivel3 = 3 o 2 según tu tabla.)
    $nivel3 = 3;
    if ($colNivel === 'profundidad') {
        // normalmente profundidad 0/1/2 => unidad = 2
        $nivel3 = 2;
    } else {
        // si es 'nivel', suele ser 1/2/3
        $nivel3 = 3;
    }

    $where = "WHERE `$colBukId` IS NOT NULL AND `$colBukId` > 0 AND `$colNivel` = ".(int)$nivel3;

    if ($colEstado) {
        $where .= " AND `$colEstado`='mapeado'";
    }

    // (Opcional) si guardaste el tipo (division/centro_costo/unidad) filtra unidad:
    if (table_has_column($db, $table, 'tipo_origen_adp')) {
        $where .= " AND tipo_origen_adp='unidad'";
    }

    $sql = "SELECT `$colCodigo` AS codigo_unidad, `$colBukId` AS buk_area_id
            FROM `$table`
            $where";

    $rows = $db->consultar($sql);

    $map = [];
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $u = (int)($r['codigo_unidad'] ?? 0);
            $b = (int)($r['buk_area_id'] ?? 0);
            if ($u > 0 && $b > 0) $map[$u] = $b;
        }
    }
    return $map;
}

function load_role_map_from_db(clsConexion $db): array {
    $sql = "SELECT cargo_adp_id, buk_role_id
            FROM stisoft_mapeo_cargos
            WHERE estado='mapeado' AND buk_role_id IS NOT NULL";
    $rows = $db->consultar($sql);
    $map = [];
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $c = (int)($r['cargo_adp_id'] ?? 0);
            $b = isset($r['buk_role_id']) ? (int)$r['buk_role_id'] : 0;
            if ($c > 0 && $b > 0) $map[$c] = $b;
        }
    }
    return $map;
}

function map_area_id_from_row_db(array $r, array $areaMap): ?int {
    $unidad = (int)pick($r, ['Unidad','unidad']);
    if ($unidad <= 0) return null;
    return $areaMap[$unidad] ?? null;
}

function map_role_id_from_row_db(array $r, array $roleMap): ?int {
    $cargoId = (int)pick($r, ['Cargo','cargo']);
    if ($cargoId <= 0) return null;
    return $roleMap[$cargoId] ?? null;
}

// ============================================================
// Buk API
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
    } elseif ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($ch, $opts);

    $respBody = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['ok'=>false,'code'=>0,'error'=>$err,'body'=>'Error de cURL: '.$err,'url'=>$url,'variant'=>'curl_error'];
    }

    $ok = ($code >= 200 && $code < 300);
    return ['ok'=>$ok,'code'=>$code,'error'=>null,'body'=>$respBody !== false ? $respBody : '','url'=>$url,'variant'=>$ok?'ok':'http_error'];
}

function parse_msg(string $body): string {
    $t = trim($body);
    if (substr($t, 0, 15) === 'Error de cURL:') return $t;
    $j = json_decode($body, true);
    if (is_array($j)) {
        if (isset($j['errors'])) return json_encode($j['errors'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        if (isset($j['message'])) return (string)$j['message'];
        if (isset($j['error'])) return is_string($j['error']) ? $j['error'] : json_encode($j['error'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    }
    return (strlen($body) > 5000) ? substr($body, 0, 5000) : $body;
}

// ============================================================
// Payload EMPLEADO (tu lógica intacta)
// ============================================================
// ============================================================
// Payload EMPLEADO (tu lógica intacta) + FIX pago:
// - Si hay banco + tipo cuenta + nro cuenta => Transferencia Bancaria
// - Si no => No Generar Pago
// ============================================================
function build_employee_payload(array $r): array {
    $first  = pick($r, ['Nombres','Nombre','Primer Nombre']);
    $sur1   = pick($r, ['Apaterno','Apellido Paterno','Apellido1']);
    $sur2   = pick($r, ['Amaterno','Apellido Materno','Apellido2']);
    $rut    = pick($r, ['Rut','RUT','Documento','documento']);

    $birthday = to_iso(pick($r, ['Fecha Nacimiento','Fecha de Nacimiento','birthday','Fec_Nac']));

    $start_date = to_iso(pick($r, ['Fecha de Ingreso','Fec_Ingreso','start_date','active_since']));
    if (!$start_date) $start_date = date('Y-m-d');

    $estado   = pick($r, ['Estado','status']);
    $estadoL  = strtolower((string)$estado);
    $active = in_array($estadoL, ['s','1','activo','a'], true) ? 'active' : 'inactive';

    $email  = pick($r, ['Mail','Email','Correo','email']);
    $pemail = pick($r, ['personal_email','Email Personal','Correo Personal']);

    // Evita duplicados con placeholder
    if ($email !== '' && strtolower($email) === 'correo@empresa.cl') {
        static $usedEmails = [];
        do {
            $rand6 = str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $email = "correo{$rand6}@empresa.cl";
        } while (isset($usedEmails[$email]));
        $usedEmails[$email] = true;
    }

    $street = pick($r, ['calle','Calle','street']);
    $street_number = pick($r, ['numero','Nro','Numero','Número','street_number']);
    $address = trim(pick($r, ['Direccion','Dirección','address']));
    if ($address === '') $address = trim($street . ' ' . $street_number);

    $office = pick($r, ['depto','office_number','Depto','Departamento']);
    $city   = pick($r, ['ciudad','city','Ciudad']);

    $location_id = (int)DEFAULT_LOCATION_ID;

    $gender = norm_gender(pick($r, ['Sexo','gender','Genero','Género'])) ?? 'M';
    $nationality = 'CL';
    $code_sheet  = pick($r, ['Codigo','code_sheet','Ficha','Código Ficha']);

    // ------------------- FIX FORMA DE PAGO -------------------
    // Si hay banco + tipo cuenta + nro cuenta => Transferencia Bancaria
    // (tu requisito: "si tiene columna banco es transf bancaria")
    $bank_raw = pick($r, [
        'Descripcion Banco fpago1','Descripción Banco fpago1','Banco Fpago1',
        'Descripcion Banco fpago2','Descripción Banco fpago2','Banco Fpago2',
        'bank_name','Banco'
    ]);
    $bank = norm_bank($bank_raw);

    $acct_n = trim(pick($r,[
        'Cuenta Corriente fpago1','Cuenta Corriente',
        'Cuenta Interbancaria fpago1','Cuenta Interbancaria',
        'account_number','num_cuenta','N° Cuenta','Numero Cuenta','Número Cuenta','Cuenta'
    ]));

    // Tipo de cuenta: primero intenta la columna normal,
// si viene vacía, inferir desde "Descripcion Forma de Pago 1" / "Descripcion Forma"
$acc_type_raw = pick($r, ['Tipo Cuenta fpago1','Tipo de Cuenta','account_type']);

// fallback real (TU CASO): "Descripcion Forma de Pago 1" trae "Cuenta Corriente"
if (trim($acc_type_raw) === '') {
    $acc_type_raw = pick($r, [
        'Descripcion Forma de Pago 1',
        'Descripción Forma de Pago 1',
        'Descripcion Forma',
        'Descripción Forma'
    ]);
}

$acc_type = norm_account_type($acc_type_raw);


    $pperiod_raw = pick($r, ['Regimen de Pago','payment_period','Régimen de Pago','Codigo de Regimen']);
    $pperiod = norm_payment_period($pperiod_raw) ?? 'mensual';

    $hasBankData = (!empty($bank) && !empty($acc_type) && !empty($acct_n));

    if ($hasBankData) {
        $pmeth = 'Transferencia Bancaria';
    } else {
        $pmeth = 'No Generar Pago';
        $bank = null;
        $acc_type = null;
        $acct_n = null;
    }
    // ---------------------------------------------------------

    $payload = [
        'first_name'      => $first,
        'surname'         => $sur1,
        'second_surname'  => $sur2 ?: null,
        'document_type'   => 'rut',
        'document_number' => $rut,
        'code_sheet'      => $code_sheet ?: null,

        'nationality'     => $nationality,
        'gender'          => $gender,
        'birthday'        => $birthday,

        'email'           => $email ?: ($pemail ?: null),
        'personal_email'  => $pemail ?: null,

        'location_id'     => $location_id,
        'address'         => $address ?: ($street ? trim($street.' '.$street_number) : 'Sin dirección'),
        'street'          => $street ?: null,
        'street_number'   => $street_number ?: null,
        'office_number'   => $office ?: null,
        'city'            => $city ?: null,

        'payment_currency'=> 'CLP',
        'payment_method'  => $pmeth,
        'payment_period'  => $pperiod,
        'advance_payment' => 'sin_anticipo',

        'start_date'      => $start_date,
        'active'          => $active,
    ];

    if ($pmeth === 'Transferencia Bancaria') {
        $payload['bank'] = $bank;
        $payload['account_type'] = $acc_type;
        $payload['account_number'] = $acct_n;
    }

    return $payload;
}


// ============================================================
// 🔥 PLAN (PlanInputCountry) — mapeo desde columnas ADP
// Nota: NO inventa enums: normaliza a los valores permitidos.
// ============================================================
function plan_norm_upper(?string $s): string {
    $s = (string)$s;
    $s = trim($s);
    $s = mb_strtoupper($s, 'UTF-8');
    // limpia dobles espacios
    $s = preg_replace('/\s+/', ' ', $s);
    return $s ?: '';
}

function plan_map_pension_scheme(array $r): string {
    $afp = plan_norm_upper(pick($r, ['Descripcion AFP','Descripción AFP','AFP','afp']));
    $cod = trim(pick($r, ['Codigo AFP','Código AFP','cod_afp']));
    if ($afp === '' && $cod === '') return 'no_cotiza';
    if (str_contains($afp, 'SIN') && str_contains($afp, 'AFP')) return 'no_cotiza';
    if (str_contains($afp, 'NO COTIZA')) return 'no_cotiza';

    // IPS
    if (str_contains($afp, 'I.N.P') || str_contains($afp, 'INP') || str_contains($afp, 'EMPART') || str_contains($afp, 'SERVICIOS DE SEGURO SOCIAL') || str_contains($afp, 'CAPREMER') || str_contains($afp, 'TRIOMAR')) {
        return 'ips';
    }

    // AFP conocidas (si contiene estas palabras, lo tratamos como afp)
    $afps = ['CAPITAL','CUPRUM','HABITAT','MODELO','PLANVITAL','PROVIDA','UNO'];
    foreach ($afps as $x) {
        if (str_contains($afp, $x)) return 'afp';
    }

    // Si viene algo raro, no arriesgamos: no cotiza
    return 'no_cotiza';
}

function plan_map_fund_quote(array $r, string $pension_scheme): ?string {
    if (!in_array($pension_scheme, ['afp','ips'], true)) return null;

    $afp = plan_norm_upper(pick($r, ['Descripcion AFP','Descripción AFP','AFP','afp']));

    // AFP
    if (str_contains($afp, 'CAPITAL')) return 'capital';
    if (str_contains($afp, 'CUPRUM')) return 'cuprum';
    if (str_contains($afp, 'HABITAT')) return 'habitat';
    if (str_contains($afp, 'MODELO')) return 'modelo';
    if (str_contains($afp, 'PLANVITAL') || str_contains($afp, 'PLAN VITAL')) return 'planvital';
    if (str_contains($afp, 'PROVIDA') || str_contains($afp, 'PRO VIDA')) return 'provida';
    if (str_contains($afp, 'UNO') || str_contains($afp, 'AFP UNO')) return 'uno';

    // IPS (mejor esfuerzo según lo que sueles tener)
    if (str_contains($afp, 'CAPREMER')) return 'capremer_regimen_1';
    if (str_contains($afp, 'TRIOMAR')) return 'triomar_regimen_1';
    if (str_contains($afp, 'EMPART')) return 'empart_regimen_1';

    // Si no calza, no enviamos fund_quote (para no reventar por enum)
    return null;
}

function plan_map_health_company(array $r): string {
    $isa = plan_norm_upper(pick($r, ['Descripcion Isapre','Descripción Isapre','Isapre','isapre','Descripcion Salud','Descripción Salud']));
    if ($isa === '' || $isa === '0') return 'no_cotiza_salud';
    if (str_contains($isa, 'FONASA')) return 'fonasa';
    if (str_contains($isa, 'BANMEDICA') || str_contains($isa, 'BANMÉDICA')) return 'banmedica';
    if (str_contains($isa, 'COLMENA')) return 'colmena';
    if (str_contains($isa, 'CONSALUD')) return 'consalud';
    if (str_contains($isa, 'CRUZ BLANCA')) return 'cruz_blanca';
    if (str_contains($isa, 'MASVIDA') || str_contains($isa, 'MÁS VIDA') || str_contains($isa, 'MAS VIDA')) return 'nueva_masvida';
    if (str_contains($isa, 'VIDA TRES')) return 'vida_tres';
    if (str_contains($isa, 'BANCO ESTADO') || str_contains($isa, 'BANCOESTADO')) return 'banco_estado';

    // Si viene algo raro, no arriesgamos
    return 'no_cotiza_salud';
}

function plan_map_afc(array $r): string {
    // Si hay fecha seguro cesantía => normal, si no => no_cotiza
    $f = to_iso(pick($r, ['Fecha Seguro Cesantía','Fecha Seguro Cesantia','Fecha Seguro Cesantía (AFC)','fecha_afc']));
    return $f ? 'normal' : 'no_cotiza';
}

function plan_map_retired(array $r): bool {
    $j = mb_strtolower(trim(pick($r, ['Jubilado','jubilado'])), 'UTF-8');
    return in_array($j, ['s','si','sí','1','true','y','yes'], true);
}

function build_plan_payload(array $r): array {
    $pension_scheme = plan_map_pension_scheme($r);
    $fund_quote = plan_map_fund_quote($r, $pension_scheme);
    $health_company = plan_map_health_company($r);
    $afc = plan_map_afc($r);
    $retired = plan_map_retired($r);

    $payload = [
        'pension_scheme' => $pension_scheme,
        'health_company' => $health_company,
        'afc'            => $afc,
        'retired'        => $retired,

        // Defaults seguros (no inventamos datos, solo “no / false”)
        'disability'             => false,
        'invalidity'             => 'no',
        'youth_employment_subsidy'=> false,
        'foreign_technician'     => false,
        'quote_increase_one_percent' => false,
    ];

    // Solo si corresponde
    if ($fund_quote) $payload['fund_quote'] = $fund_quote;

    // afp_collector solo si IPS y cotiza AFC y fund_quote es AFP (capital/cuprum/...)
    if ($pension_scheme === 'ips' && $afc !== 'no_cotiza' && $fund_quote) {
        $afpQuotes = ['capital','cuprum','habitat','modelo','planvital','provida','uno'];
        if (in_array($fund_quote, $afpQuotes, true)) {
            $payload['afp_collector'] = 'recauda_'.$fund_quote;
        }
    }

    // ⚠️ NO enviamos health_company_plan* porque en tu BD/ADP normalmente no viene.
    // Si algún día lo tienes, lo agregamos condicionado a Isapre.

    return $payload;
}

// ============================================================
// ✅ Payload JOB (área/cargo desde BD; NO inventa; jefe fijo)
// ============================================================
function build_job_payload(array $r, int $employeeId, array $areaMap, array $roleMap): array
{
    // 1) IDs desde mapping BD (Unidad/Cargo)
    $areaId = map_area_id_from_row_db($r, $areaMap);
    $roleId = map_role_id_from_row_db($r, $roleMap);

    // 2) Fechas
    $start_date = to_iso(pick($r, [
        'start_date',
        'Fecha Inicio',
        'Fecha de Ingreso',
        'Fecha Ingreso',
        'Fecha Contrato'
    ])) ?? date('Y-m-d');

    // 3) Tipo de contrato desde ADP (BD) - TUS COLUMNAS
    // Descripcion Situacion Laboral: "Contrato Indefinido" | "Contrato A Plazo Fijo" | "Eventual con Cppt" | NULL
    $rawSituacion = norm_txt(pick($r, ['Descripcion Situacion Laboral', 'Descripción Situación Laboral']));
    $typeContract = null;

    if ($rawSituacion === '' || $rawSituacion === 'NULL') {
        // ✅ decisión práctica: default para no perder registros
        $typeContract = 'Indefinido';
    } elseif (str_contains($rawSituacion, 'CONTRATO INDEFINIDO')) {
        $typeContract = 'Indefinido';
    } elseif (str_contains($rawSituacion, 'CONTRATO A PLAZO FIJO')) {
        $typeContract = 'Plazo fijo';
    } elseif (str_contains($rawSituacion, 'EVENTUAL CON CPPT')) {
        $typeContract = 'Obra';
    }

    // 4) Jornada desde ADP (BD) - TUS COLUMNAS
    // Descripcion Horario: "Administrativo" | "Art.22 Inciso 2." | "Turnos Rotativos 7,5 Hrs."
    $rawHorario = norm_txt(pick($r, ['Descripcion Horario', 'Descripción Horario']));
    $typeWorking = null;
    $otherWorking = null;

    if ($rawHorario !== '' && $rawHorario !== 'NULL') {
        if (str_contains($rawHorario, 'TURNOS ROTATIVOS')) {
            $typeWorking  = 'otros';
            $otherWorking = 'especial_art_38_inc_6';
        } elseif (str_contains($rawHorario, 'ART.22') || str_contains($rawHorario, 'ART 22') || str_contains($rawHorario, 'ART. 22')) {
            $typeWorking = 'exenta_art_22';
        } elseif (str_contains($rawHorario, 'ADMINISTRATIVO')) {
            $typeWorking = 'ordinaria_art_22';
        }
    }

    // ✅ Si por alguna razón viene vacío/desconocido, define un default seguro
    // (si prefieres "no enviar", cambia esto por: $typeWorking = null;)
    if (!$typeWorking) {
        $typeWorking = 'ordinaria_art_22';
    }
    if (!$typeContract) {
        $typeContract = 'Indefinido';
    }

    // 5) Sueldo (si no lo tienes, queda 0)
    $salary_liq   = money_to_int(pick($r, ['Sueldo Líquido','Sueldo Liquido','Liquido','Sueldo']));
    $salary_gross = money_to_int(pick($r, ['Sueldo Bruto','Bruto','Renta Bruta','Renta']));
    $wage = $salary_gross ?: $salary_liq ?: 0;

    // 6) Payload final (Buk JobInputCountryPost)
    $payload = [
        // Debug útil para ver qué venía de ADP/BD
        '__raw_situacion_laboral' => pick($r, ['Descripcion Situacion Laboral', 'Descripción Situación Laboral']),
        '__raw_horario'           => pick($r, ['Descripcion Horario', 'Descripción Horario']),

        // ✅ mapped_ok REAL: solo unidad/cargo (BD)
        '__mapped_ok'   => (bool)($areaId && $roleId),
        '__map_area_id' => $areaId,
        '__map_role_id' => $roleId,

        'company_id'   => (int)COMPANY_ID_FOR_JOBS,
        'location_id'  => (int)DEFAULT_LOCATION_ID,
        'employee_id'  => (int)$employeeId,

        'start_date'        => $start_date,
        'type_of_contract'  => $typeContract,

        // Defaults que definiste
        'periodicity'   => 'mensual',
        'regular_hours' => 44,

        'type_of_working_day' => $typeWorking,

        'area_id'    => (int)$areaId,
        'role_id'    => (int)$roleId,
        'leader_id'  => (int)DEFAULT_LEADER_ID,

        'wage'     => (int)$wage,
        'currency' => 'peso',
    ];

    // Si es "otros", agregamos el detalle
    if ($typeWorking === 'otros' && $otherWorking) {
        $payload['other_type_of_working_day'] = $otherWorking;
    }

    // end_of_contract: solo si tuvieras fecha término y contrato plazo fijo/renovación
    // (si la tienes en BD, dime el nombre exacto de la columna y lo conecto)
    // if ($typeContract === 'Plazo fijo' && $endDate) { $payload['end_of_contract'] = $endDate; }

    return $payload;
}



// ============================================================
// Carga de empleados desde la BD (tabla adp_empleados)
// ============================================================
function load_empleados_from_db(clsConexion $db): array {
    $rutSolo = isset($_GET['rut']) ? trim((string)$_GET['rut']) : '';
    $where = "estado_buk = 'no_enviado'";

    if ($rutSolo !== '') {
        $rutEsc = $db->real_escape_string($rutSolo);
        $where .= " AND Rut = '{$rutEsc}'";
    }

    $sql = "SELECT * FROM adp_empleados WHERE {$where}";
    $rows = $db->consultar($sql);
    return is_array($rows) ? $rows : [];
}

// ===== Controller =====
$alert = null;

// 🔥 Cargamos mapas UNA VEZ por request
$AREA_MAP = load_area_map_from_db($db);
$ROLE_MAP = load_role_map_from_db($db);

$rows  = load_empleados_from_db($db);
$last_result = null;
$bulk_result = null;
$result_log  = $_SESSION['result_log'] ?? [];

$perPage = isset($_REQUEST['per_page']) ? (int)$_REQUEST['per_page'] : 30;
if(!in_array($perPage, PAGE_SIZES, true)) $perPage = 30;
$page    = isset($_REQUEST['p']) ? max(1, (int)$_REQUEST['p']) : 1;

// Estado persistente de pasos
$STEP_STATE = load_step_state();

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action = $_POST['action'] ?? '';

    if ($action==='clear_log') {
        unset($_SESSION['result_log']);
        $result_log = [];
    }

    if($action==='upload' && isset($_FILES['archivo'])){
        $alert = ['type'=>'error','msg'=>'Esta pantalla toma los datos desde BD (adp_empleados). Usa la importación ADP para cargar la información.'];
    }

    // Helpers para ejecutar un paso y guardar estado + last_result + historial
    $run_step_emp = function(int $idx, array $row) use (&$STEP_STATE, $db, &$last_result, &$result_log) {
        $key = step_key_from_row($row);
        init_step_state_if_missing($STEP_STATE, $key);

        $emp = build_employee_payload($row);
        $payloadFile = save_log('payload_emp', $idx, $emp);

        $resEmp = buk_api_request('POST', BUK_EMP_CREATE_PATH, $emp);
        $respEmpFile = save_log('response_emp', $idx, [
            'http_code'=>$resEmp['code'], 'variant'=>$resEmp['variant'], 'url'=>$resEmp['url'],
            'body'=>$resEmp['body'], 'curl_error' => $resEmp['error']
        ]);

        $empId = null;
        if ($resEmp['ok']) {
            $empJson = json_decode($resEmp['body'], true);
            $empId = $empJson['data']['id'] ?? null;

            // cache rut->id
            $rutMap = load_rut_empid_map();
            $rutEmp = normalize_rut($empJson['data']['rut'] ?? pick($row, ['Rut','RUT','Documento','documento']));
            if ($rutEmp && $empId) {
                $rutMap[$rutEmp] = (int)$empId;
                save_rut_empid_map($rutMap);
            }
        }

        $STEP_STATE[$key]['emp'] = [
            'ok' => (bool)$resEmp['ok'],
            'id' => $empId ? (int)$empId : null,
            'http' => (int)$resEmp['code'],
            'msg' => parse_msg($resEmp['body']).' ['.$resEmp['variant'].']',
            'payload_file' => $payloadFile,
            'resp_file' => $respEmpFile,
            'ts' => date('Y-m-d H:i:s'),
        ];

        // last_result detallado
        $empPayloadJson = json_encode($emp, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        if ($empPayloadJson === false) $empPayloadJson = "Error: No se pudo generar el JSON de empleado.";

        $last_result = [
            'ok' => (bool)$resEmp['ok'],
            'code' => (int)$resEmp['code'],
            'title' => $resEmp['ok'] ? 'Paso 1/3 — Colaborador creado' : 'Paso 1/3 — Error al crear colaborador',
            'msg' => $STEP_STATE[$key]['emp']['msg'],
            'emp_payload'=> $empPayloadJson,
            'emp_response'=> $resEmp['body'],
            'emp_payload_file'=> $payloadFile,
            'emp_response_file'=> $respEmpFile,
            'job_status'=> '-',
            'job_payload'=> '',
            'job_response'=> '',
        ];

        $nombreRow = trim((pick($row, ['Nombres','Nombre']).' '.pick($row, ['Apaterno']).' '.pick($row, ['Amaterno'])));
        $result_log[] = [
            'idx'    => $idx,
            'name'   => $nombreRow !== '' ? $nombreRow : (pick($row, ['full_name']) ?: '-'),
            'status' => $resEmp['ok'] ? ('EMP_OK id='.$empId) : 'EMP_ERROR',
            'http'   => (int)$resEmp['code'],
            'msg'    => $STEP_STATE[$key]['emp']['msg'],
            'ts'     => date('Y-m-d H:i:s'),
        ];
        $_SESSION['result_log'] = $result_log;

        // Persistimos estado
        save_step_state($STEP_STATE);
    };

    $run_step_plan = function(int $idx, array $row) use (&$STEP_STATE, &$last_result, &$result_log) {
        $key = step_key_from_row($row);
        init_step_state_if_missing($STEP_STATE, $key);

        $empId = (int)($STEP_STATE[$key]['emp']['id'] ?? 0);
        if ($empId <= 0) {
            $last_result = ['ok'=>false,'code'=>0,'title'=>'Paso 2/3 — No se puede crear Plan','msg'=>'Primero debes crear el Colaborador (EMP).'];
            return;
        }

        $plan = build_plan_payload($row);
        $payloadFile = save_log('payload_plan', $idx, $plan);

        $resPlan = buk_api_request('POST', sprintf(BUK_PLAN_CREATE_PATH, $empId), $plan);
        $respFile = save_log('response_plan', $idx, [
            'http_code'=>$resPlan['code'], 'variant'=>$resPlan['variant'], 'url'=>$resPlan['url'],
            'body'=>$resPlan['body'], 'curl_error' => $resPlan['error']
        ]);

        $planId = null;
        if ($resPlan['ok']) {
            $j = json_decode($resPlan['body'], true);
            // Buk suele devolver data.id, si no, quedará null (igual marcamos ok)
            $planId = $j['data']['id'] ?? null;
        }

        $STEP_STATE[$key]['plan'] = [
            'ok' => (bool)$resPlan['ok'],
            'id' => $planId !== null ? $planId : null,
            'http' => (int)$resPlan['code'],
            'msg' => parse_msg($resPlan['body']).' ['.$resPlan['variant'].']',
            'payload_file' => $payloadFile,
            'resp_file' => $respFile,
            'ts' => date('Y-m-d H:i:s'),
        ];

        $planPayloadJson = json_encode($plan, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        if ($planPayloadJson === false) $planPayloadJson = "Error: No se pudo generar el JSON del plan.";

        $last_result = [
            'ok'=> (bool)$resPlan['ok'],
            'code'=> (int)$resPlan['code'],
            'title'=> $resPlan['ok'] ? 'Paso 2/3 — Plan creado' : 'Paso 2/3 — Error al crear Plan',
            'msg'=> $STEP_STATE[$key]['plan']['msg'],
            'emp_payload'=> '',
            'emp_response'=> '',
            'emp_payload_file'=> null,
            'emp_response_file'=> null,
            'job_status'=> 'PLAN',
            'job_payload'=> $planPayloadJson,
            'job_response'=> $resPlan['body'],
        ];

        $nombreRow = trim((pick($row, ['Nombres','Nombre']).' '.pick($row, ['Apaterno']).' '.pick($row, ['Amaterno'])));
        $result_log[] = [
            'idx'    => $idx,
            'name'   => $nombreRow !== '' ? $nombreRow : (pick($row, ['full_name']) ?: '-'),
            'status' => $resPlan['ok'] ? ('PLAN_OK'.($planId?(' id='.$planId):'')) : 'PLAN_ERROR',
            'http'   => (int)$resPlan['code'],
            'msg'    => $STEP_STATE[$key]['plan']['msg'],
            'ts'     => date('Y-m-d H:i:s'),
        ];
        $_SESSION['result_log'] = $result_log;

        save_step_state($STEP_STATE);
    };

    $run_step_job = function(int $idx, array $row) use (&$STEP_STATE, $db, &$last_result, &$result_log, $AREA_MAP, $ROLE_MAP) {
        $key = step_key_from_row($row);
        init_step_state_if_missing($STEP_STATE, $key);

        $empId = (int)($STEP_STATE[$key]['emp']['id'] ?? 0);
        if ($empId <= 0) {
            $last_result = ['ok'=>false,'code'=>0,'title'=>'Paso 3/3 — No se puede crear Job','msg'=>'Primero debes crear el Colaborador (EMP).'];
            return;
        }
        if (empty($STEP_STATE[$key]['plan']['ok'])) {
            $last_result = ['ok'=>false,'code'=>0,'title'=>'Paso 3/3 — No se puede crear Job','msg'=>'Primero debes crear el Plan (PASO 2).'];
            return;
        }

        $jobPayload = build_job_payload($row, $empId, $AREA_MAP, $ROLE_MAP);

        // Si no está mapeado, NO enviamos JOB
        if (empty($jobPayload['__mapped_ok'])) {
            $STEP_STATE[$key]['job'] = [
                'ok'=> false,
                'id'=> null,
                'http'=> 0,
                'msg'=> 'JOB: NO_ENVIADO (mapping BD incompleto)',
                'payload_file'=> null,
                'resp_file'=> null,
                'ts'=> date('Y-m-d H:i:s'),
                'skip'=> true,
                'skip_reason'=> 'Falta mapping BD: area_id/role_id no encontrado',
            ];
            save_step_state($STEP_STATE);

            $last_result = [
                'ok'=>false,
                'code'=>0,
                'title'=>'Paso 3/3 — Job NO enviado',
                'msg'=>'JOB: NO_ENVIADO (mapping BD incompleto) — Unidad/Cargo sin mapeo en BD.',
                'emp_payload'=>'',
                'emp_response'=>'',
                'job_status'=>'JOB_SKIP',
                'job_payload'=> json_encode($jobPayload, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),
                'job_response'=> '',
            ];
            return;
        }

        unset($jobPayload['__mapped_ok'], $jobPayload['__map_area_id'], $jobPayload['__map_role_id']);

        $payloadFile = save_log('payload_job', $idx, $jobPayload);
        $jobRes = buk_api_request('POST', sprintf(BUK_JOB_CREATE_PATH, $empId), $jobPayload);
        $respFile = save_log('response_job', $idx, [
            'http_code'=>$jobRes['code'],
            'variant'=>$jobRes['variant'],
            'url'=>$jobRes['url'],
            'body'=>$jobRes['body'],
            'curl_error'=>$jobRes['error']
        ]);

        $jobId = null;
        if ($jobRes['ok']) {
            $j = json_decode($jobRes['body'], true);
            $jobId = $j['data']['id'] ?? null;
        }

        $STEP_STATE[$key]['job'] = [
            'ok' => (bool)$jobRes['ok'],
            'id' => $jobId,
            'http' => (int)$jobRes['code'],
            'msg' => parse_msg($jobRes['body']).' ['.$jobRes['variant'].']',
            'payload_file' => $payloadFile,
            'resp_file' => $respFile,
            'ts' => date('Y-m-d H:i:s'),
            'skip' => false,
            'skip_reason' => null,
        ];

        // Si todo OK (EMP+PLAN+JOB) => completo
        if (!empty($STEP_STATE[$key]['emp']['ok']) && !empty($STEP_STATE[$key]['plan']['ok']) && !empty($STEP_STATE[$key]['job']['ok'])) {
            if (!empty($row['Codigo'])) {
                $codigoEsc = $db->real_escape_string($row['Codigo']);
                $db->ejecutar("UPDATE adp_empleados SET estado_buk='completo' WHERE Codigo = '{$codigoEsc}'");
            }
        }

        $jobPayloadJson = json_encode($jobPayload, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        if ($jobPayloadJson === false) $jobPayloadJson = "Error: No se pudo generar el JSON del job.";

        $last_result = [
            'ok'=> (bool)$jobRes['ok'],
            'code'=> (int)$jobRes['code'],
            'title'=> $jobRes['ok'] ? 'Paso 3/3 — Job creado' : 'Paso 3/3 — Error al crear Job',
            'msg'=> $STEP_STATE[$key]['job']['msg'],
            'emp_payload'=> '',
            'emp_response'=> '',
            'emp_payload_file'=> null,
            'emp_response_file'=> null,
            'job_status'=> 'JOB',
            'job_payload'=> $jobPayloadJson,
            'job_response'=> $jobRes['body'] ?? '',
        ];

        $nombreRow = trim((pick($row, ['Nombres','Nombre']).' '.pick($row, ['Apaterno']).' '.pick($row, ['Amaterno'])));
        $result_log[] = [
            'idx'    => $idx,
            'name'   => $nombreRow !== '' ? $nombreRow : (pick($row, ['full_name']) ?: '-'),
            'status' => $jobRes['ok'] ? ('JOB_OK'.($jobId?(' id='.$jobId):'')) : 'JOB_ERROR',
            'http'   => (int)$jobRes['code'],
            'msg'    => $STEP_STATE[$key]['job']['msg'],
            'ts'     => date('Y-m-d H:i:s'),
        ];
        $_SESSION['result_log'] = $result_log;

        save_step_state($STEP_STATE);
    };

    // === Acciones por fila ===
    if(($action==='create_emp' || $action==='create_plan' || $action==='create_job') && isset($_POST['idx'])){
        $idx=(int)$_POST['idx'];
        $rows = load_empleados_from_db($db);

        if(isset($rows[$idx])){
            $row = $rows[$idx];

            if ($action==='create_emp')  $run_step_emp($idx, $row);
            if ($action==='create_plan') $run_step_plan($idx, $row);
            if ($action==='create_job')  $run_step_job($idx, $row);

        } else {
            $last_result = ['ok'=>false,'code'=>0,'title'=>'Error Interno','msg'=>'Índice de fila inválido.'];
        }
    }

    // === Envío masivo (solo Paso 1: crear colaboradores) ===
    if($action==='create_emp_bulk' && !empty($_POST['idx'])){
        $indices = array_map('intval', (array)$_POST['idx']);
        $rows    = load_empleados_from_db($db);

        $summary = ['total' => count($indices), 'ok' => 0, 'fail' => 0, 'items' => []];

        foreach($indices as $idx){
            if(!isset($rows[$idx])) continue;
            $row = $rows[$idx];

            $key = step_key_from_row($row);
            init_step_state_if_missing($STEP_STATE, $key);

            // Si ya está creado, lo saltamos sin contar como error
            if (!empty($STEP_STATE[$key]['emp']['ok']) && !empty($STEP_STATE[$key]['emp']['id'])) {
                $summary['ok']++;
                $summary['items'][] = ['idx'=>$idx,'http_code'=>200,'ok'=>true,'msg'=>'EMP ya creado id='.$STEP_STATE[$key]['emp']['id']];
                continue;
            }

            // Ejecuta
            $emp = build_employee_payload($row);
            $payloadFile = save_log('payload_emp',$idx,$emp);
            $resEmp = buk_api_request('POST', BUK_EMP_CREATE_PATH, $emp);
            $respEmpFile = save_log('response_emp',$idx,[
                'http_code'=>$resEmp['code'], 'variant'=>$resEmp['variant'], 'url'=>$resEmp['url'],
                'body'=>$resEmp['body'], 'curl_error' => $resEmp['error']
            ]);

            $empId = null;
            if ($resEmp['ok']) {
                $empJson = json_decode($resEmp['body'], true);
                $empId = $empJson['data']['id'] ?? null;

                $rutMap = load_rut_empid_map();
                $rutEmp = normalize_rut($empJson['data']['rut'] ?? pick($row, ['Rut','RUT','Documento','documento']));
                if ($rutEmp && $empId) {
                    $rutMap[$rutEmp] = (int)$empId;
                    save_rut_empid_map($rutMap);
                }
            }

            $STEP_STATE[$key]['emp'] = [
                'ok' => (bool)$resEmp['ok'],
                'id' => $empId ? (int)$empId : null,
                'http' => (int)$resEmp['code'],
                'msg' => parse_msg($resEmp['body']).' ['.$resEmp['variant'].']',
                'payload_file' => $payloadFile,
                'resp_file' => $respEmpFile,
                'ts' => date('Y-m-d H:i:s'),
            ];

            $okForSummary = (bool)$resEmp['ok'];
            $summary[$okForSummary?'ok':'fail']++;

            $summary['items'][] = [
                'idx'=>$idx,
                'http_code'=>$resEmp['code'],
                'ok'=>$okForSummary,
                'msg'=>$STEP_STATE[$key]['emp']['msg'],
                'payload_file'=>basename($payloadFile),
                'response_file'=>basename($respEmpFile),
            ];
        }

        save_step_state($STEP_STATE);
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

// Helpers UI status badge
function step_badge(array $step): string {
    $ok = !empty($step['ok']);
    $id = $step['id'] ?? null;
    if ($ok) {
        $txt = 'OK'.($id!==null?(' id='.$id):'');
        return '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-emerald-50 text-emerald-700 border border-emerald-200">'.$txt.'</span>';
    }
    if (!empty($step['skip'])) {
        return '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-amber-50 text-amber-700 border border-amber-200">SKIP</span>';
    }
    return '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-gray-50 text-gray-600 border border-gray-200">Pendiente</span>';
}

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
          <div class="text-xs text-gray-500">ADP → Buk · PASOS: EMP → PLAN → JOB (área/cargo desde BD)</div>
        </div>

        <?php if($last_result): ?>
          <div class="<?= $last_result['ok'] ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?> border rounded-2xl px-4 py-3 text-sm space-y-2">
            <div class="font-semibold"><?= e($last_result['title']) ?></div>
            <div><?= nl2br(e($last_result['msg'])) ?></div>
            <?php if(!empty($last_result['code'])): ?>
              <div class="text-xs text-gray-600">HTTP: <?= (int)$last_result['code'] ?></div>
            <?php endif; ?>

            <?php if(!empty($last_result['emp_payload'])): ?>
              <details class="mt-2 bg-white/70 border border-gray-200 rounded-xl p-3">
                <summary class="cursor-pointer font-semibold text-gray-800">EMP — Payload enviado</summary>
                <pre class="mt-2 text-xs overflow-auto whitespace-pre-wrap"><?= e($last_result['emp_payload'] ?? '') ?></pre>
              </details>
              <details class="bg-white/70 border border-gray-200 rounded-xl p-3">
                <summary class="cursor-pointer font-semibold text-gray-800">EMP — Respuesta Buk</summary>
                <pre class="mt-2 text-xs overflow-auto whitespace-pre-wrap"><?= e($last_result['emp_response'] ?? '') ?></pre>
              </details>
            <?php endif; ?>

            <?php if(!empty($last_result['job_status']) && $last_result['job_status'] !== '-'): ?>
              <details class="bg-white/70 border border-gray-200 rounded-xl p-3">
                <summary class="cursor-pointer font-semibold text-gray-800"><?= e($last_result['job_status']) ?> — Payload/Respuesta</summary>
                <pre class="mt-2 text-xs overflow-auto whitespace-pre-wrap"><?= e($last_result['job_payload'] ?? '') ?></pre>
                <pre class="mt-2 text-xs overflow-auto whitespace-pre-wrap"><?= e($last_result['job_response'] ?? '') ?></pre>
              </details>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if($bulk_result): ?>
          <div class="bg-blue-50 text-blue-700 border border-blue-200 rounded-2xl px-4 py-3 text-sm space-y-1">
            <div class="font-semibold">Resultado envío masivo (Paso 1: EMP)</div>
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
          <input type="hidden" name="action" value="create_emp_bulk">
          <input type="hidden" name="per_page" value="<?= $perPage ?>">
          <input type="hidden" name="p" value="<?= $page ?>">

          <div class="px-5 py-3 border-b bg-gray-50/60">
            <div class="bg-white/90 backdrop-blur border rounded-2xl p-3 shadow-sm">
              <div class="flex flex-wrap items-center gap-3">
                <div class="text-xs text-gray-500">
                  Mapping desde BD:
                  <code>stisoft_mapeo_areas</code> (Unidad→Área) ·
                  <code>stisoft_mapeo_cargos</code> (Cargo→Role)
                </div>
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

          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 text-gray-600">
                <tr>
                  <th class="text-left px-5 py-3"><input type="checkbox" id="checkAll"></th>
                  <th class="text-left px-5 py-3">#</th>
                  <th class="text-left px-5 py-3">RUT</th>
                  <th class="text-left px-5 py-3">Nombre</th>
                  <th class="text-left px-5 py-3">Unidad</th>
                  <th class="text-left px-5 py-3">Cargo</th>

                  <th class="text-left px-5 py-3">Colab</th>
                  <th class="text-left px-5 py-3">Plan</th>
                  <th class="text-left px-5 py-3">Job</th>

                  <th class="text-left px-5 py-3">Acciones</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
              <?php if(is_array($pageRows) && count($pageRows)>0): ?>
                <?php foreach($pageRows as $origIdx=>$r):
                  $rut    = pick($r, ['Rut','RUT']);
                  $nombre = trim(pick($r, ['Nombres']).' '.pick($r, ['Apaterno']).' '.pick($r, ['Amaterno']));

                  $unidad = (int)pick($r, ['Unidad']);
                  $cargo  = (int)pick($r, ['Cargo']);

                  $areaOk = isset($AREA_MAP[$unidad]);
                  $roleOk = isset($ROLE_MAP[$cargo]);

                  $k = step_key_from_row($r);
                  init_step_state_if_missing($STEP_STATE, $k);

                  $empS  = $STEP_STATE[$k]['emp'];
                  $planS = $STEP_STATE[$k]['plan'];
                  $jobS  = $STEP_STATE[$k]['job'];

                  $canPlan = !empty($empS['ok']) && !empty($empS['id']);
                  $canJob  = $canPlan && !empty($planS['ok']);
                ?>
                <tr class="hover:bg-gray-50/80">
                  <td class="px-5 py-2 align-top">
                    <input type="checkbox" name="idx[]" value="<?= $origIdx ?>" class="rowCheck">
                  </td>
                  <td class="px-5 py-2 align-top text-xs text-gray-500"><?= $origIdx+1+$offset ?></td>
                  <td class="px-5 py-2 align-top font-mono text-xs"><?= e($rut) ?></td>
                  <td class="px-5 py-2 align-top"><div class="font-medium"><?= e($nombre) ?></div></td>
                  <td class="px-5 py-2 align-top text-xs <?= $areaOk?'text-emerald-700':'text-rose-700' ?>">
                    <?= e((string)$unidad) ?> <?= $areaOk ? '✓' : '✗' ?>
                  </td>
                  <td class="px-5 py-2 align-top text-xs <?= $roleOk?'text-emerald-700':'text-rose-700' ?>">
                    <?= e((string)$cargo) ?> <?= $roleOk ? '✓' : '✗' ?>
                  </td>

                  <td class="px-5 py-2 align-top"><?= step_badge($empS) ?></td>
                  <td class="px-5 py-2 align-top"><?= step_badge($planS) ?></td>
                  <td class="px-5 py-2 align-top"><?= step_badge($jobS) ?></td>

                  <td class="px-5 py-2 align-top space-x-2 whitespace-nowrap">
                    <!-- Paso 1 -->
                    <form method="post" class="inline">
                      <input type="hidden" name="action" value="create_emp">
                      <input type="hidden" name="idx" value="<?= $origIdx ?>">
                      <input type="hidden" name="per_page" value="<?= $perPage ?>">
                      <input type="hidden" name="p" value="<?= $page ?>">
                      <button type="submit"
                        class="inline-flex items-center px-2.5 py-1.5 rounded-lg bg-blue-600 text-white text-xs hover:bg-blue-700">
                        <i class="fa-solid fa-user-plus mr-1"></i> Crear Colab
                      </button>
                    </form>

                    <!-- Paso 2 -->
                    <form method="post" class="inline">
                      <input type="hidden" name="action" value="create_plan">
                      <input type="hidden" name="idx" value="<?= $origIdx ?>">
                      <input type="hidden" name="per_page" value="<?= $perPage ?>">
                      <input type="hidden" name="p" value="<?= $page ?>">
                      <button type="submit"
                        class="inline-flex items-center px-2.5 py-1.5 rounded-lg <?= $canPlan ? 'bg-indigo-600 hover:bg-indigo-700 text-white' : 'bg-gray-200 text-gray-500 cursor-not-allowed' ?> text-xs"
                        <?= $canPlan ? '' : 'disabled' ?>>
                        <i class="fa-solid fa-file-contract mr-1"></i> Crear Plan
                      </button>
                    </form>

                    <!-- Paso 3 -->
                    <form method="post" class="inline">
                      <input type="hidden" name="action" value="create_job">
                      <input type="hidden" name="idx" value="<?= $origIdx ?>">
                      <input type="hidden" name="per_page" value="<?= $perPage ?>">
                      <input type="hidden" name="p" value="<?= $page ?>">
                      <button type="submit"
                        class="inline-flex items-center px-2.5 py-1.5 rounded-lg <?= $canJob ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-gray-200 text-gray-500 cursor-not-allowed' ?> text-xs"
                        <?= $canJob ? '' : 'disabled' ?>>
                        <i class="fa-solid fa-briefcase mr-1"></i> Crear Job
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="10" class="px-5 py-6 text-center text-sm text-gray-500">
                    No hay registros pendientes (estado_buk = 'no_enviado').
                  </td>
                </tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="px-5 py-3 border-t flex items-center justify-between bg-gray-50/80">
            <button type="submit" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs hover:bg-blue-700">
              <i class="fa-solid fa-users mr-1"></i> Crear colaboradores seleccionados (Paso 1)
            </button>
            <div class="text-xs text-gray-500">Página <?= $page ?> de <?= $pages ?> · Total: <?= number_format($total) ?> registros</div>
          </div>
        </form>
      </section>

      <section class="bg-white border rounded-2xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b flex items-center justify-between">
          <div class="font-semibold">Historial de resultados</div>
          <form method="post">
            <input type="hidden" name="action" value="clear_log">
            <button type="submit" class="text-xs px-2 py-1.5 border rounded-lg hover:bg-gray-50">Limpiar log</button>
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
                  <td colspan="6" class="px-3 py-4 text-center text-gray-500">No hay resultados aún.</td>
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
<?php ob_end_flush(); ?>
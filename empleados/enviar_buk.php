<?php
// /empleados/enviar_buk.php
// Enviar UN empleado (desde tabla adp_empleados) a Buk: EMP + JOB

// When included from CLI we don't need the web authentication or output buffering.
if (PHP_SAPI !== 'cli') {
    ob_start();
    require_once __DIR__ . '/../includes/auth.php';
    require_auth();
    $user = current_user();
}

require_once __DIR__ . '/../conexion/db.php';

// DEBUG (desactiva en prod si quieres)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ================= CONFIG BUK =================
const BUK_API_BASE         = 'https://sti.buk.cl/api/v1/chile';
const BUK_EMP_CREATE_PATH  = '/employees.json';
const BUK_JOB_CREATE_PATH  = '/employees/%d/jobs';
const BUK_ROLES_PATH       = '/roles';
const BUK_TOKEN            = 'bAVH6fNSraVT17MBv1ECPrfW'; // mover a .env en prod

// Para job
const COMPANY_ID_FOR_JOBS  = 1;
const DEFAULT_LOCATION_ID  = 407;

const BUK_ALLOWED_BANKS = [
    'HSBC',
    'Itau',
    'BBVA',
    'BCI',
    'BICE',
    'Banco de Chile',
    'Consorcio',
    'COOPEUCH',
    'Corpbanca',
    'CrediChile',
    'Banco Estado',
    'Falabella',
    'Internacional',
    'Rabobank',
    'BTG Pactual',
    'Ripley',
    'Santander',
    'Scotiabank',
    'Security',
    'The Bank of Tokyo-Mitsubishi',
    'Sociedad Emisora de Tarjetas Los Heroes S.A.',
    'Tenpo Prepago SA',
    'Global 66',
    'Los Andes Tarjetas de Prepago',
    'Mercadopago Emisora S.A.',
    'JP Morgan Chase Bank',
    'Banco Deutsche',
    'Copec Pay',
    'MACH',
];

const LOG_DIR          = __DIR__ . '/logs_buk';
const RUT_MAP_FILE     = LOG_DIR . '/rut_to_empid.json';
const ROLES_CACHE_FILE = LOG_DIR . '/roles_cache.json';

if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// =============================================================
// Helpers genéricos
// =============================================================
function pick(array $row, array $cands): string {
    foreach ($cands as $c) {
        if (array_key_exists($c, $row) && $row[$c] !== null && $row[$c] !== '') {
            return (string)$row[$c];
        }
    }
    return '';
}

function to_iso(?string $s): ?string {
    $s = trim((string)$s);
    if ($s === '') return null;

    foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.Y', 'm/d/Y', 'Ymd'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt && $dt->format($fmt) === $s) {
            $year = (int)$dt->format('Y');
            if ($year > 1900 && $year < 2100) return $dt->format('Y-m-d');
        }
    }

    $s2 = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?(\.\d+)?$/', '', $s);
    if ($s2 !== null && $s2 !== $s) return to_iso($s2);

    $ts = strtotime($s);
    if ($ts !== false) {
        $year = (int)date('Y', $ts);
        if ($year > 1900 && $year < 2100) return date('Y-m-d', $ts);
    }
    return null;
}

// iso(YYYY-MM-DD) -> ymd(YYYYMMDD)
function iso_to_ymd(?string $iso): ?string {
    if (!$iso) return null;
    return str_replace('-', '', $iso);
}

function norm_gender(?string $g): ?string {
    if (!$g) return null;
    $g = strtolower(trim($g));
    if ($g === '') return null;
    if (in_array($g, ['m','masculino','1'], true)) return 'M';
    if (in_array($g, ['f','femenino','2'], true)) return 'F';
    return null;
}

function norm_payment_period(?string $v): ?string {
    if ($v === null) return null;
    $x = strtolower(trim($v));
    if ($x === '') return null;
    $map = [
        'm' => 'mensual',  'mensual'   => 'mensual',
        'q' => 'quincenal','quincenal' => 'quincenal',
        's' => 'semanal',  'semanal'   => 'semanal',
        'd' => 'diario',   'diario'    => 'diario',
        'a' => 'anual',    'anual'     => 'anual'
    ];
    return $map[$x] ?? null;
}

function norm_payment_method(?string $v): ?string {
    if ($v === null) return null;
    $x = strtolower(trim($v));
    if ($x === '') return null;
    $map = [
        'transferencia'                  => 'Transferencia Bancaria',
        'transferencia bancaria'         => 'Transferencia Bancaria',
        'cuenta corriente'               => 'Transferencia Bancaria',
        'cta cte'                        => 'Transferencia Bancaria',
        'cta cte otro banco'             => 'Transferencia Bancaria',
        'cuenta vista'                   => 'Transferencia Bancaria',
        'cta vista otro banco'           => 'Transferencia Bancaria',
        'cheque'                         => 'Cheque',
        'servipag'                       => 'Servipag',
        'vale vista'                     => 'Vale Vista',
        'no pago'                        => 'No Generar Pago',
        'no generar pago'                => 'No Generar Pago',
        'sin definicion'                 => 'Transferencia Bancaria',
    ];
    return $map[$x] ?? 'Transferencia Bancaria';
}

function norm_account_type(?string $v): ?string {
    if ($v === null) return null;
    $x = strtolower(trim($v));
    if ($x === '') return null;
    $map = [
        'corriente'       => 'Corriente',
        'cuenta corriente'=> 'Corriente',
        'vista'           => 'Vista',
        'cuenta vista'    => 'Vista',
        'ahorro'          => 'Ahorro',
        'cuenta de ahorro'=> 'Ahorro'
    ];
    return $map[$x] ?? 'Vista';
}

function norm_civil_status(?string $v): ?string {
    if ($v === null) return null;
    $x = strtolower(trim(str_replace(['(', ')'], '', $v)));
    if ($x === '') return null;
    $map = [
        'casado'  => 'Casado','casada'    => 'Casado',
        'divorciado' => 'Divorciado','divorciada' => 'Divorciado',
        'soltero' => 'Soltero','soltera'  => 'Soltero',
        'viudo'   => 'Viudo','viuda'      => 'Viudo',
        'auc'     => 'Acuerdo de Unión Civil',
        'acuerdo de union civil' => 'Acuerdo de Unión Civil',
        'union civil'           => 'Acuerdo de Unión Civil',
        'sep. no legal'         => 'Soltero',
        'separado legal'        => 'Divorciado',
    ];
    return $map[$x] ?? null;
}

function norm_bank(?string $v): ?string {
    if ($v === null) return null;
    $x = strtolower(trim($v));
    if ($x === '') return null;
    $map = [
        'bancoestado'   => 'Banco Estado',
        'banco estado'  => 'Banco Estado',
        'bci'           => 'BCI',
        'banco de chile'=> 'Banco de Chile',
        'santander'     => 'Santander',
        'scotiabank'    => 'Scotiabank',
    ];
    return $map[$x] ?? $v;
}

function money_to_int(?string $v): int {
    if ($v === null) return 0;
    $s = preg_replace('/[^0-9\-]/', '', (string)$v);
    if ($s === '' || $s === '-') return 0;
    return (int)$s;
}

function normalize_phone(?string $p): ?string {
    if (!$p) return null;
    $d = preg_replace('/\D/', '', $p);
    if ($d === '') return null;
    if (strlen($d) === 8) $d = '56' . $d;
    if (strlen($d) === 9 && $d[0] === '9') $d = '56' . $d;
    if (strlen($d) === 11 && substr($d, 0, 2) === '56') return $d;
    if (strlen($d) >= 8 && strlen($d) <= 12) return $d;
    return null;
}

// ============================================================
// Mapeo Centro de Costo ADP -> area_id BUK usando tabla buk_areas
// ============================================================
function map_area_id_from_row(array $r): ?int {
    $cc = (string) pick($r, [
        'Centro de Costo',
        'Centro Costo',
        'CC',
    ]);
    $cc = trim($cc);
    if ($cc === '') return null;

    $cc = (string) intval($cc);

    $db = new clsConexion();
    $ccEsc = $db->real_escape_string($cc);

    $sql = "
        SELECT id
        FROM buk_areas
        WHERE centro_costo = '$ccEsc'
        ORDER BY profundidad DESC
        LIMIT 1
    ";
    $rows = $db->consultar($sql);
    if ($rows && isset($rows[0]['id'])) {
        return (int) $rows[0]['id'];
    }

    return null;
}

// ============================================================
// Logs + parse_msg
// ============================================================
function save_log(string $prefix, int|string $idx, array|string $data): string {
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
    $fn = LOG_DIR . '/' . $prefix . '_idx_' . $idx . '_' . date('Ymd_His') . '.json';
    file_put_contents($fn, is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : $data);
    return $fn;
}

function parse_msg(string $body): string {
    $t = trim($body);
    if (substr($t, 0, 15) === 'Error de cURL:') return $t;
    $j = json_decode($body, true);
    if (is_array($j)) {
        if (isset($j['errors']))  return json_encode($j['errors'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (isset($j['message'])) return (string)$j['message'];
        if (isset($j['error']))   return is_string($j['error']) ? $j['error'] : json_encode($j['error'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    return (strlen($body) > 5000) ? substr($body, 0, 5000) : $body;
}

// ============================================================
// Llamadas genéricas a la API
// ============================================================
function buk_api_request(string $method, string $path, ?array $payload = null): array {
    $url  = rtrim(BUK_API_BASE, '/') . $path;

    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $jsonErr = json_last_error_msg();
            save_log('json_fail_data', $path, $payload);
            return [
                'ok'     => false,
                'code'   => 0,
                'error'  => 'JSON_ENCODE_FAIL',
                'body'   => 'Error de cURL: Fallo al codificar JSON (' . $jsonErr . ').',
                'url'    => $url,
                'variant'=> 'json_fail'
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
            'auth_token: ' . BUK_TOKEN,
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
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return [
            'ok'      => false,
            'code'    => 0,
            'error'   => $err,
            'body'    => 'Error de cURL: ' . $err,
            'url'     => $url,
            'variant' => 'curl_error'
        ];
    }

    return [
        'ok'      => ($code >= 200 && $code < 300),
        'code'    => $code,
        'error'   => '',
        'body'    => $resp,
        'url'     => $url,
        'variant' => 'bare_root'
    ];
}

function post_buk_employee(array $emp): array {
    return buk_api_request('POST', BUK_EMP_CREATE_PATH, $emp);
}

function post_buk_job(int $employeeId, array $job): array {
    $path = sprintf(BUK_JOB_CREATE_PATH, $employeeId);
    return buk_api_request('POST', $path, $job);
}

// ============================================================
// Roles cache + creación automática (roles de Buk)
// ============================================================
function get_roles_cache(): array {
    if (file_exists(ROLES_CACHE_FILE)) {
        $raw = file_get_contents(ROLES_CACHE_FILE);
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
    }

    $all   = [];
    $page  = 1;
    $loops = 0;
    $next  = BUK_ROLES_PATH . '?page=' . $page . '&page_size=1000';

    while ($next && $loops < 5) {
        $res = buk_api_request('GET', $next, null);
        if (!$res['ok']) break;

        $j = json_decode($res['body'], true);
        if (!is_array($j) || !isset($j['data'])) break;

        $all   = array_merge($all, (array)$j['data']);
        $next  = $j['pagination']['next'] ?? null;
        if ($next) {
            $next = str_replace(BUK_API_BASE, '', $next);
        }
        $loops++;
    }

    file_put_contents(
        ROLES_CACHE_FILE,
        json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );

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
    if ($s === '') $s = 'role_' . time();
    return $s;
}

function ensure_role_exists_in_buk(string $cargo, ?int $areaId): ?int {
    $cargo = trim($cargo);
    if ($cargo === '') return null;

    $found = find_role_id_in_cache($cargo);
    if ($found) return $found;

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
        save_log('role_create_error', $code, [
            'cargo'    => $cargo,
            'area_id'  => $areaId,
            'response' => $res
        ]);
        return null;
    }

    $j  = json_decode($res['body'], true);
    $id = $j['data']['id'] ?? null;
    if ($id) {
        $roles   = get_roles_cache();
        $roles[] = $j['data'];
        file_put_contents(
            ROLES_CACHE_FILE,
            json_encode($roles, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        return (int)$id;
    }

    return null;
}

// ============================================================
// Normalización RUT + líder
// ============================================================
function normalize_rut(?string $rut): ?string {
    if ($rut === null) return null;
    $s = preg_replace('/[^0-9kK]/', '', (string)$rut);
    if ($s === '') return null;
    return strtoupper($s);
}

function load_rut_empid_map(): array {
    if (!file_exists(RUT_MAP_FILE)) return [];
    $raw = file_get_contents(RUT_MAP_FILE);
    $map = json_decode($raw, true);
    return is_array($map) ? $map : [];
}

function save_rut_empid_map(array $map): void {
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
    file_put_contents(RUT_MAP_FILE, json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function map_leader_id_from_row(array $r, array $rutMap): ?int {
    $rutJefe = pick($r, ['Jefe', 'Rut Jefe', 'RUT Jefe', 'rut_jefe']);
    $rutJefeNorm = normalize_rut($rutJefe);
    if (!$rutJefeNorm) return null;
    return isset($rutMap[$rutJefeNorm]) ? (int)$rutMap[$rutJefeNorm] : null;
}

// ============================================================
// Job helpers
// ============================================================
function norm_contract_type_for_job(?string $v): string {
    $x = mb_strtolower(trim((string)$v), 'UTF-8');
    $map = [
        'indefinido'             => 'Indefinido',
        'plazo fijo'             => 'Plazo fijo',
        'plazo_fijo'             => 'Plazo fijo',
        'renovación automática'  => 'Renovación Automática',
        'renovacion automatica'  => 'Renovación Automática',
        'obra'                   => 'Obra',
        'aprendizaje'            => 'Aprendizaje',
        'honorarios'             => 'A honorarios',
        'a honorarios'           => 'A honorarios',
    ];
    return $map[$x] ?? 'Indefinido';
}

// ============================================================
// Job helpers - modelo nuevo /employees/{id}/jobs
// ============================================================
function build_job_payload(array $r, int $employeeId, ?int $leaderId): ?array
{
    $empresaAdp = pick($r, ['Empresa', 'empresa_id', 'company_id']);
    $companyId  = (int)($empresaAdp ?: COMPANY_ID_FOR_JOBS);
    if ($companyId <= 0) {
        $companyId = COMPANY_ID_FOR_JOBS;
    }

    $areaId = map_area_id_from_row($r);

    $cargoNombre = pick($r, ['Descripcion Cargo','Cargo','Cargo Nombre','role_name']);
    $roleId      = ensure_role_exists_in_buk($cargoNombre, $areaId);

    if (!$areaId || !$roleId) {
        return null;
    }

    $startDateIso = to_iso(pick($r, ['Fecha de Ingreso','start_date'])) ?? date('Y-m-d');
    $endDateIso   = to_iso(pick($r, ['Fecha de Retiro','end_date']));

    $weekly_hours  = 45;
    $salary_liq    = money_to_int(pick($r, ['Sueldo Líquido','Sueldo Liquido','Liquido','Sueldo']));
    $salary_gross  = money_to_int(pick($r, ['Sueldo Bruto','Bruto','Renta Bruta','Renta']));
    $wage          = $salary_gross ?: $salary_liq ?: 0;

    $pperiod = norm_payment_period(
        pick($r, ['Regimen de Pago','Régimen de Pago','Codigo de Regimen'])
    ) ?? 'mensual';

    $contractType = norm_contract_type_for_job(
        pick($r, ['Tipo de Contrato','Descripcion Tipo Contrato'])
    );

    $cc_code = pick($r, ['Centro de Costo','Centro Costo','CC','cost_center']);

    if ($wage <= 0) {
        // Para evitar problemas con sueldo base 0
        $wage = 1;
    }

    $job = [
        'company_id'                => $companyId,
        'area_id'                   => $areaId,
        'contract_type'             => $contractType,
        'start_date'                => $startDateIso,
        'end_date'                  => $endDateIso,
        'notice_date'               => null,
        'contract_finishing_date_1' => null,
        'contract_finishing_date_2' => null,
        'weekly_hours'              => $weekly_hours,
        'cost_center'               => $cc_code ?: null,
        'periodicity'               => $pperiod,
        'frequency'                 => $pperiod,
        'working_schedule_type'     => 'ordinaria_art_22',
        'other_type_of_working_day' => null,
        'location_id'               => (string)DEFAULT_LOCATION_ID,
        'without_wage'              => false,
        'zone_assignment'           => false,
        'currency_code'             => 'CLP',
        'base_wage'                 => $wage,
        'contract_subscription_date'=> $startDateIso,
        'reward'                    => false,
        'reward_concept'            => null,
        'reward_payment_period'     => null,
        'reward_description'        => null,
        'grado_sector_publico_chile'=> null,
        'estamento_sector_publico_chile'=> null,
        'termination_fundaments'    => null,
        'role'                      => (string)$roleId,
    ];

    if ($leaderId) {
        $job['boss'] = [
            'id' => $leaderId,
        ];
    }

    $job = array_filter($job, fn($v) => !($v === null || $v === ''));

    return $job;
}

// ============================================================
// build_employee_payload (ajustado a lo que exige la API)
// ============================================================
function build_employee_payload(array $r): array {
    $first  = pick($r,['Nombres','Nombre','Primer Nombre']);
    $sur1   = pick($r,['Apaterno','Apellido Paterno','Apellido1']);
    $sur2   = pick($r,['Amaterno','Apellido Materno','Apellido2']);
    $rut    = pick($r,['Rut','RUT','Documento','documento']);

    $email  = pick($r,['Mail','Email','Correo','email']);
    $pemail = pick($r,['personal_email','Email Personal','Correo Personal']);

    if ($email !== '' && strtolower($email) === 'correo@empresa.cl') {
        static $usedEmails = [];
        do {
            $rand6 = str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $email = "correo{$rand6}@empresa.cl";
        } while (isset($usedEmails[$email]));
        $usedEmails[$email] = true;
    }

    $phone  = pick($r,['Celular', 'Telefono1','Teléfono 1','Telefono','phone']);
    $ofono  = pick($r,['Telefono', 'Telefono2','Teléfono 2','office_phone']);
    $phone  = normalize_phone($phone);
    $ofono  = normalize_phone($ofono);

    $street = pick($r,['calle','street','Calle','Direccion','Dirección','address','Address']);
    $num    = pick($r,['numero','Numero','N°','Número','street_number']);
    $addr   = trim(($street ?: '').' '.($num ?: ''));
    if ($addr === '') {
        $addr = pick($r,['address','Address','Direccion','Dirección']);
    }

    $office = pick($r,['depto','office_number','Depto','Departamento']);
    $city   = pick($r,['ciudad','city','Ciudad']);
    $district_code = pick($r,['Comuna']);
    $district_name = pick($r,['Dcomuna']);
    $district = $district_name ?: ($district_code ?: null);
    $region_code   = pick($r,['region','Region','Región']);
    $region_name   = pick($r,['Dregion']);
    $region = $region_name ?: ($region_code ?: null);

    $birthday_iso = to_iso(pick($r,['Fecha Nacimiento','birthday','Fecha de Nacimiento','Fec_Nac']));
    $birthday_ymd = iso_to_ymd($birthday_iso);

    $ing_iso   = to_iso(pick($r,['Fecha de Ingreso','active_since','Fec_Ingreso','start_date']));
    $ing_iso   = $ing_iso ?: date('Y-m-d');
    $ing_ymd   = iso_to_ymd($ing_iso);

    $estado   = pick($r,['Estado','status']);
    $activo   = in_array(strtolower((string)$estado), ['s','activo','a'], true);
    $status   = $activo ? 'activo' : 'inactivo';

    $gender   = norm_gender(pick($r,['Sexo','gender','Genero','Género']));

    $countryCode = 'CL';
    $civil_raw = pick($r,['Estado Civil','civil_status']);
    $civil     = norm_civil_status($civil_raw);

    $pmeth_raw = pick($r,['Descripcion Forma de Pago 1','payment_method','Forma de Pago 1']);
    $pmeth     = norm_payment_method($pmeth_raw);

    $acct_t_raw = pick($r,['Tipo Cuenta','account_type','Cuenta Tipo']);
    $acct_t     = norm_account_type($acct_t_raw);

    $bank_raw   = pick($r,['Descripcion Banco fpago1','bank','Banco Nombre','Banco Fpago1','Banco fpago1']);
    $bank       = norm_bank($bank_raw);

    $acct_n     = pick($r,[
        'Cuenta Corriente fpago1','account_number','Cuenta Corriente',
        'Cuenta Interbancaria fpago1','Cuenta Interbancaria',
        'num_cuenta','N° Cuenta','Numero Cuenta','Número Cuenta'
    ]);

    $pperiod_raw = pick($r,['Regimen de Pago','payment_period','Régimen de Pago','Codigo de Regimen']);
    $pperiod     = norm_payment_period($pperiod_raw);

    $fr_section = pick($r,['Tramo','family_allowance_section']) ?: 'D';

    $family_responsabilities = [[
        'id'                           => null,
        'family_allowance_section'     => $fr_section,
        'simple_family_responsability' => 0,
        'maternity_family_responsability' => 0,
        'invalid_family_responsability'=> 0,
        'start_date'                   => $ing_ymd,
        'end_date'                     => null,
        'responsability_details'       => [],
    ]];

    $doc_number = $rut ?: null;
    $doc_type   = $doc_number ? 'rut' : null;

    $full_name = trim(($first ?: '').' '.($sur1 ?: '').' '.($sur2 ?: ''));
    $last_name_full = trim(($sur1 ?: '').' '.($sur2 ?: ''));

    $data = [
        'first_name'      => $first !== '' ? $first : ($rut ?: 'SIN_NOMBRE'),
        'surname'         => $sur1 ?: null,
        'second_surname'  => $sur2 ?: null,
        'last_name'       => $last_name_full ?: null,
        'full_name'       => $full_name ?: null,
        'rut'             => $rut ?: null,
        'document_number' => $doc_number,
        'document_type'   => $doc_type,
        'nationality'     => 'Chilena',
        'country_code'    => $countryCode,
        'codigo_pais'     => $countryCode,
        'civil_status'    => $civil,
        'gender'          => $gender,
        'sexo'            => $gender,
        'birthday'        => $birthday_ymd,
        'date_of_birth'   => $birthday_ymd,
        'email'           => $email ?: null,
        'personal_email'  => $pemail ?: null,
        'address'         => $addr ?: null,
        'direccion'       => $addr ?: null,
        'street'          => $street ?: null,
        'street_number'   => $num ?: null,
        'office_number'   => $office ?: null,
        'city'            => $city ?: null,
        'district'        => $district ?: null,
        'location_id'     => DEFAULT_LOCATION_ID,
        'region'          => $region ?: null,
        'office_phone'    => $ofono ?: null,
        'phone'           => $phone ?: null,
        'active_since'    => $ing_ymd,
        'ingreso_compañia'=> $ing_ymd,
        'start_date'      => $ing_ymd,
        'status'          => $status,
        'active'          => $activo ? 'active' : 'inactive',
        'payment_currency'=> 'CLP',
        'payment_method'  => $pmeth ?: 'Transferencia Bancaria',
        'payment_period'  => $pperiod ?: 'mensual',
        'advance_payment' => 'sin_anticipo',
        'bank'            => $bank,
        'account_type'    => $acct_t,
        'account_number'  => $acct_n ?: null,
        'private_role'    => false,
        'code_sheet'      => pick($r,['Codigo','code_sheet','Ficha','Código Ficha']) ?: null,
        'family_responsabilities' => $family_responsabilities,
    ];

    $data = array_filter($data, fn($v) => !($v === null || $v === ''));

    if (($data['payment_method'] ?? null) === 'Transferencia Bancaria') {
        if (
            empty($data['bank']) ||
            !in_array($data['bank'], BUK_ALLOWED_BANKS, true)
        ) {
            $data['bank'] = 'Banco Estado';
        }

        if (empty($data['account_type'])) {
            $data['account_type'] = 'Vista';
        }
    } else {
        unset($data['bank'], $data['account_type'], $data['account_number']);
    }

    return $data;
}

// ============================================================
// Controller: recibe RUT, busca en BD y envía a Buk
// ============================================================
$rutSolicitado  = $_POST['rut'] ?? '';
$empleadoRow    = null;
$resultadoEnvio = null;
$errorGlobal    = null;

// only execute the web controller when running via HTTP POST
if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $rutSolicitado = trim($rutSolicitado);

    if ($rutSolicitado === '') {
        $errorGlobal = 'No se recibió el RUT del empleado.';
    } else {
        $db = new clsConexion();
        $rutEsc = $db->real_escape_string($rutSolicitado);
        $rows = $db->consultar("SELECT * FROM adp_empleados WHERE Rut = '$rutEsc' LIMIT 1");

        if (!$rows) {
            $errorGlobal = 'No se encontró el empleado con RUT ' . $rutSolicitado . ' en la tabla adp_empleados.';
        } else {
            $empleadoRow = $rows[0];

            // -------- EMP --------
            $empPayload   = build_employee_payload($empleadoRow);
            $payloadFile  = save_log('payload_emp', $rutSolicitado, $empPayload);
            $resEmp       = post_buk_employee($empPayload);
            $respEmpFile  = save_log('response_emp', $rutSolicitado, [
                'http_code' => $resEmp['code'],
                'variant'   => $resEmp['variant'],
                'url'       => $resEmp['url'],
                'body'      => $resEmp['body'],
                'curl_error'=> $resEmp['error'],
            ]);

            // -------- JOB --------
           $rutMap          = load_rut_empid_map();
$empId           = null;
$jobId           = null;   // <-- inicializamos aquí
$jobPayload      = null;
$jobRes          = null;
$jobStatus       = 'NO_JOB';
$payloadJobFile  = '';
$respJobFile     = '';
$idsJobLine      = '';


            if ($resEmp['ok']) {
                $empJson = json_decode($resEmp['body'], true);
                $empData = is_array($empJson) ? ($empJson['data'] ?? $empJson) : [];
                $empId   = $empData['id'] ?? null;

                $rutEmp = normalize_rut($empData['rut'] ?? $empleadoRow['Rut']);
                if ($rutEmp && $empId) {
                    $rutMap[$rutEmp] = (int)$empId;
                    save_rut_empid_map($rutMap);
                }
            }

            if ($empId) {
                $leaderId   = map_leader_id_from_row($empleadoRow, $rutMap);
                $jobPayload = build_job_payload($empleadoRow, $empId, $leaderId);

                if ($jobPayload) {
                    $payloadJobFile = save_log('payload_job', $rutSolicitado, $jobPayload);
                    $jobRes         = post_buk_job($empId, $jobPayload);
                    $respJobFile    = save_log('response_job', $rutSolicitado, [
                        'http_code' => $jobRes['code'],
                        'variant'   => $jobRes['variant'],
                        'url'       => $jobRes['url'],
                        'body'      => $jobRes['body'],
                        'curl_error'=> $jobRes['error'],
                    ]);
                    $jobStatus = $jobRes['ok'] ? 'JOB_OK' : 'JOB_ERROR';

                    $idsJobLine = sprintf(
                        'IDs JOB → company_id=%s · area_id=%s · role_id=%s · boss_id=%s',
                        $jobPayload['company_id'] ?? 'N/D',
                        $jobPayload['area_id'] ?? 'N/D',
                        $jobPayload['role'] ?? 'N/D',
                        $leaderId !== null ? $leaderId : 'N/D'
                    );
                } else {
                    $jobStatus = 'JOB_SKIP';
                }
            }

            // -------- Mensajes legibles EMP/JOB --------
            $empPayloadJson = json_encode($empPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($empPayloadJson === false) {
                $empPayloadJson = "Error: No se pudo generar JSON de empleado.";
            }

            $jobPayloadJson = '';
            if ($jobPayload !== null) {
                $tmp = json_encode($jobPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $jobPayloadJson = $tmp !== false ? $tmp : "Error: No se pudo generar JSON de JOB.";
            }

            $empJsonForRut = json_decode($resEmp['body'], true);
            $empDataForRut = is_array($empJsonForRut) ? ($empJsonForRut['data'] ?? $empJsonForRut) : [];

            $idStr  = $empId !== null ? (string)$empId : 'N/D';
            $rutStr = $empDataForRut['rut'] ?? ($empleadoRow['Rut'] ?? 'N/D');

            if ($resEmp['ok']) {
                $msgEmp = "EMP OK · id={$idStr} · rut={$rutStr}";
            } else {
                $msgEmp = 'EMP ERROR: ' . parse_msg($resEmp['body']) . ' [' . $resEmp['variant'] . ']';
            }

            $msgJob = '';
            if ($jobStatus === 'JOB_OK') {
                $msgJob = 'JOB OK';
            } elseif ($jobStatus === 'JOB_ERROR') {
                if ($jobRes) {
                    $parsed = trim(parse_msg($jobRes['body']));
                    if ($parsed === '') {
                        $bodyShort = substr((string)($jobRes['body'] ?? ''), 0, 2000);
                        $parsed = 'HTTP ' . (int)($jobRes['code'] ?? 0) . ' · ' . $bodyShort;
                    }
                    $msgJob = 'JOB ERROR: ' . $parsed;
                } else {
                    $msgJob = 'JOB ERROR: (no se recibió detalle desde la API)';
                }
            } elseif ($jobStatus === 'JOB_SKIP') {
                $msgJob = 'JOB NO ENVIADO (falta área/cargo/supervisor)';
            }

            $msgCombined = $msgEmp;
            if ($jobStatus !== 'NO_JOB' && $msgJob !== '') {
                $msgCombined .= "\n\n" . $msgJob;
                if ($idsJobLine !== '') {
                    $msgCombined .= "\n" . $idsJobLine;
                }
            }

            if (in_array($resEmp['variant'], ['json_fail','curl_error'], true)) {
                $title = 'Error Crítico';
            } elseif ($resEmp['ok']) {
                $title = ($jobStatus === 'JOB_OK' || $jobStatus === 'NO_JOB')
                    ? 'Enviado a Buk (EMP OK)'
                    : 'Empleado creado en Buk, JOB con errores';
            } else {
                $title = 'Fallo envío EMP';
            }

            $resultadoEnvio = [
    'ok'      => $resEmp['ok'] && ($jobStatus === 'JOB_OK' || $jobStatus === 'NO_JOB'),
    'code'    => $resEmp['code'],
    'title'   => $title,
    'msg'     => $msgCombined,
    'payload' => $empPayloadJson,
    'response'=> $resEmp['body'],
    'payload_file'       => basename($payloadFile),
    'response_file'      => basename($respEmpFile),
    'job_payload'        => $jobPayloadJson,
    'job_response'       => $jobRes['body'] ?? '',
    'job_payload_file'   => $payloadJobFile ? basename($payloadJobFile) : '',
    'job_response_file'  => $respJobFile ? basename($respJobFile) : '',

    // 👉 añadimos estos dos campos:
    'emp_id'            => $empId,
    'job_id'            => $jobId,
];

            
                        // =========================
            // Guardar estado en la tabla adp_empleados
            // =========================
            $estadoBuk = 'no_enviado';
           // $jobId     = null;

            if (!$resEmp['ok']) {
                $estadoBuk = 'emp_error';
            } else {
                if ($jobStatus === 'JOB_OK') {
                    $estadoBuk = 'completo';

                    // intentar sacar id de JOB de la respuesta
                    if ($jobRes && $jobRes['ok']) {
                        $jobJson = json_decode($jobRes['body'], true);
                        $jobData = is_array($jobJson) ? ($jobJson['data'] ?? $jobJson) : [];
                        $jobId   = $jobData['id'] ?? null;
                    }
                } elseif ($jobStatus === 'JOB_ERROR') {
                    $estadoBuk = 'emp_ok_job_error';
                } elseif ($jobStatus === 'JOB_SKIP') {
                    $estadoBuk = 'emp_ok_sin_job';
                } else {
                    // emp OK pero no se intentó job
                    $estadoBuk = 'emp_ok_sin_job';
                }
            }

            // Actualizar fila de empleados
            try {
                $dbUpdate = new clsConexion();
                $rutDb    = $dbUpdate->real_escape_string($empleadoRow['Rut']);
                $estadoDb = $dbUpdate->real_escape_string($estadoBuk);

                $empIdDb  = $empId  !== null ? (int)$empId  : 'NULL';
                $jobIdDb  = $jobId  !== null ? (int)$jobId  : 'NULL';

                $sqlUpd = "
                    UPDATE adp_empleados
                    SET estado_buk = '$estadoDb',
                        buk_emp_id  = $empIdDb,
                        buk_job_id  = $jobIdDb
                    WHERE Rut = '$rutDb'
                    LIMIT 1
                ";

                // usa el método que tengas para ejecutar UPDATE
                $dbUpdate->consultar($sqlUpd);
            } catch (Throwable $e) {
                // si falla el update, sólo lo logueamos para debug
                save_log('update_estado_buk_fail', $rutSolicitado, [
                    'error' => $e->getMessage(),
                    'sql'   => $sqlUpd ?? ''
                ]);
            }

        }
    }
} else {
    $errorGlobal = 'Acceso inválido. Este endpoint espera un POST con el RUT.';
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

        <main class="max-w-5xl mx-auto p-6 space-y-6">
            <section class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Empleado — Envío a Buk</h1>
                <a href="/empleados/" class="text-sm text-emerald-600 hover:underline">
                    ← Volver a listado
                </a>
            </section>

            <?php if ($errorGlobal): ?>
                <section class="bg-white border border-red-200 text-red-800 rounded-2xl p-4">
                    <div class="font-semibold mb-1">Error</div>
                    <div class="text-sm"><?= e($errorGlobal) ?></div>
                </section>
            <?php else: ?>
                <section class="bg-white border rounded-2xl p-4 space-y-3 shadow-sm">
                    <div class="flex items-center justify-between">
                       <div>
    <div class="text-sm text-gray-500">RUT</div>
    <div class="font-semibold text-lg"><?= e($empleadoRow['Rut'] ?? '') ?></div>
    <div class="text-sm text-gray-700">
        <?= e(trim(($empleadoRow['Nombres'] ?? '').' '.($empleadoRow['Apaterno'] ?? '').' '.($empleadoRow['Amaterno'] ?? ''))) ?>
    </div>

    <?php if ($resultadoEnvio): ?>
        <div class="text-xs text-gray-500 mt-1">
            EMP ID: <?= e($resultadoEnvio['emp_id'] ?? 'N/D') ?>
            <?php if (!empty($resultadoEnvio['job_id'])): ?>
                · JOB ID: <?= e($resultadoEnvio['job_id']) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

                        <?php if ($resultadoEnvio && $resultadoEnvio['ok']): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <i class="fa-solid fa-check mr-1"></i> Envío OK
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs bg-red-50 text-red-700 border border-red-200">
                                <i class="fa-solid fa-triangle-exclamation mr-1"></i> Con errores
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($resultadoEnvio): ?>
                        <div class="mt-4 space-y-2">
                            <div class="text-sm font-semibold"><?= e($resultadoEnvio['title']) ?></div>
                            <div class="text-sm whitespace-pre-wrap text-gray-800"><?= e($resultadoEnvio['msg']) ?></div>
                            <div class="text-xs text-gray-500 mt-2">
                                HTTP: <?= (int)$resultadoEnvio['code'] ?> ·
                                Payload: <?= e($resultadoEnvio['payload_file']) ?> ·
                                Response: <?= e($resultadoEnvio['response_file']) ?>
                            </div>
                            <?php if (!empty($resultadoEnvio['job_payload_file']) || !empty($resultadoEnvio['job_response_file'])): ?>
                                <div class="text-xs text-gray-500">
                                    JOB Payload: <?= e($resultadoEnvio['job_payload_file']) ?> ·
                                    JOB Response: <?= e($resultadoEnvio['job_response_file']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <details class="mt-4">
                            <summary class="cursor-pointer text-sm text-gray-600 hover:text-gray-800">
                                Ver JSON enviado (EMP)
                            </summary>
                            <pre class="mt-2 text-xs bg-gray-900 text-gray-100 rounded-lg p-3 overflow-x-auto"><?= e($resultadoEnvio['payload']) ?></pre>
                        </details>

                        <?php if (!empty($resultadoEnvio['job_payload'])): ?>
                            <details class="mt-2">
                                <summary class="cursor-pointer text-sm text-gray-600 hover:text-gray-800">
                                    Ver JSON JOB enviado
                                </summary>
                                <pre class="mt-2 text-xs bg-gray-900 text-gray-100 rounded-lg p-3 overflow-x-auto"><?= e($resultadoEnvio['job_payload']) ?></pre>
                            </details>
                        <?php endif; ?>

                        <details class="mt-2">
                            <summary class="cursor-pointer text-sm text-gray-600 hover:text-gray-800">
                                Ver respuesta cruda de Buk (EMP)
                            </summary>
                            <pre class="mt-2 text-xs bg-gray-900 text-gray-100 rounded-lg p-3 overflow-x-auto"><?= e($resultadoEnvio['response']) ?></pre>
                        </details>
                        
                        
                        <?php if (!empty($resultadoEnvio['job_payload'])): ?>
    <details class="mt-2">
        <summary class="cursor-pointer text-sm text-gray-600 hover:text-gray-800">
            Ver JSON JOB enviado
        </summary>
        <pre class="mt-2 text-xs bg-gray-900 text-gray-100 rounded-lg p-3 overflow-x-auto">
<?= e($resultadoEnvio['job_payload']) ?>
        </pre>
    </details>
<?php else: ?>
    <details class="mt-2">
        <summary class="cursor-pointer text-sm text-gray-600 hover:text-gray-800">
            Ver JSON JOB enviado
        </summary>
        <pre class="mt-2 text-xs bg-gray-900 text-gray-100 rounded-lg p-3 overflow-x-auto">
No se envió JOB porque falló el EMP (no se obtuvo id de colaborador).
        </pre>
    </details>
<?php endif; ?>


                        <?php if (!empty($resultadoEnvio['job_response'])): ?>
                            <details class="mt-2">
                                <summary class="cursor-pointer text-sm text-gray-600 hover:text-gray-800">
                                    Ver respuesta cruda de Buk (JOB)
                                </summary>
                                <pre class="mt-2 text-xs bg-gray-900 text-gray-100 rounded-lg p-3 overflow-x-auto"><?= e($resultadoEnvio['job_response']) ?></pre>
                            </details>
                        <?php endif; ?>

                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>
</div>
</body>
</html>

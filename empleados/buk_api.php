<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require_once __DIR__ . '/../includes/auth.php';
    require_auth();
}

require_once __DIR__ . '/../conexion/db.php';
require_once __DIR__ . '/../includes/runtime_config.php';

// DEBUG
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ================= CONFIG BUK =================
$bukCfg = runtime_buk_config();
define('BUK_API_BASE', $bukCfg['base']);
const BUK_EMP_CREATE_PATH  = '/employees.json';
const BUK_JOB_CREATE_PATH  = '/employees/%d/jobs';
const BUK_ROLES_PATH       = '/roles';
define('BUK_TOKEN', $bukCfg['token']);

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

if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0775, true);
}

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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
        'corriente'        => 'Corriente',
        'cuenta corriente' => 'Corriente',
        'vista'            => 'Vista',
        'cuenta vista'     => 'Vista',
        'ahorro'           => 'Ahorro',
        'cuenta de ahorro' => 'Ahorro'
    ];
    return $map[$x] ?? 'Vista';
}

function norm_civil_status(?string $v): ?string {
    if ($v === null) return null;
    $x = strtolower(trim(str_replace(['(', ')'], '', $v)));
    if ($x === '') return null;
    $map = [
        'casado'                 => 'Casado',
        'casada'                 => 'Casado',
        'divorciado'             => 'Divorciado',
        'divorciada'             => 'Divorciado',
        'soltero'                => 'Soltero',
        'soltera'                => 'Soltero',
        'viudo'                  => 'Viudo',
        'viuda'                  => 'Viudo',
        'auc'                    => 'Acuerdo de Unión Civil',
        'acuerdo de union civil' => 'Acuerdo de Unión Civil',
        'union civil'            => 'Acuerdo de Unión Civil',
        'sep. no legal'          => 'Soltero',
        'separado legal'         => 'Divorciado',
    ];
    return $map[$x] ?? null;
}

function norm_bank(?string $v): ?string {
    if ($v === null) return null;
    $x = strtolower(trim($v));
    if ($x === '') return null;
    $map = [
        'bancoestado'    => 'Banco Estado',
        'banco estado'   => 'Banco Estado',
        'bci'            => 'BCI',
        'banco de chile' => 'Banco de Chile',
        'santander'      => 'Santander',
        'scotiabank'     => 'Scotiabank',
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
        return (int)$rows[0]['id'];
    }

    return null;
}

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

function buk_api_request(string $method, string $path, ?array $payload = null): array {
    $url = rtrim(BUK_API_BASE, '/') . $path;

    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $jsonErr = json_last_error_msg();
            save_log('json_fail_data', $path, $payload);
            return [
                'ok'      => false,
                'code'    => 0,
                'error'   => 'JSON_ENCODE_FAIL',
                'body'    => 'Error de cURL: Fallo al codificar JSON (' . $jsonErr . ').',
                'url'     => $url,
                'variant' => 'json_fail'
            ];
        }
    }

    $ch = curl_init();
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

        $all  = array_merge($all, (array)$j['data']);
        $next = $j['pagination']['next'] ?? null;
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
        'name'              => $cargo,
        'code'              => $code,
        'description'       => '',
        'requirements'      => '',
        'area_ids'          => $areaId ? [$areaId] : [],
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

function norm_contract_type_for_job(?string $v): string {
    $x = mb_strtolower(trim((string)$v), 'UTF-8');
    $map = [
        'indefinido'            => 'Indefinido',
        'plazo fijo'            => 'Plazo fijo',
        'plazo_fijo'            => 'Plazo fijo',
        'renovación automática' => 'Renovación Automática',
        'renovacion automatica' => 'Renovación Automática',
        'obra'                  => 'Obra',
        'aprendizaje'           => 'Aprendizaje',
        'honorarios'            => 'A honorarios',
        'a honorarios'          => 'A honorarios',
    ];
    return $map[$x] ?? 'Indefinido';
}

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

    $weekly_hours = 45;
    $salary_liq   = money_to_int(pick($r, ['Sueldo Líquido','Sueldo Liquido','Liquido','Sueldo']));
    $salary_gross = money_to_int(pick($r, ['Sueldo Bruto','Bruto','Renta Bruta','Renta']));
    $wage         = $salary_gross ?: $salary_liq ?: 0;

    $pperiod = norm_payment_period(
        pick($r, ['Regimen de Pago','Régimen de Pago','Codigo de Regimen'])
    ) ?? 'mensual';

    $contractType = norm_contract_type_for_job(
        pick($r, ['Tipo de Contrato','Descripcion Tipo Contrato'])
    );

    $cc_code = pick($r, ['Centro de Costo','Centro Costo','CC','cost_center']);

    if ($wage <= 0) {
        $wage = 1;
    }

    $job = [
        'company_id'                 => $companyId,
        'area_id'                    => $areaId,
        'contract_type'              => $contractType,
        'start_date'                 => $startDateIso,
        'end_date'                   => $endDateIso,
        'notice_date'                => null,
        'contract_finishing_date_1'  => null,
        'contract_finishing_date_2'  => null,
        'weekly_hours'               => $weekly_hours,
        'cost_center'                => $cc_code ?: null,
        'periodicity'                => $pperiod,
        'frequency'                  => $pperiod,
        'working_schedule_type'      => 'ordinaria_art_22',
        'other_type_of_working_day'  => null,
        'location_id'                => (string)DEFAULT_LOCATION_ID,
        'without_wage'               => false,
        'zone_assignment'            => false,
        'currency_code'              => 'CLP',
        'base_wage'                  => $wage,
        'contract_subscription_date' => $startDateIso,
        'reward'                     => false,
        'reward_concept'             => null,
        'reward_payment_period'      => null,
        'reward_description'         => null,
        'grado_sector_publico_chile' => null,
        'estamento_sector_publico_chile' => null,
        'termination_fundaments'     => null,
        'role'                       => (string)$roleId,
    ];

    if ($leaderId) {
        $job['boss'] = [
            'id' => $leaderId,
        ];
    }

    $job = array_filter($job, fn($v) => !($v === null || $v === ''));

    return $job;
}

function build_employee_payload(array $r): array {
    $first = pick($r,['Nombres','Nombre','Primer Nombre']);
    $sur1  = pick($r,['Apaterno','Apellido Paterno','Apellido1']);
    $sur2  = pick($r,['Amaterno','Apellido Materno','Apellido2']);
    $rut   = pick($r,['Rut','RUT','Documento','documento']);

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

    $phone = pick($r,['Celular', 'Telefono1','Teléfono 1','Telefono','phone']);
    $ofono = pick($r,['Telefono', 'Telefono2','Teléfono 2','office_phone']);
    $phone = normalize_phone($phone);
    $ofono = normalize_phone($ofono);

    $street = pick($r,['calle','street','Calle','Direccion','Dirección','address','Address']);
    $num    = pick($r,['numero','Numero','N°','Número','street_number']);
    $addr   = trim(($street ?: '') . ' ' . ($num ?: ''));
    if ($addr === '') {
        $addr = pick($r,['address','Address','Direccion','Dirección']);
    }

    $office = pick($r,['depto','office_number','Depto','Departamento']);
    $city   = pick($r,['ciudad','city','Ciudad']);
    $district_code = pick($r,['Comuna']);
    $district_name = pick($r,['Dcomuna']);
    $district = $district_name ?: ($district_code ?: null);
    $region_code = pick($r,['region','Region','Región']);
    $region_name = pick($r,['Dregion']);
    $region = $region_name ?: ($region_code ?: null);

    $birthday_iso = to_iso(pick($r,['Fecha Nacimiento','birthday','Fecha de Nacimiento','Fec_Nac']));
    $birthday_ymd = iso_to_ymd($birthday_iso);

    $ing_iso = to_iso(pick($r,['Fecha de Ingreso','active_since','Fec_Ingreso','start_date']));
    $ing_iso = $ing_iso ?: date('Y-m-d');
    $ing_ymd = iso_to_ymd($ing_iso);

    $estado = pick($r,['Estado','status']);
    $activo = in_array(strtolower((string)$estado), ['s','activo','a'], true);
    $status = $activo ? 'activo' : 'inactivo';

    $gender = norm_gender(pick($r,['Sexo','gender','Genero','Género']));

    $countryCode = 'CL';
    $civil_raw = pick($r,['Estado Civil','civil_status']);
    $civil = norm_civil_status($civil_raw);

    $pmeth_raw = pick($r,['Descripcion Forma de Pago 1','payment_method','Forma de Pago 1']);
    $pmeth = norm_payment_method($pmeth_raw);

    $acct_t_raw = pick($r,['Tipo Cuenta','account_type','Cuenta Tipo']);
    $acct_t = norm_account_type($acct_t_raw);

    $bank_raw = pick($r,['Descripcion Banco fpago1','bank','Banco Nombre','Banco Fpago1','Banco fpago1']);
    $bank = norm_bank($bank_raw);

    $acct_n = pick($r,[
        'Cuenta Corriente fpago1','account_number','Cuenta Corriente',
        'Cuenta Interbancaria fpago1','Cuenta Interbancaria',
        'num_cuenta','N° Cuenta','Numero Cuenta','Número Cuenta'
    ]);

    $pperiod_raw = pick($r,['Regimen de Pago','payment_period','Régimen de Pago','Codigo de Regimen']);
    $pperiod = norm_payment_period($pperiod_raw);

    $fr_section = pick($r,['Tramo','family_allowance_section']) ?: 'D';

    $family_responsabilities = [[
        'id'                              => null,
        'family_allowance_section'        => $fr_section,
        'simple_family_responsability'    => 0,
        'maternity_family_responsability' => 0,
        'invalid_family_responsability'   => 0,
        'start_date'                      => $ing_ymd,
        'end_date'                        => null,
        'responsability_details'          => [],
    ]];

    $doc_number = $rut ?: null;
    $doc_type   = $doc_number ? 'rut' : null;

    $full_name = trim(($first ?: '') . ' ' . ($sur1 ?: '') . ' ' . ($sur2 ?: ''));
    $last_name_full = trim(($sur1 ?: '') . ' ' . ($sur2 ?: ''));

    $data = [
        'first_name'       => $first !== '' ? $first : ($rut ?: 'SIN_NOMBRE'),
        'surname'          => $sur1 ?: null,
        'second_surname'   => $sur2 ?: null,
        'last_name'        => $last_name_full ?: null,
        'full_name'        => $full_name ?: null,
        'rut'              => $rut ?: null,
        'document_number'  => $doc_number,
        'document_type'    => $doc_type,
        'nationality'      => 'Chilena',
        'country_code'     => $countryCode,
        'codigo_pais'      => $countryCode,
        'civil_status'     => $civil,
        'gender'           => $gender,
        'sexo'             => $gender,
        'birthday'         => $birthday_ymd,
        'date_of_birth'    => $birthday_ymd,
        'email'            => $email ?: null,
        'personal_email'   => $pemail ?: null,
        'address'          => $addr ?: null,
        'direccion'        => $addr ?: null,
        'street'           => $street ?: null,
        'street_number'    => $num ?: null,
        'office_number'    => $office ?: null,
        'city'             => $city ?: null,
        'district'         => $district ?: null,
        'location_id'      => DEFAULT_LOCATION_ID,
        'region'           => $region ?: null,
        'office_phone'     => $ofono ?: null,
        'phone'            => $phone ?: null,
        'active_since'     => $ing_ymd,
        'ingreso_compañia' => $ing_ymd,
        'start_date'       => $ing_ymd,
        'status'           => $status,
        'active'           => $activo ? 'active' : 'inactive',
        'payment_currency' => 'CLP',
        'payment_method'   => $pmeth ?: 'Transferencia Bancaria',
        'payment_period'   => $pperiod ?: 'mensual',
        'advance_payment'  => 'sin_anticipo',
        'bank'             => $bank,
        'account_type'     => $acct_t,
        'account_number'   => $acct_n ?: null,
        'private_role'     => false,
        'code_sheet'       => pick($r,['Codigo','code_sheet','Ficha','Código Ficha']) ?: null,
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

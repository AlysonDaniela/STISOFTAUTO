<?php
// /empleados/index.php  (FUSIONADO: listado + envío a Buk en la misma pantalla)
// - Mantiene estilo/estructura del index original
// - Agrega 3 modos en la MISMA página:
//   1) Envío masivo seleccionando (checkbox)  -> SOLO TODO (EMP+PLAN+JOB)  ✅ (solo activos)
//   2) Envío manual por fila (botones)        -> EMP / PLAN / JOB / TODO  ✅ (solo activos)
//   3) Envío masivo "filtrado" (arriba)       -> TODO / SOLO EMP / SOLO PLAN / SOLO JOB ✅ (solo activos)
// - Jefes: sección separada, colapsable, fuera de filtros/tabla; con jerarquía por niveles.
// - En masivo: NO muestra payload por fila; solo resumen + guarda logs SOLO de caídos
// - Mapeo JOB desde BD: buk_jerarquia (nivel unidad) + stisoft_mapeo_cargos (cargo_adp_id -> buk_role_id)
// - Si falta mapping (área/cargo): NO envía JOB (queda como "emp_ok_sin_job")

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Acceso denegado.');
}

require_once __DIR__ . '/../includes/auth.php';
require_auth();
$user = current_user();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../conexion/db.php';
$db = new clsConexion();

// ------------------ MENSAJES FLASH ------------------
$flashOk    = $_SESSION['flash_ok']    ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// ============================================================
// CONFIG BUK (igual que enviar_buk_solo.php)
// ============================================================
const BUK_API_BASE         = 'https://sti.buk.cl/api/v1/chile';
const BUK_EMP_CREATE_PATH  = '/employees.json';
const BUK_JOB_CREATE_PATH  = '/employees/%d/jobs';
const BUK_PLAN_CREATE_PATH = '/employees/%d/plans';

const BUK_TOKEN            = 'bAVH6fNSraVT17MBv1ECPrfW'; // ⚠️ mover a .env en prod

const COMPANY_ID_FOR_JOBS  = 1;
const DEFAULT_LOCATION_ID  = 407;
const FALLBACK_BOSS_RUT     = '15871627-5'; // ✅ jefe comodín por regla

const DEFAULT_LEADER_ID    = 313;

const LOG_DIR              = __DIR__ . '/logs_buk';

//Nuevos atributos Sindicato1
const ATTR_KEY_ACTUAL   = 'Sindicato Actual';
const ATTR_KEY_ANTERIOR = 'Sindicato Anterior';
///


// ============================================================
// Helpers genéricos
// ============================================================

//Nueva funcion Helper Sindicato1
function build_sindicato_custom_attrs(array $r): array {
    $actual = trim(pick($r, [
        'Sindicato Actual',
        'Descripción Categoria',   // ✅ TU BD
        'Descripcion Categoria',   // ✅ por si viene sin tilde
        'Sindicato',
        'sindicato_actual',
        'sindicato',
    ]));

    $anterior = trim(pick($r, [
        'Sindicato Anterior',
        'Descripción Sindicato',   // ✅ TU BD
        'Descripcion Sindicato',   // ✅ por si viene sin tilde
        'sindicato_anterior',
    ]));

    $attrs = [];
    if ($actual !== '')   $attrs[ATTR_KEY_ACTUAL]   = $actual;     // "Sindicato Actual" en Buk
    if ($anterior !== '') $attrs[ATTR_KEY_ANTERIOR] = $anterior;   // "Sindicato Anterior" en Buk
    return $attrs;
}

///

/*function buk_patch_custom_attributes(int $employee_id, array $customAttrs): array {
    return buk_api_request('PATCH', sprintf('/employees/%d', $employee_id), [
        'custom_attributes' => $customAttrs
    ]);
} comentado de Sindicato1 */


//----- Nueva funcion Sindicato1------//
function buk_patch_custom_attributes(int $employee_id, array $customAttrs): array {
    return buk_api_request('PATCH', sprintf('/employees/%d.json', $employee_id), [
        'custom_attributes' => $customAttrs
    ]);
}
////////



function http_request_with_headers(string $method, string $url, array $headers = [], $body = null, int $timeout = 30): array {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
  curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
  curl_setopt($ch, CURLOPT_HEADER, true);

  if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

  if ($body !== null) {
    if (is_array($body) || is_object($body)) $body = json_encode($body);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  }

  $raw = curl_exec($ch);
  $errno = curl_errno($ch);
  $err  = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);

  if ($raw === false) {
    return ['status' => 0, 'body' => '', 'headers' => [], 'error' => "cURL($errno): $err"];
  }

  $rawHeaders = substr($raw, 0, $headerSize);
  $bodyStr    = substr($raw, $headerSize);

  $headersOut = [];
  foreach (preg_split("/\r\n\r\n|\n\n|\r\r/", trim($rawHeaders)) as $block) {
    $lines = preg_split("/\r\n|\n|\r/", trim($block));
    foreach ($lines as $line) {
      if (strpos($line, ':') !== false) {
        [$k, $v] = explode(':', $line, 2);
        $headersOut[trim($k)] = trim($v);
      }
    }
  }

  return ['status' => $status, 'body' => $bodyStr, 'headers' => $headersOut];
}

function header_value(array $headers, string $name): ?string {
  foreach ($headers as $k => $v) {
    if (strcasecmp($k, $name) === 0) return $v;
  }
  return null;
}

function plan_id_from_location(?string $location): int {
  if (!$location) return 0;
  if (preg_match('~/plans/(\d+)~', $location, $m)) return (int)$m[1];
  return 0;
}

function find_plan_id_in_list(array $listJson, array $needle): int {
  $items = [];
  if (isset($listJson['data']) && is_array($listJson['data'])) {
    $items = array_values($listJson['data']);
    if (isset($listJson['data']['plans']) && is_array($listJson['data']['plans'])) {
      $items = $listJson['data']['plans'];
    }
  } elseif (is_array($listJson)) {
    $items = $listJson;
  }

  $keys = ['start_date','pension_scheme','fund_quote','health_company','afc','disability','invalidity','retired'];

  foreach ($items as $p) {
    if (!is_array($p)) continue;
    $id = (int)($p['id'] ?? 0);
    if ($id <= 0) continue;

    $ok = true;
    foreach ($keys as $k) {
      if (!array_key_exists($k, $needle)) continue;
      if (!array_key_exists($k, $p)) { $ok = false; break; }
      if ((string)$p[$k] !== (string)$needle[$k]) { $ok = false; break; }
    }
    if ($ok) return $id;
  }

  return 0;
}


function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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

function rut_key(?string $rut): string {
    $rut = (string)$rut;
    $rut = trim($rut);
    if ($rut === '') return '';
    $rut = preg_replace('/[^0-9kK]/', '', $rut);
    return strtoupper($rut);
}

function rut_pretty(?string $rut): string {
    $k = rut_key($rut);
    if ($k === '') return '';
    $dv = substr($k, -1);
    $num = substr($k, 0, -1);
    if ($num === '') return $k;
    return $num . '-' . $dv;
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

function norm_payment_period(?string $p): ?string {
    $p = mb_strtolower(trim((string)$p), 'UTF-8');
    if($p==='') return null;
    if($p==='m' || strpos($p,'mensual')!==false) return 'mensual';
    if($p==='q' || strpos($p,'quincena')!==false) return 'quincenal';
    if($p==='s' || strpos($p,'semanal')!==false) return 'semanal';
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

/**
 * Normaliza bancos ADP → EXACTO string permitido por BUK.
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
// Logs (solo caídos en masivo; en manual guardamos siempre)
// ============================================================
function save_log(string $type, string $key, $data): string {
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
    $ts = date('Ymd_His');
    $safeKey = preg_replace('/[^a-zA-Z0-9_\-:.|]/','_', $key);
    $file = sprintf('%s/%s_%s_%s.json', LOG_DIR, $type, $safeKey, $ts);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    return $file;
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
            return [
                'ok'=>false, 'code'=>0, 'error'=>'JSON_ENCODE_FAIL',
                'body'=>'JSON_ENCODE_FAIL: '.$jsonErr,
                'headers'=>[],
                'url'=>$url, 'variant'=>'json_fail'
            ];
        }
    }

    $ch  = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,

        // 👇 clave: capturar headers
        CURLOPT_HEADER         => true,

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

    $raw = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($err) {
        return [
            'ok'=>false,
            'code'=>0,
            'error'=>$err,
            'body'=>'Error de cURL: '.$err,
            'headers'=>[],
            'url'=>$url,
            'variant'=>'curl_error'
        ];
    }

    $rawHeaders = substr((string)$raw, 0, $headerSize);
    $respBody   = substr((string)$raw, $headerSize);

    // parse headers (puede venir más de un bloque por redirects)
    $headersOut = [];
    foreach (preg_split("/\r\n\r\n|\n\n|\r\r/", trim($rawHeaders)) as $block) {
        $lines = preg_split("/\r\n|\n|\r/", trim($block));
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $headersOut[trim($k)] = trim($v);
            }
        }
    }

    $ok = ($code >= 200 && $code < 300);

    return [
        'ok'      => $ok,
        'code'    => $code,
        'error'   => null,
        'body'    => ($respBody !== false ? $respBody : ''),
        'headers' => $headersOut,  // 👈 NUEVO (aquí viene Location)
        'url'     => $url,
        'variant' => $ok ? 'ok' : 'http_error'
    ];
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



function map_empresa_sistema_a_buk(?int $empresaSistema): ?int {
  if ($empresaSistema === null) return null;

  // tu mapping simple
  if ($empresaSistema === 3) return 2;
  if ($empresaSistema === 2) return 3;
  if ($empresaSistema === 1) return 1;

  return null;
}

function resolver_company_id_buk(?int $empresaAdp, $credencial): ?int {
  if ($empresaAdp === null) return null;

  // Caso especial DAMEC
  if ((int)$empresaAdp === 101) {
    $c = is_numeric($credencial) ? (int)$credencial : null;
    return map_empresa_sistema_a_buk($c);
  }

  return map_empresa_sistema_a_buk((int)$empresaAdp);
}

function db_set_company_buk(clsConexion $db, string $rut, ?int $bukCompanyId, string $estado): void {
  // ✅ anti-caídas: si las columnas no existen, no hacemos nada
  if (!table_has_column($db, 'adp_empleados', 'buk_company_id')) return;
  if (!table_has_column($db, 'adp_empleados', 'company_buk')) return;

  $rutEsc = $db->real_escape_string($rut);
  $bukVal = ($bukCompanyId === null) ? "NULL" : (int)$bukCompanyId;
  $estadoEsc = $db->real_escape_string($estado);

  $db->ejecutar("
    UPDATE adp_empleados
    SET buk_company_id = {$bukVal},
        company_buk = '{$estadoEsc}'
    WHERE Rut = '{$rutEsc}'
    LIMIT 1
  ");
}
// ============================================================
// Carga fila completa desde BD (para payloads)
// ============================================================
function load_employee_row_by_rut(clsConexion $db, string $rut): ?array {
    $rutEsc = $db->real_escape_string($rut);
    $rows = $db->consultar("SELECT * FROM adp_empleados WHERE Rut = '{$rutEsc}' LIMIT 1");
    if (is_array($rows) && !empty($rows[0]) && is_array($rows[0])) return $rows[0];
    return null;
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
 * ✅ areaMap[UnidadADP(int)] = buk_area_id  (desde buk_jerarquia nivel unidad)
 */
function load_area_map_from_db(clsConexion $db): array {
    $table = 'buk_jerarquia';

    $colNivel = table_has_column($db, $table, 'nivel') ? 'nivel' :
                (table_has_column($db, $table, 'profundidad') ? 'profundidad' : null);

    $colCodigo = table_has_column($db, $table, 'codigo_adp') ? 'codigo_adp' :
                 (table_has_column($db, $table, 'unidad_adp') ? 'unidad_adp' : null);

    $colEstado = table_has_column($db, $table, 'estado') ? 'estado' : null;

    $colBukId = table_has_column($db, $table, 'buk_area_id') ? 'buk_area_id' :
                (table_has_column($db, $table, 'id_buk_area') ? 'id_buk_area' : null);

    if (!$colNivel || !$colCodigo || !$colBukId) return [];

    $nivelUnidad = ($colNivel === 'profundidad') ? 2 : 3;

    $where = "WHERE `$colBukId` IS NOT NULL AND `$colBukId` > 0 AND `$colNivel` = ".(int)$nivelUnidad;
    if ($colEstado) $where .= " AND `$colEstado`='mapeado'";
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
// JEFES
// ============================================================
function load_employee_min_by_rut(clsConexion $db, string $rut): ?array {
    $rutEsc = $db->real_escape_string($rut);
    $rows = $db->consultar("
        SELECT Rut, Estado, buk_emp_id
        FROM adp_empleados
        WHERE Rut = '{$rutEsc}'
        LIMIT 1
    ");
    return (is_array($rows) && !empty($rows[0]) && is_array($rows[0])) ? $rows[0] : null;
}

/**
 * Resuelve leader_id (Buk employee id del jefe) según reglas:
 * - Jefe vacío o jefe inactivo => usa FALLBACK_BOSS_RUT (debe existir y estar creado en Buk)
 * - Jefe activo => si buk_emp_id existe se usa, si no => error (no enviar JOB)
 */
function resolve_leader_id_for_row(clsConexion $db, array $empRow): array {
    $bossRutRaw = trim((string)($empRow['Jefe'] ?? ''));
    $bossRut = $bossRutRaw;

    // regla: si viene vacío => fallback
    if ($bossRut === '') {
        $bossRut = FALLBACK_BOSS_RUT;
        $bossRow = load_employee_min_by_rut($db, $bossRut);
        if (!$bossRow || (int)($bossRow['buk_emp_id'] ?? 0) <= 0) {
            return [
                'ok' => false,
                'leader_id' => null,
                'msg' => "Jefe vacío: se requiere jefe comodín (".FALLBACK_BOSS_RUT.") creado en Buk, pero no existe o no tiene buk_emp_id.",
                'boss_rut_used' => $bossRut,
            ];
        }
        return [
            'ok' => true,
            'leader_id' => (int)$bossRow['buk_emp_id'],
            'msg' => 'Jefe vacío: usando jefe comodín.',
            'boss_rut_used' => $bossRut,
        ];
    }

    // trae el jefe desde BD
    $bossRow = load_employee_min_by_rut($db, $bossRut);

    // si no existe en BD => fallback (tu regla dice null/inactivo, pero "no existe" conviene tratarlo como fallback)
    if (!$bossRow) {
        $bossRut = FALLBACK_BOSS_RUT;
        $fallbackRow = load_employee_min_by_rut($db, $bossRut);
        if (!$fallbackRow || (int)($fallbackRow['buk_emp_id'] ?? 0) <= 0) {
            return [
                'ok' => false,
                'leader_id' => null,
                'msg' => "Jefe {$bossRutRaw} no existe en BD; se requiere jefe comodín (".FALLBACK_BOSS_RUT.") creado en Buk, pero no existe o no tiene buk_emp_id.",
                'boss_rut_used' => $bossRut,
            ];
        }
        return [
            'ok' => true,
            'leader_id' => (int)$fallbackRow['buk_emp_id'],
            'msg' => "Jefe {$bossRutRaw} no existe en BD: usando jefe comodín.",
            'boss_rut_used' => $bossRut,
        ];
    }

    $bossEstado = (string)($bossRow['Estado'] ?? '');

    // si jefe inactivo => fallback
    if ($bossEstado !== 'A') {
        $bossRut = FALLBACK_BOSS_RUT;
        $fallbackRow = load_employee_min_by_rut($db, $bossRut);
        if (!$fallbackRow || (int)($fallbackRow['buk_emp_id'] ?? 0) <= 0) {
            return [
                'ok' => false,
                'leader_id' => null,
                'msg' => "Jefe {$bossRutRaw} está inactivo; se requiere jefe comodín (".FALLBACK_BOSS_RUT.") creado en Buk, pero no existe o no tiene buk_emp_id.",
                'boss_rut_used' => $bossRut,
            ];
        }
        return [
            'ok' => true,
            'leader_id' => (int)$fallbackRow['buk_emp_id'],
            'msg' => "Jefe {$bossRutRaw} inactivo: usando jefe comodín.",
            'boss_rut_used' => $bossRut,
        ];
    }

    // jefe activo: debe estar creado en Buk
    $bossBukId = (int)($bossRow['buk_emp_id'] ?? 0);
    if ($bossBukId <= 0) {
        return [
            'ok' => false,
            'leader_id' => null,
            'msg' => "Jefe {$bossRutRaw} está ACTIVO pero aún NO está creado en Buk (sin buk_emp_id).",
            'boss_rut_used' => $bossRutRaw,
        ];
    }

    return [
        'ok' => true,
        'leader_id' => $bossBukId,
        'msg' => 'Jefe activo y creado en Buk.',
        'boss_rut_used' => $bossRutRaw,
    ];
}


// ============================================================
// Payload EMP
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

    $acc_type_raw = pick($r, ['Tipo Cuenta fpago1','Tipo de Cuenta','account_type']);

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
// PLAN (PlanInputCountry)
// ============================================================
function plan_norm_upper(?string $s): string {
    $s = (string)$s;
    $s = trim($s);
    $s = mb_strtoupper($s, 'UTF-8');
    $s = preg_replace('/\s+/', ' ', $s);
    return $s ?: '';
}

function plan_map_pension_scheme(array $r): string {
    $afp = plan_norm_upper(pick($r, ['Descripcion AFP','Descripción AFP','AFP','afp']));
    $cod = trim(pick($r, ['Codigo AFP','Código AFP','cod_afp']));
    if ($afp === '' && $cod === '') return 'no_cotiza';
    if (str_contains($afp, 'SIN') && str_contains($afp, 'AFP')) return 'no_cotiza';
    if (str_contains($afp, 'NO COTIZA')) return 'no_cotiza';

    if (str_contains($afp, 'I.N.P') || str_contains($afp, 'INP') || str_contains($afp, 'EMPART') || str_contains($afp, 'SERVICIOS DE SEGURO SOCIAL') || str_contains($afp, 'CAPREMER') || str_contains($afp, 'TRIOMAR')) {
        return 'ips';
    }

    $afps = ['CAPITAL','CUPRUM','HABITAT','MODELO','PLANVITAL','PROVIDA','UNO'];
    foreach ($afps as $x) {
        if (str_contains($afp, $x)) return 'afp';
    }
    return 'no_cotiza';
}

function plan_map_fund_quote(array $r, string $pension_scheme): ?string {
    if (!in_array($pension_scheme, ['afp','ips'], true)) return null;

    $afp = plan_norm_upper(pick($r, ['Descripcion AFP','Descripción AFP','AFP','afp']));

    if (str_contains($afp, 'CAPITAL')) return 'capital';
    if (str_contains($afp, 'CUPRUM')) return 'cuprum';
    if (str_contains($afp, 'HABITAT')) return 'habitat';
    if (str_contains($afp, 'MODELO')) return 'modelo';
    if (str_contains($afp, 'PLANVITAL') || str_contains($afp, 'PLAN VITAL')) return 'planvital';
    if (str_contains($afp, 'PROVIDA') || str_contains($afp, 'PRO VIDA')) return 'provida';
    if (str_contains($afp, 'UNO') || str_contains($afp, 'AFP UNO')) return 'uno';

    if (str_contains($afp, 'CAPREMER')) return 'capremer_regimen_1';
    if (str_contains($afp, 'TRIOMAR')) return 'triomar_regimen_1';
    if (str_contains($afp, 'EMPART')) return 'empart_regimen_1';

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
    return 'no_cotiza_salud';
}

function plan_map_afc(array $r): string {
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

        'disability'             => false,
        'invalidity'             => 'no',
        'youth_employment_subsidy'=> false,
        'foreign_technician'     => false,
        'quote_increase_one_percent' => false,
    ];

    if ($fund_quote) $payload['fund_quote'] = $fund_quote;

    if ($pension_scheme === 'ips' && $afc !== 'no_cotiza' && $fund_quote) {
        $afpQuotes = ['capital','cuprum','habitat','modelo','planvital','provida','uno'];
        if (in_array($fund_quote, $afpQuotes, true)) {
            $payload['afp_collector'] = 'recauda_'.$fund_quote;
        }
    }

    return $payload;
}

// ============================================================
// JOB
// ============================================================
function build_job_payload(array $r, int $employeeId, int $leaderId, array $areaMap, array $roleMap): array
{
    $areaId = map_area_id_from_row_db($r, $areaMap);
    $roleId = map_role_id_from_row_db($r, $roleMap);

    $start_date = to_iso(pick($r, [
        'start_date',
        'Fecha Inicio',
        'Fecha de Ingreso',
        'Fecha Ingreso',
        'Fecha Contrato'
    ])) ?? date('Y-m-d');

    // -----------------------------
    // Contrato (type_of_contract) + project
    // -----------------------------
    $rawSituacion = norm_txt(pick($r, ['Descripcion Situacion Laboral', 'Descripción Situación Laboral']));
    $typeContract = null;
    $project = null;

    if ($rawSituacion === '' || $rawSituacion === 'NULL') {
        $typeContract = 'Indefinido';

    } elseif (str_contains($rawSituacion, 'CONTRATO INDEFINIDO')) {
        $typeContract = 'Indefinido';

    } elseif (str_contains($rawSituacion, 'CONTRATO A PLAZO FIJO')) {
        $typeContract = 'Plazo fijo';

    } elseif (str_contains($rawSituacion, 'RENOVACION AUTOMATICA') || str_contains($rawSituacion, 'RENOVACIÓN AUTOMÁTICA')) {
        // ✅ nombre válido en Buk
        $typeContract = 'Renovación Automática';

    } elseif (str_contains($rawSituacion, 'EVENTUAL CON CPPT')) {
        $typeContract = 'Obra';
        $project = 'Eventual con Cppt';

    } else {
        $typeContract = 'Indefinido';
    }

    // -----------------------------
    // Fecha término contrato (Buk: end_of_contract)
    // ADP: Fecha de Retiro
    // -----------------------------
    $end_of_contract = to_iso(pick($r, [
        'Fecha de Retiro',
        'Fecha Retiro',
        'Fec_Retiro'
    ]));

    // seguridad: si viene anterior al inicio, no la mandamos
    if ($end_of_contract && $start_date && $end_of_contract < $start_date) {
        $end_of_contract = null;
    }

    // Buk exige término si es fijo o renovación automática
    $requiresEnd = in_array($typeContract, ['Plazo fijo', 'Renovación Automática'], true);

    // -----------------------------
    // Jornada
    // -----------------------------
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

    if (!$typeWorking) $typeWorking = 'ordinaria_art_22';

    // -----------------------------
    // Sueldo
    // -----------------------------
    $salary_liq   = money_to_int(pick($r, ['Sueldo Líquido','Sueldo Liquido','Liquido','Sueldo']));
    $salary_gross = money_to_int(pick($r, ['Sueldo Bruto','Bruto','Renta Bruta','Renta']));
    $wage = $salary_gross ?: $salary_liq ?: 0;
    
    //--------------
    $empresaAdp  = is_numeric(pick($r, ['Empresa'])) ? (int)pick($r, ['Empresa']) : null;
$credencial  = pick($r, ['Credencial']);
$bukCompanyId = resolver_company_id_buk($empresaAdp, $credencial);

// fallback (nunca null)
if (!$bukCompanyId) $bukCompanyId = (int)COMPANY_ID_FOR_JOBS;

    // -----------------------------
    // Payload base
    // -----------------------------
    $payload = [
        '__mapped_ok'   => (bool)($areaId && $roleId),
        '__map_area_id' => $areaId,
        '__map_role_id' => $roleId,

'company_id' => (int)$bukCompanyId,        'location_id'  => (int)DEFAULT_LOCATION_ID,
        'employee_id'  => (int)$employeeId,

        'project' => $project,

        'start_date'       => $start_date,
        'type_of_contract' => $typeContract,

        'periodicity'   => 'mensual',
        'regular_hours' => 44,

        'type_of_working_day' => $typeWorking,

        'area_id'   => (int)$areaId,
        'role_id'   => (int)$roleId,
        'leader_id' => (int)$leaderId,

        'wage'     => (int)$wage,
        'currency' => 'peso',

        '__raw_situacion_laboral' => pick($r, ['Descripcion Situacion Laboral', 'Descripción Situación Laboral']),
        '__raw_horario'           => pick($r, ['Descripcion Horario', 'Descripción Horario']),
        '__other_type_of_working_day' => $otherWorking,
    ];

    // ✅ solo agregar end_of_contract si aplica y existe
    if ($requiresEnd && $end_of_contract) {
        $payload['end_of_contract'] = $end_of_contract;
    }

    return $payload;
}
// ============================================================
// DB marks
// ============================================================

//DB Mark Sindicato1
function db_mark_emp_attr_error(clsConexion $db, string $rut): void {
    $rutEsc = $db->real_escape_string($rut);
    $db->ejecutar("
        UPDATE adp_empleados
        SET estado_buk='emp_attr_error'
        WHERE Rut='{$rutEsc}'
    ");
}
///////


function db_mark_emp_ok(clsConexion $db, string $rut, int $bukEmpId): void {
    $rutEsc = $db->real_escape_string($rut);
    $db->ejecutar("
        UPDATE adp_empleados
        SET buk_emp_id=".(int)$bukEmpId.",
            ficha_buk='ok',
            estado_buk='emp_ok_sin_job'
        WHERE Rut='{$rutEsc}'
    ");
}
function db_mark_emp_error(clsConexion $db, string $rut): void {
    $rutEsc = $db->real_escape_string($rut);
    $db->ejecutar("
        UPDATE adp_empleados
        SET ficha_buk='error',
            estado_buk='emp_error'
        WHERE Rut='{$rutEsc}'
    ");
}
function db_mark_job_ok(clsConexion $db, string $rut, ?int $bukJobId, ?int $bukCargoId): void {
    $rutEsc = $db->real_escape_string($rut);
    $jobVal   = ($bukJobId && $bukJobId > 0) ? (int)$bukJobId : "NULL";
    $cargoVal = ($bukCargoId && $bukCargoId > 0) ? (int)$bukCargoId : "NULL";
    $db->ejecutar("
        UPDATE adp_empleados
        SET buk_job_id={$jobVal},
            buk_cargo_id={$cargoVal},
            job_buk='ok',
            estado_buk='completo'
        WHERE Rut='{$rutEsc}'
    ");
}
function db_mark_job_error(clsConexion $db, string $rut): void {
    $rutEsc = $db->real_escape_string($rut);
    $db->ejecutar("
        UPDATE adp_empleados
        SET job_buk='error',
            estado_buk = CASE
                WHEN estado_buk = 'completo' THEN 'completo'
                WHEN estado_buk = 'emp_plan_ok' THEN 'emp_plan_ok_job_error'
                ELSE 'emp_ok_job_error'
            END
        WHERE Rut='{$rutEsc}'
    ");
}

function db_mark_skip_mapping(clsConexion $db, string $rut): void {
    $rutEsc = $db->real_escape_string($rut);
    $db->ejecutar("UPDATE adp_empleados SET estado_buk='emp_ok_sin_job' WHERE Rut='{$rutEsc}'");
}

function db_mark_plan_ok(clsConexion $db, string $rut, ?int $bukPlanId = null): void {
    $rutEsc = $db->real_escape_string($rut);
    $planVal = ($bukPlanId && $bukPlanId > 0) ? (int)$bukPlanId : "NULL";
    $db->ejecutar("
        UPDATE adp_empleados
        SET buk_plan_id={$planVal},
            plan_buk='ok',
            estado_buk = CASE
                WHEN estado_buk = 'completo' THEN 'completo'
                ELSE 'emp_plan_ok'
            END
        WHERE Rut='{$rutEsc}'
    ");
}

function db_mark_plan_error(clsConexion $db, string $rut): void {
    $rutEsc = $db->real_escape_string($rut);
    $db->ejecutar("
        UPDATE adp_empleados
        SET plan_buk='error',
            estado_buk = CASE
                WHEN estado_buk = 'completo' THEN 'completo'
                ELSE 'emp_plan_error'
            END
        WHERE Rut='{$rutEsc}'
    ");
}


function ensure_logs_dir(): void {
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
}

/**
 * Pipeline completo EMP -> PLAN -> JOB
 */
function run_pipeline_all(
    clsConexion $db,
    array $rowFull,
    array $AREA_MAP,
    array $ROLE_MAP,
    string $mode = 'manual'
): array {
    ensure_logs_dir();

    $rut = trim(pick($rowFull, ['Rut','RUT']));
    $key = $rut !== '' ? $rut : ('ROW_'.md5(json_encode($rowFull)));

    $existingEmpId = (int)($rowFull['buk_emp_id'] ?? 0);
    $empId = $existingEmpId > 0 ? $existingEmpId : 0;

    $out = [
        'ok' => false,
        'stage' => null,
        'http' => 0,
        'msg' => '',
        'emp' => ['ok'=>false,'id'=>$empId,'payload'=>null,'resp'=>null],
        'plan'=> ['ok'=>false,'id'=>null,'payload'=>null,'resp'=>null],
        'job' => ['ok'=>false,'id'=>null,'payload'=>null,'resp'=>null,'skip'=>false],
    ];

    // ---------------- EMP ----------------
    if ($empId <= 0) {
        $empPayload = build_employee_payload($rowFull);
        $resEmp = buk_api_request('POST', BUK_EMP_CREATE_PATH, $empPayload);

        $out['emp']['payload'] = $empPayload;
        $out['emp']['resp'] = $resEmp;

        if (!$resEmp['ok']) {
            db_mark_emp_error($db, $rut);

            if ($mode === 'bulk') {
                save_log('bulk_fail_emp_payload', $key, $empPayload);
                save_log('bulk_fail_emp_response', $key, $resEmp);
            } else {
                save_log('manual_emp_payload', $key, $empPayload);
                save_log('manual_emp_response', $key, $resEmp);
            }

            $out['stage'] = 'EMP';
            $out['http']  = (int)$resEmp['code'];
            $out['msg']   = parse_msg($resEmp['body']).' ['.$resEmp['variant'].']';
            return $out;
        }

        $j = json_decode($resEmp['body'], true);
        $empId = (int)($j['data']['id'] ?? 0);

        if ($empId > 0) {
            db_mark_emp_ok($db, $rut, $empId);
            $out['emp']['ok'] = true;
            $out['emp']['id'] = $empId;
        } else {
            db_mark_emp_error($db, $rut);
            if ($mode === 'bulk') {
                save_log('bulk_fail_emp_response_noid', $key, $resEmp);
            }
            $out['stage'] = 'EMP';
            $out['http']  = (int)$resEmp['code'];
            $out['msg']   = 'EMP OK pero sin data.id';
            return $out;
        }
    } else {
        $out['emp']['ok'] = true;
    }
    // --------------- SINDICATO (Custom Attributes) Sindicato1 ----------------
$attrsSindicato = build_sindicato_custom_attrs($rowFull);

if (!empty($attrsSindicato) && $empId > 0) {
    $resAttr = buk_patch_custom_attributes($empId, $attrsSindicato);

    // (opcional) guardar en el OUT para depurar
    $out['emp']['attr'] = [
        'ok' => (bool)$resAttr['ok'],
        'payload' => $attrsSindicato,
        'resp' => $resAttr,
    ];

    if (!$resAttr['ok']) {
        db_mark_emp_attr_error($db, $rut);

        if ($mode === 'bulk') {
            save_log('bulk_fail_emp_attr_payload', $key, $attrsSindicato);
            save_log('bulk_fail_emp_attr_response', $key, $resAttr);
        } else {
            save_log('manual_emp_attr_payload', $key, $attrsSindicato);
            save_log('manual_emp_attr_response', $key, $resAttr);
        }

        $out['stage'] = 'EMP_ATTR';
        $out['http']  = (int)$resAttr['code'];
        $out['msg']   = parse_msg($resAttr['body']).' ['.$resAttr['variant'].']';
        return $out; // corta para que el operador reintente
    }
}

    

  
    // ---------------- PLAN ----------------
$planPayload = build_plan_payload($rowFull);
$resPlan = buk_api_request('POST', sprintf(BUK_PLAN_CREATE_PATH, $empId), $planPayload);

$out['plan']['payload'] = $planPayload;
$out['plan']['resp'] = $resPlan;

if (!$resPlan['ok']) {
    db_mark_plan_error($db, $rut);

    if ($mode === 'bulk') {
        save_log('bulk_fail_plan_payload', $key, $planPayload);
        save_log('bulk_fail_plan_response', $key, $resPlan);
    } else {
        save_log('manual_plan_payload', $key, $planPayload);
        save_log('manual_plan_response', $key, $resPlan);
    }

    $out['stage'] = 'PLAN';
    $out['http']  = (int)$resPlan['code'];
    $out['msg']   = parse_msg($resPlan['body']).' ['.$resPlan['variant'].']';
    return $out;
}

$out['plan']['ok'] = true;

// ✅ Camino A: Location header (si viene)
$location = header_value($resPlan['headers'] ?? [], 'Location');
$planId = plan_id_from_location($location);

// ✅ Camino B: listar planes si no vino Location o id
if ($planId <= 0) {
    // endpoint de listado
    $resList = buk_api_request('GET', "/employees/{$empId}/plans", null);

    if (!empty($resList['ok'])) {
        $listJson = json_decode((string)($resList['body'] ?? ''), true);
        if (is_array($listJson)) {
            $planId = find_plan_id_in_list($listJson, $planPayload);
        }
    }
}

$out['plan']['id'] = $planId > 0 ? $planId : null;
db_mark_plan_ok($db, $rut, $planId > 0 ? $planId : null);

// log si no encontramos id
if ($planId <= 0) {
    save_log('plan_ok_but_no_id', $key, [
        'create_response' => $resPlan,
        'list_response'   => $resList ?? null,
        'payload'         => $planPayload,
    ]);
}

//-----JEFE-LEADER

// ✅ resolver leader_id dinámico (JEFE)
$leaderRes = resolve_leader_id_for_row($db, $rowFull);
if (!$leaderRes['ok']) {
    if ($mode === 'bulk') save_log('bulk_fail_leader_resolve', $key, $leaderRes);
    else save_log('manual_fail_leader_resolve', $key, $leaderRes);

    $out['stage'] = 'LEADER';
    $out['http']  = 0;
    $out['msg']   = $leaderRes['msg'] ?? 'No se pudo resolver leader_id';
    return $out;
}
$leaderId = (int)$leaderRes['leader_id'];

// ✅ construir JOB con leaderId correcto
$jobPayload = build_job_payload($rowFull, $empId, $leaderId, $AREA_MAP, $ROLE_MAP);

$bukCompanyIdForDb = isset($jobPayload['company_id']) ? (int)$jobPayload['company_id'] : null;
if ($bukCompanyIdForDb) db_set_company_buk($db, $rut, $bukCompanyIdForDb, 'pendiente');
else db_set_company_buk($db, $rut, null, 'error');

// ---------------- ----------------


    if (empty($jobPayload['__mapped_ok'])) {
        db_mark_skip_mapping($db, $rut);

        if ($mode === 'bulk') {
            save_log('bulk_skip_job_mapping', $key, $jobPayload);
        }

        $out['job']['skip'] = true;
        $out['stage'] = 'JOB_SKIP';
        $out['http']  = 0;
        $out['msg']   = 'JOB: NO_ENVIADO (mapping BD incompleto) — Unidad/Cargo sin mapeo.';
        $out['ok'] = false;
        db_set_company_buk($db, $rut, $bukCompanyIdForDb, 'ok');
        return $out;
    }

    $other = $jobPayload['__other_type_of_working_day'] ?? null;

    unset(
        $jobPayload['__mapped_ok'],
        $jobPayload['__map_area_id'],
        $jobPayload['__map_role_id'],
        $jobPayload['__raw_situacion_laboral'],
        $jobPayload['__raw_horario'],
        $jobPayload['__other_type_of_working_day']
    );

    if (($jobPayload['type_of_working_day'] ?? '') === 'otros' && $other) {
        $jobPayload['other_type_of_working_day'] = $other;
    }

    $resJob = buk_api_request('POST', sprintf(BUK_JOB_CREATE_PATH, $empId), $jobPayload);

    $out['job']['payload'] = $jobPayload;
    $out['job']['resp'] = $resJob;

    if (!$resJob['ok']) {
        db_mark_job_error($db, $rut);

        if ($mode === 'bulk') {
            save_log('bulk_fail_job_payload', $key, $jobPayload);
            save_log('bulk_fail_job_response', $key, $resJob);
        } else {
            save_log('manual_job_payload', $key, $jobPayload);
            save_log('manual_job_response', $key, $resJob);
        }

        $out['stage'] = 'JOB';
        $out['http']  = (int)$resJob['code'];
        $out['msg']   = parse_msg($resJob['body']).' ['.$resJob['variant'].']';
        db_set_company_buk($db, $rut, $bukCompanyIdForDb, 'error');
        return $out;
    }

    $jid = null;
    $jj = json_decode($resJob['body'], true);
    if (is_array($jj)) $jid = $jj['data']['id'] ?? null;

    db_mark_job_ok($db, $rut, $jid ? (int)$jid : null, isset($jobPayload['role_id']) ? (int)$jobPayload['role_id'] : null);

    $out['job']['ok'] = true;
    $out['job']['id'] = $jid ? (int)$jid : null;

    $out['ok'] = true;
    $out['stage'] = 'DONE';
    $out['http']  = (int)$resJob['code'];
    $out['msg']   = 'EMP + PLAN + JOB OK';
    return $out;
}

// ============================================================
// Pasos individuales para masivos arriba
// ============================================================
function run_step_emp_only(clsConexion $db, array $rowFull, string $mode='bulk'): array {
    ensure_logs_dir();

    $rut = trim(pick($rowFull, ['Rut','RUT']));
    $key = $rut !== '' ? $rut : ('ROW_'.md5(json_encode($rowFull)));

    $existingEmpId = (int)($rowFull['buk_emp_id'] ?? 0);
    if ($existingEmpId > 0) {
        return ['ok'=>true,'stage'=>'EMP','http'=>200,'msg'=>'EMP ya existe (no se re-crea)','emp_id'=>$existingEmpId];
    }

    $payload = build_employee_payload($rowFull);
    $res = buk_api_request('POST', BUK_EMP_CREATE_PATH, $payload);

    if (!$res['ok']) {
        db_mark_emp_error($db, $rut);
        if ($mode === 'bulk') {
            save_log('bulk_fail_emp_payload', $key, $payload);
            save_log('bulk_fail_emp_response', $key, $res);
        } else {
            save_log('manual_emp_payload', $key, $payload);
            save_log('manual_emp_response', $key, $res);
        }
        return ['ok'=>false,'stage'=>'EMP','http'=>(int)$res['code'],'msg'=>parse_msg($res['body']).' ['.$res['variant'].']'];
    }

    $j = json_decode($res['body'], true);
    $empId = (int)($j['data']['id'] ?? 0);
    if ($empId > 0) {
        db_mark_emp_ok($db, $rut, $empId);
        return ['ok'=>true,'stage'=>'EMP','http'=>(int)$res['code'],'msg'=>'EMP OK id='.$empId,'emp_id'=>$empId];
    }

    db_mark_emp_error($db, $rut);
    if ($mode === 'bulk') save_log('bulk_fail_emp_response_noid', $key, $res);
    return ['ok'=>false,'stage'=>'EMP','http'=>(int)$res['code'],'msg'=>'EMP OK pero sin data.id'];
}

function run_step_plan_only(clsConexion $db, array $rowFull, array $AREA_MAP, array $ROLE_MAP, string $mode='bulk'): array {
    ensure_logs_dir();

    $rut = trim(pick($rowFull, ['Rut','RUT']));
    $key = $rut !== '' ? $rut : ('ROW_'.md5(json_encode($rowFull)));

    $empId = (int)($rowFull['buk_emp_id'] ?? 0);
    if ($empId <= 0) {
        return ['ok'=>false,'stage'=>'PLAN','http'=>0,'msg'=>'PLAN: falta buk_emp_id (crea EMP primero)'];
    }

    $payload = build_plan_payload($rowFull);
    $res = buk_api_request('POST', sprintf(BUK_PLAN_CREATE_PATH, $empId), $payload);

    if (!$res['ok']) {
        if ($mode === 'bulk') {
            save_log('bulk_fail_plan_payload', $key, $payload);
            save_log('bulk_fail_plan_response', $key, $res);
        } else {
            save_log('manual_plan_payload', $key, $payload);
            save_log('manual_plan_response', $key, $res);
        }
        return ['ok'=>false,'stage'=>'PLAN','http'=>(int)$res['code'],'msg'=>parse_msg($res['body']).' ['.$res['variant'].']'];
    }

    // ✅ si ok, intentar sacar planId
    $location = header_value($res['headers'] ?? [], 'Location');
    $planId = plan_id_from_location($location);

    if ($planId <= 0) {
        $resList = buk_api_request('GET', "/employees/{$empId}/plans", null);
        if (!empty($resList['ok'])) {
            $listJson = json_decode((string)($resList['body'] ?? ''), true);
            if (is_array($listJson)) $planId = find_plan_id_in_list($listJson, $payload);
        }
    }

    db_mark_plan_ok($db, $rut, $planId > 0 ? $planId : null);

    return [
        'ok'=>true,
        'stage'=>'PLAN',
        'http'=>(int)$res['code'],
        'msg'=>'PLAN OK'.($planId ? (' id='.$planId) : '')
    ];
}


function run_step_job_only(clsConexion $db, array $rowFull, array $AREA_MAP, array $ROLE_MAP, string $mode='bulk'): array {
    ensure_logs_dir();

    $rut = trim(pick($rowFull, ['Rut','RUT']));
    $key = $rut !== '' ? $rut : ('ROW_'.md5(json_encode($rowFull)));

    $empId = (int)($rowFull['buk_emp_id'] ?? 0);
    if ($empId <= 0) {
        return ['ok'=>false,'stage'=>'JOB','http'=>0,'msg'=>'JOB: falta buk_emp_id (crea EMP primero)'];
    }

$leaderRes = resolve_leader_id_for_row($db, $rowFull);
if (!$leaderRes['ok']) {
    if ($mode === 'bulk') save_log('bulk_fail_leader_resolve', $key, $leaderRes);
    else save_log('manual_fail_leader_resolve', $key, $leaderRes);
    return ['ok'=>false,'stage'=>'LEADER','http'=>0,'msg'=>$leaderRes['msg'] ?? 'No se pudo resolver leader_id'];
}
$leaderId = (int)$leaderRes['leader_id'];

$jobPayload = build_job_payload($rowFull, $empId, $leaderId, $AREA_MAP, $ROLE_MAP);

$bukCompanyIdForDb = isset($jobPayload['company_id']) ? (int)$jobPayload['company_id'] : null;
if ($bukCompanyIdForDb) db_set_company_buk($db, $rut, $bukCompanyIdForDb, 'pendiente');
else db_set_company_buk($db, $rut, null, 'error');


    if (empty($jobPayload['__mapped_ok'])) {
        db_mark_skip_mapping($db, $rut);
        if ($mode === 'bulk') save_log('bulk_skip_job_mapping', $key, $jobPayload);
        return ['ok'=>false,'stage'=>'JOB_SKIP','http'=>0,'msg'=>'JOB: NO_ENVIADO (mapping BD incompleto) — Unidad/Cargo sin mapeo.'];
    }

    $other = $jobPayload['__other_type_of_working_day'] ?? null;

    unset(
        $jobPayload['__mapped_ok'],
        $jobPayload['__map_area_id'],
        $jobPayload['__map_role_id'],
        $jobPayload['__raw_situacion_laboral'],
        $jobPayload['__raw_horario'],
        $jobPayload['__other_type_of_working_day']
    );

    if (($jobPayload['type_of_working_day'] ?? '') === 'otros' && $other) {
        $jobPayload['other_type_of_working_day'] = $other;
    }

    $res = buk_api_request('POST', sprintf(BUK_JOB_CREATE_PATH, $empId), $jobPayload);

    if (!$res['ok']) {
        db_mark_job_error($db, $rut);
        if ($mode === 'bulk') {
            save_log('bulk_fail_job_payload', $key, $jobPayload);
            save_log('bulk_fail_job_response', $key, $res);
        } else {
            save_log('manual_job_payload', $key, $jobPayload);
            save_log('manual_job_response', $key, $res);
        }
        return ['ok'=>false,'stage'=>'JOB','http'=>(int)$res['code'],'msg'=>parse_msg($res['body']).' ['.$res['variant'].']'];
    }

    $jid = null;
    $jj = json_decode($res['body'], true);
    if (is_array($jj)) $jid = $jj['data']['id'] ?? null;

    db_mark_job_ok($db, $rut, $jid ? (int)$jid : null, isset($jobPayload['role_id']) ? (int)$jobPayload['role_id'] : null);
    return ['ok'=>true,'stage'=>'JOB','http'=>(int)$res['code'],'msg'=>'JOB OK'.($jid?(' id='.$jid):'')];
}

// ============================================================
// ------------------ FILTROS (igual que tu index) ------------------
// ============================================================
$rutFiltro     = isset($_GET['rut']) ? trim($_GET['rut']) : '';
$tipoFiltro    = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';
$estadoFiltro  = isset($_GET['estado']) ? trim($_GET['estado']) : '';
$bukFiltro     = isset($_GET['buk_estado']) ? trim($_GET['buk_estado']) : '';

$where = [];
if ($rutFiltro !== '') {
    $rutEsc = $db->real_escape_string($rutFiltro);
    $where[] = "e.Rut LIKE '%{$rutEsc}%'";
}
if ($tipoFiltro !== '') {
    $tipoEsc = $db->real_escape_string($tipoFiltro);
    $where[] = "e.origenadp = '{$tipoEsc}'";
}
if ($estadoFiltro !== '') {
    if ($estadoFiltro === 'A') $where[] = "e.Estado = 'A'";
    elseif ($estadoFiltro === 'INACTIVOS') $where[] = "(e.Estado IS NULL OR e.Estado <> 'A')";
}
if ($bukFiltro !== '') {
    $bukEsc = $db->real_escape_string($bukFiltro);
    $where[] = "COALESCE(e.estado_buk,'no_enviado') = '{$bukEsc}'";
}
$whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

// ------------------ PAGINACIÓN ------------------
$perPageOptions = [25, 50, 100];
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if (!in_array($perPage, $perPageOptions, true)) $perPage = 25;

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// ------------------ ORDENAMIENTO ------------------
$allowedSort = [
    'Rut'            => 'e.Rut',
    'Nombre'         => 'e.Nombres',
    'Apaterno'       => 'e.Apaterno',
    'Amaterno'       => 'e.Amaterno',
    'Estado'         => 'e.Estado',
    'AreaADP'        => 'e.`Descripcion Unidad`',
    'CargoADP'       => 'e.`Descripcion Cargo`',
    'EmpresaADP'     => 'e.`Descripcion Empresa`',
    'CCostoADP'      => 'e.`Centro de Costo`',
    'AreaBuk'        => 'vuni.area_buk',
    'CargoBuk'       => 'vcargo.cargo_buk',
    'CCostoBuk'      => 'vcc.cencos_buk',
    'EstadoBuk'      => 'e.estado_buk',
    'OrigenADP'      => 'e.origenadp',
];

$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'Apaterno';
$dir  = isset($_GET['dir'])  ? strtolower(trim($_GET['dir'])) : 'asc';

if (!isset($allowedSort[$sort])) $sort = 'Apaterno';
if (!in_array($dir, ['asc','desc'], true)) $dir = 'asc';

$orderBySql = $allowedSort[$sort] . ' ' . strtoupper($dir);
$orderBySql .= ", e.Apaterno ASC, e.Amaterno ASC, e.Nombres ASC";

// Helpers URLs
function q(array $extra = []) {
    $params = $_GET;
    foreach ($extra as $k => $v) {
        if ($v === null) unset($params[$k]);
        else $params[$k] = $v;
    }
    return '?' . http_build_query($params);
}
function next_dir($currentSort, $currentDir, $col) {
    if ($currentSort === $col) return ($currentDir === 'asc') ? 'desc' : 'asc';
    return 'asc';
}

// ============================================================
// KPIs
// ============================================================
$kpiGeneral = $db->consultar("
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN Estado = 'A' THEN 1 ELSE 0 END) AS activos,
      SUM(CASE WHEN Estado IS NULL OR Estado <> 'A' THEN 1 ELSE 0 END) AS inactivos
    FROM adp_empleados
");
$totalAll    = (int)($kpiGeneral[0]['total'] ?? 0);
$activosAll  = (int)($kpiGeneral[0]['activos'] ?? 0);
$inactivosAll= (int)($kpiGeneral[0]['inactivos'] ?? 0);

$kpiPorOrigen = $db->consultar("
    SELECT
      COALESCE(origenadp,'SIN_ORIGEN') AS origen,
      COUNT(*) AS total,
      SUM(CASE WHEN Estado = 'A' THEN 1 ELSE 0 END) AS activos,
      SUM(CASE WHEN Estado IS NULL OR Estado <> 'A' THEN 1 ELSE 0 END) AS inactivos
    FROM adp_empleados
    GROUP BY COALESCE(origenadp,'SIN_ORIGEN')
    ORDER BY origen
");

$kpiBuk = $db->consultar("
    SELECT
      COALESCE(estado_buk,'no_enviado') AS estado_buk,
      COUNT(*) AS total
    FROM adp_empleados
    GROUP BY COALESCE(estado_buk,'no_enviado')
    ORDER BY total DESC
");

$tiposEmpleado = $db->consultar("
    SELECT DISTINCT COALESCE(origenadp,'') AS origen
    FROM adp_empleados
    WHERE origenadp IS NOT NULL AND origenadp <> ''
    ORDER BY origenadp
");
$tiposEmpleado = array_map(fn($r) => $r['origen'], $tiposEmpleado);

$estadosBuk = $db->consultar("
    SELECT DISTINCT COALESCE(estado_buk,'no_enviado') AS estado_buk
    FROM adp_empleados
    ORDER BY estado_buk
");
$estadosBuk = array_map(fn($r) => $r['estado_buk'], $estadosBuk);

// ------------------ TOTAL FILTRADO ------------------
$resTotal = $db->consultar("
    SELECT COUNT(*) AS total
    FROM adp_empleados e
    {$whereSql}
");
$totalRegistros = (int)($resTotal[0]['total'] ?? 0);

$totalPaginas = max(1, (int)ceil($totalRegistros / $perPage));
if ($page > $totalPaginas) $page = $totalPaginas;
$offset = ($page - 1) * $perPage;

// ============================================================
// 🔥 Cargamos mapas UNA VEZ por request (para JOB)
// ============================================================
$AREA_MAP = load_area_map_from_db($db);
$ROLE_MAP = load_role_map_from_db($db);

// ============================================================
// SECCIÓN JEFES (BD COMPLETA, SOLO ACTIVOS, CON NIVELES)
// ============================================================
$rowsActivos = $db->consultar("
    SELECT
        Rut, Jefe, Estado, COALESCE(estado_buk,'no_enviado') AS estado_buk,
        buk_emp_id, buk_cargo_id,
        Nombres, Apaterno, Amaterno
    FROM adp_empleados
    WHERE Estado='A'
");

$activos = [];          // rutKey => row
$bossKeys = [];         // set de rutKey que son jefes
$reportsCount = [];     // bossKey => # reportes directos
$bossToReports = [];    // bossKey => [rutKeyEmpleado...]

if (is_array($rowsActivos)) {
    foreach ($rowsActivos as $r) {
        $rk = rut_key($r['Rut'] ?? '');
        if ($rk === '') continue;
        $activos[$rk] = $r;
    }

    foreach ($rowsActivos as $r) {
        $empKey  = rut_key($r['Rut'] ?? '');
        $bossKey = rut_key($r['Jefe'] ?? '');
        if ($empKey === '' || $bossKey === '') continue;

        // jefe detectado (por subordinados activos)
        $bossKeys[$bossKey] = true;
        $reportsCount[$bossKey] = ($reportsCount[$bossKey] ?? 0) + 1;
        $bossToReports[$bossKey][] = $empKey;
    }
}

// Niveles jerárquicos
$level = []; // bossKey => nivel (1..n)

// inicial nivel 1
foreach (array_keys($bossKeys) as $bk) {
    $rowBoss = $activos[$bk] ?? null;
    $bossBossKey = $rowBoss ? rut_key($rowBoss['Jefe'] ?? '') : '';

    if ($bossBossKey === '' || !isset($activos[$bossBossKey])) {
        $level[$bk] = 1;
    }
}

// propagación
$changed = true;
$guard = 0;
while ($changed && $guard < 50) {
    $changed = false;
    $guard++;

    foreach (array_keys($bossKeys) as $bk) {
        if (isset($level[$bk])) continue;
        $rowBoss = $activos[$bk] ?? null;
        if (!$rowBoss) continue;

        $bossBossKey = rut_key($rowBoss['Jefe'] ?? '');
        if ($bossBossKey === '' || !isset($activos[$bossBossKey])) {
            $level[$bk] = 1;
            $changed = true;
            continue;
        }

        if (isset($level[$bossBossKey])) {
            $level[$bk] = $level[$bossBossKey] + 1;
            $changed = true;
        }
    }
}

foreach (array_keys($bossKeys) as $bk) {
    if (!isset($level[$bk])) $level[$bk] = 99;
}

$bossList = array_keys($bossKeys);
usort($bossList, function($a, $b) use ($level, $reportsCount) {
    $la = $level[$a] ?? 99;
    $lb = $level[$b] ?? 99;
    if ($la !== $lb) return $la <=> $lb;
    $ca = $reportsCount[$a] ?? 0;
    $cb = $reportsCount[$b] ?? 0;
    if ($ca !== $cb) return $cb <=> $ca;
    return strcmp($a, $b);
});



/**
 * Pre-crea jefes activos (y el comodín) para un set de empleados (ruts).
 * Así, cuando corra resolve_leader_id_for_row(), el jefe ya tendrá buk_emp_id.
 */
function precreate_bosses_for_employees(
    clsConexion $db,
    array $employeeRuts,
    array $AREA_MAP,
    array $ROLE_MAP
): void {
    $bossSet = [];

    foreach ($employeeRuts as $rutEmp) {
        $rowEmp = load_employee_row_by_rut($db, $rutEmp);
        if (!$rowEmp) continue;

        $bossRut = trim((string)($rowEmp['Jefe'] ?? ''));
        if ($bossRut !== '') $bossSet[$bossRut] = true;
    }

    // asegurar comodín siempre
    $bossRuts = array_keys($bossSet);
    $bossRuts[] = FALLBACK_BOSS_RUT;
    $bossRuts = array_values(array_unique(array_filter($bossRuts)));

    foreach ($bossRuts as $rutBoss) {
        $rowBoss = load_employee_row_by_rut($db, $rutBoss);
        if (!$rowBoss) continue;

        // Solo pre-creamos jefes activos (si es inactivo, resolve_leader_id_for_row usará el comodín)
        if (($rowBoss['Estado'] ?? '') !== 'A') continue;

        // Precrear completo para que tenga buk_emp_id (y plan/job si mapea)
        run_pipeline_all($db, $rowBoss, $AREA_MAP, $ROLE_MAP, 'bulk');
    }
}

// ============================================================
// HANDLERS POST
// ============================================================
$send_ui = null;      // resultado manual (con payloads)
$bulk_ui = null;      // resultado masivo (solo resumen)
$bulk_fail_list = []; // lista corta

function bulk_summary_init(int $requested): array {
    return [
        'requested' => $requested,
        'done' => 0,
        'failed' => 0,
        'skipped_mapping' => 0,
        'failed_items' => [],
    ];
}

function bulk_add_fail(array &$summary, string $rut, string $stage, int $http, string $msg): void {
    $summary['failed']++;
    $summary['failed_items'][] = [
        'rut'=>$rut,
        'stage'=>$stage,
        'http'=>$http,
        'msg'=>$msg
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ============================================================
    // MASIVOS ARRIBA: EMPLEADOS FILTRADOS (solo activos)
    // ============================================================
    if (in_array($action, ['bulk_filtered_all','bulk_filtered_emp','bulk_filtered_plan','bulk_filtered_job'], true)) {

        // Base: todos los RUT del filtrado, pero SIEMPRE Estado='A'
        $sqlRuts = "
            SELECT e.Rut
            FROM adp_empleados e
            {$whereSql}
            " . (stripos($whereSql, 'WHERE') !== false ? " AND e.Estado='A' " : " WHERE e.Estado='A' ") . "
            ORDER BY {$orderBySql}
        ";
        $rowsR = $db->consultar($sqlRuts);
        
        

        $ruts = [];
        if (is_array($rowsR)) {
            foreach ($rowsR as $rr) {
                $rt = trim((string)($rr['Rut'] ?? ''));
                if ($rt !== '') $ruts[] = $rt;
            }
        }

        $summary = bulk_summary_init(count($ruts));
        
        
        // ✅ Pre-crear jefes SOLO cuando se enviará JOB (all o job)
if (in_array($action, ['bulk_filtered_all', 'bulk_filtered_job'], true) && !empty($ruts)) {
    precreate_bosses_for_employees($db, $ruts, $AREA_MAP, $ROLE_MAP);
}


        foreach ($ruts as $rut) {
            $rowFull = load_employee_row_by_rut($db, $rut);
            if (!$rowFull) {
                bulk_add_fail($summary, $rut, 'LOAD', 0, 'No encontrado en BD');
                continue;
            }

            if ($action === 'bulk_filtered_all') {
                $res = run_pipeline_all($db, $rowFull, $AREA_MAP, $ROLE_MAP, 'bulk');
                if ($res['ok']) $summary['done']++;
                else {
                    if (($res['stage'] ?? '') === 'JOB_SKIP') $summary['skipped_mapping']++;
                    else $summary['failed']++;
                    $summary['failed_items'][] = [
                        'rut'=>$rut,
                        'stage'=>$res['stage'] ?? '-',
                        'http'=>(int)($res['http'] ?? 0),
                        'msg'=>$res['msg'] ?? 'Error'
                    ];
                }
                continue;
            }
           

 

            if ($action === 'bulk_filtered_emp') {
                $res = run_step_emp_only($db, $rowFull, 'bulk');
                if ($res['ok']) $summary['done']++;
                else bulk_add_fail($summary, $rut, $res['stage'] ?? 'EMP', (int)($res['http'] ?? 0), (string)($res['msg'] ?? 'Error'));
                continue;
            }

            if ($action === 'bulk_filtered_plan') {
                // "solo plan (envía planes de solo los que ya están creados)"
                // => solo si ya tiene buk_emp_id
                if ((int)($rowFull['buk_emp_id'] ?? 0) <= 0) {
                    bulk_add_fail($summary, $rut, 'PLAN', 0, 'Skip: sin buk_emp_id (no creado)');
                    continue;
                }

                $res = run_step_plan_only($db, $rowFull, $AREA_MAP, $ROLE_MAP, 'bulk');
                if ($res['ok']) $summary['done']++;
                else bulk_add_fail($summary, $rut, $res['stage'] ?? 'PLAN', (int)($res['http'] ?? 0), (string)($res['msg'] ?? 'Error'));
                continue;
            }

            if ($action === 'bulk_filtered_job') {
                // "solo jobs (envía solo los que ya tienen colaborador y plan creado)"
                // => mínimo validamos EMP creado (buk_emp_id>0). PLAN no se puede confirmar por BD acá, pero respetamos regla de "ya creado" al menos para EMP.
                if ((int)($rowFull['buk_emp_id'] ?? 0) <= 0) {
                    bulk_add_fail($summary, $rut, 'JOB', 0, 'Skip: sin buk_emp_id (no creado)');
                    continue;
                }

                $res = run_step_job_only($db, $rowFull, $AREA_MAP, $ROLE_MAP, 'bulk');
                if ($res['ok']) $summary['done']++;
                else {
                    if (($res['stage'] ?? '') === 'JOB_SKIP') $summary['skipped_mapping']++;
                    else bulk_add_fail($summary, $rut, $res['stage'] ?? 'JOB', (int)($res['http'] ?? 0), (string)($res['msg'] ?? 'Error'));
                }
                continue;
            }
        }

        $bulk_ui = $summary;
        $bulk_fail_list = array_slice($summary['failed_items'], 0, 12);
    }

    // ============================================================
    // MASIVOS ARRIBA: JEFES (solo activos)
    // ============================================================
    if (in_array($action, ['bulk_boss_all','bulk_boss_emp','bulk_boss_plan','bulk_boss_job'], true)) {

        // bossList son keys; aquí convertimos a Rut real desde $activos (solo activos ya)
        $bossRuts = [];
        foreach ($bossList as $bk) {
            if (!isset($activos[$bk])) continue;
            $bossRuts[] = trim((string)($activos[$bk]['Rut'] ?? rut_pretty($bk)));
        }

        $summary = bulk_summary_init(count($bossRuts));
        
        if (in_array($action, ['bulk_boss_all', 'bulk_boss_job'], true) && !empty($bossRuts)) {
    precreate_bosses_for_employees($db, $bossRuts, $AREA_MAP, $ROLE_MAP);
}


        foreach ($bossRuts as $rutBoss) {
            $rowBoss = load_employee_row_by_rut($db, $rutBoss);
            if (!$rowBoss || (($rowBoss['Estado'] ?? '') !== 'A')) {
                bulk_add_fail($summary, $rutBoss, 'LOAD', 0, 'No existe como Activo en BD');
                continue;
            }

            if ($action === 'bulk_boss_all') {
                $res = run_pipeline_all($db, $rowBoss, $AREA_MAP, $ROLE_MAP, 'bulk');
                if ($res['ok']) $summary['done']++;
                else {
                    if (($res['stage'] ?? '') === 'JOB_SKIP') $summary['skipped_mapping']++;
                    else $summary['failed']++;
                    $summary['failed_items'][] = [
                        'rut'=>$rutBoss,
                        'stage'=>$res['stage'] ?? '-',
                        'http'=>(int)($res['http'] ?? 0),
                        'msg'=>$res['msg'] ?? 'Error'
                    ];
                }
                continue;
            }

            if ($action === 'bulk_boss_emp') {
                $res = run_step_emp_only($db, $rowBoss, 'bulk');
                if ($res['ok']) $summary['done']++;
                else bulk_add_fail($summary, $rutBoss, $res['stage'] ?? 'EMP', (int)($res['http'] ?? 0), (string)($res['msg'] ?? 'Error'));
                continue;
            }

            if ($action === 'bulk_boss_plan') {
                if ((int)($rowBoss['buk_emp_id'] ?? 0) <= 0) {
                    bulk_add_fail($summary, $rutBoss, 'PLAN', 0, 'Skip: sin buk_emp_id (no creado)');
                    continue;
                }
                $res = run_step_plan_only($db, $rowBoss, $AREA_MAP, $ROLE_MAP, 'bulk');
                if ($res['ok']) $summary['done']++;
                else bulk_add_fail($summary, $rutBoss, $res['stage'] ?? 'PLAN', (int)($res['http'] ?? 0), (string)($res['msg'] ?? 'Error'));
                continue;
            }

            if ($action === 'bulk_boss_job') {
                if ((int)($rowBoss['buk_emp_id'] ?? 0) <= 0) {
                    bulk_add_fail($summary, $rutBoss, 'JOB', 0, 'Skip: sin buk_emp_id (no creado)');
                    continue;
                }
                $res = run_step_job_only($db, $rowBoss, $AREA_MAP, $ROLE_MAP, 'bulk');
                if ($res['ok']) $summary['done']++;
                else {
                    if (($res['stage'] ?? '') === 'JOB_SKIP') $summary['skipped_mapping']++;
                    else bulk_add_fail($summary, $rutBoss, $res['stage'] ?? 'JOB', (int)($res['http'] ?? 0), (string)($res['msg'] ?? 'Error'));
                }
                continue;
            }
        }

        $bulk_ui = $summary;
        $bulk_fail_list = array_slice($summary['failed_items'], 0, 12);
    }

    // ============================================================
// Manual por fila (solo activos muestran botones en UI; pero si forzan POST igual funciona)
// ============================================================
if (in_array($action, ['send_emp_one','send_plan_one','send_job_one','send_all_one'], true)) {
    $rut = trim((string)($_POST['rut'] ?? ''));
    $rowFull = $rut !== '' ? load_employee_row_by_rut($db, $rut) : null;

    if (!$rowFull) {
        $send_ui = [
            'ok'=>false,
            'title'=>'Envío a Buk',
            'msg'=>'No se encontró el empleado en BD para el RUT indicado.',
            'http'=>0,
            'details'=>null
        ];
    } else {
        $rutKey = trim(pick($rowFull, ['Rut','RUT']));
        ensure_logs_dir();

        // refrescar ids desde BD por si cambiaron
        $existingEmpId = (int)($rowFull['buk_emp_id'] ?? 0);
        $empId = $existingEmpId;

        if ($action === 'send_all_one') {
            // Pipeline completo (ya marca estados en BD)
            $res = run_pipeline_all($db, $rowFull, $AREA_MAP, $ROLE_MAP, 'manual');

            $send_ui = [
                'ok'=>$res['ok'],
                'title'=>$res['ok'] ? 'Envío completo: EMP + PLAN + JOB' : ('Envío detenido en: '.$res['stage']),
                'msg'=>$res['msg'],
                'http'=>$res['http'],
                'details'=>$res
            ];
        }

        // ---------------- EMP ----------------
        if ($action === 'send_emp_one') {
            if ($empId > 0) {
                $send_ui = [
                    'ok'=>true,
                    'title'=>'EMP',
                    'msg'=>'Empleado ya tiene buk_emp_id='.$empId.' (no se re-crea).',
                    'http'=>200,
                    'details'=>['emp'=>['ok'=>true,'id'=>$empId,'payload'=>null,'resp'=>null]]
                ];
            } else {
                $payload = build_employee_payload($rowFull);
                $resEmp = buk_api_request('POST', BUK_EMP_CREATE_PATH, $payload);

                save_log('manual_emp_payload', $rutKey, $payload);
                save_log('manual_emp_response', $rutKey, $resEmp);

                if (!$resEmp['ok']) {
                    db_mark_emp_error($db, $rutKey);
                    $send_ui = [
                        'ok'=>false,
                        'title'=>'EMP',
                        'msg'=>parse_msg($resEmp['body']).' ['.$resEmp['variant'].']',
                        'http'=>$resEmp['code'],
                        'details'=>['payload'=>$payload,'resp'=>$resEmp]
                    ];
                } else {
                    $j = json_decode($resEmp['body'], true);
                    $empId = (int)($j['data']['id'] ?? 0);
                    if ($empId > 0) {
                        db_mark_emp_ok($db, $rutKey, $empId);
                    } else {
                        db_mark_emp_error($db, $rutKey);
                    }

                    $send_ui = [
                        'ok'=>($empId > 0),
                        'title'=>'EMP',
                        'msg'=>($empId > 0) ? ('EMP OK id='.$empId) : 'EMP OK pero sin data.id',
                        'http'=>$resEmp['code'],
                        'details'=>['payload'=>$payload,'resp'=>$resEmp]
                    ];
                }
            }
        }

        // ---------------- PLAN ----------------
        if ($action === 'send_plan_one') {
            if ($empId <= 0) {
                $send_ui = [
                    'ok'=>false,
                    'title'=>'PLAN',
                    'msg'=>'No se puede crear PLAN: falta buk_emp_id (crea EMP primero).',
                    'http'=>0,
                    'details'=>null
                ];
            } else {
                $payload = build_plan_payload($rowFull);
                $resPlan = buk_api_request('POST', sprintf(BUK_PLAN_CREATE_PATH, $empId), $payload);

                save_log('manual_plan_payload', $rutKey, $payload);
                save_log('manual_plan_response', $rutKey, $resPlan);

                // ✅ marcar estado en BD
                if (!$resPlan['ok']) {
                    // Si Buk dice "ya existe un plan", lo tratamos como OK (plan único)
                    $bodyTxt = (string)($resPlan['body'] ?? '');
                    $already = ($resPlan['code'] === 400) && (
                        stripos($bodyTxt, 'ya existe un plan') !== false ||
                        stripos($bodyTxt, 'ya existe') !== false
                    );

                    if ($already) {
                        db_mark_plan_ok($db, $rutKey);
                        $send_ui = [
                            'ok'=>true,
                            'title'=>'PLAN',
                            'msg'=>'PLAN ya existía en Buk (se considera OK).',
                            'http'=>$resPlan['code'],
                            'details'=>['payload'=>$payload,'resp'=>$resPlan]
                        ];
                    } else {
                        db_mark_plan_error($db, $rutKey);
                        $send_ui = [
                            'ok'=>false,
                            'title'=>'PLAN',
                            'msg'=>parse_msg($resPlan['body']).' ['.$resPlan['variant'].']',
                            'http'=>$resPlan['code'],
                            'details'=>['payload'=>$payload,'resp'=>$resPlan]
                        ];
                    }
                } else {
                    db_mark_plan_ok($db, $rutKey);
                    $send_ui = [
                        'ok'=>true,
                        'title'=>'PLAN',
                        'msg'=>'PLAN OK',
                        'http'=>$resPlan['code'],
                        'details'=>['payload'=>$payload,'resp'=>$resPlan]
                    ];
                }
            }
        }

        // ---------------- JOB ----------------
        if ($action === 'send_job_one') {
            if ($empId <= 0) {
                $send_ui = [
                    'ok'=>false,
                    'title'=>'JOB',
                    'msg'=>'No se puede crear JOB: falta buk_emp_id (crea EMP primero).',
                    'http'=>0,
                    'details'=>null
                ];
            } else {
                // ✅ IMPORTANTÍSIMO:
                // NO volvemos a crear PLAN aquí. Si el plan ya existe, Buk devuelve 400.
                // El botón JOB debe concentrarse SOLO en JOB.

                $leaderRes = resolve_leader_id_for_row($db, $rowFull);
                if (!$leaderRes['ok']) {
                    save_log('manual_fail_leader_resolve', $rutKey, $leaderRes);

                    $send_ui = [
                        'ok'=>false,
                        'title'=>'JOB',
                        'msg'=>'No se pudo resolver leader_id: '.($leaderRes['msg'] ?? ''),
                        'http'=>0,
                        'details'=>['leader'=>$leaderRes]
                    ];
                } else {
                    $leaderId = (int)$leaderRes['leader_id'];
                    $jobPayload = build_job_payload($rowFull, $empId, $leaderId, $AREA_MAP, $ROLE_MAP);

                    if (empty($jobPayload['__mapped_ok'])) {
                        db_mark_skip_mapping($db, $rutKey);
                        save_log('manual_skip_job_mapping', $rutKey, $jobPayload);

                        $send_ui = [
                            'ok'=>false,
                            'title'=>'JOB',
                            'msg'=>'JOB: NO_ENVIADO (mapping BD incompleto) — Unidad/Cargo sin mapeo.',
                            'http'=>0,
                            'details'=>['job_preview'=>$jobPayload]
                        ];
                    } else {
                        $other = $jobPayload['__other_type_of_working_day'] ?? null;

                        unset(
                            $jobPayload['__mapped_ok'],
                            $jobPayload['__map_area_id'],
                            $jobPayload['__map_role_id'],
                            $jobPayload['__raw_situacion_laboral'],
                            $jobPayload['__raw_horario'],
                            $jobPayload['__other_type_of_working_day']
                        );

                        if (($jobPayload['type_of_working_day'] ?? '') === 'otros' && $other) {
                            $jobPayload['other_type_of_working_day'] = $other;
                        }

                        $resJob = buk_api_request('POST', sprintf(BUK_JOB_CREATE_PATH, $empId), $jobPayload);

                        save_log('manual_job_payload', $rutKey, $jobPayload);
                        save_log('manual_job_response', $rutKey, $resJob);

                        if (!$resJob['ok']) {
                            db_mark_job_error($db, $rutKey);
                            $send_ui = [
                                'ok'=>false,
                                'title'=>'JOB',
                                'msg'=>parse_msg($resJob['body']).' ['.$resJob['variant'].']',
                                'http'=>$resJob['code'],
                                'details'=>['payload'=>$jobPayload,'resp'=>$resJob]
                            ];
                        } else {
                            $jj = json_decode($resJob['body'], true);
                            $jobId = $jj['data']['id'] ?? null;

                            db_mark_job_ok($db, $rutKey, $jobId ? (int)$jobId : null, isset($jobPayload['role_id']) ? (int)$jobPayload['role_id'] : null);

                            $send_ui = [
                                'ok'=>true,
                                'title'=>'JOB',
                                'msg'=>'JOB OK'.($jobId ? (' id='.$jobId) : ''),
                                'http'=>$resJob['code'],
                                'details'=>['payload'=>$jobPayload,'resp'=>$resJob]
                            ];
                        }
                    }
                }
            }
        }
    }
}


    // ============================================================
    // Masivo seleccionando (checkbox) -> SOLO TODO (EMP+PLAN+JOB) y SOLO activos
    // ============================================================
    if ($action === 'bulk_send_selected_all') {
        $ruts = (array)($_POST['ruts'] ?? []);
        $ruts = array_values(array_filter(array_map('trim', $ruts), fn($x)=>$x!==''));

        if (!empty($ruts)) {
            $in = implode("','", array_map([$db,'real_escape_string'], $ruts));
            $actRows = $db->consultar("SELECT Rut FROM adp_empleados WHERE Estado='A' AND Rut IN ('{$in}')");
            $ruts = [];
            if (is_array($actRows)) {
                foreach ($actRows as $rr) {
                    $rt = trim((string)($rr['Rut'] ?? ''));
                    if ($rt !== '') $ruts[] = $rt;
                }
            }
        }

        $summary = bulk_summary_init(count($ruts));
        
        
        // ✅ PRE-CREAR JEFES para seleccionados (solo si hay ruts)
if (!empty($ruts)) {
    precreate_bosses_for_employees($db, $ruts, $AREA_MAP, $ROLE_MAP);
}




        foreach ($ruts as $rut) {
            $rowFull = load_employee_row_by_rut($db, $rut);
            if (!$rowFull) {
                bulk_add_fail($summary, $rut, 'LOAD', 0, 'No encontrado en BD');
                continue;
            }

            $res = run_pipeline_all($db, $rowFull, $AREA_MAP, $ROLE_MAP, 'bulk');

            if ($res['ok']) {
                $summary['done']++;
            } else {
                if (($res['stage'] ?? '') === 'JOB_SKIP') {
                    $summary['skipped_mapping']++;
                } else {
                    $summary['failed']++;
                }
                $summary['failed_items'][] = [
                    'rut'=>$rut,
                    'stage'=>$res['stage'] ?? '-',
                    'http'=>(int)($res['http'] ?? 0),
                    'msg'=>$res['msg'] ?? 'Error'
                ];
            }
        }

        $bulk_ui = $summary;
        $bulk_fail_list = array_slice($summary['failed_items'], 0, 12);
    }
}

// ============================================================
// DATA TABLA EMPLEADOS
// ============================================================
$sqlEmpleados = "
    SELECT
        e.Rut,
        e.Jefe,
        e.Apaterno,
        e.Amaterno,
        e.Nombres,
        e.Estado,
        e.`Descripcion Cargo`             AS desc_cargo,
        e.`Descripcion Empresa`           AS desc_empresa,
        e.`Centro de Costo`               AS cencos_adp,
        e.`Descripcion Centro de Costo`   AS cencos_adp_desc,
        e.`Descripcion Unidad`            AS desc_unidad_adp,
        e.origenadp,
        COALESCE(e.estado_buk,'no_enviado') AS estado_buk,
        e.buk_emp_id,
        e.buk_cargo_id,

        vuni.id_buk_area                  AS area_buk_id,
        vuni.area_buk                     AS area_buk,
        vuni.area_buk_completo            AS area_buk_completo,

        vcargo.id_buk_cargo               AS cargo_buk_id,
        vcargo.cargo_buk                  AS cargo_buk,

        vcc.id_buk_cencos                 AS cencos_buk_id,
        vcc.cencos_buk                    AS cencos_buk
    FROM adp_empleados e
    LEFT JOIN v_mapeo_unidades_buk_adp       vuni   ON vuni.unidad_adp_codigo = e.Unidad
    LEFT JOIN v_mapeo_cargos_buk_adp         vcargo ON vcargo.cargo_adp       = e.`Descripcion Cargo`
    LEFT JOIN v_mapeo_centros_costo_buk_adp  vcc    ON vcc.cencos_adp         = e.`Centro de Costo`
    {$whereSql}
    ORDER BY {$orderBySql}
    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

$empleados = $db->consultar($sqlEmpleados);

$desde = $totalRegistros > 0 ? $offset + 1 : 0;
$hasta = min($offset + $perPage, $totalRegistros);

// ============================================================
// EXPORT CSV
// ============================================================
$export = isset($_GET['export']) ? trim($_GET['export']) : '';
if ($export === 'csv') {
    $sqlExport = "
        SELECT
            e.Rut,
            CONCAT(COALESCE(e.Nombres,''),' ',COALESCE(e.Apaterno,''),' ',COALESCE(e.Amaterno,'')) AS NombreCompleto,
            e.Estado,
            e.`Descripcion Unidad` AS DivisionArea,
            e.`Descripcion Cargo` AS CargoADP,
            e.`Descripcion Empresa` AS EmpresaADP,
            e.`Centro de Costo` AS CCostoADP,
            COALESCE(vuni.area_buk,'') AS AreaBuk,
            COALESCE(vcargo.cargo_buk,'') AS CargoBuk,
            COALESCE(vcc.cencos_buk,'') AS CCostoBuk,
            COALESCE(e.estado_buk,'no_enviado') AS EstadoBuk,
            COALESCE(e.origenadp,'') AS OrigenADP,
            COALESCE(e.buk_emp_id,'') AS BukEmpId,
            COALESCE(e.buk_cargo_id,'') AS BukJobId
        FROM adp_empleados e
        LEFT JOIN v_mapeo_unidades_buk_adp       vuni   ON vuni.unidad_adp_codigo = e.Unidad
        LEFT JOIN v_mapeo_cargos_buk_adp         vcargo ON vcargo.cargo_adp       = e.`Descripcion Cargo`
        LEFT JOIN v_mapeo_centros_costo_buk_adp  vcc    ON vcc.cencos_adp         = e.`Centro de Costo`
        {$whereSql}
        ORDER BY {$orderBySql}
    ";

    $rows = $db->consultar($sqlExport);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="empleados_export_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Rut','NombreCompleto','Estado','DivisionArea','CargoADP','EmpresaADP','CCostoADP',
        'AreaBuk','CargoBuk','CCostoBuk','EstadoBuk','OrigenADP','BukEmpId','BukJobId'
    ], ';');

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['Rut'] ?? '',
            $r['NombreCompleto'] ?? '',
            $r['Estado'] ?? '',
            $r['DivisionArea'] ?? '',
            $r['CargoADP'] ?? '',
            $r['EmpresaADP'] ?? '',
            $r['CCostoADP'] ?? '',
            $r['AreaBuk'] ?? '',
            $r['CargoBuk'] ?? '',
            $r['CCostoBuk'] ?? '',
            $r['EstadoBuk'] ?? '',
            $r['OrigenADP'] ?? '',
            $r['BukEmpId'] ?? '',
            $r['BukJobId'] ?? '',
        ], ';');
    }

    fclose($out);
    exit;
}

// ------------------ CHIPS DE FILTROS ACTIVOS ------------------
$chips = [];
if ($rutFiltro !== '') $chips[] = ['label' => "RUT: {$rutFiltro}", 'url' => q(['rut' => null, 'page' => 1])];
if ($tipoFiltro !== '') $chips[] = ['label' => "Tipo: {$tipoFiltro}", 'url' => q(['tipo' => null, 'page' => 1])];
if ($estadoFiltro !== '') {
    $label = ($estadoFiltro === 'A') ? 'Estado: Activos' : 'Estado: Inactivos';
    $chips[] = ['label' => $label, 'url' => q(['estado' => null, 'page' => 1])];
}
if ($bukFiltro !== '') $chips[] = ['label' => "Buk: " . str_replace('_',' ', $bukFiltro), 'url' => q(['buk_estado' => null, 'page' => 1])];
?>

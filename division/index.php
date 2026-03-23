<?php
// /division/index.php
// NUEVA LÓGICA SWAP (3 niveles) usando SOLO buk_jerarquia:
// División (Desc División) -> Área (Desc Centro de Costo) -> Subárea (Desc Unidad)
// - Sin inventar data
// - Crea en Buk usando POST /organization/areas (parent_area_id y fallback parent_id)
// - Cachea IDs/estado/last_payload/last_response en buk_jerarquia
// - Muestra SIEMPRE debug: endpoint + payload + respuesta por cada click

require_once __DIR__ . '/../includes/auth.php';
require_auth();
$user = current_user();

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/../conexion/db.php';
require_once __DIR__ . '/../includes/runtime_config.php';
$db = new clsConexion();

// ---------------- CONFIG BUK ----------------
$bukCfg = runtime_buk_config();
define('BUK_API_BASE',  $bukCfg['base']);
define('BUK_API_TOKEN', $bukCfg['token']);
define('BUK_BASE_URL', BUK_API_BASE);
define('BUK_TOKEN', BUK_API_TOKEN);

$DEFAULT_CITY = 'San Antonio';
$LOCATION_ID  = 94;

// Debug llamadas API por request (se muestra tras POST)
$ultimoDebugApi = [];

// ---------------- HELPERS ----------------
function h($value): string {
  return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
function norm($s): string {
  $s = trim((string)$s);
  $s = preg_replace('/\s+/', ' ', $s);
  return $s;
}
function http_ok($code): bool {
  return ((int)$code >= 200 && (int)$code < 300);
}

/**
 * POST a Buk + debug
 */
function buk_post($endpoint, array $payload)
{
  global $ultimoDebugApi;

  $url = rtrim(BUK_BASE_URL, '/') . '/' . ltrim($endpoint, '/');
  $ch  = curl_init($url);

  $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
      'Content-Type: application/json',
      'Accept: application/json',
      'auth_token: ' . BUK_TOKEN,
    ],
    CURLOPT_POSTFIELDS     => $jsonPayload,
  ]);

  $body      = curl_exec($ch);
  $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);
  curl_close($ch);

  $result = [
    'http_code'    => $httpCode ?: 0,
    'body'         => $body ?: '',
    'payload_json' => $jsonPayload,
    'endpoint'     => $endpoint,
    'url'          => $url,
    'curl_error'   => $curlError,
  ];

  $responseBodyArray = json_decode($result['body'], true);
  $ultimoDebugApi[] = [
    'endpoint'   => $result['endpoint'],
    'url'        => $result['url'],
    'payload'    => $payload,
    'http_code'  => (int)$result['http_code'],
    'response'   => is_array($responseBodyArray) ? $responseBodyArray : $result['body'],
    'curl_error' => $result['curl_error'],
  ];

  return $result;
}

/**
 * Log opcional (si existe tabla)
 */
function log_buk(clsConexion $db, $tipoEntidad, $referenciaLocal, $metodo, array $apiResult)
{
  try {
    $endpoint = $db->real_escape_string($apiResult['endpoint'] ?? '');
    $payload  = $db->real_escape_string($apiResult['payload_json'] ?? '');

    $body = $apiResult['body'] ?? '';
    if ($body === '' || $body === null) {
      $body = json_encode(['empty' => true], JSON_UNESCAPED_UNICODE);
    } else {
      json_decode($body);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $body = json_encode(['raw' => $body], JSON_UNESCAPED_UNICODE);
      }
    }

    $bodyEsc = $db->real_escape_string($body);
    $codigo  = (int)($apiResult['http_code'] ?? 0);

    $tipoEntidad     = $db->real_escape_string($tipoEntidad);
    $referenciaLocal = $db->real_escape_string($referenciaLocal);
    $metodo          = $db->real_escape_string($metodo);

    $sql = "
      INSERT INTO stisoft_buk_logs
        (tipo_entidad, referencia_local, metodo_http, endpoint, payload, respuesta_http, respuesta_body)
      VALUES
        ('$tipoEntidad', '$referenciaLocal', '$metodo', '$endpoint', '$payload', $codigo, '$bodyEsc')
    ";
    $db->ejecutar($sql);
  } catch (Throwable $e) {
    // no-op
  }
}

/**
 * Asegura tabla buk_jerarquia (por si la usas en otra BD o faltan columnas)
 * Si YA la creaste, no te rompe.
 */
function ensure_buk_jerarquia(clsConexion $db): void
{
  $sql = "
    CREATE TABLE IF NOT EXISTS buk_jerarquia (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      profundidad TINYINT NOT NULL,
      tipo_origen_adp ENUM('division','centro_costo','unidad') NOT NULL,
      codigo_adp VARCHAR(64) NOT NULL,
      nombre VARCHAR(255) NOT NULL,

      parent_div_code VARCHAR(64) NOT NULL DEFAULT '',
      parent_cc_code  VARCHAR(64) NOT NULL DEFAULT '',

      buk_area_id INT NULL,
      buk_parent_id INT NULL,

      estado ENUM('pendiente','mapeado','sin_equivalencia') NOT NULL DEFAULT 'pendiente',
      observacion VARCHAR(255) NULL,

      last_endpoint VARCHAR(255) NULL,
      last_payload  MEDIUMTEXT NULL,
      last_response MEDIUMTEXT NULL,
      last_http_code INT NULL,

      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

      UNIQUE KEY uq_node (profundidad, tipo_origen_adp, codigo_adp, parent_div_code, parent_cc_code),
      KEY idx_estado (estado),
      KEY idx_tree (profundidad, parent_div_code, parent_cc_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ";
  $db->ejecutar($sql);
}

/**
 * Sync desde ADP a buk_jerarquia (SWAP) - SOLO ACTIVOS
 * - depth 0: División (codigo: División, nombre: Descripcion División)
 * - depth 1: Área (codigo: CC, nombre: Desc CC), parent_div_code = división
 * - depth 2: Subárea (codigo: Unidad, nombre: Desc Unidad), parent_div_code = división, parent_cc_code = CC
 */
function sync_from_adp_swap_to_jerarquia(clsConexion $db): array
{
  ensure_buk_jerarquia($db);

  $sql = "
    SELECT DISTINCT
      `División` AS div_code,
      `Descripcion División` AS div_name,
      `Centro de Costo` AS cc_code,
      `Descripcion Centro de Costo` AS cc_name,
      `Unidad` AS un_code,
      `Descripcion Unidad` AS un_name
    FROM adp_empleados
    WHERE `Estado` IN ('A','S')
      AND `División` IS NOT NULL AND TRIM(`División`) <> ''
      AND `Descripcion División` IS NOT NULL AND TRIM(`Descripcion División`) <> ''
      AND `Centro de Costo` IS NOT NULL AND TRIM(`Centro de Costo`) <> ''
      AND `Descripcion Centro de Costo` IS NOT NULL AND TRIM(`Descripcion Centro de Costo`) <> ''
      AND `Unidad` IS NOT NULL AND TRIM(`Unidad`) <> ''
      AND `Descripcion Unidad` IS NOT NULL AND TRIM(`Descripcion Unidad`) <> ''
    ORDER BY div_name, cc_name, un_name
  ";

  $rows = $db->consultar($sql);

  $count_div = 0;
  $count_cc  = 0;
  $count_un  = 0;

  foreach ($rows as $r) {
    $div_code = norm($r['div_code']);
    $div_name = norm($r['div_name']);

    $cc_code  = norm($r['cc_code']);
    $cc_name  = norm($r['cc_name']);

    $un_code  = norm($r['un_code']);
    $un_name  = norm($r['un_name']);

    // División (depth 0) -> padres '' ''
    $sqlDiv = "
      INSERT INTO buk_jerarquia
        (profundidad, tipo_origen_adp, codigo_adp, nombre, parent_div_code, parent_cc_code, estado)
      VALUES
        (0, 'division', '" . $db->real_escape_string($div_code) . "', '" . $db->real_escape_string($div_name) . "', '', '', 'pendiente')
      ON DUPLICATE KEY UPDATE
        nombre=VALUES(nombre)
    ";
    if ($db->ejecutar($sqlDiv)) $count_div++;

    // Área(CC) (depth 1) bajo división
    $sqlCC = "
      INSERT INTO buk_jerarquia
        (profundidad, tipo_origen_adp, codigo_adp, nombre, parent_div_code, parent_cc_code, estado)
      VALUES
        (1, 'centro_costo', '" . $db->real_escape_string($cc_code) . "', '" . $db->real_escape_string($cc_name) . "',
         '" . $db->real_escape_string($div_code) . "', '', 'pendiente')
      ON DUPLICATE KEY UPDATE
        nombre=VALUES(nombre)
    ";
    if ($db->ejecutar($sqlCC)) $count_cc++;

    // Subárea(Unidad) (depth 2) bajo área(CC)
    $sqlUN = "
      INSERT INTO buk_jerarquia
        (profundidad, tipo_origen_adp, codigo_adp, nombre, parent_div_code, parent_cc_code, estado)
      VALUES
        (2, 'unidad', '" . $db->real_escape_string($un_code) . "', '" . $db->real_escape_string($un_name) . "',
         '" . $db->real_escape_string($div_code) . "', '" . $db->real_escape_string($cc_code) . "', 'pendiente')
      ON DUPLICATE KEY UPDATE
        nombre=VALUES(nombre)
    ";
    if ($db->ejecutar($sqlUN)) $count_un++;
  }

  return [
    'rows_distinct'     => count($rows),
    'div_upserts'       => $count_div,
    'cc_upserts'        => $count_cc,
    'un_upserts'        => $count_un,
  ];
}

/**
 * Obtiene el buk_area_id del padre ya mapeado
 */
function jer_get_buk_id_div(clsConexion $db, string $div_code): ?int
{
  $div_code = $db->real_escape_string(norm($div_code));
  $r = $db->consultar("
    SELECT buk_area_id
    FROM buk_jerarquia
    WHERE profundidad=0 AND tipo_origen_adp='division'
      AND codigo_adp='$div_code'
      AND parent_div_code='' AND parent_cc_code=''
      AND estado='mapeado'
      AND buk_area_id IS NOT NULL
    LIMIT 1
  ");
  if (empty($r)) return null;
  return (int)$r[0]['buk_area_id'];
}

function jer_get_buk_id_cc(clsConexion $db, string $div_code, string $cc_code): ?int
{
  $div_code = $db->real_escape_string(norm($div_code));
  $cc_code  = $db->real_escape_string(norm($cc_code));

  $r = $db->consultar("
    SELECT buk_area_id
    FROM buk_jerarquia
    WHERE profundidad=1 AND tipo_origen_adp='centro_costo'
      AND codigo_adp='$cc_code'
      AND parent_div_code='$div_code' AND parent_cc_code=''
      AND estado='mapeado'
      AND buk_area_id IS NOT NULL
    LIMIT 1
  ");
  if (empty($r)) return null;
  return (int)$r[0]['buk_area_id'];
}

/**
 * Crea nodo en Buk (con parent_area_id y fallback parent_id)
 */
function buk_create_area_node(clsConexion $db, array $node, ?int $parentBukId, string $defaultCity, int $locationId): array
{
  $payload = [
    'location_id'       => $locationId,
    'name'              => $node['nombre'],
    'accounting_prefix' => (string)$node['codigo_adp'],
    'city'              => $defaultCity,
    'address'           => '',
    'cost_center_id'    => '',
    'role_ids'          => [],
  ];

  // ✅ CLAVE: Buk jerarquía con parent_id
  if ($parentBukId !== null) {
    $payload['parent_id'] = (int)$parentBukId;
  }

  $api = buk_post('/organization/areas', $payload);
  log_buk($db, 'area', "jerarquia_id:" . $node['id'], 'POST', $api);

  return [
    'http' => (int)$api['http_code'],
    'body' => json_decode($api['body'], true) ?: $api['body'],
    'raw'  => $api,
    'payload_used' => $payload,
  ];
}



/**
 * Marca resultado de API en buk_jerarquia (estado + last_*)
 */
function jer_update_last_call(clsConexion $db, int $id, array $apiRaw, string $estado, ?int $bukId, ?int $bukParentId, string $obs): void
{
  $estadoEsc = $db->real_escape_string($estado);
  $obsEsc    = $db->real_escape_string($obs);

  $endpoint = $db->real_escape_string($apiRaw['endpoint'] ?? '');
  $payload  = $db->real_escape_string($apiRaw['payload_json'] ?? '');
  $resp     = $db->real_escape_string($apiRaw['body'] ?? '');
  $http     = (int)($apiRaw['http_code'] ?? 0);

  $bukIdSql = ($bukId === null) ? "NULL" : (int)$bukId;
  $bukParentSql = ($bukParentId === null) ? "NULL" : (int)$bukParentId;

  $db->ejecutar("
    UPDATE buk_jerarquia
    SET
      estado='$estadoEsc',
      observacion='$obsEsc',
      buk_area_id=$bukIdSql,
      buk_parent_id=$bukParentSql,
      last_endpoint='$endpoint',
      last_payload='$payload',
      last_response='$resp',
      last_http_code=$http
    WHERE id=".(int)$id."
  ");
}

/**
 * Crea pendientes en orden depth 0 -> 1 -> 2
 */
function crear_pendientes_en_buk_jerarquia(clsConexion $db, string $defaultCity, int $locationId): array
{
  ensure_buk_jerarquia($db);

  $stats = ['ok'=>0,'fail'=>0,'skip'=>0,'procesadas'=>0];

  for ($depth=0; $depth<=2; $depth++) {
    $nodes = $db->consultar("
      SELECT *
      FROM buk_jerarquia
      WHERE profundidad=$depth
        AND (estado='pendiente' OR estado='sin_equivalencia')
        AND (buk_area_id IS NULL OR buk_area_id=0)
      ORDER BY id
    ");

    foreach ($nodes as $node) {
      $stats['procesadas']++;

      $nombre = norm($node['nombre']);
      if ($nombre === '') {
        jer_update_last_call($db, (int)$node['id'], ['endpoint'=>'','payload_json'=>'','body'=>'','http_code'=>0], 'sin_equivalencia', null, null, 'Nombre vacío');
        $stats['fail']++;
        continue;
      }

      $parentBukId = null;
      if ($depth === 1) {
        $parentBukId = jer_get_buk_id_div($db, $node['parent_div_code']);
        if (!$parentBukId) { $stats['skip']++; continue; }
      }
      if ($depth === 2) {
        $parentBukId = jer_get_buk_id_cc($db, $node['parent_div_code'], $node['parent_cc_code']);
        if (!$parentBukId) { $stats['skip']++; continue; }
      }

      $res = buk_create_area_node($db, $node, $parentBukId, $defaultCity, $locationId);

      $http = (int)$res['http'];
      $body = $res['body'];
      $apiRaw = $res['raw'];

      if (http_ok($http) && is_array($body)) {
        $idBuk = null;
        if (isset($body['data']['id'])) $idBuk = (int)$body['data']['id'];
        elseif (isset($body['id'])) $idBuk = (int)$body['id'];

        if ($idBuk) {
          jer_update_last_call($db, (int)$node['id'], $apiRaw, 'mapeado', $idBuk, $parentBukId, 'Creado OK en BUK');
          $stats['ok']++;
        } else {
          jer_update_last_call($db, (int)$node['id'], $apiRaw, 'sin_equivalencia', null, $parentBukId, 'Respuesta sin id');
          $stats['fail']++;
        }
      } else {
        $msg = "HTTP $http";
        if (is_array($body)) $msg .= ' - ' . substr(json_encode($body, JSON_UNESCAPED_UNICODE), 0, 180);
        jer_update_last_call($db, (int)$node['id'], $apiRaw, 'sin_equivalencia', null, $parentBukId, $msg);
        $stats['fail']++;
      }
    }
  }

  return $stats;
}

/**
 * Reset: deja todo como pendiente y limpia IDs/observaciones/last_*
 */
function reset_jerarquia(clsConexion $db): bool
{
  ensure_buk_jerarquia($db);
  return $db->ejecutar("
    UPDATE buk_jerarquia
    SET
      buk_area_id=NULL,
      buk_parent_id=NULL,
      estado='pendiente',
      observacion=NULL,
      last_endpoint=NULL,
      last_payload=NULL,
      last_response=NULL,
      last_http_code=NULL
  ");
}

/**
 * Crear un nodo por ID
 */
function crear_uno_por_id(clsConexion $db, int $id, string $defaultCity, int $locationId): array
{
  $r = $db->consultar("SELECT * FROM buk_jerarquia WHERE id=$id LIMIT 1");
  if (empty($r)) return ['ok'=>false,'msg'=>'No existe'];

  $node = $r[0];
  $depth = (int)$node['profundidad'];

  $parentBukId = null;
  if ($depth === 1) {
    $parentBukId = jer_get_buk_id_div($db, $node['parent_div_code']);
    if (!$parentBukId) return ['ok'=>false,'msg'=>'Aún no existe el padre (División)'];
  }
  if ($depth === 2) {
    $parentBukId = jer_get_buk_id_cc($db, $node['parent_div_code'], $node['parent_cc_code']);
    if (!$parentBukId) return ['ok'=>false,'msg'=>'Aún no existe el padre (Área CC)'];
  }

  $res = buk_create_area_node($db, $node, $parentBukId, $defaultCity, $locationId);
  $http = (int)$res['http'];
  $body = $res['body'];
  $apiRaw = $res['raw'];

  if (http_ok($http) && is_array($body)) {
    $idBuk = $body['data']['id'] ?? ($body['id'] ?? null);
    $idBuk = $idBuk ? (int)$idBuk : null;

    if ($idBuk) {
      jer_update_last_call($db, (int)$node['id'], $apiRaw, 'mapeado', $idBuk, $parentBukId, 'Creado OK en BUK');
      return ['ok'=>true,'msg'=>'Creado OK'];
    }
  }

  $msg = "Falló (HTTP $http)";
  jer_update_last_call($db, (int)$node['id'], $apiRaw, 'sin_equivalencia', null, $parentBukId, $msg);
  return ['ok'=>false,'msg'=>$msg];
}

// ------------------ POST actions ------------------
$flashOk = '';
$flashError = '';

ensure_buk_jerarquia($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? '';
  $ultimoDebugApi = []; // limpiar debug por click

  try {
    if ($accion === 'sync_swap') {
      $st = sync_from_adp_swap_to_jerarquia($db);
      $flashOk = "Sync SWAP OK. Distinct={$st['rows_distinct']} | Div={$st['div_upserts']} | CC={$st['cc_upserts']} | Unidad={$st['un_upserts']}";
    }
    elseif ($accion === 'crear_pendientes') {
      $st = crear_pendientes_en_buk_jerarquia($db, $DEFAULT_CITY, $LOCATION_ID);
      $flashOk = "Crear pendientes OK={$st['ok']} FAIL={$st['fail']} SKIP={$st['skip']} Procesadas={$st['procesadas']}";
    }
    elseif ($accion === 'reset') {
      $flashOk = reset_jerarquia($db) ? "Reset OK (todo a pendiente)" : "No se pudo resetear";
    }
    elseif ($accion === 'crear_uno' && isset($_POST['jer_id'])) {
      $id = (int)$_POST['jer_id'];
      $r = crear_uno_por_id($db, $id, $DEFAULT_CITY, $LOCATION_ID);
      if ($r['ok']) $flashOk = "Nodo $id: ".$r['msg'];
      else $flashError = "Nodo $id: ".$r['msg'];
    }
  } catch (Throwable $e) {
    $flashError = "Error: " . $e->getMessage();
  }
}

// ------------------ DATA for UI ------------------

// Conteos
$counts = $db->consultar("
  SELECT profundidad, estado, COUNT(*) c
  FROM buk_jerarquia
  GROUP BY profundidad, estado
");
$cnt = [
  0 => ['pendiente'=>0,'mapeado'=>0,'sin_equivalencia'=>0],
  1 => ['pendiente'=>0,'mapeado'=>0,'sin_equivalencia'=>0],
  2 => ['pendiente'=>0,'mapeado'=>0,'sin_equivalencia'=>0],
];
foreach ($counts as $r) {
  $d = (int)$r['profundidad'];
  $e = (string)$r['estado'];
  $cnt[$d][$e] = (int)$r['c'];
}

// Tabla plana (últimos 400)
$rows = $db->consultar("
  SELECT *
  FROM buk_jerarquia
  ORDER BY profundidad, parent_div_code, parent_cc_code, codigo_adp
  LIMIT 400
");

?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="bg-gray-50">
<div class="min-h-screen grid grid-cols-12">
  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php $active='division'; include __DIR__ . '/../partials/sidebar.php'; ?>
  </div>

  <div class="col-span-12 md:col-span-9 lg:col-span-10">
    <div class="border-b bg-white">
      <div class="max-w-7xl mx-auto px-4 py-4 flex flex-wrap items-start justify-between gap-3">
        <div class="flex flex-col gap-2">
          <div>
            <h1 class="text-lg font-semibold text-gray-900">Estructura BUK SWAP (División → CC → Unidad)</h1>
            <p class="text-xs text-gray-500">Usando solo <b>buk_jerarquia</b> (sin queue). Crea 3 niveles en Buk y guarda IDs.</p>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 text-gray-800">
              Divisiones: P <?= h($cnt[0]['pendiente']) ?> | OK <?= h($cnt[0]['mapeado']) ?> | Err <?= h($cnt[0]['sin_equivalencia']) ?>
            </span>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 text-gray-800">
              Áreas(CC): P <?= h($cnt[1]['pendiente']) ?> | OK <?= h($cnt[1]['mapeado']) ?> | Err <?= h($cnt[1]['sin_equivalencia']) ?>
            </span>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 text-gray-800">
              Subáreas(Unidad): P <?= h($cnt[2]['pendiente']) ?> | OK <?= h($cnt[2]['mapeado']) ?> | Err <?= h($cnt[2]['sin_equivalencia']) ?>
            </span>
          </div>
        </div>

        <div class="flex flex-col md:flex-row gap-3 items-end md:items-center">
          <?php if ($flashOk): ?>
            <div class="text-xs px-3 py-2 rounded-lg bg-green-50 text-green-700 border border-green-200 max-w-md"><?= h($flashOk) ?></div>
          <?php endif; ?>
          <?php if ($flashError): ?>
            <div class="text-xs px-3 py-2 rounded-lg bg-red-50 text-red-700 border border-red-200 max-w-md"><?= h($flashError) ?></div>
          <?php endif; ?>

          <form method="post" class="flex flex-wrap items-center gap-2">
            <button type="submit" name="accion" value="sync_swap"
              class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium bg-gray-900 text-white hover:bg-gray-800">
              1) Sync desde ADP (SWAP) → buk_jerarquia
            </button>

            <button type="submit" name="accion" value="crear_pendientes"
              onclick="return confirm('Creará en BUK: Divisiones, luego CC, luego Unidades pendientes. ¿Continuar?');"
              class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium bg-blue-600 text-white hover:bg-blue-700">
              2) Crear pendientes en BUK (3 niveles)
            </button>

            <button type="submit" name="accion" value="reset"
              onclick="return confirm('Reseteará buk_jerarquia a pendiente (sin borrar registros). ¿Continuar?');"
              class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium bg-red-600 text-white hover:bg-red-700">
              3) Reset
            </button>
          </form>
        </div>
      </div>

      <!-- DEBUG SIEMPRE QUE HAY POST -->
      <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <div class="max-w-7xl mx-auto px-4 pb-4 space-y-3">
          <h3 class="text-sm font-bold text-gray-700 mt-2">Debug llamadas API (<?= count($ultimoDebugApi) ?>)</h3>

          <?php if (empty($ultimoDebugApi)): ?>
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 text-xs">
              No hubo llamadas a la API en esta acción.
            </div>
          <?php else: ?>
            <?php foreach ($ultimoDebugApi as $idx => $debug): ?>
              <?php
                $ok = ($debug['http_code'] >= 200 && $debug['http_code'] < 300);
                $statusColor = $ok ? 'text-green-400' : 'text-red-400';
                $borderColor = $ok ? 'border-green-800' : 'border-red-800';
              ?>
              <div class="bg-gray-900 text-gray-100 rounded-lg p-4 text-xs shadow-lg border-l-4 <?= $borderColor ?>">
                <div class="flex justify-between items-center mb-3 pb-2 border-b border-gray-700">
                  <div class="flex flex-col">
                    <span class="font-bold text-sm text-white">#<?= $idx + 1 ?></span>
                    <span class="font-mono text-blue-300 mt-1"><?= h("POST ".$debug['endpoint']) ?></span>
                    <span class="font-mono text-gray-300 mt-1"><?= h($debug['url']) ?></span>
                  </div>
                  <div class="text-right">
                    <span class="font-bold text-lg <?= $statusColor ?>">HTTP <?= h($debug['http_code']) ?></span>
                    <?php if (!empty($debug['curl_error'])): ?>
                      <div class="text-red-500 font-bold mt-1">CURL ERROR: <?= h($debug['curl_error']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="grid md:grid-cols-2 gap-4">
                  <div class="bg-black/30 p-3 rounded border border-gray-700">
                    <div class="font-semibold text-gray-400 mb-2 border-b border-gray-600 pb-1">PAYLOAD</div>
                    <pre class="whitespace-pre-wrap overflow-x-auto text-green-300 font-mono text-[11px]"><?= h(json_encode($debug['payload'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
                  </div>

                  <div class="bg-black/30 p-3 rounded border border-gray-700">
                    <div class="font-semibold text-gray-400 mb-2 border-b border-gray-600 pb-1">RESPONSE</div>
                    <pre class="whitespace-pre-wrap overflow-x-auto text-yellow-300 font-mono text-[11px]"><?= h(json_encode($debug['response'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <main class="max-w-7xl mx-auto px-4 py-6 space-y-6">
      <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
          <h2 class="text-sm font-semibold text-gray-800">buk_jerarquia (últimos 400)</h2>
          <span class="text-xs text-gray-500">Orden: División → CC → Unidad</span>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full text-xs border-t border-gray-100">
            <thead class="bg-gray-50">
              <tr class="text-[11px] uppercase tracking-wide text-gray-500">
                <th class="px-3 py-2 text-left">#</th>
                <th class="px-3 py-2 text-left">Nivel</th>
                <th class="px-3 py-2 text-left">Tipo</th>
                <th class="px-3 py-2 text-left">Código ADP</th>
                <th class="px-3 py-2 text-left">Nombre</th>
                <th class="px-3 py-2 text-left">Padre Div</th>
                <th class="px-3 py-2 text-left">Padre CC</th>
                <th class="px-3 py-2 text-left">BUK ID</th>
                <th class="px-3 py-2 text-left">Estado</th>
                <th class="px-3 py-2 text-left">Obs</th>
                <th class="px-3 py-2 text-left">Acción</th>
              </tr>
            </thead>

            <tbody class="divide-y divide-gray-100">
            <?php if (empty($rows)): ?>
              <tr><td colspan="11" class="px-3 py-4 text-center text-gray-500">Sin datos. Presiona “Sync desde ADP (SWAP)”.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php
                  $estado = $r['estado'] ?? '';
                  $badge = 'bg-gray-100 text-gray-700';
                  if ($estado === 'mapeado') $badge = 'bg-emerald-100 text-emerald-800';
                  elseif ($estado === 'pendiente') $badge = 'bg-amber-100 text-amber-800';
                  elseif ($estado === 'sin_equivalencia') $badge = 'bg-red-100 text-red-800';

                  $nivelTxt = 'División';
                  if ((int)$r['profundidad'] === 1) $nivelTxt = 'Área (CC)';
                  if ((int)$r['profundidad'] === 2) $nivelTxt = 'Subárea (Unidad)';
                  
                  
           $depth = (int)($r['profundidad'] ?? 0);

// Tu orden visual pedido:
// depth 0 = Nivel 1 (División)
// depth 2 = Nivel 2 (Subárea / Unidad)
// depth 1 = Nivel 3 (Área / CC)

$nivelTxt = 'Nivel 1 (División)';
$nivelBadge = 'bg-indigo-100 text-indigo-800';

if ($depth === 2) {
  $nivelTxt = 'Nivel 2 (Subárea / Unidad)';
  $nivelBadge = 'bg-violet-100 text-violet-800';
} elseif ($depth === 1) {
  $nivelTxt = 'Nivel 3 (Área / CC)';
  $nivelBadge = 'bg-sky-100 text-sky-800';
}

                ?>
                
                
                
                <tr class="hover:bg-gray-50">
                  <td class="px-3 py-2"><?= h($r['id']) ?></td>
<td class="px-3 py-2">
  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium <?= $nivelBadge ?>">
    <?= h($nivelTxt) ?>
  </span>
</td>
                  <td class="px-3 py-2"><?= h($r['tipo_origen_adp']) ?></td>
                  <td class="px-3 py-2"><?= h($r['codigo_adp']) ?></td>
                  <td class="px-3 py-2">
                    <div class="max-w-[260px] truncate" title="<?= h($r['nombre']) ?>"><?= h($r['nombre']) ?></div>
                  </td>
                  <td class="px-3 py-2"><?= h($r['parent_div_code']) ?></td>
                  <td class="px-3 py-2"><?= h($r['parent_cc_code']) ?></td>
                  <td class="px-3 py-2"><?= h($r['buk_area_id']) ?></td>
                  <td class="px-3 py-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium <?= $badge ?>">
                      <?= h($estado) ?>
                    </span>
                  </td>
                  <td class="px-3 py-2">
                    <div class="max-w-[220px] truncate" title="<?= h($r['observacion']) ?>"><?= h($r['observacion']) ?></div>
                  </td>
                  <td class="px-3 py-2">
                    <?php if ($estado !== 'mapeado'): ?>
                      <form method="post" class="inline">
                        <input type="hidden" name="accion" value="crear_uno">
                        <input type="hidden" name="jer_id" value="<?= h($r['id']) ?>">
                        <button
                          type="submit"
                          class="inline-flex items-center px-2 py-1 rounded-full text-[10px] font-medium bg-blue-600 text-white hover:bg-blue-700"
                          onclick="return confirm('¿Crear este nodo en BUK?');"
                        >
                          Crear
                        </button>
                      </form>
                    <?php else: ?>
                      <span class="text-[10px] text-gray-400">–</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="text-xs text-gray-500">
        Nota: La creación respeta la jerarquía real. Si un hijo no se crea, normalmente es porque su padre aún no está “mapeado”.
      </div>
    </main>
  </div>
</div>
</body>
</html>

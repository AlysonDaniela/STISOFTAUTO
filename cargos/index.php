<?php
// /cargos/index.php
// NUEVA LÓGICA: Roles (Cargos) se asocian SOLO a Nivel 3 (Subárea/Unidad) según mapeo buk_jerarquia
// - Data real: cargo -> unidades (adp_empleados) -> buk_jerarquia (profundidad=2, tipo_origen_adp='unidad')
// - POST /roles con area_ids = subáreas nivel 3 correspondientes
// - Debug SIEMPRE: endpoint + payload + respuesta por cada click



require_once __DIR__ . '/../includes/auth.php';
require_auth();
$user = current_user();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../conexion/db.php';
$db = new clsConexion();

define('BUK_API_BASE',  'https://sti.buk.cl/api/v1/chile');
define('BUK_API_TOKEN', 'bAVH6fNSraVT17MBv1ECPrfW');

$ultimoDebugApi = []; // debug de ESTA request

// ------------------- HELPERS -------------------
function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function norm($s): string {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

function buk_post_json(string $endpoint, array $payload): array
{
    global $ultimoDebugApi;

    $url = rtrim(BUK_API_BASE, '/') . $endpoint;
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'auth_token: ' . BUK_API_TOKEN,
        ],
        CURLOPT_POSTFIELDS     => $jsonPayload,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $decoded = null;
    if ($response !== false) {
        $decoded = json_decode($response, true);
    }

    // Guardar debug (bonito)
    $ultimoDebugApi[] = [
        'endpoint'   => $endpoint,
        'url'        => $url,
        'payload'    => $payload,
        'http_code'  => $httpCode ?: 0,
        'response'   => is_array($decoded) ? $decoded : ($response ?: ''),
        'curl_error' => $curlErr,
    ];

    return [
        'http_code' => $httpCode ?: 0,
        'body_raw'  => $response === false ? ($curlErr ?: '') : ($response ?: ''),
        'body_json' => $decoded,
        'payload_json' => $jsonPayload,
        'endpoint'  => $endpoint,
        'url'       => $url,
        'curl_error'=> $curlErr,
    ];
}

function log_buk_safe(clsConexion $db, string $tipo, string $ref, string $method, array $apiResp): void
{
    try {
        $endpoint = $db->real_escape_string($apiResp['endpoint'] ?? '');
        $payload  = $db->real_escape_string($apiResp['payload_json'] ?? '');
        $codigo   = (int)($apiResp['http_code'] ?? 0);

        $body = $apiResp['body_raw'] ?? '';
        if ($body === '' || $body === null) {
            $body = json_encode(['empty'=>true], JSON_UNESCAPED_UNICODE);
        } else {
            json_decode($body);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $body = json_encode(['raw'=>$body], JSON_UNESCAPED_UNICODE);
            }
        }
        $bodyEsc = $db->real_escape_string($body);

        $tipo = $db->real_escape_string($tipo);
        $ref  = $db->real_escape_string($ref);
        $method = $db->real_escape_string($method);

        $sql = "
            INSERT INTO stisoft_buk_logs
              (tipo_entidad, referencia_local, metodo_http, endpoint, payload, respuesta_http, respuesta_body)
            VALUES
              ('$tipo', '$ref', '$method', '$endpoint', '$payload', $codigo, '$bodyEsc')
        ";
        $db->ejecutar($sql);
    } catch (Throwable $e) {
        // no-op
    }
}

// ------------------- NEGOCIO (NUEVO) -------------------

/**
 * area_ids NIVEL 3 para un cargo ADP:
 * adp_empleados.Cargo -> adp_empleados.Unidad -> buk_jerarquia (profundidad=2, tipo_origen_adp='unidad')
 */
function obtener_subareas_nivel3_por_cargo(clsConexion $db, int $cargoAdpId): array
{
    $cargoAdpId = (int)$cargoAdpId;

    // OJO: ajusta nombres de columnas si en tu ADP están distintos (aquí uso tal cual lo venías usando)
    $sql = "
        SELECT DISTINCT bj.buk_area_id
        FROM adp_empleados e
        INNER JOIN buk_jerarquia bj
            ON bj.profundidad = 2
           AND bj.tipo_origen_adp = 'unidad'
           AND bj.codigo_adp = e.Unidad
           AND bj.buk_area_id IS NOT NULL
        WHERE e.Cargo = {$cargoAdpId}
          AND e.Estado IN ('A','S')
        ORDER BY bj.buk_area_id
    ";

    $rows = $db->consultar($sql);
    $ids = [];
    foreach ($rows as $r) {
        $ids[] = (int)$r['buk_area_id'];
    }
    return $ids;
}

/**
 * Sync stisoft_mapeo_cargos desde vista v_adp_cargos_sin_buk
 */
function sync_cargos_adp_a_mapeo(clsConexion $db): int
{
    $sql = "
        INSERT INTO stisoft_mapeo_cargos (cargo_adp_id, cargo_adp_desc, estado)
        SELECT v.id_adp_cargo, v.cargo_adp, 'pendiente'
        FROM v_adp_cargos_sin_buk v
        ON DUPLICATE KEY UPDATE
            cargo_adp_desc = VALUES(cargo_adp_desc)
    ";
    $db->ejecutar($sql);

    $res = $db->consultar("SELECT COUNT(*) AS total FROM stisoft_mapeo_cargos");
    return !empty($res) ? (int)$res[0]['total'] : 0;
}

/**
 * Crea cargo (role) en Buk asociado a subáreas nivel 3 (area_ids).
 */
function crear_cargo_en_buk(clsConexion $db, array $cargoRow): array
{
    $cargoAdpId   = (int)$cargoRow['cargo_adp_id'];
    $cargoAdpDesc = norm($cargoRow['cargo_adp_desc'] ?? '');

    // 1) Obtener subáreas nivel 3 reales para este cargo
    $areaIds = obtener_subareas_nivel3_por_cargo($db, $cargoAdpId);

    if (empty($areaIds)) {
        // No llamamos API: no hay data real de subáreas (o no están mapeadas)
        $obs = "Sin subáreas NIVEL 3 mapeadas para este cargo (revisar unidades ADP / buk_jerarquia).";
        $obsEsc = $db->real_escape_string($obs);

        $db->ejecutar("
            UPDATE stisoft_mapeo_cargos
            SET buk_role_id = NULL,
                estado      = 'sin_equivalencia',
                observacion = '{$obsEsc}'
            WHERE cargo_adp_id = {$cargoAdpId}
        ");

        return [
            'http_code'    => 0,
            'payload'      => null,
            'response_raw' => '',
            'response_json'=> null,
            'estado'       => 'sin_equivalencia',
            'buk_role_id'  => null,
            'observacion'  => $obs,
            'area_ids'     => $areaIds,
        ];
    }

    // 2) Payload Buk
    $codigoBuk = 'ADP_' . $cargoAdpId;

    $payload = [
        "name"              => $cargoAdpDesc,
        "code"              => $codigoBuk,
        "description"       => $cargoAdpDesc,
        "requirements"      => "",
        "role_family_id"    => null,
        "area_ids"          => $areaIds, // ✅ SOLO NIVEL 3 (subáreas)
        "custom_attributes" => new stdClass(),
    ];

    $endpoint = "/roles";
    $apiResp  = buk_post_json($endpoint, $payload);

    log_buk_safe($db, 'cargo', 'cargo_adp:' . $cargoAdpId, 'POST', $apiResp);

    // 3) Analizar respuesta
    $httpCode = (int)$apiResp['http_code'];
    $estado   = 'sin_equivalencia';
    $obs      = null;
    $bukRoleId = null;

    if ($httpCode >= 200 && $httpCode < 300 && isset($apiResp['body_json']['data']['id'])) {
        $estado    = 'mapeado';
        $bukRoleId = (int)$apiResp['body_json']['data']['id'];
        $obs       = 'Creado OK en BUK';
    } else {
        if (is_array($apiResp['body_json']) && isset($apiResp['body_json']['errors'])) {
            $err = $apiResp['body_json']['errors'];
            $obs = is_array($err) ? implode(' | ', $err) : (string)$err;
        } else {
            $obs = substr((string)$apiResp['body_raw'], 0, 250);
        }
    }

    // 4) Actualizar tabla mapeo cargos
    $obsSql = $obs ? "'" . $db->real_escape_string($obs) . "'" : "NULL";
    $db->ejecutar("
        UPDATE stisoft_mapeo_cargos
        SET buk_role_id = " . ($bukRoleId !== null ? $bukRoleId : "NULL") . ",
            estado      = '{$db->real_escape_string($estado)}',
            observacion = {$obsSql}
        WHERE cargo_adp_id = {$cargoAdpId}
    ");

    // 5) Guardar en buk_cargos / buk_cargo_area (si existen)
    if ($bukRoleId !== null) {
        try {
            $rawResponse = json_encode($apiResp['body_json'], JSON_UNESCAPED_UNICODE);
            $rawEsc = "'" . $db->real_escape_string($rawResponse) . "'";

            $db->ejecutar("
                INSERT INTO buk_cargos (id, id_origen_excel, nombre, codigo, id_adp_cargo, fecha_ultima_sync, raw_response)
                VALUES ({$bukRoleId}, NULL,
                        '" . $db->real_escape_string($cargoAdpDesc) . "',
                        '" . $db->real_escape_string($codigoBuk) . "',
                        {$cargoAdpId},
                        NOW(),
                        {$rawEsc})
                ON DUPLICATE KEY UPDATE
                    nombre = VALUES(nombre),
                    codigo = VALUES(codigo),
                    id_adp_cargo = VALUES(id_adp_cargo),
                    fecha_ultima_sync = NOW(),
                    raw_response = VALUES(raw_response)
            ");

            foreach ($areaIds as $areaId) {
                $db->ejecutar("
                    INSERT IGNORE INTO buk_cargo_area (cargo_codigo, area_id, origen)
                    VALUES ('" . $db->real_escape_string($codigoBuk) . "', " . (int)$areaId . ", 'api_roles')
                ");
            }
        } catch (Throwable $e) {
            // no-op
        }
    }

    return [
        'http_code'    => $httpCode,
        'payload'      => $payload,
        'response_raw' => $apiResp['body_raw'],
        'response_json'=> $apiResp['body_json'],
        'estado'       => $estado,
        'buk_role_id'  => $bukRoleId,
        'observacion'  => $obs,
        'area_ids'     => $areaIds,
    ];
}

// ------------------- POST Actions -------------------
$flashOk = '';
$flashError = '';

$action = $_POST['action'] ?? ($_GET['action'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ultimoDebugApi = []; // reset debug por click
}

if ($action === 'sync_adp') {
    $total = sync_cargos_adp_a_mapeo($db);
    $_SESSION['flash_ok'] = "Cargos ADP sincronizados a tabla de mapeo. Total en tabla: {$total}.";
    header("Location: index.php");
    exit;
}

if ($action === 'reset_estados') {
    $db->ejecutar("
        UPDATE stisoft_mapeo_cargos
        SET buk_role_id = NULL,
            estado      = 'pendiente',
            observacion = NULL
    ");
    $_SESSION['flash_ok'] = "Estados de cargos reseteados a 'pendiente'.";
    header("Location: index.php");
    exit;
}

if ($action === 'crear_todos') {
    // crear 100 por click para no saturar, y debug limitado
    $pendientes = $db->consultar("
        SELECT cargo_adp_id, cargo_adp_desc
        FROM stisoft_mapeo_cargos
        WHERE estado = 'pendiente'
        ORDER BY cargo_adp_id
        LIMIT 100
    ");

    $procesados = 0;
    $ok = 0; $fail = 0;

    foreach ($pendientes as $row) {
        $res = crear_cargo_en_buk($db, $row);
        $procesados++;
        if ($res['buk_role_id']) $ok++; else $fail++;

        // limitar debug visible
        if (count($ultimoDebugApi) > 30) break;
    }

    $_SESSION['flash_ok'] = "Creación masiva ejecutada. Procesados={$procesados} | OK={$ok} | FAIL={$fail}.";
    // Guardar debug en sesión para mostrar tras redirect
    $_SESSION['buk_debug_api'] = $ultimoDebugApi;

    header("Location: index.php");
    exit;
}

if ($action === 'crear_uno' && isset($_POST['cargo_adp_id'])) {
    $id = (int)$_POST['cargo_adp_id'];

    $row = $db->consultar("
        SELECT cargo_adp_id, cargo_adp_desc
        FROM stisoft_mapeo_cargos
        WHERE cargo_adp_id = {$id}
        LIMIT 1
    ");

    if (empty($row)) {
        $_SESSION['flash_error'] = "No se encontró el cargo ADP {$id} en la tabla de mapeo.";
        header("Location: index.php");
        exit;
    }

    $res = crear_cargo_en_buk($db, $row[0]);

    $_SESSION['flash_ok'] = $res['buk_role_id']
        ? "Cargo ADP {$id} creado/mapeado en BUK con id {$res['buk_role_id']}."
        : "Cargo ADP {$id} NO se creó: " . ($res['observacion'] ?? 'sin detalle');

    $_SESSION['buk_debug_api'] = $ultimoDebugApi;

    header("Location: index.php");
    exit;
}

// ------------------- Flash + Debug session -------------------
$flashOk    = $_SESSION['flash_ok']    ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

$debugApi = $_SESSION['buk_debug_api'] ?? [];
unset($_SESSION['buk_debug_api']);

// ------------------- LISTADO -------------------
// Para mostrar ejemplo: 1 unidad ADP + 1 subárea buk id + count subáreas por cargo
$subAreasPorCargo = "
    SELECT
        e.Cargo AS cargo_adp_id,
        MIN(e.Unidad) AS unidad_adp_codigo,
        MIN(e.`Descripcion Unidad`) AS unidad_adp_desc,
        COUNT(DISTINCT bj.buk_area_id) AS subareas_count,
        MIN(bj.buk_area_id) AS ejemplo_buk_area_id,
        MIN(bj.nombre) AS ejemplo_buk_area_desc
    FROM adp_empleados e
    LEFT JOIN buk_jerarquia bj
        ON bj.profundidad = 2
       AND bj.tipo_origen_adp = 'unidad'
       AND bj.codigo_adp = e.Unidad
       AND bj.buk_area_id IS NOT NULL
    WHERE e.Estado IN ('A','S')
    GROUP BY e.Cargo
";

$cargos = $db->consultar("
    SELECT
        c.cargo_adp_id,
        c.cargo_adp_desc,
        c.buk_role_id,
        c.estado,
        c.observacion,
        a.unidad_adp_codigo,
        a.unidad_adp_desc,
        a.subareas_count,
        a.ejemplo_buk_area_id,
        a.ejemplo_buk_area_desc
    FROM stisoft_mapeo_cargos c
    LEFT JOIN ({$subAreasPorCargo}) a
        ON a.cargo_adp_id = c.cargo_adp_id
    ORDER BY c.cargo_adp_id
");

// Contadores
$totales = ['total'=>0,'pendiente'=>0,'mapeado'=>0,'sin_equivalencia'=>0];
foreach ($cargos as $c) {
    $totales['total']++;
    if (isset($totales[$c['estado']])) $totales[$c['estado']]++;
}

?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="bg-gray-50">
<div class="min-h-screen grid grid-cols-12">

  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php $active='cargos'; include __DIR__ . '/../partials/sidebar.php'; ?>
  </div>

  <div class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col">

    <header class="border-b bg-white">
      <div class="max-w-7xl mx-auto px-4 py-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-col gap-1">
          <h1 class="text-lg font-semibold text-gray-900">Cargos ADP → Roles BUK (Nivel 3)</h1>
          <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600">
            <span>Total: <strong><?= (int)$totales['total'] ?></strong></span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-yellow-50 text-yellow-800">
              Pendientes: <?= (int)$totales['pendiente'] ?>
            </span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-800">
              Mapeados: <?= (int)$totales['mapeado'] ?>
            </span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-red-50 text-red-700">
              Sin equivalencia: <?= (int)$totales['sin_equivalencia'] ?>
            </span>
          </div>
          <p class="text-xs text-gray-500 mt-1">
            Asociación: Cargo → Subáreas (Unidad) NIVEL 3 según empleados activos y buk_jerarquia.
          </p>
        </div>

        <div class="flex flex-col items-end gap-2">
          <div class="flex flex-col gap-1 items-end">
            <?php if ($flashOk): ?>
              <div class="text-xs px-3 py-2 rounded-lg bg-green-50 text-green-700 border border-green-200 max-w-md">
                <?= h($flashOk) ?>
              </div>
            <?php endif; ?>
            <?php if ($flashError): ?>
              <div class="text-xs px-3 py-2 rounded-lg bg-red-50 text-red-700 border border-red-200 max-w-md">
                <?= h($flashError) ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <form method="post">
              <input type="hidden" name="action" value="sync_adp">
              <button type="submit"
                class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium bg-gray-900 text-white hover:bg-gray-800">
                1) Cargar / actualizar cargos ADP
              </button>
            </form>

            <form method="post" onsubmit="return confirm('¿Crear TODOS los cargos pendientes en BUK?');">
              <input type="hidden" name="action" value="crear_todos">
              <button type="submit"
                class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium bg-blue-600 text-white hover:bg-blue-700">
                2) Crear en BUK cargos pendientes (Nivel 3)
              </button>
            </form>

            <form method="post" onsubmit="return confirm('Esto deja todos los cargos en pendiente. ¿Continuar?');">
              <input type="hidden" name="action" value="reset_estados">
              <button type="submit"
                class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium bg-rose-100 text-rose-700 hover:bg-rose-200">
                Limpiar estados
              </button>
            </form>
          </div>
        </div>
      </div>
    </header>

    <main class="flex-1 overflow-y-auto">
      <div class="max-w-7xl mx-auto px-4 py-6 space-y-4">

        <!-- DEBUG llamadas API -->
        <section class="space-y-2">
          <h2 class="text-sm font-semibold text-gray-800">Debug llamadas API (<?= count($debugApi) ?>)</h2>

          <?php if (empty($debugApi)): ?>
            <div class="bg-gray-900 text-gray-100 rounded-xl p-4 text-xs">
              No hubo llamadas a la API en la última acción (puede ser porque no había subáreas nivel 3 para esos cargos).
            </div>
          <?php else: ?>
            <?php foreach ($debugApi as $idx => $d): ?>
              <?php
                $ok = ((int)$d['http_code'] >= 200 && (int)$d['http_code'] < 300);
                $border = $ok ? 'border-green-800' : 'border-red-800';
                $status = $ok ? 'text-green-400' : 'text-red-400';
              ?>
              <div class="bg-gray-900 text-gray-100 rounded-xl p-4 text-xs border-l-4 <?= $border ?>">
                <div class="flex justify-between items-center mb-3 pb-2 border-b border-gray-700">
                  <div class="flex flex-col">
                    <span class="font-bold text-sm">#<?= $idx+1 ?></span>
                    <span class="font-mono text-blue-300 mt-1"><?= h("POST ".$d['endpoint']) ?></span>
                    <span class="font-mono text-gray-300 mt-1"><?= h($d['url']) ?></span>
                  </div>
                  <div class="text-right">
                    <span class="font-bold text-lg <?= $status ?>">HTTP <?= h($d['http_code']) ?></span>
                    <?php if (!empty($d['curl_error'])): ?>
                      <div class="text-red-500 font-bold mt-1">CURL ERROR: <?= h($d['curl_error']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="grid md:grid-cols-2 gap-4">
                  <div class="bg-black/30 p-3 rounded border border-gray-700">
                    <div class="font-semibold text-gray-400 mb-2 border-b border-gray-600 pb-1">PAYLOAD</div>
                    <pre class="whitespace-pre-wrap overflow-x-auto text-green-300 font-mono text-[11px]"><?= h(json_encode($d['payload'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
                  </div>
                  <div class="bg-black/30 p-3 rounded border border-gray-700">
                    <div class="font-semibold text-gray-400 mb-2 border-b border-gray-600 pb-1">RESPONSE</div>
                    <pre class="whitespace-pre-wrap overflow-x-auto text-yellow-300 font-mono text-[11px]"><?= h(json_encode($d['response'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </section>

        <!-- Tabla cargos -->
        <section class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-800">Tabla de mapeo (stisoft_mapeo_cargos)</h2>
            <p class="text-xs text-gray-500">Mostrando <strong><?= count($cargos) ?></strong></p>
          </div>

          <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
              <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                <tr>
                  <th class="px-3 py-2 text-left">#</th>
                  <th class="px-3 py-2 text-left">ID Cargo ADP</th>
                  <th class="px-3 py-2 text-left">Descripción</th>
                  <th class="px-3 py-2 text-left">Ejemplo Unidad ADP</th>
                  <th class="px-3 py-2 text-left">Subáreas NIVEL 3</th>
                  <th class="px-3 py-2 text-left">Ejemplo BUK (nivel 3)</th>
                  <th class="px-3 py-2 text-left">Rol BUK ID</th>
                  <th class="px-3 py-2 text-left">Estado</th>
                  <th class="px-3 py-2 text-left">Obs</th>
                  <th class="px-3 py-2 text-left">Acción</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
              <?php if (empty($cargos)): ?>
                <tr><td colspan="10" class="px-3 py-4 text-center text-gray-500">Sin datos.</td></tr>
              <?php else: ?>
                <?php $i=1; foreach ($cargos as $cargo): ?>
                  <?php
                    $estado = $cargo['estado'] ?? 'pendiente';
                    $badgeClass = 'bg-gray-100 text-gray-700';
                    if ($estado === 'mapeado') $badgeClass = 'bg-emerald-100 text-emerald-800';
                    elseif ($estado === 'sin_equivalencia') $badgeClass = 'bg-red-100 text-red-800';
                    elseif ($estado === 'pendiente') $badgeClass = 'bg-yellow-50 text-yellow-800';
                  ?>
                  <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 text-gray-500"><?= $i++ ?></td>
                    <td class="px-3 py-2 whitespace-nowrap"><?= (int)$cargo['cargo_adp_id'] ?></td>
                    <td class="px-3 py-2">
                      <div class="max-w-xs truncate" title="<?= h($cargo['cargo_adp_desc']) ?>"><?= h($cargo['cargo_adp_desc']) ?></div>
                    </td>
                    <td class="px-3 py-2">
                      <?php if (!empty($cargo['unidad_adp_codigo'])): ?>
                        <div class="max-w-xs truncate" title="<?= h($cargo['unidad_adp_desc']) ?>">
                          [<?= h($cargo['unidad_adp_codigo']) ?>] <?= h($cargo['unidad_adp_desc']) ?>
                        </div>
                      <?php else: ?>
                        <span class="text-[11px] text-gray-400">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2">
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-violet-100 text-violet-800">
                        <?= (int)($cargo['subareas_count'] ?? 0) ?> subáreas
                      </span>
                    </td>
                    <td class="px-3 py-2">
                      <?php if (!empty($cargo['ejemplo_buk_area_id'])): ?>
                        <div class="max-w-xs truncate" title="<?= h($cargo['ejemplo_buk_area_desc']) ?>">
                          [<?= (int)$cargo['ejemplo_buk_area_id'] ?>] <?= h($cargo['ejemplo_buk_area_desc']) ?>
                        </div>
                      <?php else: ?>
                        <span class="text-[11px] text-gray-400">Sin mapeo nivel 3</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-3 py-2"><?= $cargo['buk_role_id'] ? (int)$cargo['buk_role_id'] : '-' ?></td>
                    <td class="px-3 py-2">
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium <?= $badgeClass ?>">
                        <?= h($estado) ?>
                      </span>
                    </td>
                    <td class="px-3 py-2">
                      <div class="max-w-sm truncate" title="<?= h($cargo['observacion']) ?>"><?= h($cargo['observacion']) ?></div>
                    </td>
                    <td class="px-3 py-2">
                      <?php if ($estado === 'pendiente'): ?>
                        <form method="post" class="inline">
                          <input type="hidden" name="action" value="crear_uno">
                          <input type="hidden" name="cargo_adp_id" value="<?= (int)$cargo['cargo_adp_id'] ?>">
                          <button type="submit"
                            class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-medium bg-blue-600 text-white hover:bg-blue-700">
                            Crear
                          </button>
                        </form>
                      <?php else: ?>
                        <span class="text-[11px] text-gray-400">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

      </div>
    </main>

  </div>
</div>
</body>
</html>
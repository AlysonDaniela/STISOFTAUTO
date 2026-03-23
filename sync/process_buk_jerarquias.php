<?php
declare(strict_types=1);

// /sync/process_buk_jerarquias.php
// Hace TODO en un solo proceso:
// 1) Empresas ADP -> tabla mapeo + regla Buk + vista resolver
// 2) Jerarquía -> crea áreas en Buk y actualiza buk_jerarquia
// 3) Cargos -> crea roles en Buk y actualiza stisoft_mapeo_cargos / buk_cargos / buk_cargo_area

if (php_sapi_name() !== 'cli') {
    echo "Este script debe ejecutarse desde CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../conexion/db.php';
require_once __DIR__ . '/../includes/runtime_config.php';
$db = new clsConexion();

$bukCfg = runtime_buk_config();
define('BUK_API_BASE', $bukCfg['base']);
define('BUK_API_TOKEN', $bukCfg['token']);

$DEFAULT_CITY = 'San Antonio';
$LOCATION_ID  = 94;

function norm($s): string
{
    $s = trim((string)$s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

function http_ok($code): bool
{
    return ((int)$code >= 200 && (int)$code < 300);
}

function out_result(array $result, int $exitCode = 0): void
{
    echo "SYNC_RESULT=" . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    exit($exitCode);
}

function buk_post_json(string $endpoint, array $payload): array
{
    $url = rtrim(BUK_API_BASE, '/') . '/' . ltrim($endpoint, '/');
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
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $decoded = null;
    if ($response !== false && $response !== '') {
        $decoded = json_decode($response, true);
    }

    return [
        'http_code'    => (int)($httpCode ?: 0),
        'body_raw'     => $response === false ? '' : (string)$response,
        'body_json'    => is_array($decoded) ? $decoded : null,
        'payload_json' => $jsonPayload,
        'endpoint'     => $endpoint,
        'url'          => $url,
        'curl_error'   => $curlErr,
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
            $body = json_encode(['empty' => true], JSON_UNESCAPED_UNICODE);
        } else {
            json_decode($body);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $body = json_encode(['raw' => $body], JSON_UNESCAPED_UNICODE);
            }
        }

        $bodyEsc = $db->real_escape_string($body);
        $tipoEsc = $db->real_escape_string($tipo);
        $refEsc  = $db->real_escape_string($ref);
        $metEsc  = $db->real_escape_string($method);

        $sql = "
            INSERT INTO stisoft_buk_logs
              (tipo_entidad, referencia_local, metodo_http, endpoint, payload, respuesta_http, respuesta_body)
            VALUES
              ('{$tipoEsc}', '{$refEsc}', '{$metEsc}', '{$endpoint}', '{$payload}', {$codigo}, '{$bodyEsc}')
        ";
        $db->ejecutar($sql);
    } catch (Throwable $e) {
        // no-op
    }
}

/* =========================================================
   EMPRESAS
   ========================================================= */

function ensure_empresas_table(clsConexion $db): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS stisoft_mapeo_empresas_codigo (
          empresa_adp_id INT NOT NULL,
          empresa_adp_desc VARCHAR(150) NULL,
          buk_empresa_id INT NULL,
          estado ENUM('pendiente','mapeado','condicional','sin_equivalencia') NOT NULL DEFAULT 'pendiente',
          observacion VARCHAR(255) NULL,
          PRIMARY KEY (empresa_adp_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $db->ejecutar($sql);
}

function map_empresa_sistema_a_buk(?int $empresaSistema): ?int
{
    if ($empresaSistema === null) return null;
    if ($empresaSistema === 3) return 2;
    if ($empresaSistema === 2) return 3;
    if ($empresaSistema === 1) return 1;
    return null;
}

function sync_empresas_adp(clsConexion $db): int
{
    ensure_empresas_table($db);

    $sql = "
        INSERT INTO stisoft_mapeo_empresas_codigo (empresa_adp_id, empresa_adp_desc, estado)
        SELECT
          CAST(e.Empresa AS SIGNED) AS empresa_adp_id,
          MIN(e.`Descripcion Empresa`) AS empresa_adp_desc,
          'pendiente' AS estado
        FROM adp_empleados e
        WHERE e.Estado = 'A'
          AND e.Empresa IS NOT NULL
          AND TRIM(e.Empresa) <> ''
        GROUP BY CAST(e.Empresa AS SIGNED)
        ON DUPLICATE KEY UPDATE
          empresa_adp_desc = VALUES(empresa_adp_desc)
    ";
    $db->ejecutar($sql);

    $res = $db->consultar("SELECT COUNT(*) AS total FROM stisoft_mapeo_empresas_codigo");
    return !empty($res) ? (int)$res[0]['total'] : 0;
}

function auto_map_empresas(clsConexion $db): array
{
    ensure_empresas_table($db);

    $rows = $db->consultar("
        SELECT empresa_adp_id, empresa_adp_desc, buk_empresa_id, estado
        FROM stisoft_mapeo_empresas_codigo
        ORDER BY empresa_adp_id
    ");

    $stats = [
        'total'         => 0,
        'mapeadas'      => 0,
        'condicionales' => 0,
        'sin_regla'     => 0,
        'cambios'       => 0,
    ];

    foreach ($rows as $r) {
        $stats['total']++;
        $id = (int)$r['empresa_adp_id'];
        $oldBuk = isset($r['buk_empresa_id']) && $r['buk_empresa_id'] !== null ? (int)$r['buk_empresa_id'] : null;
        $oldEstado = (string)($r['estado'] ?? 'pendiente');

        if ($id === 101) {
            $nuevoEstado = 'condicional';
            $obs = "DAMEC (101): se resuelve por Credencial por empleado (1/2/3) aplicando regla 3→2,2→3,1→1.";

            $db->ejecutar("
                UPDATE stisoft_mapeo_empresas_codigo
                SET buk_empresa_id = NULL,
                    estado = 'condicional',
                    observacion = '" . $db->real_escape_string($obs) . "'
                WHERE empresa_adp_id = {$id}
            ");

            $stats['condicionales']++;
            if ($oldEstado !== $nuevoEstado || $oldBuk !== null) {
                $stats['cambios']++;
            }
            continue;
        }

        $buk = map_empresa_sistema_a_buk($id);

        if ($buk === null) {
            $nuevoEstado = 'sin_equivalencia';
            $obs = "Sin regla de mapeo para empresa ADP={$id}.";

            $db->ejecutar("
                UPDATE stisoft_mapeo_empresas_codigo
                SET buk_empresa_id = NULL,
                    estado = 'sin_equivalencia',
                    observacion = '" . $db->real_escape_string($obs) . "'
                WHERE empresa_adp_id = {$id}
            ");

            $stats['sin_regla']++;
            if ($oldEstado !== $nuevoEstado || $oldBuk !== null) {
                $stats['cambios']++;
            }
            continue;
        }

        $db->ejecutar("
            UPDATE stisoft_mapeo_empresas_codigo
            SET buk_empresa_id = {$buk},
                estado = 'mapeado',
                observacion = NULL
            WHERE empresa_adp_id = {$id}
        ");

        $stats['mapeadas']++;
        if ($oldEstado !== 'mapeado' || $oldBuk !== $buk) {
            $stats['cambios']++;
        }
    }

    return $stats;
}

function create_or_replace_view_resolver(clsConexion $db): void
{
    $viewName = 'v_mapeo_empresas_buk_adp';
    $viewEsc = $db->real_escape_string($viewName);

    // Compatibilidad: en algunos ambientes este nombre quedó como tabla.
    // Si existe como tabla, la eliminamos para poder crear la vista.
    $exists = $db->consultar("
        SELECT TABLE_NAME, TABLE_TYPE
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = '{$viewEsc}'
        LIMIT 1
    ");

    if (!empty($exists) && is_array($exists[0])) {
        $type = strtoupper((string)($exists[0]['TABLE_TYPE'] ?? ''));
        if ($type === 'VIEW') {
            $db->ejecutar("DROP VIEW IF EXISTS `{$viewName}`");
        } else {
            $db->ejecutar("DROP TABLE IF EXISTS `{$viewName}`");
        }
    }

    $sql = "
        CREATE VIEW v_mapeo_empresas_buk_adp AS
        SELECT
          e.Rut,
          CAST(e.Empresa AS SIGNED) AS empresa_adp,
          e.`Descripcion Empresa` AS empresa_adp_desc,
          e.`Credencial` AS credencial,
          CASE
            WHEN CAST(e.Empresa AS SIGNED) = 101 THEN
              CASE CAST(e.`Credencial` AS SIGNED)
                WHEN 3 THEN 2
                WHEN 2 THEN 3
                WHEN 1 THEN 1
                ELSE NULL
              END
            WHEN CAST(e.Empresa AS SIGNED) = 3 THEN 2
            WHEN CAST(e.Empresa AS SIGNED) = 2 THEN 3
            WHEN CAST(e.Empresa AS SIGNED) = 1 THEN 1
            ELSE NULL
          END AS buk_empresa_id
        FROM adp_empleados e
        WHERE e.Estado = 'A'
          AND e.Empresa IS NOT NULL
          AND TRIM(e.Empresa) <> ''
    ";
    $db->ejecutar($sql);
}

/* =========================================================
   JERARQUÍA
   ========================================================= */

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

        $sqlDiv = "
            INSERT INTO buk_jerarquia
              (profundidad, tipo_origen_adp, codigo_adp, nombre, parent_div_code, parent_cc_code, estado)
            VALUES
              (0, 'division', '" . $db->real_escape_string($div_code) . "', '" . $db->real_escape_string($div_name) . "', '', '', 'pendiente')
            ON DUPLICATE KEY UPDATE
              nombre=VALUES(nombre)
        ";
        if ($db->ejecutar($sqlDiv)) $count_div++;

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
        'rows_distinct' => count($rows),
        'div_upserts'   => $count_div,
        'cc_upserts'    => $count_cc,
        'un_upserts'    => $count_un,
    ];
}

function jer_get_buk_id_div(clsConexion $db, string $div_code): ?int
{
    $div_code = $db->real_escape_string(norm($div_code));
    $r = $db->consultar("
        SELECT buk_area_id
        FROM buk_jerarquia
        WHERE profundidad=0
          AND tipo_origen_adp='division'
          AND codigo_adp='{$div_code}'
          AND parent_div_code=''
          AND parent_cc_code=''
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
        WHERE profundidad=1
          AND tipo_origen_adp='centro_costo'
          AND codigo_adp='{$cc_code}'
          AND parent_div_code='{$div_code}'
          AND parent_cc_code=''
          AND estado='mapeado'
          AND buk_area_id IS NOT NULL
        LIMIT 1
    ");
    if (empty($r)) return null;
    return (int)$r[0]['buk_area_id'];
}

function jer_update_last_call(clsConexion $db, int $id, array $apiRaw, string $estado, ?int $bukId, ?int $bukParentId, string $obs): void
{
    $estadoEsc = $db->real_escape_string($estado);
    $obsEsc    = $db->real_escape_string($obs);
    $endpoint  = $db->real_escape_string($apiRaw['endpoint'] ?? '');
    $payload   = $db->real_escape_string($apiRaw['payload_json'] ?? '');
    $resp      = $db->real_escape_string($apiRaw['body_raw'] ?? '');
    $http      = (int)($apiRaw['http_code'] ?? 0);

    $bukIdSql = ($bukId === null) ? "NULL" : (int)$bukId;
    $bukParentSql = ($bukParentId === null) ? "NULL" : (int)$bukParentId;

    $db->ejecutar("
        UPDATE buk_jerarquia
        SET
          estado='{$estadoEsc}',
          observacion='{$obsEsc}',
          buk_area_id={$bukIdSql},
          buk_parent_id={$bukParentSql},
          last_endpoint='{$endpoint}',
          last_payload='{$payload}',
          last_response='{$resp}',
          last_http_code={$http}
        WHERE id=" . (int)$id
    );
}

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

    if ($parentBukId !== null) {
        $payload['parent_id'] = (int)$parentBukId;
    }

    $api = buk_post_json('/organization/areas', $payload);
    log_buk_safe($db, 'area', 'jerarquia_id:' . $node['id'], 'POST', $api);

    return [
        'http' => (int)$api['http_code'],
        'body' => is_array($api['body_json']) ? $api['body_json'] : [],
        'raw'  => $api,
    ];
}

function crear_pendientes_en_buk_jerarquia(clsConexion $db, string $defaultCity, int $locationId): array
{
    ensure_buk_jerarquia($db);

    $stats = [
        'procesadas' => 0,
        'ok'         => 0,
        'fail'       => 0,
        'skip'       => 0,
        'cambios'    => 0,
    ];

    for ($depth = 0; $depth <= 2; $depth++) {
        $nodes = $db->consultar("
            SELECT *
            FROM buk_jerarquia
            WHERE profundidad={$depth}
              AND (estado='pendiente' OR estado='sin_equivalencia')
              AND (buk_area_id IS NULL OR buk_area_id=0)
            ORDER BY id
        ");

        foreach ($nodes as $node) {
            $stats['procesadas']++;

            $nombre = norm($node['nombre']);
            if ($nombre === '') {
                jer_update_last_call($db, (int)$node['id'], ['endpoint'=>'','payload_json'=>'','body_raw'=>'','http_code'=>0], 'sin_equivalencia', null, null, 'Nombre vacío');
                $stats['fail']++;
                $stats['cambios']++;
                continue;
            }

            $parentBukId = null;

            if ($depth === 1) {
                $parentBukId = jer_get_buk_id_div($db, (string)$node['parent_div_code']);
                if (!$parentBukId) {
                    $stats['skip']++;
                    continue;
                }
            }

            if ($depth === 2) {
                $parentBukId = jer_get_buk_id_cc($db, (string)$node['parent_div_code'], (string)$node['parent_cc_code']);
                if (!$parentBukId) {
                    $stats['skip']++;
                    continue;
                }
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
                    $stats['cambios']++;
                } else {
                    jer_update_last_call($db, (int)$node['id'], $apiRaw, 'sin_equivalencia', null, $parentBukId, 'Respuesta sin id');
                    $stats['fail']++;
                    $stats['cambios']++;
                }
            } else {
                $msg = "HTTP {$http}";
                if (is_array($body) && !empty($body)) {
                    $msg .= ' - ' . substr(json_encode($body, JSON_UNESCAPED_UNICODE), 0, 180);
                }
                jer_update_last_call($db, (int)$node['id'], $apiRaw, 'sin_equivalencia', null, $parentBukId, $msg);
                $stats['fail']++;
                $stats['cambios']++;
            }
        }
    }

    return $stats;
}

/* =========================================================
   CARGOS
   ========================================================= */

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

function obtener_subareas_nivel3_por_cargo(clsConexion $db, int $cargoAdpId): array
{
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

function crear_cargo_en_buk(clsConexion $db, array $cargoRow): array
{
    $cargoAdpId   = (int)$cargoRow['cargo_adp_id'];
    $cargoAdpDesc = norm($cargoRow['cargo_adp_desc'] ?? '');

    $areaIds = obtener_subareas_nivel3_por_cargo($db, $cargoAdpId);

    if (empty($areaIds)) {
        $obs = "Sin subáreas NIVEL 3 mapeadas para este cargo.";

        $db->ejecutar("
            UPDATE stisoft_mapeo_cargos
            SET buk_role_id = NULL,
                estado      = 'sin_equivalencia',
                observacion = '" . $db->real_escape_string($obs) . "'
            WHERE cargo_adp_id = {$cargoAdpId}
        ");

        return [
            'ok'          => false,
            'estado'      => 'sin_equivalencia',
            'observacion' => $obs,
            'buk_role_id' => null,
            'cambio'      => true,
        ];
    }

    $codigoBuk = 'ADP_' . $cargoAdpId;

    $payload = [
        "name"              => $cargoAdpDesc,
        "code"              => $codigoBuk,
        "description"       => $cargoAdpDesc,
        "requirements"      => "",
        "role_family_id"    => null,
        "area_ids"          => $areaIds,
        "custom_attributes" => new stdClass(),
    ];

    $apiResp = buk_post_json('/roles', $payload);
    log_buk_safe($db, 'cargo', 'cargo_adp:' . $cargoAdpId, 'POST', $apiResp);

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
            $obs = substr((string)($apiResp['body_raw'] ?? ''), 0, 250);
        }
    }

    $obsSql = $obs ? "'" . $db->real_escape_string($obs) . "'" : "NULL";
    $db->ejecutar("
        UPDATE stisoft_mapeo_cargos
        SET buk_role_id = " . ($bukRoleId !== null ? $bukRoleId : "NULL") . ",
            estado      = '" . $db->real_escape_string($estado) . "',
            observacion = {$obsSql}
        WHERE cargo_adp_id = {$cargoAdpId}
    ");

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
        'ok'          => ($bukRoleId !== null),
        'estado'      => $estado,
        'observacion' => $obs,
        'buk_role_id' => $bukRoleId,
        'cambio'      => true,
    ];
}

function crear_cargos_pendientes_en_buk(clsConexion $db, int $limit = 100): array
{
    $rows = $db->consultar("
        SELECT cargo_adp_id, cargo_adp_desc
        FROM stisoft_mapeo_cargos
        WHERE estado = 'pendiente'
        ORDER BY cargo_adp_id
        LIMIT {$limit}
    ");

    $stats = [
        'procesados' => 0,
        'ok'         => 0,
        'fail'       => 0,
        'cambios'    => 0,
    ];

    foreach ($rows as $row) {
        $res = crear_cargo_en_buk($db, $row);
        $stats['procesados']++;
        if (!empty($res['ok'])) $stats['ok']++;
        else $stats['fail']++;
        if (!empty($res['cambio'])) $stats['cambios']++;
    }

    return $stats;
}

/* =========================================================
   MAIN
   ========================================================= */

try {
    // EMPRESAS
    $empresasTotal = sync_empresas_adp($db);
    $empresasMap   = auto_map_empresas($db);
    create_or_replace_view_resolver($db);

    // JERARQUÍA
    $jerSync = sync_from_adp_swap_to_jerarquia($db);
    $jerBuk  = crear_pendientes_en_buk_jerarquia($db, $DEFAULT_CITY, $LOCATION_ID);

    // CARGOS
    $cargosTotal = sync_cargos_adp_a_mapeo($db);
    $cargosBuk   = crear_cargos_pendientes_en_buk($db, 100);

    $result = [
        'status' => 'ok',
        'tipo'   => 'buk_jerarquias',
        'message'=> 'Jerarquías enviadas a BUK correctamente.',
        'empresas' => [
            'total_tabla'    => $empresasTotal,
            'mapeadas'       => $empresasMap['mapeadas'],
            'condicionales'  => $empresasMap['condicionales'],
            'sin_regla'      => $empresasMap['sin_regla'],
            'cambios_nuevos' => $empresasMap['cambios'],
        ],
        'jerarquia' => [
            'rows_distinct'   => $jerSync['rows_distinct'],
            'div_upserts'     => $jerSync['div_upserts'],
            'cc_upserts'      => $jerSync['cc_upserts'],
            'un_upserts'      => $jerSync['un_upserts'],
            'procesadas_buk'  => $jerBuk['procesadas'],
            'ok_buk'          => $jerBuk['ok'],
            'fail_buk'        => $jerBuk['fail'],
            'skip_buk'        => $jerBuk['skip'],
            'cambios_nuevos'  => $jerBuk['cambios'],
        ],
        'cargos' => [
            'total_tabla'     => $cargosTotal,
            'procesados_buk'  => $cargosBuk['procesados'],
            'ok_buk'          => $cargosBuk['ok'],
            'fail_buk'        => $cargosBuk['fail'],
            'cambios_nuevos'  => $cargosBuk['cambios'],
        ],
    ];

    echo "Proceso BUK jerarquías completado.\n";
    echo "Empresas -> cambios: {$empresasMap['cambios']}\n";
    echo "Jerarquía -> cambios: {$jerBuk['cambios']}, OK: {$jerBuk['ok']}, FAIL: {$jerBuk['fail']}, SKIP: {$jerBuk['skip']}\n";
    echo "Cargos -> cambios: {$cargosBuk['cambios']}, OK: {$cargosBuk['ok']}, FAIL: {$cargosBuk['fail']}\n";

    out_result($result, 0);

} catch (Throwable $e) {
    out_result([
        'status'  => 'error',
        'tipo'    => 'buk_jerarquias',
        'message' => $e->getMessage(),
    ], 1);
}

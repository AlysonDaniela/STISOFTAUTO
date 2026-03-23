<?php
// /sync/empresas_sync_adp_buk.php
// Sincroniza EMPRESAS desde BUK hacia la BD local y mapea contra ADP.

// Mostrar errores mientras probamos (luego puedes quitarlo o bajarlo en producción)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';
require_auth();

require_once __DIR__ . '/../conexion/db.php';

$db = new clsConexion();

/**
 * Constantes de BUK
 * Si ya están definidas en otro archivo, estos define() no harán nada.
 *
 * En tu caso ya tienes algo así:
 *   const BUK_TOKEN    = '...';
 *   const BUK_API_BASE = 'https://sti-test.buk.cl/api/v1/chile';
 *
 * Aquí las mapeamos a defines para usarlas sin romper nada.
 */

if (!defined('BUK_TOKEN')) {
    define('BUK_TOKEN', 'K63xJHSj1v4J7pqnCgkro8X1');
}
if (!defined('BUK_API_BASE')) {
    define('BUK_API_BASE', 'https://sti-test.buk.cl/api/v1/chile');
}

if (!defined('BUK_API_BASE_URL')) {
    define('BUK_API_BASE_URL', BUK_API_BASE);
}
if (!defined('BUK_API_TOKEN')) {
    define('BUK_API_TOKEN', BUK_TOKEN);
}

// Endpoint de LISTADO de empresas en BUK (no creamos, sólo leemos)
$ENDPOINT_EMPRESAS_BUK = '/companies';

// ================= HELPER FUNCTIONS =================

function normalizarRut(string $rut): string
{
    $rut = trim($rut);
    $rut = str_replace(['.', ' ', '-'], ['', '', '-'], $rut);
    $rut = str_replace('.', '', $rut);
    return strtoupper($rut);
}

function db_escape(clsConexion $db, ?string $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    return "'" . $db->real_escape_string($value) . "'";
}

/**
 * Registra una llamada a la API de BUK en stisoft_buk_logs.
 */
function logBukCall(
    clsConexion $db,
    string $tipoEntidad,
    string $referenciaLocal,
    string $metodo,
    string $endpoint,
    array $payload,
    ?int $httpCode,
    ?array $respuestaBody
): void {
    $payloadJson   = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $respuestaJson = $respuestaBody ? json_encode($respuestaBody, JSON_UNESCAPED_UNICODE) : null;

    $sql = "
        INSERT INTO stisoft_buk_logs
            (tipo_entidad, referencia_local, metodo_http, endpoint, payload, respuesta_http, respuesta_body)
        VALUES (
            " . db_escape($db, $tipoEntidad) . ",
            " . db_escape($db, $referenciaLocal) . ",
            " . db_escape($db, $metodo) . ",
            " . db_escape($db, $endpoint) . ",
            " . db_escape($db, $payloadJson) . ",
            " . ($httpCode !== null ? (int)$httpCode : 'NULL') . ",
            " . db_escape($db, $respuestaJson) . "
        )
    ";
    $db->ejecutar($sql);
}

/**
 * Hace un GET a BUK y devuelve [statusCode, bodyArray|null].
 */
function bukGet(clsConexion $db, string $endpoint, array $query = [], string $ref = ''): array
{
    if (!defined('BUK_API_BASE_URL') || !defined('BUK_API_TOKEN')) {
        throw new Exception('BUK_API_BASE_URL o BUK_API_TOKEN no están definidos.');
    }

    $baseUrl = rtrim(BUK_API_BASE_URL, '/');
    $url     = $baseUrl . $endpoint;

    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            // ⚠️ Si en tu script de empleados usas otro header (por ej. "Token token=..."),
            // cambia esta línea para que sea igual.
            'Authorization: Bearer ' . BUK_API_TOKEN,
        ],
        CURLOPT_HTTPGET    => true,
        CURLOPT_TIMEOUT    => 60,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError    = curl_error($ch);
    curl_close($ch);

    $decoded = null;
    if ($responseBody !== false && $responseBody !== '') {
        $decoded = json_decode($responseBody, true);
    }

    if ($responseBody === false && $curlError) {
        $decoded = ['errors' => ["cURL error: {$curlError}"]];
    }

    logBukCall(
        $db,
        'empresa_list',
        $ref,
        'GET',
        $endpoint,
        $query,
        $httpCode ?: null,
        $decoded
    );

    return [$httpCode ?: 0, $decoded];
}

// ================= 1) LEER EMPRESAS DESDE BUK =================

$empresasBuk = [];
$page        = 1;
$hasMore     = true;

while ($hasMore) {
    [$status, $body] = bukGet($db, $ENDPOINT_EMPRESAS_BUK, ['page' => $page], 'page_' . $page);

    if (!in_array($status, [200, 201], true) || !is_array($body)) {
        // En caso de error, salimos del bucle
        $hasMore = false;
        break;
    }

    if (!isset($body['data']) || !is_array($body['data'])) {
        $hasMore = false;
        break;
    }

    // Agregar empresas de esta página
    foreach ($body['data'] as $empresa) {
        $empresasBuk[] = $empresa;
    }

    // Manejar paginación (según ejemplo que nos diste)
    if (!empty($body['pagination']['next'])) {
        $page++;
    } else {
        $hasMore = false;
    }
}

$importadasBuk = 0;

// Guardar/actualizar empresas en buk_empresas
foreach ($empresasBuk as $e) {
    $idBuk   = isset($e['id']) ? (int)$e['id'] : null;
    $name    = $e['name']    ?? '';
    $address = $e['address'] ?? '';
    $commune = $e['commune'] ?? '';
    $city    = $e['city']    ?? '';
    $email   = $e['company_email']   ?? '';
    $business= $e['company_business']?? '';
    $rut     = $e['rut']     ?? '';

    if (!$idBuk) {
        continue;
    }

    $sql = "
        INSERT INTO buk_empresas
            (id_buk, name, address, commune, ciudad, company_email, company_business, rut, origen, fecha_ultima_sync, raw_response)
        VALUES (
            {$idBuk},
            " . db_escape($db, $name) . ",
            " . db_escape($db, $address) . ",
            " . db_escape($db, $commune) . ",
            " . db_escape($db, $city) . ",
            " . db_escape($db, $email) . ",
            " . db_escape($db, $business) . ",
            " . db_escape($db, $rut) . ",
            'buk_import',
            NOW(),
            " . db_escape($db, json_encode($e, JSON_UNESCAPED_UNICODE)) . "
        )
        ON DUPLICATE KEY UPDATE
            name              = VALUES(name),
            address           = VALUES(address),
            commune           = VALUES(commune),
            ciudad            = VALUES(ciudad),
            company_email     = VALUES(company_email),
            company_business  = VALUES(company_business),
            rut               = VALUES(rut),
            origen            = VALUES(origen),
            fecha_ultima_sync = VALUES(fecha_ultima_sync),
            raw_response      = VALUES(raw_response)
    ";
    $db->ejecutar($sql);
    $importadasBuk++;
}

// ================= 2) ASEGURAR MAPEOS ADP =================

// Primero, aseguramos que existan filas en stisoft_mapeo_empresas por cada empresa ADP.
$sqlMapSeed = "
    INSERT INTO stisoft_mapeo_empresas (rut_adp, empresa_adp, estado)
    SELECT DISTINCT
        `Rut Empresa`        AS rut_adp,
        `Descripcion Empresa` AS empresa_adp,
        'pendiente'          AS estado
    FROM adp_empleados
    WHERE `Rut Empresa` IS NOT NULL AND `Rut Empresa` <> ''
    ON DUPLICATE KEY UPDATE
        empresa_adp = VALUES(empresa_adp)
";
$db->ejecutar($sqlMapSeed);

// Luego, auto-mapeamos por RUT: buk_empresas.rut ↔ stisoft_mapeo_empresas.rut_adp
$rowsBuk = $db->consultar("SELECT id_buk, rut, name FROM buk_empresas");
$mapBuk  = [];

foreach ($rowsBuk as $row) {
    $rutNorm = normalizarRut($row['rut'] ?? '');
    if ($rutNorm !== '') {
        $mapBuk[$rutNorm] = $row;
    }
}

$mapeos    = $db->consultar("SELECT rut_adp, empresa_adp, buk_empresa_id FROM stisoft_mapeo_empresas");
$mapeados  = 0;
$sinMatch  = 0;
$detalles  = [];

foreach ($mapeos as $m) {
    $rutAdp   = $m['rut_adp'] ?? '';
    $rutNorm  = normalizarRut($rutAdp);

    if ($rutNorm === '' || !isset($mapBuk[$rutNorm])) {
        $sinMatch++;
        $detalles[] = [
            'rut_adp'     => $rutAdp,
            'empresa_adp' => $m['empresa_adp'] ?? '',
            'status'      => 'sin_match',
            'id_buk'      => null,
            'mensaje'     => 'No se encontró empresa en BUK con el mismo RUT',
        ];
        continue;
    }

    $idBuk = (int)$mapBuk[$rutNorm]['id_buk'];

    // Sólo actualizamos si no estaba mapeado
    if (empty($m['buk_empresa_id']) || (int)$m['buk_empresa_id'] !== $idBuk) {
        $sqlUpd = "
            UPDATE stisoft_mapeo_empresas
            SET buk_empresa_id = {$idBuk},
                estado = 'mapeado'
            WHERE rut_adp = " . db_escape($db, $rutAdp) . "
            LIMIT 1
        ";
        $db->ejecutar($sqlUpd);
        $mapeados++;

        $detalles[] = [
            'rut_adp'     => $rutAdp,
            'empresa_adp' => $m['empresa_adp'] ?? '',
            'status'      => 'mapeado',
            'id_buk'      => $idBuk,
            'mensaje'     => 'Rut coincide con empresa BUK: ' . ($mapBuk[$rutNorm]['name'] ?? ''),
        ];
    }
}

// ================= SALIDA HTML =================

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Empresas BUK → STISOFT (mapeo con ADP)</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 1.5rem; }
        table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
        th, td { border: 1px solid #ddd; padding: 0.4rem 0.6rem; font-size: 0.9rem; }
        th { background: #f3f4f6; text-align: left; }
        .ok { color: #16a34a; font-weight: 600; }
        .warn { color: #92400e; font-weight: 600; }
    </style>
</head>
<body>
    <h1>Empresas BUK → STISOFT (mapeo con ADP)</h1>

    <p><strong>Empresas leídas desde BUK:</strong> <?= htmlspecialchars((string)$importadasBuk) ?></p>
    <p><strong>Mapeos ADP actualizados (por RUT):</strong> <span class="ok"><?= htmlspecialchars((string)$mapeados) ?></span></p>
    <p><strong>Empresas ADP sin match automático por RUT:</strong> <span class="warn"><?= htmlspecialchars((string)$sinMatch) ?></span></p>

    <table>
        <thead>
            <tr>
                <th>RUT ADP</th>
                <th>Empresa ADP</th>
                <th>Estado mapeo</th>
                <th>ID BUK</th>
                <th>Mensaje</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($detalles as $d): ?>
            <tr>
                <td><?= htmlspecialchars($d['rut_adp'] ?? '') ?></td>
                <td><?= htmlspecialchars($d['empresa_adp'] ?? '') ?></td>
                <td class="<?= $d['status'] === 'mapeado' ? 'ok' : 'warn' ?>">
                    <?= htmlspecialchars(strtoupper($d['status'])) ?>
                </td>
                <td><?= htmlspecialchars($d['id_buk'] !== null ? (string)$d['id_buk'] : '') ?></td>
                <td><?= htmlspecialchars($d['mensaje'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p style="margin-top:1rem; font-size:0.85rem; color:#6b7280;">
        Cada llamada a la API se registró en <code>stisoft_buk_logs</code> (tipo_entidad = 'empresa_list').
    </p>
</body>
</html>
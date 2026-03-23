<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acceso denegado.');
}

if (is_file(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
}
require_once __DIR__ . '/../includes/runtime_config.php';
if (!class_exists('Conexion')) {
    /**
     * Compatibilidad: en este proyecto la conexión principal vive en conexion/db.php (clsConexion).
     * Este script histórico usa Conexion(PDO), así que creamos un wrapper equivalente.
     */
    class Conexion {
        public function getConexion(): PDO {
            $dbCfg = runtime_db_config();
            $host = $dbCfg['host'];
            $db_name = $dbCfg['name'];
            $user = $dbCfg['user'];
            $pass = $dbCfg['pass'];

            $dsn = "mysql:host={$host};dbname={$db_name};charset=utf8mb4";
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
    }
}

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json');
}

$start = microtime(true);

$result = [
    "status" => "ok",
    "tipo" => "jerarquias",
    "empresas" => [],
    "jerarquia" => [],
    "cargos" => [],
    "message" => ""
];

try {

    $db = (new Conexion())->getConexion();

    $getCols = function (PDO $db, string $table): array {
        $sqlCols = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
        $stmtCols = $db->prepare($sqlCols);
        $stmtCols->execute([$table]);
        $cols = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_map('strval', is_array($cols) ? $cols : []));
    };

    $findCol = function (array $cols, array $candidates): ?string {
        $norm = static function (string $v): string {
            $v = mb_strtolower(trim($v), 'UTF-8');
            $v = strtr($v, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);
            $v = preg_replace('/[^a-z0-9]/', '', $v);
            return $v;
        };
        $map = [];
        foreach ($cols as $c) {
            $map[$norm((string)$c)] = (string)$c;
        }
        foreach ($candidates as $cand) {
            $k = $norm((string)$cand);
            if (isset($map[$k])) {
                return $map[$k];
            }
        }
        return null;
    };

    $adpCols = $getCols($db, 'adp_empleados');
    $empresasCols = $getCols($db, 'stisoft_mapeo_empresas_codigo');
    $jerarquiaCols = $getCols($db, 'buk_jerarquia');
    $cargosCols = $getCols($db, 'stisoft_mapeo_cargos');

    /*
    ============================================
    1. EMPRESAS
    ============================================
    */

    $empresaCol = $findCol($adpCols, ['Empresa']);
    if ($empresaCol === null) {
        throw new RuntimeException("No se encontró columna Empresa en adp_empleados");
    }
    $nombreEmpresaCol = $findCol($adpCols, ['NombreEmpresa', 'Nombre Empresa', 'RazonSocial', 'Razon Social', 'EmpresaNombre']);
    $nombreExpr = $nombreEmpresaCol !== null
        ? "COALESCE(NULLIF(TRIM(`{$nombreEmpresaCol}`), ''), TRIM(`{$empresaCol}`))"
        : "TRIM(`{$empresaCol}`)";

    $sql = "
        SELECT DISTINCT
            TRIM(`{$empresaCol}`) AS Empresa,
            {$nombreExpr} AS NombreEmpresa
        FROM adp_empleados
        WHERE TRIM(`{$empresaCol}`) <> ''
    ";

    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $empNuevas = 0;

    foreach ($rows as $r) {

        $codigo = trim($r['Empresa']);
        $nombre = trim($r['NombreEmpresa']);

        $empresaIdCol = $findCol($empresasCols, ['empresa_adp_id']);
        $empresaDescCol = $findCol($empresasCols, ['empresa_adp_desc']);
        $empresaEstadoCol = $findCol($empresasCols, ['estado']);
        if ($empresaIdCol === null || $empresaDescCol === null || $empresaEstadoCol === null) {
            throw new RuntimeException("stisoft_mapeo_empresas_codigo no tiene el esquema esperado");
        }

        $stmt = $db->prepare("
            INSERT INTO stisoft_mapeo_empresas_codigo
            (`{$empresaIdCol}`, `{$empresaDescCol}`, `{$empresaEstadoCol}`)
            VALUES (?, ?, 'pendiente')
            ON DUPLICATE KEY UPDATE
            `{$empresaDescCol}` = VALUES(`{$empresaDescCol}`)
        ");

        $stmt->execute([(int)$codigo, $nombre]);

        if ($stmt->rowCount() === 1) {
            $empNuevas++;
        }
    }

    $result["empresas"] = [
        "nuevas" => $empNuevas
    ];


    /*
    ============================================
    2. JERARQUIA
    ============================================
    */

    $divisionCodeCol = $findCol($adpCols, ['Division', 'División']);
    $divisionNameCol = $findCol($adpCols, ['Descripcion Division', 'Descripción División', 'Descripcion División']);
    $ccCodeCol = $findCol($adpCols, ['CentroCosto', 'Centro Costo', 'Centro de Costo']);
    $ccNameCol = $findCol($adpCols, ['Descripcion CentroCosto', 'Descripcion Centro Costo', 'Descripcion Centro de Costo', 'Descripción Centro de Costo']);
    $unidadCodeCol = $findCol($adpCols, ['Unidad']);
    $unidadNameCol = $findCol($adpCols, ['Descripcion Unidad', 'Descripción Unidad']);
    if ($divisionCodeCol === null || $ccCodeCol === null || $unidadCodeCol === null) {
        throw new RuntimeException("No se encontraron columnas base de jerarquía en adp_empleados");
    }

    $sql = "
        SELECT DISTINCT
            TRIM(`{$divisionCodeCol}`) AS Division,
            " . ($divisionNameCol !== null ? "TRIM(`{$divisionNameCol}`)" : "TRIM(`{$divisionCodeCol}`)") . " AS DivisionNombre,
            TRIM(`{$ccCodeCol}`) AS CentroCosto,
            " . ($ccNameCol !== null ? "TRIM(`{$ccNameCol}`)" : "TRIM(`{$ccCodeCol}`)") . " AS CentroCostoNombre,
            TRIM(`{$unidadCodeCol}`) AS Unidad,
            " . ($unidadNameCol !== null ? "TRIM(`{$unidadNameCol}`)" : "TRIM(`{$unidadCodeCol}`)") . " AS UnidadNombre
        FROM adp_empleados
        WHERE TRIM(`{$divisionCodeCol}`) <> ''
    ";

    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $divNuevas = 0;
    $ccNuevos = 0;
    $unNuevas = 0;

    foreach ($rows as $r) {

        $division = trim((string)$r['Division']);
        $divisionNombre = trim((string)($r['DivisionNombre'] ?? $division));
        $cc = trim((string)$r['CentroCosto']);
        $ccNombre = trim((string)($r['CentroCostoNombre'] ?? $cc));
        $un = trim((string)$r['Unidad']);
        $unNombre = trim((string)($r['UnidadNombre'] ?? $un));

        $profCol = $findCol($jerarquiaCols, ['profundidad']);
        $tipoCol = $findCol($jerarquiaCols, ['tipo_origen_adp']);
        $codigoCol = $findCol($jerarquiaCols, ['codigo_adp']);
        $nombreColJer = $findCol($jerarquiaCols, ['nombre']);
        $parentDivCol = $findCol($jerarquiaCols, ['parent_div_code']);
        $parentCcCol = $findCol($jerarquiaCols, ['parent_cc_code']);
        $estadoJerCol = $findCol($jerarquiaCols, ['estado']);
        if ($profCol === null || $tipoCol === null || $codigoCol === null || $nombreColJer === null || $parentDivCol === null || $parentCcCol === null || $estadoJerCol === null) {
            throw new RuntimeException("buk_jerarquia no tiene el esquema esperado");
        }

        // DIVISION

        if ($division !== '') {

            $stmt = $db->prepare("
                INSERT INTO buk_jerarquia
                (`{$profCol}`, `{$tipoCol}`, `{$codigoCol}`, `{$nombreColJer}`, `{$parentDivCol}`, `{$parentCcCol}`, `{$estadoJerCol}`)
                VALUES (0, 'division', ?, ?, '', '', 'pendiente')
                ON DUPLICATE KEY UPDATE
                `{$nombreColJer}` = VALUES(`{$nombreColJer}`)
            ");

            $stmt->execute([$division, $divisionNombre]);

            if ($stmt->rowCount() === 1) {
                $divNuevas++;
            }
        }

        // CENTRO COSTO

        if ($cc !== '') {

            $stmt = $db->prepare("
                INSERT INTO buk_jerarquia
                (`{$profCol}`, `{$tipoCol}`, `{$codigoCol}`, `{$nombreColJer}`, `{$parentDivCol}`, `{$parentCcCol}`, `{$estadoJerCol}`)
                VALUES (1, 'centro_costo', ?, ?, ?, '', 'pendiente')
                ON DUPLICATE KEY UPDATE
                `{$nombreColJer}` = VALUES(`{$nombreColJer}`)
            ");

            $stmt->execute([$cc, $ccNombre, $division]);

            if ($stmt->rowCount() === 1) {
                $ccNuevos++;
            }
        }

        // UNIDAD

        if ($un !== '') {

            $stmt = $db->prepare("
                INSERT INTO buk_jerarquia
                (`{$profCol}`, `{$tipoCol}`, `{$codigoCol}`, `{$nombreColJer}`, `{$parentDivCol}`, `{$parentCcCol}`, `{$estadoJerCol}`)
                VALUES (2, 'unidad', ?, ?, ?, ?, 'pendiente')
                ON DUPLICATE KEY UPDATE
                `{$nombreColJer}` = VALUES(`{$nombreColJer}`)
            ");

            $stmt->execute([$un, $unNombre, $division, $cc]);

            if ($stmt->rowCount() === 1) {
                $unNuevas++;
            }
        }
    }

    $result["jerarquia"] = [
        "divisiones_nuevas" => $divNuevas,
        "cc_nuevos" => $ccNuevos,
        "unidades_nuevas" => $unNuevas
    ];


    /*
    ============================================
    3. CARGOS
    ============================================
    */

    $cargoIdCol = $findCol($adpCols, ['Cargo', 'Codigo Cargo', 'Código Cargo']);
    $cargoDescCol = $findCol($adpCols, ['Descripcion Cargo', 'Descripción Cargo', 'CargoDesc']);
    if ($cargoIdCol === null) {
        throw new RuntimeException("No se encontró columna de cargo en adp_empleados");
    }

    $sql = "
        SELECT DISTINCT
            TRIM(`{$cargoIdCol}`) AS Cargo,
            " . ($cargoDescCol !== null ? "TRIM(`{$cargoDescCol}`)" : "TRIM(`{$cargoIdCol}`)") . " AS CargoNombre
        FROM adp_empleados
        WHERE TRIM(`{$cargoIdCol}`) <> ''
    ";

    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $cargosNuevos = 0;

    foreach ($rows as $r) {

        $cargo = trim((string)$r['Cargo']);
        $cargoNombre = trim((string)($r['CargoNombre'] ?? $cargo));

        $cargoIdMapCol = $findCol($cargosCols, ['cargo_adp_id']);
        $cargoDescMapCol = $findCol($cargosCols, ['cargo_adp_desc']);
        $cargoEstadoMapCol = $findCol($cargosCols, ['estado']);
        if ($cargoIdMapCol === null || $cargoDescMapCol === null || $cargoEstadoMapCol === null) {
            throw new RuntimeException("stisoft_mapeo_cargos no tiene el esquema esperado");
        }

        $stmt = $db->prepare("
            INSERT INTO stisoft_mapeo_cargos
            (`{$cargoIdMapCol}`, `{$cargoDescMapCol}`, `{$cargoEstadoMapCol}`)
            VALUES (?, ?, 'pendiente')
            ON DUPLICATE KEY UPDATE
            `{$cargoDescMapCol}` = VALUES(`{$cargoDescMapCol}`)
        ");

        $stmt->execute([(int)$cargo, $cargoNombre]);

        if ($stmt->rowCount() === 1) {
            $cargosNuevos++;
        }
    }

    $result["cargos"] = [
        "nuevos" => $cargosNuevos
    ];


    $result["message"] = "Jerarquías sincronizadas correctamente";

} catch (Throwable $e) {

    $result["status"] = "error";
    $result["message"] = $e->getMessage();

}

$end = microtime(true);

$result["duration"] = round($end - $start, 2);

echo "SYNC_RESULT=" . json_encode($result, JSON_UNESCAPED_UNICODE);

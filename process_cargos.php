<?php
// /sync/process_cargos.php — CLI para procesar archivos de cargos automáticamente
// Sincroniza cargos a stisoft_mapeo_cargos con estado pendiente

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acceso denegado.');
}

if ($argc < 2) {
    echo "Uso: php process_cargos.php <ruta_al_archivo>\n";
    exit(1);
}

$filePath = $argv[1];

if (!is_file($filePath)) {
    echo "Error: Archivo no encontrado: $filePath\n";
    exit(1);
}

$nombreOrig = basename($filePath);
$delimiter  = ';';

// Incluir BD
require_once __DIR__ . '/../conexion/db.php';
$db = new clsConexion();

function norm($s): string {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

function esc($s) {
    global $db;
    return $db->real_escape_string($s);
}

// Asegurarse que existe la tabla stisoft_mapeo_cargos
$db->ejecutar("
    CREATE TABLE IF NOT EXISTS stisoft_mapeo_cargos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cargo_adp_id INT NOT NULL UNIQUE,
        cargo_adp_desc VARCHAR(255),
        buk_role_id INT NULL,
        estado ENUM('pendiente','mapeado','sin_equivalencia') DEFAULT 'pendiente',
        observacion VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if (($handle = fopen($filePath, 'r')) === false) {
    echo "Error: No se pudo abrir el archivo.\n";
    exit(1);
}

try {
    $header = fgetcsv($handle, 0, $delimiter);
    if ($header === false || count($header) === 0) {
        throw new Exception('No se pudo leer la cabecera.');
    }

    // Limpieza de BOM y espacios
    $header = array_map(function ($col) {
        $col = trim($col, " \t\n\r\0\x0B\xEF\xBB\xBF");
        return $col;
    }, $header);

    // Crear mapa de columnas
    $headerMap = [];
    foreach ($header as $idx => $col) {
        $colLower = strtolower($col);
        $headerMap[$colLower] = $idx;
    }

    $insertados = 0;
    $actualizados = 0;
    $linea = 1;

    function findCol($map, $keys) {
        foreach ((array)$keys as $k) {
            if (isset($map[strtolower($k)])) return $map[strtolower($k)];
        }
        return null;
    }

    $idxCargoId = findCol($headerMap, ['cargo_adp_id','id cargo','id_cargo','codigo cargo']);
    $idxCargoDesc = findCol($headerMap, ['cargo_adp_desc','descripcion cargo','desc cargo','nombre cargo']);

    // Procesar filas
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $linea++;

        if (count($data) === 0 || (count($data) === 1 && trim($data[0]) === '')) {
            continue;
        }

        // Normalizar
        if (count($data) < count($header)) {
            $data = array_pad($data, count($header), null);
        } elseif (count($data) > count($header)) {
            $data = array_slice($data, 0, count($header));
        }

        $cargoId = (int)($data[$idxCargoId] ?? 0);
        $cargoDesc = norm($data[$idxCargoDesc] ?? '');

        if ($cargoId <= 0 || !$cargoDesc) continue;

        // Insertar o actualizar cargo
        $sql = "
            INSERT INTO stisoft_mapeo_cargos (cargo_adp_id, cargo_adp_desc, estado)
            VALUES ($cargoId, '" . esc($cargoDesc) . "', 'pendiente')
            ON DUPLICATE KEY UPDATE
              cargo_adp_desc = VALUES(cargo_adp_desc)
        ";
        
        if ($db->ejecutar($sql)) {
            $insertados++;
        }
    }

    fclose($handle);

    echo "Procesado: $nombreOrig. Cargos sincronizados: $insertados.\n";

} catch (Exception $e) {
    if (is_resource($handle)) {
        fclose($handle);
    }
    echo "Error procesando: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Procesamiento de cargos completado.\n";
?>

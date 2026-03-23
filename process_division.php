<?php
// /sync/process_division.php — CLI para procesar archivos de división automáticamente
// Carga la estructura de División -> Centro de Costo -> Unidad en buk_jerarquia

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acceso denegado.');
}

if ($argc < 2) {
    echo "Uso: php process_division.php <ruta_al_archivo>\n";
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

// Asegurarse que existe la tabla buk_jerarquia
$db->ejecutar("
    CREATE TABLE IF NOT EXISTS buk_jerarquia (
        id INT AUTO_INCREMENT PRIMARY KEY,
        profundidad INT NOT NULL,
        tipo_origen_adp VARCHAR(50),
        codigo_adp VARCHAR(100),
        nombre VARCHAR(255),
        parent_div_code VARCHAR(100),
        parent_cc_code VARCHAR(100),
        buk_area_id INT NULL,
        buk_parent_id INT NULL,
        estado ENUM('pendiente','mapeado','sin_equivalencia') DEFAULT 'pendiente',
        observacion VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_node (profundidad, tipo_origen_adp, codigo_adp, parent_div_code, parent_cc_code),
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

    $count_div = 0;
    $count_cc = 0;
    $count_un = 0;
    $linea = 1;

    function findCol($map, $keys) {
        foreach ((array)$keys as $k) {
            if (isset($map[strtolower($k)])) return $map[strtolower($k)];
        }
        return null;
    }

    $idxDivCode = findCol($headerMap, ['division','División','div_code']);
    $idxDivName = findCol($headerMap, ['descripcion división','descr división','desc division','div_name']);
    $idxCCCode = findCol($headerMap, ['centro de costo','centro costo','cc_code']);
    $idxCCName = findCol($headerMap, ['descripcion centro de costo','desc cc','cc_name']);
    $idxUnCode = findCol($headerMap, ['unidad','Unidad','un_code']);
    $idxUnName = findCol($headerMap, ['descripcion unidad','desc unidad','un_name']);

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

        $div_code = norm($data[$idxDivCode] ?? '');
        $div_name = norm($data[$idxDivName] ?? '');
        $cc_code = norm($data[$idxCCCode] ?? '');
        $cc_name = norm($data[$idxCCName] ?? '');
        $un_code = norm($data[$idxUnCode] ?? '');
        $un_name = norm($data[$idxUnName] ?? '');

        if (!$div_code || !$div_name) continue;

        // División (depth 0)
        $sqlDiv = "
            INSERT INTO buk_jerarquia
              (profundidad, tipo_origen_adp, codigo_adp, nombre, parent_div_code, parent_cc_code, estado)
            VALUES
              (0, 'division', '" . esc($div_code) . "', '" . esc($div_name) . "', '', '', 'pendiente')
            ON DUPLICATE KEY UPDATE
              nombre=VALUES(nombre)
        ";
        if ($db->ejecutar($sqlDiv)) $count_div++;

        // Área(CC) (depth 1) bajo división
        if ($cc_code && $cc_name) {
            $sqlCC = "
                INSERT INTO buk_jerarquia
                  (profundidad, tipo_origen_adp, codigo_adp, nombre, parent_div_code, parent_cc_code, estado)
                VALUES
                  (1, 'centro_costo', '" . esc($cc_code) . "', '" . esc($cc_name) . "',
                   '" . esc($div_code) . "', '', 'pendiente')
                ON DUPLICATE KEY UPDATE
                  nombre=VALUES(nombre)
            ";
            if ($db->ejecutar($sqlCC)) $count_cc++;
        }

        // Subárea(Unidad) (depth 2) bajo área(CC)
        if ($un_code && $un_name) {
            $sqlUN = "
                INSERT INTO buk_jerarquia
                  (profundidad, tipo_origen_adp, codigo_adp, nombre, parent_div_code, parent_cc_code, estado)
                VALUES
                  (2, 'unidad', '" . esc($un_code) . "', '" . esc($un_name) . "',
                   '" . esc($div_code) . "', '" . esc($cc_code) . "', 'pendiente')
                ON DUPLICATE KEY UPDATE
                  nombre=VALUES(nombre)
            ";
            if ($db->ejecutar($sqlUN)) $count_un++;
        }
    }

    fclose($handle);

    echo "Procesado: $nombreOrig. División: $count_div. Centro Costo: $count_cc. Unidad: $count_un.\n";

} catch (Exception $e) {
    if (is_resource($handle)) {
        fclose($handle);
    }
    echo "Error procesando: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Procesamiento de división completado.\n";
?>

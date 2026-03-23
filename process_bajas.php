<?php
// /sync/process_bajas.php — CLI para procesar archivos de bajas automáticamente
// Detecta bajas de empleados y actualiza el estado en BD

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acceso denegado.');
}

if ($argc < 2) {
    echo "Uso: php process_bajas.php <ruta_al_archivo>\n";
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

// Helper para normalizar RUT
function rut_norm($r) {
    $r = preg_replace('/[^0-9kK]/', '', (string)$r);
    return strtoupper($r);
}

// Helper para escapar
function esc($s) {
    global $db;
    return $db->real_escape_string($s);
}

$nombreMayus = strtoupper($nombreOrig);

// Detectar origen
$targetOrigin = null;
if (strpos($nombreMayus, 'EMPLEADOS_MAIPO') !== false) $targetOrigin = 'EMPLEADOS_MAIPO';
if (strpos($nombreMayus, 'EMPLEADOS_STI') !== false)   $targetOrigin = 'EMPLEADOS_STI';
if (strpos($nombreMayus, 'EVENTUALES_MAIPO') !== false) $targetOrigin = 'EVENTUALES_MAIPO';

if ($targetOrigin === null) {
    echo "Error: El archivo debe contener EMPLEADOS_MAIPO, EMPLEADOS_STI o EVENTUALES_MAIPO en su nombre.\n";
    exit(1);
}

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

    // Crear mapa de columnas (sin case-sensitive)
    $headerMap = [];
    foreach ($header as $idx => $col) {
        $colLower = strtolower($col);
        $headerMap[$colLower] = $idx;
    }

    $detected = 0;
    $updatedEstado = 0;
    $notFound = 0;
    $skipped = 0;
    $linea = 1;

    // Buscar índices de columnas clave
    function findCol($map, $keys) {
        foreach ((array)$keys as $k) {
            if (isset($map[strtolower($k)])) return $map[strtolower($k)];
        }
        return null;
    }

    $idxRut = findCol($headerMap, ['rut','Rut']);
    $idxEstado = findCol($headerMap, ['estado','Estado','Status']);
    $idxMotivo = findCol($headerMap, ['motivo','Motivo','Codigo Motivo']);
    $idxMotivoDesc = findCol($headerMap, ['motivo_desc','Descripcion Motivo','DescripcionMotivo','Motivo Retiro']);
    $idxFechaRetiro = findCol($headerMap, ['fecha_retiro','Fecha Retiro','FechaRetiro']);
    $idxFechaIngreso = findCol($headerMap, ['fecha_ingreso','Fecha Ingreso','FechaIngreso']);
    $originField = ($targetOrigin === 'EVENTUALES_MAIPO') ? 'origenadp' : 'origenadp';

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

        $rut = rut_norm($data[$idxRut] ?? '');
        if (!$rut) { $skipped++; continue; }

        $newEstado = strtoupper(trim((string)($data[$idxEstado] ?? '')));
        $motivo = trim((string)($data[$idxMotivo] ?? ''));
        $motivoDesc = trim((string)($data[$idxMotivoDesc] ?? ''));

        // Buscar en BD
        $sqlFind = "
            SELECT Rut, `Estado` AS EstadoDB, `DocDetectaBaja` AS DocDetectaBajaDB
            FROM adp_empleados
            WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ','')) = '".esc($rut)."'
              AND UPPER(TRIM(`$originField`)) = '".esc($targetOrigin ?: '')."'
            LIMIT 1
        ";
        $rowDb = $db->consultar($sqlFind);
        $rowDb = $rowDb && isset($rowDb[0]) ? $rowDb[0] : null;
        if (!$rowDb) { $notFound++; continue; }

        $estadoDB = strtoupper(trim((string)($rowDb['EstadoDB'] ?? '')));
        $docBajaDB = (int)($rowDb['DocDetectaBajaDB'] ?? 0);

        // Actualiza Estado en BD si viene distinto y no vacío
        if ($newEstado !== '' && $newEstado !== $estadoDB) {
            $db->ejecutar("UPDATE adp_empleados SET `Estado`='".esc($newEstado)."' WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ',''))='".esc($rut)."' AND UPPER(TRIM(`$originField`))='".esc($targetOrigin ?: '')."' LIMIT 1");
            $updatedEstado++;
        }

        // Detecta baja: venía activo y ahora no
        if ($estadoDB === 'A' && $newEstado !== '' && $newEstado !== 'A') {
            if ($docBajaDB < 2) {
                $db->ejecutar("
                    UPDATE adp_empleados
                    SET
                      `DocDetectaBaja`=1,
                      `Motivo de Retiro`='".esc($motivo)."',
                      `Descripcion Motivo de Retiro`='".esc($motivoDesc)."'
                    WHERE UPPER(REPLACE(REPLACE(REPLACE(Rut,'.',''),'-',''),' ',''))='".esc($rut)."'
                      AND UPPER(TRIM(`$originField`)) = '".esc($targetOrigin ?: '')."'
                    LIMIT 1
                ");
                $detected++;
            }
        }
    }

    fclose($handle);

    echo "Procesado: $nombreOrig (origen: $targetOrigin). Detectadas: $detected. Estado actualizado: $updatedEstado. No encontrados: $notFound. Omitidas: $skipped.\n";

} catch (Exception $e) {
    if (is_resource($handle)) {
        fclose($handle);
    }
    echo "Error procesando: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Procesamiento de bajas completado.\n";
?>

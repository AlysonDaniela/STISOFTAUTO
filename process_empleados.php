<?php
// /sync/process_empleados.php — CLI para procesar archivos de empleados automáticamente
// Uso: php process_empleados.php /path/to/file.csv

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acceso denegado.');
}

if ($argc < 2) {
    echo "Uso: php process_empleados.php <ruta_al_archivo>\n";
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

// Opcional: integrar envío a Buk usando librería existente
$bukAvailable = false;
$bukSentCount = 0;
$bukSentErrors = 0;
$bukJobCount = 0;
$bukJobErrors = 0;
if (is_file(__DIR__ . '/../empleados/enviar_buk.php')) {
    require_once __DIR__ . '/../empleados/enviar_buk.php';
    $bukAvailable = function_exists('build_employee_payload');
}


// ========== LÓGICA DE PROCESAMIENTO ==========

$nombreMayus = strtoupper($nombreOrig);

$origenAdp = null;
if (strpos($nombreMayus, 'EMPLEADOS_MAIPO') !== false) {
    $origenAdp = 'EMPLEADOS_MAIPO';
} elseif (strpos($nombreMayus, 'EMPLEADOS_STI') !== false) {
    $origenAdp = 'EMPLEADOS_STI';
} elseif (strpos($nombreMayus, 'EVENTUALES_MAIPO') !== false) {
    $origenAdp = 'EVENTUALES_MAIPO';
}

if (($handle = fopen($filePath, 'r')) === false) {
    echo "Error: No se pudo abrir el archivo.\n";
    exit(1);
}

try {
    // Leer cabecera
    $header = fgetcsv($handle, 0, $delimiter);
    if ($header === false || count($header) === 0) {
        throw new Exception('No se pudo leer la cabecera.');
    }

    // Limpieza de BOM y espacios
    $header = array_map(function ($col) {
        $col = trim($col, " \t\n\r\0\x0B\xEF\xBB\xBF");
        return $col;
    }, $header);

    $numColsArchivo = count($header);

    // Construir lista de columnas SQL
    $columnsSql = array_map(function ($col) {
        $col = str_replace('`', '``', $col);
        return "`{$col}`";
    }, $header);

    // Agregar columna origenadp
    $columnsSql[] = '`origenadp`';
    $columnList = implode(', ', $columnsSql);

    $insertados = 0;
    $errores    = 0;
    $linea      = 1;
    $numColsCabecera = $numColsArchivo;

    // Procesar filas
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $linea++;

        // Saltar filas vacías
        if ($data === null || count($data) === 0 ||
            (count($data) === 1 && trim($data[0]) === '')) {
            continue;
        }

        // Normalizar cantidad de columnas
        if (count($data) < $numColsCabecera) {
            $data = array_pad($data, $numColsCabecera, null);
        } elseif (count($data) > $numColsCabecera) {
            $data = array_slice($data, 0, $numColsCabecera);
        }

        $valuesSqlParts = [];

        // Valores del archivo
        foreach ($data as $valor) {
            $valor = is_null($valor) ? '' : trim($valor);

            if ($valor === '') {
                $valuesSqlParts[] = "NULL";
            } else {
                $valorEsc = $db->real_escape_string($valor);
                $valuesSqlParts[] = "'" . $valorEsc . "'";
            }
        }

        // Agregar origen
        if ($origenAdp === null) {
            $valuesSqlParts[] = "NULL";
        } else {
            $origenEsc = $db->real_escape_string($origenAdp);
            $valuesSqlParts[] = "'" . $origenEsc . "'";
        }

        $valuesSql = implode(', ', $valuesSqlParts);
        $sql = "INSERT INTO `adp_empleados` ({$columnList}) VALUES ({$valuesSql})";

        if ($db->ejecutar($sql)) {
            $insertados++;

            // si tenemos la librería Buk, intentar enviar el empleado y su cargo/job
            if ($bukAvailable) {
                // construir fila asociativa para el mapeo
                $rowAssoc = [];
                foreach ($header as $idx => $colName) {
                    $rowAssoc[$colName] = $data[$idx] ?? null;
                }
                $rowAssoc['origenadp'] = $origenAdp;

                try {
                    $empPayload = build_employee_payload($rowAssoc);
                    $resEmp     = post_buk_employee($empPayload);
                    if ($resEmp['ok']) {
                        $bukSentCount++;
                        // obtener id del empleado recién creado
                        $empJson = json_decode($resEmp['body'], true);
                        $empId   = $empJson['data']['id'] ?? null;
                        if ($empId) {
                            $jobPayload = build_job_payload($rowAssoc, $empId, null);
                            if ($jobPayload) {
                                $resJob = post_buk_job($empId, $jobPayload);
                                if ($resJob['ok']) {
                                    $bukJobCount++;
                                } else {
                                    $bukJobErrors++;
                                }
                            }
                        }
                    } else {
                        $bukSentErrors++;
                    }
                } catch (Throwable $e) {
                    $bukSentErrors++;
                }
            }

        } else {
            $errores++;
        }
    }

    fclose($handle);

    echo "Procesado: $insertados filas insertadas, $errores con error de $nombreOrig (origen: $origenAdp)\n";

    if ($bukAvailable) {
        echo "Buk -> empleados enviados: $bukSentCount, errores: $bukSentErrors" . PHP_EOL;
        echo "Buk -> jobs creados: $bukJobCount, errores: $bukJobErrors" . PHP_EOL;
    }

} catch (Exception $e) {
    if (is_resource($handle)) {
        fclose($handle);
    }
    echo "Error procesando: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Procesamiento completado.\n";
?>

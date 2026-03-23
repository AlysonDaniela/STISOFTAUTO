<?php
declare(strict_types=1);

// /sync/process_empleados.php

if (php_sapi_name() !== 'cli') {
    echo "Este script debe ejecutarse desde CLI.\n";
    exit(1);
}

if (empty($argv[1])) {
    echo "Uso: php process_empleados.php /ruta/al/archivo.txt\n";
    exit(1);
}

$filePath = $argv[1];
if (!is_file($filePath)) {
    echo "Error: Archivo no encontrado: {$filePath}\n";
    exit(1);
}

require_once __DIR__ . '/../conexion/db.php';
$db = new clsConexion();

/**
 * Normaliza texto para comparar nombres de columnas
 */
function norm_col($s) {
    if ($s === null) return '';
    $s = trim((string)$s, " \t\n\r\0\x0B\xEF\xBB\xBF");

    if ($s !== '') {
        $enc = mb_detect_encoding($s, ['UTF-8','ISO-8859-1','Windows-1252'], true);
        if ($enc && $enc !== 'UTF-8') {
            $s = mb_convert_encoding($s, 'UTF-8', $enc);
        }
    }

    $s = mb_strtolower($s, 'UTF-8');

    $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($tmp !== false) $s = $tmp;

    $s = str_replace(['`', '"', "'"], '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

function seems_header_row(array $row) {
    $joined = norm_col(implode(' ', $row));
    if ($joined === '') return false;

    $tokens = ['codigo','rut','apaterno','amaterno','nombres','descripcion','unidad','empresa','division','fecha','sexo','estado civil'];
    $hits = 0;
    foreach ($tokens as $t) {
        if (strpos($joined, $t) !== false) $hits++;
    }
    return $hits >= 2;
}

function get_table_columns($db, $table) {
    $tableEsc = str_replace('`','``', $table);
    $sql = "SHOW COLUMNS FROM `{$tableEsc}`";
    $res = $db->ejecutar($sql);
    if (!$res) return [];

    $cols = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $cols[] = $row['Field'];
    }
    return $cols;
}

function sql_value($db, $v) {
    if ($v === null) return "NULL";
    $v = trim((string)$v);
    if ($v === '') return "NULL";
    return "'" . $db->real_escape_string($v) . "'";
}

function rut_get_row($db, $tabla, $rutCol, $rutVal, ?string $origenAdp = null) {
    if ($rutVal === null) return null;
    $rutVal = trim((string)$rutVal);
    if ($rutVal === '') return null;

    $tablaEsc = str_replace('`','``',$tabla);
    $colEsc   = str_replace('`','``',$rutCol);

    $rutSql = "'" . $db->real_escape_string($rutVal) . "'";
    $where = "`{$colEsc}` = {$rutSql}";

    if ($origenAdp !== null && $origenAdp !== '') {
        $where .= " AND `origenadp` = '" . $db->real_escape_string($origenAdp) . "'";
    }

    $sql = "SELECT * FROM `{$tablaEsc}` WHERE {$where} LIMIT 1";
    $res = $db->ejecutar($sql);
    if ($res === false) return null;
    if (mysqli_num_rows($res) === 0) return null;

    return mysqli_fetch_assoc($res);
}

function update_by_rut_and_origen($db, $tablaDestino, $rutColName, $rutVal, string $origenAdp, array $setPairsSql) {
    $tablaEsc  = str_replace('`','``',$tablaDestino);
    $rutColEsc = str_replace('`','``',$rutColName);

    $rutSql = "'" . $db->real_escape_string(trim((string)$rutVal)) . "'";
    $oriSql = "'" . $db->real_escape_string($origenAdp) . "'";
    $setSql = implode(", ", $setPairsSql);

    $sql = "UPDATE `{$tablaEsc}` 
            SET {$setSql}
            WHERE `{$rutColEsc}` = {$rutSql}
              AND `origenadp` = {$oriSql}
            LIMIT 1";
    return (bool)$db->ejecutar($sql);
}

function normalize_estado($v): string {
    return strtoupper(trim((string)$v));
}

function nullable_date_sql($db, $v): string {
    $v = trim((string)$v);
    if ($v === '') return "NULL";
    return "'" . $db->real_escape_string($v) . "'";
}

$nombreOrig = basename($filePath);
$nombreMayus = strtoupper($nombreOrig);
$delimiter = ';';

// Determinar origen
$origenAdp = null;
if (strpos($nombreMayus, 'EMPLEADOS_MAIPO') !== false) {
    $origenAdp = 'EMPLEADOS_MAIPO';
} elseif (strpos($nombreMayus, 'EMPLEADOS_STI') !== false) {
    $origenAdp = 'EMPLEADOS_STI';
}
// EVENTUALES desactivado por ahora
/*
elseif (strpos($nombreMayus, 'EVENTUALES_MAIPO') !== false) {
    $origenAdp = 'EVENTUALES_MAIPO';
}
*/

if ($origenAdp === null) {
    $result = [
        'status'  => 'unsupported',
        'file'    => $nombreOrig,
        'tipo'    => 'empleados',
        'message' => 'Archivo no soportado para sync de empleados. Solo se aceptan EMPLEADOS_MAIPO y EMPLEADOS_STI.',
    ];
    echo "Archivo no soportado para sync de empleados: {$nombreOrig}\n";
    echo "Solo se aceptan EMPLEADOS_MAIPO y EMPLEADOS_STI.\n";
    echo "SYNC_RESULT=" . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$handle = fopen($filePath, 'r');
if ($handle === false) {
    $result = [
        'status'  => 'error',
        'file'    => $nombreOrig,
        'tipo'    => 'empleados',
        'origen'  => $origenAdp,
        'message' => 'No se pudo abrir el archivo.',
    ];
    echo "Error: No se pudo abrir el archivo.\n";
    echo "SYNC_RESULT=" . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

try {
    $tablaDestino = 'adp_empleados';

    $tableColsAll = get_table_columns($db, $tablaDestino);
    if (empty($tableColsAll)) {
        throw new Exception("No pude leer columnas de la tabla {$tablaDestino}.");
    }

    $systemCols = ['origenadp', 'FechaStisoft', 'estado_buk', 'buk_emp_id', 'buk_job_id', 'buk_plan_id', 'buk_cargo_id', 'buk_company_id', 'company_buk', 'ficha_buk', 'plan_buk', 'job_buk'];
    $baseCols = array_values(array_filter($tableColsAll, function($c) use ($systemCols) {
        return !in_array($c, $systemCols, true);
    }));

    $firstRow = fgetcsv($handle, 0, $delimiter);
    if ($firstRow === false || (count($firstRow) === 1 && trim((string)$firstRow[0]) === '')) {
        throw new Exception('Archivo vacío o sin primera fila válida.');
    }

    $firstRow = array_map(function($v) {
        return trim((string)$v, " \t\n\r\0\x0B\xEF\xBB\xBF");
    }, $firstRow);

    $hasHeader = seems_header_row($firstRow);

    $fileIdxToTableCol = [];
    $insertCols = [];
    $expectedFileColsCount = 0;

    if ($hasHeader) {
        $dict = [];
        foreach ($baseCols as $colName) {
            $dict[norm_col($colName)] = $colName;
        }

        $aliases = [
            'descripcion division'            => 'Descripcion División',
            'descripcion unidad'              => 'Descripcion Unidad',
            'division'                        => 'División',
            'nacion'                          => 'Nación',
            'descripcion nacion'              => 'Descripcion Nacion',
            'fecha ultima modificacion'       => 'Fecha última modificacion',
            'fecha ultima modificación'       => 'Fecha última modificacion',
        ];

        foreach ($firstRow as $i => $colFromFile) {
            $n = norm_col($colFromFile);
            if ($n === '') continue;

            if (isset($dict[$n])) {
                $fileIdxToTableCol[$i] = $dict[$n];
                continue;
            }

            if (isset($aliases[$n])) {
                $target = $aliases[$n];
                if (in_array($target, $baseCols, true)) {
                    $fileIdxToTableCol[$i] = $target;
                    continue;
                }
            }
        }

        if (empty($fileIdxToTableCol)) {
            $hasHeader = false;
        } else {
            ksort($fileIdxToTableCol);
            foreach ($fileIdxToTableCol as $idx => $colName) {
                $insertCols[] = $colName;
            }
            $expectedFileColsCount = count($firstRow);
        }
    }

    if (!$hasHeader) {
        $expectedFileColsCount = count($firstRow);
        $insertCols = array_slice($baseCols, 0, $expectedFileColsCount);

        for ($i = 0; $i < count($insertCols); $i++) {
            $fileIdxToTableCol[$i] = $insertCols[$i];
        }
    }

    $insertColsWithOrigen = $insertCols;
    $insertColsWithOrigen[] = 'origenadp';
    $insertColsWithOrigen[] = 'FechaStisoft';

    $colSql = implode(', ', array_map(function($c) {
        $c = str_replace('`', '``', $c);
        return "`{$c}`";
    }, $insertColsWithOrigen));

    $rutColName = 'Rut';

    $altasNuevas      = 0;
    $altasReingreso   = 0;
    $bajasDetectadas  = 0;
    $ignoradosMismo   = 0;
    $ignoradosNoAct   = 0;
    $errores          = 0;
    $linea            = 1;

    $altasNuevasRuts     = [];
    $altasReingresoRuts  = [];
    $bajasDetectadasRuts = [];
    $ignoradosRuts       = [];
    $erroresRuts         = [];

    $pendingFirstData = (!$hasHeader) ? $firstRow : null;

    $processRow = function($row) use (
        $db, $tablaDestino, $colSql,
        $expectedFileColsCount, $fileIdxToTableCol, $insertCols, $insertColsWithOrigen,
        $origenAdp, $rutColName,
        &$altasNuevas, &$altasReingreso, &$bajasDetectadas, &$ignoradosMismo, &$ignoradosNoAct, &$errores, &$linea,
        &$altasNuevasRuts, &$altasReingresoRuts, &$bajasDetectadasRuts, &$ignoradosRuts, &$erroresRuts
    ) {
        if ($row === null || count($row) === 0 || (count($row) === 1 && trim((string)$row[0]) === '')) {
            return;
        }

        if (count($row) < $expectedFileColsCount) {
            $row = array_pad($row, $expectedFileColsCount, null);
        } elseif (count($row) > $expectedFileColsCount) {
            $row = array_slice($row, 0, $expectedFileColsCount);
        }

        $rowAssoc = [];
        foreach ($fileIdxToTableCol as $idx => $mappedCol) {
            $rowAssoc[$mappedCol] = $row[$idx] ?? null;
        }

        $rutVal = trim((string)($rowAssoc[$rutColName] ?? ''));
        if ($rutVal === '') {
            $errores++;
            $erroresRuts[] = [
                'rut'   => '',
                'linea' => $linea,
                'error' => 'Fila sin Rut'
            ];
            return;
        }

        $estadoArchivo = normalize_estado($rowAssoc['Estado'] ?? '');

        $registroActual = rut_get_row($db, $tablaDestino, $rutColName, $rutVal, $origenAdp);

        // ========== NO EXISTE EN BD ==========
        if ($registroActual === null) {
            if ($estadoArchivo !== 'A') {
                $ignoradosNoAct++;
                $ignoradosRuts[] = $rutVal;
                return;
            }

            $values = [];
            foreach ($insertCols as $colName) {
                $val = $rowAssoc[$colName] ?? null;
                $values[] = sql_value($db, $val);
            }
            $values[] = "'" . $db->real_escape_string($origenAdp) . "'";
            $values[] = "CURRENT_TIMESTAMP";

            $valuesSql = implode(', ', $values);
            $sql = "INSERT INTO `{$tablaDestino}` ({$colSql}) VALUES ({$valuesSql})";

            if ($db->ejecutar($sql)) {
                $altasNuevas++;
                $altasNuevasRuts[] = $rutVal;
            } else {
                $errores++;
                $erroresRuts[] = [
                    'rut'   => $rutVal,
                    'linea' => $linea,
                    'error' => mysqli_error($db->conexion ?? null) ?: 'Falló INSERT en BD'
                ];
            }
            return;
        }

        $estadoBd = normalize_estado($registroActual['Estado'] ?? '');

        // ========== MISMO ESTADO ==========
        if ($estadoBd === $estadoArchivo) {
            $ignoradosMismo++;
            $ignoradosRuts[] = $rutVal;
            return;
        }

        // Prepara UPDATE completo de campos del archivo
        $setPairs = [];
        foreach ($insertCols as $colName) {
            if ($colName === $rutColName) continue;
            $val = $rowAssoc[$colName] ?? null;
            $colEsc = str_replace('`','``',$colName);
            $setPairs[] = "`{$colEsc}` = " . sql_value($db, $val);
        }
        $setPairs[] = "`origenadp` = '" . $db->real_escape_string($origenAdp) . "'";

        // ========== BAJA DETECTADA ==========
        if ($estadoBd === 'A' && $estadoArchivo !== 'A') {
            $setPairsBaja = $setPairs;

            // Forzar marca de baja
            if (in_array('DocDetectaBaja', get_table_columns($db, $tablaDestino), true)) {
                $setPairsBaja[] = "`DocDetectaBaja` = 1";
            }

            // Si viene vacío motivo/fecha, mantener lo que ya venga del update normal; no forzamos NULL extra
            if (update_by_rut_and_origen($db, $tablaDestino, $rutColName, $rutVal, $origenAdp, $setPairsBaja)) {
                $bajasDetectadas++;
                $bajasDetectadasRuts[] = $rutVal;
            } else {
                $errores++;
                $erroresRuts[] = [
                    'rut'   => $rutVal,
                    'linea' => $linea,
                    'error' => 'Falló UPDATE de baja en BD'
                ];
            }
            return;
        }

        // ========== REINGRESO / ALTA DESDE ESTADO NO ACTIVO ==========
        if ($estadoBd !== 'A' && $estadoArchivo === 'A') {
            $setPairsReingreso = $setPairs;

            // Limpiar marca de baja
            if (in_array('DocDetectaBaja', get_table_columns($db, $tablaDestino), true)) {
                $setPairsReingreso[] = "`DocDetectaBaja` = 0";
            }

            // Limpiar datos de retiro
            if (in_array('Fecha de Retiro', get_table_columns($db, $tablaDestino), true)) {
                $setPairsReingreso[] = "`Fecha de Retiro` = NULL";
            }
            if (in_array('Motivo de Retiro', get_table_columns($db, $tablaDestino), true)) {
                $setPairsReingreso[] = "`Motivo de Retiro` = NULL";
            }
            if (in_array('Descripcion Motivo de Retiro', get_table_columns($db, $tablaDestino), true)) {
                $setPairsReingreso[] = "`Descripcion Motivo de Retiro` = NULL";
            }

            if (update_by_rut_and_origen($db, $tablaDestino, $rutColName, $rutVal, $origenAdp, $setPairsReingreso)) {
                $altasReingreso++;
                $altasReingresoRuts[] = $rutVal;
            } else {
                $errores++;
                $erroresRuts[] = [
                    'rut'   => $rutVal,
                    'linea' => $linea,
                    'error' => 'Falló UPDATE de reingreso en BD'
                ];
            }
            return;
        }

        // ========== CUALQUIER OTRO CASO ==========
        $ignoradosMismo++;
        $ignoradosRuts[] = $rutVal;
    };

    if ($pendingFirstData !== null) {
        $processRow($pendingFirstData);
    }

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $linea++;
        $processRow($data);
    }

    fclose($handle);

    $result = [
        'status' => 'ok',
        'file'   => $nombreOrig,
        'tipo'   => 'empleados',
        'origen' => $origenAdp,

        'altas_nuevas'             => $altasNuevas,
        'altas_reingreso'          => $altasReingreso,
        'bajas_detectadas'         => $bajasDetectadas,
        'ignorados_mismo_estado'   => $ignoradosMismo,
        'ignorados_no_activos_nuevos' => $ignoradosNoAct,
        'errores'                  => $errores,

        // compatibilidad con correo actual
        'insertados'   => $altasNuevas,
        'actualizados' => $altasReingreso,
        'omitidos'     => $ignoradosMismo + $ignoradosNoAct,

        'buk' => [
            'empleados_ok'    => 0,
            'empleados_error' => 0,
            'jobs_ok'         => 0,
            'jobs_error'      => 0,
        ],

        'altas_ruts'              => array_values(array_unique($altasNuevasRuts)),
        'altas_reingreso_ruts'    => array_values(array_unique($altasReingresoRuts)),
        'bajas_detectadas_ruts'   => array_values(array_unique($bajasDetectadasRuts)),
        'ignorados_ruts'          => array_values(array_unique($ignoradosRuts)),
        'errores_ruts'            => $erroresRuts,

        // compatibilidad vieja
        'altas_buk_ok_ruts'       => [],
        'altas_buk_error_ruts'    => [],
        'actualizados_ruts'       => array_values(array_unique($altasReingresoRuts)),
        'omitidos_ruts'           => array_values(array_unique($ignoradosRuts)),
    ];

    echo "Importación completada desde '{$nombreOrig}'.\n";
    echo "Altas nuevas: {$altasNuevas}\n";
    echo "Altas por reingreso: {$altasReingreso}\n";
    echo "Bajas detectadas: {$bajasDetectadas}\n";
    echo "Ignorados mismo estado: {$ignoradosMismo}\n";
    echo "Ignorados nuevos no activos: {$ignoradosNoAct}\n";
    echo "Errores: {$errores}\n";
    echo "Buk no ejecutado en esta fase.\n";
    echo "SYNC_RESULT=" . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";

} catch (Throwable $e) {
    if (is_resource($handle)) fclose($handle);

    $result = [
        'status'  => 'error',
        'file'    => $nombreOrig,
        'tipo'    => 'empleados',
        'origen'  => $origenAdp,
        'message' => $e->getMessage(),
    ];

    echo "Error durante la importación: " . $e->getMessage() . "\n";
    echo "SYNC_RESULT=" . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}
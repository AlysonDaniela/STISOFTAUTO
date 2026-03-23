<?php
// /empleados/importar_archivo_adp_patched.php

require_once __DIR__ . '/../includes/auth.php';
require_auth();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../conexion/db.php';
$db = new clsConexion();

/**
 * Normaliza texto para comparar nombres de columnas:
 * - trim + elimina BOM
 * - pasa a UTF-8 si viene ISO-8859-1/Windows-1252
 * - minusculas
 * - elimina tildes/diacríticos
 * - colapsa espacios
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

/**
 * Heurística: ¿esta fila parece cabecera?
 */
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

/**
 * Obtiene columnas reales de una tabla (más compatible que INFORMATION_SCHEMA)
 */
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

/**
 * Escapa valores para SQL (sin prepared statements)
 */
function sql_value($db, $v) {
    if ($v === null) return "NULL";
    $v = trim((string)$v);
    if ($v === '') return "NULL";
    return "'" . $db->real_escape_string($v) . "'";
}

/**
 * Obtiene el Estado actual del RUT (o null si no existe)
 */
function rut_get_estado($db, $tabla, $rutCol, $rutVal) {
    if ($rutVal === null) return null;
    $rutVal = trim((string)$rutVal);
    if ($rutVal === '') return null;

    $tablaEsc = str_replace('`','``',$tabla);
    $colEsc   = str_replace('`','``',$rutCol);

    $rutSql = "'" . $db->real_escape_string($rutVal) . "'";
    $sql = "SELECT `Estado` FROM `{$tablaEsc}` WHERE `{$colEsc}` = {$rutSql} LIMIT 1";

    $res = $db->ejecutar($sql);
    if ($res === false) return null;

    if (mysqli_num_rows($res) === 0) return null;

    $row = mysqli_fetch_assoc($res);
    return $row['Estado'] ?? null;
}

/**
 * UPDATE por RUT (retorna true/false)
 */
function update_by_rut($db, $tablaDestino, $rutColName, $rutVal, array $setPairsSql) {
    $tablaEsc  = str_replace('`','``',$tablaDestino);
    $rutColEsc = str_replace('`','``',$rutColName);

    $rutSql = "'" . $db->real_escape_string(trim((string)$rutVal)) . "'";
    $setSql = implode(", ", $setPairsSql);

    $sql = "UPDATE `{$tablaEsc}` SET {$setSql} WHERE `{$rutColEsc}` = {$rutSql} LIMIT 1";
    return (bool)$db->ejecutar($sql);
}

// ==============================
// 1) TOMAR EL ARCHIVO SUBIDO
// ==============================
if (empty($_FILES)) {
    $_SESSION['flash_error'] = 'No se recibió archivo válido para importar.';
    header('Location: index.php');
    exit;
}

$possibleFields = ['archivo_empleados', 'archivo', 'archivo_adp', 'file', 'empleados_file'];
$fileData = null;

foreach ($possibleFields as $field) {
    if (isset($_FILES[$field])) { $fileData = $_FILES[$field]; break; }
}
if ($fileData === null) {
    $firstKey = array_key_first($_FILES);
    $fileData = $_FILES[$firstKey];
}

if (!isset($fileData['error']) || $fileData['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['flash_error'] = 'No se recibió archivo válido para importar (error en la subida).';
    header('Location: index.php');
    exit;
}

$archivoTmp = $fileData['tmp_name'];
$nombreOrig = $fileData['name'];
$delimiter  = ';';

if (!is_uploaded_file($archivoTmp)) {
    $_SESSION['flash_error'] = 'No se pudo acceder al archivo subido.';
    header('Location: index.php');
    exit;
}

$handle = fopen($archivoTmp, 'r');
if ($handle === false) {
    $_SESSION['flash_error'] = 'No se pudo abrir el archivo para lectura.';
    header('Location: index.php');
    exit;
}

// ==============================
// 1.b) DETERMINAR ORIGEN
// ==============================
$origenAdp = null;
$nombreMayus = strtoupper($nombreOrig);

if (strpos($nombreMayus, 'EMPLEADOS_MAIPO') !== false) {
    $origenAdp = 'EMPLEADOS_MAIPO';
} elseif (strpos($nombreMayus, 'EMPLEADOS_STI') !== false) {
    $origenAdp = 'EMPLEADOS_STI';
} elseif (strpos($nombreMayus, 'EVENTUALES_MAIPO') !== false) {
    $origenAdp = 'EVENTUALES_MAIPO';
}

try {
    $tablaDestino = 'adp_empleados';

    // Columnas reales de la tabla (en orden)
    $tableColsAll = get_table_columns($db, $tablaDestino);
    if (empty($tableColsAll)) {
        throw new Exception("No pude leer columnas de la tabla {$tablaDestino}. (SHOW COLUMNS falló)");
    }

    // Columnas que NO vienen en archivos ADP (system columns)
    $systemCols = ['origenadp', 'estado_buk', 'buk_emp_id', 'buk_job_id'];

    // Lista “base” para cuando NO hay cabecera: todas menos las system
    $baseCols = array_values(array_filter($tableColsAll, function($c) use ($systemCols) {
        return !in_array($c, $systemCols, true);
    }));

    // ==============================
    // 2) LEER PRIMERA FILA
    // ==============================
    $firstRow = fgetcsv($handle, 0, $delimiter);
    if ($firstRow === false || (count($firstRow) === 1 && trim((string)$firstRow[0]) === '')) {
        throw new Exception('Archivo vacío o sin primera fila válida.');
    }

    // Limpiar BOM/espacios
    $firstRow = array_map(function($v) {
        return trim((string)$v, " \t\n\r\0\x0B\xEF\xBB\xBF");
    }, $firstRow);

    $hasHeader = seems_header_row($firstRow);

    $fileIdxToTableCol = [];   // idxArchivo => nombreColTabla
    $insertCols = [];          // columnas reales a insertar (sin system, +origenadp al final)
    $expectedFileColsCount = 0;

    if ($hasHeader) {
        // Diccionario normalizado de columnas reales de la tabla
        $dict = [];
        foreach ($baseCols as $colName) {
            $dict[norm_col($colName)] = $colName;
        }

        // Equivalencias típicas por si ADP trae variantes
        $aliases = [
            'descripcion division' => 'Descripcion División',
            'descripcion unidad'   => 'Descripcion Unidad',
            'division'             => 'División',
            'nacion'               => 'Nación',
            'descripcion nacion'   => 'Descripcion Nacion',
            'fecha ultima modificacion' => 'Fecha última modificacion',
            'fecha ultima modificación' => 'Fecha última modificacion',
        ];

        foreach ($firstRow as $i => $colFromFile) {
            $n = norm_col($colFromFile);
            if ($n === '') continue;

            // 1) match directo
            if (isset($dict[$n])) {
                $fileIdxToTableCol[$i] = $dict[$n];
                continue;
            }

            // 2) match por alias
            if (isset($aliases[$n])) {
                $target = $aliases[$n];
                if (in_array($target, $baseCols, true)) {
                    $fileIdxToTableCol[$i] = $target;
                    continue;
                }
            }
        }

        if (empty($fileIdxToTableCol)) {
            // Si “parecía header” pero no matcheó nada, lo tratamos como NO header
            $hasHeader = false;
        } else {
            // columnas a insertar: las que efectivamente pudimos mapear (en el orden del archivo)
            ksort($fileIdxToTableCol);
            foreach ($fileIdxToTableCol as $idx => $colName) {
                $insertCols[] = $colName;
            }
            $expectedFileColsCount = count($firstRow);
            // Continuamos leyendo desde la segunda fila (porque la primera fue cabecera)
        }
    }

    if (!$hasHeader) {
        // La primera fila es data (no cabecera)
        $expectedFileColsCount = count($firstRow);

        // Tomamos las primeras N columnas “base” de la tabla
        $insertCols = array_slice($baseCols, 0, $expectedFileColsCount);

        // Creamos map 0..N-1 => insertCols[i]
        for ($i = 0; $i < count($insertCols); $i++) {
            $fileIdxToTableCol[$i] = $insertCols[$i];
        }
    }

    // Siempre agregamos origenadp al final (para INSERT)
    $insertColsWithOrigen = $insertCols;
    $insertColsWithOrigen[] = 'origenadp';

    $colSql = implode(', ', array_map(function($c) {
        $c = str_replace('`', '``', $c);
        return "`{$c}`";
    }, $insertColsWithOrigen));

    // ==============================
    // 3) RECORRER FILAS
    // ==============================
    $insertados   = 0;
    $actualizados = 0;
    $omitidos     = 0;
    $errores      = 0;
    $linea        = 1;

    // Columna RUT en la BD (AJUSTA si es distinto)
    $rutColName = 'Rut';

    // Si NO había cabecera, ya tenemos una fila data leída (firstRow) que debemos procesar primero.
    $pendingFirstData = (!$hasHeader) ? $firstRow : null;

    $processRow = function($row) use (
        $db, $tablaDestino, $colSql,
        $expectedFileColsCount, $fileIdxToTableCol,
        $insertCols, $origenAdp,
        $rutColName,
        &$insertados, &$actualizados, &$omitidos, &$errores, &$linea
    ) {
        // Saltar filas vacías
        if ($row === null || count($row) === 0 || (count($row) === 1 && trim((string)$row[0]) === '')) {
            return;
        }

        // Normalizar cantidad de columnas del archivo
        if (count($row) < $expectedFileColsCount) {
            $row = array_pad($row, $expectedFileColsCount, null);
        } elseif (count($row) > $expectedFileColsCount) {
            $row = array_slice($row, 0, $expectedFileColsCount);
        }

        // localizar índice de RUT en el archivo
        $rutIdxArchivo = null;
        foreach ($fileIdxToTableCol as $idx => $mappedCol) {
            if ($mappedCol === $rutColName) { $rutIdxArchivo = $idx; break; }
        }
        $rutVal = ($rutIdxArchivo !== null && array_key_exists($rutIdxArchivo, $row)) ? $row[$rutIdxArchivo] : null;
        $rutVal = ($rutVal !== null) ? trim((string)$rutVal) : null;

        if ($rutVal === null || $rutVal === '') {
            // sin rut no importamos
            $errores++;
            return;
        }

        // ====== NUEVA LÓGICA ======
        // Si existe y está Activo (Estado='A'): omitir
        // Si existe y NO está Activo: actualizar
        // Si no existe: insertar
        $estadoActual = rut_get_estado($db, $tablaDestino, $rutColName, $rutVal);

        if ($estadoActual !== null) {
            // Existe en BD
            if (trim((string)$estadoActual) === 'A') {
                $omitidos++;
                return;
            }

            // No activo: UPDATE
            $setPairs = [];

            foreach ($insertCols as $colName) {
                if ($colName === $rutColName) continue;

                // buscar índice de archivo correspondiente a esta columna
                $idxArchivo = null;
                foreach ($fileIdxToTableCol as $idx => $mappedCol) {
                    if ($mappedCol === $colName) { $idxArchivo = $idx; break; }
                }

                $val = ($idxArchivo !== null && array_key_exists($idxArchivo, $row)) ? $row[$idxArchivo] : null;

                $colEsc = str_replace('`','``',$colName);
                $setPairs[] = "`{$colEsc}` = " . sql_value($db, $val);
            }

            // origenadp también lo seteamos
            $setPairs[] = "`origenadp` = " . (($origenAdp === null) ? "NULL" : ("'".$db->real_escape_string($origenAdp)."'"));

            if (update_by_rut($db, $tablaDestino, $rutColName, $rutVal, $setPairs)) {
                $actualizados++;
            } else {
                $errores++;
            }
            return;
        }

        // ====== INSERT (no existe) ======
        $values = [];
        foreach ($insertCols as $colName) {
            // buscar índice de archivo que corresponde a esta columna
            $idxArchivo = null;
            foreach ($fileIdxToTableCol as $idx => $mappedCol) {
                if ($mappedCol === $colName) { $idxArchivo = $idx; break; }
            }
            $val = ($idxArchivo !== null && array_key_exists($idxArchivo, $row)) ? $row[$idxArchivo] : null;
            $values[] = sql_value($db, $val);
        }

        // origenadp
        $values[] = ($origenAdp === null) ? "NULL" : ("'".$db->real_escape_string($origenAdp)."'");

        $valuesSql = implode(', ', $values);
        $sql = "INSERT INTO `{$tablaDestino}` ({$colSql}) VALUES ({$valuesSql})";

        if ($db->ejecutar($sql)) {
            $insertados++;
        } else {
            $errores++;
        }
    };

    // Procesar first data row si correspondía
    if ($pendingFirstData !== null) {
        $processRow($pendingFirstData);
    }

    // Leer el resto
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $linea++;
        $processRow($data);
    }

    fclose($handle);

    $_SESSION['flash_ok'] =
        "Importación completada desde '{$nombreOrig}'. " .
        "Filas insertadas: {$insertados}, actualizadas (RUT existente no activo): {$actualizados}, " .
        "omitidas (RUT activo): {$omitidos}, con error: {$errores}.";

} catch (Exception $e) {
    if (is_resource($handle)) fclose($handle);
    $_SESSION['flash_error'] = 'Error durante la importación: ' . $e->getMessage();
}

header('Location: index.php');
exit;

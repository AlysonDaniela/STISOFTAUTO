<?php
// /empleados/importar_archivo_adp.php

// PARA DEPURAR, DESCOMENTA ESTO:
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';
require_auth();
$user = current_user();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../conexion/db.php';

$db = new clsConexion();

// ==============================
// 1) TOMAR EL ARCHIVO SUBIDO
// ==============================

if (empty($_FILES)) {
    $_SESSION['flash_error'] = 'No se recibió archivo válido para importar (no llegó ningún archivo).';
    header('Location: index.php');
    exit;
}

// Intentar usar estos nombres de campo primero
$possibleFields = ['archivo_empleados', 'archivo', 'archivo_adp', 'file', 'empleados_file'];
$fileField = null;
$fileData  = null;

foreach ($possibleFields as $field) {
    if (isset($_FILES[$field])) {
        $fileField = $field;
        $fileData  = $_FILES[$field];
        break;
    }
}

// Si no encontramos ninguno de esos nombres, tomamos el PRIMERO que venga
if ($fileData === null) {
    $fileField = array_key_first($_FILES);
    $fileData  = $_FILES[$fileField];
}

// Validar error de subida
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

if (($handle = fopen($archivoTmp, 'r')) === false) {
    $_SESSION['flash_error'] = 'No se pudo abrir el archivo para lectura.';
    header('Location: index.php');
    exit;
}

/* ==========================================
 * 1.b) DETERMINAR ORIGEN DEL ARCHIVO ADP
 * ========================================== */
$origenAdp = null;
$nombreMayus = strtoupper($nombreOrig);

// SIEMPRE LOS ARCHIVOS SERAN ESTOS 3:
// EMPLEADOS_MAIPO_XXXXX
// EMPLEADOS_STI_XXXXX
// EVENTUALES_MAIPO_XXXXX
if (strpos($nombreMayus, 'EMPLEADOS_MAIPO') !== false) {
    $origenAdp = 'EMPLEADOS_MAIPO';
} elseif (strpos($nombreMayus, 'EMPLEADOS_STI') !== false) {
    $origenAdp = 'EMPLEADOS_STI';
} elseif (strpos($nombreMayus, 'EVENTUALES_MAIPO') !== false) {
    $origenAdp = 'EVENTUALES_MAIPO';
}
// Si no calza con ninguno, quedará NULL en la BD para esa columna.

try {

    // 2) LEER CABECERA
    $header = fgetcsv($handle, 0, $delimiter);

    if ($header === false || count($header) === 0) {
        throw new Exception('No se pudo leer la cabecera del archivo.');
    }

    // Limpieza de BOM y espacios
    $header = array_map(function ($col) {
        $col = trim($col, " \t\n\r\0\x0B\xEF\xBB\xBF");
        return $col;
    }, $header);

    // Número de columnas reales que vienen en el archivo
    $numColsArchivo = count($header);

    // Construimos la lista de columnas SQL a partir del archivo
    $columnsSql = array_map(function ($col) {
        $col = str_replace('`', '``', $col);
        return "`{$col}`";
    }, $header);

    // ==============================================
    // 2.b) AÑADIR COLUMNA FIJA `origenadp` AL INSERT
    // ==============================================
    $columnsSql[] = '`origenadp`';

    $columnList = implode(', ', $columnsSql);

    // Si quieres vaciar la tabla antes de importar:
    // $db->ejecutar("TRUNCATE TABLE `adp_empleados`");

    // 3) RECORRER TODAS LAS FILAS
    $insertados = 0;
    $errores    = 0;
    $linea      = 1;

    // Este número se usa solo para normalizar las columnas del archivo,
    // NO incluye la columna extra `origenadp`.
    $numColsCabecera = $numColsArchivo;

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $linea++;

        // Saltar filas completamente vacías
        if ($data === null || count($data) === 0 ||
            (count($data) === 1 && trim($data[0]) === '')) {
            continue;
        }

        // Normalizar cantidad de columnas respecto a la cabecera del archivo
        if (count($data) < $numColsCabecera) {
            $data = array_pad($data, $numColsCabecera, null);
        } elseif (count($data) > $numColsCabecera) {
            $data = array_slice($data, 0, $numColsCabecera);
        }

        $valuesSqlParts = [];

        // Primero, valores que vienen desde el archivo
        foreach ($data as $valor) {
            $valor = is_null($valor) ? '' : trim($valor);

            if ($valor === '') {
                $valuesSqlParts[] = "NULL";
            } else {
                $valorEsc = $db->real_escape_string($valor);
                $valuesSqlParts[] = "'" . $valorEsc . "'";
            }
        }

        // Luego, agregamos el valor fijo de `origenadp`
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
        } else {
            $errores++;
            // error_log("Error en línea {$linea} al ejecutar INSERT. SQL: {$sql}");
        }
    }

    fclose($handle);

    $_SESSION['flash_ok'] =
        "Importación completada desde '{$nombreOrig}'. " .
        "Filas insertadas: {$insertados}, con error: {$errores}.";

} catch (Exception $e) {
    if (is_resource($handle)) {
        fclose($handle);
    }
    $_SESSION['flash_error'] = 'Error durante la importación: ' . $e->getMessage();
}

header('Location: index.php');
exit;

<?php
declare(strict_types=1);

/**
 * Normaliza un decimal desde formato ADP (coma) a float PHP.
 * Acepta cosas tipo "1.234,56" -> 1234.56
 */
function adp_normalize_decimal(?string $value): ?float
{
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    // El archivo viene con coma como separador decimal.
    // Eliminamos separador de miles (.) y usamos punto como decimal.
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);

    if (!is_numeric($value)) {
        return null;
    }
    return (float) $value;
}

/**
 * Limpia la cabecera: BOM, espacios, etc.
 */
function adp_normalize_header(string $h): string
{
    // Quitar BOM si viene en la primera cabecera (ï»¿)
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    return trim($h);
}

/**
 * Lee un CSV/TXT de ADP y lo devuelve como:
 *   [ $headers, $rows ]
 * donde $rows es un array de filas asociativas.
 */
function read_csv_assoc(string $path, string $delimiter = ';'): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Archivo no encontrado: ' . $path);
    }

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('No se pudo abrir el archivo: ' . $path);
    }

    $header = fgetcsv($fh, 0, $delimiter);
    if ($header === false) {
        fclose($fh);
        throw new RuntimeException('No se pudo leer la cabecera del archivo.');
    }

    $header = array_map('adp_normalize_header', $header);

    $rows = [];
    while (($data = fgetcsv($fh, 0, $delimiter)) !== false) {
        // Saltar filas vacías
        if (count($data) === 1 && trim((string)$data[0]) === '') {
            continue;
        }

        $row = [];
        foreach ($header as $idx => $name) {
            $row[$name] = array_key_exists($idx, $data) ? trim((string)$data[$idx]) : null;
        }
        $rows[] = $row;
    }

    fclose($fh);

    return [$header, $rows];
}

/**
 * Calcula el saldo de vacaciones para un ID (Rut o Codigo) usando
 * el archivo de saldos nuevo (VACACIONES_TERM_STI_...).
 *
 * Devuelve un array con la forma:
 *   [
 *     'found'        => bool,
 *     'rows'         => [ fila cruda(s) ],
 *     'byYear'       => [ [year, granted, taken, balance], ... ],
 *     'totalBalance' => float|null,
 *     'matchedBy'    => 'Rut' | 'Codigo' | ...,
 *     'debug'        => [...]
 *   ]
 */
function compute_vacation_from_file(array $rows, string $id): array
{
    $id = trim($id);
    if ($id === '') {
        throw new InvalidArgumentException('El identificador de búsqueda está vacío.');
    }

    $candidateColumns = [
        'Rut', 'RUT', 'rut',
        'Codigo', 'Código', 'CODIGO',
        'Codigo Empleado', 'Código Empleado',
        'ID Empleado', 'Codigo Trabajador'
    ];

    // Variantes del input para intentar matchear
    $cleanRut = preg_replace('/[^0-9kK]/', '', $id);
    $variants = array_values(array_unique([
        $id,
        strtoupper($id),
        strtolower($id),
        $cleanRut,
    ]));

    $matchedRows = [];
    $matchedBy   = null;

    foreach ($rows as $row) {
        foreach ($candidateColumns as $col) {
            if (!array_key_exists($col, $row)) {
                continue;
            }

            $value = trim((string)$row[$col]);
            if ($value === '') {
                continue;
            }

            $valueNorm = preg_replace('/[^0-9A-Za-z]/', '', $value);

            foreach ($variants as $v) {
                $vNorm = preg_replace('/[^0-9A-Za-z]/', '', (string)$v);
                if ($vNorm === '') {
                    continue;
                }
                if (strcasecmp($valueNorm, $vNorm) === 0) {
                    $matchedRows[] = $row;
                    $matchedBy     = $col;
                    // En este archivo normalmente hay una fila por empleado,
                    // así que paramos en el primer match.
                    break 2;
                }
            }
        }
        if ($matchedBy !== null) {
            break;
        }
    }

    $debug = [
        'candidateColumns' => $candidateColumns,
        'inputVariants'    => $variants,
        'suggestions'      => [], // Aquí podrías agregar sugerencias si quieres
    ];

    if (empty($matchedRows)) {
        return [
            'found'        => false,
            'byYear'       => [],
            'totalBalance' => null,
            'matchedBy'    => null,
            'debug'        => $debug,
        ];
    }

    // Para este archivo tomamos la primera fila encontrada
    $row = $matchedRows[0];

    // Intentamos sacar el año desde "Fecha de Vacaciones" (dd-mm-yyyy ...)
    $fechaVac = $row['Fecha de Vacaciones'] ?? ($row['Fecha Vacaciones'] ?? null);
    $year     = null;
    if ($fechaVac !== null) {
        $fechaVac = trim((string)$fechaVac);
        if (strlen($fechaVac) >= 10) {
            // dd-mm-yyyy...
            $y = substr($fechaVac, 6, 4);
            if (ctype_digit($y)) {
                $year = (int)$y;
            }
        }
    }

    // Columnas propias del archivo nuevo
    $diasProg    = adp_normalize_decimal($row['Dias Progresivos'] ?? null);
    $diasAnual   = adp_normalize_decimal($row['Dias Anuales'] ?? null);
    $diasProp    = adp_normalize_decimal($row['Dias Proporcionales'] ?? null);
    $diasAdic    = adp_normalize_decimal($row['Dias Adicionales'] ?? null);
    $diasHabiles = adp_normalize_decimal(
        $row['Dias habiles (Normales + Progresivos+ Adicionales)'] ?? null
    );

    // "Otorgados" = Anuales + Progresivos + Proporcionales + Adicionales
    $granted = 0.0;
    foreach ([$diasAnual, $diasProg, $diasProp, $diasAdic] as $v) {
        if ($v !== null) {
            $granted += $v;
        }
    }

    // Saldo = campo de días hábiles; si no existe, usamos otorgados
    $balance = $diasHabiles;
    if ($balance === null) {
        $balance = $granted;
    }

    $totalBalance = $balance;

    $byYear = [[
        'year'    => $year,
        'granted' => $granted,
        'taken'   => null,    // Este archivo no trae días tomados
        'balance' => $balance,
    ]];

    return [
        'found'        => true,
        'rows'         => $matchedRows,   // fila(s) cruda(s) para poder mostrar más info en la vista
        'byYear'       => $byYear,
        'totalBalance' => $totalBalance,
        'matchedBy'    => $matchedBy,
        'debug'        => $debug,
    ];
}

/**
 * Dado el saldo calculado y los días solicitados, define si alcanza o no.
 */
function decision_from_file_balance(array $saldo, float $diasSolicitados): array
{
    $total     = isset($saldo['totalBalance']) ? (float)$saldo['totalBalance'] : 0.0;
    $remaining = $total - $diasSolicitados;
    $can       = $remaining >= -0.0001; // pequeño margen

    return [
        'requested'   => $diasSolicitados,
        'available'   => $total,
        'remaining'   => $remaining,
        'canApprove'  => $can,
        'label'       => $can ? 'Saldo suficiente' : 'Saldo insuficiente',
    ];
}
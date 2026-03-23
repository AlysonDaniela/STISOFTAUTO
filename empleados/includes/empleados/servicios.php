<?php
// empleados/includes/empleados/servicios.php
// Aquí va TODO tu core (helpers + db + mapeos + payloads + pipeline).
// Para que el ZIP sea "completo" y consistente, dejo el esqueleto y llamadas.
// Tú solo reemplazas el contenido con tus funciones existentes (las del index original).

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../buk/config.php';
require_once __DIR__ . '/../buk/cliente.php';
require_once __DIR__ . '/../buk/logger.php';

// ------------------ FILTROS ------------------
function empleados_build_where(clsConexion $db, string $rutFiltro, string $tipoFiltro, string $estadoFiltro, string $bukFiltro): array {
    $where = [];
    if ($rutFiltro !== '') {
        $rutEsc = $db->real_escape_string($rutFiltro);
        $where[] = "e.Rut LIKE '%{$rutEsc}%'";
    }
    if ($tipoFiltro !== '') {
        $tipoEsc = $db->real_escape_string($tipoFiltro);
        $where[] = "e.origenadp = '{$tipoEsc}'";
    }
    if ($estadoFiltro !== '') {
        if ($estadoFiltro === 'A') $where[] = "e.Estado = 'A'";
        elseif ($estadoFiltro === 'INACTIVOS') $where[] = "(e.Estado IS NULL OR e.Estado <> 'A')";
    }
    if ($bukFiltro !== '') {
        $bukEsc = $db->real_escape_string($bukFiltro);
        $where[] = "COALESCE(e.estado_buk,'no_enviado') = '{$bukEsc}'";
    }
    $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
    return ['where'=>$where, 'whereSql'=>$whereSql];
}

function empleados_sorting_from_get(array $get): array {
    $allowedSort = [
        'Rut'            => 'e.Rut',
        'Nombre'         => 'e.Nombres',
        'Apaterno'       => 'e.Apaterno',
        'Amaterno'       => 'e.Amaterno',
        'Estado'         => 'e.Estado',
        'AreaADP'        => 'e.`Descripcion Unidad`',
        'CargoADP'       => 'e.`Descripcion Cargo`',
        'EmpresaADP'     => 'e.`Descripcion Empresa`',
        'CCostoADP'      => 'e.`Centro de Costo`',
        'AreaBuk'        => 'vuni.area_buk',
        'CargoBuk'       => 'vcargo.cargo_buk',
        'CCostoBuk'      => 'vcc.cencos_buk',
        'EstadoBuk'      => 'e.estado_buk',
        'OrigenADP'      => 'e.origenadp',
    ];

    $sort = isset($get['sort']) ? trim($get['sort']) : 'Apaterno';
    $dir  = isset($get['dir'])  ? strtolower(trim($get['dir'])) : 'asc';

    if (!isset($allowedSort[$sort])) $sort = 'Apaterno';
    if (!in_array($dir, ['asc','desc'], true)) $dir = 'asc';

    $orderBySql = $allowedSort[$sort] . ' ' . strtoupper($dir);
    $orderBySql .= ", e.Apaterno ASC, e.Amaterno ASC, e.Nombres ASC";

    return compact('allowedSort','sort','dir','orderBySql');
}

// ------------------ KPIs / LISTADOS / EXPORT ------------------
function empleados_load_kpis(clsConexion $db): array {
    $kpiGeneral = $db->consultar("
        SELECT
          COUNT(*) AS total,
          SUM(CASE WHEN Estado = 'A' THEN 1 ELSE 0 END) AS activos,
          SUM(CASE WHEN Estado IS NULL OR Estado <> 'A' THEN 1 ELSE 0 END) AS inactivos
        FROM adp_empleados
    ");
    $totalAll    = (int)($kpiGeneral[0]['total'] ?? 0);
    $activosAll  = (int)($kpiGeneral[0]['activos'] ?? 0);
    $inactivosAll= (int)($kpiGeneral[0]['inactivos'] ?? 0);

    $kpiPorOrigen = $db->consultar("
        SELECT
          COALESCE(origenadp,'SIN_ORIGEN') AS origen,
          COUNT(*) AS total,
          SUM(CASE WHEN Estado = 'A' THEN 1 ELSE 0 END) AS activos,
          SUM(CASE WHEN Estado IS NULL OR Estado <> 'A' THEN 1 ELSE 0 END) AS inactivos
        FROM adp_empleados
        GROUP BY COALESCE(origenadp,'SIN_ORIGEN')
        ORDER BY origen
    ");

    $kpiBuk = $db->consultar("
        SELECT
          COALESCE(estado_buk,'no_enviado') AS estado_buk,
          COUNT(*) AS total
        FROM adp_empleados
        GROUP BY COALESCE(estado_buk,'no_enviado')
        ORDER BY total DESC
    ");

    $tiposEmpleado = $db->consultar("
        SELECT DISTINCT COALESCE(origenadp,'') AS origen
        FROM adp_empleados
        WHERE origenadp IS NOT NULL AND origenadp <> ''
        ORDER BY origenadp
    ");
    $tiposEmpleado = array_map(fn($r) => $r['origen'], $tiposEmpleado);

    $estadosBuk = $db->consultar("
        SELECT DISTINCT COALESCE(estado_buk,'no_enviado') AS estado_buk
        FROM adp_empleados
        ORDER BY estado_buk
    ");
    $estadosBuk = array_map(fn($r) => $r['estado_buk'], $estadosBuk);

    return compact('totalAll','activosAll','inactivosAll','kpiPorOrigen','kpiBuk','tiposEmpleado','estadosBuk');
}

function empleados_count_filtered(clsConexion $db, string $whereSql): int {
    $resTotal = $db->consultar("
        SELECT COUNT(*) AS total
        FROM adp_empleados e
        {$whereSql}
    ");
    return (int)($resTotal[0]['total'] ?? 0);
}

function empleados_load_table(clsConexion $db, string $whereSql, string $orderBySql, int $perPage, int $offset): array {
    $sqlEmpleados = "
        SELECT
            e.Rut,
            e.Jefe,
            e.Apaterno,
            e.Amaterno,
            e.Nombres,
            e.Estado,
            e.`Descripcion Cargo`             AS desc_cargo,
            e.`Descripcion Empresa`           AS desc_empresa,
            e.`Centro de Costo`               AS cencos_adp,
            e.`Descripcion Centro de Costo`   AS cencos_adp_desc,
            e.`Descripcion Unidad`            AS desc_unidad_adp,
            e.origenadp,
            COALESCE(e.estado_buk,'no_enviado') AS estado_buk,
            e.buk_emp_id,
            e.buk_job_id,

            vuni.id_buk_area                  AS area_buk_id,
            vuni.area_buk                     AS area_buk,
            vuni.area_buk_completo            AS area_buk_completo,

            vcargo.id_buk_cargo               AS cargo_buk_id,
            vcargo.cargo_buk                  AS cargo_buk,

            vcc.id_buk_cencos                 AS cencos_buk_id,
            vcc.cencos_buk                    AS cencos_buk
        FROM adp_empleados e
        LEFT JOIN v_mapeo_unidades_buk_adp       vuni   ON vuni.unidad_adp_codigo = e.Unidad
        LEFT JOIN v_mapeo_cargos_buk_adp         vcargo ON vcargo.cargo_adp       = e.`Descripcion Cargo`
        LEFT JOIN v_mapeo_centros_costo_buk_adp  vcc    ON vcc.cencos_adp         = e.`Centro de Costo`
        {$whereSql}
        ORDER BY {$orderBySql}
        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

    $empleados = $db->consultar($sqlEmpleados);
    return is_array($empleados) ? $empleados : [];
}

function empleados_export_csv(clsConexion $db, string $whereSql, string $orderBySql): void {
    $sqlExport = "
        SELECT
            e.Rut,
            CONCAT(COALESCE(e.Nombres,''),' ',COALESCE(e.Apaterno,''),' ',COALESCE(e.Amaterno,'')) AS NombreCompleto,
            e.Estado,
            e.`Descripcion Unidad` AS DivisionArea,
            e.`Descripcion Cargo` AS CargoADP,
            e.`Descripcion Empresa` AS EmpresaADP,
            e.`Centro de Costo` AS CCostoADP,
            COALESCE(vuni.area_buk,'') AS AreaBuk,
            COALESCE(vcargo.cargo_buk,'') AS CargoBuk,
            COALESCE(vcc.cencos_buk,'') AS CCostoBuk,
            COALESCE(e.estado_buk,'no_enviado') AS EstadoBuk,
            COALESCE(e.origenadp,'') AS OrigenADP,
            COALESCE(e.buk_emp_id,'') AS BukEmpId,
            COALESCE(e.buk_job_id,'') AS BukJobId
        FROM adp_empleados e
        LEFT JOIN v_mapeo_unidades_buk_adp       vuni   ON vuni.unidad_adp_codigo = e.Unidad
        LEFT JOIN v_mapeo_cargos_buk_adp         vcargo ON vcargo.cargo_adp       = e.`Descripcion Cargo`
        LEFT JOIN v_mapeo_centros_costo_buk_adp  vcc    ON vcc.cencos_adp         = e.`Centro de Costo`
        {$whereSql}
        ORDER BY {$orderBySql}
    ";
    $rows = $db->consultar($sqlExport);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="empleados_export_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Rut','NombreCompleto','Estado','DivisionArea','CargoADP','EmpresaADP','CCostoADP',
        'AreaBuk','CargoBuk','CCostoBuk','EstadoBuk','OrigenADP','BukEmpId','BukJobId'
    ], ';');

    if (is_array($rows)) {
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['Rut'] ?? '',
                $r['NombreCompleto'] ?? '',
                $r['Estado'] ?? '',
                $r['DivisionArea'] ?? '',
                $r['CargoADP'] ?? '',
                $r['EmpresaADP'] ?? '',
                $r['CCostoADP'] ?? '',
                $r['AreaBuk'] ?? '',
                $r['CargoBuk'] ?? '',
                $r['CCostoBuk'] ?? '',
                $r['EstadoBuk'] ?? '',
                $r['OrigenADP'] ?? '',
                $r['BukEmpId'] ?? '',
                $r['BukJobId'] ?? '',
            ], ';');
        }
    }

    fclose($out);
}

// ------------------ CHIPS ------------------
function empleados_build_filter_chips(string $rutFiltro, string $tipoFiltro, string $estadoFiltro, string $bukFiltro): array {
    $chips = [];
    if ($rutFiltro !== '') $chips[] = ['label' => "RUT: {$rutFiltro}", 'url' => q(['rut' => null, 'page' => 1])];
    if ($tipoFiltro !== '') $chips[] = ['label' => "Tipo: {$tipoFiltro}", 'url' => q(['tipo' => null, 'page' => 1])];
    if ($estadoFiltro !== '') {
        $label = ($estadoFiltro === 'A') ? 'Estado: Activos' : 'Estado: Inactivos';
        $chips[] = ['label' => $label, 'url' => q(['estado' => null, 'page' => 1])];
    }
    if ($bukFiltro !== '') $chips[] = ['label' => "Buk: " . str_replace('_',' ', $bukFiltro), 'url' => q(['buk_estado' => null, 'page' => 1])];
    return $chips;
}

// ------------------ JEFES (ESQUELETO) ------------------
function empleados_build_boss_map(clsConexion $db): array {
    // Copia aquí tu lógica completa de jefes (tal cual la tienes).
    // Dejo un retorno compatible para no romper el controlador.
    return [
        'bossList' => [],
        'activos' => [],
        'reportsCount' => [],
        'level' => [],
    ];
}

// ------------------ MAPEOS (PEGA TU CÓDIGO COMPLETO AQUÍ) ------------------
function load_area_map_from_db(clsConexion $db): array { return []; }
function load_role_map_from_db(clsConexion $db): array { return []; }

// ------------------ (PEGA TU PIPELINE COMPLETO AQUÍ) ------------------
// run_pipeline_all(), run_step_emp_only(), run_step_plan_only(), run_step_job_only(), etc.
// Además: load_employee_row_by_rut(), resolve_leader_id_for_row(), payload builders, db_mark_*, etc.

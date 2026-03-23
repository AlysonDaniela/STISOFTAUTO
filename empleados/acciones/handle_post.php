<?php
// empleados/acciones/handle_post.php
require_once __DIR__ . '/../includes/empleados/servicios.php';

// Este handler encapsula TODO el POST (masivos + manual).
// En este ZIP dejo la estructura lista. Tú pegas tu bloque POST completo aquí
// y reemplazas llamadas a funciones si lo prefieres.

function empleados_handle_post(
    clsConexion $db,
    array $post,
    string $whereSql,
    string $orderBySql,
    array $AREA_MAP,
    array $ROLE_MAP,
    array $bossList,
    array $activos
): array {

    $send_ui = null;
    $bulk_ui = null;
    $bulk_fail_list = [];

    // ✅ Aquí pega tu bloque: if ($_SERVER['REQUEST_METHOD']==='POST') { ... }
    // - Usando $post['action'], $post['rut'], $post['ruts'], etc.
    // - Y devolviendo $send_ui/$bulk_ui/$bulk_fail_list

    return compact('send_ui','bulk_ui','bulk_fail_list');
}

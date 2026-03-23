<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
    }
    exit('Acceso denegado.');
}

if (!function_exists('h')) {
    function h(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

function render_list_inline(array $items, int $max = 15): string
{
    $items = array_values(array_filter(array_map(function ($v) {
        return trim((string)$v);
    }, $items), fn($v) => $v !== ''));

    if (empty($items)) {
        return '-';
    }

    if (count($items) > $max) {
        $shown = array_slice($items, 0, $max);
        return implode(', ', $shown) . ' ... (+' . (count($items) - $max) . ')';
    }

    return implode(', ', $items);
}

function render_error_items_html(array $items): string
{
    if (empty($items)) {
        return '<div style="color:#6b7280;">Sin errores detallados</div>';
    }

    $html = '<ul style="margin:8px 0 0 18px;padding:0;">';
    foreach ($items as $e) {
        $rut = h($e['rut'] ?? '');
        $linea = h((string)($e['linea'] ?? ''));
        $error = h($e['error'] ?? 'Error no especificado');
        $html .= '<li style="margin-bottom:4px;">Línea ' . $linea . ' · RUT ' . $rut . ' · ' . $error . '</li>';
    }
    $html .= '</ul>';

    return $html;
}

function render_failed_items_html(array $items): string
{
    if (empty($items)) {
        return '<div style="color:#6b7280;">Sin fallas detalladas</div>';
    }

    $html = '<ul style="margin:8px 0 0 18px;padding:0;">';
    foreach ($items as $e) {
        $rut = h((string)($e['rut'] ?? '-'));
        $stage = h((string)($e['stage'] ?? '-'));
        $http = h((string)($e['http'] ?? '0'));
        $msg = h((string)($e['msg'] ?? 'Error no especificado'));
        $html .= '<li style="margin-bottom:6px;">RUT ' . $rut . ' · Etapa ' . $stage . ' · HTTP ' . $http . ' · ' . $msg . '</li>';
    }
    $html .= '</ul>';

    return $html;
}

function find_file_by_script_suffix(array $files, string $suffix): ?array
{
    foreach ($files as $f) {
        $script = (string)($f['script'] ?? '');
        if ($script !== '' && substr($script, -strlen($suffix)) === $suffix) {
            return $f;
        }
    }
    return null;
}

function find_file_by_tipo(array $files, string $tipo): ?array
{
    foreach ($files as $f) {
        if ((string)($f['tipo'] ?? '') === $tipo) {
            return $f;
        }
    }
    return null;
}

function status_badge(string $status): string
{
    $status = strtolower(trim($status));
    $colors = [
        'ok'          => ['#dcfce7', '#166534'],
        'error'       => ['#fee2e2', '#991b1b'],
        'unsupported' => ['#fef3c7', '#92400e'],
        'unknown'     => ['#e5e7eb', '#374151'],
    ];
    [$bg, $fg] = $colors[$status] ?? ['#e5e7eb', '#374151'];

    return '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:' . $bg . ';color:' . $fg . ';font-size:12px;font-weight:700;">' . h(strtoupper($status)) . '</span>';
}

function process_message(array $event, array $processedStats): array
{
    $status = strtolower((string)($event['status'] ?? 'unknown'));
    $errores = (int)($processedStats['global']['errores_total'] ?? 0);
    $unsupported = (int)($processedStats['global']['files_unsupported'] ?? 0);

    if ($status === 'ok' && $errores === 0 && $unsupported === 0) {
        return [
            'title' => '✅ Sincronización completada',
            'desc'  => 'Todo terminó correctamente.',
            'bg'    => '#dcfce7',
            'fg'    => '#166534',
        ];
    }

    if ($status === 'ok') {
        return [
            'title' => 'ℹ️ Sincronización completada con observaciones',
            'desc'  => 'Terminó, pero hay puntos para revisar.',
            'bg'    => '#fef3c7',
            'fg'    => '#92400e',
        ];
    }

    return [
        'title' => '❌ Sincronización con errores',
        'desc'  => 'El proceso no terminó correctamente.',
        'bg'    => '#fee2e2',
        'fg'    => '#991b1b',
    ];
}

function calc_global_totals(array $files): array
{
    $altasTotales = 0;
    $reingresosTotales = 0;
    $bajasTotales = 0;
    $erroresTotales = 0;

    foreach ($files as $f) {
        $r = $f['result'] ?? [];
        if (($f['tipo'] ?? '') !== 'empleados') {
            continue;
        }

        $altasTotales += (int)($r['altas_nuevas'] ?? $r['insertados'] ?? 0);
        $reingresosTotales += (int)($r['altas_reingreso'] ?? $r['actualizados'] ?? 0);
        $bajasTotales += (int)($r['bajas_detectadas'] ?? 0);
        $erroresTotales += (int)($r['errores'] ?? 0);
    }

    return [
        'altas_totales'   => $altasTotales + $reingresosTotales,
        'altas_nuevas'    => $altasTotales,
        'reingresos'      => $reingresosTotales,
        'bajas_totales'   => $bajasTotales,
        'errores_totales' => $erroresTotales,
    ];
}

function report_table_rows_html(array $rows, int $labelWidth = 260): string
{
    $html = '';
    foreach ($rows as $k => $v) {
        $html .= '<tr>';
        $html .= '<td style="padding:10px;border-bottom:1px solid #e5e7eb;background:#fafafa;font-weight:700;width:' . $labelWidth . 'px;">' . h((string)$k) . '</td>';
        $html .= '<td style="padding:10px;border-bottom:1px solid #e5e7eb;">' . h((string)$v) . '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function cambios_detected_count(array $result): int
{
    $changedRuts = array_values(array_filter((array)($result['changed_ruts'] ?? []), function ($value) {
        return trim((string)$value) !== '';
    }));
    if (!empty($changedRuts)) {
        return count(array_unique($changedRuts));
    }

    $detailTotals = (array)($result['detail_totals'] ?? []);
    $detected = (int)($detailTotals['detected'] ?? 0);
    $skip = (int)($detailTotals['skip'] ?? 0);
    $ok = (int)($detailTotals['ok'] ?? 0);
    $error = (int)($detailTotals['error'] ?? 0);
    $pending = (int)($detailTotals['pending'] ?? 0);
    $queueError = (int)($detailTotals['queue_error'] ?? 0);

    $effective = $detected - $skip;
    if ($effective > 0) {
        return $effective;
    }

    return max($ok + $error + $pending + $queueError, 0);
}

function buk_jerarquias_change_count(array $result): int
{
    $emp = (array)($result['empresas'] ?? []);
    $jer = (array)($result['jerarquia'] ?? []);
    $car = (array)($result['cargos'] ?? []);

    return
        (int)($emp['cambios_nuevos'] ?? 0) +
        (int)($jer['cambios_nuevos'] ?? 0) +
        (int)($car['cambios_nuevos'] ?? 0);
}

function employee_metric_cards_html(array $metrics): string
{
    $html = '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">';
    foreach ($metrics as $metric) {
        $html .= '<div style="flex:1 1 180px;min-width:170px;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;background:#ffffff;">';
        $html .= '<div style="font-size:12px;color:#6b7280;margin-bottom:6px;">' . $metric['icon'] . ' ' . h($metric['label']) . '</div>';
        $html .= '<div style="font-size:22px;font-weight:700;color:' . h($metric['color']) . ';">' . h((string)$metric['value']) . '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

function employee_metric_lines_html(array $metrics): string
{
    $html = '<table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:8px;">';
    foreach ($metrics as $metric) {
        $html .= '<tr>';
        $html .= '<td style="padding:9px;border-bottom:1px solid #eef2f7;background:#fafafa;font-weight:700;width:280px;">' . $metric['icon'] . ' ' . h($metric['label']) . '</td>';
        $html .= '<td style="padding:9px;border-bottom:1px solid #eef2f7;">' . h((string)$metric['value']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';

    return $html;
}

function sum_employee_activity(array $files): array
{
    $altas = 0;
    $reingresos = 0;
    $bajas = 0;
    $errores = 0;

    foreach ($files as $f) {
        if (($f['tipo'] ?? '') !== 'empleados') {
            continue;
        }
        $r = (array)($f['result'] ?? []);
        $altas += (int)($r['altas_nuevas'] ?? $r['insertados'] ?? 0);
        $reingresos += (int)($r['altas_reingreso'] ?? $r['actualizados'] ?? 0);
        $bajas += (int)($r['bajas_detectadas'] ?? 0);
        $errores += (int)($r['errores'] ?? 0);
    }

    return [
        'altas' => $altas,
        'reingresos' => $reingresos,
        'bajas' => $bajas,
        'errores' => $errores,
    ];
}

function summarize_stage_parts(array $parts, string $emptyLabel = 'Sin cambios'): string
{
    $parts = array_values(array_filter($parts, function ($part) {
        return trim((string)$part) !== '';
    }));

    if (empty($parts)) {
        return $emptyLabel;
    }

    return implode(' · ', $parts);
}

function build_html_report(string $runDate, array $event, array $processedStats, array $history = []): string
{
    $g = $processedStats['global'] ?? [];
    $files = $processedStats['files'] ?? [];
    $msg = process_message($event, $processedStats);
    $totals = calc_global_totals($files);
    $bukOkTotal = (int)($g['altas_buk_ok_total'] ?? 0);
    $bukErrTotal = (int)($g['altas_buk_error_total'] ?? 0);

    $jerarquiaMsg = null;

    foreach ($files as $f) {
        if (($f['tipo'] ?? '') !== 'jerarquias') {
            continue;
        }

        $status = strtolower((string)($f['status'] ?? ''));
        $r = $f['result'] ?? [];

        if ($status === 'error') {
            $jerarquiaMsg = 'Jerarquías: Error - ver manual en el sistema';
        } else {
            $totalCambios =
    (int)($r['empresas']['nuevas'] ?? 0) +
    (int)($r['jerarquia']['divisiones_nuevas'] ?? 0) +
    (int)($r['jerarquia']['cc_nuevos'] ?? 0) +
    (int)($r['jerarquia']['unidades_nuevas'] ?? 0) +
    (int)($r['cargos']['nuevos'] ?? 0);

            if ($totalCambios > 0) {
                $jerarquiaMsg = 'Jerarquías: Cambios nuevos: ' . $totalCambios;
            } else {
                $jerarquiaMsg = 'Jerarquías: Sin cambios';
            }
        }
    }

    $stepJer = find_file_by_script_suffix($files, '/process_jerarquias.php');
    $stepCambios = find_file_by_script_suffix($files, '/process_cambios.php');
    $stepBukJer = find_file_by_script_suffix($files, '/process_buk_jerarquias.php');
    $stepBukEmp = find_file_by_script_suffix($files, '/process_buk_empleados.php');
    $stepBukBajas = find_file_by_script_suffix($files, '/process_buk_bajas.php');
    $stepVacaciones = find_file_by_tipo($files, 'vacaciones');
    $employeeActivity = sum_employee_activity($files);
    $empleadosOk = 0;
    $empleadosErr = 0;
    foreach ($files as $f) {
        if (($f['tipo'] ?? '') !== 'empleados') continue;
        $st = strtolower((string)($f['status'] ?? 'unknown'));
        if ($st === 'ok') $empleadosOk++;
        else $empleadosErr++;
    }

    $bukJerMsg = strtoupper((string)($stepBukJer['status'] ?? 'NO_EJECUTADO'));
    if ($stepBukJer) {
        $rBukJer = (array)($stepBukJer['result'] ?? []);
        $bukJerTotal = buk_jerarquias_change_count($rBukJer);
        if (strtolower((string)($stepBukJer['status'] ?? '')) !== 'error' && $bukJerTotal === 0) {
            $bukJerMsg = 'Sin cambios';
        }
    }

    $bukBajasMsg = strtoupper((string)($stepBukBajas['status'] ?? 'NO_EJECUTADO'));
    if ($stepBukBajas) {
        $rBukBajas = (array)($stepBukBajas['result'] ?? []);
        $bukBajasTotal =
            (int)($rBukBajas['requested'] ?? 0) +
            (int)($rBukBajas['done'] ?? 0) +
            (int)($rBukBajas['failed'] ?? 0);
        if (strtolower((string)($stepBukBajas['status'] ?? '')) !== 'error' && $bukBajasTotal === 0) {
            $bukBajasMsg = 'Sin cambios';
        }
    }

    $rows = [
        'Resultado' => $event['message'] ?? '-',
        'Duración' => $event['duration'] ?? '-',
        'Archivos detectados' => (string)($g['files_detected'] ?? 0),
    ];
    if ((int)($totals['altas_totales'] ?? 0) > 0) {
        $rows['Altas totales'] = (string)($totals['altas_totales'] ?? 0);
    }
    if ((int)($totals['bajas_totales'] ?? 0) > 0) {
        $rows['Bajas totales'] = (string)($totals['bajas_totales'] ?? 0);
    }
    if ($bukOkTotal > 0) {
        $rows['Altas BUK OK'] = (string)$bukOkTotal;
    }
    if ($bukErrTotal > 0) {
        $rows['Altas BUK error'] = (string)$bukErrTotal;
    }
    if ((int)($totals['errores_totales'] ?? 0) > 0) {
        $rows['Errores'] = (string)($totals['errores_totales'] ?? 0);
    }
    if ((int)($g['files_skipped'] ?? 0) > 0) {
        $rows['Omitidos'] = (string)($g['files_skipped'] ?? 0);
    }
    if ((int)($g['files_unsupported'] ?? 0) > 0) {
        $rows['No soportados'] = (string)($g['files_unsupported'] ?? 0);
    }

    $adpEmpleadosMsg = summarize_stage_parts([
        $employeeActivity['altas'] > 0 ? 'Altas: ' . $employeeActivity['altas'] : '',
        $employeeActivity['reingresos'] > 0 ? 'Reingresos: ' . $employeeActivity['reingresos'] : '',
        $employeeActivity['errores'] > 0 ? 'Errores: ' . $employeeActivity['errores'] : '',
    ]);
    $adpBajasMsg = ((int)$employeeActivity['bajas'] > 0)
        ? ('Bajas: ' . $employeeActivity['bajas'])
        : 'Sin cambios';

    $cambiosTotal = 0;
    $cambiosErrores = 0;
    foreach ($files as $f) {
        if (($f['tipo'] ?? '') !== 'cambios') {
            continue;
        }
        $rCambios = (array)($f['result'] ?? []);
        $cambiosTotal += cambios_detected_count($rCambios);
        $cambiosErrores += (int)($rCambios['sent_error'] ?? 0);
    }
    $adpCambiosMsg = 'Sin cambios';
    if ($cambiosTotal > 0 || $cambiosErrores > 0) {
        $adpCambiosMsg = summarize_stage_parts([
            $cambiosTotal > 0 ? 'Detectados: ' . $cambiosTotal : '',
            $cambiosErrores > 0 ? 'Errores: ' . $cambiosErrores : '',
        ]);
    }

    $bukEmpMsg = 'Sin cambios';
    if ($stepBukEmp) {
        $rBukEmp = (array)($stepBukEmp['result'] ?? []);
        $bukEmpDone = (int)($rBukEmp['done'] ?? 0);
        $bukEmpFail = (int)($rBukEmp['failed'] ?? 0);
        if ($bukEmpDone > 0 || $bukEmpFail > 0) {
            $bukEmpMsg = summarize_stage_parts([
                $bukEmpDone > 0 ? 'OK: ' . $bukEmpDone : '',
                $bukEmpFail > 0 ? 'Error: ' . $bukEmpFail : '',
            ]);
        }
    }

    $bukCambiosMsg = 'Sin cambios';
    if ($cambiosTotal > 0 || $cambiosErrores > 0) {
        $bukCambiosMsg = summarize_stage_parts([
            $cambiosTotal > 0 ? 'Detectados: ' . $cambiosTotal : '',
            $cambiosErrores > 0 ? 'Error: ' . $cambiosErrores : '',
        ]);
    }

    $vacacionesMsg = 'Archivo no encontrado';
    if ($stepVacaciones) {
        $rVac = (array)($stepVacaciones['result'] ?? []);
        $vacRequested = (int)($rVac['requested'] ?? 0);
        $vacDone = (int)($rVac['done'] ?? 0);
        $vacFailed = (int)($rVac['failed'] ?? 0);
        $vacMessage = trim((string)($rVac['message'] ?? ''));

        if (stripos($vacMessage, 'no encontrado') !== false) {
            $vacacionesMsg = 'Archivo no encontrado';
        } elseif ($vacRequested === 0 && $vacDone === 0 && $vacFailed === 0) {
            $vacacionesMsg = 'Sin cambios';
        } else {
            $vacacionesMsg = summarize_stage_parts([
                'Detectados: ' . $vacRequested,
                'OK: ' . $vacDone,
                $vacFailed > 0 ? 'Error: ' . $vacFailed : '',
            ]);
        }
    }

    $pipelineRows = [
        'ADP-STISOFT Empleados' => $adpEmpleadosMsg,
        'ADP-STISOFT Bajas' => $adpBajasMsg,
        'ADP-STISOFT Cambios' => $adpCambiosMsg,
        'STISOFT-BUK Empleados' => $bukEmpMsg,
        'STISOFT-BUK Bajas' => $bukBajasMsg,
        'STISOFT-BUK Cambios' => $bukCambiosMsg,
        'STISOFT-BUK Vacaciones' => $vacacionesMsg,
    ];
    $detailHtml = '';
    if (empty($files)) {
        $detailHtml .= '<div style="padding:14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;color:#6b7280;">No hay archivos registrados en esta ejecución.</div>';
    } else {
        foreach ($files as $f) {
            $r = $f['result'] ?? [];

            // Saltar bloque jerarquías del detalle largo
            if (($f['tipo'] ?? '') === 'jerarquias') {
                continue;
            }

            $altasNuevas = (int)($r['altas_nuevas'] ?? $r['insertados'] ?? 0);
            $reingresos  = (int)($r['altas_reingreso'] ?? $r['actualizados'] ?? 0);
            $bajas       = (int)($r['bajas_detectadas'] ?? 0);
            $errores     = (int)($r['errores'] ?? 0);

            $detailHtml .= '<div style="border:1px solid #e5e7eb;border-radius:14px;padding:18px;margin-bottom:16px;background:#fcfcfd;">';

            $detailHtml .= '<div style="margin-bottom:10px;">' . status_badge((string)($f['status'] ?? 'unknown')) . '</div>';
            $cardTitle = (string)($f['file'] ?? '-');
            $cardSubtitle = '';
            if (($f['tipo'] ?? '') === 'cambios') {
                $cardTitle = 'PROCESO BUK CAMBIOS';
                $cardSubtitle = (string)($f['file'] ?? '-');
            } elseif (($f['tipo'] ?? '') === 'buk_jerarquias') {
                $cardTitle = 'PROCESO BUK JERARQUIAS';
            } elseif (($f['tipo'] ?? '') === 'buk_empleados') {
                $cardTitle = 'PROCESO BUK ALTAS';
            } elseif (($f['tipo'] ?? '') === 'buk_bajas') {
                $cardTitle = 'PROCESO BUK BAJAS';
            } elseif (($f['tipo'] ?? '') === 'vacaciones') {
                $cardTitle = 'PROCESO BUK VACACIONES';
                if (($f['file'] ?? '-') !== '-') {
                    $cardSubtitle = (string)($f['file'] ?? '-');
                }
            }
            $detailHtml .= '<div style="font-size:17px;font-weight:700;margin-bottom:4px;">' . h($cardTitle) . '</div>';
            if ($cardSubtitle !== '') {
                $detailHtml .= '<div style="font-size:12px;color:#6b7280;font-style:italic;margin-bottom:12px;">' . h($cardSubtitle) . '</div>';
            } else {
                $detailHtml .= '<div style="margin-bottom:12px;"></div>';
            }

            if (($r['status'] ?? '') === 'unsupported') {
                $detailHtml .= '<div style="margin-top:12px;font-size:14px;color:#92400e;"><strong>Detalle:</strong> ' . h($r['message'] ?? 'Archivo no soportado') . '</div>';
                $detailHtml .= '</div>';
                continue;
            }

            if (($r['status'] ?? '') === 'error') {
                $detailHtml .= '<div style="margin-top:12px;font-size:14px;color:#991b1b;"><strong>Detalle:</strong> ' . h($r['message'] ?? 'Error no especificado') . '</div>';
                if (!empty($r['failed_items']) && is_array($r['failed_items'])) {
                    $detailHtml .= '<div style="margin-top:10px;font-size:14px;"><strong>Fallos BUK:</strong>' . render_failed_items_html(array_slice($r['failed_items'], 0, 20)) . '</div>';
                }
                if (!empty($f['raw_output'])) {
                    $detailHtml .= '<div style="margin-top:10px;padding:10px;background:#f9fafb;border-radius:8px;font-size:12px;white-space:pre-wrap;">' . h($f['raw_output'] ?? '-') . '</div>';
                }
                $detailHtml .= '</div>';
                continue;
            }

            if (($f['tipo'] ?? '') === 'buk_jerarquias') {
                $emp = (array)($r['empresas'] ?? []);
                $jer = (array)($r['jerarquia'] ?? []);
                $car = (array)($r['cargos'] ?? []);
                $totalBukJer = buk_jerarquias_change_count($r);

                if ($totalBukJer === 0 && strtolower((string)($f['status'] ?? '')) !== 'error') {
                    $detailHtml .= '<div style="margin-top:10px;padding:10px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;font-size:14px;font-weight:600;">Sin cambios</div>';
                    $detailHtml .= '</div>';
                    continue;
                }

                $detailHtml .= '<table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:8px;">';
                $rowsBukJer = [
                    'Cambios empresas' => (string)($emp['cambios_nuevos'] ?? 0),
                    'Cambios jerarquía' => (string)($jer['cambios_nuevos'] ?? 0),
                    'Cambios cargos' => (string)($car['cambios_nuevos'] ?? 0),
                ];
                $detailHtml .= report_table_rows_html($rowsBukJer, 280);
                $detailHtml .= '</table>';

                if (!empty($r['message'])) {
                    $detailHtml .= '<div style="margin-top:10px;font-size:14px;"><strong>Detalle:</strong> ' . h((string)$r['message']) . '</div>';
                }

                $detailHtml .= '</div>';
                continue;
            }

            if (($f['tipo'] ?? '') === 'cambios') {
                $detectedChanges = cambios_detected_count($r);
                if ($detectedChanges === 0 && strtolower((string)($f['status'] ?? '')) !== 'error') {
                    $detailHtml .= '<div style="margin-top:10px;padding:10px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;font-size:14px;font-weight:600;">Sin cambios</div>';
                    $detailHtml .= '</div>';
                    continue;
                }

                $detailHtml .= '<table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:8px;">';
                $rowsCambios = [
                    'Detectados' => (string)$detectedChanges,
                    'OK' => (string)($r['sent_ok'] ?? 0),
                    'Error' => (string)($r['sent_error'] ?? 0),
                ];
                $detailHtml .= report_table_rows_html($rowsCambios, 280);
                $detailHtml .= '</table>';

                $fSummary = (array)($r['failure_summary'] ?? []);
                if (!empty($fSummary)) {
                    $detailHtml .= '<div style="margin-top:10px;font-size:14px;"><strong>Resumen de errores:</strong></div>';
                    if (!empty($fSummary['by_stage']) && is_array($fSummary['by_stage'])) {
                        $parts = [];
                        foreach ($fSummary['by_stage'] as $st => $cnt) {
                            $parts[] = $st . ': ' . (int)$cnt;
                        }
                        $detailHtml .= '<div style="margin-top:4px;font-size:13px;color:#374151;">' . h(implode(' · ', $parts)) . '</div>';
                    }
                    if (!empty($fSummary['top_messages']) && is_array($fSummary['top_messages'])) {
                        $detailHtml .= '<ul style="margin:6px 0 0 18px;padding:0;">';
                        foreach (array_slice($fSummary['top_messages'], 0, 5) as $tm) {
                            $detailHtml .= '<li style="margin-bottom:4px;">' . h((string)($tm['message'] ?? '-')) . ' · ' . h((string)($tm['count'] ?? 0)) . '</li>';
                        }
                        $detailHtml .= '</ul>';
                    }
                }

                if (!empty($r['column_change_samples']) && is_array($r['column_change_samples'])) {
                    $detailHtml .= '<div style="margin-top:10px;font-size:14px;"><strong>Cambios detectados:</strong></div>';
                    $detailHtml .= '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:6px;">';
                    $detailHtml .= '<tr><td style="padding:7px;border-bottom:1px solid #eef2f7;background:#fafafa;font-weight:700;">RUT</td><td style="padding:7px;border-bottom:1px solid #eef2f7;background:#fafafa;font-weight:700;">Columna</td><td style="padding:7px;border-bottom:1px solid #eef2f7;background:#fafafa;font-weight:700;">Antes</td><td style="padding:7px;border-bottom:1px solid #eef2f7;background:#fafafa;font-weight:700;">Actual</td></tr>';
                    foreach (array_slice($r['column_change_samples'], 0, 8) as $ch) {
                        $detailHtml .= '<tr>';
                        $detailHtml .= '<td style="padding:7px;border-bottom:1px solid #eef2f7;">' . h((string)($ch['rut'] ?? '-')) . '</td>';
                        $detailHtml .= '<td style="padding:7px;border-bottom:1px solid #eef2f7;">' . h((string)($ch['column'] ?? '-')) . '</td>';
                        $detailHtml .= '<td style="padding:7px;border-bottom:1px solid #eef2f7;">' . h((string)($ch['before'] ?? '')) . '</td>';
                        $detailHtml .= '<td style="padding:7px;border-bottom:1px solid #eef2f7;">' . h((string)($ch['after'] ?? '')) . '</td>';
                        $detailHtml .= '</tr>';
                    }
                    $detailHtml .= '</table>';
                }

                $detailHtml .= '</div>';
                continue;
            }

            if (($f['tipo'] ?? '') === 'buk_empleados') {
                $buk = (array)($r['buk'] ?? []);
                $detailHtml .= '<table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:8px;">';
                $rowsBukEmp = [
                    'RUT solicitados' => (string)($r['requested'] ?? 0),
                    'RUT OK' => (string)($r['done'] ?? 0),
                    'RUT FAIL' => (string)($r['failed'] ?? 0),
                    'RUT JOB SKIP' => (string)($r['skipped_mapping'] ?? 0),
                    'BUK EMP OK' => (string)($buk['empleados_ok'] ?? 0),
                    'BUK EMP ERROR' => (string)($buk['empleados_error'] ?? 0),
                    'BUK PLAN OK' => (string)($buk['plans_ok'] ?? 0),
                    'BUK PLAN ERROR' => (string)($buk['plans_error'] ?? 0),
                    'BUK JOB OK' => (string)($buk['jobs_ok'] ?? 0),
                    'BUK JOB ERROR' => (string)($buk['jobs_error'] ?? 0),
                ];
                $detailHtml .= report_table_rows_html($rowsBukEmp, 280);
                $detailHtml .= '</table>';

                if (!empty($r['message'])) {
                    $detailHtml .= '<div style="margin-top:10px;font-size:14px;"><strong>Detalle:</strong> ' . h((string)$r['message']) . '</div>';
                }
                if (!empty($r['altas_buk_error_ruts'])) {
                    $detailHtml .= '<div style="margin-top:10px;font-size:14px;"><strong>RUT con error:</strong><br>' . h(render_list_inline((array)$r['altas_buk_error_ruts'], 30)) . '</div>';
                }
                if (!empty($r['failed_items']) && is_array($r['failed_items'])) {
                    $detailHtml .= '<div style="margin-top:10px;font-size:14px;"><strong>Fallos BUK:</strong>' . render_failed_items_html(array_slice($r['failed_items'], 0, 25)) . '</div>';
                }

                $detailHtml .= '</div>';
                continue;
            }

            if (($f['tipo'] ?? '') === 'buk_bajas') {
                $totalBukBajas =
                    (int)($r['requested'] ?? 0) +
                    (int)($r['done'] ?? 0) +
                    (int)($r['failed'] ?? 0);

                if ($totalBukBajas === 0 && strtolower((string)($f['status'] ?? '')) !== 'error') {
                    $detailHtml .= '<div style="margin-top:10px;padding:10px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;font-size:14px;font-weight:600;">Sin cambios</div>';
                    $detailHtml .= '</div>';
                    continue;
                }

                $detailHtml .= '<table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:8px;">';
                $rowsBukBajas = [
                    'RUT solicitados' => (string)($r['requested'] ?? 0),
                    'RUT OK' => (string)($r['done'] ?? 0),
                    'RUT FAIL' => (string)($r['failed'] ?? 0),
                ];
                $detailHtml .= report_table_rows_html($rowsBukBajas, 280);
                $detailHtml .= '</table>';

                if (!empty($r['bajas_buk_error_ruts'])) {
                    $detailHtml .= '<div style="margin-top:10px;font-size:14px;"><strong>RUT con error:</strong><br>' . h(render_list_inline((array)$r['bajas_buk_error_ruts'], 30)) . '</div>';
                }
                if (!empty($r['failed_items']) && is_array($r['failed_items'])) {
                    $detailHtml .= '<div style="margin-top:10px;font-size:14px;"><strong>Fallos BUK:</strong>' . render_failed_items_html(array_slice($r['failed_items'], 0, 25)) . '</div>';
                }

                $detailHtml .= '</div>';
                continue;
            }

            if (($f['tipo'] ?? '') === 'vacaciones') {
                $vacRequested = (int)($r['requested'] ?? 0);
                $vacDone = (int)($r['done'] ?? 0);
                $vacFailed = (int)($r['failed'] ?? 0);
                $vacMessage = trim((string)($r['message'] ?? ''));

                if (stripos($vacMessage, 'no encontrado') !== false) {
                    $detailHtml .= '<div style="margin-top:10px;padding:10px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;font-size:14px;font-weight:600;">Archivo no encontrado</div>';
                    $detailHtml .= '</div>';
                    continue;
                }

                $detailHtml .= '<table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:8px;">';
                $rowsVacaciones = [
                    'Detectados' => (string)$vacRequested,
                    'Enviados OK' => (string)$vacDone,
                    'Error' => (string)$vacFailed,
                    'Sin buk_emp_id' => (string)($r['missing_buk'] ?? 0),
                    'No encontrados BD' => (string)($r['not_found_bd'] ?? 0),
                ];
                $detailHtml .= report_table_rows_html($rowsVacaciones, 280);
                $detailHtml .= '</table>';

                if (!empty($r['failed_items']) && is_array($r['failed_items'])) {
                    $detailHtml .= '<div style="margin-top:12px;font-size:14px;"><strong>Detalle de errores:</strong>' . render_error_items_html(array_slice($r['failed_items'], 0, 20)) . '</div>';
                }

                $detailHtml .= '</div>';
                continue;
            }

            $detailHtml .= employee_metric_lines_html([
                [
                    'icon' => '🟢',
                    'label' => 'Altas nuevas',
                    'value' => $altasNuevas,
                    'color' => '#166534',
                ],
                [
                    'icon' => '🔄',
                    'label' => 'Reingresos',
                    'value' => $reingresos,
                    'color' => '#1d4ed8',
                ],
                [
                    'icon' => '📤',
                    'label' => 'Bajas detectadas',
                    'value' => $bajas,
                    'color' => '#b45309',
                ],
                [
                    'icon' => '⚠️',
                    'label' => 'Errores',
                    'value' => $errores,
                    'color' => '#991b1b',
                ],
            ]);

            if (!empty($r['altas_ruts'])) {
                $detailHtml .= '<div style="margin-top:12px;font-size:14px;"><strong>RUT altas nuevas:</strong><br>' . h(render_list_inline($r['altas_ruts'])) . '</div>';
            }

            if (!empty($r['altas_reingreso_ruts'])) {
                $detailHtml .= '<div style="margin-top:12px;font-size:14px;"><strong>RUT reingresos:</strong><br>' . h(render_list_inline($r['altas_reingreso_ruts'])) . '</div>';
            }

            if (!empty($r['bajas_detectadas_ruts'])) {
                $detailHtml .= '<div style="margin-top:12px;font-size:14px;"><strong>RUT bajas detectadas:</strong><br>' . h(render_list_inline($r['bajas_detectadas_ruts'])) . '</div>';
            }

            if (!empty($r['errores_ruts'])) {
                $detailHtml .= '<div style="margin-top:12px;font-size:14px;"><strong>Detalle de errores:</strong>' . render_error_items_html($r['errores_ruts']) . '</div>';
            }

            $detailHtml .= '</div>';
        }
    }

    $statusErrorHtml = '';
    if (!empty($event['errors'])) {
        $statusErrorHtml = '<div style="margin-top:8px;font-size:13px;"><strong>Error:</strong> ' . h((string)$event['errors']) . '</div>';
    }

    $jerarquiaMessageHtml = '';
    if ($jerarquiaMsg !== null) {
        $jerarquiaMessageHtml = '<div style="margin-bottom:10px;padding:10px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;font-size:14px;font-weight:600;">' . h($jerarquiaMsg) . '</div>';
    }

    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8">';
    $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    $html .= '<title>Resumen Sync STISOFT</title></head>';
    $html .= '<body style="margin:0;padding:20px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">';
    $html .= '<div style="max-width:820px;margin:0 auto;">';
    $html .= '<div style="margin-bottom:12px;font-size:12px;color:#6b7280;text-align:center;">STISOFT · Reporte automático</div>';
    $html .= '<div style="background:#ffffff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;">';
    $html .= '<div style="background:#111827;color:#ffffff;padding:24px 28px;">';
    $html .= '<div style="font-size:12px;color:#cbd5e1;letter-spacing:.3px;margin-bottom:6px;">STISOFT · SYNC</div>';
    $html .= '<div style="font-size:28px;font-weight:700;">Resumen de sincronización</div>';
    $html .= '<div style="margin-top:8px;font-size:14px;color:#d1d5db;">Ejecución: ' . h($runDate) . '</div>';
    $html .= '</div><div style="padding:24px 28px;">';
    $html .= '<div style="background:' . $msg['bg'] . ';color:' . $msg['fg'] . ';border-radius:12px;padding:16px 18px;margin-bottom:20px;">';
    $html .= '<div style="font-size:18px;font-weight:700;">' . h($msg['title']) . '</div>';
    $html .= '<div style="margin-top:4px;font-size:14px;">' . h($msg['desc']) . '</div>';
    $html .= $statusErrorHtml;
    $html .= '</div>';
    $html .= '<div style="margin-bottom:24px;"><div style="font-size:18px;font-weight:700;margin-bottom:10px;">Resumen</div>';
    $html .= $jerarquiaMessageHtml;
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">' . report_table_rows_html($rows, 260) . '</table></div>';
    $html .= '<div style="margin-bottom:24px;"><div style="font-size:18px;font-weight:700;margin-bottom:10px;">Etapas</div>';
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">' . report_table_rows_html($pipelineRows, 300) . '</table></div>';
    $html .= '<div style="font-size:18px;font-weight:700;margin-bottom:12px;">Detalle</div>';
    $html .= $detailHtml;
    $html .= '</div></div>';
    $html .= '<div style="margin-top:16px;font-size:12px;color:#6b7280;text-align:center;">Generado el ' . h($runDate) . ' · Estado ' . h(strtoupper((string)($event['status'] ?? 'unknown'))) . '</div>';
    $html .= '</div></body></html>';

    return $html;
}

function send_report_email(array $toAddresses, string $htmlContent, string $subject, ?array $smtpConfig = null): bool
{
    $smtpConfig = $smtpConfig ?? [];

    $fromEmail = $smtpConfig['from_email'] ?? $smtpConfig['from_address'] ?? $smtpConfig['username'] ?? 'no-reply@localhost';
    $fromName  = $smtpConfig['from_name'] ?? 'STISOFT Sync';

    $vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($vendorAutoload)) {
        require_once $vendorAutoload;

        if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

                $useSmtp = !empty($smtpConfig['host']);
                if ($useSmtp) {
                    $mail->isSMTP();
                    $mail->Host          = $smtpConfig['host'];
                    $mail->Port          = (int)($smtpConfig['port'] ?? 587);
                    $mail->SMTPAuth      = true;
                    $mail->Username      = $smtpConfig['username'] ?? '';
                    $mail->Password      = $smtpConfig['password'] ?? '';
                    $mail->Timeout       = 20;
                    $mail->SMTPKeepAlive = false;
                    $mail->SMTPAutoTLS   = true;

                    $secure = strtolower((string)($smtpConfig['secure'] ?? 'tls'));
                    if ($secure === 'ssl') {
                        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                    } else {
                        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    }
                }

                $mail->CharSet = 'UTF-8';
                $mail->setFrom($fromEmail, $fromName);

                foreach ($toAddresses as $addr) {
                    $addr = trim((string)$addr);
                    if ($addr !== '') {
                        $mail->addAddress($addr);
                    }
                }

                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $htmlContent;
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlContent));

                return $mail->send();
            } catch (\Throwable $e) {
                error_log('STISOFT MAIL ERROR: ' . $e->getMessage());
                return false;
            }
        }
    }

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=UTF-8';
    $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';

    return mail(implode(',', $toAddresses), $subject, $htmlContent, implode("\r\n", $headers));
}

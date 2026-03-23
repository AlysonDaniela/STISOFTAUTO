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

function render_list_inline(array $items, int $max = 20): string
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

    $html = '<ul style="margin:8px 0 0 18px; padding:0;">';
    foreach ($items as $e) {
        $rut = h($e['rut'] ?? '');
        $linea = h((string)($e['linea'] ?? ''));
        $error = h($e['error'] ?? 'Error no especificado');
        $html .= "<li style=\"margin-bottom:4px;\">línea {$linea} / RUT {$rut} / {$error}</li>";
    }
    $html .= '</ul>';

    return $html;
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

    return '<span style="display:inline-block;padding:5px 12px;border-radius:999px;background:' . $bg . ';color:' . $fg . ';font-size:12px;font-weight:700;">' . h(strtoupper($status)) . '</span>';
}

function count_by_tipo(array $files, string $tipo): int
{
    $n = 0;
    foreach ($files as $f) {
        if (($f['tipo'] ?? '') === $tipo) {
            $n++;
        }
    }
    return $n;
}

function metric_card(string $title, string $value, string $bg = '#f9fafb', string $fg = '#111827'): string
{
    return '
    <div style="background:' . $bg . ';border:1px solid #e5e7eb;border-radius:14px;padding:16px;min-width:160px;flex:1;">
        <div style="font-size:12px;color:#6b7280;margin-bottom:6px;">' . h($title) . '</div>
        <div style="font-size:24px;font-weight:700;color:' . $fg . ';">' . h($value) . '</div>
    </div>';
}

function process_message(array $event, array $processedStats): array
{
    $status = strtolower((string)($event['status'] ?? 'unknown'));
    $errores = (int)($processedStats['global']['errores_total'] ?? 0);
    $unsupported = (int)($processedStats['global']['files_unsupported'] ?? 0);

    if ($status === 'ok' && $errores === 0 && $unsupported === 0) {
        return [
            'title' => '✅ Proceso completado sin errores',
            'desc'  => 'La sincronización terminó correctamente y no se detectaron errores en el procesamiento.',
            'bg'    => '#dcfce7',
            'fg'    => '#166534',
        ];
    }

    if ($status === 'ok' && ($errores > 0 || $unsupported > 0)) {
        return [
            'title' => '⚠️ Proceso completado con observaciones',
            'desc'  => 'La sincronización terminó, pero hubo errores o archivos con incidencias que requieren revisión.',
            'bg'    => '#fef3c7',
            'fg'    => '#92400e',
        ];
    }

    return [
        'title' => '❌ Proceso con fallas',
        'desc'  => 'La sincronización no terminó correctamente. Revisa el detalle del error.',
        'bg'    => '#fee2e2',
        'fg'    => '#991b1b',
    ];
}

function build_html_report(string $runDate, array $event, array $processedStats, array $history = []): string
{
    $g = $processedStats['global'] ?? [];
    $files = $processedStats['files'] ?? [];

    $empleadosCount = count_by_tipo($files, 'empleados');
    $bajasCount     = count_by_tipo($files, 'bajas');
    $divisionCount  = count_by_tipo($files, 'division');
    $cargosCount    = count_by_tipo($files, 'cargos');

    $msg = process_message($event, $processedStats);

    $html = '';
    $html .= '<!doctype html><html lang="es"><head><meta charset="utf-8">';
    $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    $html .= '<title>Reporte Sync STISOFT</title></head>';
    $html .= '<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">';

    $html .= '<div style="max-width:1100px;margin:0 auto;background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #e5e7eb;">';

    // Header
    $html .= '<div style="background:linear-gradient(135deg,#111827,#1f2937);color:#ffffff;padding:28px 32px;">';
    $html .= '<div style="font-size:13px;letter-spacing:.4px;color:#cbd5e1;margin-bottom:6px;">STISOFT · SINCRONIZACIÓN ADP</div>';
    $html .= '<h1 style="margin:0;font-size:28px;">📡 Sync ADP → STISOFT</h1>';
    $html .= '<div style="margin-top:8px;font-size:14px;color:#d1d5db;">Lectura SFTP + procesamiento interno en base de datos</div>';
    $html .= '<div style="margin-top:10px;font-size:14px;color:#d1d5db;">📅 Fecha ejecución: ' . h($runDate) . '</div>';
    $html .= '<div style="margin-top:14px;">' . status_badge((string)($event['status'] ?? 'unknown')) . '</div>';
    $html .= '</div>';

    $html .= '<div style="padding:28px 32px;">';

    // Estado visual principal
    $html .= '<div style="background:' . $msg['bg'] . ';color:' . $msg['fg'] . ';border-radius:16px;padding:18px 20px;border:1px solid #e5e7eb;margin-bottom:24px;">';
    $html .= '<div style="font-size:20px;font-weight:700;">' . h($msg['title']) . '</div>';
    $html .= '<div style="margin-top:6px;font-size:14px;">' . h($msg['desc']) . '</div>';
    if (!empty($event['errors'])) {
        $html .= '<div style="margin-top:10px;font-size:13px;"><strong>Error:</strong> ' . h((string)$event['errors']) . '</div>';
    }
    $html .= '</div>';

    // KPIs
    $html .= '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:28px;">';
    $html .= metric_card('📁 Archivos detectados', (string)($g['files_detected'] ?? 0), '#eef2ff', '#3730a3');
    $html .= metric_card('✅ Archivos procesados', (string)($g['files_processed'] ?? 0), '#ecfdf5', '#166534');
    $html .= metric_card('⚠️ Errores totales', (string)($g['errores_total'] ?? 0), '#fef2f2', '#991b1b');
    $html .= metric_card('⏱️ Duración', (string)($event['duration'] ?? '-'), '#f9fafb', '#111827');
    $html .= '</div>';

    // Resumen general
    $html .= '<h2 style="margin:0 0 14px 0;font-size:20px;">🧾 Resumen general</h2>';
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:28px;">';
    $rows = [
        'Modo'                   => $event['mode'] ?? '-',
        'Mensaje'                => $event['message'] ?? '-',
        'Archivos detectados'    => (string)($g['files_detected'] ?? 0),
        'Archivos procesados'    => (string)($g['files_processed'] ?? 0),
        'Archivos omitidos'      => (string)($g['files_skipped'] ?? 0),
        'Archivos no soportados' => (string)($g['files_unsupported'] ?? 0),
        'Altas totales'          => (string)($g['altas_total'] ?? 0),
        'Errores totales'        => (string)($g['errores_total'] ?? 0),
    ];
    foreach ($rows as $k => $v) {
        $html .= '<tr>';
        $html .= '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;font-weight:700;width:280px;background:#fafafa;">' . h($k) . '</td>';
        $html .= '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;">' . h((string)$v) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';

    // Desglose por tipo
    $html .= '<h2 style="margin:0 0 14px 0;font-size:20px;">📦 Desglose por tipo de archivo</h2>';
    $html .= '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:28px;">';
    $html .= metric_card('👥 Empleados', (string)$empleadosCount, '#eff6ff', '#1d4ed8');
    $html .= metric_card('❌ Bajas', (string)$bajasCount, '#fff1f2', '#be123c');
    $html .= metric_card('🏢 División', (string)$divisionCount, '#f5f3ff', '#6d28d9');
    $html .= metric_card('💼 Cargos', (string)$cargosCount, '#fffbeb', '#b45309');
    $html .= '</div>';

    // Archivos leídos
    $html .= '<h2 style="margin:0 0 14px 0;font-size:20px;">📂 Archivos leídos</h2>';

    if (empty($files)) {
        $html .= '<div style="padding:14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;color:#6b7280;margin-bottom:28px;">No hay archivos registrados en esta ejecución.</div>';
    } else {
        $html .= '<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:28px;">';
        $html .= '<tr style="background:#f9fafb;">';
        $html .= '<th style="text-align:left;padding:10px;border-bottom:1px solid #e5e7eb;">Archivo</th>';
        $html .= '<th style="text-align:left;padding:10px;border-bottom:1px solid #e5e7eb;">Ruta remota</th>';
        $html .= '<th style="text-align:left;padding:10px;border-bottom:1px solid #e5e7eb;">Tipo</th>';
        $html .= '<th style="text-align:left;padding:10px;border-bottom:1px solid #e5e7eb;">Estado</th>';
        $html .= '</tr>';

        foreach ($files as $f) {
            $html .= '<tr>';
            $html .= '<td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:600;">' . h($f['file'] ?? '-') . '</td>';
            $html .= '<td style="padding:10px;border-bottom:1px solid #e5e7eb;">' . h($f['remote_file'] ?? '-') . '</td>';
            $html .= '<td style="padding:10px;border-bottom:1px solid #e5e7eb;">' . h($f['tipo'] ?? '-') . '</td>';
            $html .= '<td style="padding:10px;border-bottom:1px solid #e5e7eb;">' . status_badge((string)($f['status'] ?? 'unknown')) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
    }

    // Detalle empleados
    $html .= '<h2 style="margin:0 0 14px 0;font-size:20px;">👥 Detalle archivos de empleados</h2>';

    $employeeFiles = array_values(array_filter($files, function ($f) {
        return (($f['tipo'] ?? '') === 'empleados');
    }));

    if (empty($employeeFiles)) {
        $html .= '<div style="padding:14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;color:#6b7280;">No hubo archivos de empleados en esta ejecución.</div>';
    } else {
        foreach ($employeeFiles as $f) {
            $r = $f['result'] ?? [];

            $altasNuevas   = (int)($r['altas_nuevas'] ?? $r['insertados'] ?? 0);
            $reingresos    = (int)($r['altas_reingreso'] ?? $r['actualizados'] ?? 0);
            $bajas         = (int)($r['bajas_detectadas'] ?? 0);
            $ignMismo      = (int)($r['ignorados_mismo_estado'] ?? 0);
            $ignNoAct      = (int)($r['ignorados_no_activos_nuevos'] ?? 0);
            $errores       = (int)($r['errores'] ?? 0);

            $html .= '<div style="margin-bottom:20px;padding:20px;border:1px solid #e5e7eb;border-radius:16px;background:#fcfcfd;">';
            $html .= '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">';
            $html .= '<div>';
            $html .= '<div style="font-size:18px;font-weight:700;">' . h($f['file'] ?? '-') . '</div>';
            $html .= '<div style="margin-top:6px;color:#6b7280;font-size:13px;">📍 Ruta remota: ' . h($f['remote_file'] ?? '-') . '</div>';
            $html .= '<div style="margin-top:4px;color:#6b7280;font-size:13px;">🏷️ Origen: ' . h($r['origen'] ?? '-') . '</div>';
            $html .= '</div>';
            $html .= '<div>' . status_badge((string)($f['status'] ?? 'unknown')) . '</div>';
            $html .= '</div>';

            if (($r['status'] ?? '') === 'unsupported') {
                $html .= '<div style="margin-top:14px;color:#92400e;"><strong>Motivo:</strong> ' . h($r['message'] ?? 'Archivo no soportado') . '</div>';
                $html .= '</div>';
                continue;
            }

            if (($r['status'] ?? '') === 'error') {
                $html .= '<div style="margin-top:14px;color:#991b1b;"><strong>Error:</strong> ' . h($r['message'] ?? 'Error no especificado') . '</div>';
                if (!empty($f['raw_output'])) {
                    $html .= '<div style="margin-top:10px;padding:10px;background:#f9fafb;border-radius:8px;font-size:12px;white-space:pre-wrap;">' . h($f['raw_output']) . '</div>';
                }
                $html .= '</div>';
                continue;
            }

            $html .= '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;margin-bottom:16px;">';
            $html .= metric_card('🟢 Altas nuevas', (string)$altasNuevas, '#ecfdf5', '#166534');
            $html .= metric_card('🔄 Reingresos', (string)$reingresos, '#eff6ff', '#1d4ed8');
            $html .= metric_card('🔻 Bajas detectadas', (string)$bajas, '#fff1f2', '#be123c');
            $html .= metric_card('⏭️ Ignorados mismo estado', (string)$ignMismo, '#f9fafb', '#374151');
            $html .= metric_card('⚪ Nuevos no activos ignorados', (string)$ignNoAct, '#f9fafb', '#6b7280');
            $html .= metric_card('⚠️ Errores', (string)$errores, '#fef2f2', '#991b1b');
            $html .= '</div>';

            if (!empty($r['altas_ruts'])) {
                $html .= '<div style="margin-top:14px;"><strong>🟢 RUT altas nuevas:</strong><br><span style="color:#374151;">' . h(render_list_inline($r['altas_ruts'])) . '</span></div>';
            }

            if (!empty($r['altas_reingreso_ruts'])) {
                $html .= '<div style="margin-top:14px;"><strong>🔄 RUT reingresos:</strong><br><span style="color:#374151;">' . h(render_list_inline($r['altas_reingreso_ruts'])) . '</span></div>';
            }

            if (!empty($r['bajas_detectadas_ruts'])) {
                $html .= '<div style="margin-top:14px;"><strong>🔻 RUT bajas detectadas:</strong><br><span style="color:#374151;">' . h(render_list_inline($r['bajas_detectadas_ruts'])) . '</span></div>';
            }

            if (!empty($r['errores_ruts'])) {
                $html .= '<div style="margin-top:14px;"><strong>⚠️ Errores detalle:</strong>' . render_error_items_html($r['errores_ruts']) . '</div>';
            }

            $html .= '</div>';
        }
    }

    $html .= '</div></div></body></html>';

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
                    $mail->Host         = $smtpConfig['host'];
                    $mail->Port         = (int)($smtpConfig['port'] ?? 587);
                    $mail->SMTPAuth     = true;
                    $mail->Username     = $smtpConfig['username'] ?? '';
                    $mail->Password     = $smtpConfig['password'] ?? '';
                    $mail->Timeout      = 20;
                    $mail->SMTPKeepAlive = false;
                    $mail->SMTPAutoTLS  = true;

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

<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_admin();

ob_start();

foreach ([
    __DIR__ . '/../includes/bootstrap.php',
    __DIR__ . '/../includes/init.php',
    __DIR__ . '/../partials/bootstrap.php',
    __DIR__ . '/../app/bootstrap.php',
] as $b) {
    if (is_file($b)) {
        require_once $b;
        break;
    }
}

function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function read_json_file(string $file): ?array {
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function status_badge_class(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'ok') return 'bg-emerald-50 text-emerald-700 border border-emerald-200';
    if ($s === 'error') return 'bg-red-50 text-red-700 border border-red-200';
    return 'bg-gray-50 text-gray-700 border border-gray-200';
}

function safe_rel_path(string $rel): string {
    $rel = str_replace('\\', '/', trim($rel));
    if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) return '';
    return ltrim($rel, '/');
}

function resolve_under_base(string $base, string $rel): ?string {
    $baseReal = realpath($base);
    if ($baseReal === false) return null;
    $target = realpath($baseReal . '/' . $rel);
    if ($target === false || !is_file($target)) return null;
    if (strpos($target, $baseReal . DIRECTORY_SEPARATOR) !== 0) return null;
    return $target;
}

$csrf = csrf_token();
$msg = null;
$msgType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate($_POST['csrf_token'] ?? '')) {
    $msg = 'La sesión de seguridad expiró. Recarga la pantalla e inténtalo nuevamente.';
}

function normalize_stage_label(string $stage): string {
    $s = strtoupper(trim($stage));
    if ($s === 'EMP' || $s === 'EMP_ATTR') return 'Ficha';
    if ($s === 'PLAN') return 'Plan';
    if ($s === 'JOB' || $s === 'LEADER' || $s === 'JOB_SKIP') return 'Job';
    return $s !== '' ? $s : '-';
}

function render_visual_report_html(array $payload, string $title, bool $autoPrint = false): string {
    $event = is_array($payload['event'] ?? null) ? $payload['event'] : [];
    $stats = is_array($payload['processed_stats'] ?? null) ? $payload['processed_stats'] : [];
    $global = is_array($stats['global'] ?? null) ? $stats['global'] : [];
    $files = is_array($stats['files'] ?? null) ? $stats['files'] : [];

    $esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    $html .= '<title>' . $esc($title) . '</title>';
    $html .= '<style>
      body{font-family:Arial,sans-serif;background:#f4f6f8;color:#111;margin:0}
      .wrap{max-width:1200px;margin:0 auto;padding:18px}
      .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin-bottom:12px}
      .h1{font-size:20px;font-weight:700;margin:0 0 8px}
      .h2{font-size:15px;font-weight:700;margin:8px 0}
      .muted{color:#555;font-size:12px}
      .grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
      .grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
      .k{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:8px;font-size:13px}
      .k-ok{background:#ecfdf5}.k-err{background:#fef2f2}.k-warn{background:#fffbeb}
      .tag{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #ddd;font-size:11px}
      .tag-ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
      .tag-error{background:#fef2f2;border-color:#fecaca;color:#991b1b}
      .tag-unknown{background:#f3f4f6;border-color:#d1d5db;color:#374151}
      .box{border:1px solid #e5e7eb;border-radius:8px;padding:8px;margin-top:6px;font-size:12px}
      .box-ok{background:#ecfdf5}.box-err{background:#fef2f2}.box-warn{background:#fffbeb}
      .list{margin:6px 0 0 18px;padding:0} .list li{margin:2px 0}
      code{background:#f3f4f6;border-radius:4px;padding:1px 4px}
      .card,.k,.box,code,pre{overflow-wrap:anywhere;word-break:break-word}
      pre{white-space:pre-wrap;max-width:100%;overflow-x:auto}
      @media (max-width:1000px){.grid,.grid3{grid-template-columns:1fr 1fr}}
      @media (max-width:680px){.grid,.grid3{grid-template-columns:1fr}}
      @media print{body{background:#fff}.card{break-inside:avoid}}
    </style></head><body><div class="wrap">';

    $status = strtoupper((string)($event['status'] ?? 'UNKNOWN'));
    $tagClass = ($status === 'OK') ? 'tag-ok' : (($status === 'ERROR') ? 'tag-error' : 'tag-unknown');
    $html .= '<div class="card"><div class="h1">' . $esc($title) . '</div>';
    $html .= '<div class="muted">Generado: ' . $esc((string)($payload['generated_at'] ?? '-')) . '</div>';
    $html .= '<div style="margin-top:6px"><span class="tag ' . $tagClass . '">Estado: ' . $esc($status) . '</span></div>';
    $html .= '<div style="margin-top:8px;font-size:13px">' . $esc((string)($event['message'] ?? '-')) . '</div></div>';

    $html .= '<div class="card"><div class="grid">';
    $html .= '<div class="k">Detectados: <strong>' . (int)($global['files_detected'] ?? 0) . '</strong></div>';
    $html .= '<div class="k">Procesados: <strong>' . (int)($global['files_processed'] ?? 0) . '</strong></div>';
    $html .= '<div class="k">Omitidos: <strong>' . (int)($global['files_skipped'] ?? 0) . '</strong></div>';
    $html .= '<div class="k k-err">Errores: <strong>' . (int)($global['errores_total'] ?? 0) . '</strong></div>';
    $html .= '</div></div>';

    $render_changes = static function (array $changes) use ($esc): string {
        if (empty($changes)) {
            return '<div style="margin-top:4px;color:#6b7280">Sin detalle anterior/actual.</div>';
        }
        $out = '<ul class="list">';
        foreach ($changes as $ch) {
            $out .= '<li><strong>' . $esc((string)($ch['column'] ?? '-')) . ':</strong> '
                . '<code>' . $esc((string)($ch['before'] ?? '')) . '</code> → '
                . '<code>' . $esc((string)($ch['after'] ?? '')) . '</code></li>';
        }
        $out .= '</ul>';
        return $out;
    };

    foreach ($files as $f) {
        $name = (string)($f['file'] ?? '-');
        $tipo = (string)($f['tipo'] ?? '-');
        $r = is_array($f['result'] ?? null) ? $f['result'] : [];
        $st = strtoupper((string)($f['status'] ?? 'unknown'));
        $tag = ($st === 'OK') ? 'tag-ok' : (($st === 'ERROR') ? 'tag-error' : 'tag-unknown');

        $html .= '<div class="card">';
        $html .= '<div style="display:flex;justify-content:space-between;gap:8px;align-items:start">';
        $html .= '<div><strong>' . $esc($name) . '</strong><div class="muted">' . $esc((string)($f['script'] ?? '-')) . '</div></div>';
        $html .= '<span class="tag ' . $tag . '">' . $esc($st) . '</span></div>';
        if (!empty($r['message'])) {
            $html .= '<div style="font-size:13px;margin-top:8px"><strong>Mensaje:</strong> ' . $esc((string)$r['message']) . '</div>';
        }

        if ($tipo === 'cambios') {
            $dt = is_array($r['detail_totals'] ?? null) ? $r['detail_totals'] : [];
            $isDrain = (strtoupper($name) === 'PROCESS_CAMBIOS_DRAIN');
            $isEnqueue = (strtoupper((string)($r['mode'] ?? '')) === 'ENQUEUE_ONLY')
                || (strpos(strtoupper($name), 'PROCESS_CAMBIOS_ENQUEUE_') === 0);

            $html .= '<div class="grid" style="margin-top:8px">';
            $html .= '<div class="k">Detectados: <strong>' . (int)($dt['detected'] ?? ($r['requested'] ?? 0)) . '</strong></div>';
            $html .= '<div class="k">Encolados: <strong>' . (int)($dt['queued'] ?? ($r['queued'] ?? 0)) . '</strong></div>';
            $html .= '<div class="k k-ok">OK: <strong>' . (int)($dt['ok'] ?? ($r['sent_ok'] ?? 0)) . '</strong></div>';
            $html .= '<div class="k k-err">ERROR: <strong>' . (int)($dt['error'] ?? ($r['sent_error'] ?? 0)) . '</strong></div>';
            $html .= '<div class="k k-warn">Pendientes: <strong>' . (int)($dt['pending'] ?? ($r['queue_pending'] ?? 0)) . '</strong></div>';
            $html .= '<div class="k k-err">Errores cola: <strong>' . (int)($dt['queue_error'] ?? ($r['queue_error'] ?? 0)) . '</strong></div>';
            $html .= '<div class="k">SKIP: <strong>' . (int)($dt['skip'] ?? ($r['skip'] ?? 0)) . '</strong></div>';
            $html .= '<div class="k">Ignorados: <strong>' . (int)($dt['ignored_legacy'] ?? ($r['ignored'] ?? 0)) . '</strong></div>';
            $html .= '</div>';

            if ($isDrain) {
                $html .= '<div class="grid" style="margin-top:8px">';
                $html .= '<div class="k">Procesados cola: <strong>' . (int)($r['queue_processed'] ?? 0) . '</strong></div>';
                $html .= '<div class="k k-ok">RUT OK: <strong>' . count((array)($r['changed_ruts'] ?? [])) . '</strong></div>';
                $html .= '<div class="k k-err">RUT ERROR: <strong>' . count((array)($r['error_ruts'] ?? [])) . '</strong></div>';
                $html .= '<div class="k k-warn">Items ignorados: <strong>' . count((array)($r['ignored_items'] ?? [])) . '</strong></div>';
                $html .= '</div>';
            }

            if ($isEnqueue) {
                $html .= '<div class="grid" style="margin-top:8px">';
                $html .= '<div class="k">Modo: <strong>ENQUEUE_ONLY</strong></div>';
                $html .= '<div class="k">Detect errors: <strong>' . (int)($r['detect_errors'] ?? 0) . '</strong></div>';
                $html .= '<div class="k">Muestras cambios: <strong>' . count((array)($r['column_change_samples'] ?? [])) . '</strong></div>';
                $html .= '<div class="k">Columnas con cambio: <strong>' . count((array)($r['column_change_counts'] ?? [])) . '</strong></div>';
                $html .= '</div>';
            }

            $html .= '<div class="h2" style="color:#047857">OK</div>';
            $okItems = is_array($r['ok_items'] ?? null) ? $r['ok_items'] : [];
            if (!empty($okItems)) {
                foreach ($okItems as $ok) {
                    $html .= '<div class="box box-ok"><strong>RUT:</strong> ' . $esc((string)($ok['rut'] ?? '-'));
                    if (!empty($ok['stages']) && is_array($ok['stages'])) {
                        $html .= ' · <strong>Etapas:</strong> ' . $esc(implode(', ', $ok['stages']));
                    }
                    $html .= '<div style="margin-top:4px"><strong>Cambios (anterior → actual):</strong></div>';
                    $html .= $render_changes((array)($ok['changes'] ?? []));
                    $html .= '</div>';
                }
            } elseif (!empty($r['changed_ruts']) && is_array($r['changed_ruts'])) {
                $html .= '<div class="box box-ok">' . $esc(implode(', ', $r['changed_ruts'])) . '</div>';
            } else {
                $html .= '<div class="box">Sin detalles OK.</div>';
            }

            $html .= '<div class="h2" style="color:#b91c1c">ERROR</div>';
            $failed = is_array($r['failed_items'] ?? null) ? $r['failed_items'] : [];
            if (!empty($r['failure_summary']) && is_array($r['failure_summary'])) {
                $fs = $r['failure_summary'];
                if (!empty($fs['by_stage']) && is_array($fs['by_stage'])) {
                    $parts = [];
                    foreach ($fs['by_stage'] as $stage => $cnt) $parts[] = $stage . ': ' . (int)$cnt;
                    $html .= '<div class="box">' . $esc(implode(' · ', $parts)) . '</div>';
                }
                if (!empty($fs['top_messages']) && is_array($fs['top_messages'])) {
                    $parts = [];
                    foreach ($fs['top_messages'] as $tm) $parts[] = (int)($tm['count'] ?? 0) . 'x ' . (string)($tm['message'] ?? '');
                    $html .= '<div class="box">' . $esc(implode(' | ', $parts)) . '</div>';
                }
            }
            if (!empty($failed)) {
                foreach ($failed as $it) {
                    $html .= '<div class="box box-err"><strong>RUT:</strong> ' . $esc((string)($it['rut'] ?? '-'))
                        . ' · <strong>Etapa:</strong> ' . $esc((string)($it['stage'] ?? '-'))
                        . ' · <strong>HTTP:</strong> ' . (int)($it['http'] ?? 0)
                        . '<br><strong>Mensaje:</strong> ' . $esc((string)($it['msg'] ?? '-'));
                    $html .= '<div style="margin-top:4px"><strong>Cambios intentados (anterior → actual):</strong></div>';
                    $html .= $render_changes((array)($it['changes'] ?? []));
                    $html .= '</div>';
                }
            } else {
                $html .= '<div class="box">Sin errores.</div>';
            }

            $html .= '<div class="h2" style="color:#92400e">SKIP / IGNORADOS</div>';
            $html .= '<div class="box box-warn">SKIP: ' . (int)($r['skip'] ?? 0) . ' · Ignorados legado: ' . (int)($r['ignored'] ?? 0) . '</div>';
            $ignored = is_array($r['ignored_items'] ?? null) ? $r['ignored_items'] : [];
            foreach ($ignored as $ig) {
                $html .= '<div class="box box-warn"><strong>RUT:</strong> ' . $esc((string)($ig['rut'] ?? '-'))
                    . ' · ' . $esc((string)($ig['msg'] ?? ''));
                $html .= '<div style="margin-top:4px"><strong>Cambios detectados (anterior → actual):</strong></div>';
                $html .= $render_changes((array)($ig['changes'] ?? []));
                $html .= '</div>';
            }

            if ($isDrain) {
                if (!empty($r['changed_ruts']) && is_array($r['changed_ruts'])) {
            $html .= '<div class="h2" style="color:#047857">RUT OK (completo)</div><div class="box box-ok">' . $esc(implode(', ', $r['changed_ruts'])) . '</div>';
                }
                if (!empty($r['error_ruts']) && is_array($r['error_ruts'])) {
                    $html .= '<div class="h2" style="color:#b91c1c">RUT ERROR (completo)</div><div class="box box-err">' . $esc(implode(', ', $r['error_ruts'])) . '</div>';
                }
            }

            if ($isEnqueue && !empty($r['column_change_counts']) && is_array($r['column_change_counts'])) {
                $parts = [];
                foreach ((array)$r['column_change_counts'] as $col => $cnt) $parts[] = (string)$col . ': ' . (int)$cnt;
                $html .= '<div class="h2" style="color:#3730a3">Columnas con cambios</div><div class="box">' . $esc(implode(' · ', $parts)) . '</div>';
            }
            if ($isEnqueue && !empty($r['column_change_samples']) && is_array($r['column_change_samples'])) {
                $html .= '<div class="h2" style="color:#3730a3">Muestras de cambios (anterior → actual)</div>';
                foreach ((array)$r['column_change_samples'] as $smp) {
                    $html .= '<div class="box"><strong>RUT:</strong> ' . $esc((string)($smp['rut'] ?? '-'))
                        . ' · <strong>Columna:</strong> ' . $esc((string)($smp['column'] ?? '-'))
                        . '<br><strong>Antes:</strong> <code>' . $esc((string)($smp['before'] ?? '')) . '</code>'
                        . ' · <strong>Ahora:</strong> <code>' . $esc((string)($smp['after'] ?? '')) . '</code></div>';
                }
            }
        } elseif ($tipo === 'buk_empleados') {
            $buk = is_array($r['buk'] ?? null) ? $r['buk'] : [];
            $fichaOk = (int)($buk['empleados_ok'] ?? 0);
            $fichaErr = (int)($buk['empleados_error'] ?? 0);
            $planOk = (int)($buk['plans_ok'] ?? 0);
            $planErr = (int)($buk['plans_error'] ?? 0);
            $jobOk = (int)($buk['jobs_ok'] ?? 0);
            $jobErr = (int)($buk['jobs_error'] ?? 0);
            $jobSkip = (int)($r['skipped_mapping'] ?? 0);

            $html .= '<div class="grid3" style="margin-top:8px">';
            $html .= '<div class="k">Ficha<br>OK: <strong>' . $fichaOk . '</strong> · ERROR: <strong>' . $fichaErr . '</strong> · PENDIENTE: <strong>0</strong></div>';
            $html .= '<div class="k">Plan<br>OK: <strong>' . $planOk . '</strong> · ERROR: <strong>' . $planErr . '</strong> · PENDIENTE: <strong>0</strong></div>';
            $html .= '<div class="k">Job<br>OK: <strong>' . $jobOk . '</strong> · ERROR: <strong>' . $jobErr . '</strong> · SKIP: <strong>' . $jobSkip . '</strong> · PENDIENTE: <strong>0</strong></div>';
            $html .= '</div>';

            $failedItems = is_array($r['failed_items'] ?? null) ? $r['failed_items'] : [];
            $failedBy = ['Ficha' => [], 'Plan' => [], 'Job' => []];
            foreach ($failedItems as $it) {
                $grp = normalize_stage_label((string)($it['stage'] ?? ''));
                if (!isset($failedBy[$grp])) $failedBy[$grp] = [];
                $failedBy[$grp][] = $it;
            }
            $html .= '<div class="h2" style="color:#b91c1c">Errores por etapa</div>';
            foreach (['Ficha', 'Plan', 'Job'] as $stg) {
                $html .= '<div class="box box-err"><strong>' . $esc($stg) . '</strong> · ' . count((array)($failedBy[$stg] ?? []));
                foreach ((array)($failedBy[$stg] ?? []) as $it) {
                    $html .= '<div style="margin-top:4px"><strong>RUT:</strong> ' . $esc((string)($it['rut'] ?? '-'))
                        . ' · <strong>Etapa:</strong> ' . $esc((string)($it['stage'] ?? '-'))
                        . ' · <strong>HTTP:</strong> ' . (int)($it['http'] ?? 0)
                        . ' · <strong>Mensaje:</strong> ' . $esc((string)($it['msg'] ?? '-'))
                        . '<div style="color:#6b7280">Anterior/actual: no informado por process_buk_empleados.</div></div>';
                }
                if (empty($failedBy[$stg])) $html .= '<div style="margin-top:4px">Sin errores.</div>';
                $html .= '</div>';
            }

            $okRuts = is_array($r['altas_buk_ok_ruts'] ?? null) ? $r['altas_buk_ok_ruts'] : [];
            $errRuts = is_array($r['altas_buk_error_ruts'] ?? null) ? $r['altas_buk_error_ruts'] : [];
            $skipRuts = is_array($r['job_skip_ruts'] ?? null) ? $r['job_skip_ruts'] : [];
            $html .= '<div class="h2" style="color:#047857">RUT OK</div><div class="box box-ok">' . $esc(!empty($okRuts) ? implode(', ', $okRuts) : 'Sin RUT OK') . '</div>';
            $html .= '<div class="h2" style="color:#b91c1c">RUT ERROR</div><div class="box box-err">' . $esc(!empty($errRuts) ? implode(', ', $errRuts) : 'Sin RUT ERROR') . '</div>';
            $html .= '<div class="h2" style="color:#92400e">RUT JOB SKIP</div><div class="box box-warn">' . $esc(!empty($skipRuts) ? implode(', ', $skipRuts) : 'Sin JOB SKIP') . '</div>';
        }

        if (!empty($f['raw_output'])) {
            $html .= '<div class="h2">Salida técnica</div><div class="box"><pre style="margin:0">' . $esc((string)$f['raw_output']) . '</pre></div>';
        }

        $html .= '</div>';
    }

    if ($autoPrint) {
        $html .= '<script>window.onload=function(){window.print();}</script>';
    }
    $html .= '</div></body></html>';
    return $html;
}

$BASE = __DIR__;
$reportsBase = $BASE . '/storage/reports';

if ($msg === null && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_report'])) {
    $rel = safe_rel_path((string)($_POST['delete_report'] ?? ''));
    $target = $rel !== '' ? resolve_under_base($reportsBase, $rel) : null;
    if ($target === null) {
        $msg = 'No se encontró el reporte seleccionado.';
    } elseif (@unlink($target)) {
        $msg = 'Reporte eliminado correctamente.';
        $msgType = 'success';
    } else {
        $msg = 'No se pudo eliminar el reporte.';
    }
}

$download = safe_rel_path((string)($_GET['download'] ?? ''));
$viewHtml = safe_rel_path((string)($_GET['view_html'] ?? ''));
$downloadHtml = safe_rel_path((string)($_GET['download_html'] ?? ''));
$pdfView = safe_rel_path((string)($_GET['pdf'] ?? ''));

if ($download !== '') {
    $target = resolve_under_base($reportsBase, $download);
    if ($target === null) {
        http_response_code(404);
        exit('Reporte no encontrado.');
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($target) . '"');
    header('Content-Length: ' . (string)filesize($target));
    readfile($target);
    exit;
}

if ($viewHtml !== '' || $downloadHtml !== '' || $pdfView !== '') {
    $rel = $viewHtml !== '' ? $viewHtml : ($downloadHtml !== '' ? $downloadHtml : $pdfView);
    $target = resolve_under_base($reportsBase, $rel);
    if ($target === null) {
        http_response_code(404);
        exit('Reporte no encontrado.');
    }
    $payload = read_json_file($target) ?: [];
    $title = 'Reporte Visual Sync - ' . basename($target, '.json');
    $html = render_visual_report_html($payload, $title, $pdfView !== '');

    if ($downloadHtml !== '') {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($target, '.json') . '_visual.html"');
        echo $html;
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

$reports = [];
if (is_dir($reportsBase)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($reportsBase, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        if (strtolower($file->getExtension()) !== 'json') continue;

        $full = $file->getPathname();
        $rel = ltrim(str_replace($reportsBase, '', $full), '/');
        $json = read_json_file($full) ?: [];
        $event = is_array($json['event'] ?? null) ? $json['event'] : [];
        $stats = is_array($json['processed_stats']['global'] ?? null) ? $json['processed_stats']['global'] : [];

        $reports[] = [
            'full' => $full,
            'rel' => $rel,
            'name' => basename($full),
            'year_month' => substr($rel, 0, 7),
            'generated_at' => (string)($json['generated_at'] ?? ''),
            'status' => (string)($event['status'] ?? 'unknown'),
            'message' => (string)($event['message'] ?? ''),
            'duration' => (string)($event['duration'] ?? ''),
            'files_detected' => (int)($stats['files_detected'] ?? 0),
            'files_processed' => (int)($stats['files_processed'] ?? 0),
            'errors_total' => (int)($stats['errores_total'] ?? 0),
            'mtime' => (int)$file->getMTime(),
        ];
    }
}

usort($reports, static function (array $a, array $b): int {
    return $b['mtime'] <=> $a['mtime'];
});

$grouped = [];
foreach ($reports as $r) {
    $k = preg_match('/^\d{4}\/\d{2}$/', $r['year_month']) ? $r['year_month'] : 'otros';
    if (!isset($grouped[$k])) $grouped[$k] = [];
    $grouped[$k][] = $r;
}

require_once __DIR__ . '/../partials/head.php';
?>
<div class="min-h-screen grid grid-cols-12">
  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php require_once __DIR__ . '/../partials/sidebar.php'; ?>
  </div>
  <main class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col min-h-screen">
    <?php require_once __DIR__ . '/../partials/topbar.php'; ?>

    <div class="max-w-7xl mx-auto w-full px-4 py-6 space-y-6">
      <section class="rounded-3xl border border-slate-200 bg-gradient-to-r from-slate-900 via-cyan-900 to-sky-800 text-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div class="text-xs uppercase tracking-[0.18em] text-cyan-100/80">Sync general</div>
            <h1 class="text-2xl md:text-3xl font-semibold mt-2 flex items-center gap-3">
              <i class="fa-regular fa-file-lines"></i>
              Reportes Sync
            </h1>
            <p class="text-sm text-cyan-50/90 mt-3 max-w-3xl">Consulta el historial de reportes guardados, revisa el resumen de cada corrida y descarga la versión JSON o visual cuando la necesites.</p>
          </div>
          <a href="/sync/sync_detalles.php" class="inline-flex items-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-900 hover:bg-slate-100 transition">
            <i class="fa-solid fa-chart-line text-cyan-700"></i>
            Volver a Sync Detalles
          </a>
        </div>
      </section>

      <?php if ($msg): ?>
        <div class="p-4 rounded-2xl <?= $msgType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
          <?= e($msg) ?>
        </div>
      <?php endif; ?>

      <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-200">
        <div class="grid md:grid-cols-2 gap-4 text-sm">
          <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
            <div class="text-slate-500 text-xs uppercase tracking-wide">Carpeta base</div>
            <div class="mt-2 text-slate-800 break-all"><code><?= e($reportsBase) ?></code></div>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
            <div class="text-slate-500 text-xs uppercase tracking-wide">Total reportes</div>
            <div class="mt-2 text-2xl font-semibold text-slate-900"><?= e((string)count($reports)) ?></div>
          </div>
        </div>
      </div>

      <?php if (empty($grouped)): ?>
        <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-200">
          <p class="text-sm text-gray-500">Aún no hay reportes guardados.</p>
        </div>
      <?php else: ?>
        <?php foreach ($grouped as $month => $rows): ?>
          <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-200">
            <h2 class="text-lg font-semibold text-slate-900 mb-4">
              <?= e($month) ?> · <?= e((string)count($rows)) ?> reporte(s)
            </h2>

            <div class="space-y-3">
              <?php foreach ($rows as $r): ?>
                <?php $badge = status_badge_class((string)$r['status']); ?>
                <div class="border border-slate-200 rounded-2xl p-4 bg-slate-50/60">
                  <div class="flex items-center justify-between gap-3">
                    <div>
                      <div class="font-medium text-slate-900"><?= e($r['name']) ?></div>
                      <div class="text-xs text-slate-500 mt-1"><?= e($r['generated_at'] !== '' ? $r['generated_at'] : date('Y-m-d H:i:s', (int)$r['mtime'])) ?></div>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                      <span class="text-xs px-2 py-1 rounded-lg <?= e($badge) ?>"><?= e(strtoupper((string)$r['status'])) ?></span>
                      <a href="?download=<?= urlencode((string)$r['rel']) ?>" class="px-3 py-2 rounded-xl bg-cyan-700 text-white text-xs font-semibold hover:bg-cyan-800 transition">
                        Descargar JSON
                      </a>
                      <a href="?view_html=<?= urlencode((string)$r['rel']) ?>" target="_blank" class="px-3 py-2 rounded-xl bg-slate-800 text-white text-xs font-semibold hover:bg-black transition">
                        Ver HTML
                      </a>
                      <form method="post" onsubmit="return confirm('¿Eliminar este reporte sync?');">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="delete_report" value="<?= e((string)$r['rel']) ?>">
                        <button class="px-3 py-2 rounded-xl bg-red-600 text-white text-xs font-semibold hover:bg-red-700 transition" type="submit">
                          Borrar
                        </button>
                      </form>
                    </div>
                  </div>

                  <div class="grid md:grid-cols-4 gap-2 mt-3 text-sm">
                    <div class="rounded-xl border border-slate-200 bg-white p-3">Detectados: <strong><?= e((string)$r['files_detected']) ?></strong></div>
                    <div class="rounded-xl border border-slate-200 bg-white p-3">Procesados: <strong><?= e((string)$r['files_processed']) ?></strong></div>
                    <div class="rounded-xl border border-red-200 bg-red-50 p-3">Errores: <strong><?= e((string)$r['errors_total']) ?></strong></div>
                    <div class="rounded-xl border border-slate-200 bg-white p-3">Duración: <strong><?= e($r['duration'] !== '' ? $r['duration'] : '-') ?></strong></div>
                  </div>

                  <?php if ($r['message'] !== ''): ?>
                    <div class="text-sm text-slate-700 mt-3">
                      <strong>Mensaje:</strong> <?= e($r['message']) ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>
<?php ob_end_flush(); ?>

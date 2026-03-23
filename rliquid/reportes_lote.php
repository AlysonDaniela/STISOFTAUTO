<?php
declare(strict_types=1);

define('RLIQUID_BOOTSTRAP_ONLY', true);
require_once __DIR__ . '/index.php';

require_once __DIR__ . '/../includes/auth.php';
require_auth();
$user = current_user();

$headPath   = __DIR__ . '/../partials/head.php';
$sidebarPath= __DIR__ . '/../partials/sidebar.php';
$topbarPath = __DIR__ . '/../partials/topbar.php';
$footerPath = __DIR__ . '/../partials/footer.php';

date_default_timezone_set('America/Santiago');
$csrf = csrf_token();

function fmt_dt_cl_local($dt): string {
  if (!$dt) return '-';
  $t = strtotime((string)$dt);
  return $t ? date('d/m/Y H:i', $t) : (string)$dt;
}

function tipo_label_local(string $t): string {
  return ($t === 'dia25') ? 'Día 25' : 'Fin de mes';
}

function periodo_label_local(string $ames): string {
  $ames = preg_replace('/[^0-9]/', '', $ames);
  if (strlen($ames) !== 6) return $ames;
  $year = substr($ames, 0, 4);
  $month = (int)substr($ames, 4, 2);
  $months = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
  return ($months[$month] ?? $ames).' '.$year;
}

function periodo_export_local(string $ames): string {
  $ames = preg_replace('/[^0-9]/', '', $ames);
  if (strlen($ames) !== 6) return $ames;
  return substr($ames, 4, 2).'-'.substr($ames, 0, 4);
}

function estado_export_item_local(array $item): string {
  if ((int)($item['pdf_ok'] ?? 0) === 1 && (int)($item['send_ok'] ?? 0) === 1) {
    return 'OK';
  }
  return 'ERROR';
}

function report_list_html(array $items, string $emptyText = 'Sin datos'): string {
  if (!$items) {
    return '<div class="small">'.$emptyText.'</div>';
  }
  $html = '<ul class="detail-list">';
  foreach ($items as $item) {
    $html .= '<li>'.h((string)$item).'</li>';
  }
  return $html.'</ul>';
}

function report_error_list_html(array $items, string $kind = 'error'): string {
  if (!$items) {
    return '<div class="small">Sin datos</div>';
  }
  $html = '<ul class="detail-list">';
  foreach ($items as $item) {
    $rut = (string)($item['rut'] ?? '-');
    $nombre = trim((string)($item['nombre'] ?? ''));
    $label = $nombre !== '' ? ($rut.' · '.$nombre) : $rut;
    $message = $kind === 'pdf'
      ? (string)($item['error'] ?? 'Error PDF')
      : ('HTTP '.(int)($item['http_code'] ?? 0).' · '.(string)($item['error'] ?? 'Error Buk'));
    $html .= '<li><strong>'.h($label).'</strong><br><span class="small">'.h($message).'</span></li>';
  }
  return $html.'</ul>';
}

function rliquid_visual_report_html(array $run, array $job, array $summary): string {
  $status = (string)($run['status'] ?? 'queued');
  $statusLabel = rliquid_run_status_label($status);
  $statusClass = in_array($status, ['done'], true)
    ? ['bg' => '#ecfdf5', 'border' => '#a7f3d0', 'fg' => '#065f46']
    : (in_array($status, ['error'], true)
      ? ['bg' => '#fef2f2', 'border' => '#fecaca', 'fg' => '#991b1b']
      : ['bg' => '#fffbeb', 'border' => '#fde68a', 'fg' => '#92400e']);

  $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  $html .= '<title>Reporte Liquidaciones · ' . h(periodo_label_local((string)($job['ames'] ?? ''))) . '</title>';
  $html .= '<style>
    body{font-family:Arial,sans-serif;background:#f4f6f8;color:#111;margin:0}
    .wrap{max-width:1200px;margin:0 auto;padding:18px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin-bottom:12px}
    .h1{font-size:20px;font-weight:700;margin:0 0 8px}
    .h2{font-size:15px;font-weight:700;margin:8px 0}
    .muted{color:#555;font-size:12px}
    .grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
    .grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
    .k{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:8px;font-size:13px}
    .box{border:1px solid #e5e7eb;border-radius:8px;padding:10px;margin-top:8px;font-size:12px;background:#fff}
    .list{margin:6px 0 0 18px;padding:0}
    .list li{margin:3px 0}
    iframe{width:100%;min-height:520px;border:1px solid #dbe3ef;border-radius:12px;background:#fff}
    @media (max-width:1000px){.grid,.grid2{grid-template-columns:1fr}}
  </style></head><body><div class="wrap">';
  $html .= '<div class="card">';
  $html .= '<div class="h1">'.h(periodo_label_local((string)($job['ames'] ?? ''))).' · '.h(tipo_label_local((string)($job['tipo'] ?? 'mes'))).'</div>';
  $html .= '<div class="muted">Operación: ' . h(fmt_dt_cl_local($run['created_at'] ?? null)) . ' · ' . h((string)($job['source_filename'] ?? '')) . '</div>';
  $html .= '<div style="margin-top:10px;padding:12px 14px;background:' . $statusClass['bg'] . ';border:1px solid ' . $statusClass['border'] . ';border-radius:10px;color:' . $statusClass['fg'] . ';font-weight:700;">Estado: ' . h($statusLabel) . '</div>';
  $html .= '</div>';

  $html .= '<div class="card"><div class="grid">';
  $html .= '<div class="k">Solicitada por: <strong>' . h((string)($run['requested_by'] ?? '-')) . '</strong></div>';
  $html .= '<div class="k">Correos: <strong>' . h((string)($run['notify_email'] ?? '-')) . '</strong></div>';
  $html .= '<div class="k">Inicio: <strong>' . h(fmt_dt_cl_local($run['started_at'] ?? null)) . '</strong></div>';
  $html .= '<div class="k">Fin: <strong>' . h(fmt_dt_cl_local($run['finished_at'] ?? null)) . '</strong></div>';
  $html .= '<div class="k">Total registros: <strong>' . (int)($summary['total_items'] ?? 0) . '</strong></div>';
  $html .= '<div class="k">PDF OK: <strong>' . (int)($summary['pdf_ok'] ?? 0) . '</strong></div>';
  $html .= '<div class="k">Buk OK: <strong>' . (int)($summary['send_ok'] ?? 0) . '</strong></div>';
  $html .= '<div class="k">Errores: <strong>' . ((int)($summary['pdf_error'] ?? 0) + (int)($summary['send_error'] ?? 0)) . '</strong></div>';
  $html .= '</div></div>';

  $html .= '<div class="card"><div class="h2">Detalle por resultado</div><div class="grid2">';
  $html .= '<div class="box"><strong>RUT enviados OK</strong>' . report_list_html((array)($summary['send_ok_ruts'] ?? []), 'Sin RUT enviados OK') . '</div>';
  $html .= '<div class="box"><strong>RUT sin buk_id</strong>' . report_list_html((array)($summary['missing_buk_ruts'] ?? []), 'Sin RUT sin buk_id') . '</div>';
  $html .= '<div class="box"><strong>Errores PDF</strong>' . report_error_list_html((array)($summary['pdf_error_items'] ?? []), 'pdf') . '</div>';
  $html .= '<div class="box"><strong>Errores de envío Buk</strong>' . report_error_list_html((array)($summary['send_error_items'] ?? []), 'send') . '</div>';
  $html .= '</div>';
  if (!empty($summary['pending_ruts'])) {
    $html .= '<div class="box"><strong>Pendientes</strong>' . report_list_html((array)$summary['pending_ruts'], 'Sin pendientes') . '</div>';
  }
  if (!empty($summary['error_message'])) {
    $html .= '<div class="box" style="border-color:#fecaca;background:#fef2f2;color:#991b1b;"><strong>Error:</strong> ' . h((string)$summary['error_message']) . '</div>';
  }
  $html .= '</div>';

  if (!empty($summary['email_html'])) {
    $html .= '<div class="card"><div class="h2">HTML del correo</div>';
    if (!empty($summary['email_subject'])) {
      $html .= '<div class="muted" style="margin-bottom:8px"><strong>Asunto:</strong> ' . h((string)$summary['email_subject']) . '</div>';
    }
    $html .= '<iframe sandbox="" srcdoc="' . h((string)$summary['email_html']) . '"></iframe></div>';
  }

  $html .= '</div></body></html>';
  return $html;
}

$msgOk = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate($_POST['csrf_token'] ?? '')) {
  $msgOk = 'La sesión de seguridad expiró. Recarga la pantalla e inténtalo nuevamente.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validate($_POST['csrf_token'] ?? '') && isset($_POST['delete_run'])) {
  $runIdToDelete = (int)($_POST['delete_run'] ?? 0);
  $runToDelete = $runIdToDelete > 0 ? rliquid_get_run($runIdToDelete) : null;
  if (!$runToDelete) {
    $msgOk = 'No se encontró la corrida seleccionada.';
  } elseif (in_array((string)($runToDelete['status'] ?? ''), ['queued', 'running'], true)) {
    $msgOk = 'No se puede borrar una corrida activa.';
  } else {
    $logPath = (string)($runToDelete['log_path'] ?? '');
    DB()->ejecutar("DELETE FROM buk_liq_job_runs WHERE id=".(int)$runIdToDelete." LIMIT 1");
    if ($logPath !== '' && is_file($logPath)) {
      @unlink($logPath);
    }
    header('Location: /rliquid/reportes_lote.php?job='.(int)($runToDelete['job_id'] ?? 0).'&deleted=1');
    exit;
  }
}

if (isset($_GET['view_html'])) {
  $runIdView = (int)($_GET['view_html'] ?? 0);
  $runView = $runIdView > 0 ? rliquid_get_run($runIdView) : null;
  if (!$runView) {
    http_response_code(404);
    exit('Corrida no encontrada.');
  }
  $jobView = get_job((int)($runView['job_id'] ?? 0));
  if (!$jobView) {
    http_response_code(404);
    exit('Lote no encontrado.');
  }
  $summaryView = rliquid_json_decode($runView['summary_json'] ?? '');
  header('Content-Type: text/html; charset=utf-8');
  echo rliquid_visual_report_html($runView, $jobView, $summaryView);
  exit;
}

if (isset($_GET['export_excel'])) {
  $runIdExport = (int)($_GET['export_excel'] ?? 0);
  $runExport = $runIdExport > 0 ? rliquid_get_run($runIdExport) : null;
  if (!$runExport) {
    http_response_code(404);
    exit('Corrida no encontrada.');
  }
  $jobExport = get_job((int)($runExport['job_id'] ?? 0));
  if (!$jobExport) {
    http_response_code(404);
    exit('Lote no encontrado.');
  }

  $itemsExport = DB()->consultar("
    SELECT buk_emp_id, rut_norm, nombre, neto, pdf_ok, send_ok
    FROM buk_liq_job_items
    WHERE job_id=".(int)$jobExport['id']."
    ORDER BY rut_norm
  ") ?: [];

  if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Reporte');

    $headers = ['ID en BUK', 'RUT', 'NOMBRE', 'TOTAL A PAGAR', 'TIPO LIQUIDACION', 'MES-ANO LIQUIDACION', 'ESTADO'];
    $col = 'A';
    foreach ($headers as $header) {
      $sheet->setCellValue($col.'1', $header);
      $col++;
    }

    $rowNum = 2;
    foreach ($itemsExport as $item) {
      $sheet->setCellValue('A'.$rowNum, (string)($item['buk_emp_id'] ?? ''));
      $sheet->setCellValue('B'.$rowNum, rut_display((string)($item['rut_norm'] ?? '')));
      $sheet->setCellValue('C'.$rowNum, (string)($item['nombre'] ?? ''));
      $sheet->setCellValue('D'.$rowNum, (float)($item['neto'] ?? 0));
      $sheet->setCellValue('E'.$rowNum, tipo_label_local((string)($jobExport['tipo'] ?? 'mes')));
      $sheet->setCellValue('F'.$rowNum, periodo_export_local((string)($jobExport['ames'] ?? '')));
      $sheet->setCellValue('G'.$rowNum, estado_export_item_local($item));
      $rowNum++;
    }

    $sheet->getStyle('A1:G1')->getFont()->setBold(true);
    $sheet->getStyle('D2:D'.max(2, $rowNum - 1))->getNumberFormat()->setFormatCode('#,##0');
    foreach (range('A', 'G') as $column) {
      $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $fileName = 'reporte_liquidaciones_'.preg_replace('/[^a-zA-Z0-9_-]/', '_', periodo_label_local((string)($jobExport['ames'] ?? ''))).'_'.preg_replace('/[^a-zA-Z0-9_-]/', '_', tipo_label_local((string)($jobExport['tipo'] ?? 'mes'))).'.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$fileName.'"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
  }

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="reporte_liquidaciones.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID en BUK', 'RUT', 'NOMBRE', 'TOTAL A PAGAR', 'TIPO LIQUIDACION', 'MES-ANO LIQUIDACION', 'ESTADO']);
  foreach ($itemsExport as $item) {
    fputcsv($out, [
      (string)($item['buk_emp_id'] ?? ''),
      rut_display((string)($item['rut_norm'] ?? '')),
      (string)($item['nombre'] ?? ''),
      (float)($item['neto'] ?? 0),
      tipo_label_local((string)($jobExport['tipo'] ?? 'mes')),
      periodo_export_local((string)($jobExport['ames'] ?? '')),
      estado_export_item_local($item),
    ]);
  }
  fclose($out);
  exit;
}

if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
  $msgOk = 'Reporte de corrida eliminado correctamente.';
}
if (isset($_GET['run_started']) && isset($_GET['run'])) {
  $msgOk = 'El lote ya está corriendo en segundo plano. Puedes cerrar esta página; recibirás un correo al finalizar.';
}

$runs = DB()->consultar("
  SELECT
    r.*,
    j.ames,
    j.tipo,
    j.source_filename
  FROM buk_liq_job_runs r
  INNER JOIN buk_liq_jobs j ON j.id = r.job_id
  ORDER BY r.id DESC
  LIMIT 100
") ?: [];
$totalLotsRow = DB()->consultar("
  SELECT COUNT(DISTINCT CONCAT(j.ames, '-', j.tipo)) AS total
  FROM buk_liq_job_runs r
  INNER JOIN buk_liq_jobs j ON j.id = r.job_id
");
$totalRunsRow = DB()->consultar("SELECT COUNT(*) AS total FROM buk_liq_job_runs");
$totalLots = (int)($totalLotsRow[0]['total'] ?? 0);
$totalRuns = (int)($totalRunsRow[0]['total'] ?? 0);
$hasOpenRun = false;
foreach ($runs as $rr) {
  if (in_array((string)($rr['status'] ?? ''), ['queued', 'running'], true)) {
    $hasOpenRun = true;
    break;
  }
}
?>
<?php include $headPath; ?>
<body class="bg-gray-50">
<div class="min-h-screen grid grid-cols-12">
  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php $active='liquidaciones'; include $sidebarPath; ?>
  </div>

  <div class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col">
    <?php include $topbarPath; ?>

    <main class="flex-grow max-w-[1320px] p-4 md:p-5 space-y-4">
      <style>
        .card{background:#fff;border:1px solid #e6e8f0;border-radius:20px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
        .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
        .small{font-size:12px;color:#6b7280}
        .msg{padding:12px 14px;border-radius:12px;border:1px solid}
        .ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
        .tag{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;font-size:12px;border:1px solid #e6e8f0;background:#fafafa;font-weight:700}
        .tag-ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
        .tag-warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
        .tag-err{background:#fef2f2;border-color:#fecaca;color:#991b1b}
        .hero{background:linear-gradient(135deg,#0f172a 0%,#155e75 52%,#0284c7 100%);color:#fff;border-radius:28px;padding:24px;border:1px solid rgba(255,255,255,.12)}
        .hero-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:16px}
        .hero-stat{background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:12px}
        .section-title{font-size:19px;font-weight:900;color:#111827;margin:0}
        .section-sub{font-size:13px;color:#6b7280;margin-top:6px;line-height:1.45}
        .inline-link{display:inline-flex;align-items:center;justify-content:center;height:44px;padding:0 16px;border-radius:14px;border:1px solid #dbeafe;background:#eff6ff;color:#1d4ed8;text-decoration:none;font-weight:800}
        .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        select{padding:10px;border:1px solid #e6e8f0;border-radius:12px;width:100%;height:44px;background:#fff}
        .run-detail{padding:12px 14px;border:1px solid #e6e8f0;border-radius:14px;background:#fbfcff}
        .run-detail summary{cursor:pointer;font-weight:800;color:#111827}
        .detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}
        .detail-card{border:1px solid #e6e8f0;border-radius:12px;background:#fff;padding:12px}
        .detail-card-title{font-size:13px;font-weight:800;color:#111827;margin-bottom:8px}
        .detail-list{margin:0;padding-left:18px}
        .detail-list li{margin-bottom:8px}
        .mail-frame{width:100%;min-height:420px;border:1px solid #dbe3ef;border-radius:12px;background:#fff}
        .run-list{margin-top:12px;display:flex;flex-direction:column;gap:12px}
        .run-card{border:1px solid #e2e8f0;border-radius:24px;padding:16px;background:#f8fafc}
        .run-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
        .run-title{font-size:18px;font-weight:800;color:#0f172a}
        .run-meta{font-size:12px;color:#64748b;margin-top:4px}
        .run-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:flex-end}
        .run-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}
        .run-stat{border:1px solid #e2e8f0;border-radius:16px;background:#fff;padding:12px}
        .run-stat-label{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.08em}
        .run-stat-value{font-size:14px;font-weight:700;color:#0f172a;margin-top:6px;line-height:1.45}
        @media (max-width:980px){ .grid2,.hero-grid{grid-template-columns:1fr} }
        @media (max-width:980px){ .detail-grid,.run-stats{grid-template-columns:1fr} }
      </style>

      <?php if($msgOk): ?><div class="msg ok"><?= h($msgOk) ?></div><?php endif; ?>

      <div class="hero">
        <div class="hero-grid">
          <div>
            <div class="small" style="color:#ccfbf1;letter-spacing:.18em;text-transform:uppercase;font-weight:800">Liquidaciones</div>
            <div style="font-size:32px;font-weight:950;letter-spacing:-0.03em;margin-top:10px">Reportes de liquidaciones</div>
            <div style="font-size:14px;color:#e6fffb;margin-top:10px;max-width:760px;line-height:1.5">Aquí ves el historial de ejecuciones, el período informado y el resumen guardado de cada operación.</div>
            <div class="row" style="margin-top:16px">
              <a class="inline-link" href="/rliquid/index.php">Volver al flujo principal</a>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;align-self:start">
            <div class="hero-stat">
            <div class="small" style="color:#ccfbf1">Períodos</div>
            <div style="font-size:24px;font-weight:900;margin-top:6px"><?= $totalLots ?></div>
          </div>
          <div class="hero-stat">
            <div class="small" style="color:#ccfbf1">Corridas</div>
            <div style="font-size:24px;font-weight:900;margin-top:6px"><?= $totalRuns ?></div>
          </div>
        </div>
      </div>
      </div>

      <div class="card">
        <div class="row" style="justify-content:space-between;align-items:flex-end">
          <div>
            <div class="section-title">Historial de reportes</div>
            <div class="section-sub">Cada tarjeta corresponde a una corrida ejecutada y conserva su resumen.</div>
          </div>
          <?php if($hasOpenRun): ?>
            <span class="tag tag-warn">Hay una corrida activa</span>
          <?php else: ?>
            <span class="tag tag-ok">Sin corridas activas</span>
          <?php endif; ?>
        </div>

        <div class="run-list">
          <?php if(!$runs): ?>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
              <p class="small">Todavía no hay reportes de liquidaciones.</p>
            </div>
          <?php else: ?>
            <?php foreach($runs as $run): ?>
              <?php
                $status = (string)($run['status'] ?? 'queued');
                $summary = rliquid_json_decode($run['summary_json'] ?? '');
              ?>
              <div class="run-card">
                <div class="run-head">
                  <div>
                    <div class="run-title"><?= h(periodo_label_local((string)($run['ames'] ?? ''))) ?> · <?= h(tipo_label_local((string)($run['tipo'] ?? 'mes'))) ?></div>
                    <div class="run-meta">Operación: <?= h(fmt_dt_cl_local($run['created_at'] ?? null)) ?> · <?= h((string)($run['source_filename'] ?? '')) ?> · <?= h((string)($run['requested_by'] ?? '-')) ?></div>
                  </div>
                  <div class="run-actions">
                    <span class="tag <?= rliquid_run_status_class($status) ?>"><?= h(rliquid_run_status_label($status)) ?></span>
                    <a href="?view_html=<?= (int)$run['id'] ?>" target="_blank" class="px-3 py-2 rounded-xl bg-slate-800 text-white text-xs font-semibold hover:bg-black transition">Ver HTML</a>
                    <a href="?export_excel=<?= (int)$run['id'] ?>" class="px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-700 transition">Exportar Excel</a>
                    <?php if (!in_array($status, ['queued', 'running'], true)): ?>
                      <form method="post" onsubmit="return confirm('¿Eliminar este reporte de corrida?');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="delete_run" value="<?= (int)$run['id'] ?>">
                        <button type="submit" class="px-3 py-2 rounded-xl bg-red-600 text-white text-xs font-semibold hover:bg-red-700 transition">Borrar</button>
                      </form>
                    <?php else: ?>
                      <span class="small">Activa</span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="run-stats">
                  <div class="run-stat">
                    <div class="run-stat-label">Resumen</div>
                    <div class="run-stat-value"><?= h(rliquid_run_resume($run)) ?></div>
                  </div>
                  <div class="run-stat">
                    <div class="run-stat-label">Período</div>
                    <div class="run-stat-value"><?= h(periodo_label_local((string)($run['ames'] ?? ''))) ?></div>
                  </div>
                  <div class="run-stat">
                    <div class="run-stat-label">Tipo</div>
                    <div class="run-stat-value"><?= h(tipo_label_local((string)($run['tipo'] ?? 'mes'))) ?></div>
                  </div>
                  <div class="run-stat">
                    <div class="run-stat-label">Operación</div>
                    <div class="run-stat-value"><?= h(fmt_dt_cl_local($run['created_at'] ?? null)) ?></div>
                  </div>
                  <div class="run-stat">
                    <div class="run-stat-label">Inicio</div>
                    <div class="run-stat-value"><?= h(fmt_dt_cl_local($run['started_at'] ?? null)) ?></div>
                  </div>
                  <div class="run-stat">
                    <div class="run-stat-label">Fin</div>
                    <div class="run-stat-value"><?= h(fmt_dt_cl_local($run['finished_at'] ?? null)) ?></div>
                  </div>
                </div>

                <?php if (!empty($summary['error_message'])): ?>
                  <div style="margin-top:10px;color:#991b1b;font-size:13px"><strong>Error:</strong> <?= h((string)$summary['error_message']) ?></div>
                <?php endif; ?>

              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </main>

    <script>
      <?php if($hasOpenRun): ?>
      setTimeout(function(){ window.location.reload(); }, 15000);
      <?php endif; ?>
    </script>

    <?php if (is_file($footerPath)) { include $footerPath; } else { ?>
      <footer class="text-center text-xs text-gray-400 py-4 border-t bg-white">
        © <?= date('Y') ?> STI Soft — Integración ADP + Buk
      </footer>
    <?php } ?>
  </div>
</div>
</body>
</html>

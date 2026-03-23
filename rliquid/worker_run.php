<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  echo "CLI only\n";
  exit(1);
}

define('RLIQUID_BOOTSTRAP_ONLY', true);
require_once __DIR__ . '/index.php';
while (ob_get_level() > 0) {
  @ob_end_clean();
}

@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '1024M');
ignore_user_abort(true);
if (function_exists('proc_nice')) {
  @proc_nice(19);
}

$runId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($runId <= 0) {
  fwrite(STDERR, "Uso: php worker_run.php <run_id>\n");
  exit(1);
}

try {
  rliquid_process_run($runId);
  exit(0);
} catch (Throwable $e) {
  $run = function_exists('rliquid_get_run') ? rliquid_get_run($runId) : null;
  $logPath = $run['log_path'] ?? null;
  if (function_exists('rliquid_log_line')) {
    rliquid_log_line($logPath, 'FATAL: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
  }
  if (function_exists('DB')) {
    DB()->ejecutar("UPDATE buk_liq_job_runs
      SET status='error', finished_at=NOW(), error_message='".esc($e->getMessage())."'
      WHERE id=".(int)$runId);
    $run = rliquid_get_run($runId) ?: $run;
    $job = $run ? get_job((int)$run['job_id']) : null;
    if ($run && $job) {
      $summary = rliquid_json_decode($run['summary_json'] ?? '');
      $summary['error_message'] = $e->getMessage();
      $summary = array_merge($summary, rliquid_collect_run_details((int)$job['id']));
      $mailPayload = rliquid_build_summary_email($run, $job, $summary);
      $summary['email_subject'] = (string)($mailPayload['subject'] ?? '');
      $summary['email_html'] = (string)($mailPayload['html'] ?? '');
      DB()->ejecutar("UPDATE buk_liq_job_runs
        SET summary_json='".esc(rliquid_json_encode($summary))."'
        WHERE id=".(int)$runId);
      $sent = rliquid_send_summary_email($run, $job, $summary);
      if (function_exists('rliquid_log_line')) {
        rliquid_log_line($logPath, $sent ? 'Correo resumen OK' : 'Correo resumen ERROR');
      }
    }
  }
  fwrite(STDERR, $e->getMessage()."\n");
  exit(1);
}

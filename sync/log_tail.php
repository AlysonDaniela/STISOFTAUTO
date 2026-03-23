<?php
// /sync/log_tail.php — devuelve últimas N líneas del log
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_admin();

header('Content-Type: text/plain; charset=UTF-8');
$lines = max(10, min(1000, (int)($_GET['lines'] ?? 200)));
$file  = __DIR__ . '/storage/logs/sync.log';
if(!is_file($file)){ echo ""; exit; }
function tail_lines($file, $lines){
  $f = fopen($file, "r"); $buffer=''; $chunk=4096;
  fseek($f, 0, SEEK_END); $pos = ftell($f); $count=0;
  while($pos>0 && $count <= $lines){
    $read = ($pos - $chunk)>=0 ? $chunk : $pos;
    $pos -= $read; fseek($f, $pos);
    $buffer = fread($f, $read) . $buffer;
    $count = substr_count($buffer, "\n");
    if($pos===0) break;
  }
  fclose($f);
  $arr = explode("\n",$buffer);
  return implode("\n", array_slice($arr, -$lines));
}
echo tail_lines($file, $lines);

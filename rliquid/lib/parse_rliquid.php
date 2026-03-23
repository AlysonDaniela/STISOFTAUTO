<?php
/* ===== Helpers de formato ===== */
function parse_monto($raw) {
  $s = is_string($raw) ? $raw : strval($raw);
  $s = str_replace(["\xC2\xA0"], ' ', $s);
  $s = str_replace(' ', '', $s);
  $s = preg_replace('/[^0-9,\.-]/', '', $s);
  $s = str_replace('.', '', $s);
  $s = preg_replace('/,(\d+)$/', '.$1', $s);
  if ($s === '' || $s === '-') return 0.0;
  return floatval($s);
}
function fmt_clp($n) { return '$' . number_format(round($n), 0, ',', '.'); }
function normalize_header($h) { return strtolower(preg_replace('/\s+/', '', trim($h))); }

/* ===== Detección de separador ===== */
function detect_delimiter($line) {
  $candidates = [';', "\t", '|', ','];
  $best = ';'; $bestCount = 0;
  foreach ($candidates as $d) {
    $cnt = substr_count($line, $d);
    if ($cnt > $bestCount) { $best = $d; $bestCount = $cnt; }
  }
  return $best;
}

/* ===== Parser principal ===== */
function parse_rliquid($text) {
  $lines = preg_split('/\r?\n/', $text);
  $lines = array_values(array_filter($lines, fn($l)=>trim($l)!==''));
  if (empty($lines)) return [];

  $first = $lines[0];
  $delim = detect_delimiter($first);

  $looksHeader = (strpos($first, $delim)!==false) && (strpos(normalize_header($first),'codigo')!==false);
  $start = $looksHeader ? 1 : 0;

  $cols = [
    'Codigo','Ames','Peri','Cohade','Tipo','Descitm','Orden','Monto',
    'MontoO','MontoA','PerImp','Empresa','Inform','Cencos','Coprev',
    'Origen','Cod_reg','Codpres','Cmapa','Dato','Nro','VO','Jdd'
  ];

  $rows = [];
  for ($i=$start; $i<count($lines); $i++) {
    $parts = explode($delim, $lines[$i]);
    if (count($parts) < 2) continue;
    $rec = [];
    foreach ($cols as $idx=>$name) {
      $rec[$name] = isset($parts[$idx]) ? trim($parts[$idx]) : '';
    }
$baseMonto = $rec['Monto'] ?? '';
if ($baseMonto === '' || $baseMonto === '0') {
  if (!empty($rec['MontoO'])) $baseMonto = $rec['MontoO'];
  elseif (!empty($rec['MontoA'])) $baseMonto = $rec['MontoA'];
}
$rec['_monto'] = parse_monto($baseMonto);
    $rows[] = $rec;
  }
  return $rows;
}

/* ===== Reglas para totales ===== */
function exclude_codes() {
  return [
    'A000','UNIBAS','BASEIA','IMPUES','TOTDES',
    'APOEMP','SEGCEE','SEGCEI','SEGCET','SISAFP','MUTUAL','CAJACO','COCAPI',
    'COSESO','LSANNA','CGRATF','PRBVAC','PROVAC','PROEXT','PROREG'
  ];
}

/* Totales para un RUT y un AMES */
function compute_totals_for($rows, $rut, $ames) {
  $EX = exclude_codes();
  $totHab=0; $totDesc=0;
  foreach ($rows as $r) {
    if (($r['Codigo'] ?? '') !== $rut) continue;
    if (($r['Ames'] ?? '') !== $ames) continue;
    $tipo = trim(strval($r['Tipo'] ?? ''));
    $code = trim($r['Cohade'] ?? '');
    if (in_array($code, $EX, true)) continue;
    $m = $r['_monto'] ?? 0;
    if ($tipo==='1' || $tipo==='2') $totHab += $m;
    if ($tipo==='3' || $tipo==='4') $totDesc += $m;
  }
  return [$totHab, $totDesc, $totHab - $totDesc];
}

/* Último AMES por usuario */
function get_latest_ames_for_user($rows, $rut) {
  $amesSet = [];
  foreach ($rows as $r) {
    if (($r['Codigo'] ?? '') !== $rut) continue;
    $a = $r['Ames'] ?? '';
    if ($a==='') continue;
    $amesSet[$a] = true;
  }
  if (empty($amesSet)) return '';
  $keys = array_keys($amesSet);
  rsort($keys, SORT_STRING); // YYYYMM → orden descendente
  return $keys[0];
}

/* Agrupar por usuario usando su último AMES */
function group_by_user_latest($rows) {
  // lista única de usuarios
  $users = [];
  foreach ($rows as $r) {
    $rut = trim($r['Codigo'] ?? '');
    if ($rut==='') continue;
    $users[$rut] = true;
  }
  $grupos = [];
  foreach (array_keys($users) as $rut) {
    $ames = get_latest_ames_for_user($rows, $rut);
    if ($ames==='') continue; // sin mes, lo omitimos
    [$hab, $desc, $neto] = compute_totals_for($rows, $rut, $ames);
    $grupos[] = [
      'rut' => $rut,
      'ames_latest' => $ames,
      'totHaberes' => $hab,
      'totDescuentos' => $desc,
      'neto' => $neto,
    ];
  }
  usort($grupos, fn($a,$b)=>strcmp($a['rut'],$b['rut']));
  return $grupos;
}

function month_label($ames){
  if (!preg_match('/^[0-9]{6}$/',$ames)) return $ames;
  $y = intval(substr($ames,0,4));
  $m = intval(substr($ames,4,2));
  $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
  return $meses[$m] . ' ' . $y;
}

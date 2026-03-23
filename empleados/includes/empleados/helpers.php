<?php
// empleados/includes/empleados/helpers.php

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function pick(array $row, array $cands): string {
    foreach ($cands as $c) {
        if (array_key_exists($c, $row) && $row[$c] !== '' && $row[$c] !== null) {
            return (string)$row[$c];
        }
    }
    return '';
}


function map_empresa_sistema_a_buk(?int $empresaSistema): ?int {
  if ($empresaSistema === null) return null;
  if ($empresaSistema === 3) return 2;
  if ($empresaSistema === 2) return 3;
  if ($empresaSistema === 1) return 1;
  return null;
}

function resolver_company_id_buk(?int $empresaAdp, $credencial): ?int {
  if ($empresaAdp === null) return null;

  // DAMEC
  if ((int)$empresaAdp === 101) {
    $c = is_numeric($credencial) ? (int)$credencial : null;
    return map_empresa_sistema_a_buk($c);
  }

  return map_empresa_sistema_a_buk((int)$empresaAdp);
}

function db_set_company_buk(clsConexion $db, string $rut, ?int $bukCompanyId, string $estado): void {
  $rutEsc = $db->real_escape_string($rut);
  $bukVal = ($bukCompanyId === null) ? "NULL" : (int)$bukCompanyId;
  $estadoEsc = $db->real_escape_string($estado);

  $db->ejecutar("
    UPDATE adp_empleados
    SET buk_company_id = {$bukVal},
        company_buk = '{$estadoEsc}'
    WHERE Rut = '{$rutEsc}'
  ");
}

function norm_txt(?string $s): string {
    $s = trim((string)$s);
    $s = mb_strtoupper($s, 'UTF-8');
    $s = preg_replace('/\s+/', ' ', $s);
    return $s ?: '';
}

function rut_key(?string $rut): string {
    $rut = (string)$rut;
    $rut = trim($rut);
    if ($rut === '') return '';
    $rut = preg_replace('/[^0-9kK]/', '', $rut);
    return strtoupper($rut);
}

function rut_pretty(?string $rut): string {
    $k = rut_key($rut);
    if ($k === '') return '';
    $dv = substr($k, -1);
    $num = substr($k, 0, -1);
    if ($num === '') return $k;
    return $num . '-' . $dv;
}

function to_iso(?string $s): ?string {
    if($s===null) return null;
    $s=trim($s);
    if($s==='') return null;

    $s = str_replace(['.','/'], '-', $s);
    $s = preg_replace('/\s+/', ' ', $s);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

    $fmts = ['d-m-Y','d-m-y','Y-m-d','Y/m/d','d/m/Y','d/m/y'];
    foreach($fmts as $fmt){
        $dt = DateTime::createFromFormat($fmt, $s);
        if($dt && $dt->format($fmt)===$s) {
             $year = (int)$dt->format('Y');
             if ($year > 1900 && $year < 2100) return $dt->format('Y-m-d');
        }
    }

    $s2 = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?(\.\d+)?$/','',$s);
    if ($s2 !== null && $s2 !== $s) return to_iso($s2);

    $ts = strtotime($s);
    if ($ts !== false) {
        $year = (int)date('Y', $ts);
        if ($year > 1900 && $year < 2100) return date('Y-m-d', $ts);
    }
    return null;
}

function money_to_int($v): ?int {
    if($v===null) return null;
    $s = (string)$v;
    $s = str_replace(['.', ' '], '', $s);
    $s = str_replace(',', '.', $s);
    if($s==='') return null;
    if(!is_numeric($s)) return null;
    return (int)round((float)$s);
}

function norm_gender(?string $g): ?string {
    $g = mb_strtolower(trim((string)$g), 'UTF-8');
    if ($g === '' || $g === '0') return null;
    if (in_array($g, ['m','masculino','hombre'], true)) return 'M';
    if (in_array($g, ['f','femenino','mujer'], true)) return 'F';
    return null;
}

<?php
/**
 * liquidacion.php – ACTUALIZADO (Empresa dinámica + Formato Nombre + Ajuste Tipografía Header + Medidas logo por empresa + Tipo Remun (Mes/Día25) + Nombre archivo)
 *
 * Ubicación esperada: /rliquid/liquidacion.php
 * Dependencia BD:     /conexion/db.php
 */

// 1. SUPRIMIR ERRORES EN PANTALLA PARA NO ROMPER EL PDF
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require_once __DIR__ . '/../includes/auth.php';
require_auth();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cargar librerías y utilidades
require_once __DIR__ . '/lib/parse_rliquid.php';

// Cargar FPDF
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) { http_response_code(500); echo 'Falta vendor/autoload.php'; exit; }
require_once $autoload;
if (!class_exists('FPDF')) { http_response_code(500); echo 'No se encontró FPDF.'; exit; }

// Cargar tu clase de Conexión
$dbPath = __DIR__ . '/../conexion/db.php';
if (file_exists($dbPath)) {
    require_once $dbPath;
}

/* ==========================================================
   FUNCIONES AUXILIARES
   ========================================================== */

function calcular_antiguedad($fechaIngreso, $ames) {
    if (!$fechaIngreso || !$ames) return '';
    $ingreso = DateTime::createFromFormat('d/m/Y', $fechaIngreso);
    if (!$ingreso) $ingreso = DateTime::createFromFormat('Y-m-d', $fechaIngreso);
    if (!$ingreso) return '';

    $year = substr($ames, 0, 4);
    $month = substr($ames, 4, 2);
    $liquidacion = new DateTime("$year-$month-01");
    $liquidacion->modify('last day of this month');

    $diff = $ingreso->diff($liquidacion);

    $txt = [];
    if ($diff->y > 0) $txt[] = $diff->y . " años";
    if ($diff->m > 0) $txt[] = $diff->m . " meses";

    if (empty($txt)) return "Menos de 1 mes";
    return implode(", ", $txt);
}

function _tramo_1_999($n){
    $u = ['','uno','dos','tres','cuatro','cinco','seis','siete','ocho','nueve',
          'diez','once','doce','trece','catorce','quince','dieciséis','diecisiete','dieciocho','diecinueve'];
    $d = ['','', 'veinte','treinta','cuarenta','cincuenta','sesenta','setenta','ochenta','noventa'];
    $c = ['','ciento','doscientos','trescientos','cuatrocientos','quinientos','seiscientos','setecientos','ochocientos','novecientos'];
    if ($n==0) return 'cero';
    if ($n==100) return 'cien';
    $txt = '';
    $cent = intdiv($n,100); $n %= 100;
    if ($cent) $txt .= $c[$cent].' ';
    if ($n<20) { $txt .= $u[$n]; } else {
        $dec = intdiv($n,10); $uni = $n%10;
        if ($dec==2 && $uni>0) $txt .= 'veinti'.$u[$uni];
        else { $txt .= $d[$dec]; if ($uni) $txt .= ' y '.$u[$uni]; }
    }
    return trim($txt);
}
function numero_a_letras($num){
    $num = (int)round($num);
    if ($num==0) return 'cero pesos';
    $parts=[];
    $millones = intdiv($num,1000000); $num%=1000000;
    $miles    = intdiv($num,1000);    $num%=1000;
    $resto    = $num;
    if ($millones){ $parts[] = ($millones==1?'un millón': _tramo_1_999($millones).' millones'); }
    if ($miles){    $parts[] = ($miles==1?'mil': _tramo_1_999($miles).' mil'); }
    if ($resto){
        $r = _tramo_1_999($resto);
        $r = preg_replace('/\buno\b/u','un',$r);
        $parts[] = $r;
    }
    $frase = trim(implode(' ', $parts)).' pesos';
    return mb_convert_case($frase, MB_CASE_LOWER, 'UTF-8');
}

function format_nombre_persona($s) {
    $s = trim((string)$s);
    if ($s === '') return '';
    $s = preg_replace('/\s+/u', ' ', $s);
    return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
}

function get_empresa_data($empresaId) {
    $empresaId = (int)$empresaId;

    $data = [
        'NOMBRE' => 'MUELLAJE DEL MAIPO S.A.',
        'RUT'    => '99506030-2',
        'DIR'    => 'AV. Bernardo Ohiggins 2263',
        'LOGO'   => __DIR__ . '/img/LogoMuellajedelMaipo.jpg',

        // Medidas default logo
        'LOGO_X' => 2,
        'LOGO_Y' => -8,
        'LOGO_W' => 22,
    ];

    if ($empresaId === 1) {
        $data['NOMBRE'] = 'SAN ANTONIO TERMINAL';
        $data['RUT']    = '96908970-K';
        $data['DIR']    = 'Avda Bernardo Ohiggins 2263';
        $data['LOGO']   = __DIR__ . '/img/LogoSTI.jpg';

        $data['LOGO_X'] = 5;
        $data['LOGO_Y'] = -8;
        $data['LOGO_W'] = 19;

    } elseif ($empresaId === 2) {
        $data['NOMBRE'] = 'MUELLAJE STI S.A.';
        $data['RUT']    = '96915770-5';
        $data['DIR']    = 'Avda. Ramón Barros Luco 1613 13 1301';
        $data['LOGO']   = __DIR__ . '/img/LogoMuellaje.jpg';

        $data['LOGO_X'] = 3;
        $data['LOGO_Y'] = -8;
        $data['LOGO_W'] = 21;

    } elseif ($empresaId === 3) {
        $data['NOMBRE'] = 'MUELLAJE DEL MAIPO S.A.';
        $data['RUT']    = '99506030-2';
        $data['DIR']    = 'Av. Bernardo Ohiggins 2263';
        $data['LOGO']   = __DIR__ . '/img/LogoMuellajedelMaipo.jpg';

        $data['LOGO_X'] = 1.6;
        $data['LOGO_Y'] = -4;
        $data['LOGO_W'] = 26;
    }

    return $data;
}

/* ==========================================================
   LECTURA DE DATOS
   ========================================================== */

$ruta  = $_SESSION['rliquid_path'] ?? '';
$rut   = $_GET['rut'] ?? '';
$ames  = preg_replace('/[^0-9]/','', $_GET['ames'] ?? '');

$nombre= $_GET['nombre'] ?? '';
$antig = $_GET['antig'] ?? '';
$zona  = $_GET['zona'] ?? '';
$pago  = $_GET['pago'] ?? '';
$banco = $_GET['banco'] ?? '';
$cta   = $_GET['cta'] ?? '';

// >>> NUEVO: Tipo recuadro desde el index (mes | dia25)
$REMUN_TIPO = ($_SESSION['rliquid_remun_tipo'] ?? 'mes');
$REMUN_TIPO = ($REMUN_TIPO === 'dia25') ? 'dia25' : 'mes';

function term_row_monto($row, $idx) {
    $base = $row[$idx['Monto']] ?? '';
    return parse_monto($base);
}

function build_sections_from_term_csv($csvPath, $rut, $ames) {
    $sections = [
        'HABERES AFECTOS'      => [],
        'OTROS HABERES'        => [],
        'DESCUENTOS LEGALES'   => [],
        'OTROS DESCUENTOS'     => [],
    ];
    $tot = ['hab'=>0,'desc'=>0];

    $info = [
        'Empresa'   => '',
        'Nombre'    => '', 'Cargo'     => '', 'Lugar'     => '', 'Ccosto'    => '',
        'Codigo'    => '', 'Rut'       => '', 'FechaIng'  => '', 'Dias'      => '',
        'Antig'     => '', 'Zona'      => '', 'Pago'      => '', 'Banco'     => '', 'Cta'       => ''
    ];

    $h = fopen($csvPath, 'r');
    if (!$h) return [$sections, $tot, $info];

    $first = fgets($h);
    if ($first === false) { fclose($h); return [$sections, $tot, $info]; }
    $delim = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';
    rewind($h);

    $header = fgetcsv($h, 0, $delim);
    $idx = [];
    foreach ($header as $i => $col) $idx[trim((string)$col)] = $i;

    $pick = function($row, $names) use ($idx) {
        foreach ($names as $n) {
            if (isset($idx[$n])) {
                $v = trim((string)($row[$idx[$n]] ?? ''));
                if ($v !== '') return $v;
            }
        }
        return '';
    };

    while (($row = fgetcsv($h, 0, $delim)) !== false) {
        $rCodigo = trim((string)($row[$idx['Codigo']] ?? ''));
        $rAmes   = trim((string)($row[$idx['Ames']] ?? ''));

        if ($rCodigo !== $rut) continue;
        if ($rAmes !== $ames) continue;

        if ($info['Empresa'] === '') $info['Empresa'] = $pick($row, ['Empresa']);

        if ($info['Dias'] === '') $info['Dias'] = $pick($row, ['DiasTrabajados','Dias','Días Trabajados']);
        if ($info['Nombre'] === '') $info['Nombre'] = $pick($row, ['Nomtra','Nombre']);

        $inform = strtoupper(trim((string)($row[$idx['Inform']] ?? '')));
        if ($inform !== 'N') continue;

        $tipo = trim((string)($row[$idx['Tipo']] ?? ''));
        $desc = trim((string)($row[$idx['Descitm']] ?? ''));
        $m    = term_row_monto($row, $idx);

        $vo = '';
        if (isset($idx['VO'])) $vo = trim((string)($row[$idx['VO']] ?? ''));
        elseif (isset($idx['V.O'])) $vo = trim((string)($row[$idx['V.O']] ?? ''));

        $rowOut = ['detalle'=>$desc, 'vo'=>$vo, 'hab'=>0, 'desc'=>0];

        if ($tipo === '1') { $rowOut['hab'] = $m; $sections['HABERES AFECTOS'][] = $rowOut; $tot['hab'] += $m; }
        elseif ($tipo === '2') { $rowOut['hab'] = $m; $sections['OTROS HABERES'][] = $rowOut; $tot['hab'] += $m; }
        elseif ($tipo === '3') { $rowOut['desc'] = $m; $sections['DESCUENTOS LEGALES'][] = $rowOut; $tot['desc'] += $m; }
        elseif ($tipo === '4') { $rowOut['desc'] = $m; $sections['OTROS DESCUENTOS'][] = $rowOut; $tot['desc'] += $m; }
    }
    fclose($h);

    foreach ($sections as $k => $arr) {
        usort($arr, function($a,$b){
            $am = ($b['hab'] ?: $b['desc']) <=> ($a['hab'] ?: $a['desc']);
            return $am ?: strcmp($a['detalle'], $b['detalle']);
        });
        $sections[$k] = $arr;
    }

    return [$sections, $tot, $info];
}

/* ==========================================================
   PROCESAMIENTO DATOS
   ========================================================== */

if (!$ruta || !is_file($ruta)) { echo 'Error: No hay archivo CSV.'; exit; }
if ($rut === '' || $ames === '') { echo 'Error: Faltan parámetros.'; exit; }

// 1. Datos Term
try {
    list($sections, $tot, $info) = build_sections_from_term_csv($ruta, $rut, $ames);
} catch (Exception $e) {
    die("Error CSV: " . $e->getMessage());
}

// 2. Datos BD (MySQL) - PROTECCIÓN CONTRA NULLS
if (class_exists('clsConexion')) {
    try {
        $db = new clsConexion();

        $rutBusqueda = str_replace('.', '', $rut);
        $rutSafe = $db->real_escape_string($rutBusqueda);

        $sql = "SELECT
                    Rut, Nombres, Apaterno, Amaterno,
                    `Descripcion Cargo` as Cargo,
                    `Descripcion Ubicacion` as Ubicacion,
                    `Descripcion Centro de Costo` as CC,
                    `Fecha de Ingreso` as FechaIngreso,
                    `Descripcion Zona Asignacion` as Zona,
                    `Descripcion Forma de Pago 1` as FormaPago,
                    `Descripcion Banco fpago1` as Banco,
                    `Cuenta Corriente fpago1` as Cta
                FROM adp_empleados
                WHERE Rut = '$rutSafe'
                LIMIT 1";

        $data = $db->consultar($sql);

        if (!empty($data)) {
            $row = $data[0];

            $nombreCompleto = trim((string)$row['Nombres'] . ' ' . (string)$row['Apaterno'] . ' ' . (string)$row['Amaterno']);

            $info['Nombre']   = $nombreCompleto;
            $info['Cargo']    = (string)$row['Cargo'];
            $info['Lugar']    = (string)$row['Ubicacion'];
            $info['Ccosto']   = (string)$row['CC'];
            $info['Rut']      = (string)$row['Rut'];
            $info['Codigo']   = (string)$row['Rut'];
            $info['FechaIng'] = (string)$row['FechaIngreso'];
            $info['Zona']     = (string)$row['Zona'];
            $info['Pago']     = (string)$row['FormaPago'];
            $info['Banco']    = (string)$row['Banco'];
            $info['Cta']      = (string)$row['Cta'];

            $info['Antig'] = calcular_antiguedad($row['FechaIngreso'], $ames);
        }

    } catch (Exception $e) {
        // Fallo silencioso BD
    }
}

// Formateo nombre
$info['Nombre'] = format_nombre_persona($info['Nombre']);
$nombre = format_nombre_persona($nombre);

// Empresa dinámica
$empresaData = get_empresa_data($info['Empresa']);

// Variables empresa
$EMPRESA_NOMBRE = $empresaData['NOMBRE'];
$EMPRESA_RUT    = $empresaData['RUT'];
$EMPRESA_DIR    = $empresaData['DIR'];
$LOGO_PATH      = $empresaData['LOGO'];

$footerNombre = ($info['Nombre'] !== '') ? $info['Nombre'] : $nombre;
$footerAntig  = ($info['Antig']  !== '') ? $info['Antig']  : $antig;
$footerZona   = ($info['Zona']   !== '') ? $info['Zona']   : $zona;
$footerPago   = ($info['Pago']   !== '') ? $info['Pago']   : $pago;
$footerBanco  = ($info['Banco']  !== '') ? $info['Banco']  : $banco;
$footerCta    = ($info['Cta']    !== '') ? $info['Cta']    : $cta;

/* ==========================================================
   GENERACIÓN DEL PDF
   ========================================================== */

function pdf_section_header($pdf, $x, $w, $h, $label) {
    $pdf->SetFillColor(248,248,248);
    $pdf->SetX($x);
    $pdf->SetFont('Times','B',9);
    $pdf->Cell($w['det']+$w['vo']+$w['hab']+$w['desc'], $h, utf8_decode((string)$label), 1, 1, 'L', true);
    $pdf->SetFont('Arial','',10);
}

function pdf_row_item($pdf, $x, $w, $h, $row, $borders='') {
    $pdf->SetX($x);
    $pdf->Cell($w['det'],  $h, utf8_decode((string)$row['detalle']), $borders, 0, 'L');
    $pdf->Cell($w['vo'],   $h, $row['vo']!=='' ? (string)$row['vo'] : '', $borders, 0, 'C');
    $pdf->Cell($w['hab'],  $h, $row['hab'] ? fmt_clp($row['hab']) : '', $borders, 0, 'R');
    $pdf->Cell($w['desc'], $h, $row['desc']? fmt_clp($row['desc']) : '', $borders, 1, 'R');
}

class MyPDF extends FPDF {
    public $empresaNombre,$empresaRut,$empresaDir,$logoPath,$remuneracion,$periodo;
    public $footerNombre,$footerAntig,$footerZona,$footerPago,$footerBanco,$footerCta;
    public $hdrNombre='',$hdrCargo='',$hdrLugar='',$hdrCcosto='';
    public $hdrCodigo='',$hdrRut='',$hdrFechaIng='',$hdrDias='';

    public $logoX = 2, $logoY = -8, $logoW = 22;

    // >>> NUEVO: Texto línea 1 del recuadro (Mes / Día25)
    public $remunLabel = 'Remuneración del mes';

    function RoundedRect($x, $y, $w, $h, $r, $style='') {
        $k = $this->k; $hp = $this->h;
        $op = ($style=='F') ? 'f' : (($style=='FD' || $style=='DF') ? 'B' : 'S');
        $MyArc = 4/3 * (M_SQRT2 - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x+$r)*$k, ($hp-$y)*$k ));
        $this->_out(sprintf('%.2F %.2F l', ($x+$w-$r)*$k, ($hp-$y)*$k ));
        $this->_Arc($x+$w-$r+$r*$MyArc, $y, $x+$w, $y+$r-$r*$MyArc, $x+$w, $y+$r);
        $this->_out(sprintf('%.2F %.2F l', ($x+$w)*$k, ($hp-($y+$h-$r))*$k ));
        $this->_Arc($x+$w, $y+$h-$r+$r*$MyArc, $x+$w-$r+$r*$MyArc, $y+$h, $x+$w-$r, $y+$h);
        $this->_out(sprintf('%.2F %.2F l', ($x+$r)*$k, ($hp-($y+$h))*$k ));
        $this->_Arc($x+$r-$r*$MyArc, $y+$h, $x, $y+$h-$r+$r*$MyArc, $x, $y+$h-$r);
        $this->_out(sprintf('%.2F %.2F l', $x*$k, ($hp-($y+$r))*$k ));
        $this->_Arc($x, $y+$r-$r*$MyArc, $x+$r-$r*$MyArc, $y, $x+$r, $y);
        $this->_out($op);
    }
    function _Arc($x1, $y1, $x2, $y2, $x3, $y3) {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ',
        $x1*$this->k, ($h-$y1)*$this->k, $x2*$this->k, ($h-$y2)*$this->k, $x3*$this->k, ($h-$y3)*$this->k));
    }
    function LM() { return $this->lMargin; }
    function RM() { return $this->rMargin; }
    function PW() { return $this->w; }
    function PH() { return $this->h; }

    function Header() {
        $L = $this->lMargin; $R = $this->rMargin; $T = 12;

        if (is_file($this->logoPath)) $this->Image($this->logoPath, $L + $this->logoX, $T + $this->logoY, $this->logoW);

        $this->SetXY($L + 28, $T-2.7);
        $this->SetFont('Times','B',9);
        $this->Cell(100, 5, utf8_decode((string)$this->empresaNombre), 0, 2, 'L');
        $this->Cell(100, 5, utf8_decode('Rut: ') . $this->empresaRut, 0, 2, 'L');
        $this->Cell(100, 5, utf8_decode((string)$this->empresaDir), 0, 2, 'L');

        $boxW = 55; $boxH = 16; $r = 2;
        $boxX = $this->w - $this->rMargin - $boxW; $boxY = 9;
        $this->RoundedRect($boxX, $boxY, $boxW, $boxH, $r, '');
        $this->Line($boxX, $boxY + ($boxH/2), $boxX + $boxW, $boxY + ($boxH/2));

        // >>> CAMBIO: primera línea del recuadro depende del combo (mes / dia25)
        $this->SetFont('Arial','',11.2); $this->SetXY($boxX, $boxY + 2);
        $this->Cell($boxW, 5, utf8_decode((string)$this->remunLabel), 0, 2, 'C');

        $this->SetXY($boxX, $boxY + ($boxH/2) + 1.5);
        $this->Cell($boxW, 5, utf8_decode((string)$this->periodo), 0, 2, 'C');

        $this->SetFont('Courier','B',11); $this->SetXY($L, $T + 15);
        $this->Cell($this->w - $L - $R, 7, utf8_decode('LIQUIDACION DE REMUNERACIONES'), 0, 2, 'C');

        $bx = $L; $bw = $this->w - $L - $R; $by = $T + 22; $bh = 32; $rr = 3;
        $this->RoundedRect($bx, $by, $bw, $bh, $rr, '');

        $pad = 3; $leftX = $bx + $pad; $rightX = $bx + ($bw/2) + $pad + 25; $yRow = $by + 3; $sep = 7;

        $linesL = [
            ['lbl'=>'Nombre:',        'val'=>$this->hdrNombre],
            ['lbl'=>'Cargo:',         'val'=>$this->hdrCargo],
            ['lbl'=>'L. de trabajo:', 'val'=>$this->hdrLugar],
            ['lbl'=>'C. de costo:',   'val'=>$this->hdrCcosto]
        ];
        foreach($linesL as $i=>$item){
            $this->SetXY($leftX, $yRow + $sep*$i);
            if ($i == 0) { $wLbl = 28; }
            elseif ($i == 2) { $wLbl = 28; }
            elseif ($i == 3) { $wLbl = 28; }
            else { $wLbl = 28; }

            $this->SetFont('Times','B',10);
            $this->Cell($wLbl, 6, utf8_decode($item['lbl']), 0, 0, 'L');

            $this->SetFont('Arial','',10);
            $this->Cell(10, 6, utf8_decode((string)$item['val']), 0, 0, 'L');
        }

        $linesR = [
            ['lbl'=>'Código:',          'val'=>$this->hdrCodigo],
            ['lbl'=>'Rut:',             'val'=>$this->hdrRut],
            ['lbl'=>'Fecha ingreso:',   'val'=>$this->hdrFechaIng],
            ['lbl'=>'Días trabajados:', 'val'=>$this->hdrDias]
        ];
        foreach($linesR as $i=>$item){
            $this->SetXY($rightX, $yRow + $sep*$i);

            if ($i == 0) { $wLbl = 31; }
            elseif ($i == 1) { $wLbl = 31; }
            elseif ($i == 2) { $wLbl = 31; }
            else { $wLbl = 31; }

            $this->SetFont('Times','B',10);
            $this->Cell($wLbl, 6, utf8_decode($item['lbl']), 0, 0, 'L');

            $this->SetFont('Arial','',10);
            $this->Cell(10, 6, utf8_decode((string)$item['val']), 0, 0, 'L');
        }

        $tableY = $by + $bh + 1.5;
        $this->SetFont('Times','B',10); $this->SetFillColor(245,245,245); $this->SetXY($L, $tableY);
        $totalW = $this->w - $L - $R;
        $this->Cell($totalW * 0.54, 8, utf8_decode('Detalle'), 1, 0, 'C', true);
        $this->Cell($totalW * 0.10, 8, 'V.O', 1, 0, 'C', true);
        $this->Cell($totalW * 0.18, 8, utf8_decode('Haberes'), 1, 0, 'C', true);
        $this->Cell($totalW * 0.18, 8, utf8_decode('Descuentos'), 1, 1, 'C', true);
    }

    function Footer() {
        $L = $this->lMargin; $R = $this->rMargin;
        $yTop = $this->h - 70 + 6; $boxH = 46;
        $this->Rect($L, $yTop, $this->w - $L - $R, $boxH, 'D');

        $this->SetXY($L+4, $yTop+2); $this->SetFont('Arial','',9);
        $this->MultiCell($this->w-$L-$R-8, 4, utf8_decode("Certifico haber recibido en este acto a mi entera satisfacción, el total de haberes en la presente liquidación.Asimismo, declaro que nada se me adeuda y no tener reclamo alguno en contra de la empresa MUELLAJE DEL MAIPO S.A., por concepto de Remuneraciones."),0,'L');

        $yData = $this->GetY() + 4; $sep=4;
        $footerItems = [
            'Nombre' => $this->footerNombre,
            'Antigüedad' => $this->footerAntig,
            'Zona Extrema' => $this->footerZona,
            'Forma de Pago' => $this->footerPago,
            'Banco' => $this->footerBanco,
            'N° cta cte' => $this->footerCta
        ];

        $i=0;
        foreach($footerItems as $lbl=>$val){
            $this->SetXY($L+5, $yData + ($i*$sep));
            $this->SetFont('Times','B',9); $this->Cell(38, 4, utf8_decode((string)$lbl.':'), 0, 0, 'L');
            $this->SetFont('Arial','',9);  $this->Cell(100, 4, utf8_decode((string)$val), 0, 0, 'L');
            $i++;
        }

        $fx = $this->w - $R - 78; $fy = $yData + 23;
        $this->Line($fx, $fy, $fx + 70, $fy);
        $this->SetXY($fx, $fy); $this->SetFont('Times','B',9);
        $this->Cell(70, 4.5, utf8_decode('Firma Trabajador'), 0, 1, 'C');

        $this->SetY(-12); $this->SetFont('Arial','',9);
        $this->Cell(0, 6, utf8_decode('Página ') . $this->PageNo() . utf8_decode(' de {nb}'), 0, 0, 'R');
    }
}

// Configurar instancia PDF
$pdf = new MyPDF('P','mm','Letter');
$pdf->AliasNbPages();
$bottomReserve = 70;
$pdf->SetAutoPageBreak(true, $bottomReserve);

$pdf->empresaNombre = $EMPRESA_NOMBRE;
$pdf->empresaRut    = $EMPRESA_RUT;
$pdf->empresaDir    = $EMPRESA_DIR;
$pdf->logoPath      = $LOGO_PATH;

// Pasar medidas logo por empresa (sin romper)
$pdf->logoX = (float)($empresaData['LOGO_X'] ?? 2);
$pdf->logoY = (float)($empresaData['LOGO_Y'] ?? -8);
$pdf->logoW = (float)($empresaData['LOGO_W'] ?? 22);

$pdf->periodo       = month_label($ames);

// >>> NUEVO: set del label según sesión (Mes/Día25)
$pdf->remunLabel = ($REMUN_TIPO === 'dia25') ? 'REMUN. DIA 25' : 'Remuneración del mes';

// Llenar datos Header
$pdf->hdrNombre   = $info['Nombre'];
$pdf->hdrCargo    = $info['Cargo'];
$pdf->hdrLugar    = $info['Lugar'];
$pdf->hdrCcosto   = $info['Ccosto'];
$pdf->hdrCodigo   = $info['Rut'];
$pdf->hdrRut      = $info['Rut'];
$pdf->hdrFechaIng = $info['FechaIng'];
$pdf->hdrDias     = $info['Dias'];

// Llenar datos Footer
$pdf->footerNombre  = $footerNombre;
$pdf->footerAntig   = $footerAntig;
$pdf->footerZona    = $footerZona;
$pdf->footerPago    = $footerPago;
$pdf->footerBanco   = $footerBanco;
$pdf->footerCta     = $footerCta;

$pdf->SetTitle($filename, true);   // true = UTF-8

$pdf->AddPage();

$L = $pdf->LM(); $R = $pdf->RM();
$totalW  = $pdf->PW() - $L - $R;
$w = ['det'=>$totalW*0.54, 'vo'=>$totalW*0.10, 'hab'=>$totalW*0.18, 'desc'=>$totalW*0.18];

$orden = ['HABERES AFECTOS','OTROS HABERES','DESCUENTOS LEGALES','OTROS DESCUENTOS'];
foreach ($orden as $sec) {
    if (empty($sections[$sec])) continue;
    pdf_section_header($pdf, $L, $w, 5, $sec);
    foreach ($sections[$sec] as $row) {
        if ($pdf->GetY() > ($pdf->PH() - $bottomReserve - 8)) {
            $pdf->AddPage();
            pdf_section_header($pdf, $L, $w, 5, $sec);
        }
        pdf_row_item($pdf, $L, $w, 4, $row, 'LR');
    }
}

// Totales
$tot_hab    = $tot['hab'];
$tot_desc   = $tot['desc'];
$neto_pagar = $tot_hab - $tot_desc;

$pdf->SetX($L); $pdf->SetFont('Times','B',11); $pdf->SetFillColor(255,255,255);
$pdf->Cell($w['det'] + $w['vo'], 9, utf8_decode('TOTALES'), 1, 0, 'L', false);
$pdf->Cell($w['hab'],  9, fmt_clp($tot_hab),  1, 0, 'R', false);
$pdf->Cell($w['desc'], 9, fmt_clp($tot_desc), 1, 1, 'R', false);

$pdf->SetX($L); $pdf->SetFillColor(240,240,240);
$pdf->Cell($w['det'] + $w['vo'] + $w['hab'], 9, utf8_decode('TOTAL A PAGAR'), 1, 0, 'L', true);
$pdf->SetFont('Arial','B',11);
$pdf->Cell($w['desc'], 9, fmt_clp($neto_pagar), 1, 1, 'R', true);

$pdf->SetX($L); $pdf->SetFont('Times','B',11);
$pdf->Cell(10, 9, utf8_decode('Son:'), 1, 0, 'L');
$pdf->SetFont('Arial','',9);
$pdf->Cell($totalW - 10, 9, utf8_decode(ucfirst(numero_a_letras($neto_pagar))), 1, 1, 'L');

// Limpiar buffer
while (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }

// >>> NUEVO: nombre archivo Liq_{Mes|Dia25}_{MesNombre}_{Rut}.pdf
$mesNombre = month_label($ames); // Ej: "Octubre del 2025"
$mesNombre = trim(str_replace('del ', '', $mesNombre)); // "Octubre 2025"
$mesNombre = preg_replace('/\s+/u', '_', $mesNombre);   // "Octubre_2025"

$rutFile = preg_replace('/[^0-9Kk]/', '', (string)$rut); // deja solo 0-9 y K
$rutFile = strtoupper($rutFile);

$tipoFile = ($REMUN_TIPO === 'dia25') ? 'Dia25' : 'Mes';

$filename = "Liq_{$tipoFile}_{$mesNombre}_{$rutFile}.pdf";
// Forzar nombre también en vista previa (inline)
header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $filename . '"');
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($filename));

$pdf->Output('I', $filename);
exit;

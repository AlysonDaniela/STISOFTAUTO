<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    echo "Este script debe ejecutarse desde CLI.\n";
    exit(1);
}

$filePath = null;
$enqueueOnly = false;
$drainOnly = false;
$maxSend = 5;
$requeueErrors = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = (string)$argv[$i];
    if ($arg === '--enqueue-only') {
        $enqueueOnly = true;
        continue;
    }
    if ($arg === '--drain-only') {
        $drainOnly = true;
        continue;
    }
    if (strpos($arg, '--max-send=') === 0) {
        $maxSend = max(1, min(100, (int)substr($arg, 11)));
        continue;
    }
    if ($arg === '--requeue-errors') {
        $requeueErrors = true;
        continue;
    }
    if ($filePath === null) {
        $filePath = $arg;
    }
}

if (!$drainOnly && ($filePath === null || trim($filePath) === '')) {
    echo "Uso: php process_cambios.php /ruta/al/archivo.csv [--enqueue-only] [--max-send=5]\n";
    echo "   o: php process_cambios.php --drain-only [--max-send=5] [--requeue-errors]\n";
    exit(1);
}

if ($filePath !== null && !is_file($filePath)) {
    echo "Archivo no encontrado: {$filePath}\n";
    exit(1);
}

require_once __DIR__ . '/../conexion/db.php';
require_once __DIR__ . '/../includes/runtime_config.php';
$db = new clsConexion();

$bukCfg = runtime_buk_config();
define('BUK_API_BASE', $bukCfg['base']);
define('BUK_TOKEN', $bukCfg['token']);
const LOG_DIR = __DIR__ . '/../logs/cambios';
const FALLBACK_BOSS_RUT = '15871627-5';

if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);

function str_norm($v): string {
    $v = is_null($v) ? '' : (string)$v;
    $v = trim($v);
    $v = preg_replace('/\s+/', ' ', $v);
    return $v;
}

function str_norm_db($v): string {
    $v = is_null($v) ? '' : (string)$v;
    $v = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $v);
    $v = trim($v);
    $v = preg_replace('/\s+/', ' ', $v);
    return $v;
}

function dbv(array $row, array $candidates, string $default = ''): string {
    foreach ($candidates as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null) {
            return str_norm_db($row[$k]);
        }
    }
    return $default;
}

function rut_norm(string $rut): string {
    $rut = strtoupper(str_norm($rut));
    $rut = str_replace(["-", "–", "—", "−"], "", $rut);
    $rut = str_replace([".", " ", "\t", "\r", "\n", "\xC2\xA0"], "", $rut);
    return preg_replace('/[^0-9K]/', '', $rut);
}

function rut_base_from_norm(string $rutNorm): string {
    if ($rutNorm === '') return '';
    if (strlen($rutNorm) >= 2) return preg_replace('/\D/', '', substr($rutNorm, 0, -1));
    return preg_replace('/\D/', '', $rutNorm);
}

function detect_delimiter(string $filePath): string {
    $delims = ["," => 0, ";" => 0, "\t" => 0, "|" => 0];
    $line = '';
    $fh = fopen($filePath, 'r');
    if ($fh) { $line = (string)fgets($fh); fclose($fh); }
    foreach ($delims as $d => $_) $delims[$d] = substr_count($line, $d);
    arsort($delims);
    return (string)array_key_first($delims);
}

function header_key(string $s): string {
    $s = trim($s, " \t\n\r\0\x0B\xEF\xBB\xBF");
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = trim($s, '_');

    $map = [
        'rut'=>'rut','r_u_t'=>'rut','rut_empleado'=>'rut','rutbase'=>'rut','rut_base'=>'rut',
        'estado'=>'estado','status'=>'estado',
        'nombres'=>'nombres','nombre'=>'nombres',
        'apaterno'=>'apaterno','apellido_paterno'=>'apaterno','paterno'=>'apaterno',
        'amaterno'=>'amaterno','apellido_materno'=>'amaterno','materno'=>'amaterno',
        'sexo'=>'sexo',
        'mail'=>'mail','email'=>'mail','correo'=>'mail',
        'fecha_nacimiento'=>'fecha_nacimiento','fecha_de_nacimiento'=>'fecha_nacimiento',
        'fecha_de_ingreso'=>'fecha_de_ingreso','fecha_ingreso'=>'fecha_de_ingreso',
        'descripcion_cargo'=>'descripcion_cargo',
        'codigo_afp'=>'codigo_afp',
        'descripcion_afp'=>'descripcion_afp',
        'descripcion_isapre'=>'descripcion_isapre',
        'fecha_seguro_cesantia'=>'fecha_seguro_cesantia',
        'descripcion_forma_de_pago_1'=>'descripcion_forma_de_pago_1',
        'descripcion_banco_fpago1'=>'descripcion_banco_fpago1',
        'cta_corriente_fpago1'=>'cta_corriente_fpago1',
        'descripcion_sindicato'=>'descripcion_sindicato',
        'descripcion_categoria'=>'descripcion_categoria',
        'leader'=>'leader','lider'=>'leader','jefe'=>'leader','supervisor'=>'leader','manager'=>'leader','encargado'=>'leader',
    ];
    return $map[$s] ?? $s;
}

function read_csv_rows(string $filePath): array {
    $rows = [];
    $delimiter = detect_delimiter($filePath);
    $h = fopen($filePath, 'r');
    if (!$h) return [null, []];

    $rawHeader = fgetcsv($h, 0, $delimiter);
    if (!$rawHeader) { fclose($h); return [null, []]; }

    $header = array_map(fn($c) => header_key((string)$c), $rawHeader);
    $lineNo = 1;
    while (($data = fgetcsv($h, 0, $delimiter)) !== false) {
        $lineNo++;
        if (!$data || (count($data) === 1 && trim((string)$data[0]) === '')) continue;
        if (count($data) < count($header)) $data = array_pad($data, count($header), null);
        if (count($data) > count($header)) $data = array_slice($data, 0, count($header));

        $row = ['__line' => $lineNo];
        foreach ($header as $i => $col) {
            if ($col === '') continue;
            $row[$col] = $data[$i] ?? null;
        }
        $rows[] = $row;
    }
    fclose($h);
    return [$header, $rows];
}

function to_ymd($date): ?string {
    $date = str_norm($date);
    if ($date === '') return null;
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $date, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return $date;
    if (preg_match('~^\d{8}$~', $date)) return substr($date,0,4) . '-' . substr($date,4,2) . '-' . substr($date,6,2);
    return null;
}

function map_payment_method($v): ?string {
    $v = strtoupper(str_norm($v));
    if ($v === '') return null;
    if (strpos($v, 'TRANSFER') !== false) return 'Transferencia Bancaria';
    if (strpos($v, 'CHEQUE') !== false) return 'Cheque';
    if (strpos($v, 'SERVIPAG') !== false) return 'Servipag';
    if (strpos($v, 'VALE VISTA') !== false || strpos($v, 'VALEVISTA') !== false) return 'Vale Vista';
    if (strpos($v, 'NO GENER') !== false) return 'No Generar Pago';
    return null;
}

function map_bank($v): ?string {
    $v = strtoupper(str_norm($v));
    if ($v === '') return null;
    if (strpos($v, 'BANCO ESTADO') !== false || $v === 'ESTADO') return 'Banco Estado';
    if (strpos($v, 'SCOTIA') !== false) return 'Scotiabank';
    if (strpos($v, 'FALABELLA') !== false) return 'Falabella';
    if (strpos($v, 'BCI') !== false) return 'BCI';
    if (strpos($v, 'CORPBANCA') !== false) return 'Corpbanca';
    if (strpos($v, 'SANTANDER') !== false) return 'Santander';
    if (strpos($v, 'BANCO DE CHILE') !== false || $v === 'CHILE') return 'Banco de Chile';
    if (strpos($v, 'BBVA') !== false) return 'BBVA';
    if (strpos($v, 'ITAU') !== false) return 'Itau';
    if (strpos($v, 'RIPLEY') !== false) return 'Ripley';
    if (strpos($v, 'SECURITY') !== false) return 'Security';
    if (strpos($v, 'BICE') !== false) return 'BICE';
    return null;
}

function map_fund_quote($v): ?string {
    $v = strtolower(str_norm($v));
    if ($v === '') return null;
    if (strpos($v, 'capital') !== false) return 'capital';
    if (strpos($v, 'cuprum') !== false) return 'cuprum';
    if (strpos($v, 'habitat') !== false) return 'habitat';
    if (strpos($v, 'modelo') !== false) return 'modelo';
    if (strpos($v, 'planvital') !== false || strpos($v, 'plan vital') !== false) return 'planvital';
    if (strpos($v, 'provida') !== false || strpos($v, 'pro vida') !== false) return 'provida';
    if (strpos($v, 'uno') !== false) return 'uno';
    return null;
}

function map_health_company($v): ?string {
    $v = strtolower(str_norm($v));
    if ($v === '') return null;
    if (strpos($v, 'fonasa') !== false) return 'fonasa';
    if (strpos($v, 'consalud') !== false) return 'consalud';
    if (strpos($v, 'colmena') !== false) return 'colmena';
    if (strpos($v, 'banmedica') !== false) return 'banmedica';
    if (strpos($v, 'cruz') !== false && strpos($v, 'blanca') !== false) return 'cruz_blanca';
    if (strpos($v, 'nueva') !== false && strpos($v, 'masvida') !== false) return 'nueva_masvida';
    if (strpos($v, 'vida') !== false && strpos($v, 'tres') !== false) return 'vida_tres';
    return null;
}

function save_log(string $event, array $data = []): void {
    $row = ['ts' => date('c'), 'event' => $event, 'data' => $data];
    $file = LOG_DIR . '/cambios_' . date('Ymd') . '.jsonl';
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
}

function buk_request(string $method, string $path, array $payload): array {
    $url = rtrim(BUK_API_BASE, '/') . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'auth_token: ' . BUK_TOKEN,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$http, (string)$body, (string)$err, $url];
}

function buk_patch(string $path, array $payload): array {
    return buk_request('PATCH', $path, $payload);
}

function buk_post(string $path, array $payload): array {
    return buk_request('POST', $path, $payload);
}

function buk_get(string $path): array {
    $url = rtrim(BUK_API_BASE, '/') . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'auth_token: ' . BUK_TOKEN,
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$http, (string)$body, (string)$err, $url];
}

function db_rut_expr(mysqli $cn, string $field = 'Rut'): string {
    static $useRegex = null;
    if ($useRegex === null) {
        $useRegex = false;
        $test = @$cn->query("SELECT REGEXP_REPLACE('A-1', '[^0-9K]', '') AS x");
        if ($test) {
            $useRegex = true;
            $test->free();
        }
    }
    if ($useRegex) return "REGEXP_REPLACE(UPPER(`{$field}`), '[^0-9K]', '')";

    $expr = "UPPER(`{$field}`)";
    $expr = "REPLACE({$expr}, '.', '')";
    $expr = "REPLACE({$expr}, ' ', '')";
    $expr = "REPLACE({$expr}, '-', '')";
    $expr = "REPLACE({$expr}, '–', '')";
    $expr = "REPLACE({$expr}, '—', '')";
    $expr = "REPLACE({$expr}, '−', '')";
    $expr = "REPLACE({$expr}, CHAR(9), '')";
    $expr = "REPLACE({$expr}, CHAR(10), '')";
    $expr = "REPLACE({$expr}, CHAR(13), '')";
    return $expr;
}

function db_get_emp_by_rut_active(mysqli $cn, string $rut): ?array {
    $norm = rut_norm($rut);
    $base = rut_base_from_norm($norm);
    if ($norm === '' && $base === '') return null;

    $expr = db_rut_expr($cn, 'Rut');
    $sql = "SELECT * FROM adp_empleados WHERE (`Estado`='A') AND ({$expr} = ? OR {$expr} = ?) LIMIT 1";
    $stmt = $cn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('ss', $norm, $base);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function db_get_emp_by_rut_any(mysqli $cn, string $rut): ?array {
    $norm = rut_norm($rut);
    $base = rut_base_from_norm($norm);
    if ($norm === '' && $base === '') return null;

    $expr = db_rut_expr($cn, 'Rut');
    $sql = "SELECT * FROM adp_empleados WHERE ({$expr} = ? OR {$expr} = ?) LIMIT 1";
    $stmt = $cn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('ss', $norm, $base);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function db_get_emp_min_by_rut(mysqli $cn, string $rut): ?array {
    $norm = rut_norm($rut);
    $base = rut_base_from_norm($norm);
    if ($norm === '' && $base === '') return null;

    $expr = db_rut_expr($cn, 'Rut');
    $sql = "SELECT Rut, Estado, buk_emp_id FROM adp_empleados WHERE ({$expr} = ? OR {$expr} = ?) LIMIT 1";
    $stmt = $cn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('ss', $norm, $base);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function resolve_leader_id_for_cambios(mysqli $cn, string $leaderRutRaw): array {
    $bossRut = trim($leaderRutRaw);
    if ($bossRut === '') {
        $bossRut = FALLBACK_BOSS_RUT;
    }

    $boss = db_get_emp_min_by_rut($cn, $bossRut);
    if (!$boss) {
        $fallback = db_get_emp_min_by_rut($cn, FALLBACK_BOSS_RUT);
        if (!$fallback || (int)($fallback['buk_emp_id'] ?? 0) <= 0) {
            return ['ok' => false, 'leader_id' => null, 'msg' => "No se pudo resolver jefe {$leaderRutRaw} ni fallback."];
        }
        return ['ok' => true, 'leader_id' => (int)$fallback['buk_emp_id'], 'msg' => "Jefe {$leaderRutRaw} no encontrado, usando fallback."];
    }

    if ((string)($boss['Estado'] ?? '') !== 'A') {
        $fallback = db_get_emp_min_by_rut($cn, FALLBACK_BOSS_RUT);
        if (!$fallback || (int)($fallback['buk_emp_id'] ?? 0) <= 0) {
            return ['ok' => false, 'leader_id' => null, 'msg' => "Jefe {$leaderRutRaw} inactivo y fallback no disponible."];
        }
        return ['ok' => true, 'leader_id' => (int)$fallback['buk_emp_id'], 'msg' => "Jefe {$leaderRutRaw} inactivo, usando fallback."];
    }

    $leaderId = (int)($boss['buk_emp_id'] ?? 0);
    if ($leaderId <= 0) {
        return ['ok' => false, 'leader_id' => null, 'msg' => "Jefe {$leaderRutRaw} sin buk_emp_id."];
    }

    return ['ok' => true, 'leader_id' => $leaderId, 'msg' => 'Jefe resuelto correctamente.'];
}

function build_changes_for_row(array $csvRow, array $dbRow): array {
    $csvEstado = str_norm($csvRow['estado'] ?? '');
    if ($csvEstado !== '' && strtoupper($csvEstado) !== 'A') {
        return ['skip' => "Estado en CSV distinto de A ('{$csvEstado}')"];
    }

    $bukEmpId = (int)($dbRow['buk_emp_id'] ?? 0);
    $bukPlanId = (int)($dbRow['buk_plan_id'] ?? 0);
    $bukJobId = (int)($dbRow['buk_job_id'] ?? 0);
    if ($bukEmpId <= 0) return ['skip' => 'Sin buk_emp_id en BD (no se puede actualizar)'];

    $diffs = ['PROFILE'=>[], 'PLAN'=>[], 'JOB'=>[], 'DB_UPDATES'=>[], 'meta'=>[], 'warnings'=>[]];

    $newFirst = str_norm($csvRow['nombres'] ?? '');
    $newSur = str_norm($csvRow['apaterno'] ?? '');
    $newSur2 = str_norm($csvRow['amaterno'] ?? '');
    if ($newFirst !== '' && str_norm_db($dbRow['Nombres'] ?? '') !== $newFirst) { $diffs['PROFILE']['first_name'] = $newFirst; $diffs['DB_UPDATES']['Nombres'] = $newFirst; }
    if ($newSur !== '' && str_norm_db($dbRow['Apaterno'] ?? '') !== $newSur) { $diffs['PROFILE']['surname'] = $newSur; $diffs['DB_UPDATES']['Apaterno'] = $newSur; }
    if ($newSur2 !== '' && str_norm_db($dbRow['Amaterno'] ?? '') !== $newSur2) { $diffs['PROFILE']['second_surname'] = $newSur2; $diffs['DB_UPDATES']['Amaterno'] = $newSur2; }

    $newGender = strtoupper(str_norm($csvRow['sexo'] ?? ''));
    if (in_array($newGender, ['M','F'], true) && str_norm_db($dbRow['Sexo'] ?? '') !== $newGender) { $diffs['PROFILE']['gender'] = $newGender; $diffs['DB_UPDATES']['Sexo'] = $newGender; }

    $newEmail = str_norm($csvRow['mail'] ?? '');
    $oldEmail = str_norm_db($dbRow['Mail'] ?? '');
    if ($newEmail !== '' && strtoupper($newEmail) !== strtoupper($oldEmail)) { $diffs['PROFILE']['email'] = $newEmail; $diffs['DB_UPDATES']['Mail'] = $newEmail; }

    $newBirth = to_ymd($csvRow['fecha_nacimiento'] ?? '');
    $oldBirth = to_ymd($dbRow['Fecha Nacimiento'] ?? '');
    if ($newBirth && $newBirth !== $oldBirth) { $diffs['PROFILE']['birthday'] = $newBirth; $diffs['DB_UPDATES']['Fecha Nacimiento'] = $newBirth; }

    $newPayMethodRaw = str_norm_db($csvRow['descripcion_forma_de_pago_1'] ?? '');
    $oldPayMethodRaw = dbv($dbRow, ['Descripcion Forma de Pago 1', 'Descripción Forma de Pago 1']);
    if ($newPayMethodRaw !== '' && $newPayMethodRaw !== $oldPayMethodRaw) {
        $mapped = map_payment_method($newPayMethodRaw);
        if ($mapped) { $diffs['PROFILE']['payment_method'] = $mapped; $diffs['DB_UPDATES']['Descripcion Forma de Pago 1'] = $newPayMethodRaw; }
        else $diffs['warnings'][] = "Forma de pago sin mapeo Buk: '{$newPayMethodRaw}'";
    }

    $newBankRaw = str_norm_db($csvRow['descripcion_banco_fpago1'] ?? '');
    $oldBankRaw = dbv($dbRow, ['Descripcion Banco fpago1', 'Descripción Banco fpago1']);
    if ($newBankRaw !== '' && $newBankRaw !== $oldBankRaw) {
        $mapped = map_bank($newBankRaw);
        if ($mapped) { $diffs['PROFILE']['bank'] = $mapped; $diffs['DB_UPDATES']['Descripcion Banco fpago1'] = $newBankRaw; }
        else $diffs['warnings'][] = "Banco sin mapeo Buk: '{$newBankRaw}'";
    }

    $newAcc = str_norm_db($csvRow['cta_corriente_fpago1'] ?? '');
    $oldAcc = dbv($dbRow, ['Cta Corriente fpago1']);
    if ($newAcc !== '' && $newAcc !== $oldAcc) { $diffs['PROFILE']['account_number'] = $newAcc; $diffs['DB_UPDATES']['Cta Corriente fpago1'] = $newAcc; }

    $custom = [];
    $newSindAnt = str_norm_db($csvRow['descripcion_sindicato'] ?? '');
    $oldSindAnt = dbv($dbRow, ['Descripción Sindicato', 'Descripcion Sindicato']);
    if ($newSindAnt !== '' && $newSindAnt !== $oldSindAnt) { $custom['Sindicato Anterior'] = $newSindAnt; $diffs['DB_UPDATES']['Descripción Sindicato'] = $newSindAnt; }

    $newSindAct = str_norm_db($csvRow['descripcion_categoria'] ?? '');
    $oldSindAct = dbv($dbRow, ['Descripción Categoria', 'Descripcion Categoria']);
    if ($newSindAct !== '' && $newSindAct !== $oldSindAct) { $custom['Sindicato Actual'] = $newSindAct; $diffs['DB_UPDATES']['Descripción Categoria'] = $newSindAct; }

    $leader = str_norm_db($csvRow['leader'] ?? '');
    if (!empty($custom)) $diffs['PROFILE']['custom_attributes'] = $custom;

    if ($bukPlanId > 0) {
        $newAfpDesc = str_norm_db($csvRow['descripcion_afp'] ?? '');
        $oldAfpDesc = dbv($dbRow, ['Descripcion AFP', 'Descripción AFP']);
        if ($newAfpDesc !== '' && $newAfpDesc !== $oldAfpDesc) {
            $mapped = map_fund_quote($newAfpDesc);
            if ($mapped) { $diffs['PLAN']['fund_quote'] = $mapped; $diffs['DB_UPDATES']['Descripcion AFP'] = $newAfpDesc; }
            else $diffs['warnings'][] = "AFP sin mapeo fund_quote: '{$newAfpDesc}'";
        }

        $newIsapre = str_norm_db($csvRow['descripcion_isapre'] ?? '');
        $oldIsapre = dbv($dbRow, ['Descripcion Isapre', 'Descripción Isapre']);
        if ($newIsapre !== '' && $newIsapre !== $oldIsapre) {
            $mapped = map_health_company($newIsapre);
            if ($mapped) { $diffs['PLAN']['health_company'] = $mapped; $diffs['DB_UPDATES']['Descripcion Isapre'] = $newIsapre; }
            else $diffs['warnings'][] = "Isapre sin mapeo health_company: '{$newIsapre}'";
        }

        $newAfcDate = str_norm_db($csvRow['fecha_seguro_cesantia'] ?? '');
        $oldAfcDate = dbv($dbRow, ['Fecha Seguro Cesantia', 'Fecha Seguro Cesantía']);
        if ($newAfcDate !== '' && $newAfcDate !== $oldAfcDate) { $diffs['PLAN']['afc'] = 'normal'; $diffs['DB_UPDATES']['Fecha Seguro Cesantia'] = $newAfcDate; }

        // Para evitar rechazos de BUK por vigencia de plan, enviamos start_date cuando hay cambios de PLAN.
        if (!empty($diffs['PLAN']) && !isset($diffs['PLAN']['start_date'])) {
            $planStart =
                to_ymd($csvRow['fecha_de_ingreso'] ?? '') ?:
                to_ymd(dbv($dbRow, ['Fecha de Ingreso', 'Fecha Ingreso', 'Fecha Ingreso Compañia', 'Fecha Ingreso Compañía']));
            if ($planStart) {
                $diffs['PLAN']['start_date'] = $planStart;
            }
        }
    } else {
        $diffs['warnings'][] = "Sin buk_plan_id, no se enviarán cambios de PLAN.";
    }

    if ($bukJobId > 0) {
        $newStart = to_ymd($csvRow['fecha_de_ingreso'] ?? '');
        $oldStart = to_ymd($dbRow['Fecha de Ingreso'] ?? '');
        if ($newStart && $newStart !== $oldStart) { $diffs['JOB']['start_date'] = $newStart; $diffs['DB_UPDATES']['Fecha de Ingreso'] = $newStart; }

        $oldLeader = str_norm_db($dbRow['Jefe'] ?? '');
        if ($leader !== '' && rut_norm($leader) !== '' && rut_norm($leader) !== rut_norm($oldLeader)) {
            $diffs['meta']['leader_rut'] = $leader;
            $diffs['DB_UPDATES']['Jefe'] = $leader;
        }
    } else {
        $diffs['warnings'][] = "Sin buk_job_id, no se enviarán cambios de JOB.";
    }

    if (empty($diffs['PROFILE']) && empty($diffs['PLAN']) && empty($diffs['JOB'])) {
        return ['skip' => 'Sin cambios relevantes'];
    }

    $diffs['meta'] = array_merge((array)$diffs['meta'], [
        'buk_emp_id' => $bukEmpId,
        'buk_plan_id' => $bukPlanId,
        'buk_job_id' => $bukJobId,
    ]);
    return $diffs;
}

function col_norm(string $v): string {
    $v = mb_strtolower(trim($v), 'UTF-8');
    $v = strtr($v, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);
    $v = preg_replace('/[^a-z0-9]/', '', $v);
    return $v;
}

function db_row_get_by_col(array $dbRow, string $targetCol): string {
    $target = col_norm($targetCol);
    foreach ($dbRow as $k => $v) {
        if (col_norm((string)$k) === $target) {
            return str_norm_db($v);
        }
    }
    return '';
}

function build_column_changes(array $dbRow, array $updates, string $rutBd): array {
    $out = [];
    foreach ($updates as $col => $newVal) {
        $oldVal = db_row_get_by_col($dbRow, (string)$col);
        $newStr = str_norm_db($newVal);
        if ($oldVal === $newStr) {
            continue;
        }
        $out[] = [
            'rut' => $rutBd,
            'column' => (string)$col,
            'before' => mb_substr($oldVal, 0, 120),
            'after' => mb_substr($newStr, 0, 120),
        ];
    }
    return $out;
}

function summarize_failures(array $failedItems): array {
    $byStage = [];
    $byMessage = [];
    foreach ($failedItems as $f) {
        $stage = (string)($f['stage'] ?? 'UNKNOWN');
        $msg = trim((string)($f['msg'] ?? 'Error no especificado'));
        if ($msg === '') $msg = 'Error no especificado';
        $byStage[$stage] = ($byStage[$stage] ?? 0) + 1;
        $byMessage[$msg] = ($byMessage[$msg] ?? 0) + 1;
    }
    arsort($byStage);
    arsort($byMessage);
    $topMessages = [];
    $i = 0;
    foreach ($byMessage as $msg => $cnt) {
        $topMessages[] = ['message' => $msg, 'count' => $cnt];
        $i++;
        if ($i >= 8) break;
    }
    return [
        'total' => count($failedItems),
        'by_stage' => $byStage,
        'top_messages' => $topMessages,
    ];
}

function apply_db_updates(mysqli $cn, string $rutDb, array $updates): bool {
    if (empty($updates)) return true;

    static $adpColumnsExact = null;
    static $adpColumnsNormMap = null;
    if ($adpColumnsExact === null || $adpColumnsNormMap === null) {
        $adpColumnsExact = [];
        $adpColumnsNormMap = [];
        $resCols = @$cn->query("SHOW COLUMNS FROM adp_empleados");
        if ($resCols) {
            while ($row = $resCols->fetch_assoc()) {
                $field = (string)($row['Field'] ?? '');
                if ($field === '') continue;
                $adpColumnsExact[$field] = true;
                $n = col_norm($field);
                if ($n !== '' && !isset($adpColumnsNormMap[$n])) {
                    $adpColumnsNormMap[$n] = $field;
                }
            }
            $resCols->free();
        }
    }

    $sets = [];
    $skippedCols = [];
    foreach ($updates as $col => $val) {
        $requested = (string)$col;
        $resolved = null;
        if (isset($adpColumnsExact[$requested])) {
            $resolved = $requested;
        } else {
            $norm = col_norm($requested);
            if ($norm !== '' && isset($adpColumnsNormMap[$norm])) {
                $resolved = (string)$adpColumnsNormMap[$norm];
            }
        }

        if ($resolved === null || $resolved === '') {
            $skippedCols[] = $requested;
            continue;
        }

        $colEsc = str_replace('`', '``', $resolved);
        if ($val === null || $val === '') $sets[] = "`{$colEsc}` = NULL";
        else $sets[] = "`{$colEsc}` = '" . $cn->real_escape_string((string)$val) . "'";
    }
    if (!empty($skippedCols)) {
        save_log('db_updates_skipped_columns', [
            'rut' => $rutDb,
            'columns' => $skippedCols,
        ]);
    }
    if (empty($sets)) return true;

    $rutEsc = $cn->real_escape_string($rutDb);
    $sql = "UPDATE adp_empleados SET " . implode(', ', $sets) . " WHERE Rut='{$rutEsc}' LIMIT 1";
    return (bool)$cn->query($sql);
}

function update_buk_plan_id(mysqli $cn, string $rutDb, int $bukPlanId): bool {
    if ($bukPlanId <= 0) return true;
    $rutEsc = $cn->real_escape_string($rutDb);
    $sql = "UPDATE adp_empleados
            SET buk_plan_id=" . (int)$bukPlanId . ",
                plan_buk='ok'
            WHERE Rut='{$rutEsc}'
            LIMIT 1";
    return (bool)$cn->query($sql);
}

function ensure_queue_table(mysqli $cn): void {
    $sql = "CREATE TABLE IF NOT EXISTS `stisoft_cambios_queue` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `source_file` VARCHAR(255) NOT NULL,
        `source_line` INT NOT NULL DEFAULT 0,
        `rut_csv` VARCHAR(32) NOT NULL,
        `rut_bd` VARCHAR(32) NOT NULL,
        `payload_hash` VARCHAR(64) NOT NULL,
        `payload_profile` LONGTEXT NULL,
        `payload_plan` LONGTEXT NULL,
        `payload_job` LONGTEXT NULL,
        `db_updates` LONGTEXT NULL,
        `meta_json` LONGTEXT NULL,
        `warnings_json` LONGTEXT NULL,
        `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
        `attempts` INT NOT NULL DEFAULT 0,
        `last_http` INT NULL,
        `last_stage` VARCHAR(32) NULL,
        `last_error` TEXT NULL,
        `last_response` LONGTEXT NULL,
        `sent_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_source_line` (`source_file`,`source_line`),
        KEY `idx_status_attempts` (`status`,`attempts`),
        KEY `idx_rut_bd` (`rut_bd`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$cn->query($sql)) {
        throw new RuntimeException('No se pudo crear tabla de cola de cambios: ' . $cn->error);
    }

    ensure_queue_unique_payload_key($cn);
}

function queue_index_exists(mysqli $cn, string $table, string $indexName): bool {
    $tableEsc = $cn->real_escape_string($table);
    $indexEsc = $cn->real_escape_string($indexName);
    $sql = "SHOW INDEX FROM `{$tableEsc}` WHERE Key_name = '{$indexEsc}'";
    $res = $cn->query($sql);
    if (!$res) return false;
    $exists = (bool)$res->fetch_assoc();
    $res->free();
    return $exists;
}

function queue_cleanup_duplicate_payloads(mysqli $cn): void {
    $sql = "SELECT id, rut_bd, payload_hash, status, updated_at
            FROM stisoft_cambios_queue
            ORDER BY rut_bd ASC, payload_hash ASC,
                     CASE status
                        WHEN 'ignored_manual' THEN 1
                        WHEN 'pending' THEN 2
                        WHEN 'error' THEN 3
                        WHEN 'sent' THEN 4
                        ELSE 5
                     END ASC,
                     updated_at DESC,
                     id DESC";
    $res = $cn->query($sql);
    if (!$res) return;

    $keep = [];
    $deleteIds = [];
    while ($row = $res->fetch_assoc()) {
        $rut = (string)($row['rut_bd'] ?? '');
        $hash = (string)($row['payload_hash'] ?? '');
        if ($rut === '' || $hash === '') continue;
        $key = $rut . '|' . $hash;
        if (!isset($keep[$key])) {
            $keep[$key] = (int)($row['id'] ?? 0);
            continue;
        }
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) $deleteIds[] = $id;
    }
    $res->free();

    if (empty($deleteIds)) return;

    $deleteIds = array_values(array_unique($deleteIds));
    $chunks = array_chunk($deleteIds, 500);
    foreach ($chunks as $chunk) {
        $idsSql = implode(',', array_map('intval', $chunk));
        if ($idsSql === '') continue;
        $cn->query("DELETE FROM stisoft_cambios_queue WHERE id IN ({$idsSql})");
    }
}

function ensure_queue_unique_payload_key(mysqli $cn): void {
    if (queue_index_exists($cn, 'stisoft_cambios_queue', 'uq_rut_payload')) {
        return;
    }

    queue_cleanup_duplicate_payloads($cn);
    $sql = "ALTER TABLE `stisoft_cambios_queue`
            ADD UNIQUE KEY `uq_rut_payload` (`rut_bd`, `payload_hash`)";
    if (!$cn->query($sql) && strpos((string)$cn->error, 'Duplicate') === false) {
        throw new RuntimeException('No se pudo crear índice único uq_rut_payload: ' . $cn->error);
    }
}

function json_str($data): string {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function safe_json_decode($raw): array {
    if (!is_string($raw) || trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function keep_only_keys(array $payload, array $allowed): array {
    $out = [];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $payload)) {
            $out[$k] = $payload[$k];
        }
    }
    return $out;
}

function normalize_plan_payload(array $plan): array {
    $allowed = [
        'start_date',
        'pension_scheme','fund_quote','health_company','health_company_plan','health_company_plan_currency',
        'health_company_plan_percentage','afc','afp_collector','disability','disability_start_date',
        'invalidity','invalidity_start_date','youth_employment_subsidy','retired','retirement_regime',
        'fun','ips_rate','foreign_technician','quote_increase_one_percent',
    ];
    return keep_only_keys($plan, $allowed);
}

function plan_payload_has_changes(array $plan): bool {
    foreach ($plan as $k => $_) {
        if ((string)$k === 'start_date') continue;
        return true;
    }
    return false;
}

function updates_require_plan_stage(array $updates): bool {
    $planCols = [
        'Descripcion AFP',
        'Descripción AFP',
        'Descripcion Isapre',
        'Descripción Isapre',
        'Fecha Seguro Cesantia',
        'Fecha Seguro Cesantía',
    ];
    $needles = [];
    foreach ($planCols as $col) {
        $needles[col_norm($col)] = true;
    }
    foreach ($updates as $col => $_) {
        if (isset($needles[col_norm((string)$col)])) {
            return true;
        }
    }
    return false;
}

function normalize_plan_response_to_payload(array $plan): array {
    $mapped = [];
    $allowed = [
        'start_date',
        'pension_scheme','fund_quote','health_company','health_company_plan','health_company_plan_currency',
        'health_company_plan_percentage','afc','afp_collector','disability','disability_start_date',
        'invalidity','invalidity_start_date','youth_employment_subsidy','retired','retirement_regime',
        'fun','ips_rate','foreign_technician','quote_increase_one_percent',
    ];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $plan)) {
            $mapped[$key] = $plan[$key];
        }
    }
    return normalize_plan_payload($mapped);
}

function normalize_job_payload(array $job): array {
    if (!isset($job['leader_id']) && isset($job['boss']) && is_array($job['boss']) && isset($job['boss']['id'])) {
        $job['leader_id'] = (int)$job['boss']['id'];
    }
    if (!isset($job['wage']) && isset($job['base_wage'])) {
        $job['wage'] = $job['base_wage'];
    }
    if (!isset($job['type_of_contract']) && isset($job['contract_type'])) {
        $job['type_of_contract'] = $job['contract_type'];
    }
    if (!isset($job['regular_hours']) && isset($job['weekly_hours'])) {
        $job['regular_hours'] = $job['weekly_hours'];
    }
    $allowed = [
        'company_id','start_date','type_of_contract','end_of_contract','end_of_contract_2','periodicity',
        'regular_hours','days','type_of_working_day','other_type_of_working_day','location_id','area_id',
        'role_id','leader_id','wage','currency','without_wage','contract_subscription_date','reward',
        'reward_concept','reward_payment_period','reward_description','custom_attributes',
        'contractual_stipulation_attributes','contractual_detail_attributes',
        'grado_sector_publico_chile','estamento_sector_publico_chile',
    ];
    return keep_only_keys($job, $allowed);
}

function has_plan_start_date_conflict_error(string $body, string $curlErr): bool {
    if ($curlErr !== '') return false;
    $b = mb_strtolower($body, 'UTF-8');
    if (strpos($b, 'fecha de fin debe ser mayor o igual que fecha de inicio') !== false) return true;
    if (strpos($b, 'fecha de fin') !== false && strpos($b, 'fecha de inicio') !== false) return true;
    return false;
}

function find_plan_start_date_in_response(string $body, int $planId): ?string {
    $json = json_decode($body, true);
    if (!is_array($json)) return null;

    $items = [];
    if (isset($json['data']['plans']) && is_array($json['data']['plans'])) {
        $items = $json['data']['plans'];
    } elseif (isset($json['data']) && is_array($json['data'])) {
        $items = array_values($json['data']);
    } elseif (isset($json[0]) && is_array($json)) {
        $items = $json;
    }

    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if ((int)($item['id'] ?? 0) !== $planId) continue;
        $start = to_ymd((string)($item['start_date'] ?? ''));
        if ($start) return $start;
    }

    return null;
}

function extract_plan_items_from_response(string $body): array {
    $json = json_decode($body, true);
    if (!is_array($json)) return [];

    if (isset($json['data']['plans']) && is_array($json['data']['plans'])) {
        return $json['data']['plans'];
    }
    if (isset($json['data']) && is_array($json['data'])) {
        $data = $json['data'];
        if (isset($data['id']) && is_array($data)) return [$data];
        return array_values(array_filter($data, 'is_array'));
    }
    if (isset($json['id']) && is_array($json)) {
        return [$json];
    }
    if (isset($json[0]) && is_array($json)) {
        return array_values(array_filter($json, 'is_array'));
    }

    return [];
}

function pick_current_plan_info_from_items(array $plans): array {
    foreach ($plans as $plan) {
        if (!is_array($plan) || (int)($plan['id'] ?? 0) <= 0) continue;
        if (!empty($plan['current'])) {
            return [
                'id' => (int)$plan['id'],
                'start_date' => to_ymd((string)($plan['start_date'] ?? '')),
            ];
        }
    }

    $best = ['id' => 0, 'start_date' => null];
    $bestDate = '';
    foreach ($plans as $plan) {
        if (!is_array($plan) || (int)($plan['id'] ?? 0) <= 0) continue;
        $startDate = to_ymd((string)($plan['start_date'] ?? '')) ?? '';
        if ($startDate >= $bestDate) {
            $bestDate = $startDate;
            $best = [
                'id' => (int)$plan['id'],
                'start_date' => $startDate !== '' ? $startDate : null,
            ];
        }
    }

    if ((int)($best['id'] ?? 0) > 0) {
        return $best;
    }

    foreach ($plans as $plan) {
        if (!is_array($plan) || (int)($plan['id'] ?? 0) <= 0) continue;
        $endDate = trim((string)($plan['end_date'] ?? ''));
        if ($endDate === '') {
            return [
                'id' => (int)$plan['id'],
                'start_date' => to_ymd((string)($plan['start_date'] ?? '')),
            ];
        }
    }

    return $best;
}

function pick_current_plan_payload_from_items(array $plans): array {
    foreach ($plans as $plan) {
        if (!is_array($plan) || (int)($plan['id'] ?? 0) <= 0) continue;
        if (!empty($plan['current'])) {
            return normalize_plan_response_to_payload($plan);
        }
    }

    $best = [];
    $bestDate = '';
    foreach ($plans as $plan) {
        if (!is_array($plan) || (int)($plan['id'] ?? 0) <= 0) continue;
        $startDate = to_ymd((string)($plan['start_date'] ?? '')) ?? '';
        if ($startDate >= $bestDate) {
            $bestDate = $startDate;
            $best = normalize_plan_response_to_payload($plan);
        }
    }

    if (!empty($best)) {
        return $best;
    }

    foreach ($plans as $plan) {
        if (!is_array($plan) || (int)($plan['id'] ?? 0) <= 0) continue;
        $endDate = trim((string)($plan['end_date'] ?? ''));
        if ($endDate === '') {
            return normalize_plan_response_to_payload($plan);
        }
    }

    return $best;
}

function fetch_existing_plan_start_date(int $bukEmpId, int $bukPlanId): ?string {
    if ($bukEmpId <= 0 || $bukPlanId <= 0) return null;
    [$http, $body, $curlErr] = buk_get("employees/{$bukEmpId}/plans");
    if ($curlErr !== '' || $http < 200 || $http >= 300) return null;
    return find_plan_start_date_in_response($body, $bukPlanId);
}

function fetch_current_plan_info(int $bukEmpId): array {
    if ($bukEmpId <= 0) return ['id' => 0, 'start_date' => null];
    [$http, $body, $curlErr] = buk_get("employees/{$bukEmpId}/plans");
    if ($curlErr !== '' || $http < 200 || $http >= 300) return ['id' => 0, 'start_date' => null];
    return pick_current_plan_info_from_items(extract_plan_items_from_response($body));
}

function fetch_current_plan_payload(int $bukEmpId): array {
    if ($bukEmpId <= 0) return [];
    [$http, $body, $curlErr] = buk_get("employees/{$bukEmpId}/plans");
    if ($curlErr !== '' || $http < 200 || $http >= 300) return [];
    return pick_current_plan_payload_from_items(extract_plan_items_from_response($body));
}

function extract_plan_id_from_patch_response(string $body): int {
    $plans = extract_plan_items_from_response($body);
    foreach ($plans as $plan) {
        $id = (int)($plan['id'] ?? 0);
        if ($id > 0) return $id;
    }
    return 0;
}

function find_recent_success_plan_info_from_logs(string $rutBd): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $logFile = LOG_DIR . '/cambios_' . date('Ymd') . '.jsonl';
        if (is_file($logFile)) {
            $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $row = json_decode((string)$line, true);
                if (!is_array($row)) continue;
                if ((string)($row['event'] ?? '') !== 'buk_patch') continue;
                $data = is_array($row['data'] ?? null) ? $row['data'] : [];
                if ((string)($data['type'] ?? '') !== 'PLAN') continue;
                $http = (int)($data['http'] ?? 0);
                if ($http < 200 || $http >= 300) continue;
                $rut = (string)($data['rut'] ?? '');
                if ($rut === '') continue;
                $body = (string)($data['body'] ?? '');
                $planId = extract_plan_id_from_patch_response($body);
                if ($planId <= 0) continue;
                $plans = extract_plan_items_from_response($body);
                $startDate = null;
                foreach ($plans as $plan) {
                    if ((int)($plan['id'] ?? 0) !== $planId) continue;
                    $startDate = to_ymd((string)($plan['start_date'] ?? ''));
                    break;
                }
                $cache[$rut] = [
                    'id' => $planId,
                    'start_date' => $startDate,
                ];
            }
        }
    }

    return $cache[$rutBd] ?? ['id' => 0, 'start_date' => null];
}

function pick_plan_start_date_for_retry(array $dbCurrent): string {
    return date('Y-m-01');
}

function plan_effective_start_date(array $currentPlanInfo, array $payload, array $dbCurrent): string {
    $openPeriodStart = date('Y-m-01');

    $payloadStart = to_ymd((string)($payload['start_date'] ?? ''));
    if ($payloadStart && $payloadStart >= $openPeriodStart) return $payloadStart;

    $currentStart = to_ymd((string)($currentPlanInfo['start_date'] ?? ''));
    if ($currentStart && $currentStart >= $openPeriodStart) return $currentStart;

    $dbStart = to_ymd(dbv($dbCurrent, ['Fecha de Ingreso', 'Fecha Ingreso', 'Fecha Ingreso Compañia', 'Fecha Ingreso Compañía']));
    if ($dbStart && $dbStart >= $openPeriodStart) return $dbStart;

    return $openPeriodStart;
}

function queue_upsert_item(mysqli $cn, string $sourceFile, int $lineNo, string $rutCsv, string $rutBd, array $diff): bool {
    $profile = json_str((array)($diff['PROFILE'] ?? []));
    $plan = json_str((array)($diff['PLAN'] ?? []));
    $job = json_str((array)($diff['JOB'] ?? []));
    $updates = json_str((array)($diff['DB_UPDATES'] ?? []));
    $meta = json_str((array)($diff['meta'] ?? []));
    $warn = json_str((array)($diff['warnings'] ?? []));
    $hash = hash('sha256', $profile . '|' . $plan . '|' . $job . '|' . $updates . '|' . $rutBd);

    $sql = "INSERT INTO `stisoft_cambios_queue` (
            source_file, source_line, rut_csv, rut_bd, payload_hash,
            payload_profile, payload_plan, payload_job, db_updates, meta_json, warnings_json,
            status, attempts, last_http, last_stage, last_error, last_response, sent_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, NULL, NULL, NULL, NULL, NULL
        )
        ON DUPLICATE KEY UPDATE
            rut_csv = VALUES(rut_csv),
            rut_bd = VALUES(rut_bd),
            payload_hash = VALUES(payload_hash),
            payload_profile = VALUES(payload_profile),
            payload_plan = VALUES(payload_plan),
            payload_job = VALUES(payload_job),
            db_updates = VALUES(db_updates),
            meta_json = VALUES(meta_json),
            warnings_json = VALUES(warnings_json),
            status = CASE
                WHEN status = 'ignored_manual' AND payload_hash = VALUES(payload_hash) THEN status
                ELSE 'pending'
            END,
            attempts = CASE
                WHEN status = 'ignored_manual' AND payload_hash = VALUES(payload_hash) THEN attempts
                ELSE 0
            END,
            last_http = CASE
                WHEN status = 'ignored_manual' AND payload_hash = VALUES(payload_hash) THEN last_http
                ELSE NULL
            END,
            last_stage = CASE
                WHEN status = 'ignored_manual' AND payload_hash = VALUES(payload_hash) THEN last_stage
                ELSE NULL
            END,
            last_error = CASE
                WHEN status = 'ignored_manual' AND payload_hash = VALUES(payload_hash) THEN last_error
                ELSE NULL
            END,
            last_response = CASE
                WHEN status = 'ignored_manual' AND payload_hash = VALUES(payload_hash) THEN last_response
                ELSE NULL
            END,
            sent_at = CASE
                WHEN status = 'ignored_manual' AND payload_hash = VALUES(payload_hash) THEN sent_at
                ELSE NULL
            END";

    $stmt = $cn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param(
        'sisssssssss',
        $sourceFile,
        $lineNo,
        $rutCsv,
        $rutBd,
        $hash,
        $profile,
        $plan,
        $job,
        $updates,
        $meta,
        $warn
    );
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

function queue_fetch_pending(mysqli $cn, int $limit = 1000): array {
    $limit = max(1, min(5000, $limit));
    $sql = "SELECT * FROM `stisoft_cambios_queue` WHERE status = 'pending' ORDER BY id ASC LIMIT {$limit}";
    $res = $cn->query($sql);
    if (!$res) return [];
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $res->free();
    return $rows;
}

function queue_count_pending(mysqli $cn): int {
    $res = $cn->query("SELECT COUNT(*) AS c FROM `stisoft_cambios_queue` WHERE status = 'pending'");
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    $res->free();
    return (int)($row['c'] ?? 0);
}

function queue_count_error(mysqli $cn): int {
    $res = $cn->query("SELECT COUNT(*) AS c FROM `stisoft_cambios_queue` WHERE status = 'error'");
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    $res->free();
    return (int)($row['c'] ?? 0);
}

function queue_requeue_errors(mysqli $cn): int {
    $sql = "UPDATE `stisoft_cambios_queue`
            SET status='pending',
                last_stage='REQUEUE_ERROR',
                updated_at=NOW()
            WHERE status='error'";
    if (!$cn->query($sql)) return 0;
    return (int)$cn->affected_rows;
}

function queue_mark_error(mysqli $cn, int $id, string $stage, int $http, string $err, array $responses = []): void {
    $err = substr($err, 0, 2000);
    $resp = json_str($responses);
    $sql = "UPDATE `stisoft_cambios_queue`
            SET status='error',
                attempts=attempts+1,
                last_stage=?,
                last_http=?,
                last_error=?,
                last_response=?,
                updated_at=NOW()
            WHERE id=?";
    $stmt = $cn->prepare($sql);
    if (!$stmt) return;
    $stmt->bind_param('sissi', $stage, $http, $err, $resp, $id);
    $stmt->execute();
    $stmt->close();
}

function queue_mark_sent(mysqli $cn, int $id, array $responses = []): bool {
    $resp = json_str($responses);
    $sql = "UPDATE `stisoft_cambios_queue`
            SET status='sent',
                attempts=attempts+1,
                last_stage='DONE',
                last_http=200,
                last_error=NULL,
                last_response=?,
                sent_at=NOW(),
                updated_at=NOW()
            WHERE id=?";
    $stmt = $cn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('si', $resp, $id);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

function queue_mark_ignored(mysqli $cn, int $id, string $reason, array $responses = []): bool {
    $reason = substr($reason, 0, 1000);
    $resp = json_str($responses);
    $sql = "UPDATE `stisoft_cambios_queue`
            SET status='ignored',
                attempts=attempts+1,
                last_stage='IGNORED',
                last_http=200,
                last_error=?,
                last_response=?,
                sent_at=NOW(),
                updated_at=NOW()
            WHERE id=?";
    $stmt = $cn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('ssi', $reason, $resp, $id);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

function queue_mark_error_batch(mysqli $cn, array $ids, string $stage, int $http, string $err, array $responses = []): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    foreach ($ids as $id) {
        queue_mark_error($cn, $id, $stage, $http, $err, $responses);
    }
}

function queue_mark_sent_batch(mysqli $cn, array $ids, array $responses = []): bool {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    foreach ($ids as $id) {
        if (!queue_mark_sent($cn, $id, $responses)) {
            return false;
        }
    }
    return true;
}

function queue_mark_ignored_batch(mysqli $cn, array $ids, string $reason, array $responses = []): bool {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    foreach ($ids as $id) {
        if (!queue_mark_ignored($cn, $id, $reason, $responses)) {
            return false;
        }
    }
    return true;
}

function merge_queue_items_by_rut(array $items): array {
    $groups = [];
    foreach ($items as $item) {
        $rutBd = (string)($item['rut_bd'] ?? '');
        $id = (int)($item['id'] ?? 0);
        if ($rutBd === '' || $id <= 0) {
            continue;
        }
        if (!isset($groups[$rutBd])) {
            $groups[$rutBd] = [
                'id' => $id,
                'rut_bd' => $rutBd,
                'rut_csv' => (string)($item['rut_csv'] ?? ''),
                'meta_json' => [],
                'payload_profile' => [],
                'payload_plan' => [],
                'payload_job' => [],
                'db_updates' => [],
                'ids' => [],
            ];
        }

        $groups[$rutBd]['ids'][] = $id;
        $groups[$rutBd]['id'] = min($groups[$rutBd]['id'], $id);
        if ($groups[$rutBd]['rut_csv'] === '') {
            $groups[$rutBd]['rut_csv'] = (string)($item['rut_csv'] ?? '');
        }

        $meta = safe_json_decode($item['meta_json'] ?? '');
        $profile = safe_json_decode($item['payload_profile'] ?? '');
        $plan = safe_json_decode($item['payload_plan'] ?? '');
        $job = safe_json_decode($item['payload_job'] ?? '');
        $updates = safe_json_decode($item['db_updates'] ?? '');

        $groups[$rutBd]['meta_json'] = array_replace($groups[$rutBd]['meta_json'], $meta);
        foreach (['buk_emp_id', 'buk_plan_id', 'buk_job_id'] as $idKey) {
            if ((int)($groups[$rutBd]['meta_json'][$idKey] ?? 0) <= 0 && (int)($meta[$idKey] ?? 0) > 0) {
                $groups[$rutBd]['meta_json'][$idKey] = (int)$meta[$idKey];
            }
        }
        $groups[$rutBd]['payload_profile'] = array_replace_recursive($groups[$rutBd]['payload_profile'], $profile);
        $groups[$rutBd]['payload_plan'] = array_replace($groups[$rutBd]['payload_plan'], $plan);
        $groups[$rutBd]['payload_job'] = array_replace_recursive($groups[$rutBd]['payload_job'], $job);
        $groups[$rutBd]['db_updates'] = array_replace($groups[$rutBd]['db_updates'], $updates);
    }

    uasort($groups, fn($a, $b) => ((int)$a['id']) <=> ((int)$b['id']));
    return array_values($groups);
}

function process_queue(mysqli $cn, int $limit = 1000): array {
    $items = merge_queue_items_by_rut(queue_fetch_pending($cn, $limit));
    $ok = 0;
    $err = 0;
    $processed = 0;
    $changedRuts = [];
    $errorRuts = [];
    $failedItems = [];
    $failedAll = [];
    $ignored = 0;
    $ignoredItems = [];
    $okItems = [];

    foreach ($items as $item) {
        $processed++;
        $id = (int)($item['id'] ?? 0);
        $queueIds = array_values(array_unique(array_filter(array_map('intval', (array)($item['ids'] ?? [$id])))));
        $rutBd = (string)($item['rut_bd'] ?? '');
        $meta = is_array($item['meta_json'] ?? null) ? (array)$item['meta_json'] : safe_json_decode($item['meta_json'] ?? '');
        $profile = is_array($item['payload_profile'] ?? null) ? (array)$item['payload_profile'] : safe_json_decode($item['payload_profile'] ?? '');
        $plan = is_array($item['payload_plan'] ?? null) ? (array)$item['payload_plan'] : safe_json_decode($item['payload_plan'] ?? '');
        $job = is_array($item['payload_job'] ?? null) ? (array)$item['payload_job'] : safe_json_decode($item['payload_job'] ?? '');
        $updates = is_array($item['db_updates'] ?? null) ? (array)$item['db_updates'] : safe_json_decode($item['db_updates'] ?? '');
        $dbCurrent = db_get_emp_by_rut_any($cn, $rutBd) ?: [];
        $changesPreview = build_column_changes($dbCurrent, $updates, $rutBd);

        $bukEmpId = (int)($meta['buk_emp_id'] ?? 0);
        $bukPlanId = (int)($meta['buk_plan_id'] ?? 0);
        $bukJobId = (int)($meta['buk_job_id'] ?? 0);
        $effectiveBukPlanId = $bukPlanId;
        $currentPlanInfo = [];
        $currentPlanPayload = [];

        if (!empty($plan) && $bukEmpId > 0) {
            $currentPlanInfo = fetch_current_plan_info($bukEmpId);
            $currentPlanPayload = fetch_current_plan_payload($bukEmpId);
            $currentPlanId = (int)($currentPlanInfo['id'] ?? 0);
            if ($currentPlanId <= 0) {
                $currentPlanInfo = find_recent_success_plan_info_from_logs($rutBd);
                $currentPlanId = (int)($currentPlanInfo['id'] ?? 0);
            }
            if ($currentPlanId > 0) {
                $effectiveBukPlanId = $currentPlanId;
                if ($currentPlanId !== $bukPlanId) {
                    save_log('plan_id_refresh', [
                        'queue_id' => $id,
                        'queue_ids' => $queueIds,
                        'rut' => $rutBd,
                        'buk_emp_id' => $bukEmpId,
                        'buk_plan_id_old' => $bukPlanId,
                        'buk_plan_id_new' => $currentPlanId,
                        'start_date' => $currentPlanInfo['start_date'] ?? null,
                        'source' => 'api_or_log_fallback',
                    ]);
                }
            }
        }

        $reqs = [];
        if (isset($profile['custom_attributes']) && is_array($profile['custom_attributes'])) {
            unset($profile['custom_attributes']['Leader/Jefe']);
            if (empty($profile['custom_attributes'])) unset($profile['custom_attributes']);
        }
        $plan = normalize_plan_payload($plan);
        if (plan_payload_has_changes($plan) && updates_require_plan_stage($updates)) {
            $plan = normalize_plan_payload(array_merge($currentPlanPayload, $plan));
            $plan['start_date'] = plan_effective_start_date($currentPlanInfo, $plan, $dbCurrent);
        } else {
            $plan = [];
        }
        $job = normalize_job_payload($job);

        $leaderRutMeta = trim((string)($meta['leader_rut'] ?? ''));
        if ($leaderRutMeta === '') {
            $leaderRutMeta = trim((string)($updates['Jefe'] ?? ''));
        }
        if ($leaderRutMeta !== '' && $bukJobId > 0) {
            $leaderRes = resolve_leader_id_for_cambios($cn, $leaderRutMeta);
            if (!$leaderRes['ok']) {
                queue_mark_error_batch($cn, $queueIds, 'LEADER', 0, (string)$leaderRes['msg'], []);
                $err++;
                $errorRuts[] = $rutBd;
                $failedEntry = [
                    'rut' => $rutBd,
                    'stage' => 'LEADER',
                    'http' => 0,
                    'msg' => (string)$leaderRes['msg'],
                    'changes' => array_slice($changesPreview, 0, 8),
                ];
                $failedAll[] = $failedEntry;
                if (count($failedItems) < 60) $failedItems[] = $failedEntry;
                continue;
            }
            $job['leader_id'] = (int)$leaderRes['leader_id'];
        }

        if (!empty($profile) && $bukEmpId > 0) $reqs[] = ['type' => 'PROFILE', 'method' => 'PATCH', 'path' => "employees/{$bukEmpId}", 'payload' => $profile];
        if (!empty($plan) && $bukEmpId > 0) {
            $planMethod = $effectiveBukPlanId > 0 ? 'PATCH' : 'POST';
            $planPath = $effectiveBukPlanId > 0
                ? "employees/{$bukEmpId}/plans/{$effectiveBukPlanId}"
                : "employees/{$bukEmpId}/plans";
            $reqs[] = ['type' => 'PLAN', 'method' => $planMethod, 'path' => $planPath, 'payload' => $plan];
        }
        if (!empty($job) && $bukEmpId > 0 && $bukJobId > 0) $reqs[] = ['type' => 'JOB', 'method' => 'PATCH', 'path' => "employees/{$bukEmpId}/jobs/{$bukJobId}", 'payload' => $job];

        if (empty($reqs)) {
            $hasAnyPayload = !empty($profile) || !empty($plan) || !empty($job);
            if (!$hasAnyPayload) {
                queue_mark_ignored_batch($cn, $queueIds, 'Payload legado vacío: requiere re-detección de cambios.', []);
                $ignored++;
                if (count($ignoredItems) < 30) {
                    $ignoredItems[] = [
                        'rut' => $rutBd,
                        'msg' => 'Payload legado vacío: requiere re-detección.',
                        'changes' => array_slice($changesPreview, 0, 8),
                    ];
                }
                continue;
            }
            queue_mark_error_batch($cn, $queueIds, 'BUILD', 0, 'No hay payload válido para enviar a BUK (faltan IDs o estructura).', []);
            $err++;
            $errorRuts[] = $rutBd;
            $failedBuild = [
                'rut' => $rutBd,
                'stage' => 'BUILD',
                'http' => 0,
                'msg' => 'No hay payload válido para enviar a BUK (faltan IDs o estructura).',
                'changes' => array_slice($changesPreview, 0, 8),
            ];
            $failedAll[] = $failedBuild;
            if (count($failedItems) < 60) $failedItems[] = $failedBuild;
            continue;
        }

        $allOk = true;
        $responses = [];
        $firstErr = ['stage' => '', 'http' => 0, 'msg' => ''];
        foreach ($reqs as $rq) {
            $payloadToSend = (array)($rq['payload'] ?? []);
            $method = strtoupper((string)($rq['method'] ?? 'PATCH'));
            [$http, $body, $curlErr, $url] = buk_request($method, $rq['path'], $payloadToSend);
            $responses[] = [
                'stage' => $rq['type'],
                'method' => $method,
                'http' => $http,
                'url' => $url,
                'err' => $curlErr,
                'body' => mb_substr((string)$body, 0, 1000),
            ];
            save_log('buk_patch', [
                'queue_id' => $id,
                'queue_ids' => $queueIds,
                'rut' => $rutBd,
                'type' => $rq['type'],
                'method' => $method,
                'url' => $url,
                'http' => $http,
                'err' => $curlErr,
                'payload' => $payloadToSend,
                'body' => $body,
            ]);

            $requestFailed = ($curlErr !== '' || $http < 200 || $http >= 300);
            $shouldRetryPlanWithStartDate =
                ($rq['type'] === 'PLAN')
                && $requestFailed
                && has_plan_start_date_conflict_error((string)$body, (string)$curlErr);

            if ($shouldRetryPlanWithStartDate) {
                $retryPayload = $payloadToSend;
                $retryPayload['start_date'] =
                    fetch_existing_plan_start_date($bukEmpId, $effectiveBukPlanId)
                    ?: (($currentPlanInfo['start_date'] ?? null) ?: null)
                    ?: pick_plan_start_date_for_retry($dbCurrent);
                [$http2, $body2, $curlErr2, $url2] = buk_request($method, $rq['path'], $retryPayload);

                $responses[] = [
                    'stage' => 'PLAN_RETRY_START_DATE',
                    'method' => $method,
                    'http' => $http2,
                    'url' => $url2,
                    'err' => $curlErr2,
                    'body' => mb_substr((string)$body2, 0, 1000),
                ];
                save_log('buk_patch_plan_retry_start_date', [
                    'queue_id' => $id,
                    'queue_ids' => $queueIds,
                    'rut' => $rutBd,
                    'type' => $rq['type'],
                    'method' => $method,
                    'url' => $url2,
                    'http' => $http2,
                    'err' => $curlErr2,
                    'payload' => $retryPayload,
                    'body' => $body2,
                ]);

                $http = $http2;
                $body = $body2;
                $curlErr = $curlErr2;
                $requestFailed = ($curlErr !== '' || $http < 200 || $http >= 300);
            }

            if ($requestFailed) {
                $allOk = false;
                if ($firstErr['stage'] === '') {
                    $firstErr = [
                        'stage' => $rq['type'],
                        'http' => $http,
                        'msg' => $curlErr !== '' ? $curlErr : substr((string)$body, 0, 250),
                    ];
                }
            }
        }

        if (!$allOk) {
            queue_mark_error_batch($cn, $queueIds, (string)$firstErr['stage'], (int)$firstErr['http'], (string)$firstErr['msg'], $responses);
            $err++;
            $errorRuts[] = $rutBd;
            $failedEntry = [
                'rut' => $rutBd,
                'stage' => (string)$firstErr['stage'],
                'http' => (int)$firstErr['http'],
                'msg' => (string)$firstErr['msg'],
                'changes' => array_slice($changesPreview, 0, 8),
            ];
            $failedAll[] = $failedEntry;
            if (count($failedItems) < 60) $failedItems[] = $failedEntry;
            continue;
        }

        $resolvedPlanId = 0;
        foreach ($responses as $resp) {
            $stage = (string)($resp['stage'] ?? '');
            if ($stage !== 'PLAN' && $stage !== 'PLAN_RETRY_START_DATE') {
                continue;
            }
            $respPlanId = extract_plan_id_from_patch_response((string)($resp['body'] ?? ''));
            if ($respPlanId > 0) {
                $resolvedPlanId = $respPlanId;
            }
        }
        if ($resolvedPlanId <= 0 && !empty($plan) && $bukEmpId > 0) {
            $latestPlanInfo = fetch_current_plan_info($bukEmpId);
            $latestPlanId = (int)($latestPlanInfo['id'] ?? 0);
            if ($latestPlanId > 0) {
                $resolvedPlanId = $latestPlanId;
            }
        }
        if ($resolvedPlanId <= 0) {
            $resolvedPlanId = $effectiveBukPlanId;
        }

        try {
            $cn->begin_transaction();
            if (!apply_db_updates($cn, $rutBd, $updates)) {
                throw new RuntimeException('Error actualizando BD local para RUT ' . $rutBd . ': ' . $cn->error);
            }
            if (!update_buk_plan_id($cn, $rutBd, $resolvedPlanId)) {
                throw new RuntimeException('Error actualizando buk_plan_id para RUT ' . $rutBd . ': ' . $cn->error);
            }
            if (!queue_mark_sent_batch($cn, $queueIds, $responses)) {
                throw new RuntimeException('Error marcando cola como sent para items ' . implode(',', $queueIds));
            }
            $cn->commit();
            $ok++;
            $changedRuts[] = $rutBd;
            if (count($okItems) < 60) {
                $okItems[] = [
                    'rut' => $rutBd,
                    'stages' => array_values(array_map(fn($x) => (string)($x['type'] ?? ''), $reqs)),
                    'fields' => array_values(array_keys($updates)),
                    'changes' => array_slice($changesPreview, 0, 8),
                ];
            }
        } catch (Throwable $t) {
            $cn->rollback();
            queue_mark_error_batch($cn, $queueIds, 'DB', 0, $t->getMessage(), $responses);
            $err++;
            $errorRuts[] = $rutBd;
            $failedEntry = [
                'rut' => $rutBd,
                'stage' => 'DB',
                'http' => 0,
                'msg' => $t->getMessage(),
                'changes' => array_slice($changesPreview, 0, 8),
            ];
            $failedAll[] = $failedEntry;
            if (count($failedItems) < 60) $failedItems[] = $failedEntry;
        }
    }

    return [
        'queue_processed' => $processed,
        'sent_ok' => $ok,
        'sent_error' => $err,
        'changed_ruts' => array_values(array_unique(array_filter($changedRuts))),
        'error_ruts' => array_values(array_unique(array_filter($errorRuts))),
        'failed_items' => $failedItems,
        'failure_summary' => summarize_failures($failedAll),
        'ignored' => $ignored,
        'ignored_items' => $ignoredItems,
        'ok_items' => $okItems,
        'queue_pending' => queue_count_pending($cn),
        'queue_error' => queue_count_error($cn),
    ];
}

$dbCfg = runtime_db_config();
$cn = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($cn->connect_error) {
    $res = ['status' => 'error', 'tipo' => 'cambios', 'message' => 'Error conectando BD: ' . $cn->connect_error];
    echo "SYNC_RESULT=" . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}
$cn->set_charset('utf8mb4');

try {
    ensure_queue_table($cn);
} catch (Throwable $t) {
    $res = ['status' => 'error', 'tipo' => 'cambios', 'message' => $t->getMessage()];
    echo "SYNC_RESULT=" . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

$requested = 0;
$queued = 0;
$skip = 0;
$detectErrors = 0;
$columnChanges = [];
$columnCounts = [];
$requeuedErrors = 0;

if (!$drainOnly) {
    [$header, $rows] = read_csv_rows((string)$filePath);
    if (!$header) {
        $res = ['status' => 'error', 'tipo' => 'cambios', 'message' => 'No se pudo leer cabecera del archivo.'];
        echo "SYNC_RESULT=" . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }

    foreach ($rows as $r) {
        $rutCsv = (string)($r['rut'] ?? '');
        if ($rutCsv === '') {
            $skip++;
            continue;
        }
        $requested++;

        $dbRow = db_get_emp_by_rut_active($cn, $rutCsv);
        if (!$dbRow) {
            $skip++;
            continue;
        }

        $diff = build_changes_for_row($r, $dbRow);
        if (isset($diff['skip'])) {
            $skip++;
            continue;
        }

        $lineNo = (int)($r['__line'] ?? 0);
        $rutBd = (string)($dbRow['Rut'] ?? '');
        if ($rutBd === '') {
            $skip++;
            $detectErrors++;
            continue;
        }

        if (queue_upsert_item($cn, basename((string)$filePath), $lineNo, $rutCsv, $rutBd, $diff)) {
            $queued++;
            $changes = build_column_changes($dbRow, (array)($diff['DB_UPDATES'] ?? []), $rutBd);
            foreach ($changes as $ch) {
                $col = (string)($ch['column'] ?? '');
                if ($col !== '') {
                    $columnCounts[$col] = ($columnCounts[$col] ?? 0) + 1;
                }
                if (count($columnChanges) < 80) {
                    $columnChanges[] = $ch;
                }
            }
        } else {
            $detectErrors++;
            save_log('queue_upsert_error', ['line' => $lineNo, 'rut' => $rutCsv, 'db_error' => $cn->error]);
        }
    }
}

$queueRun = $enqueueOnly ? [
    'queue_processed' => 0,
    'sent_ok' => 0,
    'sent_error' => 0,
    'changed_ruts' => [],
    'error_ruts' => [],
    'failed_items' => [],
    'ok_items' => [],
    'queue_pending' => queue_count_pending($cn),
    'queue_error' => queue_count_error($cn),
] : (function () use ($cn, $maxSend, $requeueErrors, &$requeuedErrors) {
    if ($requeueErrors) {
        $requeuedErrors = queue_requeue_errors($cn);
    }
    return process_queue($cn, $maxSend);
})();

$fileLabel = $filePath !== null ? basename((string)$filePath) : '-';
$message = "Cambios en cola procesados. Archivo: {$fileLabel}. Detectados: {$requested}, Encolados: {$queued}, OK: {$queueRun['sent_ok']}, ERROR: {$queueRun['sent_error']}, Pendientes: {$queueRun['queue_pending']}, SKIP: {$skip}";
$message .= ", ERRORES_COLA: " . (int)($queueRun['queue_error'] ?? 0);
$message .= ", REINTENTADOS_ERROR: " . (int)$requeuedErrors;
$message .= ", IGNORADOS_LEGADO: " . (int)($queueRun['ignored'] ?? 0);

$result = [
    'status' => ($queueRun['sent_error'] > 0 || $detectErrors > 0 ? 'error' : 'ok'),
    'tipo' => 'cambios',
    'file' => $fileLabel,
    'mode' => $drainOnly ? 'drain_only' : ($enqueueOnly ? 'enqueue_only' : 'detect_and_send'),
    'requested' => $requested,
    'queued' => $queued,
    'queue_processed' => (int)$queueRun['queue_processed'],
    'sent_ok' => (int)$queueRun['sent_ok'],
    'sent_error' => (int)$queueRun['sent_error'],
    'queue_pending' => (int)$queueRun['queue_pending'],
    'queue_error' => (int)($queueRun['queue_error'] ?? 0),
    'skip' => $skip,
    'detect_errors' => $detectErrors,
    'requeued_errors' => (int)$requeuedErrors,
    'changed_ruts' => (array)$queueRun['changed_ruts'],
    'error_ruts' => (array)$queueRun['error_ruts'],
    'failed_items' => (array)$queueRun['failed_items'],
    'ok_items' => (array)($queueRun['ok_items'] ?? []),
    'failure_summary' => (array)($queueRun['failure_summary'] ?? []),
    'ignored' => (int)($queueRun['ignored'] ?? 0),
    'ignored_items' => (array)($queueRun['ignored_items'] ?? []),
    'column_change_counts' => $columnCounts,
    'column_change_samples' => $columnChanges,
    'detail_totals' => [
        'detected' => $requested,
        'queued' => $queued,
        'ok' => (int)$queueRun['sent_ok'],
        'error' => (int)$queueRun['sent_error'],
        'pending' => (int)$queueRun['queue_pending'],
        'queue_error' => (int)($queueRun['queue_error'] ?? 0),
        'skip' => $skip,
        'ignored_legacy' => (int)($queueRun['ignored'] ?? 0),
    ],
    'message' => $message,
];

echo $result['message'] . "\n";
echo "SYNC_RESULT=" . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
exit(($result['status'] === 'ok') ? 0 : 1);

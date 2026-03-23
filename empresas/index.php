<?php
// /empresas/index.php
// Mapeo Empresas ADP -> Empresa BUK (con regla especial DAMEC=101 usando Credencial)
// SOLO colaboradores con Estado='A'
//
// Regla:
// - 3 (sistema) -> 2 (BUK)
// - 2 (sistema) -> 3 (BUK)
// - 1 (sistema) -> 1 (BUK)
// - si Empresa=101 (DAMEC): usar Credencial (3/2/1) y aplicar regla anterior



require_once __DIR__ . '/../includes/auth.php';
require_auth();
$user = current_user();

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/../conexion/db.php';
$db = new clsConexion();

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/**
 * Regla base sistema -> buk
 */
function map_empresa_sistema_a_buk(?int $empresaSistema): ?int {
  if ($empresaSistema === null) return null;
  if ($empresaSistema === 3) return 2;
  if ($empresaSistema === 2) return 3;
  if ($empresaSistema === 1) return 1;
  return null;
}

function ensure_empresas_table(clsConexion $db): void
{
  $sql = "
    CREATE TABLE IF NOT EXISTS stisoft_mapeo_empresas_codigo (
      empresa_adp_id INT NOT NULL,
      empresa_adp_desc VARCHAR(150) NULL,
      buk_empresa_id INT NULL,
      estado ENUM('pendiente','mapeado','condicional','sin_equivalencia') NOT NULL DEFAULT 'pendiente',
      observacion VARCHAR(255) NULL,
      PRIMARY KEY (empresa_adp_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ";
  $db->ejecutar($sql);
}

function sync_empresas_adp(clsConexion $db): int
{
  ensure_empresas_table($db);

  $sql = "
    INSERT INTO stisoft_mapeo_empresas_codigo (empresa_adp_id, empresa_adp_desc, estado)
    SELECT
      CAST(e.Empresa AS SIGNED) AS empresa_adp_id,
      MIN(e.`Descripcion Empresa`) AS empresa_adp_desc,
      'pendiente' AS estado
    FROM adp_empleados e
    WHERE e.Estado = 'A'
      AND e.Empresa IS NOT NULL AND TRIM(e.Empresa) <> ''
    GROUP BY CAST(e.Empresa AS SIGNED)
    ON DUPLICATE KEY UPDATE
      empresa_adp_desc = VALUES(empresa_adp_desc)
  ";
  $db->ejecutar($sql);

  $res = $db->consultar("SELECT COUNT(*) AS total FROM stisoft_mapeo_empresas_codigo");
  return !empty($res) ? (int)$res[0]['total'] : 0;
}

function auto_map_empresas(clsConexion $db): array
{
  ensure_empresas_table($db);

  $rows = $db->consultar("SELECT empresa_adp_id, empresa_adp_desc FROM stisoft_mapeo_empresas_codigo ORDER BY empresa_adp_id");
  $ok = 0; $cond = 0; $fail = 0;

  foreach ($rows as $r) {
    $id = (int)$r['empresa_adp_id'];

    if ($id === 101) {
      $obs = "DAMEC (101): se resuelve por Credencial por empleado (1/2/3) aplicando regla 3→2,2→3,1→1.";
      $obsEsc = addslashes($obs);
      $db->ejecutar("
        UPDATE stisoft_mapeo_empresas_codigo
        SET buk_empresa_id = NULL,
            estado = 'condicional',
            observacion = '{$obsEsc}'
        WHERE empresa_adp_id = 101
      ");
      $cond++;
      continue;
    }

    $buk = map_empresa_sistema_a_buk($id);

    if ($buk === null) {
      $obs = "Sin regla de mapeo para empresa ADP={$id}.";
      $obsEsc = addslashes($obs);
      $db->ejecutar("
        UPDATE stisoft_mapeo_empresas_codigo
        SET buk_empresa_id = NULL,
            estado = 'sin_equivalencia',
            observacion = '{$obsEsc}'
        WHERE empresa_adp_id = {$id}
      ");
      $fail++;
      continue;
    }

    $db->ejecutar("
      UPDATE stisoft_mapeo_empresas_codigo
      SET buk_empresa_id = {$buk},
          estado = 'mapeado',
          observacion = NULL
      WHERE empresa_adp_id = {$id}
    ");
    $ok++;
  }

  return ['ok'=>$ok,'condicional'=>$cond,'fail'=>$fail];
}

function reset_empresas(clsConexion $db): void
{
  ensure_empresas_table($db);
  $db->ejecutar("
    UPDATE stisoft_mapeo_empresas_codigo
    SET buk_empresa_id = NULL,
        estado = 'pendiente',
        observacion = NULL
  ");
}

function create_or_replace_view_resolver(clsConexion $db): void
{
  // Vista que devuelve buk_empresa_id resuelto por empleado
  // SOLO Estado='A' y DAMEC=101 por Credencial
  $sql = "
    CREATE OR REPLACE VIEW v_mapeo_empresas_buk_adp AS
    SELECT
      e.PersonID,
      CAST(e.Empresa AS SIGNED) AS empresa_adp,
      e.`Descripcion Empresa` AS empresa_adp_desc,
      e.`Credencial` AS credencial,
      CASE
        WHEN CAST(e.Empresa AS SIGNED) = 101 THEN
          CASE CAST(e.`Credencial` AS SIGNED)
            WHEN 3 THEN 2
            WHEN 2 THEN 3
            WHEN 1 THEN 1
            ELSE NULL
          END
        WHEN CAST(e.Empresa AS SIGNED) = 3 THEN 2
        WHEN CAST(e.Empresa AS SIGNED) = 2 THEN 3
        WHEN CAST(e.Empresa AS SIGNED) = 1 THEN 1
        ELSE NULL
      END AS buk_empresa_id
    FROM adp_empleados e
    WHERE e.Estado = 'A'
      AND e.Empresa IS NOT NULL AND TRIM(e.Empresa) <> ''
  ";
  $db->ejecutar($sql);
}

// ------------------- POST Actions -------------------
$action = $_POST['action'] ?? ($_GET['action'] ?? null);

if ($action === 'sync_adp') {
  $total = sync_empresas_adp($db);
  $_SESSION['flash_ok'] = "Empresas ADP sincronizadas (Estado=A). Total en tabla: {$total}.";
  header("Location: index.php");
  exit;
}

if ($action === 'auto_map') {
  $r = auto_map_empresas($db);
  $_SESSION['flash_ok'] = "Auto-mapeo listo. OK={$r['ok']} | Condicional(DAMEC)={$r['condicional']} | Sin regla={$r['fail']}.";
  header("Location: index.php");
  exit;
}

if ($action === 'reset_estados') {
  reset_empresas($db);
  $_SESSION['flash_ok'] = "Estados reseteados a 'pendiente'.";
  header("Location: index.php");
  exit;
}

if ($action === 'crear_vista') {
  create_or_replace_view_resolver($db);
  $_SESSION['flash_ok'] = "Vista v_mapeo_empresas_buk_adp creada/actualizada (Estado=A).";
  header("Location: index.php");
  exit;
}

// ------------------- Flash -------------------
$flashOk    = $_SESSION['flash_ok']    ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_error']);

// ------------------- LISTADO -------------------
ensure_empresas_table($db);

$empresas = $db->consultar("
  SELECT
    m.empresa_adp_id,
    m.empresa_adp_desc,
    m.buk_empresa_id,
    m.estado,
    m.observacion,
    COALESCE(x.cantidad_personas,0) AS cantidad_personas
  FROM stisoft_mapeo_empresas_codigo m
  LEFT JOIN (
    SELECT CAST(Empresa AS SIGNED) AS empresa_adp_id, COUNT(*) AS cantidad_personas
    FROM adp_empleados
    WHERE Estado = 'A'
      AND Empresa IS NOT NULL AND TRIM(Empresa) <> ''
    GROUP BY CAST(Empresa AS SIGNED)
  ) x ON x.empresa_adp_id = m.empresa_adp_id
  ORDER BY m.empresa_adp_id
");

// Conteo DAMEC por Credencial (SOLO Estado=A)
$damecCred = $db->consultar("
  SELECT
    CAST(`Credencial` AS SIGNED) AS credencial,
    COUNT(*) AS cantidad
  FROM adp_empleados
  WHERE Estado = 'A'
    AND CAST(Empresa AS SIGNED) = 101
  GROUP BY CAST(`Credencial` AS SIGNED)
  ORDER BY credencial
");

$totales = ['total'=>0,'pendiente'=>0,'mapeado'=>0,'condicional'=>0,'sin_equivalencia'=>0];
foreach ($empresas as $e) {
  $totales['total']++;
  $st = $e['estado'] ?? 'pendiente';
  if (isset($totales[$st])) $totales[$st]++;
}

?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="bg-gray-50">
<div class="min-h-screen grid grid-cols-12">

  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-gray-200">
    <?php $active='empresas'; include __DIR__ . '/../partials/sidebar.php'; ?>
  </div>

  <div class="col-span-12 md:col-span-9 lg:col-span-10 flex flex-col">

    <header class="border-b bg-white">
      <div class="max-w-7xl mx-auto px-4 py-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-col gap-1">
          <h1 class="text-lg font-semibold text-gray-900">Empresas ADP → Empresa BUK (DAMEC por Credencial)</h1>
          <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600">
            <span>Total: <strong><?= (int)$totales['total'] ?></strong></span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-yellow-50 text-yellow-800">
              Pendientes: <?= (int)$totales['pendiente'] ?>
            </span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-800">
              Mapeados: <?= (int)$totales['mapeado'] ?>
            </span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-violet-50 text-violet-800">
              Condicional: <?= (int)$totales['condicional'] ?>
            </span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-red-50 text-red-700">
              Sin equivalencia: <?= (int)$totales['sin_equivalencia'] ?>
            </span>
          </div>
          <p class="text-xs text-gray-500 mt-1">
            Solo Estado=A. Regla: 3→2, 2→3, 1→1. DAMEC(101) se decide por Credencial (1/2/3).
          </p>
        </div>

        <div class="flex flex-col items-end gap-2">
          <div class="flex flex-col gap-1 items-end">
            <?php if ($flashOk): ?>
              <div class="text-xs px-3 py-2 rounded-lg bg-green-50 text-green-700 border border-green-200 max-w-md">
                <?= h($flashOk) ?>
              </div>
            <?php endif; ?>
            <?php if ($flashError): ?>
              <div class="text-xs px-3 py-2 rounded-lg bg-red-50 text-red-700 border border-red-200 max-w-md">
                <?= h($flashError) ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <form method="post">
              <input type="hidden" name="action" value="sync_adp">
              <button type="submit"
                class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium bg-gray-900 text-white hover:bg-gray-800">
                1) Cargar / actualizar empresas ADP
              </button>
            </form>

            <form method="post" onsubmit="return confirm('¿Aplicar auto-mapeo según regla (incluye DAMEC como condicional)?');">
              <input type="hidden" name="action" value="auto_map">
              <button type="submit"
                class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium bg-blue-600 text-white hover:bg-blue-700">
                2) Auto-mapear según regla
              </button>
            </form>

            <form method="post">
              <input type="hidden" name="action" value="crear_vista">
              <button type="submit"
                class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium bg-emerald-600 text-white hover:bg-emerald-700">
                3) Crear/Actualizar vista resolver
              </button>
            </form>

            <form method="post" onsubmit="return confirm('Esto deja todo en pendiente. ¿Continuar?');">
              <input type="hidden" name="action" value="reset_estados">
              <button type="submit"
                class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium bg-rose-100 text-rose-700 hover:bg-rose-200">
                Limpiar estados
              </button>
            </form>
          </div>
        </div>
      </div>
    </header>

    <main class="flex-1 overflow-y-auto">
      <div class="max-w-7xl mx-auto px-4 py-6 space-y-4">

        <section class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-4 py-3 border-b border-gray-100">
            <h2 class="text-sm font-semibold text-gray-800">DAMEC (Empresa=101) — distribución por Credencial (Estado=A)</h2>
            <p class="text-xs text-gray-500 mt-1">Cada Credencial (1/2/3) se mapea con la regla 3→2,2→3,1→1.</p>
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
              <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                <tr>
                  <th class="px-3 py-2 text-left">Credencial</th>
                  <th class="px-3 py-2 text-left">BUK empresa_id (resuelto)</th>
                  <th class="px-3 py-2 text-left">Personas</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <?php if (empty($damecCred)): ?>
                  <tr><td colspan="3" class="px-3 py-3 text-gray-500">No hay empleados DAMEC activos (Estado=A).</td></tr>
                <?php else: ?>
                  <?php foreach ($damecCred as $r): ?>
                    <?php
                      $c = is_numeric($r['credencial']) ? (int)$r['credencial'] : null;
                      $buk = map_empresa_sistema_a_buk($c);
                    ?>
                    <tr>
                      <td class="px-3 py-2"><?= h($r['credencial']) ?></td>
                      <td class="px-3 py-2"><?= $buk === null ? '<span class="text-red-600">NULL</span>' : (int)$buk ?></td>
                      <td class="px-3 py-2"><?= (int)$r['cantidad'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-800">Tabla de mapeo (stisoft_mapeo_empresas_codigo)</h2>
            <p class="text-xs text-gray-500">Mostrando <strong><?= count($empresas) ?></strong></p>
          </div>

          <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
              <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                <tr>
                  <th class="px-3 py-2 text-left">Empresa ADP</th>
                  <th class="px-3 py-2 text-left">Descripción</th>
                  <th class="px-3 py-2 text-left">Personas (Estado=A)</th>
                  <th class="px-3 py-2 text-left">BUK empresa_id</th>
                  <th class="px-3 py-2 text-left">Estado</th>
                  <th class="px-3 py-2 text-left">Obs</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <?php if (empty($empresas)): ?>
                  <tr><td colspan="6" class="px-3 py-4 text-center text-gray-500">Sin datos. Ejecuta “Cargar / actualizar empresas ADP”.</td></tr>
                <?php else: ?>
                  <?php foreach ($empresas as $e): ?>
                    <?php
                      $estado = $e['estado'] ?? 'pendiente';
                      $badgeClass = 'bg-yellow-50 text-yellow-800';
                      if ($estado === 'mapeado') $badgeClass = 'bg-emerald-100 text-emerald-800';
                      elseif ($estado === 'condicional') $badgeClass = 'bg-violet-100 text-violet-800';
                      elseif ($estado === 'sin_equivalencia') $badgeClass = 'bg-red-100 text-red-800';
                    ?>
                    <tr class="hover:bg-gray-50">
                      <td class="px-3 py-2 font-mono"><?= (int)$e['empresa_adp_id'] ?></td>
                      <td class="px-3 py-2">
                        <div class="max-w-sm truncate" title="<?= h($e['empresa_adp_desc']) ?>"><?= h($e['empresa_adp_desc']) ?></div>
                      </td>
                      <td class="px-3 py-2"><?= (int)$e['cantidad_personas'] ?></td>
                      <td class="px-3 py-2 font-mono">
                        <?= $e['buk_empresa_id'] === null ? '<span class="text-gray-400">NULL</span>' : (int)$e['buk_empresa_id'] ?>
                      </td>
                      <td class="px-3 py-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium <?= $badgeClass ?>">
                          <?= h($estado) ?>
                        </span>
                      </td>
                      <td class="px-3 py-2">
                        <div class="max-w-md truncate" title="<?= h($e['observacion']) ?>"><?= h($e['observacion']) ?></div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section class="bg-white border border-gray-200 rounded-xl p-4 text-xs text-gray-700">
          <div class="font-semibold mb-2">Uso en import</div>
          <p>
            Usa la vista <code class="px-1 py-0.5 bg-gray-100 rounded">v_mapeo_empresas_buk_adp</code> para obtener
            <code class="px-1 py-0.5 bg-gray-100 rounded">buk_empresa_id</code> por empleado (incluye DAMEC por Credencial).
          </p>
          <p class="mt-2">
            Cuando ya esté OK, apaga debug (display_errors) para producción.
          </p>
        </section>

      </div>
    </main>

  </div>
</div>
</body>
</html>

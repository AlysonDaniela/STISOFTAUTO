<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  http_response_code(403);
  exit('Acceso denegado.');
}

include __DIR__ . '/../partials/head.php';
?>
<script>
  (function () {
    try {
      const saved = localStorage.getItem('theme');
      const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
      const theme = saved || (prefersDark ? 'dark' : 'light');
      document.documentElement.classList.toggle('dark', theme === 'dark');
    } catch (e) {}
  })();
</script>

<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
<div class="min-h-screen grid grid-cols-12">
  <!-- SIDEBAR -->
  <div class="col-span-12 md:col-span-3 lg:col-span-2 bg-white border-r border-slate-200 dark:bg-slate-900/70 dark:border-white/10">
    <?php $active='empleados'; include __DIR__ . '/../partials/sidebar.php'; ?>
  </div>

  <!-- MAIN -->
  <div class="col-span-12 md:col-span-9 lg:col-span-10">
    <!-- HEADER -->
    <div class="border-b border-slate-200 bg-white/70 backdrop-blur dark:border-white/10 dark:bg-slate-950/60">
      <div class="max-w-7xl mx-auto px-4 py-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 class="text-xl font-semibold tracking-tight text-slate-900 dark:text-white">Empleados ADP → Mapeo Buk</h1>
            <p class="text-xs text-slate-500 dark:text-white/60 mt-1">
              Mostrando <span class="font-semibold text-slate-900 dark:text-white"><?php echo $desde; ?></span>–
              <span class="font-semibold text-slate-900 dark:text-white"><?php echo $hasta; ?></span> de
              <span class="font-semibold text-slate-900 dark:text-white"><?php echo $totalRegistros; ?></span> (filtrados)
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-2 justify-end">
            <!-- Toggle tema -->
            <button
              type="button"
              onclick="
                const isDark = document.documentElement.classList.toggle('dark');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
              "
              class="inline-flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold border border-slate-200 bg-white hover:bg-slate-50
                     dark:border-white/10 dark:bg-white/10 dark:hover:bg-white/15"
              title="Cambiar tema"
            >
              <span class="text-slate-700 dark:text-white">Tema</span>
              <span class="text-slate-400 dark:text-white/60">☾/☀︎</span>
            </button>

            <form method="post" action="importar_archivo_adp_patched.php" enctype="multipart/form-data"
                  class="flex items-center gap-2 rounded-2xl px-3 py-2 border border-slate-200 bg-white
                         dark:border-white/10 dark:bg-white/10">
              <input type="file" name="archivo_empleados" accept=".csv,.txt" required
                     class="text-xs text-slate-700 dark:text-white/80
                            file:mr-3 file:rounded-full file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:text-slate-800 hover:file:bg-slate-200
                            dark:file:bg-white/10 dark:file:text-white dark:hover:file:bg-white/15">
              <button type="submit"
                class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold bg-indigo-600 text-white hover:bg-indigo-700">
                Importar ADP
              </button>
            </form>
          </div>
        </div>

        <?php if ($flashOk): ?>
          <div class="mt-4 text-xs px-4 py-3 rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-800
                      dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">
            <?php echo htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8'); ?>
          </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
          <div class="mt-4 text-xs px-4 py-3 rounded-2xl border border-red-200 bg-red-50 text-red-800
                      dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-200">
            <?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?>
          </div>
        <?php endif; ?>

        <!-- RESULTADO MANUAL -->
        <?php if ($send_ui): ?>
          <div class="mt-4 text-xs px-4 py-3 rounded-2xl border <?php echo $send_ui['ok'] ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-200'; ?>">
            <div class="font-semibold"><?php echo e($send_ui['title'] ?? 'Envío a Buk'); ?></div>
            <div class="mt-1 whitespace-pre-line"><?php echo e($send_ui['msg'] ?? ''); ?></div>
            <?php if (!empty($send_ui['http'])): ?>
              <div class="mt-1 text-[11px] text-slate-600 dark:text-white/60">HTTP: <?php echo (int)$send_ui['http']; ?></div>
            <?php endif; ?>

            <?php
              $d = $send_ui['details'] ?? null;
              if (is_array($d)) {
                $asJson = function($x) {
                  $j = json_encode($x, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
                  return $j === false ? 'No se pudo generar JSON.' : $j;
                };
              }
            ?>
            <?php if (is_array($d) && !empty($d['emp']['payload'])): ?>
              <details class="mt-3 bg-white/70 border border-slate-200 rounded-xl p-3 dark:bg-white/5 dark:border-white/10">
                <summary class="cursor-pointer font-semibold text-slate-800 dark:text-white">EMP — Payload/Respuesta</summary>
                <pre class="mt-2 text-[11px] overflow-auto whitespace-pre-wrap"><?php echo e($asJson($d['emp']['payload'])); ?></pre>
                <pre class="mt-2 text-[11px] overflow-auto whitespace-pre-wrap"><?php echo e($asJson($d['emp']['resp'])); ?></pre>
              </details>
            <?php endif; ?>
            <?php if (is_array($d) && !empty($d['plan']['payload'])): ?>
              <details class="mt-2 bg-white/70 border border-slate-200 rounded-xl p-3 dark:bg-white/5 dark:border-white/10">
                <summary class="cursor-pointer font-semibold text-slate-800 dark:text-white">PLAN — Payload/Respuesta</summary>
                <pre class="mt-2 text-[11px] overflow-auto whitespace-pre-wrap"><?php echo e($asJson($d['plan']['payload'])); ?></pre>
                <pre class="mt-2 text-[11px] overflow-auto whitespace-pre-wrap"><?php echo e($asJson($d['plan']['resp'])); ?></pre>
              </details>
            <?php endif; ?>
            <?php if (is_array($d) && !empty($d['job']['payload'])): ?>
              <details class="mt-2 bg-white/70 border border-slate-200 rounded-xl p-3 dark:bg-white/5 dark:border-white/10">
                <summary class="cursor-pointer font-semibold text-slate-800 dark:text-white">JOB — Payload/Respuesta</summary>
                <pre class="mt-2 text-[11px] overflow-auto whitespace-pre-wrap"><?php echo e($asJson($d['job']['payload'])); ?></pre>
                <pre class="mt-2 text-[11px] overflow-auto whitespace-pre-wrap"><?php echo e($asJson($d['job']['resp'])); ?></pre>
              </details>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- RESULTADO MASIVO -->
        <?php if ($bulk_ui): ?>
          <div class="mt-4 text-xs px-4 py-3 rounded-2xl border border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-200">
            <div class="font-semibold">Resultado envío masivo</div>
            <div class="mt-1">
              Total: <b><?php echo (int)$bulk_ui['requested']; ?></b> ·
              OK: <b><?php echo (int)$bulk_ui['done']; ?></b> ·
              SKIP mapping: <b><?php echo (int)$bulk_ui['skipped_mapping']; ?></b> ·
              Fallidos: <b><?php echo (int)$bulk_ui['failed']; ?></b>
            </div>
            <div class="mt-1 text-[11px] text-slate-600 dark:text-white/60">
              (En masivo: se guardan logs SOLO de caídos en <code><?php echo e(basename(LOG_DIR)); ?></code>)
            </div>

            <?php if (!empty($bulk_fail_list)): ?>
              <details class="mt-3 bg-white/70 border border-slate-200 rounded-xl p-3 dark:bg-white/5 dark:border-white/10">
                <summary class="cursor-pointer font-semibold text-slate-800 dark:text-white">Ver fallos (muestra máx. 12)</summary>
                <div class="mt-2 space-y-1">
                  <?php foreach ($bulk_fail_list as $f): ?>
                    <div class="text-[11px] text-slate-700 dark:text-white/80">
                      <b><?php echo e($f['rut'] ?? '-'); ?></b> ·
                      <?php echo e($f['stage'] ?? '-'); ?> ·
                      HTTP <?php echo (int)($f['http'] ?? 0); ?> ·
                      <?php echo e($f['msg'] ?? ''); ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </details>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- ============================
             ACCIONES MASIVAS (ARRIBA KPI)
             ============================ -->
        <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-xs font-semibold text-slate-800 dark:text-white">Acciones masivas</p>
              <p class="text-[11px] text-slate-500 dark:text-white/60 mt-1">
                Solo considera <b>Activos (Estado = A)</b>. En masivo no se muestran payloads, solo resumen; logs solo para caídos.
              </p>
            </div>
          </div>

          <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-3">
            <!-- Empleados filtrados -->
            <!-- Empleados filtrados -->
<div class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
  <div class="flex items-center justify-between">
    <p class="text-xs font-semibold text-slate-700 dark:text-white/80">Empleados filtrados (Activos)</p>
    <span class="text-[11px] text-slate-400 dark:text-white/50">Se aplica a los filtros actuales</span>
  </div>

  <form method="post" class="mt-3 flex flex-wrap items-center gap-2">
    <label class="text-[11px] text-slate-500 dark:text-white/60">Acción</label>

    <select name="action"
      class="min-w-[260px] px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-900 text-xs
             focus:outline-none focus:ring-2 focus:ring-indigo-500/30
             dark:border-white/10 dark:bg-slate-950/30 dark:text-white">
      <option value="bulk_filtered_all">Enviar TODO (EMP+PLAN+JOB)</option>
      <option value="bulk_filtered_emp">Enviar solo EMP</option>
      <option value="bulk_filtered_plan">Enviar solo PLAN</option>
      <option value="bulk_filtered_job">Enviar solo JOB</option>
    </select>

    <button type="submit"
      class="inline-flex items-center px-4 py-2 rounded-xl text-xs font-semibold bg-indigo-600 text-white hover:bg-indigo-700">
      Ejecutar
    </button>
  </form>

  <p class="mt-2 text-[11px] text-slate-500 dark:text-white/60">
    “Solo PLAN/JOB” intenta enviar únicamente a quienes ya tienen <b>buk_emp_id</b>.
  </p>
</div>


            <!-- Jefes detectados -->
            
<!-- Jefes detectados -->
<div class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
  <div class="flex items-center justify-between">
    <p class="text-xs font-semibold text-slate-700 dark:text-white/80">Jefes detectados (Activos)</p>
    <span class="text-[11px] text-slate-400 dark:text-white/50">Ordenados por nivel jerárquico</span>
  </div>

  <form method="post" class="mt-3 flex flex-wrap items-center gap-2">
    <label class="text-[11px] text-slate-500 dark:text-white/60">Acción</label>

    <select name="action"
      class="min-w-[260px] px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-900 text-xs
             focus:outline-none focus:ring-2 focus:ring-indigo-500/30
             dark:border-white/10 dark:bg-slate-950/30 dark:text-white">
      <option value="bulk_boss_all">Enviar TODO (EMP+PLAN+JOB)</option>
      <option value="bulk_boss_emp">Enviar solo EMP</option>
      <option value="bulk_boss_plan">Enviar solo PLAN</option>
      <option value="bulk_boss_job">Enviar solo JOB</option>
    </select>

    <button type="submit"
      class="inline-flex items-center px-4 py-2 rounded-xl text-xs font-semibold bg-indigo-600 text-white hover:bg-indigo-700">
      Ejecutar
    </button>
  </form>

  <p class="mt-2 text-[11px] text-slate-500 dark:text-white/60">
    “Jefes detectados” sale desde la columna <b>Jefe</b> de subordinados activos.
  </p>
</div>
          </div> <!-- /grid 2 cols -->
        </div>   <!-- /card Acciones masivas -->

        <!-- KPI CARDS -->
<!-- KPI + POR TIPO (1 fila) + ESTADO BUK (abajo ocupando todo) -->
<div class="grid grid-cols-1 lg:grid-cols-4 gap-3 mt-5">

  <!-- Total -->
  <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
    <p class="text-xs text-slate-500 dark:text-white/60">Total registros</p>
    <p class="text-3xl font-semibold text-slate-900 mt-1 dark:text-white"><?php echo $totalAll; ?></p>
    <p class="text-xs text-slate-500 mt-1 dark:text-white/50">Base completa (sin filtros)</p>
  </div>

  <!-- Activos -->
  <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm dark:border-emerald-500/20 dark:bg-emerald-500/10">
    <p class="text-xs text-emerald-700/80 dark:text-emerald-200/80">Activos</p>
    <p class="text-3xl font-semibold text-emerald-700 mt-1 dark:text-emerald-200"><?php echo $activosAll; ?></p>
    <p class="text-xs text-emerald-700/70 mt-1 dark:text-emerald-200/60">Estado = A</p>
  </div>

  <!-- Inactivos -->
  <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
    <p class="text-xs text-slate-500 dark:text-white/60">Inactivos</p>
    <p class="text-3xl font-semibold text-slate-900 mt-1 dark:text-white"><?php echo $inactivosAll; ?></p>
    <p class="text-xs text-slate-500 mt-1 dark:text-white/50">Estado ≠ A</p>
  </div>

  <!-- Por tipo -->
  <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold text-slate-700 dark:text-white/80">Por tipo (Origen ADP)</p>
      <span class="text-[11px] text-slate-400 dark:text-white/50">Total / Activos / Inactivos</span>
    </div>

    <div class="mt-3 overflow-x-auto">
      <table class="min-w-full text-xs">
        <thead class="text-[11px] uppercase text-slate-400 dark:text-white/50">
          <tr>
            <th class="text-left py-1.5">Tipo</th>
            <th class="text-right py-1.5">Total</th>
            <th class="text-right py-1.5">Act</th>
            <th class="text-right py-1.5">Inac</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-white/10">
          <?php foreach ($kpiPorOrigen as $r): ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
              <td class="py-2 text-slate-700 dark:text-white/85"><?php echo htmlspecialchars($r['origen'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="py-2 text-right text-slate-900 font-semibold dark:text-white"><?php echo (int)$r['total']; ?></td>
              <td class="py-2 text-right text-emerald-700 dark:text-emerald-200"><?php echo (int)$r['activos']; ?></td>
              <td class="py-2 text-right text-slate-600 dark:text-white/70"><?php echo (int)$r['inactivos']; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ESTADO BUK (abajo, full width) -->
  <div class="lg:col-span-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
    <div class="flex items-center justify-between">
      <p class="text-xs font-semibold text-slate-700 dark:text-white/80">Estado Buk</p>
      <span class="text-[11px] text-slate-400 dark:text-white/50">Solo Activos (recomendado)</span>
    </div>

    <div class="mt-3 flex flex-wrap gap-2">
      <?php foreach ($kpiBuk as $r): ?>
        <?php
          $st = $r['estado_buk'];
          $cnt = (int)$r['total'];

          $cls = 'bg-indigo-50 text-indigo-700 border-indigo-100';
          if ($st === 'completo') $cls = 'bg-emerald-50 text-emerald-700 border-emerald-100';
          if ($st === 'emp_error' || $st === 'emp_ok_job_error' || $st === 'emp_plan_error' || $st === 'emp_plan_ok_job_error')
            $cls = 'bg-red-50 text-red-700 border-red-100';
          if ($st === 'emp_ok_sin_job') $cls = 'bg-amber-50 text-amber-800 border-amber-100';
          if ($st === 'no_enviado') $cls = 'bg-sky-50 text-sky-700 border-sky-100';

          $clsDark = 'dark:bg-indigo-500/10 dark:text-indigo-200 dark:border-indigo-500/20';
          if ($st === 'completo') $clsDark = 'dark:bg-emerald-500/10 dark:text-emerald-200 dark:border-emerald-500/20';
          if ($st === 'emp_error' || $st === 'emp_ok_job_error' || $st === 'emp_plan_error' || $st === 'emp_plan_ok_job_error')
            $clsDark = 'dark:bg-red-500/10 dark:text-red-200 dark:border-red-500/20';
          if ($st === 'emp_ok_sin_job') $clsDark = 'dark:bg-amber-500/10 dark:text-amber-200 dark:border-amber-500/20';
          if ($st === 'no_enviado') $clsDark = 'dark:bg-sky-500/10 dark:text-sky-200 dark:border-sky-500/20';
        ?>
        <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs border <?php echo $cls; ?> <?php echo $clsDark; ?>">
          <?php echo htmlspecialchars(str_replace('_',' ', $st), ENT_QUOTES, 'UTF-8'); ?>
          <span class="font-semibold"><?php echo $cnt; ?></span>
        </span>
      <?php endforeach; ?>
    </div>
  </div>

</div>

    <!-- CONTENIDO -->
    <main class="max-w-7xl mx-auto px-4 py-6">
      <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden dark:border-white/10 dark:bg-white/5">

        <!-- SECCIÓN JEFES (colapsable y fuera de filtros/tabla) -->
        <div class="px-4 py-3 border-b border-slate-100 bg-white/60 dark:border-white/10 dark:bg-white/5">
          <details class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-white/5">
            <summary class="cursor-pointer select-none text-xs font-semibold text-slate-800 dark:text-white flex items-center justify-between">
              <span>Mapa de Jefes (subordinados activos) — <?php echo count($bossList); ?></span>
              <span class="text-[11px] text-slate-400 dark:text-white/50">Expandir/Colapsar</span>
            </summary>

            <div class="mt-3 overflow-x-auto">
              <table class="min-w-full text-xs">
                <thead class="text-[11px] uppercase text-slate-400 dark:text-white/50">
                  <tr>
                    <th class="text-left py-2 px-2">Nivel</th>
                    <th class="text-left py-2 px-2">RUT Jefe</th>
                    <th class="text-left py-2 px-2">Nombre</th>
                    <th class="text-left py-2 px-2">Estado</th>
                    <th class="text-left py-2 px-2">Estado Buk</th>
                    <th class="text-right py-2 px-2"># Reportes</th>
                    <th class="text-left py-2 px-2">Nota</th>
                    <th class="text-left py-2 px-2">Acciones Buk</th>
                  </tr>
                </thead>

                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                <?php if (empty($bossList)): ?>
                  <tr>
                    <td colspan="8" class="py-4 px-2 text-slate-500 dark:text-white/60">
                      No se detectaron jefes (revisa columna <b>Jefe</b> en empleados activos).
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($bossList as $bk): ?>
                    <?php
                      $info = $activos[$bk] ?? null;
                      $rutBossPretty = rut_pretty($bk);
                      $rutBossRaw = $info ? (string)($info['Rut'] ?? $rutBossPretty) : $rutBossPretty;

                      $nombreJ = $info ? trim(($info['Nombres'] ?? '').' '.($info['Apaterno'] ?? '').' '.($info['Amaterno'] ?? '')) : 'No existe como activo (solo referenciado)';
                      $estadoJ = $info ? ($info['Estado'] ?? '-') : '-';
                      $estadoBukJ = $info ? ($info['estado_buk'] ?? 'no_enviado') : '-';
                      $cnt = (int)($reportsCount[$bk] ?? 0);
                      $lv = (int)($level[$bk] ?? 99);

                      $nota = ($lv === 1) ? 'Top (no reporta a otro activo)' : (($lv === 99) ? 'Nivel no resuelto (posible ciclo)' : 'Reporta a otro jefe activo');

                      $isBossActivo = ($info && ($estadoJ === 'A'));
                    ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                      <td class="py-2 px-2 font-semibold text-slate-900 dark:text-white"><?php echo $lv; ?></td>
                      <td class="py-2 px-2 font-mono text-[11px] text-slate-800 dark:text-white"><?php echo e($rutBossPretty); ?></td>
                      <td class="py-2 px-2 text-slate-700 dark:text-white/85"><?php echo e($nombreJ); ?></td>
                      <td class="py-2 px-2">
                        <?php if ($estadoJ === 'A'): ?>
                          <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold border
                                       bg-emerald-50 text-emerald-700 border-emerald-200
                                       dark:bg-emerald-500/10 dark:text-emerald-200 dark:border-emerald-500/20">Activo</span>
                        <?php else: ?>
                          <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold border
                                       bg-slate-50 text-slate-700 border-slate-200
                                       dark:bg-white/10 dark:text-white/70 dark:border-white/10"><?php echo e($estadoJ); ?></span>
                        <?php endif; ?>
                      </td>
                      <td class="py-2 px-2 text-slate-700 dark:text-white/80"><?php echo e(str_replace('_',' ', (string)$estadoBukJ)); ?></td>
                      <td class="py-2 px-2 text-right font-semibold text-slate-900 dark:text-white"><?php echo $cnt; ?></td>
                      <td class="py-2 px-2 text-[11px] text-slate-500 dark:text-white/60"><?php echo e($nota); ?></td>

                      <!-- Acciones Buk Jefes -->
                      <!-- Acciones Buk Jefes -->
<td class="py-2 px-2 whitespace-nowrap">
  <?php if (!$isBossActivo): ?>
    <span class="text-[11px] text-slate-400 dark:text-white/40">Sin acciones</span>

  <?php else: ?>
    <?php
      // Estado Buk y progresos (igual lógica que en tabla principal)
      $estadoBukJ = (string)($info['estado_buk'] ?? 'no_enviado');
      $empIdJ = (int)($info['buk_emp_id'] ?? 0);
      $jobIdJ = (int)($info['buk_cargo_id'] ?? 0);

      $empDoneJ  = ($empIdJ > 0);
      $planDoneJ = in_array($estadoBukJ, ['emp_plan_ok','completo'], true);
      $jobDoneJ  = ($jobIdJ > 0) || ($estadoBukJ === 'completo');
      $allDoneJ  = ($estadoBukJ === 'completo');
    ?>

    <?php if ($allDoneJ): ?>
      <span class="text-[11px] font-semibold text-emerald-700 dark:text-emerald-200">
        OK (completo)
      </span>
    <?php else: ?>
      <div class="flex flex-wrap gap-1.5">

        <?php if (!$empDoneJ): ?>
          <form method="post" class="inline">
            <input type="hidden" name="action" value="send_emp_one">
            <input type="hidden" name="rut" value="<?php echo e($rutBossRaw); ?>">
            <button type="submit"
              class="px-2.5 py-1.5 rounded-lg text-[11px] font-semibold bg-indigo-600 text-white hover:bg-indigo-700">
              EMP
            </button>
          </form>
        <?php endif; ?>

        <?php if ($empDoneJ && !$planDoneJ): ?>
          <form method="post" class="inline">
            <input type="hidden" name="action" value="send_plan_one">
            <input type="hidden" name="rut" value="<?php echo e($rutBossRaw); ?>">
            <button type="submit"
              class="px-2.5 py-1.5 rounded-lg text-[11px] font-semibold bg-slate-900 text-white hover:bg-slate-800 dark:bg-white/15 dark:hover:bg-white/20">
              PLAN
            </button>
          </form>
        <?php endif; ?>

        <?php if ($empDoneJ && $planDoneJ && !$jobDoneJ): ?>
          <form method="post" class="inline">
            <input type="hidden" name="action" value="send_job_one">
            <input type="hidden" name="rut" value="<?php echo e($rutBossRaw); ?>">
            <button type="submit"
              class="px-2.5 py-1.5 rounded-lg text-[11px] font-semibold bg-emerald-600 text-white hover:bg-emerald-700">
              JOB
            </button>
          </form>
        <?php endif; ?>

        <!-- TODO (siempre disponible si falta algo) -->
        <form method="post" class="inline">
          <input type="hidden" name="action" value="send_all_one">
          <input type="hidden" name="rut" value="<?php echo e($rutBossRaw); ?>">
          <button type="submit"
            class="px-2.5 py-1.5 rounded-lg text-[11px] font-semibold border border-slate-200 bg-white hover:bg-slate-50 text-slate-700
                   dark:border-white/10 dark:bg-white/10 dark:hover:bg-white/15 dark:text-white">
            TODO
          </button>
        </form>

      </div>
    <?php endif; ?>
  <?php endif; ?>
</td>

                    
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </details>
        </div>

        <!-- FILTROS -->
        <div class="px-4 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white
                    dark:border-white/10 dark:from-white/5 dark:to-white/0">
          <div class="flex flex-wrap items-end justify-between gap-3">
            <form method="get" class="flex flex-wrap items-end gap-2">
              <div class="flex flex-col gap-1">
                <label for="rut" class="text-[11px] text-slate-500 dark:text-white/60">RUT</label>
                <input
                  type="text" id="rut" name="rut" placeholder="Ej: 12.345.678-9"
                  value="<?php echo htmlspecialchars($rutFiltro, ENT_QUOTES, 'UTF-8'); ?>"
                  class="w-48 px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-900 text-xs placeholder:text-slate-400
                         focus:outline-none focus:ring-2 focus:ring-indigo-500/30
                         dark:border-white/10 dark:bg-slate-950/30 dark:text-white dark:placeholder:text-white/30"
                >
              </div>

              <div class="flex flex-col gap-1">
                <label for="tipo" class="text-[11px] text-slate-500 dark:text-white/60">Tipo empleado</label>
                <select id="tipo" name="tipo"
                  class="w-56 px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-900 text-xs
                         focus:outline-none focus:ring-2 focus:ring-indigo-500/30
                         dark:border-white/10 dark:bg-slate-950/30 dark:text-white">
                  <option value="">Todos</option>
                  <?php foreach ($tiposEmpleado as $t): ?>
                    <option value="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $tipoFiltro === $t ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="flex flex-col gap-1">
                <label for="estado" class="text-[11px] text-slate-500 dark:text-white/60">Estado</label>
                <select id="estado" name="estado"
                  class="w-40 px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-900 text-xs
                         focus:outline-none focus:ring-2 focus:ring-indigo-500/30
                         dark:border-white/10 dark:bg-slate-950/30 dark:text-white">
                  <option value="">Todos</option>
                  <option value="A" <?php echo $estadoFiltro === 'A' ? 'selected' : ''; ?>>Activos (A)</option>
                  <option value="INACTIVOS" <?php echo $estadoFiltro === 'INACTIVOS' ? 'selected' : ''; ?>>Inactivos</option>
                </select>
              </div>

              <div class="flex flex-col gap-1">
                <label for="buk_estado" class="text-[11px] text-slate-500 dark:text-white/60">Estado Buk</label>
                <select id="buk_estado" name="buk_estado"
                  class="w-56 px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-900 text-xs
                         focus:outline-none focus:ring-2 focus:ring-indigo-500/30
                         dark:border-white/10 dark:bg-slate-950/30 dark:text-white">
                  <option value="">Todos</option>
                  <?php foreach ($estadosBuk as $st): ?>
                    <option value="<?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $bukFiltro === $st ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars(str_replace('_',' ', $st), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="flex flex-col gap-1">
                <label for="per_page" class="text-[11px] text-slate-500 dark:text-white/60">Por página</label>
                <select id="per_page" name="per_page"
                  class="w-28 px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-900 text-xs
                         focus:outline-none focus:ring-2 focus:ring-indigo-500/30
                         dark:border-white/10 dark:bg-slate-950/30 dark:text-white">
                  <?php foreach ($perPageOptions as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo ($perPage === $opt) ? 'selected' : ''; ?>>
                      <?php echo $opt; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="dir" value="<?php echo htmlspecialchars($dir, ENT_QUOTES, 'UTF-8'); ?>">

              <button type="submit"
                class="inline-flex items-center px-4 py-2 rounded-xl text-xs font-semibold bg-indigo-600 text-white hover:bg-indigo-700">
                Aplicar
              </button>

              <a href="index.php"
                class="inline-flex items-center px-4 py-2 rounded-xl text-xs font-semibold border border-slate-200 bg-white hover:bg-slate-50 text-slate-700
                       dark:border-white/10 dark:bg-white/10 dark:hover:bg-white/15 dark:text-white">
                Limpiar
              </a>

              <a href="<?php echo q(['export' => 'csv']); ?>"
                class="inline-flex items-center px-4 py-2 rounded-xl text-xs font-semibold border border-slate-200 bg-white hover:bg-slate-50 text-slate-700
                       dark:border-white/10 dark:bg-white/10 dark:hover:bg-white/15 dark:text-white">
                Export CSV
              </a>
            </form>
          </div>

          <?php if (!empty($chips)): ?>
            <div class="mt-4 flex flex-wrap items-center gap-2">
              <span class="text-[11px] text-slate-500 dark:text-white/60">Filtros activos:</span>

              <?php foreach ($chips as $c): ?>
                <a href="<?php echo $c['url']; ?>"
                   class="group inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs border
                          bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100
                          dark:bg-white/10 dark:text-white/80 dark:border-white/10 dark:hover:bg-white/15">
                  <span><?php echo htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                  <span class="font-bold text-slate-400 group-hover:text-slate-600 dark:text-white/50 dark:group-hover:text-white">×</span>
                </a>
              <?php endforeach; ?>

              <a href="index.php"
                 class="ml-1 inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold
                        bg-white hover:bg-slate-50 border border-slate-200 text-slate-700
                        dark:bg-white/10 dark:hover:bg-white/15 dark:border-white/10 dark:text-white">
                Limpiar todo
              </a>
            </div>
          <?php endif; ?>
        </div>

        <!-- BARRA MASIVO (seleccionando checkboxes) -->
        <div class="px-4 py-3 border-b border-slate-100 bg-white/60 dark:border-white/10 dark:bg-white/5">
          <form method="post" id="bulkSelectedForm" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="action" value="bulk_send_selected_all">
            <button type="submit"
              class="inline-flex items-center px-3 py-2 rounded-xl text-xs font-semibold bg-emerald-600 text-white hover:bg-emerald-700">
              Enviar seleccionados (EMP+PLAN+JOB)
            </button>
            <span class="text-[11px] text-slate-500 dark:text-white/60">
              (Solo Activos. En masivo: no se muestran payloads; logs solo para caídos)
            </span>
          </form>
        </div>

        <!-- TABLA -->
        <div class="overflow-x-auto">
  <table class="min-w-full text-xs">
    <thead class="bg-white sticky top-0 z-10 border-b border-slate-200 dark:bg-slate-950/40 dark:border-white/10">
      <tr class="text-[11px] uppercase tracking-wide text-slate-500 dark:text-white/50">
        <th class="px-3 py-3 text-left whitespace-nowrap">
          <input type="checkbox" id="checkAll" class="rounded border-slate-300 dark:border-white/20">
        </th>

        <?php
          $th = function($label, $key) use ($sort, $dir) {
              $nd = next_dir($sort, $dir, $key);
              $arrow = '';
              if ($sort === $key) $arrow = $dir === 'asc' ? ' ▲' : ' ▼';
              $url = q(['sort' => $key, 'dir' => $nd, 'page' => 1]);
              echo '<th class="px-3 py-3 text-left whitespace-nowrap">';
              echo '<a class="hover:text-slate-900 hover:underline dark:hover:text-white" href="'.$url.'">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').$arrow.'</a>';
              echo '</th>';
          };
        ?>

        <?php $th('RUT', 'Rut'); ?>
        <?php $th('Nombre completo', 'Nombre'); ?>
        <?php $th('Estado', 'Estado'); ?>
        <?php $th('División/Área', 'AreaADP'); ?>
        <?php $th('Cargo ADP', 'CargoADP'); ?>
        <?php $th('Empresa ADP', 'EmpresaADP'); ?>
        <?php $th('CCosto ADP', 'CCostoADP'); ?>
        <?php $th('Área Buk', 'AreaBuk'); ?>
        <?php $th('Cargo Buk', 'CargoBuk'); ?>
        <?php $th('CCosto Buk', 'CCostoBuk'); ?>
        <?php $th('Estado Buk', 'EstadoBuk'); ?>
        <?php $th('Origen ADP', 'OrigenADP'); ?>

        <th class="px-3 py-3 text-left whitespace-nowrap">Emp ID</th>
        <th class="px-3 py-3 text-left whitespace-nowrap">Job ID</th>
        <th class="px-3 py-3 text-left whitespace-nowrap">Acciones Buk</th>
      </tr>
    </thead>

    <tbody class="divide-y divide-slate-100 dark:divide-white/10">
      <?php if (empty($empleados)): ?>
        <tr>
          <td colspan="16" class="px-3 py-10 text-center text-xs text-slate-500 dark:text-white/60">
            No hay empleados para los filtros aplicados.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($empleados as $emp): ?>
          <?php
            $estadoBuk = $emp['estado_buk'] ?? 'no_enviado';

            $badge = 'bg-slate-50 text-slate-700 border-slate-200';
            $badgeDark = 'dark:bg-white/10 dark:text-white/70 dark:border-white/10';

            if ($estadoBuk === 'completo') {
              $badge = 'bg-emerald-50 text-emerald-700 border-emerald-200';
              $badgeDark = 'dark:bg-emerald-500/10 dark:text-emerald-200 dark:border-emerald-500/20';
            } elseif ($estadoBuk === 'emp_error' || $estadoBuk === 'emp_ok_job_error') {
              $badge = 'bg-red-50 text-red-700 border-red-200';
              $badgeDark = 'dark:bg-red-500/10 dark:text-red-200 dark:border-red-500/20';
            } elseif ($estadoBuk === 'emp_ok_sin_job') {
              $badge = 'bg-amber-50 text-amber-800 border-amber-200';
              $badgeDark = 'dark:bg-amber-500/10 dark:text-amber-200 dark:border-amber-500/20';
            } elseif ($estadoBuk === 'no_enviado') {
              $badge = 'bg-sky-50 text-sky-700 border-sky-200';
              $badgeDark = 'dark:bg-sky-500/10 dark:text-sky-200 dark:border-sky-500/20';
            }

            $estadoLabel = str_replace('_', ' ', $estadoBuk);

            $nombreCompleto = trim(
              ($emp['Nombres'] ?? '') . ' ' .
              ($emp['Apaterno'] ?? '') . ' ' .
              ($emp['Amaterno'] ?? '')
            );

            $rutVal   = (string)($emp['Rut'] ?? '');
            $isActivo = (($emp['Estado'] ?? '') === 'A');

            $empId = (int)($emp['buk_emp_id'] ?? 0);
            $jobId = (int)($emp['buk_cargo_id'] ?? 0);

            $empDone  = ($empId > 0);
$planDone = $empDone && in_array($estadoBuk, [
  'emp_plan_ok',
  'emp_plan_ok_job_error',
  'emp_ok_job_error',   // para datos antiguos que ya quedaron así
  'completo'
], true);
            $jobDone  = ($jobId > 0) || ($estadoBuk === 'completo');
            $allDone  = ($estadoBuk === 'completo');
          ?>

          <tr class="hover:bg-slate-50 odd:bg-white even:bg-white dark:hover:bg-white/5 dark:odd:bg-white/[0.02] dark:even:bg-transparent">
            <td class="px-3 py-3 whitespace-nowrap">
              <?php if ($isActivo): ?>
                <input type="checkbox"
                       class="rowCheck rounded border-slate-300 dark:border-white/20"
                       name="ruts[]"
                       value="<?php echo e($rutVal); ?>"
                       form="bulkSelectedForm">
              <?php else: ?>
                <span class="text-[11px] text-slate-400 dark:text-white/40">—</span>
              <?php endif; ?>
            </td>

            <td class="px-3 py-3 whitespace-nowrap font-medium text-slate-900 dark:text-white">
              <?php echo htmlspecialchars($emp['Rut'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td class="px-3 py-3 text-slate-700 dark:text-white/85">
              <?php echo htmlspecialchars($nombreCompleto, ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td class="px-3 py-3">
              <?php if (($emp['Estado'] ?? '') === 'A'): ?>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold border bg-emerald-50 text-emerald-700 border-emerald-200
                             dark:bg-emerald-500/10 dark:text-emerald-200 dark:border-emerald-500/20">Activo</span>
              <?php else: ?>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold border bg-slate-50 text-slate-700 border-slate-200
                             dark:bg-white/10 dark:text-white/70 dark:border-white/10">
                  <?php echo htmlspecialchars($emp['Estado'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                </span>
              <?php endif; ?>
            </td>

            <td class="px-3 py-3">
              <div class="max-w-[220px] truncate text-slate-700 dark:text-white/80"
                   title="<?php echo htmlspecialchars($emp['desc_unidad_adp'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($emp['desc_unidad_adp'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
              </div>
            </td>

            <td class="px-3 py-3">
              <div class="max-w-[220px] truncate text-slate-700 dark:text-white/80"
                   title="<?php echo htmlspecialchars($emp['desc_cargo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($emp['desc_cargo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
              </div>
            </td>

            <td class="px-3 py-3">
              <div class="max-w-[220px] truncate text-slate-700 dark:text-white/80"
                   title="<?php echo htmlspecialchars($emp['desc_empresa'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($emp['desc_empresa'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
              </div>
            </td>

            <td class="px-3 py-3 text-slate-700 dark:text-white/80">
              <?php echo htmlspecialchars($emp['cencos_adp'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td class="px-3 py-3">
              <div class="max-w-[220px] truncate text-slate-700 dark:text-white/80"
                   title="<?php echo htmlspecialchars($emp['area_buk_completo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($emp['area_buk'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
              </div>
            </td>

            <td class="px-3 py-3">
              <div class="max-w-[220px] truncate text-slate-700 dark:text-white/80"
                   title="<?php echo htmlspecialchars($emp['cargo_buk'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($emp['cargo_buk'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
              </div>
            </td>

            <td class="px-3 py-3 text-slate-700 dark:text-white/80">
              <?php echo htmlspecialchars($emp['cencos_buk'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td class="px-3 py-3">
              <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-semibold border <?php echo $badge; ?> <?php echo $badgeDark; ?>">
                <?php echo htmlspecialchars($estadoLabel, ENT_QUOTES, 'UTF-8'); ?>
              </span>
            </td>

            <td class="px-3 py-3 text-slate-700 dark:text-white/80">
              <?php echo htmlspecialchars($emp['origenadp'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td class="px-3 py-3 text-slate-700 dark:text-white/80">
              <?php echo $emp['buk_emp_id'] ? (int)$emp['buk_emp_id'] : '-'; ?>
            </td>

            <td class="px-3 py-3 text-slate-700 dark:text-white/80">
              <?php echo $emp['buk_cargo_id'] ? (int)$emp['buk_cargo_id'] : '-'; ?>
            </td>

            <!-- ACCIONES MANUAL -->
            <td class="px-3 py-3 whitespace-nowrap">
              <?php if (!$isActivo): ?>
                <span class="text-[11px] text-slate-400 dark:text-white/40">Sin acciones (inactivo)</span>

              <?php elseif ($allDone): ?>
                <span class="text-[11px] font-semibold text-emerald-700 dark:text-emerald-200">OK (completo)</span>

              <?php else: ?>
                <div class="flex flex-wrap gap-1.5">

                  <?php if (!$empDone): ?>
                    <form method="post" class="inline">
                      <input type="hidden" name="action" value="send_emp_one">
                      <input type="hidden" name="rut" value="<?php echo e($rutVal); ?>">
                      <button type="submit"
                        class="px-2.5 py-1.5 rounded-lg text-[11px] font-semibold bg-indigo-600 text-white hover:bg-indigo-700">
                        EMP
                      </button>
                    </form>
                  <?php endif; ?>

                  <?php if ($empDone && !$planDone): ?>
                    <form method="post" class="inline">
                      <input type="hidden" name="action" value="send_plan_one">
                      <input type="hidden" name="rut" value="<?php echo e($rutVal); ?>">
                      <button type="submit"
                        class="px-2.5 py-1.5 rounded-lg text-[11px] font-semibold bg-slate-900 text-white hover:bg-slate-800
                               dark:bg-white/15 dark:hover:bg-white/20">
                        PLAN
                      </button>
                    </form>
                  <?php endif; ?>

                  <?php if ($empDone && $planDone && !$jobDone): ?>
                    <form method="post" class="inline">
                      <input type="hidden" name="action" value="send_job_one">
                      <input type="hidden" name="rut" value="<?php echo e($rutVal); ?>">
                      <button type="submit"
                        class="px-2.5 py-1.5 rounded-lg text-[11px] font-semibold bg-emerald-600 text-white hover:bg-emerald-700">
                        JOB
                      </button>
                    </form>
                  <?php endif; ?>

                  <form method="post" class="inline">
                    <input type="hidden" name="action" value="send_all_one">
                    <input type="hidden" name="rut" value="<?php echo e($rutVal); ?>">
                    <button type="submit"
                      class="px-2.5 py-1.5 rounded-lg text-[11px] font-semibold border border-slate-200 bg-white hover:bg-slate-50 text-slate-700
                             dark:border-white/10 dark:bg-white/10 dark:hover:bg-white/15 dark:text-white">
                      TODO
                    </button>
                  </form>

                </div>
              <?php endif; ?>
            </td>
          </tr>

        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
        <!-- FOOTER -->
        <div class="px-4 py-4 border-t border-slate-100 text-xs text-slate-500 flex items-center justify-between
                    dark:border-white/10 dark:text-white/60">
          <div>
            Mostrando <span class="text-slate-900 font-semibold dark:text-white"><?php echo $desde; ?></span>–
            <span class="text-slate-900 font-semibold dark:text-white"><?php echo $hasta; ?></span> de
            <span class="text-slate-900 font-semibold dark:text-white"><?php echo $totalRegistros; ?></span>
          </div>
          <div class="flex items-center gap-1">
            <?php if ($page > 1): ?>
              <a href="<?php echo q(['page' => $page - 1]); ?>"
                 class="px-4 py-2 rounded-xl bg-white hover:bg-slate-50 border border-slate-200 text-slate-700
                        dark:bg-white/10 dark:hover:bg-white/15 dark:border-white/10 dark:text-white">
                &laquo; Anterior
              </a>
            <?php endif; ?>
            <?php if ($page < $totalPaginas): ?>
              <a href="<?php echo q(['page' => $page + 1]); ?>"
                 class="px-4 py-2 rounded-xl bg-white hover:bg-slate-50 border border-slate-200 text-slate-700
                        dark:bg-white/10 dark:hover:bg-white/15 dark:border-white/10 dark:text-white">
                Siguiente &raquo;
              </a>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </main>
  </div>
</div>

<script>
  const checkAll = document.getElementById('checkAll');
  if (checkAll) {
    checkAll.addEventListener('change', function () {
      document.querySelectorAll('.rowCheck').forEach(cb => {
        cb.checked = checkAll.checked;
      });
    });
  }
</script>
</body>
</html>  

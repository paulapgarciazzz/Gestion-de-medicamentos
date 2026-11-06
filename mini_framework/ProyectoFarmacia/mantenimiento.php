<?php
/*
 * Mantenimiento de la base de datos (solo Administrador).
 * - Data Warehouse: ejecuta el ETL (FarmaciaDW.etl.sp_ejecutar_etl), muestra su bitácora
 *   (etl.log_carga) y los reportes de ventas, inventario y caducidad (docs/sql/data_warehouse).
 * - Respaldos: completo, diferencial o de log con dbo.sp_respaldar_farmacia; cada respaldo
 *   queda registrado en la tabla dbo.respaldo (docs/sql/respaldos/06_respaldo_desde_aplicacion.sql).
 */
require_once __DIR__ . '/lib.php';
requerir_login(ROLES_ADMIN);
const TIPOS_RESPALDO = ['Completo' => 'Todo el contenido de la base.', 'Diferencial' => 'Solo lo que cambió desde el último completo.', 'Log' => 'Las transacciones desde el último respaldo de log.'];
$accion = $_GET['accion'] ?? 'pagina';

/* Errores de "no instalado" (base, tabla o procedimiento inexistente) con un mensaje que dice qué script ejecutar. */
function fallo_mantenimiento(PDOException $e, $script, $generico) {
    $n = (int)($e->errorInfo[1] ?? 0);
    if (in_array($n, [208, 911, 2812, 4060], true)) {
        error_log($e->getMessage());
        fallo('Falta instalar esta parte en SQL Server: ejecute ' . $script . ' (vea LEEME.md).', 500);
    }
    fallo_sql($e, $generico);
}
function solo_post() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Método no permitido.'); }
}
function duracion($inicio, $fin) {
    if (!$fin) return '—';
    $s = max(0, strtotime((string)$fin) - strtotime((string)$inicio));
    return $s < 60 ? $s . ' s' : floor($s / 60) . ' min ' . ($s % 60) . ' s';
}
const DW = 'docs/sql/data_warehouse/01 a 03';

/* ------------------------------ Data Warehouse ------------------------------ */
if ($accion === 'etl') {
    solo_post();
    try {
        $r = ejecutar_proc('EXEC FarmaciaDW.etl.sp_ejecutar_etl');
    } catch (PDOException $e) {
        fallo_mantenimiento($e, DW, 'El ETL no se pudo completar; los datos del Data Warehouse no cambiaron. Revise la bitácora.');
    }
    $texto = 'ETL ejecutado: carga N.º ' . (int)$r['id_carga'] . '.';
    hx_eventos(['etlEjecutado' => 1, 'aviso' => ['texto' => $texto]]);
    echo '<div class="notification is-success">' . h($texto) . ' Ventas nuevas: ' . (int)$r['hechos_insertados'] . ', ventas actualizadas: ' . (int)$r['hechos_actualizados']
        . ', lotes nuevos: ' . (int)$r['lotes_insertados'] . ', foto de inventario: ' . ((int)$r['inventario_insertados'] + (int)$r['inventario_actualizados']) . ' lotes.</div>';
    exit;
}

if ($accion === 'bitacora') {
    try {
        $rows = filas('SELECT TOP (10) * FROM FarmaciaDW.etl.log_carga ORDER BY id_carga DESC');
    } catch (PDOException $e) {
        fallo_mantenimiento($e, DW, 'No se pudo leer la bitácora del ETL.');
    }
    ?>
    <div class="table-container"><table class="table is-fullwidth is-narrow is-striped"><thead><tr><th>N.º</th><th>Inicio</th><th>Duración</th><th>Estado</th><th class="col-num">Ventas nuevas / act.</th><th class="col-num">Lotes nuevos</th><th class="col-num">Inventario</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="has-text-centered">Aún no se ha ejecutado el ETL.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $clase = ['Correcto' => 'is-vigente', 'Error' => 'is-vencido'][$r['estado']] ?? 'is-porvencer'; ?>
        <tr><td><?= (int)$r['id_carga'] ?></td><td><?= fecha_fmt($r['inicio'], true) ?></td><td><?= duracion($r['inicio'], $r['fin']) ?></td>
        <td><span class="tag <?= $clase ?>"><?= h($r['estado']) ?></span><?= $r['mensaje'] ? '<br><small class="has-text-danger">' . h($r['mensaje']) . '</small>' : '' ?></td>
        <td class="col-num"><?= (int)$r['hechos_insertados'] ?> / <?= (int)$r['hechos_actualizados'] ?></td>
        <td class="col-num"><?= (int)($r['lotes_insertados'] ?? 0) ?></td>
        <td class="col-num"><?= (int)($r['inventario_insertados'] ?? 0) + (int)($r['inventario_actualizados'] ?? 0) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php
    exit;
}

if ($accion === 'dw') {
    try {
        $ultima = escalar('SELECT MAX(fecha_key) FROM FarmaciaDW.dw.fact_inventario');
        $ventas = fila('SELECT COUNT(DISTINCT id_venta) AS ventas, ISNULL(SUM(total),0) AS total, ISNULL(SUM(utilidad_bruta),0) AS utilidad, ISNULL(SUM(ingreso_neto),0) AS neto
            FROM FarmaciaDW.dw.fact_ventas WHERE es_anulada = 0');
        $porMes = filas('SELECT f.anio_mes, f.nombre_mes, COUNT(DISTINCT h.id_venta) AS ventas, SUM(h.cantidad) AS unidades, SUM(h.total) AS total, SUM(h.utilidad_bruta) AS utilidad
            FROM FarmaciaDW.dw.fact_ventas h INNER JOIN FarmaciaDW.dw.dim_fecha f ON f.fecha_key = h.fecha_key
            WHERE h.es_anulada = 0 GROUP BY f.anio_mes, f.nombre_mes ORDER BY f.anio_mes DESC');
        $inv = fila('SELECT ISNULL(SUM(CASE WHEN es_vendible = 1 THEN unidades_disponibles END),0) AS unidades, ISNULL(SUM(CASE WHEN es_vendible = 1 THEN valor_costo END),0) AS costo,
            ISNULL(SUM(CASE WHEN es_vendible = 1 THEN valor_venta END),0) AS venta, ISNULL(SUM(valor_en_riesgo),0) AS riesgo
            FROM FarmaciaDW.dw.fact_inventario WHERE fecha_key = ?', [$ultima]);
        $porCategoria = filas('SELECT m.categoria, SUM(i.unidades_disponibles) AS unidades, SUM(i.valor_costo) AS costo, SUM(i.valor_venta) AS venta
            FROM FarmaciaDW.dw.fact_inventario i INNER JOIN FarmaciaDW.dw.dim_medicamento m ON m.medicamento_key = i.medicamento_key
            WHERE i.fecha_key = ? AND i.es_vendible = 1 GROUP BY m.categoria ORDER BY costo DESC', [$ultima]);
        $caducidad = filas("SELECT i.estado_caducidad, m.descripcion_completa AS medicamento, l.numero_lote, l.fecha_vencimiento, i.dias_para_vencer, i.unidades_disponibles, i.valor_en_riesgo
            FROM FarmaciaDW.dw.fact_inventario i INNER JOIN FarmaciaDW.dw.dim_lote l ON l.lote_key = i.lote_key
            INNER JOIN FarmaciaDW.dw.dim_medicamento m ON m.medicamento_key = i.medicamento_key
            WHERE i.fecha_key = ? AND i.estado_caducidad <> N'Vigente' AND i.unidades_disponibles > 0 ORDER BY i.dias_para_vencer", [$ultima]);
    } catch (PDOException $e) {
        fallo_mantenimiento($e, DW, 'No se pudieron leer los reportes del Data Warehouse.');
    }
    $foto = $ultima ? DateTime::createFromFormat('Ymd', (string)$ultima)->format('d/m/Y') : null;
    $margen = (float)$ventas['neto'] > 0 ? (float)$ventas['utilidad'] / (float)$ventas['neto'] * 100 : 0;
    ?>
    <p class="mb-3 has-text-grey">Datos del Data Warehouse (FarmaciaDW). Se actualizan al ejecutar el ETL<?= $foto ? '; última foto del inventario: <strong>' . $foto . '</strong>' : '' ?>.</p>
    <div class="columns is-multiline">
      <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">Ventas cargadas</div><div class="valor"><?= dinero($ventas['total']) ?></div><small><?= (int)$ventas['ventas'] ?> ventas (sin anuladas)</small></div></div>
      <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">Utilidad bruta real</div><div class="valor"><?= dinero($ventas['utilidad']) ?></div><small>Margen <?= number_format($margen, 2, ',', '.') ?> % · con el costo de cada lote</small></div></div>
      <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">Inventario vendible</div><div class="valor"><?= dinero($inv['costo'] ?? 0) ?></div><small><?= (int)($inv['unidades'] ?? 0) ?> unidades al costo · <?= dinero($inv['venta'] ?? 0) ?> a precio de venta</small></div></div>
      <div class="column is-3"><div class="tarjeta-dato <?= (float)($inv['riesgo'] ?? 0) > 0 ? 'alerta' : '' ?>"><div class="etiqueta">Dinero en riesgo por caducidad</div><div class="valor"><?= dinero($inv['riesgo'] ?? 0) ?></div><small>Unidades vencidas o que vencen en 90 días</small></div></div>
    </div>
    <div class="columns">
      <div class="column"><h3 class="title is-6">Ventas por mes</h3><div class="table-container"><table class="table is-fullwidth is-striped is-narrow"><thead><tr><th>Mes</th><th class="col-num">Ventas</th><th class="col-num">Unidades</th><th class="col-num">Total</th><th class="col-num">Utilidad</th></tr></thead><tbody>
        <?php if (!$porMes): ?><tr><td colspan="5" class="has-text-grey">Sin ventas cargadas. Ejecute el ETL.</td></tr><?php endif; ?>
        <?php foreach ($porMes as $m): ?><tr><td><?= h($m['nombre_mes']) ?> <?= h(substr($m['anio_mes'], 0, 4)) ?></td><td class="col-num"><?= (int)$m['ventas'] ?></td><td class="col-num"><?= (int)$m['unidades'] ?></td><td class="col-num"><?= dinero($m['total']) ?></td><td class="col-num"><?= dinero($m['utilidad']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
      <div class="column"><h3 class="title is-6">Inventario vendible por categoría</h3><div class="table-container"><table class="table is-fullwidth is-striped is-narrow"><thead><tr><th>Categoría</th><th class="col-num">Unidades</th><th class="col-num">Al costo</th><th class="col-num">A precio de venta</th></tr></thead><tbody>
        <?php if (!$porCategoria): ?><tr><td colspan="4" class="has-text-grey">Sin inventario cargado. Ejecute el ETL.</td></tr><?php endif; ?>
        <?php foreach ($porCategoria as $c): ?><tr><td><?= h($c['categoria']) ?></td><td class="col-num"><?= (int)$c['unidades'] ?></td><td class="col-num"><?= dinero($c['costo']) ?></td><td class="col-num"><?= dinero($c['venta']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
    </div>
    <h3 class="title is-6">Caducidad: lotes vencidos o por vencer (90 días)</h3>
    <div class="table-container"><table class="table is-fullwidth is-striped is-narrow"><thead><tr><th>Estado</th><th>Medicamento</th><th>Lote</th><th>Vence</th><th class="col-num">Días</th><th class="col-num">Unidades</th><th class="col-num">En riesgo</th></tr></thead><tbody>
      <?php if (!$caducidad): ?><tr><td colspan="7" class="has-text-grey">No hay lotes con unidades vencidas o por vencer en la última foto.</td></tr><?php endif; ?>
      <?php foreach ($caducidad as $c): ?><tr><td><span class="tag <?= $c['estado_caducidad'] === 'Vencido' ? 'is-vencido' : 'is-porvencer' ?>"><?= h($c['estado_caducidad']) ?></span></td>
        <td><?= h($c['medicamento']) ?></td><td><?= h($c['numero_lote']) ?></td><td><?= fecha_fmt($c['fecha_vencimiento']) ?></td>
        <td class="col-num"><?= (int)$c['dias_para_vencer'] ?></td><td class="col-num"><?= (int)$c['unidades_disponibles'] ?></td><td class="col-num"><strong><?= dinero($c['valor_en_riesgo']) ?></strong></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php
    exit;
}

/* ------------------------------ Respaldos ------------------------------ */
if ($accion === 'respaldo') {
    solo_post();
    $tipo = $_GET['tipo'] ?? '';
    if (!array_key_exists($tipo, TIPOS_RESPALDO)) fallo('Tipo de respaldo no válido.');
    try {
        $r = ejecutar_proc('EXEC dbo.sp_respaldar_farmacia @tipo=?, @id_usuario=?', [$tipo, usuario_actual()['id']]);
    } catch (PDOException $e) {
        hx_eventos(['respaldoCreado' => 1]);   // el intento fallido también queda en el historial
        fallo_mantenimiento($e, 'docs/sql/respaldos/06_respaldo_desde_aplicacion.sql', 'No se pudo crear el respaldo.');
    }
    $texto = 'Respaldo ' . strtolower($tipo) . ' creado correctamente.';
    hx_eventos(['respaldoCreado' => 1, 'aviso' => ['texto' => $texto]]);
    exit;   // sin contenido: limpia #resultado-respaldo; el éxito se muestra solo con la notificación
}

if ($accion === 'historial') {
    try {
        $rows = filas('SELECT TOP (15) r.*, ' . sql_nombre_usuario('u') . ' AS usuario FROM dbo.respaldo r INNER JOIN dbo.usuario u ON u.id_usuario = r.id_usuario ORDER BY r.id_respaldo DESC');
    } catch (PDOException $e) {
        fallo_mantenimiento($e, 'docs/sql/respaldos/06_respaldo_desde_aplicacion.sql', 'No se pudo leer el historial de respaldos.');
    }
    ?>
    <div class="table-container"><table class="table is-fullwidth is-narrow is-striped tabla-respaldos"><thead><tr><th>Fecha</th><th>Tipo</th><th>Estado</th><th>Usuario</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="4" class="has-text-centered">Aún no hay respaldos hechos desde la aplicación.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $clase = ['Correcto' => 'is-vigente', 'Error' => 'is-vencido'][$r['estado']] ?? 'is-porvencer'; ?>
        <tr title="<?= h(basename(str_replace('\\', '/', (string)$r['archivo']))) ?>">
        <td class="fecha"><?= fecha_fmt($r['inicio'], true) ?><small><?= duracion($r['inicio'], $r['fin']) ?></small></td>
        <td><?= h($r['tipo']) ?></td>
        <td><span class="tag <?= $clase ?>"><?= h($r['estado']) ?></span>
            <?= $r['estado'] === 'Error' ? '<small class="has-text-danger">' . h($r['mensaje']) . '</small>' : '' ?></td>
        <td><?= h($r['usuario']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php
    exit;
}

/* ------------------------------ Página ------------------------------ */
pagina_inicio('Mantenimiento', 'mantenimiento');
encabezado('Mantenimiento', 'Data Warehouse (ETL) y respaldos de la base de datos');
$cargando = 'hx-disabled-elt="this" hx-on::before-request="this.classList.add(\'is-loading\')" hx-on::after-request="this.classList.remove(\'is-loading\')"';
?>
<div class="columns">
  <div class="column is-7"><div class="box">
    <h2 class="title is-5">Data Warehouse</h2>
    <p class="mb-3">El ETL copia las <strong>ventas</strong>, el <strong>inventario</strong> y la <strong>caducidad</strong> de la base <em>farmacia</em> al Data Warehouse <em>FarmaciaDW</em>.
      Se puede ejecutar las veces que quiera: solo agrega lo nuevo y actualiza lo que cambió (por ejemplo, ventas anuladas). El inventario se guarda como una foto por día.</p>
    <button class="button is-primary" hx-post="mantenimiento.php?accion=etl" hx-target="#resultado-etl" hx-swap="innerHTML" <?= $cargando ?>
            hx-confirm="¿Ejecutar el ETL ahora? Se actualizará el Data Warehouse con los datos actuales.">Ejecutar ETL ahora</button>
    <div id="resultado-etl" class="mt-3" role="alert"></div>
    <h3 class="title is-6 mt-4">Bitácora de cargas</h3>
    <div hx-get="mantenimiento.php?accion=bitacora" hx-trigger="load, etlEjecutado from:body" hx-swap="innerHTML"></div>
  </div></div>
  <div class="column is-5"><div class="box">
    <h2 class="title is-5">Respaldos de farmacia</h2>
    <p class="mb-3">Se guardan en la carpeta de respaldos de SQL Server y cada uno queda registrado en la tabla <em>respaldo</em>.</p>
    <div class="buttons">
      <?php foreach (TIPOS_RESPALDO as $tipo => $desc): ?>
        <button class="button <?= $tipo === 'Completo' ? 'is-primary' : 'is-info' ?>" title="<?= h($desc) ?>" hx-post="mantenimiento.php?accion=respaldo&tipo=<?= $tipo ?>" hx-target="#resultado-respaldo" hx-swap="innerHTML" <?= $cargando ?>
                hx-confirm="¿Crear un respaldo <?= strtolower($tipo) ?> de la base farmacia? <?= h($desc) ?>">Respaldo <?= strtolower($tipo) ?></button>
      <?php endforeach; ?>
    </div>
    <p class="help mb-3">El diferencial y el de log necesitan un respaldo completo previo.</p>
    <div id="resultado-respaldo" role="alert"></div>
    <h3 class="title is-6 mt-4">Historial</h3>
    <div hx-get="mantenimiento.php?accion=historial" hx-trigger="load, respaldoCreado from:body" hx-swap="innerHTML"></div>
  </div></div>
</div>
<div class="box">
  <h2 class="title is-5">Análisis del Data Warehouse</h2>
  <div hx-get="mantenimiento.php?accion=dw" hx-trigger="load, etlEjecutado from:body" hx-swap="innerHTML"></div>
</div>
<?php pagina_fin();

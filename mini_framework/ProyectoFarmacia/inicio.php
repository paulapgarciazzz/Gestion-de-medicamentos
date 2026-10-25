<?php
/* Panel principal: indicadores, alertas y accesos a todos los módulos. */
require_once __DIR__ . '/lib.php';
requerir_login();
$hoy = 'CAST(GETDATE() AS DATE)';
$ventasHoy = fila("SELECT COUNT(*) AS n, ISNULL(SUM(total),0) AS t FROM dbo.venta WHERE estado<>'Anulada' AND CAST(fecha_venta AS DATE)=$hoy");
$ventasMes = fila("SELECT COUNT(*) AS n, ISNULL(SUM(total),0) AS t FROM dbo.venta WHERE estado<>'Anulada' AND fecha_venta>=DATEFROMPARTS(YEAR(GETDATE()),MONTH(GETDATE()),1)");
$nMed = (int)escalar('SELECT COUNT(*) FROM dbo.medicamento');
$nCli = (int)escalar('SELECT COUNT(*) FROM dbo.cliente');
$gestion = tiene_rol(ROLES_GESTION);
if ($gestion) {
    $porVencer = (int)escalar("SELECT COUNT(*) FROM dbo.detalle_lote dl " . sql_stock_apply('dl') . " WHERE dl.estado='Activo' AND dl.fecha_vencimiento>=$hoy AND dl.fecha_vencimiento<=DATEADD(DAY,90,$hoy) AND st.disponible>0");
    $vencidos = (int)escalar("SELECT COUNT(*) FROM dbo.detalle_lote dl " . sql_stock_apply('dl') . " WHERE dl.estado='Activo' AND dl.fecha_vencimiento<$hoy AND st.disponible>0");
    $bajo = (int)escalar('SELECT COUNT(*) FROM (' . sql_inventario() . ') x WHERE x.stock<=10');
    $proximos = filas("SELECT TOP 6 m.nombre, dl.numero_lote, dl.fecha_vencimiento, st.disponible FROM dbo.detalle_lote dl INNER JOIN dbo.medicamento m ON m.id_medicamento=dl.id_medicamento " . sql_stock_apply('dl')
        . " WHERE dl.estado='Activo' AND dl.fecha_vencimiento>=$hoy AND dl.fecha_vencimiento<=DATEADD(DAY,90,$hoy) AND st.disponible>0 ORDER BY dl.fecha_vencimiento");
}
$ultimas = filas('SELECT TOP 6 v.id_venta, v.fecha_venta, v.total, v.estado, ' . sql_nombre_cliente('c') . ' AS cliente FROM dbo.venta v INNER JOIN dbo.cliente c ON c.id_cliente=v.id_cliente ORDER BY v.fecha_venta DESC, v.id_venta DESC');

pagina_inicio('Inicio', 'inicio');
encabezado('Bienvenido, ' . usuario_actual()['nombre'], 'Resumen de la farmacia · ' . date('d/m/Y'), '<a class="button is-primary" href="venta_nueva.php">+ Nueva venta</a>' . ($gestion ? '<a class="button is-info" href="compra_nueva.php">+ Nueva compra</a>' : ''));
?>
<div class="columns is-multiline">
  <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">Ventas de hoy</div><div class="valor"><?= dinero($ventasHoy['t']) ?></div><small><?= (int)$ventasHoy['n'] ?> ventas</small></div></div>
  <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">Ventas del mes</div><div class="valor"><?= dinero($ventasMes['t']) ?></div><small><?= (int)$ventasMes['n'] ?> ventas</small></div></div>
  <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">Medicamentos</div><div class="valor"><?= $nMed ?></div><small>registrados</small></div></div>
  <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">Clientes</div><div class="valor"><?= $nCli ?></div><small>registrados</small></div></div>
  <?php if ($gestion): ?>
  <div class="column is-4"><a href="lotes.php" class="tarjeta-dato <?= $porVencer ? 'alerta' : '' ?>" style="display:block"><div class="etiqueta">Lotes por vencer (90 días)</div><div class="valor"><?= $porVencer ?></div></a></div>
  <div class="column is-4"><a href="lotes.php" class="tarjeta-dato <?= $vencidos ? 'alerta' : '' ?>" style="display:block"><div class="etiqueta">Lotes vencidos con existencias</div><div class="valor"><?= $vencidos ?></div></a></div>
  <div class="column is-4"><a href="inventario.php" class="tarjeta-dato <?= $bajo ? 'alerta' : '' ?>" style="display:block"><div class="etiqueta">Medicamentos con stock bajo (≤10)</div><div class="valor"><?= $bajo ?></div></a></div>
  <?php endif; ?>
</div>

<div class="columns">
  <div class="column"><div class="box"><h2 class="title is-5">Últimas ventas</h2>
    <table class="table is-fullwidth is-striped"><tbody>
    <?php if (!$ultimas): ?><tr><td class="has-text-grey">Aún no hay ventas.</td></tr><?php endif; ?>
    <?php foreach ($ultimas as $v): ?><tr><td><a href="venta_ver.php?id=<?= (int)$v['id_venta'] ?>">N.º <?= (int)$v['id_venta'] ?></a></td><td><?= h($v['cliente']) ?></td><td><?= fecha_fmt($v['fecha_venta'], true) ?></td>
      <td class="col-num"><?= dinero($v['total']) ?> <?= $v['estado'] === 'Anulada' ? '<span class="tag is-anulada">Anulada</span>' : '' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  <?php if ($gestion): ?>
  <div class="column"><div class="box"><h2 class="title is-5">Próximos a vencer</h2>
    <table class="table is-fullwidth is-striped"><tbody>
    <?php if (!$proximos): ?><tr><td class="has-text-grey">No hay lotes por vencer en los próximos 90 días.</td></tr><?php endif; ?>
    <?php foreach ($proximos as $p): ?><tr><td><strong><?= h($p['nombre']) ?></strong><br><small>Lote <?= h($p['numero_lote']) ?></small></td><td><?= fecha_fmt($p['fecha_vencimiento']) ?></td><td class="col-num"><?= (int)$p['disponible'] ?> u.</td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  <?php endif; ?>
</div>

<h2 class="title is-5 mt-5">Módulos</h2>
<div class="columns is-multiline">
<?php
$modulos = [
    ['venta_nueva.php', 'Nueva venta', 'Punto de venta con carrito', ROLES], ['ventas.php', 'Historial de ventas', 'Consultar, imprimir y anular', ROLES],
    ['compra_nueva.php', 'Nueva compra', 'Ingresar facturas y lotes', ROLES_GESTION], ['compras.php', 'Historial de compras', 'Facturas de proveedores', ROLES_GESTION],
    ['index.php', 'Medicamentos', 'Catálogo de productos', ROLES_GESTION], ['inventario.php', 'Existencias', 'Stock por medicamento', ROLES_GESTION],
    ['lotes.php', 'Lotes', 'Vencimientos y estado', ROLES_GESTION], ['categorias.php', 'Categorías', 'Clasificación', ROLES_GESTION],
    ['presentaciones.php', 'Presentaciones', 'Tabletas, jarabes…', ROLES_GESTION], ['presentaciones_asignar.php', 'Presentaciones por medicamento', 'Asignar presentaciones', ROLES_GESTION],
    ['clientes.php', 'Clientes', 'Datos de clientes', ROLES], ['recetas.php', 'Recetas', 'Recetas médicas', ROLES],
    ['proveedores.php', 'Proveedores', 'Empresas proveedoras', ROLES_GESTION], ['reportes.php', 'Reportes', 'Ventas, compras y utilidad', ROLES_ADMIN],
    ['usuarios.php', 'Usuarios', 'Accesos y roles', ROLES_ADMIN], ['auditoria.php', 'Auditoría', 'Quién hizo qué', ROLES_ADMIN],
];
foreach ($modulos as [$url, $t, $d, $roles]) {
    if (!tiene_rol($roles)) continue;
    echo '<div class="column is-3"><a class="tarjeta-modulo" href="' . $url . '"><strong>' . h($t) . '</strong><small>' . h($d) . '</small></a></div>';
}
?>
</div>
<?php pagina_fin();

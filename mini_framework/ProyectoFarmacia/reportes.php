<?php
/* Reportes de ventas, compras, productos más vendidos y utilidad estimada. */
require_once __DIR__ . '/lib.php';
requerir_login(ROLES_ADMIN);
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
if (!fecha_valida($desde)) $desde = date('Y-m-01');
if (!fecha_valida($hasta)) $hasta = date('Y-m-d');
$f = 'v.fecha_venta>=CAST(? AS DATE) AND v.fecha_venta<DATEADD(DAY,1,CAST(? AS DATE))';
$p = [$desde, $hasta];

$res = fila("SELECT COUNT(*) AS n, ISNULL(SUM(v.subtotal),0) AS sub, ISNULL(SUM(v.descuento),0) AS dsc, ISNULL(SUM(v.impuesto),0) AS imp, ISNULL(SUM(v.total),0) AS total FROM dbo.venta v WHERE v.estado<>'Anulada' AND $f", $p);
$anuladas = (int)escalar("SELECT COUNT(*) FROM dbo.venta v WHERE v.estado='Anulada' AND $f", $p);
$utilidad = (float)escalar("SELECT ISNULL(SUM(dv.subtotal-dv.descuento-dv.cantidad*m.precio_costo),0) FROM dbo.detalle_venta dv INNER JOIN dbo.venta v ON v.id_venta=dv.id_venta
    INNER JOIN dbo.detalle_lote dl ON dl.id_detalle_lote=dv.id_detalle_lote INNER JOIN dbo.medicamento m ON m.id_medicamento=dl.id_medicamento WHERE v.estado<>'Anulada' AND $f", $p);
$porMetodo = filas("SELECT v.metodo_pago, COUNT(*) AS n, SUM(v.total) AS total FROM dbo.venta v WHERE v.estado<>'Anulada' AND $f GROUP BY v.metodo_pago ORDER BY total DESC", $p);
$porDia = filas("SELECT CAST(v.fecha_venta AS DATE) AS dia, COUNT(*) AS n, SUM(v.total) AS total FROM dbo.venta v WHERE v.estado<>'Anulada' AND $f GROUP BY CAST(v.fecha_venta AS DATE) ORDER BY dia", $p);
$top = filas("SELECT TOP 10 m.nombre, m.concentracion, " . sql_presentacion('m') . " AS presentacion, SUM(dv.cantidad) AS unidades, SUM(dv.total) AS monto FROM dbo.detalle_venta dv INNER JOIN dbo.venta v ON v.id_venta=dv.id_venta
    INNER JOIN dbo.detalle_lote dl ON dl.id_detalle_lote=dv.id_detalle_lote INNER JOIN dbo.medicamento m ON m.id_medicamento=dl.id_medicamento WHERE v.estado<>'Anulada' AND $f
    GROUP BY m.id_medicamento, m.nombre, m.concentracion, m.id_presentacion ORDER BY unidades DESC", $p);
$compras = fila('SELECT COUNT(*) AS n, ISNULL(SUM(c.total),0) AS total FROM dbo.compra c WHERE c.fecha_compra>=CAST(? AS DATE) AND c.fecha_compra<DATEADD(DAY,1,CAST(? AS DATE))', $p);
$porProv = filas('SELECT p.nombre, COUNT(*) AS n, SUM(c.total) AS total FROM dbo.compra c INNER JOIN dbo.proveedor p ON p.id_proveedor=c.id_proveedor
    WHERE c.fecha_compra>=CAST(? AS DATE) AND c.fecha_compra<DATEADD(DAY,1,CAST(? AS DATE)) GROUP BY p.nombre ORDER BY total DESC', $p);
$maxDia = max(array_merge([1], array_map(fn($d) => (float)$d['total'], $porDia)));

pagina_inicio('Reportes', 'reportes');
encabezado('Reportes', 'Del ' . fecha_fmt($desde) . ' al ' . fecha_fmt($hasta), '<button class="button is-info" onclick="window.print()">Imprimir</button>');
?>
<form method="get" class="filtros-lista columns no-imprimir">
  <div class="column is-4"><label class="label">Desde</label><input class="input" type="date" name="desde" value="<?= h($desde) ?>"></div>
  <div class="column is-4"><label class="label">Hasta</label><input class="input" type="date" name="hasta" value="<?= h($hasta) ?>"></div>
  <div class="column is-4"><label class="label">&nbsp;</label><button class="button is-primary is-fullwidth" type="submit">Generar reporte</button></div>
</form>
<div class="columns is-multiline">
  <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">Total vendido</div><div class="valor"><?= dinero($res['total']) ?></div><small><?= (int)$res['n'] ?> ventas (<?= $anuladas ?> anuladas)</small></div></div>
  <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">IVA cobrado</div><div class="valor"><?= dinero($res['imp']) ?></div><small>Descuentos: <?= dinero($res['dsc']) ?></small></div></div>
  <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">Utilidad bruta estimada</div><div class="valor"><?= dinero($utilidad) ?></div><small>Con el precio de costo actual</small></div></div>
  <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">Total comprado</div><div class="valor"><?= dinero($compras['total']) ?></div><small><?= (int)$compras['n'] ?> compras</small></div></div>
</div>
<div class="columns">
  <div class="column"><div class="box"><h2 class="title is-5">Ventas por día</h2><table class="table is-fullwidth"><tbody>
    <?php if (!$porDia): ?><tr><td class="has-text-grey">Sin ventas en el período.</td></tr><?php endif; ?>
    <?php foreach ($porDia as $d): ?><tr><td style="width:90px"><?= fecha_fmt($d['dia']) ?></td><td><div class="barra-valor" style="width:<?= round((float)$d['total'] / $maxDia * 100) ?>%"></div></td><td class="col-num"><?= dinero($d['total']) ?> <small>(<?= (int)$d['n'] ?>)</small></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
  <div class="column"><div class="box"><h2 class="title is-5">Ventas por método de pago</h2><table class="table is-fullwidth is-striped"><tbody>
    <?php if (!$porMetodo): ?><tr><td class="has-text-grey">Sin ventas en el período.</td></tr><?php endif; ?>
    <?php foreach ($porMetodo as $m): ?><tr><td><?= h($m['metodo_pago']) ?></td><td><?= (int)$m['n'] ?> ventas</td><td class="col-num"><strong><?= dinero($m['total']) ?></strong></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
</div>
<div class="columns">
  <div class="column"><div class="box"><h2 class="title is-5">10 medicamentos más vendidos</h2><table class="table is-fullwidth is-striped"><thead><tr><th>Medicamento</th><th class="col-num">Unidades</th><th class="col-num">Monto</th></tr></thead><tbody>
    <?php if (!$top): ?><tr><td colspan="3" class="has-text-grey">Sin ventas en el período.</td></tr><?php endif; ?>
    <?php foreach ($top as $t): ?><tr><td><?= h($t['nombre']) ?> <small><?= h(trim($t['concentracion'] . ' ' . $t['presentacion'])) ?></small></td><td class="col-num"><?= (int)$t['unidades'] ?></td><td class="col-num"><?= dinero($t['monto']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
  <div class="column"><div class="box"><h2 class="title is-5">Compras por proveedor</h2><table class="table is-fullwidth is-striped"><thead><tr><th>Proveedor</th><th class="col-num">Compras</th><th class="col-num">Total</th></tr></thead><tbody>
    <?php if (!$porProv): ?><tr><td colspan="3" class="has-text-grey">Sin compras en el período.</td></tr><?php endif; ?>
    <?php foreach ($porProv as $r): ?><tr><td><?= h($r['nombre']) ?></td><td class="col-num"><?= (int)$r['n'] ?></td><td class="col-num"><?= dinero($r['total']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
</div>
<?php pagina_fin();

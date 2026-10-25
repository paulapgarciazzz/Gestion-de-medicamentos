<?php
/* Detalle imprimible de una compra. */
require_once __DIR__ . '/lib.php';
requerir_login(ROLES_GESTION);
$id = (int)($_GET['id'] ?? 0);
$c = fila('SELECT c.*, p.nombre AS proveedor, p.identificacion, p.telefono, p.condiciones_pago, ' . sql_nombre_usuario('u') . ' AS usuario
    FROM dbo.compra c INNER JOIN dbo.proveedor p ON p.id_proveedor=c.id_proveedor INNER JOIN dbo.usuario u ON u.id_usuario=c.id_usuario WHERE c.id_compra=?', [$id]);
pagina_inicio('Compra N.º ' . $id, 'compras');
if (!$c) { echo '<div class="notification is-warning">La compra no existe.</div><a class="button is-info" href="compras.php">Volver</a>'; pagina_fin(); exit; }
$det = filas('SELECT dc.*, m.nombre, m.concentracion, dl.numero_lote, dl.fecha_vencimiento FROM dbo.detalle_compra dc
    INNER JOIN dbo.medicamento m ON m.id_medicamento=dc.id_medicamento INNER JOIN dbo.detalle_lote dl ON dl.id_detalle_lote=dc.id_detalle_lote WHERE dc.id_compra=? ORDER BY dc.id_detalle_compra', [$id]);
?>
<div class="buttons no-imprimir" style="max-width:820px;margin:0 auto 1rem">
  <button class="button is-primary" onclick="window.print()">Imprimir</button><a class="button is-info" href="compra_nueva.php">+ Nueva compra</a><a class="button" href="compras.php">Historial</a>
</div>
<div class="ticket">
  <div class="level"><div class="level-left"><div><h1 class="title is-3">Compra N.º <?= (int)$c['id_compra'] ?></h1><p>Factura del proveedor: <strong><?= (int)$c['numero_factura'] ?></strong></p></div></div>
    <div class="level-right has-text-right"><div><p><?= fecha_fmt($c['fecha_compra']) ?></p><p>Registró: <?= h($c['usuario']) ?></p></div></div></div>
  <p class="mb-4"><strong><?= h($c['proveedor']) ?></strong> · Identificación: <?= h($c['identificacion']) ?><?= $c['telefono'] ? ' · Tel: ' . h($c['telefono']) : '' ?><?= $c['condiciones_pago'] ? '<br>Condiciones de pago: ' . h($c['condiciones_pago']) : '' ?></p>
  <table class="table is-fullwidth"><thead><tr><th>Medicamento</th><th>Lote</th><th>Vence</th><th class="col-num">Cant.</th><th class="col-num">Costo unit.</th><th class="col-num">Subtotal</th><th class="col-num">Total + IVA</th></tr></thead><tbody>
  <?php foreach ($det as $d): ?><tr><td><?= h($d['nombre']) ?> <small><?= h($d['concentracion']) ?></small></td><td><?= h($d['numero_lote']) ?></td><td><?= fecha_fmt($d['fecha_vencimiento']) ?></td>
    <td class="col-num"><?= (int)$d['cantidad'] ?></td><td class="col-num"><?= dinero($d['costo_unidad']) ?></td><td class="col-num"><?= dinero($d['subtotal']) ?></td><td class="col-num"><?= dinero($d['total']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <div class="columns is-justify-content-flex-end"><div class="column is-5"><table class="table is-fullwidth"><tbody>
    <tr><td>IVA</td><td class="col-num"><?= dinero($c['impuesto']) ?></td></tr><tr><td><strong>TOTAL</strong></td><td class="col-num"><strong class="is-size-4"><?= dinero($c['total']) ?></strong></td></tr></tbody></table></div></div>
</div>
<?php pagina_fin();

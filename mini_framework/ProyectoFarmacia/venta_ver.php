<?php
/* Factura / comprobante imprimible de una venta. */
require_once __DIR__ . '/lib.php';
requerir_login();
$id = (int)($_GET['id'] ?? 0);
$v = fila('SELECT v.*, ' . sql_nombre_cliente('c') . ' AS cliente, c.identificacion, c.telefono, c.correo AS cliente_correo,
    r.numero_receta, (SELECT TOP 1 ' . sql_nombre_usuario('u') . ' FROM dbo.auditoria a INNER JOIN dbo.usuario u ON u.id_usuario=a.id_usuario
        WHERE a.tabla_afectada=\'venta\' AND a.id_registro_afectado=v.id_venta AND a.accion=\'CREAR\' ORDER BY a.id_auditoria) AS atendido_por
    FROM dbo.venta v INNER JOIN dbo.cliente c ON c.id_cliente=v.id_cliente LEFT JOIN dbo.receta r ON r.id_receta=v.id_receta WHERE v.id_venta=?', [$id]);
pagina_inicio('Venta N.º ' . $id, 'ventas');
if (!$v) { echo '<div class="notification is-warning">La venta no existe.</div><a class="button is-info" href="ventas.php">Volver</a>'; pagina_fin(); exit; }
$det = filas('SELECT dv.*, m.nombre, m.concentracion, dl.numero_lote FROM dbo.detalle_venta dv INNER JOIN dbo.detalle_lote dl ON dl.id_detalle_lote=dv.id_detalle_lote
    INNER JOIN dbo.medicamento m ON m.id_medicamento=dl.id_medicamento WHERE dv.id_venta=? ORDER BY dv.id_detalle_venta', [$id]);
?>
<div class="buttons no-imprimir" style="max-width:820px;margin:0 auto 1rem">
  <button class="button is-primary" onclick="window.print()">Imprimir</button>
  <a class="button is-info" href="venta_nueva.php">+ Nueva venta</a><a class="button" href="ventas.php">Historial</a>
</div>
<div class="ticket">
  <?php if ($v['estado'] === 'Anulada'): ?><div class="sello-anulada">ANULADA</div><?php endif; ?>
  <div class="level"><div class="level-left"><div><h1 class="title is-3">Farmacia</h1><p>Factura / comprobante de venta</p></div></div>
    <div class="level-right has-text-right"><div><p class="title is-4">N.º <?= (int)$v['id_venta'] ?></p><p><?= fecha_fmt($v['fecha_venta'], true) ?></p></div></div></div>
  <div class="columns"><div class="column"><p class="label">Cliente</p><p><strong><?= h($v['cliente']) ?></strong><br>Identificación: <?= h($v['identificacion']) ?><?= $v['telefono'] ? '<br>Tel: ' . h($v['telefono']) : '' ?></p></div>
    <div class="column"><p class="label">Detalles</p><p>Pago: <?= h($v['metodo_pago']) ?><br>Estado: <?= h($v['estado']) ?><?= $v['numero_receta'] ? '<br>Receta N.º ' . (int)$v['numero_receta'] : '' ?><?= $v['atendido_por'] ? '<br>Atendido por: ' . h($v['atendido_por']) : '' ?></p></div></div>
  <table class="table is-fullwidth"><thead><tr><th>Medicamento</th><th>Lote</th><th class="col-num">Cant.</th><th class="col-num">Precio</th><th class="col-num">Desc.</th><th class="col-num">Total + IVA</th></tr></thead><tbody>
  <?php foreach ($det as $d): ?><tr><td><?= h($d['nombre']) ?> <small><?= h($d['concentracion']) ?></small></td><td><?= h($d['numero_lote']) ?></td><td class="col-num"><?= (int)$d['cantidad'] ?></td>
    <td class="col-num"><?= dinero($d['precio_unidad']) ?></td><td class="col-num"><?= dinero($d['descuento']) ?></td><td class="col-num"><?= dinero($d['total']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <div class="columns is-justify-content-flex-end"><div class="column is-5"><table class="table is-fullwidth"><tbody>
    <tr><td>Subtotal</td><td class="col-num"><?= dinero($v['subtotal']) ?></td></tr><tr><td>Descuento</td><td class="col-num"><?= dinero($v['descuento']) ?></td></tr>
    <tr><td>IVA</td><td class="col-num"><?= dinero($v['impuesto']) ?></td></tr><tr><td><strong>TOTAL</strong></td><td class="col-num"><strong class="is-size-4"><?= dinero($v['total']) ?></strong></td></tr></tbody></table></div></div>
  <p class="has-text-centered has-text-grey mt-4">Gracias por su compra</p>
</div>
<?php pagina_fin();

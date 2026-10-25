<?php
/* Historial de ventas con filtros, totales y anulación (la anulación devuelve el stock). */
require_once __DIR__ . '/lib.php';
requerir_login();
$accion = $_GET['accion'] ?? 'pagina';

if ($accion === 'lista') {
    $buscar = trim($_GET['buscar'] ?? '');
    $estado = $_GET['estado'] ?? '';
    $desde = $_GET['desde'] ?? '';
    $hasta = $_GET['hasta'] ?? '';
    $cond = []; $params = [];
    if ($buscar !== '') {
        $cond[] = '(c.primer_nombre LIKE ? OR c.primer_apellido LIKE ? OR c.identificacion LIKE ? OR CAST(v.id_venta AS VARCHAR(20))=?)';
        $params = array_merge($params, ['%' . $buscar . '%', '%' . $buscar . '%', '%' . $buscar . '%', $buscar]);
    }
    if (in_array($estado, ['Completada', 'Anulada'], true)) { $cond[] = 'v.estado=?'; $params[] = $estado; }
    if (fecha_valida($desde)) { $cond[] = 'v.fecha_venta>=CAST(? AS DATE)'; $params[] = $desde; }
    if (fecha_valida($hasta)) { $cond[] = 'v.fecha_venta<DATEADD(DAY,1,CAST(? AS DATE))'; $params[] = $hasta; }
    $where = $cond ? ' WHERE ' . implode(' AND ', $cond) : '';
    $from = 'FROM dbo.venta v INNER JOIN dbo.cliente c ON c.id_cliente=v.id_cliente';
    $res = fila("SELECT COUNT(*) AS n, ISNULL(SUM(CASE WHEN v.estado<>'Anulada' THEN v.total END),0) AS suma $from$where", $params);
    [$total, $paginas, $pagina, $offset] = paginar("SELECT COUNT(*) $from$where", $params, 10, $_GET['pagina'] ?? 1);
    $rows = filas_paginadas('SELECT v.*, ' . sql_nombre_cliente('c') . " AS cliente $from$where ORDER BY v.fecha_venta DESC, v.id_venta DESC", $params, $offset, 10);
    $puedeAnular = tiene_rol(ROLES_GESTION);
    ?>
    <p class="mb-3">Total vendido en el filtro (sin anuladas): <strong><?= dinero($res['suma']) ?></strong></p>
    <div class="table-container"><table class="table is-fullwidth is-striped is-hoverable"><thead><tr><th>N.º</th><th>Fecha</th><th>Cliente</th><th>Pago</th><th class="col-num">Subtotal</th><th class="col-num">Desc.</th><th class="col-num">IVA</th><th class="col-num">Total</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="10" class="has-text-centered">No hay ventas con ese filtro.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><tr><td><strong><?= (int)$r['id_venta'] ?></strong></td><td><?= fecha_fmt($r['fecha_venta'], true) ?></td><td><?= h($r['cliente']) ?></td><td><?= h($r['metodo_pago']) ?></td>
        <td class="col-num"><?= dinero($r['subtotal']) ?></td><td class="col-num"><?= dinero($r['descuento']) ?></td><td class="col-num"><?= dinero($r['impuesto']) ?></td><td class="col-num"><strong><?= dinero($r['total']) ?></strong></td>
        <td><span class="tag <?= $r['estado'] === 'Anulada' ? 'is-anulada' : 'is-vigente' ?>"><?= h($r['estado']) ?></span></td>
        <td><div class="buttons are-small"><a class="button is-info" href="venta_ver.php?id=<?= (int)$r['id_venta'] ?>">Ver</a>
        <?php if ($puedeAnular && $r['estado'] !== 'Anulada'): ?><button class="button is-danger" hx-post="ventas.php?accion=anular&id=<?= (int)$r['id_venta'] ?>" hx-target="#mensaje-lista" hx-confirm="¿Anular la venta N.º <?= (int)$r['id_venta'] ?>? Las unidades volverán al inventario.">Anular</button><?php endif; ?></div></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?= html_paginacion('ventas.php?accion=lista', $pagina, $paginas, $total, $offset, 10, 'ventas', '#filtros') ?>
    <?php
    exit;
}

if ($accion === 'anular') {
    requerir_login(ROLES_GESTION);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Método no permitido.'); }
    $id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) fallo('Venta no válida.');
    $s = $pdo->prepare("UPDATE dbo.venta SET estado='Anulada' WHERE id_venta=? AND estado<>'Anulada'");
    $s->execute([$id]);
    if (!$s->rowCount()) fallo('La venta no existe o ya estaba anulada.', 409);
    auditar('venta', $id, 'ANULAR');
    hx_eventos(['crudGuardado' => ['texto' => 'Venta anulada'], 'aviso' => ['texto' => 'Venta N.º ' . $id . ' anulada. El stock fue devuelto al inventario.']]);
    exit;
}

pagina_inicio('Ventas', 'ventas');
encabezado('Historial de ventas', 'Consulte, imprima o anule ventas', '<a class="button is-primary" href="venta_nueva.php">+ Nueva venta</a>');
?>
<form id="filtros" class="filtros-lista columns" hx-get="ventas.php?accion=lista" hx-target="#lista-registros" hx-trigger="input delay:350ms, change" onsubmit="return false">
  <div class="column is-4"><label class="label">Buscar</label><input class="input" type="search" name="buscar" placeholder="N.º de venta, cliente o identificación…"></div>
  <div class="column is-2"><label class="label">Desde</label><input class="input" type="date" name="desde"></div>
  <div class="column is-2"><label class="label">Hasta</label><input class="input" type="date" name="hasta"></div>
  <div class="column is-4"><label class="label">Estado</label><div class="select is-fullwidth"><select name="estado"><option value="">Todos</option><option>Completada</option><option>Anulada</option></select></div></div>
</form>
<div id="mensaje-lista" role="alert"></div>
<div id="lista-registros" hx-get="ventas.php?accion=lista" hx-trigger="load, crudGuardado from:body" hx-include="#filtros, #pagina-lista" hx-swap="innerHTML"></div>
<?php pagina_fin();

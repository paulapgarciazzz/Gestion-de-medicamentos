<?php
/* Historial de compras a proveedores. */
require_once __DIR__ . '/lib.php';
requerir_login(ROLES_GESTION);
$accion = $_GET['accion'] ?? 'pagina';

if ($accion === 'lista') {
    $buscar = trim($_GET['buscar'] ?? '');
    $desde = $_GET['desde'] ?? '';
    $hasta = $_GET['hasta'] ?? '';
    $cond = []; $params = [];
    if ($buscar !== '') { $cond[] = '(p.nombre LIKE ? OR CAST(c.numero_factura AS VARCHAR(20))=? OR CAST(c.id_compra AS VARCHAR(20))=?)'; $params = ['%' . $buscar . '%', $buscar, $buscar]; }
    if (fecha_valida($desde)) { $cond[] = 'c.fecha_compra>=CAST(? AS DATE)'; $params[] = $desde; }
    if (fecha_valida($hasta)) { $cond[] = 'c.fecha_compra<DATEADD(DAY,1,CAST(? AS DATE))'; $params[] = $hasta; }
    $where = $cond ? ' WHERE ' . implode(' AND ', $cond) : '';
    $from = 'FROM dbo.compra c INNER JOIN dbo.proveedor p ON p.id_proveedor=c.id_proveedor INNER JOIN dbo.usuario u ON u.id_usuario=c.id_usuario';
    $res = fila("SELECT COUNT(*) AS n, ISNULL(SUM(c.total),0) AS suma $from$where", $params);
    [$total, $paginas, $pagina, $offset] = paginar("SELECT COUNT(*) $from$where", $params, 10, $_GET['pagina'] ?? 1);
    $rows = filas_paginadas('SELECT c.*, p.nombre AS proveedor, ' . sql_nombre_usuario('u') . " AS usuario $from$where ORDER BY c.fecha_compra DESC, c.id_compra DESC", $params, $offset, 10);
    ?>
    <p class="mb-3">Total comprado en el filtro: <strong><?= dinero($res['suma']) ?></strong></p>
    <div class="table-container"><table class="table is-fullwidth is-striped is-hoverable"><thead><tr><th>N.º</th><th>Fecha</th><th>Proveedor</th><th>Factura</th><th>Registró</th><th class="col-num">IVA</th><th class="col-num">Total</th><th>Acciones</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="has-text-centered">No hay compras con ese filtro.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><tr><td><strong><?= (int)$r['id_compra'] ?></strong></td><td><?= fecha_fmt($r['fecha_compra']) ?></td><td><?= h($r['proveedor']) ?></td><td><?= (int)$r['numero_factura'] ?></td><td><?= h($r['usuario']) ?></td>
        <td class="col-num"><?= dinero($r['impuesto']) ?></td><td class="col-num"><strong><?= dinero($r['total']) ?></strong></td>
        <td><a class="button is-info is-small" href="compra_ver.php?id=<?= (int)$r['id_compra'] ?>">Ver</a></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?= html_paginacion('compras.php?accion=lista', $pagina, $paginas, $total, $offset, 10, 'compras', '#filtros') ?>
    <?php
    exit;
}

pagina_inicio('Compras', 'compras');
encabezado('Historial de compras', 'Facturas de proveedores registradas', '<a class="button is-primary" href="compra_nueva.php">+ Nueva compra</a>');
?>
<form id="filtros" class="filtros-lista columns" hx-get="compras.php?accion=lista" hx-target="#lista-registros" hx-trigger="input delay:350ms, change" onsubmit="return false">
  <div class="column is-6"><label class="label">Buscar</label><input class="input" type="search" name="buscar" placeholder="Proveedor, N.º de factura o N.º de compra…"></div>
  <div class="column is-3"><label class="label">Desde</label><input class="input" type="date" name="desde"></div>
  <div class="column is-3"><label class="label">Hasta</label><input class="input" type="date" name="hasta"></div>
</form>
<div id="lista-registros" hx-get="compras.php?accion=lista" hx-trigger="load" hx-include="#filtros, #pagina-lista" hx-swap="innerHTML"></div>
<?php pagina_fin();

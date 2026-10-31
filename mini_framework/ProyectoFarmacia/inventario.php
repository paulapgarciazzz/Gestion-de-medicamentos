<?php
/* Existencias por medicamento (suma de los lotes activos y no vencidos). */
require_once __DIR__ . '/lib.php';
requerir_login(ROLES_GESTION);
$accion = $_GET['accion'] ?? 'pagina';

if ($accion === 'lista') {
    $buscar = trim($_GET['buscar'] ?? '');
    $filtro = $_GET['filtro'] ?? 'todos';
    $umbral = max(0, (int)($_GET['umbral'] ?? 10));
    $cond = []; $params = [];
    if ($buscar !== '') { $cond[] = '(x.nombre LIKE ? OR x.categoria LIKE ?)'; $params = ['%' . $buscar . '%', '%' . $buscar . '%']; }
    if ($filtro === 'bajo')    { $cond[] = 'x.stock<=?'; $params[] = $umbral; }
    if ($filtro === 'sin')     { $cond[] = 'x.stock<=0'; }
    if ($filtro === 'vencido') { $cond[] = 'x.vencido>0'; }
    $where = $cond ? ' WHERE ' . implode(' AND ', $cond) : '';
    $base = '(' . sql_inventario() . ') x';
    $resumen = fila("SELECT COUNT(*) AS n, ISNULL(SUM(x.stock*x.precio_costo),0) AS costo, ISNULL(SUM(x.stock*x.precio_venta),0) AS venta FROM $base$where", $params);
    [$total, $paginas, $pagina, $offset] = paginar("SELECT COUNT(*) FROM $base$where", $params, 10, $_GET['pagina'] ?? 1);
    $rows = filas_paginadas("SELECT * FROM $base$where ORDER BY x.nombre", $params, $offset, 10);
    ?>
    <div class="columns mb-2">
      <div class="column"><div class="tarjeta-dato"><div class="etiqueta">Medicamentos en el filtro</div><div class="valor"><?= (int)$resumen['n'] ?></div></div></div>
      <div class="column"><div class="tarjeta-dato"><div class="etiqueta">Valor de existencias al costo</div><div class="valor"><?= dinero($resumen['costo']) ?></div></div></div>
      <div class="column"><div class="tarjeta-dato"><div class="etiqueta">Valor de existencias a precio de venta</div><div class="valor"><?= dinero($resumen['venta']) ?></div></div></div>
    </div>
    <div class="table-container"><table class="table is-fullwidth is-striped is-hoverable"><thead><tr><th>ID</th><th>Medicamento</th><th>Categoría</th><th class="col-num">Costo</th><th class="col-num">Venta</th><th class="col-num">Disponible</th><th class="col-num">Vencido</th><th>Próx. vencimiento</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="has-text-centered">No hay medicamentos con ese filtro.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $s = (int)$r['stock']; ?><tr><td><?= (int)$r['id_medicamento'] ?></td>
        <td><strong><?= h($r['nombre']) ?></strong> <small><?= h(trim($r['concentracion'] . ' ' . $r['presentacion'])) ?></small></td><td><?= h($r['categoria']) ?></td>
        <td class="col-num"><?= dinero($r['precio_costo']) ?></td><td class="col-num"><?= dinero($r['precio_venta']) ?></td>
        <td class="col-num"><strong><?= $s ?></strong> <?= $s <= 0 ? '<span class="tag is-vencido">Sin stock</span>' : ($s <= $umbral ? '<span class="tag is-porvencer">Bajo</span>' : '') ?></td>
        <td class="col-num"><?= (int)$r['vencido'] > 0 ? '<span class="tag is-vencido">' . (int)$r['vencido'] . '</span>' : '0' ?></td>
        <td><?= fecha_fmt($r['prox_venc']) ?: '—' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?= html_paginacion('inventario.php?accion=lista', $pagina, $paginas, $total, $offset, 10, 'medicamentos', '#filtros') ?>
    <?php
    exit;
}

pagina_inicio('Existencias', 'inventario');
encabezado('Existencias (stock)', 'Unidades disponibles por medicamento: solo lotes activos y no vencidos', '<a class="button is-info" href="lotes.php">Ver lotes</a><a class="button is-primary" href="compra_nueva.php">+ Registrar compra</a>');
?>
<form id="filtros" class="filtros-lista columns" hx-get="inventario.php?accion=lista" hx-target="#lista-registros" hx-trigger="input delay:350ms, change" onsubmit="return false">
  <div class="column is-6"><input class="input" type="search" name="buscar" placeholder="Buscar por medicamento o categoría…"></div>
  <div class="column is-4"><div class="select is-fullwidth"><select name="filtro">
    <option value="todos">Todos</option><option value="bajo">Stock bajo</option><option value="sin">Sin stock</option><option value="vencido">Con unidades vencidas</option>
  </select></div></div>
  <div class="column is-2"><input class="input" type="number" min="0" name="umbral" value="10" title="Se considera stock bajo cuando hay esta cantidad o menos"></div>
</form>
<div id="lista-registros" hx-get="inventario.php?accion=lista" hx-trigger="load" hx-include="#filtros, #pagina-lista" hx-swap="innerHTML"></div>
<?php pagina_fin();

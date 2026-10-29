<?php
/* Tablas lote y detalle_lote: control de lotes, vencimientos y existencias por lote. */
require_once __DIR__ . '/lib.php';
requerir_login(ROLES_GESTION);
$accion = $_GET['accion'] ?? 'pagina';
const ESTADOS_LOTE = ['Activo' => 'Activo (se puede vender)', 'Inactivo' => 'Inactivo (bloqueado)', 'Retirado' => 'Retirado (dañado / retiro de mercado)'];

function badge_vencimiento($venc, $disp) {
    $v = substr((string)$venc, 0, 10);
    $hoy = date('Y-m-d');
    if ($v < $hoy) return '<span class="tag is-vencido">Vencido</span>';
    if ($v <= date('Y-m-d', strtotime('+90 days')) && $disp > 0) return '<span class="tag is-porvencer">Vence en ' . (int)((strtotime($v) - strtotime($hoy)) / 86400) . ' días</span>';
    return '<span class="tag is-vigente">Vigente</span>';
}

if ($accion === 'lista') {
    $buscar = trim($_GET['buscar'] ?? '');
    $filtro = $_GET['filtro'] ?? 'todos';
    $hoy = 'CAST(GETDATE() AS DATE)';
    $from = 'FROM dbo.detalle_lote dl INNER JOIN dbo.lote l ON l.id_lote=dl.id_lote INNER JOIN dbo.medicamento m ON m.id_medicamento=dl.id_medicamento ' . sql_stock_apply('dl');
    $cond = []; $params = [];
    if ($buscar !== '') { $cond[] = '(m.nombre LIKE ? OR dl.numero_lote LIKE ?)'; $params = ['%' . $buscar . '%', '%' . $buscar . '%']; }
    switch ($filtro) {
        case 'vigentes':   $cond[] = sql_lote_estado_vendible('dl') . " AND dl.fecha_vencimiento>=$hoy AND st.disponible>0"; break;
        case 'por_vencer': $cond[] = "dl.fecha_vencimiento>=$hoy AND dl.fecha_vencimiento<=DATEADD(DAY,90,$hoy) AND st.disponible>0"; break;
        case 'vencidos':   $cond[] = "dl.fecha_vencimiento<$hoy AND st.disponible>0"; break;
        case 'agotados':   $cond[] = 'st.disponible<=0'; break;
    }
    $where = $cond ? ' WHERE ' . implode(' AND ', $cond) : '';
    [$total, $paginas, $pagina, $offset] = paginar("SELECT COUNT(*) $from$where", $params, 10, $_GET['pagina'] ?? 1);
    $rows = filas_paginadas("SELECT dl.id_detalle_lote, dl.numero_lote, dl.fecha_fabricacion, dl.fecha_vencimiento, dl.cantidad_inicial, dl.estado,
        m.nombre, m.concentracion, st.disponible $from$where ORDER BY dl.fecha_vencimiento, m.nombre", $params, $offset, 10);
    ?>
    <div class="table-container"><table class="table is-fullwidth is-striped is-hoverable"><thead><tr><th>ID</th><th>Medicamento</th><th>Lote</th><th>Fabricación</th><th>Vencimiento</th><th class="col-num">Inicial</th><th class="col-num">Vendido</th><th class="col-num">Disponible</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="10" class="has-text-centered">No hay lotes con ese filtro. Los lotes se crean al registrar una compra.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $disp = (int)$r['disponible']; ?><tr><td><?= (int)$r['id_detalle_lote'] ?></td>
        <td><strong><?= h($r['nombre']) ?></strong> <small><?= h($r['concentracion']) ?></small></td><td><?= h($r['numero_lote']) ?></td>
        <td><?= fecha_fmt($r['fecha_fabricacion']) ?></td><td><?= fecha_fmt($r['fecha_vencimiento']) ?><br><?= badge_vencimiento($r['fecha_vencimiento'], $disp) ?></td>
        <td class="col-num"><?= (int)$r['cantidad_inicial'] ?></td><td class="col-num"><?= (int)$r['cantidad_inicial'] - $disp ?></td>
        <td class="col-num"><strong><?= $disp ?></strong><?= $disp <= 0 ? ' <span class="tag is-neutro">Agotado</span>' : '' ?></td>
        <td><span class="tag <?= lote_estado_vendible($r['estado']) ? 'is-vigente' : 'is-vencido' ?>"><?= h($r['estado']) ?></span></td>
        <td><button class="button is-info is-small" hx-get="lotes.php?accion=form&id=<?= (int)$r['id_detalle_lote'] ?>" hx-target="#modal-contenido">Estado</button></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?= html_paginacion('lotes.php?accion=lista', $pagina, $paginas, $total, $offset, 10, 'lotes', '#filtros') ?>
    <?php
    exit;
}

if ($accion === 'form') {
    $id = (int)($_GET['id'] ?? 0);
    $d = fila('SELECT dl.id_detalle_lote, dl.id_lote, dl.numero_lote, dl.estado, dl.fecha_fabricacion, dl.fecha_vencimiento,
            dl.cantidad_inicial, st.disponible, m.nombre, m.concentracion
        FROM dbo.detalle_lote dl INNER JOIN dbo.medicamento m ON m.id_medicamento=dl.id_medicamento ' . sql_stock_apply('dl') . '
        WHERE dl.id_detalle_lote=?', [$id]);
    if (!$d) { http_response_code(404); exit('<div class="notification is-warning">El lote no existe.</div>'); }
    modal_inicio('Estado del lote ' . $d['numero_lote'], 'lotes.php?accion=guardar', '560px');
    $vendido = (int)$d['cantidad_inicial'] - (int)$d['disponible'];
    $estadoActual = lote_estado_vendible($d['estado']) ? 'Activo' : $d['estado'];
    echo '<input type="hidden" name="id" value="' . $id . '"><div class="content"><p><strong>' . h($d['nombre']) . '</strong> <small>' . h($d['concentracion']) . '</small></p>'
        . '<p><strong>ID de lote:</strong> ' . (int)$d['id_lote'] . '<br><strong>Número:</strong> ' . h($d['numero_lote'])
        . '<br><strong>Fabricación:</strong> ' . fecha_fmt($d['fecha_fabricacion']) . '<br><strong>Vencimiento:</strong> ' . fecha_fmt($d['fecha_vencimiento'])
        . '<br><strong>Cantidad inicial:</strong> ' . (int)$d['cantidad_inicial'] . '<br><strong>Vendido:</strong> ' . $vendido
        . '<br><strong>Disponible:</strong> ' . (int)$d['disponible'] . '</p></div>'
        . '<div class="field"><label class="label">Estado del lote</label><div class="select is-fullwidth"><select name="estado">';
    foreach (ESTADOS_LOTE as $k => $t) echo '<option value="' . $k . '" ' . ($estadoActual === $k ? 'selected' : '') . '>' . h($t) . '</option>';
    echo '</select></div></div><p class="help">Un lote que no está «Activo» o que ya venció no aparece en el punto de venta.</p>';
    modal_fin('Guardar estado');
    exit;
}

if ($accion === 'guardar') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Método no permitido.'); }
    $id = (int)($_POST['id'] ?? 0);
    $estado = $_POST['estado'] ?? '';
    if (!array_key_exists($estado, ESTADOS_LOTE)) fallo('Estado no válido.');
    try {
        $pdo->beginTransaction();
        $idLote = escalar('SELECT id_lote FROM dbo.detalle_lote WHERE id_detalle_lote=?', [$id]);
        if (!$idLote) { $pdo->rollBack(); fallo('El lote no existe.', 404); }
        $pdo->prepare('UPDATE dbo.detalle_lote SET estado=? WHERE id_detalle_lote=?')->execute([$estado, $id]);
        $pdo->prepare('UPDATE dbo.lote SET estado=? WHERE id_lote=?')->execute([$estado, $idLote]);
        $pdo->commit();
        auditar('detalle_lote', $id, 'ACTUALIZAR');
        respuesta_ok('Estado del lote actualizado.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        fallo('No se pudo actualizar el lote.', 500);
    }
    exit;
}

pagina_inicio('Lotes', 'lotes');
encabezado('Lotes', 'Control de lotes, vencimientos y existencias por lote', '<a class="button is-info" href="compra_nueva.php">+ Registrar compra (crea lotes)</a>');
?>
<form id="filtros" class="filtros-lista columns" hx-get="lotes.php?accion=lista" hx-target="#lista-registros" hx-trigger="input delay:350ms, change" onsubmit="return false">
  <div class="column is-8"><input class="input" type="search" name="buscar" placeholder="Buscar por medicamento o número de lote…"></div>
  <div class="column is-4"><div class="select is-fullwidth"><select name="filtro">
    <option value="todos">Todos los lotes</option><option value="vigentes">Vigentes con existencias</option>
    <option value="por_vencer">Por vencer (próximos 90 días)</option><option value="vencidos">Vencidos con existencias</option><option value="agotados">Agotados</option>
  </select></div></div>
</form>
<div id="lista-registros" hx-get="lotes.php?accion=lista" hx-trigger="load, crudGuardado from:body" hx-include="#filtros, #pagina-lista" hx-swap="innerHTML"></div>
<?php pagina_fin();

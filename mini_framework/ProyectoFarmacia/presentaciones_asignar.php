<?php
/* Tabla medicamento_presentacion: qué presentaciones tiene cada medicamento. */
require_once __DIR__ . '/lib.php';
requerir_login(ROLES_GESTION);
$accion = $_GET['accion'] ?? 'pagina';

if ($accion === 'lista') {
    $buscar = trim($_GET['buscar'] ?? '');
    $from = 'FROM dbo.medicamento m INNER JOIN dbo.categoria c ON c.id_categoria=m.id_categoria';
    $where = '';
    $params = [];
    if ($buscar !== '') { $where = ' WHERE m.nombre LIKE ? OR c.nombre LIKE ?'; $params = ['%' . $buscar . '%', '%' . $buscar . '%']; }
    [$total, $paginas, $pagina, $offset] = paginar("SELECT COUNT(*) $from$where", $params, 10, $_GET['pagina'] ?? 1);
    $rows = filas_paginadas("SELECT m.id_medicamento, m.nombre, m.concentracion, c.nombre AS categoria,
        STUFF((SELECT ', ' + p.nombre FROM dbo.medicamento_presentacion mp INNER JOIN dbo.presentacion p ON p.id_presentacion=mp.id_presentacion
               WHERE mp.id_medicamento=m.id_medicamento ORDER BY p.nombre FOR XML PATH(''), TYPE).value('.','NVARCHAR(MAX)'),1,2,'') AS presentaciones
        $from$where ORDER BY m.nombre", $params, $offset, 10);
    ?>
    <div class="table-container"><table class="table is-fullwidth is-striped is-hoverable"><thead><tr><th>ID</th><th>Medicamento</th><th>Categoría</th><th>Presentaciones asignadas</th><th>Acciones</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="has-text-centered">No hay medicamentos.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><tr><td><?= (int)$r['id_medicamento'] ?></td>
        <td><strong><?= h($r['nombre']) ?></strong> <small><?= h($r['concentracion']) ?></small></td><td><?= h($r['categoria']) ?></td>
        <td><?= $r['presentaciones'] ? h($r['presentaciones']) : '<span class="has-text-grey">Ninguna</span>' ?></td>
        <td><button class="button is-info is-small" hx-get="presentaciones_asignar.php?accion=form&id=<?= (int)$r['id_medicamento'] ?>" hx-target="#modal-contenido">Asignar</button></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?= html_paginacion('presentaciones_asignar.php?accion=lista', $pagina, $paginas, $total, $offset, 10, 'medicamentos') ?>
    <?php
    exit;
}

if ($accion === 'form') {
    $id = (int)($_GET['id'] ?? 0);
    $m = fila('SELECT id_medicamento, nombre FROM dbo.medicamento WHERE id_medicamento=?', [$id]);
    if (!$m) { http_response_code(404); exit('<div class="notification is-warning">El medicamento no existe.</div>'); }
    $todas = filas('SELECT id_presentacion, nombre FROM dbo.presentacion ORDER BY nombre');
    $asig = array_column(filas('SELECT id_presentacion FROM dbo.medicamento_presentacion WHERE id_medicamento=?', [$id]), 'id_presentacion');
    modal_inicio('Presentaciones de ' . $m['nombre'], 'presentaciones_asignar.php?accion=guardar', '600px');
    echo '<input type="hidden" name="id_medicamento" value="' . $id . '">';
    if (!$todas) {
        echo '<div class="notification is-warning">Primero registre presentaciones en <a href="presentaciones.php">Presentaciones</a>.</div>';
    } else {
        echo '<p class="mb-3">Marque las presentaciones en las que se comercializa este medicamento:</p><div class="columns is-multiline">';
        foreach ($todas as $p) {
            echo '<div class="column is-6"><label class="checkbox"><input type="checkbox" name="presentaciones[]" value="' . (int)$p['id_presentacion'] . '" '
                . (in_array($p['id_presentacion'], $asig) ? 'checked' : '') . '> ' . h($p['nombre']) . '</label></div>';
        }
        echo '</div>';
    }
    modal_fin('Guardar asignación');
    exit;
}

if ($accion === 'guardar') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Método no permitido.'); }
    $id = (int)($_POST['id_medicamento'] ?? 0);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['presentaciones'] ?? [])), fn($x) => $x > 0)));
    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM dbo.medicamento_presentacion WHERE id_medicamento=?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO dbo.medicamento_presentacion (id_presentacion,id_medicamento) VALUES (?,?)');
        foreach ($ids as $p) $ins->execute([$p, $id]);
        $pdo->commit();
        auditar('medicamento_presentacion', $id, 'ACTUALIZAR');
        respuesta_ok('Presentaciones actualizadas.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        fallo('No se pudo guardar la asignación.', 500);
    }
    exit;
}

pagina_inicio('Presentaciones por medicamento', 'asignar');
encabezado('Presentaciones por medicamento', 'Asigne las presentaciones disponibles a cada medicamento', '<a class="button is-info" href="presentaciones.php">Administrar presentaciones</a>');
?>
<div class="field"><div class="control"><input class="input" type="search" name="buscar" placeholder="Buscar medicamento o categoría…" hx-get="presentaciones_asignar.php?accion=lista" hx-trigger="keyup changed delay:400ms, search" hx-target="#lista-registros" hx-include="this"></div></div>
<div id="lista-registros" hx-get="presentaciones_asignar.php?accion=lista" hx-trigger="load, crudGuardado from:body" hx-include="[name=buscar], #pagina-lista" hx-swap="innerHTML"></div>
<?php pagina_fin();

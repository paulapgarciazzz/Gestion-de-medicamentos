<?php
/* Tabla auditoria: registro de acciones de los usuarios (solo lectura). */
require_once __DIR__ . '/lib.php';
requerir_login(ROLES_ADMIN);
$accion = $_GET['accion'] ?? 'pagina';

if ($accion === 'lista') {
    $idu = (int)($_GET['usuario'] ?? 0);
    $tabla = trim($_GET['tabla'] ?? '');
    $acc = trim($_GET['acc'] ?? '');
    $desde = $_GET['desde'] ?? '';
    $hasta = $_GET['hasta'] ?? '';
    $cond = []; $params = [];
    if ($idu) { $cond[] = 'a.id_usuario=?'; $params[] = $idu; }
    if ($tabla !== '') { $cond[] = 'a.tabla_afectada=?'; $params[] = $tabla; }
    if ($acc !== '') { $cond[] = 'a.accion=?'; $params[] = $acc; }
    if (fecha_valida($desde)) { $cond[] = 'a.fecha_hora>=CAST(? AS DATE)'; $params[] = $desde; }
    if (fecha_valida($hasta)) { $cond[] = 'a.fecha_hora<DATEADD(DAY,1,CAST(? AS DATE))'; $params[] = $hasta; }
    $where = $cond ? ' WHERE ' . implode(' AND ', $cond) : '';
    $from = 'FROM dbo.auditoria a INNER JOIN dbo.usuario u ON u.id_usuario=a.id_usuario';
    [$total, $paginas, $pagina, $offset] = paginar("SELECT COUNT(*) $from$where", $params, 15, $_GET['pagina'] ?? 1);
    $rows = filas_paginadas('SELECT a.*, ' . sql_nombre_usuario('u') . " AS usuario $from$where ORDER BY a.fecha_hora DESC, a.id_auditoria DESC", $params, $offset, 15);
    $clase = ['CREAR' => 'is-vigente', 'ACTUALIZAR' => 'is-neutro', 'ELIMINAR' => 'is-vencido', 'ANULAR' => 'is-vencido', 'LOGIN' => 'is-porvencer'];
    ?>
    <div class="table-container"><table class="table is-fullwidth is-striped is-hoverable"><thead><tr><th>ID</th><th>Fecha y hora</th><th>Usuario</th><th>Acción</th><th>Tabla</th><th>ID del registro</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="has-text-centered">No hay movimientos con ese filtro.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><tr><td><?= (int)$r['id_auditoria'] ?></td><td><?= fecha_fmt($r['fecha_hora'], true) ?></td><td><?= h($r['usuario']) ?></td>
      <td><span class="tag <?= $clase[$r['accion']] ?? 'is-neutro' ?>"><?= h($r['accion']) ?></span></td><td><?= h($r['tabla_afectada']) ?></td><td><?= (int)$r['id_registro_afectado'] ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?= html_paginacion('auditoria.php?accion=lista', $pagina, $paginas, $total, $offset, 15, 'movimientos', '#filtros') ?>
    <?php
    exit;
}

$usuarios = filas('SELECT id_usuario, ' . sql_nombre_usuario('u') . ' AS nombre FROM dbo.usuario u ORDER BY nombre');
$tablas = array_column(filas('SELECT DISTINCT tabla_afectada FROM dbo.auditoria ORDER BY tabla_afectada'), 'tabla_afectada');
$acciones = array_column(filas('SELECT DISTINCT accion FROM dbo.auditoria ORDER BY accion'), 'accion');
pagina_inicio('Auditoría', 'auditoria');
encabezado('Auditoría', 'Registro de las acciones realizadas en el sistema');
?>
<form id="filtros" class="filtros-lista columns is-multiline" hx-get="auditoria.php?accion=lista" hx-target="#lista-registros" hx-trigger="change" onsubmit="return false">
  <div class="column is-3"><label class="label">Usuario</label><div class="select is-fullwidth"><select name="usuario"><option value="">Todos</option><?php foreach ($usuarios as $u): ?><option value="<?= (int)$u['id_usuario'] ?>"><?= h($u['nombre']) ?></option><?php endforeach; ?></select></div></div>
  <div class="column is-3"><label class="label">Tabla</label><div class="select is-fullwidth"><select name="tabla"><option value="">Todas</option><?php foreach ($tablas as $t): ?><option><?= h($t) ?></option><?php endforeach; ?></select></div></div>
  <div class="column is-2"><label class="label">Acción</label><div class="select is-fullwidth"><select name="acc"><option value="">Todas</option><?php foreach ($acciones as $t): ?><option><?= h($t) ?></option><?php endforeach; ?></select></div></div>
  <div class="column is-2"><label class="label">Desde</label><input class="input" type="date" name="desde"></div>
  <div class="column is-2"><label class="label">Hasta</label><input class="input" type="date" name="hasta"></div>
</form>
<div id="lista-registros" hx-get="auditoria.php?accion=lista" hx-trigger="load" hx-include="#filtros, #pagina-lista" hx-swap="innerHTML"></div>
<?php pagina_fin();

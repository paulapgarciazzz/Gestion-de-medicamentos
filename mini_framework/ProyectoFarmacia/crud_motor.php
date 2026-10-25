<?php
/*
 * crud_motor.php - Motor genérico de mantenimiento (lista + búsqueda + paginación
 * + formulario modal + guardar + eliminar) con el mismo diseño HTMX/Bulma del
 * módulo de medicamentos. Cada tabla simple solo declara su configuración.
 */
require_once __DIR__ . '/lib.php';

function crud_ejecutar(array $c) {
    $c['archivo'] = basename($_SERVER['SCRIPT_NAME']);
    requerir_login($c['roles'] ?? ROLES);
    switch ($_GET['accion'] ?? 'pagina') {
        case 'lista':    crud_lista($c);    break;
        case 'form':     crud_form($c);     break;
        case 'guardar':  crud_guardar($c);  break;
        case 'eliminar': crud_eliminar($c); break;
        default:         crud_pagina($c);
    }
}

function crud_pagina(array $c) {
    $a = $c['archivo'];
    pagina_inicio($c['titulo'], $c['menu']);
    encabezado($c['titulo'], $c['subtitulo'] ?? '',
        ($c['botones'] ?? '') . '<button class="button is-primary" hx-get="' . $a . '?accion=form" hx-target="#modal-contenido" hx-swap="innerHTML">' . h($c['boton_nuevo']) . '</button>');
    ?>
    <div class="field"><div class="control"><input class="input" type="search" name="buscar" placeholder="<?= h($c['placeholder'] ?? 'Buscar…') ?>" hx-get="<?= $a ?>?accion=lista" hx-trigger="keyup changed delay:400ms, search" hx-target="#lista-registros" hx-include="this"></div></div>
    <div id="mensaje-lista" role="alert"></div>
    <div id="lista-registros" hx-get="<?= $a ?>?accion=lista" hx-trigger="load, crudGuardado from:body, crudEliminado from:body" hx-include="[name=buscar], #pagina-lista" hx-swap="innerHTML"></div>
    <?php
    pagina_fin();
}

function crud_lista(array $c) {
    $a = $c['archivo'];
    $pk = $c['pk'];
    $buscar = trim($_GET['buscar'] ?? '');
    $from = $c['from'] ?? 'FROM dbo.' . $c['tabla'] . ' t';
    $where = '';
    $params = [];
    if ($buscar !== '') {
        $partes = [];
        foreach ($c['buscar'] as $col) { $partes[] = "$col LIKE ?"; $params[] = '%' . $buscar . '%'; }
        $where = ' WHERE ' . implode(' OR ', $partes);
    }
    [$total, $paginas, $pagina, $offset] = paginar("SELECT COUNT(*) $from$where", $params, 10, $_GET['pagina'] ?? 1);
    $rows = filas_paginadas('SELECT ' . ($c['select'] ?? 't.*') . " $from$where ORDER BY " . $c['orden'], $params, $offset, 10);
    $puedeEliminar = !isset($c['roles_eliminar']) || tiene_rol($c['roles_eliminar']);
    ?>
    <div class="table-container"><table class="table is-fullwidth is-striped is-hoverable"><thead><tr><th>ID</th>
    <?php foreach ($c['lista'] as $col): ?><th><?= h($col[0]) ?></th><?php endforeach; ?><th>Acciones</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="<?= count($c['lista']) + 2 ?>" class="has-text-centered">No hay registros.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><tr><td><?= (int)$r[$pk] ?></td>
        <?php foreach ($c['lista'] as $col): ?><td><?= isset($col['f']) ? $col['f']($r) : h($r[$col[1]] ?? '') ?></td><?php endforeach; ?>
        <td><div class="buttons are-small">
            <button class="button is-info" hx-get="<?= $a ?>?accion=form&id=<?= (int)$r[$pk] ?>" hx-target="#modal-contenido">Editar</button>
            <?php if ($puedeEliminar): ?><button class="button is-danger" hx-delete="<?= $a ?>?accion=eliminar&id=<?= (int)$r[$pk] ?>" hx-target="#mensaje-lista" hx-confirm="¿Eliminar este registro? Esta acción no se puede deshacer.">Eliminar</button><?php endif; ?>
        </div></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?= html_paginacion($a . '?accion=lista', $pagina, $paginas, $total, $offset, 10, $c['etiqueta'] ?? 'registros') ?>
    <?php
}

function crud_form(array $c) {
    global $pdo;
    $a = $c['archivo'];
    $id = (int)($_GET['id'] ?? 0);
    $row = [];
    if ($id > 0) {
        $row = fila('SELECT * FROM dbo.' . $c['tabla'] . ' WHERE ' . $c['pk'] . '=?', [$id]);
        if (!$row) { http_response_code(404); exit('<div class="notification is-warning">El registro no existe.</div>'); }
    }
    modal_inicio(($id ? 'Editar ' : 'Nuevo/a ') . $c['singular'], $a . '?accion=guardar', $c['ancho'] ?? '850px');
    echo '<input type="hidden" name="id" value="' . $id . '"><div class="columns is-multiline">';
    foreach ($c['campos'] as $f) {
        $t = $f['t'] ?? 'text';
        $n = $f['n'];
        if ($id) { $v = $row[$n] ?? ''; }
        else { $v = $f['def'] ?? ''; if ($v === 'hoy') $v = date('Y-m-d'); }
        $req = !empty($f['req']) ? ' required' : '';
        $max = isset($f['max']) ? ' maxlength="' . (int)$f['max'] . '"' : '';
        echo '<div class="column is-' . ($f['col'] ?? 12) . '"><div class="field">';
        if ($t === 'checkbox') {
            echo '<label class="checkbox"><input type="checkbox" name="' . $n . '" value="1" ' . ($v ? 'checked' : '') . '> ' . h($f['l']) . '</label>';
        } else {
            echo '<label class="label">' . h($f['l']) . (!empty($f['req']) ? ' *' : '') . '</label>';
            if ($t === 'select') {
                $ops = isset($f['opciones']) ? $f['opciones'] : array_column(filas($f['sql']), 't', 'v');
                echo '<div class="select is-fullwidth"><select name="' . $n . '"' . $req . '><option value="">Seleccione…</option>';
                foreach ($ops as $val => $txt) echo '<option value="' . h($val) . '" ' . ((string)$v === (string)$val ? 'selected' : '') . '>' . h($txt) . '</option>';
                echo '</select></div>';
            } elseif ($t === 'textarea') {
                echo '<textarea class="textarea" rows="2" name="' . $n . '"' . $max . $req . '>' . h($v) . '</textarea>';
            } elseif ($t === 'password') {
                echo '<input class="input" type="password" name="' . $n . '" autocomplete="new-password"' . ($id ? '' : $req) . ' placeholder="' . ($id ? 'Dejar vacío para no cambiarla' : '') . '">';
            } else {
                $tipo = $t === 'number' ? 'number' : $t;
                $extra = $t === 'number' ? ' min="' . ($f['min'] ?? 0) . '" step="' . ($f['step'] ?? 1) . '"' : '';
                echo '<input class="input" type="' . $tipo . '" name="' . $n . '"' . $max . $extra . $req . ' value="' . h($v) . '" placeholder="' . h($f['ph'] ?? '') . '">';
            }
        }
        echo '</div></div>';
    }
    echo '</div>';
    modal_fin('Guardar ' . $c['singular']);
}

function crud_guardar(array $c) {
    global $pdo;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Método no permitido.'); }
    $id = (int)($_POST['id'] ?? 0);
    $vals = [];
    foreach ($c['campos'] as $f) {
        $n = $f['n']; $t = $f['t'] ?? 'text'; $l = $f['l'];
        if ($t === 'checkbox') { $vals[$n] = isset($_POST[$n]) ? 1 : 0; continue; }
        $raw = trim((string)($_POST[$n] ?? ''));
        if ($t === 'password') {
            if ($raw === '') { if ($id > 0) continue; fallo("$l es obligatorio."); }
            $vals[$n] = $raw;
            continue;
        }
        if ($raw === '') { if (!empty($f['req'])) fallo("$l es obligatorio."); $vals[$n] = null; continue; }
        if (isset($f['max']) && mb_strlen($raw) > $f['max']) fallo("$l no puede superar {$f['max']} caracteres.");
        switch ($t) {
            case 'email':
                if (!filter_var($raw, FILTER_VALIDATE_EMAIL)) fallo("$l no es un correo válido.");
                break;
            case 'number':
                if (!preg_match('/^\d{1,9}$/', $raw)) fallo("$l debe ser un número entero positivo.");
                $raw = (int)$raw;
                break;
            case 'date':
                if (!fecha_valida($raw)) fallo("$l no es una fecha válida.");
                break;
            case 'select':
                if (isset($f['opciones'])) { if (!array_key_exists($raw, $f['opciones'])) fallo("Seleccione un valor válido en $l."); }
                else { if (!ctype_digit($raw) || (int)$raw < 1) fallo("Seleccione $l."); $raw = (int)$raw; }
                break;
        }
        $vals[$n] = $raw;
    }
    foreach ($c['campos'] as $f) {
        if (!empty($f['unico']) && isset($vals[$f['n']]) && $vals[$f['n']] !== null) {
            $dup = (int)escalar('SELECT COUNT(*) FROM dbo.' . $c['tabla'] . ' WHERE ' . $f['n'] . '=? AND ' . $c['pk'] . '<>?', [$vals[$f['n']], $id]);
            if ($dup) fallo('Ya existe un registro con ese valor en «' . $f['l'] . '».', 409);
        }
    }
    if (!empty($c['validar']) && ($m = $c['validar']($vals, $id))) fallo($m);
    foreach ($c['campos'] as $f) {
        if (isset($f['transformar']) && array_key_exists($f['n'], $vals)) $vals[$f['n']] = $f['transformar']($vals[$f['n']]);
    }
    try {
        global $pdo;
        $cols = array_keys($vals);
        if ($id > 0) {
            $s = $pdo->prepare('UPDATE dbo.' . $c['tabla'] . ' SET ' . implode(',', array_map(fn($k) => "$k=?", $cols)) . ' WHERE ' . $c['pk'] . '=?');
            $s->execute(array_merge(array_values($vals), [$id]));
            if ($s->rowCount() === 0 && !fila('SELECT ' . $c['pk'] . ' FROM dbo.' . $c['tabla'] . ' WHERE ' . $c['pk'] . '=?', [$id])) fallo('El registro ya no existe.', 404);
            auditar($c['tabla'], $id, 'ACTUALIZAR');
        } else {
            $s = $pdo->prepare('INSERT INTO dbo.' . $c['tabla'] . ' (' . implode(',', $cols) . ') OUTPUT INSERTED.' . $c['pk'] . ' VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
            $s->execute(array_values($vals));
            auditar($c['tabla'], (int)$s->fetchColumn(), 'CREAR');
        }
        respuesta_ok(ucfirst($c['singular']) . ' guardado/a correctamente.');
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $codigo = (int)($e->errorInfo[1] ?? 0);
        if ($codigo === 2627 || $codigo === 2601) fallo('Ya existe un registro con esos datos.', 409);
        if ($codigo === 547) fallo('Alguno de los valores seleccionados no es válido.', 409);
        fallo('No se pudo guardar. Verifique los datos.', 500);
    }
}

function crud_eliminar(array $c) {
    global $pdo;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') { http_response_code(405); exit('Método no permitido.'); }
    if (isset($c['roles_eliminar'])) requerir_login($c['roles_eliminar']);
    $id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) fallo('Registro no válido.');
    if (!empty($c['puede_eliminar']) && ($m = $c['puede_eliminar']($id))) fallo($m, 409);
    try {
        $s = $pdo->prepare('DELETE FROM dbo.' . $c['tabla'] . ' WHERE ' . $c['pk'] . '=?');
        $s->execute([$id]);
        if (!$s->rowCount()) fallo('El registro no existe.', 404);
        auditar($c['tabla'], $id, 'ELIMINAR');
        hx_eventos(['crudEliminado' => 1, 'aviso' => ['texto' => ucfirst($c['singular']) . ' eliminado/a correctamente.']]);
    } catch (PDOException $e) {
        fallo($c['msg_fk'] ?? 'No se puede eliminar: el registro está siendo usado por otras tablas (ventas, compras, lotes, etc.).', 409);
    }
}

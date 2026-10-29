<?php
/*
 * Registro de compras a proveedores. Tablas: compra + detalle_compra, y crea/actualiza lote + detalle_lote.
 * Cada línea de compra registra el lote que llega (número, fabricación, vencimiento, cantidad) y esas
 * unidades pasan a estar disponibles para la venta. Si el mismo medicamento llega con un número de lote
 * que ya existe (y la misma fecha de vencimiento), se suman las unidades a ese lote.
 */
require_once __DIR__ . '/lib.php';
requerir_login(ROLES_GESTION);
switch ($_GET['accion'] ?? 'pagina') {
    case 'buscar':  compra_buscar();  break;
    case 'guardar': compra_guardar(); break;
    default:        compra_pagina();
}

function compra_buscar() {
    $q = trim($_GET['q'] ?? '');
    $like = '%' . $q . '%';
    $rows = filas('SELECT TOP 15 m.id_medicamento, m.nombre, m.concentracion, m.presentacion, m.precio_costo, m.iva_porcentaje, c.nombre AS categoria
        FROM dbo.medicamento m INNER JOIN dbo.categoria c ON c.id_categoria=m.id_categoria
        WHERE m.nombre LIKE ? OR m.codigo_barras LIKE ? OR m.concentracion LIKE ? OR c.nombre LIKE ? ORDER BY m.nombre', [$like, $like, $like, $like]);
    if (!$rows) { echo '<p class="has-text-grey">No se encontraron medicamentos. Regístrelos primero en <a href="index.php">Medicamentos</a>.</p>'; return; }
    echo '<div class="table-container"><table class="table is-fullwidth is-narrow is-hoverable"><thead><tr><th>Medicamento</th><th>Categoría</th><th class="col-num">Costo actual</th><th class="col-num">IVA</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td><strong>' . h($r['nombre']) . '</strong> <small>' . h(trim($r['concentracion'] . ' ' . $r['presentacion'])) . '</small></td><td>' . h($r['categoria']) . '</td>'
            . '<td class="col-num">' . dinero($r['precio_costo']) . '</td><td class="col-num">' . number_format((float)$r['iva_porcentaje'], 2) . '%</td>'
            . '<td><button type="button" class="button is-small is-info" onclick="CompraCar.agregar(this)" data-med="' . (int)$r['id_medicamento'] . '"'
            . ' data-nombre="' . h(trim($r['nombre'] . ' ' . $r['concentracion'])) . '" data-costo="' . h($r['precio_costo']) . '" data-iva="' . h($r['iva_porcentaje']) . '">Agregar</button></td></tr>';
    }
    echo '</tbody></table></div>';
}

function compra_pagina() {
    $_SESSION['compra_token'] = bin2hex(random_bytes(16));
    $proveedores = filas('SELECT id_proveedor, nombre, condiciones_pago FROM dbo.proveedor ORDER BY nombre');
    pagina_inicio('Nueva compra', 'compra_nueva');
    encabezado('Nueva compra', 'Registre la factura del proveedor y los lotes que ingresan al inventario', '<a class="button is-info" href="compras.php">Historial de compras</a>');
    if (!$proveedores) {
        echo '<div class="notification is-warning">Primero debe registrar al menos un proveedor en <a href="proveedores.php">Proveedores</a>.</div>';
        pagina_fin();
        return;
    }
    ?>
    <form id="form-compra" hx-post="compra_nueva.php?accion=guardar" hx-target="#mensaje-compra" hx-swap="innerHTML">
    <input type="hidden" name="token" value="<?= h($_SESSION['compra_token']) ?>">
    <div class="box"><div class="columns">
      <div class="column is-5"><label class="label">Proveedor *</label><div class="select is-fullwidth"><select name="id_proveedor" required>
        <option value="">Seleccione…</option><?php foreach ($proveedores as $p): ?><option value="<?= (int)$p['id_proveedor'] ?>"><?= h($p['nombre']) ?></option><?php endforeach; ?></select></div></div>
      <div class="column is-3"><label class="label">N.º de factura *</label><input class="input" type="number" min="1" name="numero_factura" required></div>
      <div class="column is-4"><label class="label">Fecha de la compra *</label><input class="input" type="date" name="fecha_compra" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
    </div></div>
    <div class="box">
      <label class="label">Buscar medicamento a comprar</label>
      <input class="input" type="search" name="q" placeholder="Nombre, código de barras, concentración o categoría…" autocomplete="off"
             onkeydown="if(event.key==='Enter')event.preventDefault()"
             hx-get="compra_nueva.php?accion=buscar" hx-trigger="load, keyup changed delay:300ms, search" hx-target="#resultados" hx-params="q">
      <div id="resultados" class="mt-3"></div>
    </div>
    <div class="box">
      <h2 class="title is-5">Líneas de la compra</h2>
      <div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Medicamento</th><th>N.º de lote *</th><th>Fabricación *</th><th>Vencimiento *</th><th style="width:90px">Cant. *</th><th style="width:120px">Costo unit. ₡ *</th><th class="col-num">Total + IVA</th><th></th></tr></thead>
        <tbody id="carrito"><tr><td colspan="8" class="has-text-centered has-text-grey">Aún no ha agregado medicamentos.</td></tr></tbody></table></div>
      <label class="checkbox"><input type="checkbox" name="actualizar_costo" value="1" checked> Actualizar el «precio de costo» de cada medicamento con el costo de esta compra</label>
      <table class="table is-fullwidth mt-3" style="max-width:380px;margin-left:auto"><tbody>
        <tr><td>Subtotal</td><td class="col-num" id="t-sub">₡0,00</td></tr><tr><td>IVA</td><td class="col-num" id="t-iva">₡0,00</td></tr>
        <tr><td><strong>TOTAL</strong></td><td class="col-num"><strong class="is-size-4" id="t-total">₡0,00</strong></td></tr></tbody></table>
      <div id="mensaje-compra" role="alert"></div>
      <button type="submit" id="btn-registrar" class="button is-primary is-medium" disabled>Registrar compra</button>
    </div>
    </form>
    <script>
    const CompraCar = (() => {
        let lineas = [];
        const $ = (id) => document.getElementById(id);
        const fmt = (n) => '₡' + n.toLocaleString('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
        const sub = (l) => l.cant * l.costo;
        function totales() {
            let s = 0, iva = 0;
            lineas.forEach((l, i) => { s += sub(l); iva += sub(l) * l.iva / 100; const c = $('lt-' + i); if (c) c.textContent = fmt(sub(l) * (1 + l.iva / 100)); });
            $('t-sub').textContent = fmt(s); $('t-iva').textContent = fmt(iva); $('t-total').textContent = fmt(s + iva);
            $('btn-registrar').disabled = lineas.length === 0;
        }
        function pintar() {
            const tb = $('carrito');
            if (!lineas.length) { tb.innerHTML = '<tr><td colspan="8" class="has-text-centered has-text-grey">Aún no ha agregado medicamentos.</td></tr>'; return totales(); }
            tb.innerHTML = lineas.map((l, i) => `<tr>
                <td><strong>${esc(l.nombre)}</strong><br><small>IVA ${l.iva}%</small><input type="hidden" name="lineas[${i}][med]" value="${l.med}"></td>
                <td><input class="input is-small" maxlength="50" required name="lineas[${i}][lote]" value="${esc(l.lote)}" oninput="CompraCar.set(${i},'lote',this.value)"></td>
                <td><input class="input is-small" type="date" required name="lineas[${i}][fab]" value="${l.fab}" oninput="CompraCar.set(${i},'fab',this.value)"></td>
                <td><input class="input is-small" type="date" required name="lineas[${i}][venc]" value="${l.venc}" oninput="CompraCar.set(${i},'venc',this.value)"></td>
                <td><input class="input is-small" type="number" min="1" required name="lineas[${i}][cant]" value="${l.cant}" oninput="CompraCar.set(${i},'cant',this.value)"></td>
                <td><input class="input is-small" type="number" min="0" step="0.01" required name="lineas[${i}][costo]" value="${l.costo}" oninput="CompraCar.set(${i},'costo',this.value)"></td>
                <td class="col-num" id="lt-${i}"></td>
                <td><button type="button" class="button is-small is-danger" onclick="CompraCar.quitar(${i})">×</button></td></tr>`).join('');
            totales();
        }
        return {
            agregar(btn) {
                const d = btn.dataset;
                lineas.push({med: +d.med, nombre: d.nombre, iva: +d.iva, costo: +d.costo, lote: '', fab: '', venc: '', cant: 1});
                pintar();
            },
            set(i, campo, v) { lineas[i][campo] = (campo === 'cant' || campo === 'costo') ? Math.max(0, parseFloat(v) || 0) : v; totales(); },
            quitar(i) { lineas.splice(i, 1); pintar(); },
            refrescar: totales,
        };
    })();
    const formCompra = document.getElementById('form-compra');
    formCompra.addEventListener('htmx:beforeRequest', (e) => { if (e.detail.elt === formCompra) document.getElementById('btn-registrar').disabled = true; });
    formCompra.addEventListener('htmx:afterRequest', (e) => { if (e.detail.elt === formCompra) CompraCar.refrescar(); });
    </script>
    <?php
    pagina_fin();
}

function compra_guardar() {
    global $pdo;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Método no permitido.'); }
    $token = (string)($_POST['token'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['compra_token'] ?? ''), $token)) fallo('Esta compra ya fue registrada o la página expiró. Recargue la página.', 409);
    $idProv = filter_var($_POST['id_proveedor'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$idProv) fallo('Seleccione un proveedor.');
    $factura = filter_var($_POST['numero_factura'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$factura) fallo('Ingrese un número de factura válido (solo números).');
    $fecha = $_POST['fecha_compra'] ?? '';
    $hoy = date('Y-m-d');
    if (!fecha_valida($fecha) || $fecha > $hoy) fallo('La fecha de la compra no es válida.');
    $entrada = $_POST['lineas'] ?? [];
    if (!is_array($entrada) || !$entrada) fallo('Agregue al menos un medicamento a la compra.');
    if (count($entrada) > 60) fallo('Demasiadas líneas en una sola compra.');
    $actualizarCosto = isset($_POST['actualizar_costo']);
    try {
        $pdo->beginTransaction();
        if ((int)escalar('SELECT COUNT(*) FROM dbo.compra WHERE id_proveedor=? AND numero_factura=?', [$idProv, $factura])) {
            throw new ErrorNegocio('Ya existe una compra registrada con esa factura para este proveedor.');
        }
        $lineas = [];
        $sub = $imp = 0.0;
        foreach ($entrada as $n => $l) {
            $pos = $n + 1;
            $med = fila('SELECT id_medicamento, nombre, iva_porcentaje FROM dbo.medicamento WHERE id_medicamento=?', [(int)($l['med'] ?? 0)]);
            if (!$med) throw new ErrorNegocio("Línea $pos: el medicamento no existe.");
            $lote = trim((string)($l['lote'] ?? ''));
            $fab = (string)($l['fab'] ?? '');
            $venc = (string)($l['venc'] ?? '');
            $cant = (int)($l['cant'] ?? 0);
            $costo = round((float)($l['costo'] ?? 0), 2);
            if ($lote === '' || mb_strlen($lote) > 50) throw new ErrorNegocio("Línea $pos ({$med['nombre']}): indique el número de lote (máx. 50 caracteres).");
            if (!fecha_valida($fab) || !fecha_valida($venc)) throw new ErrorNegocio("Línea $pos ({$med['nombre']}): fechas de fabricación y vencimiento no válidas.");
            if ($fab > $hoy) throw new ErrorNegocio("Línea $pos ({$med['nombre']}): la fecha de fabricación no puede ser futura.");
            if ($venc <= $hoy) throw new ErrorNegocio("Línea $pos ({$med['nombre']}): el lote ya está vencido; no se puede ingresar al inventario.");
            if ($venc <= $fab) throw new ErrorNegocio("Línea $pos ({$med['nombre']}): el vencimiento debe ser posterior a la fabricación.");
            if ($cant < 1) throw new ErrorNegocio("Línea $pos ({$med['nombre']}): la cantidad debe ser al menos 1.");
            if ($costo < 0) throw new ErrorNegocio("Línea $pos ({$med['nombre']}): costo no válido.");
            $bruto = round($cant * $costo, 2);
            $iva = round($bruto * (float)$med['iva_porcentaje'] / 100, 2);
            $sub += $bruto; $imp += $iva;
            $lineas[] = [(int)$med['id_medicamento'], $lote, $fab, $venc, $cant, $costo, $bruto, round($bruto + $iva, 2)];
        }
        $sub = round($sub, 2); $imp = round($imp, 2);
        $s = $pdo->prepare('INSERT INTO dbo.compra (id_proveedor,id_usuario,fecha_compra,numero_factura,total,impuesto) OUTPUT INSERTED.id_compra VALUES (?,?,CONVERT(DATETIME,?,120),?,?,?)');
        $s->execute([$idProv, usuario_actual()['id'], $fecha . ' ' . date('H:i:s'), $factura, round($sub + $imp, 2), $imp]);
        $idCompra = (int)$s->fetchColumn();
        foreach ($lineas as [$idMed, $lote, $fab, $venc, $cant, $costo, $bruto, $totalLinea]) {
            $ex = fila('SELECT id_detalle_lote, id_lote, fecha_fabricacion, fecha_vencimiento FROM dbo.detalle_lote WITH (UPDLOCK) WHERE id_medicamento=? AND numero_lote=?', [$idMed, $lote]);
            if ($ex) {
                if (substr((string)$ex['fecha_fabricacion'], 0, 10) !== $fab || substr((string)$ex['fecha_vencimiento'], 0, 10) !== $venc) {
                    throw new ErrorNegocio("El lote «$lote» ya existe para ese medicamento con fechas de fabricación o vencimiento diferentes. Verifique las fechas antes de registrar la compra.");
                }
                $pdo->prepare('UPDATE dbo.detalle_lote SET cantidad_inicial=cantidad_inicial+? WHERE id_detalle_lote=?')->execute([$cant, $ex['id_detalle_lote']]);
                $pdo->prepare('UPDATE dbo.lote SET cantidad_inicial=cantidad_inicial+? WHERE id_lote=?')->execute([$cant, $ex['id_lote']]);
                $idDL = (int)$ex['id_detalle_lote'];
            } else {
                $s = $pdo->prepare("INSERT INTO dbo.lote (numero_lote,fecha_fabricacion,fecha_vencimiento,cantidad_inicial,estado) OUTPUT INSERTED.id_lote VALUES (?,?,?,?,'Activo')");
                $s->execute([$lote, $fab, $venc, $cant]);
                $idLote = (int)$s->fetchColumn();
                $s = $pdo->prepare("INSERT INTO dbo.detalle_lote (id_medicamento,id_lote,numero_lote,fecha_fabricacion,fecha_vencimiento,cantidad_inicial,estado) OUTPUT INSERTED.id_detalle_lote VALUES (?,?,?,?,?,?,'Activo')");
                $s->execute([$idMed, $idLote, $lote, $fab, $venc, $cant]);
                $idDL = (int)$s->fetchColumn();
            }
            $pdo->prepare('INSERT INTO dbo.detalle_compra (id_compra,id_medicamento,id_detalle_lote,cantidad,costo_unidad,subtotal,total) VALUES (?,?,?,?,?,?,?)')
                ->execute([$idCompra, $idMed, $idDL, $cant, $costo, $bruto, $totalLinea]);
            if ($actualizarCosto) $pdo->prepare('UPDATE dbo.medicamento SET precio_costo=? WHERE id_medicamento=?')->execute([$costo, $idMed]);
        }
        $pdo->commit();
        auditar('compra', $idCompra, 'CREAR');
        unset($_SESSION['compra_token']);
        header('HX-Redirect: compra_ver.php?id=' . $idCompra);
        echo '<div class="notification is-success">Compra registrada. Abriendo el detalle…</div>';
    } catch (ErrorNegocio $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fallo($e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        fallo('No se pudo registrar la compra. Verifique los datos e intente de nuevo.', 500);
    }
}

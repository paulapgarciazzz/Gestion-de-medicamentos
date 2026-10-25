<?php
/*
 * Punto de venta. Tablas: venta + detalle_venta (usa receta, cliente y detalle_lote).
 * Reglas: solo se venden lotes ACTIVOS y NO vencidos, con existencias; los medicamentos que
 * requieren receta exigen una receta vigente del mismo cliente; todo se recalcula en el servidor.
 * Totales: detalle.subtotal = cant × precio; detalle.total = (subtotal − descuento) + IVA.
 */
require_once __DIR__ . '/lib.php';
requerir_login();
switch ($_GET['accion'] ?? 'pagina') {
    case 'buscar':  venta_buscar();  break;
    case 'recetas': venta_recetas(); break;
    case 'guardar': venta_guardar(); break;
    default:        venta_pagina();
}

function venta_buscar() {
    $q = trim($_GET['q'] ?? '');
    $like = '%' . $q . '%';
    $rows = filas("SELECT TOP 15 dl.id_detalle_lote, dl.numero_lote, dl.fecha_vencimiento, m.id_medicamento, m.nombre, m.concentracion,
            m.precio_venta, m.iva_porcentaje, m.requiere_receta, st.disponible
        FROM dbo.detalle_lote dl INNER JOIN dbo.medicamento m ON m.id_medicamento=dl.id_medicamento " . sql_stock_apply('dl') . "
        WHERE dl.estado='Activo' AND dl.fecha_vencimiento>=CAST(GETDATE() AS DATE) AND st.disponible>0
          AND (m.nombre LIKE ? OR m.codigo_barras LIKE ? OR m.concentracion LIKE ?)
        ORDER BY m.nombre, dl.fecha_vencimiento", [$like, $like, $like]);
    if (!$rows) { echo '<p class="has-text-grey">No hay medicamentos disponibles con esa búsqueda (solo se muestran lotes activos, vigentes y con existencias).</p>'; return; }
    echo '<div class="table-container"><table class="table is-fullwidth is-narrow is-hoverable"><thead><tr><th>Medicamento</th><th>Lote</th><th>Vence</th><th class="col-num">Stock</th><th class="col-num">Precio</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td><strong>' . h($r['nombre']) . '</strong> <small>' . h($r['concentracion']) . '</small>' . ($r['requiere_receta'] ? ' <span class="tag is-porvencer">Receta</span>' : '') . '</td>'
            . '<td>' . h($r['numero_lote']) . '</td><td>' . fecha_fmt($r['fecha_vencimiento']) . '</td><td class="col-num">' . (int)$r['disponible'] . '</td>'
            . '<td class="col-num">' . dinero($r['precio_venta']) . '<br><small>IVA ' . number_format((float)$r['iva_porcentaje'], 2) . '%</small></td>'
            . '<td><button type="button" class="button is-small is-info" onclick="Carrito.agregar(this)"'
            . ' data-lote="' . (int)$r['id_detalle_lote'] . '" data-nombre="' . h(trim($r['nombre'] . ' ' . $r['concentracion'])) . '" data-lotenum="' . h($r['numero_lote']) . '"'
            . ' data-precio="' . h($r['precio_venta']) . '" data-iva="' . h($r['iva_porcentaje']) . '" data-stock="' . (int)$r['disponible'] . '" data-receta="' . (int)$r['requiere_receta'] . '">Agregar</button></td></tr>';
    }
    echo '</tbody></table></div>';
}

function venta_recetas() {
    $idc = (int)($_GET['id_cliente'] ?? 0);
    $rs = $idc ? filas("SELECT r.id_receta, r.numero_receta, r.fecha_vencimiento, m.nombre FROM dbo.receta r INNER JOIN dbo.medicamento m ON m.id_medicamento=r.id_medicamento
        WHERE r.id_cliente=? AND r.fecha_vencimiento>=CAST(GETDATE() AS DATE) AND r.fecha_emision<=CAST(GETDATE() AS DATE) ORDER BY r.fecha_emision DESC", [$idc]) : [];
    echo '<div class="field"><label class="label">Receta médica <small class="has-text-grey">(obligatoria si hay medicamentos con receta)</small></label><div class="select is-fullwidth"><select name="id_receta"><option value="">Sin receta</option>';
    foreach ($rs as $r) echo '<option value="' . (int)$r['id_receta'] . '">N.º ' . (int)$r['numero_receta'] . ' · ' . h($r['nombre']) . ' · vence ' . fecha_fmt($r['fecha_vencimiento']) . '</option>';
    echo '</select></div>' . ($idc && !$rs ? '<p class="help">Este cliente no tiene recetas vigentes. <a href="recetas.php" target="_blank">Registrar receta</a></p>' : '') . '</div>';
}

function venta_pagina() {
    $_SESSION['venta_token'] = bin2hex(random_bytes(16));
    $clientes = filas('SELECT id_cliente, identificacion, ' . sql_nombre_cliente('c') . ' AS nombre FROM dbo.cliente c ORDER BY primer_apellido, primer_nombre');
    pagina_inicio('Nueva venta', 'venta_nueva');
    encabezado('Nueva venta', 'Busque el medicamento y agréguelo. Se muestran los lotes que vencen primero.', '<a class="button is-info" href="ventas.php">Historial de ventas</a>');
    ?>
    <form id="form-venta" hx-post="venta_nueva.php?accion=guardar" hx-target="#mensaje-venta" hx-swap="innerHTML">
    <input type="hidden" name="token" value="<?= h($_SESSION['venta_token']) ?>">
    <div class="columns">
      <div class="column is-8">
        <div class="box">
          <label class="label">Buscar medicamento</label>
          <input class="input" type="search" name="q" placeholder="Nombre, código de barras o concentración…" autocomplete="off" autofocus
                 onkeydown="if(event.key==='Enter')event.preventDefault()"
                 hx-get="venta_nueva.php?accion=buscar" hx-trigger="load, keyup changed delay:300ms, search" hx-target="#resultados" hx-params="q">
          <div id="resultados" class="mt-3"></div>
        </div>
        <div class="box">
          <h2 class="title is-5">Detalle de la venta</h2>
          <div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Medicamento</th><th class="col-num">Precio</th><th style="width:90px">Cant.</th><th style="width:120px">Descuento ₡</th><th class="col-num">Total + IVA</th><th></th></tr></thead>
            <tbody id="carrito"><tr><td colspan="6" class="has-text-centered has-text-grey">Aún no ha agregado medicamentos.</td></tr></tbody></table></div>
        </div>
      </div>
      <div class="column is-4">
        <div class="box">
          <h2 class="title is-5">Cliente y pago</h2>
          <div class="field"><label class="label">Cliente *</label>
            <input class="input mb-2" type="search" id="filtro-cliente" placeholder="Filtrar clientes…" autocomplete="off" onkeydown="if(event.key==='Enter')event.preventDefault()">
            <div class="select is-fullwidth"><select name="id_cliente" id="sel-cliente" required hx-get="venta_nueva.php?accion=recetas" hx-trigger="change" hx-target="#zona-receta">
              <option value="">Seleccione…</option>
              <?php foreach ($clientes as $c): ?><option value="<?= (int)$c['id_cliente'] ?>"><?= h($c['nombre']) ?> (<?= h($c['identificacion']) ?>)</option><?php endforeach; ?>
            </select></div>
            <p class="help"><a href="clientes.php" target="_blank">Registrar un cliente nuevo</a></p></div>
          <div id="zona-receta"><div class="field"><label class="label">Receta médica</label><div class="select is-fullwidth"><select name="id_receta"><option value="">Sin receta</option></select></div></div></div>
          <div class="field"><label class="label">Método de pago *</label><div class="select is-fullwidth"><select name="metodo_pago" required>
            <?php foreach (METODOS_PAGO as $m): ?><option><?= h($m) ?></option><?php endforeach; ?></select></div></div>
        </div>
        <div class="box">
          <table class="table is-fullwidth"><tbody>
            <tr><td>Subtotal</td><td class="col-num" id="t-sub">₡0,00</td></tr>
            <tr><td>Descuento</td><td class="col-num" id="t-desc">₡0,00</td></tr>
            <tr><td>IVA</td><td class="col-num" id="t-iva">₡0,00</td></tr>
            <tr><td><strong>TOTAL</strong></td><td class="col-num"><strong class="is-size-4" id="t-total">₡0,00</strong></td></tr>
          </tbody></table>
          <div id="aviso-receta" class="notification is-warning" hidden>Hay medicamentos que requieren receta: seleccione la receta del cliente.</div>
          <div id="mensaje-venta" role="alert"></div>
          <button type="submit" id="btn-registrar" class="button is-primary is-fullwidth is-medium" disabled>Registrar venta</button>
        </div>
      </div>
    </div>
    </form>
    <script>
    const Carrito = (() => {
        let lineas = [];
        const $ = (id) => document.getElementById(id);
        const fmt = (n) => '₡' + n.toLocaleString('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
        const bruto = (l) => l.cant * l.precio;
        const total = (l) => (bruto(l) - l.desc) * (1 + l.iva / 100);

        function totales() {
            let sub = 0, desc = 0, iva = 0, tot = 0;
            lineas.forEach((l, i) => {
                sub += bruto(l); desc += l.desc; iva += (bruto(l) - l.desc) * l.iva / 100; tot += total(l);
                const c = $('lt-' + i); if (c) c.textContent = fmt(total(l));
            });
            $('t-sub').textContent = fmt(sub); $('t-desc').textContent = fmt(desc); $('t-iva').textContent = fmt(iva); $('t-total').textContent = fmt(tot);
            $('btn-registrar').disabled = lineas.length === 0;
            const sel = document.querySelector('[name=id_receta]');
            $('aviso-receta').hidden = !(lineas.some((l) => l.receta) && sel && sel.value === '');
        }
        function pintar() {
            const tb = $('carrito');
            if (!lineas.length) { tb.innerHTML = '<tr><td colspan="6" class="has-text-centered has-text-grey">Aún no ha agregado medicamentos.</td></tr>'; return totales(); }
            tb.innerHTML = lineas.map((l, i) => `<tr>
                <td><strong>${esc(l.nombre)}</strong>${l.receta ? ' <span class="tag is-porvencer">Receta</span>' : ''}<br><small>Lote ${esc(l.lotenum)} · IVA ${l.iva}%</small>
                    <input type="hidden" name="lineas[${i}][lote]" value="${l.lote}"></td>
                <td class="col-num">${fmt(l.precio)}</td>
                <td><input class="input is-small" type="number" min="1" max="${l.stock}" name="lineas[${i}][cant]" value="${l.cant}" oninput="Carrito.set(${i},'cant',this.value)" onchange="Carrito.ajustar(${i})"></td>
                <td><input class="input is-small" type="number" min="0" step="0.01" name="lineas[${i}][desc]" value="${l.desc}" oninput="Carrito.set(${i},'desc',this.value)" onchange="Carrito.ajustar(${i})"></td>
                <td class="col-num" id="lt-${i}"></td>
                <td><button type="button" class="button is-small is-danger" onclick="Carrito.quitar(${i})">×</button></td></tr>`).join('');
            totales();
        }
        return {
            agregar(btn) {
                const d = btn.dataset, lote = +d.lote;
                const ex = lineas.find((x) => x.lote === lote);
                if (ex) { if (ex.cant < ex.stock) ex.cant++; }
                else lineas.push({lote, nombre: d.nombre, lotenum: d.lotenum, precio: +d.precio, iva: +d.iva, stock: +d.stock, receta: d.receta === '1', cant: 1, desc: 0});
                pintar();
            },
            set(i, campo, v) { lineas[i][campo] = Math.max(0, parseFloat(v) || 0); totales(); },
            ajustar(i) {
                const l = lineas[i];
                l.cant = Math.min(l.stock, Math.max(1, Math.floor(l.cant)));
                l.desc = Math.min(bruto(l), l.desc);
                pintar();
            },
            quitar(i) { lineas.splice(i, 1); pintar(); },
            revisarReceta: totales,
        };
    })();
    const formVenta = document.getElementById('form-venta');
    formVenta.addEventListener('htmx:beforeRequest', (e) => { if (e.detail.elt === formVenta) document.getElementById('btn-registrar').disabled = true; });
    formVenta.addEventListener('htmx:afterRequest', (e) => { if (e.detail.elt === formVenta) Carrito.revisarReceta(); });
    document.getElementById('zona-receta').addEventListener('change', () => Carrito.revisarReceta());
    document.body.addEventListener('htmx:afterSwap', (e) => { if (e.target.id === 'zona-receta') Carrito.revisarReceta(); });
    document.getElementById('filtro-cliente').addEventListener('input', (e) => {
        const t = e.target.value.toLowerCase();
        document.querySelectorAll('#sel-cliente option').forEach((o) => { if (o.value) o.hidden = !o.textContent.toLowerCase().includes(t); });
    });
    </script>
    <?php
    pagina_fin();
}

function venta_guardar() {
    global $pdo;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('Método no permitido.'); }
    $token = (string)($_POST['token'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['venta_token'] ?? ''), $token)) fallo('Esta venta ya fue registrada o la página expiró. Recargue la página para iniciar una nueva venta.', 409);
    $idCliente = filter_var($_POST['id_cliente'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$idCliente) fallo('Seleccione un cliente.');
    $idReceta = null;
    if (($_POST['id_receta'] ?? '') !== '') {
        $idReceta = filter_var($_POST['id_receta'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$idReceta) fallo('Receta no válida.');
    }
    $metodo = $_POST['metodo_pago'] ?? '';
    if (!in_array($metodo, METODOS_PAGO, true)) fallo('Seleccione el método de pago.');
    $entrada = $_POST['lineas'] ?? [];
    if (!is_array($entrada) || !$entrada) fallo('Agregue al menos un medicamento a la venta.');
    if (count($entrada) > 60) fallo('Demasiadas líneas en una sola venta.');
    $lineas = [];
    foreach ($entrada as $l) {
        $lote = (int)($l['lote'] ?? 0);
        $cant = (int)($l['cant'] ?? 0);
        if ($lote < 1 || $cant < 1) fallo('Hay una línea con datos no válidos.');
        $lineas[$lote]['cant'] = ($lineas[$lote]['cant'] ?? 0) + $cant;
        $lineas[$lote]['desc'] = ($lineas[$lote]['desc'] ?? 0) + round(max(0, (float)($l['desc'] ?? 0)), 2);
    }
    try {
        $pdo->beginTransaction();
        $hoy = date('Y-m-d');
        $sub = $dsc = $imp = 0.0;
        $detalles = [];
        $medsRx = [];
        foreach ($lineas as $lote => $l) {
            // UPDLOCK: bloquea el lote para que dos ventas simultáneas no vendan las mismas unidades.
            $d = fila('SELECT dl.estado, dl.fecha_vencimiento, m.id_medicamento, m.nombre, m.precio_venta, m.iva_porcentaje, m.requiere_receta
                FROM dbo.detalle_lote dl WITH (UPDLOCK, ROWLOCK) INNER JOIN dbo.medicamento m ON m.id_medicamento=dl.id_medicamento WHERE dl.id_detalle_lote=?', [$lote]);
            if (!$d) throw new ErrorNegocio('Uno de los lotes de la venta ya no existe.');
            if ($d['estado'] !== 'Activo') throw new ErrorNegocio("El lote de «{$d['nombre']}» no está activo.");
            if (substr((string)$d['fecha_vencimiento'], 0, 10) < $hoy) throw new ErrorNegocio("El lote de «{$d['nombre']}» está vencido.");
            $stock = stock_lote($lote);
            if ($l['cant'] > $stock) throw new ErrorNegocio("Stock insuficiente de «{$d['nombre']}» (disponible: $stock).");
            $precio = (float)$d['precio_venta'];
            $bruto = round($l['cant'] * $precio, 2);
            if ($l['desc'] > $bruto) throw new ErrorNegocio("El descuento de «{$d['nombre']}» supera el valor de la línea.");
            $neto = $bruto - $l['desc'];
            $iva = round($neto * (float)$d['iva_porcentaje'] / 100, 2);
            $sub += $bruto; $dsc += $l['desc']; $imp += $iva;
            if ($d['requiere_receta']) $medsRx[] = (int)$d['id_medicamento'];
            $detalles[] = [$lote, $l['cant'], $precio, $l['desc'], $bruto, round($neto + $iva, 2)];
        }
        if ($medsRx || $idReceta) {
            if (!$idReceta) throw new ErrorNegocio('La venta incluye medicamentos que requieren receta: seleccione la receta del cliente.');
            $r = fila('SELECT id_medicamento FROM dbo.receta WHERE id_receta=? AND id_cliente=? AND fecha_vencimiento>=CAST(? AS DATE) AND fecha_emision<=CAST(? AS DATE)', [$idReceta, $idCliente, $hoy, $hoy]);
            if (!$r) throw new ErrorNegocio('La receta no pertenece a este cliente o no está vigente.');
            foreach ($medsRx as $m) {
                if ($m !== (int)$r['id_medicamento']) throw new ErrorNegocio('La receta seleccionada solo cubre el medicamento indicado en ella. Registre los demás medicamentos con receta en otra venta.');
            }
        }
        $sub = round($sub, 2); $dsc = round($dsc, 2); $imp = round($imp, 2);
        $total = round($sub - $dsc + $imp, 2);
        $s = $pdo->prepare("INSERT INTO dbo.venta (id_cliente,id_receta,subtotal,impuesto,descuento,total,metodo_pago,estado) OUTPUT INSERTED.id_venta VALUES (?,?,?,?,?,?,?,'Completada')");
        $s->execute([$idCliente, $idReceta, $sub, $imp, $dsc, $total, $metodo]);
        $idVenta = (int)$s->fetchColumn();
        $ins = $pdo->prepare('INSERT INTO dbo.detalle_venta (id_venta,id_detalle_lote,cantidad,precio_unidad,descuento,subtotal,total) VALUES (?,?,?,?,?,?,?)');
        foreach ($detalles as $d) $ins->execute(array_merge([$idVenta], $d));
        $pdo->commit();
        auditar('venta', $idVenta, 'CREAR');
        unset($_SESSION['venta_token']);
        header('HX-Redirect: venta_ver.php?id=' . $idVenta);
        echo '<div class="notification is-success">Venta registrada. Abriendo la factura…</div>';
    } catch (ErrorNegocio $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fallo($e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        fallo('No se pudo registrar la venta. Intente de nuevo.', 500);
    }
}

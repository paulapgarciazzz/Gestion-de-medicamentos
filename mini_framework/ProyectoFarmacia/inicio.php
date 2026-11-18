<?php
/* Panel principal: indicadores, alertas y accesos a todos los módulos. */
require_once __DIR__ . '/lib.php';
requerir_login();
$hoy = 'CAST(GETDATE() AS DATE)';
$ventasHoy = fila("SELECT COUNT(*) AS n, ISNULL(SUM(total),0) AS t FROM dbo.venta WHERE estado<>'Anulada' AND CAST(fecha_venta AS DATE)=$hoy");
$ventasMes = fila("SELECT COUNT(*) AS n, ISNULL(SUM(total),0) AS t FROM dbo.venta WHERE estado<>'Anulada' AND fecha_venta>=DATEFROMPARTS(YEAR(GETDATE()),MONTH(GETDATE()),1)");
$nMed = (int)escalar('SELECT COUNT(*) FROM dbo.medicamento');
$nCli = (int)escalar('SELECT COUNT(*) FROM dbo.cliente');
$gestion = tiene_rol(ROLES_GESTION);
if ($gestion) {
    $porVencer = (int)escalar("SELECT COUNT(*) FROM dbo.detalle_lote dl " . sql_stock_apply('dl') . " WHERE " . sql_lote_estado_vendible('dl') . " AND dl.fecha_vencimiento>=$hoy AND dl.fecha_vencimiento<=DATEADD(DAY,90,$hoy) AND st.disponible>0");
    $vencidos = (int)escalar("SELECT COUNT(*) FROM dbo.detalle_lote dl " . sql_stock_apply('dl') . " WHERE dl.estado='Activo' AND dl.fecha_vencimiento<$hoy AND st.disponible>0");
    $bajo = (int)escalar('SELECT COUNT(*) FROM (' . sql_inventario() . ') x WHERE x.stock<=10');
    $proximos = filas("SELECT TOP 6 m.nombre, m.concentracion, " . sql_presentacion('m') . " AS presentacion, dl.numero_lote, dl.fecha_vencimiento, st.disponible FROM dbo.detalle_lote dl INNER JOIN dbo.medicamento m ON m.id_medicamento=dl.id_medicamento " . sql_stock_apply('dl')
        . " WHERE " . sql_lote_estado_vendible('dl') . " AND dl.fecha_vencimiento>=$hoy AND dl.fecha_vencimiento<=DATEADD(DAY,90,$hoy) AND st.disponible>0 ORDER BY dl.fecha_vencimiento");
}
$ultimas = filas('SELECT TOP 6 v.id_venta, v.fecha_venta, v.total, v.estado, ' . sql_nombre_cliente('c') . ' AS cliente FROM dbo.venta v INNER JOIN dbo.cliente c ON c.id_cliente=v.id_cliente ORDER BY v.fecha_venta DESC, v.id_venta DESC');

/* ---------- Datos de los gráficos de las tarjetas (solo lectura) ---------- */
// Total vendido por día, desde $desde (Y-m-d) hasta hoy; los días sin ventas quedan en 0.
function ventas_por_dia($desde) {
    $por = array_column(filas("SELECT CONVERT(CHAR(10), CAST(fecha_venta AS DATE), 23) AS dia, SUM(total) AS t FROM dbo.venta
        WHERE estado<>'Anulada' AND fecha_venta>=CAST(? AS DATE) GROUP BY CAST(fecha_venta AS DATE)", [$desde]), 't', 'dia');
    $dias = [];
    for ($d = new DateTime($desde), $fin = new DateTime('today'); $d <= $fin; $d->modify('+1 day')) {
        $dias[$d->format('Y-m-d')] = (float)($por[$d->format('Y-m-d')] ?? 0);
    }
    return $dias;
}
$semana = ventas_por_dia(date('Y-m-d', strtotime('-6 days')));
$mes = ventas_por_dia(date('Y-m-01'));
$estadoStock = fila('SELECT SUM(CASE WHEN x.stock<=0 THEN 1 ELSE 0 END) AS sin, SUM(CASE WHEN x.stock BETWEEN 1 AND 10 THEN 1 ELSE 0 END) AS bajo,
    SUM(CASE WHEN x.stock>10 THEN 1 ELSE 0 END) AS bien FROM (' . sql_inventario() . ') x');
$cliCompraron = (int)escalar("SELECT COUNT(DISTINCT id_cliente) FROM dbo.venta WHERE estado<>'Anulada'");
if ($gestion) {
    $plazos = fila("SELECT SUM(CASE WHEN d.dias<=30 THEN 1 ELSE 0 END) AS a30, SUM(CASE WHEN d.dias BETWEEN 31 AND 60 THEN 1 ELSE 0 END) AS a60,
        SUM(CASE WHEN d.dias>60 THEN 1 ELSE 0 END) AS a90
        FROM (SELECT DATEDIFF(DAY,$hoy,dl.fecha_vencimiento) AS dias FROM dbo.detalle_lote dl " . sql_stock_apply('dl') . "
              WHERE " . sql_lote_estado_vendible('dl') . " AND dl.fecha_vencimiento>=$hoy AND dl.fecha_vencimiento<=DATEADD(DAY,90,$hoy) AND st.disponible>0) d");
    $lotesConStock = (int)escalar("SELECT COUNT(*) FROM dbo.detalle_lote dl " . sql_stock_apply('dl') . " WHERE dl.estado='Activo' AND st.disponible>0");
}

/* ---------- Gráficos pequeños (SVG y la barra de progreso de Bulma) ---------- */
// Columnas: [['valor' => n, 'titulo' => tooltip, 'resaltar' => bool, 'etiqueta' => texto bajo la columna (opcional)]]
function grafico_columnas(array $cols, $descripcion, $color = '#0f766e', $conEtiquetas = false) {
    $n = count($cols);
    $ancho = 240; $sep = 2;
    $arriba = $conEtiquetas ? 15 : 2; $alto = $conEtiquetas ? 34 : 38; $abajo = $conEtiquetas ? 16 : 0;
    $max = max(1, ...array_map(fn($c) => (float)$c['valor'], $cols));
    $w = ($ancho - $sep * ($n - 1)) / $n;
    $base = $arriba + $alto;
    // Sin etiquetas (minigráfico): alto fijo y se estira a lo ancho; con etiquetas: mantiene sus proporciones (texto legible)
    $svg = '<svg class="grafico-columnas' . ($conEtiquetas ? '' : ' es-mini') . '" viewBox="0 0 ' . $ancho . ' ' . ($base + $abajo) . '"'
         . ($conEtiquetas ? '' : ' preserveAspectRatio="none"') . ' role="img" aria-label="' . h($descripcion) . '">'
         . '<line x1="0" y1="' . $base . '" x2="' . $ancho . '" y2="' . $base . '" stroke="#e2e8f0" stroke-width="1" vector-effect="non-scaling-stroke"/>';
    foreach ($cols as $i => $c) {
        $espacio = $i * ($w + $sep);              // franja de la columna (zona para el mouse)
        $bw = $conEtiquetas ? min($w, 30) : $w;   // pocas columnas: delgadas y centradas en su franja
        $x = $espacio + ($w - $bw) / 2;
        $h = $c['valor'] > 0 ? max(2, $c['valor'] / $max * $alto) : 0;
        $y = $base - $h;
        $r = min(3, $bw / 2, $h);
        $relleno = !empty($c['resaltar']) || $conEtiquetas ? $color : '#cbd5e1';
        $svg .= '<g><title>' . h($c['titulo']) . '</title>'
              . '<rect x="' . round($espacio, 2) . '" y="' . $arriba . '" width="' . round($w, 2) . '" height="' . ($alto + $abajo) . '" fill="transparent"/>';
        if ($h > 0) {   // columna con la punta redondeada y la base recta sobre el eje
            $svg .= '<path fill="' . $relleno . '" d="M' . round($x, 2) . ' ' . $base . 'V' . round($y + $r, 2) . 'Q' . round($x, 2) . ' ' . round($y, 2) . ' ' . round($x + $r, 2) . ' ' . round($y, 2)
                  . 'H' . round($x + $bw - $r, 2) . 'Q' . round($x + $bw, 2) . ' ' . round($y, 2) . ' ' . round($x + $bw, 2) . ' ' . round($y + $r, 2) . 'V' . $base . 'Z"/>';
        }
        if ($conEtiquetas) {
            $cx = round($x + $bw / 2, 2);
            $svg .= '<text x="' . $cx . '" y="' . round($y - 4, 2) . '" text-anchor="middle" class="grafico-valor">' . h((string)(int)$c['valor']) . '</text>'
                  . '<text x="' . $cx . '" y="' . ($base + 13) . '" text-anchor="middle" class="grafico-eje">' . h($c['etiqueta']) . '</text>';
        }
        $svg .= '</g>';
    }
    return $svg . '</svg>';
}
// Medidor: barra <progress> de Bulma; el fondo es un tono claro del mismo color ($tipo: acento, alerta o critico).
function grafico_medidor($valor, $total, $texto, $tipo = 'acento') {
    $total = max(0, (int)$total);
    $pct = $total ? round($valor / $total * 100) : 0;
    return '<progress class="progress medidor medidor-' . $tipo . '" value="' . (int)$valor . '" max="' . max(1, $total) . '" title="' . h($texto) . '">' . $pct . ' %</progress>'
         . '<div class="grafico-pie">' . h($texto) . '</div>';
}
// Barra dividida en partes con su leyenda: [['nombre' => ..., 'valor' => n, 'color' => #hex]]
function grafico_partes(array $partes, $descripcion) {
    $barra = '';
    $leyenda = '';
    foreach ($partes as $p) {
        if ((int)$p['valor'] > 0) $barra .= '<span style="flex-grow:' . (int)$p['valor'] . ';background:' . $p['color'] . '" title="' . h($p['nombre'] . ': ' . (int)$p['valor']) . '"></span>';
        $leyenda .= '<li><i style="background:' . $p['color'] . '"></i>' . h($p['nombre']) . ' <b>' . (int)$p['valor'] . '</b></li>';
    }
    return '<div class="barra-partes" role="img" aria-label="' . h($descripcion) . '">' . $barra . '</div><ul class="leyenda-partes">' . $leyenda . '</ul>';
}
$fmtDia = fn($dia) => date('d/m', strtotime($dia));
$colSemana = [];
foreach ($semana as $dia => $t) $colSemana[] = ['valor' => $t, 'titulo' => $fmtDia($dia) . ': ' . dinero($t), 'resaltar' => $dia === date('Y-m-d')];
$colMes = [];
foreach ($mes as $dia => $t) $colMes[] = ['valor' => $t, 'titulo' => $fmtDia($dia) . ': ' . dinero($t), 'resaltar' => $dia === date('Y-m-d')];

pagina_inicio('Inicio', 'inicio');
encabezado('Bienvenido, ' . usuario_actual()['nombre'], 'Resumen de la farmacia · ' . date('d/m/Y'), '<a class="button is-primary" href="venta_nueva.php">+ Nueva venta</a>' . ($gestion ? '<a class="button is-info" href="compra_nueva.php">+ Nueva compra</a>' : ''));
?>
<div class="columns is-multiline">
  <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">Ventas de hoy</div><div class="valor"><?= dinero($ventasHoy['t']) ?></div><small><?= (int)$ventasHoy['n'] ?> ventas</small>
    <div class="tarjeta-grafico"><?= grafico_columnas($colSemana, 'Ventas de los últimos 7 días; la columna resaltada es hoy') ?><div class="grafico-pie">Últimos 7 días · hoy resaltado</div></div></div></div>
  <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">Ventas del mes</div><div class="valor"><?= dinero($ventasMes['t']) ?></div><small><?= (int)$ventasMes['n'] ?> ventas</small>
    <div class="tarjeta-grafico"><?= grafico_columnas($colMes, 'Ventas por día de este mes') ?><div class="grafico-pie">Por día, del 1 al <?= date('j') ?></div></div></div></div>
  <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">Medicamentos</div><div class="valor"><?= $nMed ?></div><small>registrados</small>
    <div class="tarjeta-grafico"><?= grafico_partes([
        ['nombre' => 'Suficiente', 'valor' => $estadoStock['bien'] ?? 0, 'color' => '#4ade80'],
        ['nombre' => 'Bajo', 'valor' => $estadoStock['bajo'] ?? 0, 'color' => '#d97706'],
        ['nombre' => 'Sin stock', 'valor' => $estadoStock['sin'] ?? 0, 'color' => '#b91c1c']], 'Medicamentos según su stock vendible') ?></div></div></div>
  <div class="column is-3"><div class="tarjeta-dato"><div class="etiqueta">Clientes</div><div class="valor"><?= $nCli ?></div><small>registrados</small>
    <div class="tarjeta-grafico"><?= grafico_medidor($cliCompraron, $nCli, $cliCompraron . ' de ' . $nCli . ' ya han comprado') ?></div></div></div>
  <?php if ($gestion): ?>
  <div class="column is-4"><a href="lotes.php" class="tarjeta-dato <?= $porVencer ? 'alerta' : '' ?>" style="display:block"><div class="etiqueta">Lotes por vencer (90 días)</div><div class="valor"><?= $porVencer ?></div>
    <div class="tarjeta-grafico"><?= grafico_columnas([
        ['valor' => (int)($plazos['a30'] ?? 0), 'titulo' => 'Vencen en 30 días o menos: ' . (int)($plazos['a30'] ?? 0), 'etiqueta' => '≤ 30 días'],
        ['valor' => (int)($plazos['a60'] ?? 0), 'titulo' => 'Vencen en 31 a 60 días: ' . (int)($plazos['a60'] ?? 0), 'etiqueta' => '31–60 días'],
        ['valor' => (int)($plazos['a90'] ?? 0), 'titulo' => 'Vencen en 61 a 90 días: ' . (int)($plazos['a90'] ?? 0), 'etiqueta' => '61–90 días']],
        'Lotes por vencer según los días que faltan', '#d97706', true) ?></div></a></div>
  <div class="column is-4"><a href="lotes.php" class="tarjeta-dato <?= $vencidos ? 'alerta' : '' ?>" style="display:block"><div class="etiqueta">Lotes vencidos con existencias</div><div class="valor"><?= $vencidos ?></div>
    <div class="tarjeta-grafico"><?= grafico_medidor($vencidos, $lotesConStock, $vencidos . ' de ' . $lotesConStock . ' lotes activos con existencias', 'critico') ?></div></a></div>
  <div class="column is-4"><a href="inventario.php" class="tarjeta-dato <?= $bajo ? 'alerta' : '' ?>" style="display:block"><div class="etiqueta">Medicamentos con stock bajo (≤10)</div><div class="valor"><?= $bajo ?></div>
    <div class="tarjeta-grafico"><?= grafico_medidor($bajo, $nMed, $bajo . ' de ' . $nMed . ' medicamentos', 'alerta') ?></div></a></div>
  <?php endif; ?>
</div>

<div class="columns">
  <div class="column"><div class="box"><h2 class="title is-5">Últimas ventas</h2>
    <table class="table is-fullwidth is-striped"><tbody>
    <?php if (!$ultimas): ?><tr><td class="has-text-grey">Aún no hay ventas.</td></tr><?php endif; ?>
    <?php foreach ($ultimas as $v): ?><tr><td><a href="venta_ver.php?id=<?= (int)$v['id_venta'] ?>">N.º <?= (int)$v['id_venta'] ?></a></td><td><?= h($v['cliente']) ?></td><td><?= fecha_fmt($v['fecha_venta'], true) ?></td>
      <td class="col-num"><?= dinero($v['total']) ?> <?= $v['estado'] === 'Anulada' ? '<span class="tag is-anulada">Anulada</span>' : '' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  <?php if ($gestion): ?>
  <div class="column"><div class="box"><h2 class="title is-5">Próximos a vencer</h2>
    <table class="table is-fullwidth is-striped"><tbody>
    <?php if (!$proximos): ?><tr><td class="has-text-grey">No hay lotes por vencer en los próximos 90 días.</td></tr><?php endif; ?>
    <?php foreach ($proximos as $p): ?><tr><td><strong><?= h($p['nombre']) ?></strong> <small><?= h(trim($p['concentracion'] . ' ' . $p['presentacion'])) ?></small><br><small>Lote <?= h($p['numero_lote']) ?></small></td><td><?= fecha_fmt($p['fecha_vencimiento']) ?></td><td class="col-num"><?= (int)$p['disponible'] ?> u.</td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  <?php endif; ?>
</div>

<h2 class="title is-5 mt-5">Módulos</h2>
<div class="columns is-multiline">
<?php
$modulos = [
    ['venta_nueva.php', 'Nueva venta', 'Punto de venta con carrito', ROLES], ['ventas.php', 'Historial de ventas', 'Consultar, imprimir y anular', ROLES],
    ['compra_nueva.php', 'Nueva compra', 'Ingresar facturas y lotes', ROLES_GESTION], ['compras.php', 'Historial de compras', 'Facturas de proveedores', ROLES_GESTION],
    ['index.php', 'Medicamentos', 'Catálogo de productos', ROLES_GESTION], ['inventario.php', 'Existencias', 'Stock por medicamento', ROLES_GESTION],
    ['lotes.php', 'Lotes', 'Vencimientos y estado', ROLES_GESTION], ['categorias.php', 'Categorías', 'Clasificación', ROLES_GESTION],
    ['presentaciones.php', 'Presentaciones', 'Tabletas, jarabes…', ROLES_GESTION],
    ['clientes.php', 'Clientes', 'Datos de clientes', ROLES], ['recetas.php', 'Recetas', 'Recetas médicas', ROLES],
    ['proveedores.php', 'Proveedores', 'Empresas proveedoras', ROLES_GESTION], ['reportes.php', 'Reportes', 'Ventas, compras y utilidad', ROLES_ADMIN],
    ['usuarios.php', 'Usuarios', 'Accesos y roles', ROLES_ADMIN], ['auditoria.php', 'Auditoría', 'Quién hizo qué', ROLES_ADMIN],
    ['mantenimiento.php', 'Mantenimiento', 'Data Warehouse y respaldos', ROLES_ADMIN],
];
foreach ($modulos as [$url, $t, $d, $roles]) {
    if (!tiene_rol($roles)) continue;
    echo '<div class="column is-3"><a class="tarjeta-modulo" href="' . $url . '"><strong>' . h($t) . '</strong><small>' . h($d) . '</small></a></div>';
}
?>
</div>
<?php pagina_fin();

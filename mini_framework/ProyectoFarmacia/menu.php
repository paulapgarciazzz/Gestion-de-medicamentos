<?php
/* Menú lateral (sidebar). Se incluye justo después de <body>: el contenido que sigue (.section) se corre a la derecha.
 * En pantallas angostas el sidebar se oculta y se abre con el botón ☰ de la barra superior (app.js). */
require_once __DIR__ . '/lib.php';
$__u   = usuario_actual();
$__rol = $__u['rol'] ?? '';
$__act = $activo ?? '';
$__icono = fn($trazos, $clase = 'sidebar-icono') => '<svg class="' . $clase . '" viewBox="0 0 24 24" aria-hidden="true">' . $trazos . '</svg>';
$__flecha = $__icono('<path d="m9 6 6 6-6 6"/>', 'sidebar-flecha');
$__item = function ($href, $texto, $clave, array $roles = ROLES) use ($__rol, $__act) {
    if (!in_array($__rol, $roles, true)) return '';
    return '<a class="sidebar-enlace' . ($__act === $clave ? ' is-activo' : '') . '" href="' . $href . '"' . ($__act === $clave ? ' aria-current="page"' : '') . '>' . $texto . '</a>';
};
$__grupo = function ($titulo, $icono, $clavesActivas, array $items) use ($__act, $__flecha) {
    $html = implode('', $items);
    if ($html === '') return '';
    $abierto = in_array($__act, $clavesActivas, true);
    return '<details class="sidebar-grupo"' . ($abierto ? ' open' : '') . '><summary class="sidebar-titulo' . ($abierto ? ' is-activo' : '') . '">'
        . $icono . '<span>' . $titulo . '</span>' . $__flecha . '</summary><div class="sidebar-subenlaces">' . $html . '</div></details>';
};
?>
<header class="barra-movil no-imprimir">
  <button type="button" class="sidebar-boton" data-burger aria-label="Abrir el menú" aria-controls="sidebar" aria-expanded="false">
    <?= $__icono('<path d="M4 6h16M4 12h16M4 18h16"/>', '') ?>
  </button>
  <strong>Farmacia</strong>
</header>
<div class="sidebar-fondo no-imprimir" data-cerrar-menu></div>
<aside class="sidebar no-imprimir" id="sidebar">
  <a class="sidebar-marca" href="inicio.php">
    <span class="sidebar-logo"><?= $__icono('<path d="M12 5v14M5 12h14"/>', '') ?></span>
    <span><strong>Farmacia</strong><small>Gestión de medicamentos</small></span>
  </a>
  <nav class="sidebar-navegacion" aria-label="Menú principal">
    <a class="sidebar-titulo<?= $__act === 'inicio' ? ' is-activo' : '' ?>" href="inicio.php"<?= $__act === 'inicio' ? ' aria-current="page"' : '' ?>>
      <?= $__icono('<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/>') ?><span>Inicio</span>
    </a>
    <?= $__grupo('Ventas', $__icono('<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.6 12.4a2 2 0 0 0 2 1.6h8.8a2 2 0 0 0 2-1.6L22 7H6"/>'),
          ['ventas', 'venta_nueva'], [
            $__item('venta_nueva.php', 'Nueva venta', 'venta_nueva'),
            $__item('ventas.php', 'Historial de ventas', 'ventas')]) ?>
    <?= $__grupo('Compras', $__icono('<path d="M2 6h12v10H2z"/><path d="M14 9h4l3.5 3.5V16H14"/><circle cx="6.5" cy="18" r="2"/><circle cx="17.5" cy="18" r="2"/>'),
          ['compras', 'compra_nueva'], [
            $__item('compra_nueva.php', 'Nueva compra', 'compra_nueva', ROLES_GESTION),
            $__item('compras.php', 'Historial de compras', 'compras', ROLES_GESTION)]) ?>
    <?= $__grupo('Inventario', $__icono('<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/>'),
          ['medicamentos', 'categorias', 'presentaciones', 'lotes', 'inventario'], [
            $__item('index.php', 'Medicamentos', 'medicamentos', ROLES_GESTION),
            $__item('categorias.php', 'Categorías', 'categorias', ROLES_GESTION),
            $__item('presentaciones.php', 'Presentaciones', 'presentaciones', ROLES_GESTION),
            $__item('lotes.php', 'Lotes', 'lotes', ROLES_GESTION),
            $__item('inventario.php', 'Existencias (stock)', 'inventario', ROLES_GESTION)]) ?>
    <?= $__grupo('Personas', $__icono('<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18.5 14.2A6.5 6.5 0 0 1 21.5 20"/>'),
          ['clientes', 'proveedores', 'recetas'], [
            $__item('clientes.php', 'Clientes', 'clientes'),
            $__item('recetas.php', 'Recetas', 'recetas'),
            $__item('proveedores.php', 'Proveedores', 'proveedores', ROLES_GESTION)]) ?>
    <?= $__grupo('Administración', $__icono('<path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/>'),
          ['usuarios', 'auditoria', 'reportes', 'mantenimiento'], [
            $__item('reportes.php', 'Reportes', 'reportes', ROLES_ADMIN),
            $__item('usuarios.php', 'Usuarios', 'usuarios', ROLES_ADMIN),
            $__item('auditoria.php', 'Auditoría', 'auditoria', ROLES_ADMIN),
            $__item('mantenimiento.php', 'Mantenimiento', 'mantenimiento', ROLES_ADMIN)]) ?>
  </nav>
  <?php if ($__u): ?>
  <div class="sidebar-usuario">
    <span class="sidebar-avatar" aria-hidden="true"><?= h(mb_substr((string)$__u['nombre'], 0, 1)) ?></span>
    <span class="sidebar-usuario-datos"><strong><?= h($__u['nombre']) ?></strong><small><?= h($__u['rol']) ?></small></span>
    <a class="sidebar-salir" href="logout.php" title="Salir" aria-label="Salir"><?= $__icono('<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>', '') ?></a>
  </div>
  <?php endif; ?>
</aside>

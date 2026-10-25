<?php
/* Barra de navegación. Puede incluirse en index.php con: <?php include 'menu.php'; ?> */
require_once __DIR__ . '/lib.php';
$__u   = usuario_actual();
$__rol = $__u['rol'] ?? '';
$__act = $activo ?? '';
$__item = function ($href, $texto, $clave, array $roles = ROLES) use ($__rol, $__act) {
    if (!in_array($__rol, $roles, true)) return '';
    return '<a class="navbar-item' . ($__act === $clave ? ' is-activo' : '') . '" href="' . $href . '">' . $texto . '</a>';
};
$__grupo = function ($titulo, $clavesActivas, array $items) use ($__act) {
    $html = implode('', $items);
    if ($html === '') return '';
    return '<div class="navbar-item has-dropdown is-hoverable"><a class="navbar-link' . (in_array($__act, $clavesActivas, true) ? ' is-activo' : '') . '">' . $titulo . '</a><div class="navbar-dropdown">' . $html . '</div></div>';
};
?>
<nav class="navbar barra-principal no-imprimir" role="navigation" aria-label="Menú principal">
  <div class="navbar-brand">
    <a class="navbar-item marca" href="inicio.php">Farmacia</a>
    <a role="button" class="navbar-burger" data-burger aria-label="Menú"><span></span><span></span><span></span><span></span></a>
  </div>
  <div class="navbar-menu" id="menu-principal">
    <div class="navbar-start">
      <?= $__item('inicio.php', 'Inicio', 'inicio') ?>
      <?= $__grupo('Ventas', ['ventas', 'venta_nueva'], [
            $__item('venta_nueva.php', 'Nueva venta', 'venta_nueva'),
            $__item('ventas.php', 'Historial de ventas', 'ventas')]) ?>
      <?= $__grupo('Compras', ['compras', 'compra_nueva'], [
            $__item('compra_nueva.php', 'Nueva compra', 'compra_nueva', ROLES_GESTION),
            $__item('compras.php', 'Historial de compras', 'compras', ROLES_GESTION)]) ?>
      <?= $__grupo('Inventario', ['medicamentos', 'categorias', 'presentaciones', 'asignar', 'lotes', 'inventario'], [
            $__item('index.php', 'Medicamentos', 'medicamentos', ROLES_GESTION),
            $__item('categorias.php', 'Categorías', 'categorias', ROLES_GESTION),
            $__item('presentaciones.php', 'Presentaciones', 'presentaciones', ROLES_GESTION),
            $__item('presentaciones_asignar.php', 'Presentaciones por medicamento', 'asignar', ROLES_GESTION),
            $__item('lotes.php', 'Lotes', 'lotes', ROLES_GESTION),
            $__item('inventario.php', 'Existencias (stock)', 'inventario', ROLES_GESTION)]) ?>
      <?= $__grupo('Personas', ['clientes', 'proveedores', 'recetas'], [
            $__item('clientes.php', 'Clientes', 'clientes'),
            $__item('recetas.php', 'Recetas', 'recetas'),
            $__item('proveedores.php', 'Proveedores', 'proveedores', ROLES_GESTION)]) ?>
      <?= $__grupo('Administración', ['usuarios', 'auditoria', 'reportes'], [
            $__item('reportes.php', 'Reportes', 'reportes', ROLES_ADMIN),
            $__item('usuarios.php', 'Usuarios', 'usuarios', ROLES_ADMIN),
            $__item('auditoria.php', 'Auditoría', 'auditoria', ROLES_ADMIN)]) ?>
    </div>
    <div class="navbar-end">
      <?php if ($__u): ?>
        <div class="navbar-item usuario-barra"><?= h($__u['nombre']) ?> · <small><?= h($__u['rol']) ?></small></div>
        <a class="navbar-item" href="logout.php">Salir</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

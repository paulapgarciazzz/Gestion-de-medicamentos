<?php
require_once __DIR__ . '/crud_motor.php';
crud_ejecutar([
    'titulo' => 'Proveedores', 'subtitulo' => 'Empresas que le surten medicamentos',
    'singular' => 'proveedor', 'boton_nuevo' => '+ Nuevo proveedor', 'etiqueta' => 'proveedores',
    'tabla' => 'proveedor', 'pk' => 'id_proveedor', 'menu' => 'proveedores', 'roles' => ROLES_GESTION,
    'buscar' => ['t.nombre', 't.identificacion', 't.correo', 't.telefono'], 'orden' => 't.nombre',
    'placeholder' => 'Buscar por nombre, cédula jurídica, correo o teléfono…',
    'campos' => [
        ['n' => 'nombre', 'l' => 'Nombre', 'max' => 50, 'req' => true, 'col' => 6],
        ['n' => 'identificacion', 'l' => 'Identificación', 'max' => 50, 'req' => true, 'unico' => true, 'col' => 6],
        ['n' => 'telefono', 'l' => 'Teléfono', 'max' => 20, 't' => 'tel', 'col' => 4],
        ['n' => 'correo', 'l' => 'Correo', 'max' => 50, 't' => 'email', 'col' => 8],
        ['n' => 'direccion', 'l' => 'Dirección', 'max' => 100],
        ['n' => 'condiciones_pago', 'l' => 'Condiciones de pago', 'max' => 100, 'ph' => 'Ej. Crédito a 30 días'],
    ],
    'lista' => [
        ['Proveedor', 'nombre', 'f' => fn($r) => '<strong>' . h($r['nombre']) . '</strong><br><small>' . h($r['direccion']) . '</small>'],
        ['Identificación', 'identificacion'],
        ['Teléfono', 'telefono'],
        ['Correo', 'correo'],
        ['Condiciones de pago', 'condiciones_pago'],
    ],
    'msg_fk' => 'No se puede eliminar: el proveedor tiene compras registradas.',
]);

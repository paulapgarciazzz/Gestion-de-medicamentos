<?php
require_once __DIR__ . '/crud_motor.php';
crud_ejecutar([
    'titulo' => 'Clientes', 'subtitulo' => 'Personas a quienes se les vende',
    'singular' => 'cliente', 'boton_nuevo' => '+ Nuevo cliente', 'etiqueta' => 'clientes',
    'tabla' => 'cliente', 'pk' => 'id_cliente', 'menu' => 'clientes', 'roles' => ROLES,
    'roles_eliminar' => ROLES_GESTION,
    'buscar' => ['t.primer_nombre', 't.segundo_nombre', 't.primer_apellido', 't.segundo_apellido', 't.identificacion', 't.telefono', 't.correo'],
    'orden' => 't.primer_apellido, t.primer_nombre', 'placeholder' => 'Buscar por nombre, identificación, teléfono o correo…',
    'select' => 't.*, ' . sql_nombre_cliente('t') . ' AS nombre_completo',
    'campos' => [
        ['n' => 'primer_nombre', 'l' => 'Primer nombre', 'max' => 50, 'req' => true, 'col' => 6],
        ['n' => 'segundo_nombre', 'l' => 'Segundo nombre', 'max' => 50, 'col' => 6],
        ['n' => 'primer_apellido', 'l' => 'Primer apellido', 'max' => 50, 'req' => true, 'col' => 6],
        ['n' => 'segundo_apellido', 'l' => 'Segundo apellido', 'max' => 50, 'col' => 6],
        ['n' => 'identificacion', 'l' => 'Identificación (cédula)', 'max' => 50, 'req' => true, 'unico' => true, 'col' => 6],
        ['n' => 'telefono', 'l' => 'Teléfono', 'max' => 20, 't' => 'tel', 'col' => 6],
        ['n' => 'correo', 'l' => 'Correo', 'max' => 50, 't' => 'email', 'col' => 6],
        ['n' => 'direccion', 'l' => 'Dirección', 'max' => 50, 'col' => 6],
    ],
    'lista' => [
        ['Cliente', 'nombre_completo', 'f' => fn($r) => '<strong>' . h($r['nombre_completo']) . '</strong>'],
        ['Identificación', 'identificacion'],
        ['Teléfono', 'telefono'],
        ['Correo', 'correo'],
    ],
    'msg_fk' => 'No se puede eliminar: el cliente tiene ventas o recetas registradas.',
]);

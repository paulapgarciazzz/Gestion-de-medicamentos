<?php
require_once __DIR__ . '/crud_motor.php';
crud_ejecutar([
    'titulo' => 'Usuarios', 'subtitulo' => 'Personas que usan el sistema y sus permisos',
    'singular' => 'usuario', 'boton_nuevo' => '+ Nuevo usuario', 'etiqueta' => 'usuarios',
    'tabla' => 'usuario', 'pk' => 'id_usuario', 'menu' => 'usuarios', 'roles' => ROLES_ADMIN,
    'buscar' => ['t.nombre', 't.primer_apellido', 't.segundo_apellido', 't.correo', 't.rol'], 'orden' => 't.nombre',
    'placeholder' => 'Buscar por nombre, correo o rol…',
    // Nunca se selecciona la contraseña en los listados.
    'select' => 't.id_usuario,t.nombre,t.primer_apellido,t.segundo_apellido,t.correo,t.rol,t.estado,t.fecha_registro',
    'campos' => [
        ['n' => 'nombre', 'l' => 'Nombre', 'max' => 20, 'req' => true, 'col' => 4],
        ['n' => 'primer_apellido', 'l' => 'Primer apellido', 'max' => 20, 'req' => true, 'col' => 4],
        ['n' => 'segundo_apellido', 'l' => 'Segundo apellido', 'max' => 20, 'col' => 4],
        ['n' => 'correo', 'l' => 'Correo (usuario de ingreso)', 'max' => 100, 't' => 'email', 'req' => true, 'unico' => true, 'col' => 8],
        ['n' => 'rol', 'l' => 'Rol', 't' => 'select', 'req' => true, 'col' => 4,
            'opciones' => ['Administrador' => 'Administrador (todo)', 'Farmaceutico' => 'Farmacéutico (inventario, compras, ventas)', 'Vendedor' => 'Vendedor (ventas, clientes, recetas)']],
        ['n' => 'contrasenna', 't' => 'password', 'l' => 'Contraseña', 'col' => 6, 'transformar' => 'contrasenna_preparar'],
        ['n' => 'estado', 'l' => 'Usuario activo (puede ingresar)', 't' => 'checkbox', 'def' => 1, 'col' => 6],
    ],
    'validar' => function ($v, $id) {
        if (isset($v['contrasenna']) && ($m = contrasenna_validar_formato($v['contrasenna']))) return $m;
        if ($id === usuario_actual()['id'] && empty($v['estado'])) return 'No puede desactivar su propio usuario.';
        if ($id === usuario_actual()['id'] && $v['rol'] !== 'Administrador') return 'No puede quitarse a sí mismo el rol de administrador.';
        return null;
    },
    'puede_eliminar' => fn($id) => $id === usuario_actual()['id'] ? 'No puede eliminar su propio usuario.' : null,
    'msg_fk' => 'No se puede eliminar: el usuario tiene compras o movimientos de auditoría. Desactívelo en su lugar (edítelo y desmarque «Usuario activo»).',
    'lista' => [
        ['Usuario', 'nombre', 'f' => fn($r) => '<strong>' . h(trim($r['nombre'] . ' ' . $r['primer_apellido'] . ' ' . $r['segundo_apellido'])) . '</strong>'],
        ['Correo', 'correo'],
        ['Rol', 'rol', 'f' => fn($r) => '<span class="tag is-neutro">' . h($r['rol']) . '</span>'],
        ['Estado', 'estado', 'f' => fn($r) => $r['estado'] ? '<span class="tag is-vigente">Activo</span>' : '<span class="tag is-vencido">Inactivo</span>'],
        ['Registro', 'fecha_registro', 'f' => fn($r) => fecha_fmt($r['fecha_registro'])],
    ],
]);

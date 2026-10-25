<?php
require_once __DIR__ . '/crud_motor.php';
crud_ejecutar([
    'titulo' => 'Categorías', 'subtitulo' => 'Clasificación de los medicamentos',
    'singular' => 'categoría', 'boton_nuevo' => '+ Nueva categoría', 'etiqueta' => 'categorías',
    'tabla' => 'categoria', 'pk' => 'id_categoria', 'menu' => 'categorias', 'roles' => ROLES_GESTION,
    'buscar' => ['t.nombre', 't.descripcion'], 'orden' => 't.nombre', 'placeholder' => 'Buscar categoría…',
    'select' => 't.*, (SELECT COUNT(*) FROM dbo.medicamento m WHERE m.id_categoria=t.id_categoria) AS medicamentos',
    'campos' => [
        ['n' => 'nombre', 'l' => 'Nombre', 'max' => 30, 'req' => true, 'unico' => true],
        ['n' => 'descripcion', 'l' => 'Descripción', 'max' => 50],
    ],
    'lista' => [
        ['Nombre', 'nombre', 'f' => fn($r) => '<strong>' . h($r['nombre']) . '</strong>'],
        ['Descripción', 'descripcion'],
        ['Medicamentos', 'medicamentos'],
    ],
    'msg_fk' => 'No se puede eliminar: hay medicamentos en esta categoría. Cámbieles la categoría primero.',
]);

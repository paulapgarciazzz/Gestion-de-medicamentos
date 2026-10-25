<?php
require_once __DIR__ . '/crud_motor.php';
crud_ejecutar([
    'titulo' => 'Presentaciones', 'subtitulo' => 'Tabletas, jarabes, ampollas, cremas…',
    'singular' => 'presentación', 'boton_nuevo' => '+ Nueva presentación', 'etiqueta' => 'presentaciones',
    'tabla' => 'presentacion', 'pk' => 'id_presentacion', 'menu' => 'presentaciones', 'roles' => ROLES_GESTION,
    'botones' => '<a class="button is-info" href="presentaciones_asignar.php">Asignar a medicamentos</a>',
    'buscar' => ['t.nombre'], 'orden' => 't.nombre', 'placeholder' => 'Buscar presentación…',
    'select' => 't.*, (SELECT COUNT(*) FROM dbo.medicamento_presentacion mp WHERE mp.id_presentacion=t.id_presentacion) AS medicamentos',
    'campos' => [
        ['n' => 'nombre', 'l' => 'Nombre', 'max' => 50, 'req' => true, 'unico' => true],
    ],
    'lista' => [
        ['Nombre', 'nombre', 'f' => fn($r) => '<strong>' . h($r['nombre']) . '</strong>'],
        ['Medicamentos asignados', 'medicamentos'],
    ],
    'msg_fk' => 'No se puede eliminar: la presentación está asignada a medicamentos. Quítela primero en «Asignar a medicamentos».',
]);

/* =====================================================================
   Datos de prueba: Farmacia (Costa Rica)
   Motor: SQL Server (T-SQL)
   Proposito: poblar la base con datos ficticios coherentes para probar
              el reporte con cursor, los respaldos y el Data Warehouse.

   Que hace:
     - Inserta categorias, presentaciones, proveedores, clientes y
       medicamentos SOLO si no existen (se buscan por su dato unico:
       nombre, identificacion o codigo de barras).
     - Inserta 5 compras con sus lotes (lote + detalle_lote + detalle_compra),
       35 recetas, 100 ventas (3 anuladas) con 164 lineas y su auditoria.
       Este bloque se omite completo si las compras de prueba ya existen.
     - Las fechas son relativas al dia en que se ejecuta: compras hace
       40-190 dias y ventas en los ultimos 180 dias.
     - Todo ocurre en una sola transaccion: si algo falla no queda nada.

   Que NO hace:
     - No borra ni actualiza registros existentes.
     - No modifica la estructura de las tablas.
     - No crea usuarios ni contrasenas: usa el Administrador ya registrado.

   Datos ficticios: nombres, cedulas, telefonos y codigos de barras son
   inventados; los correos usan el dominio reservado .example.
   ===================================================================== */

USE farmacia;
GO

-- Necesarias para insertar en medicamento (tiene un indice filtrado). SSMS las trae
-- activadas, pero sqlcmd no: por eso se fijan aqui, en su propio lote.
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

SET NOCOUNT ON;
SET XACT_ABORT ON;  -- ante cualquier error la transaccion queda marcada para revertirse

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @hoy DATE = CAST(GETDATE() AS DATE);

    -- Usuario que figura en compras y auditoria: el primer Administrador activo
    DECLARE @id_admin INT = (
        SELECT TOP (1) id_usuario FROM dbo.usuario
        WHERE rol = 'Administrador' AND estado = 1
        ORDER BY id_usuario
    );
    IF @id_admin IS NULL
        THROW 50100, 'No hay un usuario Administrador activo. Cree el administrador desde login.php antes de cargar los datos.', 1;

    /* -----------------------------------------------------------------
       1. CATEGORIAS (por nombre)
       ----------------------------------------------------------------- */
    INSERT INTO dbo.categoria (nombre, descripcion)
    SELECT v.nombre, v.descripcion
    FROM (VALUES
        ('Analgésicos',             'Alivio del dolor y la fiebre'),
        ('Antiinflamatorios',       'Reducen inflamación y dolor'),
        ('Antibióticos',            'Tratamiento de infecciones bacterianas'),
        ('Antialérgicos',           'Alivio de síntomas de alergia'),
        ('Gastrointestinales',      'Estómago, acidez y diarrea'),
        ('Antihipertensivos',       'Control de la presión arterial'),
        ('Antidiabéticos',          'Control de la glucosa en sangre'),
        ('Vitaminas y suplementos', 'Complementos nutricionales'),
        ('Respiratorios',           'Tos, asma y vías respiratorias'),
        ('Dermatológicos',          'Uso tópico en la piel')
    ) v(nombre, descripcion)
    WHERE NOT EXISTS (SELECT 1 FROM dbo.categoria c WHERE c.nombre = v.nombre);
    PRINT CONCAT('Categorias insertadas: ', @@ROWCOUNT);

    /* -----------------------------------------------------------------
       2. PRESENTACIONES (por nombre)
       ----------------------------------------------------------------- */
    INSERT INTO dbo.presentacion (nombre)
    SELECT v.nombre
    FROM (VALUES
        ('Tableta'), ('Tableta efervescente'), ('Cápsula'), ('Jarabe'), ('Suspensión oral'),
        ('Crema'), ('Gel'), ('Inhalador'), ('Sobre')
    ) v(nombre)
    WHERE NOT EXISTS (SELECT 1 FROM dbo.presentacion p WHERE p.nombre = v.nombre);
    PRINT CONCAT('Presentaciones insertadas: ', @@ROWCOUNT);

    /* -----------------------------------------------------------------
       3. PROVEEDORES (por identificacion / cedula juridica)
       ----------------------------------------------------------------- */
    INSERT INTO dbo.proveedor (nombre, identificacion, telefono, direccion, correo, condiciones_pago)
    SELECT v.nombre, v.identificacion, v.telefono, v.direccion, v.correo, v.condiciones
    FROM (VALUES
        ('Distribuidora Farmacéutica del Valle S.A.', '3-101-845210', '2290-4410', 'San José, La Uruca, 300 m norte de la rotonda', 'ventas@dfvalle.example',      'Crédito a 30 días'),
        ('Droguería Central Tica S.A.',               '3-101-712398', '2441-7720', 'Alajuela, El Coyol, Zona Franca',                'pedidos@centraltica.example', 'Crédito a 45 días'),
        ('Laboratorios Pacífico S.A.',                '3-101-639054', '2661-3305', 'Puntarenas, El Roble, frente al parque',         'ventas@labpacifico.example',  'Contado'),
        ('Importadora Médica del Norte S.A.',         '3-101-590476', '2460-8812', 'Alajuela, San Carlos, Ciudad Quesada',           'compras@imnorte.example',     'Crédito a 15 días')
    ) v(nombre, identificacion, telefono, direccion, correo, condiciones)
    WHERE NOT EXISTS (SELECT 1 FROM dbo.proveedor p WHERE p.identificacion = v.identificacion);
    PRINT CONCAT('Proveedores insertados: ', @@ROWCOUNT);

    /* -----------------------------------------------------------------
       4. CLIENTES (por identificacion)
          '0' = Cliente General para ventas de mostrador; login.php ya lo
          crea con el primer administrador, aqui solo se agrega si falta.
       ----------------------------------------------------------------- */
    INSERT INTO dbo.cliente (primer_nombre, segundo_nombre, primer_apellido, segundo_apellido, identificacion, telefono, correo, direccion)
    SELECT v.n1, v.n2, v.a1, v.a2, v.identificacion, v.telefono, v.correo, v.direccion
    FROM (VALUES
        ('Cliente',  NULL,       'General',    NULL,        '0',           NULL,        NULL,                               NULL),
        ('Mariana',  'José',     'Rojas',      'Vargas',    '1-1450-0321', '8812-4471', 'mariana.rojas@correo.example',     'San José, Curridabat'),
        ('Carlos',   'Andrés',   'Jiménez',    'Mora',      '2-0786-0912', '8705-3318', 'carlos.jimenez@correo.example',    'Alajuela, San Ramón'),
        ('Sofía',    NULL,       'Solano',     'Castro',    '1-1702-0588', '6021-7745', 'sofia.solano@correo.example',      'San José, Desamparados'),
        ('Luis',     'Diego',    'Araya',      'Quesada',   '3-0459-0127', '8890-1204', 'luis.araya@correo.example',        'Cartago, Paraíso'),
        ('Ana',      'Lucía',    'Chaves',     'Méndez',    '4-0213-0764', '7012-5536', 'ana.chaves@correo.example',        'Heredia, Santo Domingo'),
        ('José',     'Pablo',    'Hernández',  'Ulate',     '1-0987-0345', '8433-9087', 'jose.hernandez@correo.example',    'San José, Escazú'),
        ('Valeria',  NULL,       'Campos',     'Brenes',    '5-0398-0611', '6155-2280', 'valeria.campos@correo.example',    'Guanacaste, Liberia'),
        ('Daniel',   'Esteban',  'Murillo',    'Salas',     '6-0274-0839', '8367-4412', 'daniel.murillo@correo.example',    'Puntarenas, Esparza'),
        ('Gabriela', 'María',    'Alfaro',     'Cordero',   '1-1325-0098', '8901-6623', 'gabriela.alfaro@correo.example',   'San José, Moravia'),
        ('Andrés',   NULL,       'Villalobos', 'Arias',     '2-0651-0473', '7290-1158', 'andres.villalobos@correo.example', 'Alajuela, Grecia'),
        ('Laura',    'Patricia', 'Núñez',      'Barrantes', '7-0182-0356', '8644-0937', 'laura.nunez@correo.example',       'Limón, Pococí'),
        ('Ricardo',  'Alonso',   'Calderón',   'Fallas',    '1-0812-0764', '8577-3301', 'ricardo.calderon@correo.example',  'San José, Tibás'),
        ('Natalia',  NULL,       'Herrera',    'Zúñiga',    '4-0287-0915', '6043-8879', 'natalia.herrera@correo.example',   'Heredia, Barva'),
        ('Fernando', 'José',     'Porras',     'León',      '3-0377-0582', '8722-6614', 'fernando.porras@correo.example',   'Cartago, Oreamuno')
    ) v(n1, n2, a1, a2, identificacion, telefono, correo, direccion)
    WHERE NOT EXISTS (SELECT 1 FROM dbo.cliente c WHERE c.identificacion = v.identificacion);
    PRINT CONCAT('Clientes insertados: ', @@ROWCOUNT);

    /* -----------------------------------------------------------------
       5. MEDICAMENTOS (por codigo de barras, que ya es unico en la tabla)
          Precios en colones por caja/frasco/unidad de venta.
          IVA 2 % para medicamentos y 13 % para suplementos.
          Dos medicamentos comparten nombre (Acetaminofen e Ibuprofeno) con
          distinta presentacion, para probar que el reporte los separa.
       ----------------------------------------------------------------- */
    INSERT INTO dbo.medicamento (id_categoria, nombre, id_presentacion, concentracion, precio_venta, requiere_receta,
                                 condicion_almacenamiento, codigo_barras, precio_costo, iva_porcentaje)
    SELECT (SELECT MIN(c.id_categoria) FROM dbo.categoria c WHERE c.nombre = v.categoria),
           v.nombre,
           (SELECT MIN(p.id_presentacion) FROM dbo.presentacion p WHERE p.nombre = v.presentacion),
           v.concentracion, v.precio_venta, v.receta, v.almacenamiento, v.codigo_barras, v.precio_costo, v.iva
    FROM (VALUES
        ('7449900000011', 'Acetaminofén',               'Analgésicos',             'Tableta',              '500 mg',        1200, 700,  2.00, 0, 'Lugar fresco y seco, menor a 30 °C'),
        ('7449900000028', 'Acetaminofén',               'Analgésicos',             'Jarabe',               '120 mg/5 mL',   2650, 1600, 2.00, 0, 'No refrigerar. Agitar antes de usar'),
        ('7449900000035', 'Ibuprofeno',                 'Antiinflamatorios',       'Tableta',              '400 mg',        1850, 1050, 2.00, 0, 'Lugar fresco y seco, menor a 30 °C'),
        ('7449900000042', 'Ibuprofeno',                 'Antiinflamatorios',       'Suspensión oral',      '100 mg/5 mL',   3400, 2050, 2.00, 0, 'No refrigerar. Agitar antes de usar'),
        ('7449900000059', 'Naproxeno',                  'Antiinflamatorios',       'Tableta',              '550 mg',        2900, 1700, 2.00, 0, 'Lugar fresco y seco, menor a 30 °C'),
        ('7449900000066', 'Amoxicilina',                'Antibióticos',            'Cápsula',              '500 mg',        4800, 2900, 2.00, 1, 'Lugar fresco y seco, menor a 25 °C'),
        ('7449900000073', 'Azitromicina',               'Antibióticos',            'Tableta',              '500 mg',        7900, 4700, 2.00, 1, 'Lugar fresco y seco, menor a 30 °C'),
        ('7449900000080', 'Loratadina',                 'Antialérgicos',           'Tableta',              '10 mg',         1950, 1100, 2.00, 0, 'Lugar fresco y seco, menor a 30 °C'),
        ('7449900000097', 'Cetirizina',                 'Antialérgicos',           'Jarabe',               '5 mg/5 mL',     3900, 2300, 2.00, 0, 'No refrigerar. Proteger de la luz'),
        ('7449900000103', 'Omeprazol',                  'Gastrointestinales',      'Cápsula',              '20 mg',         2450, 1350, 2.00, 0, 'Lugar fresco y seco, menor a 25 °C'),
        ('7449900000110', 'Sales de rehidratación oral', 'Gastrointestinales',     'Sobre',                '27,9 g',        650,  350,  2.00, 0, 'Lugar fresco y seco'),
        ('7449900000127', 'Loperamida',                 'Gastrointestinales',      'Tableta',              '2 mg',          1500, 850,  2.00, 0, 'Lugar fresco y seco, menor a 30 °C'),
        ('7449900000134', 'Losartán',                   'Antihipertensivos',       'Tableta',              '50 mg',         5200, 3100, 2.00, 1, 'Lugar fresco y seco, menor a 30 °C'),
        ('7449900000141', 'Enalapril',                  'Antihipertensivos',       'Tableta',              '20 mg',         3600, 2100, 2.00, 1, 'Lugar fresco y seco, menor a 30 °C'),
        ('7449900000158', 'Metformina',                 'Antidiabéticos',          'Tableta',              '850 mg',        4100, 2400, 2.00, 1, 'Lugar fresco y seco, menor a 30 °C'),
        ('7449900000165', 'Vitamina C',                 'Vitaminas y suplementos', 'Tableta efervescente', '1 g',           3500, 2000, 13.00, 0, 'Mantener el tubo bien cerrado'),
        ('7449900000172', 'Complejo B',                 'Vitaminas y suplementos', 'Tableta',              NULL,            4200, 2500, 13.00, 0, 'Lugar fresco y seco'),
        ('7449900000189', 'Salbutamol',                 'Respiratorios',           'Inhalador',            '100 mcg/dosis', 6900, 4200, 2.00, 1, 'No exponer al calor ni perforar'),
        ('7449900000196', 'Ambroxol',                   'Respiratorios',           'Jarabe',               '30 mg/5 mL',    3800, 2200, 2.00, 0, 'No refrigerar. Agitar antes de usar'),
        ('7449900000202', 'Hidrocortisona',             'Dermatológicos',          'Crema',                '1 %',           2700, 1550, 2.00, 0, 'Menor a 25 °C'),
        ('7449900000219', 'Clotrimazol',                'Dermatológicos',          'Crema',                '1 %',           2300, 1300, 2.00, 0, 'Menor a 25 °C'),
        ('7449900000226', 'Diclofenaco',                'Antiinflamatorios',       'Gel',                  '1 %',           3100, 1800, 2.00, 0, 'Menor a 25 °C')
    ) v(codigo_barras, nombre, categoria, presentacion, concentracion, precio_venta, precio_costo, iva, receta, almacenamiento)
    WHERE NOT EXISTS (SELECT 1 FROM dbo.medicamento m WHERE m.codigo_barras = v.codigo_barras);
    PRINT CONCAT('Medicamentos insertados: ', @@ROWCOUNT);

    /* =================================================================
       6. MOVIMIENTOS: compras, lotes, recetas, ventas y auditoria
       ================================================================= */

    -- Compras: num, proveedor (identificacion), factura, dias atras
    DECLARE @plan_compra TABLE (num INT PRIMARY KEY, proveedor VARCHAR(50), factura INT, dias INT);
    INSERT INTO @plan_compra VALUES
        (1, '3-101-845210',  70451, 190),
        (2, '3-101-712398', 120877, 188),
        (3, '3-101-639054',   5532, 185),
        (4, '3-101-845210',  71230,  75),
        (5, '3-101-590476',  30418,  40);

    IF EXISTS (
        SELECT 1
        FROM @plan_compra pc
        INNER JOIN dbo.proveedor p ON p.identificacion = pc.proveedor
        INNER JOIN dbo.compra c    ON c.id_proveedor = p.id_proveedor AND c.numero_factura = pc.factura
    )
    BEGIN
        PRINT 'Las compras de prueba ya existen: se omiten compras, lotes, recetas y ventas para no duplicarlos.';
    END
    ELSE
    BEGIN
        /* Lotes de cada compra:
           compra, codigo de barras, numero de lote, fabricacion (dias atras),
           vencimiento (dias desde hoy; negativo = ya vencido), cantidad.
           Casos especiales para probar alertas:
             LOP002-2509 vence hace 10 dias y le quedan unidades (vencido con existencias)
             CET005-2510 vence en 60 dias (por vencer)
             CLT1-2603   se vende completo (agotado)
             DCF1-2604   no tiene ventas (medicamento sin movimiento) */
        DECLARE @plan_lote TABLE (id INT IDENTITY(1,1) PRIMARY KEY, compra INT, codigo_barras VARCHAR(50),
                                  numero_lote VARCHAR(50), fab_dias INT, venc_dias INT, cantidad INT);
        INSERT INTO @plan_lote (compra, codigo_barras, numero_lote, fab_dias, venc_dias, cantidad) VALUES
        (1, '7449900000011', 'ACT500-2604', 260, 520, 30),
        (1, '7449900000028', 'ACJ120-2603', 280, 430, 25),
        (1, '7449900000035', 'IBU400-2604', 250, 610, 30),
        (1, '7449900000042', 'IBS100-2603', 270, 400, 20),
        (1, '7449900000059', 'NAP550-2602', 300, 560, 25),
        (1, '7449900000066', 'AMX500-2604', 240, 380, 20),
        (1, '7449900000073', 'AZT500-2603', 260, 470, 12),
        (2, '7449900000080', 'LOR010-2604', 245, 640, 30),
        (2, '7449900000097', 'CET005-2510', 420, 60, 24),
        (2, '7449900000103', 'OME020-2604', 240, 590, 30),
        (2, '7449900000110', 'SRO279-2603', 275, 700, 40),
        (2, '7449900000127', 'LOP002-2509', 430, -10, 20),
        (2, '7449900000134', 'LOS050-2604', 235, 650, 24),
        (2, '7449900000141', 'ENA020-2603', 265, 620, 15),
        (3, '7449900000158', 'MET850-2604', 230, 600, 20),
        (3, '7449900000165', 'VTC1G-2605', 215, 480, 30),
        (3, '7449900000172', 'CXB-2604', 240, 540, 15),
        (3, '7449900000189', 'SAL100-2603', 260, 500, 10),
        (3, '7449900000196', 'AMB030-2604', 230, 450, 25),
        (3, '7449900000202', 'HDC1-2603', 270, 690, 12),
        (3, '7449900000219', 'CLT1-2603', 265, 710, 12),
        (3, '7449900000226', 'DCF1-2604', 230, 640, 15),
        (4, '7449900000011', 'ACT500-2608', 110, 690, 40),
        (4, '7449900000035', 'IBU400-2608', 105, 720, 30),
        (4, '7449900000080', 'LOR010-2608', 100, 730, 30),
        (4, '7449900000103', 'OME020-2608', 100, 700, 30),
        (5, '7449900000028', 'ACJ120-2609', 70, 600, 25),
        (5, '7449900000042', 'IBS100-2609', 65, 560, 20),
        (5, '7449900000066', 'AMX500-2609', 60, 520, 20);

        -- Recetas: numero, cliente, medicamento, emision (dias atras), vigencia (dias)
        -- Las dos ultimas quedan vigentes y sin usar para probar ventas con receta desde la aplicacion.
        DECLARE @plan_receta TABLE (numero_receta INT PRIMARY KEY, cliente VARCHAR(50), codigo_barras VARCHAR(50),
                                    emision_dias INT, vigencia INT);
        INSERT INTO @plan_receta VALUES
        (9100001, '1-1450-0321', '7449900000158', 182, 30),
        (9100002, '4-0213-0764', '7449900000073', 177, 30),
        (9100003, '1-1325-0098', '7449900000134', 170, 30),
        (9100004, '3-0459-0127', '7449900000141', 164, 30),
        (9100005, '1-1702-0588', '7449900000066', 161, 30),
        (9100006, '1-1325-0098', '7449900000141', 152, 30),
        (9100007, '1-0987-0345', '7449900000134', 148, 30),
        (9100008, '1-0987-0345', '7449900000189', 141, 30),
        (9100009, '4-0213-0764', '7449900000066', 144, 30),
        (9100010, '1-0812-0764', '7449900000066', 127, 30),
        (9100011, '2-0786-0912', '7449900000134', 128, 30),
        (9100012, '4-0213-0764', '7449900000158', 125, 30),
        (9100013, '4-0213-0764', '7449900000189', 123, 30),
        (9100014, '1-1702-0588', '7449900000066', 120, 30),
        (9100015, '3-0377-0582', '7449900000134', 116, 30),
        (9100016, '3-0459-0127', '7449900000141', 114, 30),
        (9100017, '2-0651-0473', '7449900000158', 110, 30),
        (9100018, '3-0459-0127', '7449900000066', 99, 30),
        (9100019, '1-1702-0588', '7449900000066', 98, 30),
        (9100020, '2-0786-0912', '7449900000134', 82, 30),
        (9100021, '5-0398-0611', '7449900000158', 79, 30),
        (9100022, '1-1325-0098', '7449900000073', 72, 30),
        (9100023, '4-0287-0915', '7449900000134', 72, 30),
        (9100024, '6-0274-0839', '7449900000066', 65, 30),
        (9100025, '2-0651-0473', '7449900000141', 57, 30),
        (9100026, '4-0213-0764', '7449900000189', 52, 30),
        (9100027, '1-0987-0345', '7449900000066', 30, 30),
        (9100028, '3-0377-0582', '7449900000141', 26, 30),
        (9100029, '3-0377-0582', '7449900000073', 21, 30),
        (9100030, '1-1450-0321', '7449900000158', 13, 30),
        (9100031, '2-0651-0473', '7449900000189', 13, 30),
        (9100032, '4-0287-0915', '7449900000134', 12, 30),
        (9100033, '2-0651-0473', '7449900000066', 3, 30),
        (9100034, '1-1450-0321', '7449900000066', 3, 30),
        (9100035, '3-0459-0127', '7449900000134', 5, 60);

        -- Indicaciones de las recetas segun el medicamento
        DECLARE @indicacion TABLE (codigo_barras VARCHAR(50) PRIMARY KEY, dosis VARCHAR(50), duracion VARCHAR(20),
                                   diagnostico VARCHAR(100), indicaciones VARCHAR(100));
        INSERT INTO @indicacion VALUES
        ('7449900000066', '1 cápsula cada 8 horas',                    '7 días',  'Infección de vías respiratorias', 'Completar el tratamiento aunque mejoren los síntomas'),
        ('7449900000073', '1 tableta al día',                          '3 días',  'Faringoamigdalitis bacteriana',   'Tomar una hora antes o dos horas después de comer'),
        ('7449900000134', '1 tableta al día',                          '30 días', 'Hipertensión arterial',           'Tomar a la misma hora todos los días'),
        ('7449900000141', '1 tableta cada 12 horas',                   '30 días', 'Hipertensión arterial',           'Control de presión arterial semanal'),
        ('7449900000158', '1 tableta con almuerzo y cena',             '30 días', 'Diabetes mellitus tipo 2',        'Tomar con alimentos'),
        ('7449900000189', '2 inhalaciones cada 6 horas si hay crisis', '30 días', 'Asma bronquial',                  'Agitar el inhalador antes de usar');

        -- Ventas: num, dias atras, minuto del dia (480 = 08:00), cliente, metodo de pago, estado, receta
        DECLARE @plan_venta TABLE (num INT PRIMARY KEY, dias INT, minuto INT, cliente VARCHAR(50),
                                   metodo_pago VARCHAR(30), estado VARCHAR(30), numero_receta INT NULL);
        INSERT INTO @plan_venta VALUES
        (1, 180, 512, '1-1702-0588', 'Efectivo', 'Completada', NULL),
        (2, 179, 707, '1-1702-0588', 'SINPE Móvil', 'Completada', NULL),
        (3, 177, 900, '1-1450-0321', 'Tarjeta', 'Completada', 9100001),
        (4, 175, 584, '1-1325-0098', 'SINPE Móvil', 'Completada', NULL),
        (5, 173, 860, '4-0213-0764', 'Tarjeta', 'Completada', 9100002),
        (6, 171, 862, '7-0182-0356', 'SINPE Móvil', 'Completada', NULL),
        (7, 170, 994, '1-1325-0098', 'SINPE Móvil', 'Completada', 9100003),
        (8, 168, 928, '1-0987-0345', 'Tarjeta', 'Completada', NULL),
        (9, 166, 1104, '1-1702-0588', 'SINPE Móvil', 'Completada', NULL),
        (10, 164, 794, '5-0398-0611', 'Tarjeta', 'Completada', NULL),
        (11, 162, 645, '0', 'Efectivo', 'Completada', NULL),
        (12, 161, 891, '3-0459-0127', 'SINPE Móvil', 'Completada', 9100004),
        (13, 159, 1084, '0', 'Transferencia', 'Completada', NULL),
        (14, 157, 994, '1-1702-0588', 'Efectivo', 'Completada', 9100005),
        (15, 155, 669, '1-1702-0588', 'Efectivo', 'Completada', NULL),
        (16, 153, 998, '2-0651-0473', 'Tarjeta', 'Completada', NULL),
        (17, 152, 931, '1-1325-0098', 'SINPE Móvil', 'Completada', 9100006),
        (18, 150, 1106, '5-0398-0611', 'SINPE Móvil', 'Completada', NULL),
        (19, 148, 642, '4-0213-0764', 'Efectivo', 'Completada', NULL),
        (20, 146, 956, '1-0987-0345', 'Tarjeta', 'Completada', 9100007),
        (21, 144, 1006, '0', 'Efectivo', 'Anulada', NULL),
        (22, 143, 787, '0', 'Tarjeta', 'Completada', NULL),
        (23, 141, 672, '1-0987-0345', 'SINPE Móvil', 'Completada', 9100008),
        (24, 139, 1023, '4-0213-0764', 'Efectivo', 'Completada', 9100009),
        (25, 137, 987, '4-0287-0915', 'Efectivo', 'Completada', NULL),
        (26, 135, 774, '0', 'Tarjeta', 'Completada', NULL),
        (27, 133, 894, '0', 'Efectivo', 'Completada', NULL),
        (28, 132, 548, '1-0987-0345', 'Efectivo', 'Completada', NULL),
        (29, 130, 728, '0', 'Efectivo', 'Completada', NULL),
        (30, 128, 548, '7-0182-0356', 'SINPE Móvil', 'Completada', NULL),
        (31, 126, 1114, '1-0812-0764', 'Tarjeta', 'Completada', 9100010),
        (32, 124, 894, '0', 'Efectivo', 'Completada', NULL),
        (33, 123, 571, '2-0786-0912', 'SINPE Móvil', 'Completada', 9100011),
        (34, 121, 772, '4-0213-0764', 'Efectivo', 'Completada', 9100012),
        (35, 119, 1051, '4-0213-0764', 'Efectivo', 'Completada', 9100013),
        (36, 117, 1076, '1-1702-0588', 'Efectivo', 'Completada', 9100014),
        (37, 115, 570, '2-0786-0912', 'Efectivo', 'Completada', NULL),
        (38, 114, 875, '3-0377-0582', 'Tarjeta', 'Completada', 9100015),
        (39, 112, 517, '0', 'Tarjeta', 'Completada', NULL),
        (40, 110, 627, '3-0459-0127', 'SINPE Móvil', 'Completada', 9100016),
        (41, 108, 676, '0', 'SINPE Móvil', 'Completada', NULL),
        (42, 106, 769, '4-0287-0915', 'SINPE Móvil', 'Completada', NULL),
        (43, 105, 862, '2-0651-0473', 'Efectivo', 'Completada', 9100017),
        (44, 103, 772, '2-0786-0912', 'Tarjeta', 'Completada', NULL),
        (45, 101, 945, '6-0274-0839', 'Transferencia', 'Completada', NULL),
        (46, 99, 764, '3-0459-0127', 'Efectivo', 'Completada', 9100018),
        (47, 97, 1093, '1-1702-0588', 'Efectivo', 'Completada', 9100019),
        (48, 96, 966, '0', 'Efectivo', 'Completada', NULL),
        (49, 94, 534, '1-1702-0588', 'Efectivo', 'Completada', NULL),
        (50, 92, 561, '6-0274-0839', 'SINPE Móvil', 'Completada', NULL),
        (51, 90, 1091, '3-0459-0127', 'Efectivo', 'Completada', NULL),
        (52, 88, 819, '4-0213-0764', 'Tarjeta', 'Completada', NULL),
        (53, 86, 515, '1-1702-0588', 'Tarjeta', 'Completada', NULL),
        (54, 85, 557, '6-0274-0839', 'Tarjeta', 'Completada', NULL),
        (55, 83, 1063, '3-0377-0582', 'Efectivo', 'Completada', NULL),
        (56, 81, 1089, '2-0786-0912', 'Tarjeta', 'Anulada', 9100020),
        (57, 79, 729, '6-0274-0839', 'SINPE Móvil', 'Completada', NULL),
        (58, 77, 668, '1-1450-0321', 'Efectivo', 'Completada', NULL),
        (59, 76, 785, '0', 'Efectivo', 'Completada', NULL),
        (60, 74, 647, '5-0398-0611', 'SINPE Móvil', 'Completada', 9100021),
        (61, 72, 664, '3-0377-0582', 'SINPE Móvil', 'Completada', NULL),
        (62, 70, 918, '1-1325-0098', 'Tarjeta', 'Completada', 9100022),
        (63, 68, 612, '4-0287-0915', 'Tarjeta', 'Completada', 9100023),
        (64, 67, 611, '1-0812-0764', 'SINPE Móvil', 'Completada', NULL),
        (65, 65, 1030, '4-0287-0915', 'Efectivo', 'Completada', NULL),
        (66, 63, 867, '0', 'Efectivo', 'Completada', NULL),
        (67, 61, 907, '6-0274-0839', 'SINPE Móvil', 'Completada', 9100024),
        (68, 59, 971, '0', 'Efectivo', 'Completada', NULL),
        (69, 58, 863, '0', 'Efectivo', 'Completada', NULL),
        (70, 56, 1022, '1-1325-0098', 'Transferencia', 'Completada', NULL),
        (71, 54, 945, '1-1325-0098', 'SINPE Móvil', 'Completada', NULL),
        (72, 52, 1086, '2-0651-0473', 'SINPE Móvil', 'Completada', 9100025),
        (73, 50, 919, '0', 'SINPE Móvil', 'Completada', NULL),
        (74, 49, 721, '4-0213-0764', 'Tarjeta', 'Completada', 9100026),
        (75, 47, 770, '0', 'SINPE Móvil', 'Completada', NULL),
        (76, 45, 489, '1-1450-0321', 'SINPE Móvil', 'Completada', NULL),
        (77, 43, 529, '0', 'Efectivo', 'Completada', NULL),
        (78, 41, 480, '0', 'SINPE Móvil', 'Completada', NULL),
        (79, 39, 807, '0', 'SINPE Móvil', 'Completada', NULL),
        (80, 38, 949, '0', 'Transferencia', 'Completada', NULL),
        (81, 36, 944, '0', 'Efectivo', 'Completada', NULL),
        (82, 34, 705, '1-0987-0345', 'Efectivo', 'Completada', NULL),
        (83, 32, 989, '1-1325-0098', 'SINPE Móvil', 'Completada', NULL),
        (84, 30, 778, '7-0182-0356', 'Efectivo', 'Anulada', NULL),
        (85, 29, 962, '1-0987-0345', 'Tarjeta', 'Completada', 9100027),
        (86, 27, 807, '6-0274-0839', 'SINPE Móvil', 'Completada', NULL),
        (87, 25, 748, '1-0812-0764', 'Tarjeta', 'Completada', NULL),
        (88, 23, 756, '3-0377-0582', 'Efectivo', 'Completada', 9100028),
        (89, 21, 624, '0', 'Efectivo', 'Completada', NULL),
        (90, 20, 962, '3-0377-0582', 'SINPE Móvil', 'Completada', 9100029),
        (91, 18, 896, '0', 'SINPE Móvil', 'Completada', NULL),
        (92, 16, 947, '1-1450-0321', 'Efectivo', 'Completada', NULL),
        (93, 14, 632, '1-0987-0345', 'Tarjeta', 'Completada', NULL),
        (94, 12, 769, '1-1450-0321', 'Tarjeta', 'Completada', 9100030),
        (95, 11, 722, '2-0651-0473', 'Efectivo', 'Completada', 9100031),
        (96, 9, 1067, '1-0987-0345', 'Tarjeta', 'Completada', NULL),
        (97, 7, 1131, '4-0287-0915', 'SINPE Móvil', 'Completada', 9100032),
        (98, 5, 518, '4-0287-0915', 'Efectivo', 'Completada', NULL),
        (99, 3, 952, '0', 'Tarjeta', 'Completada', NULL),
        (100, 1, 671, '2-0651-0473', 'Efectivo', 'Completada', 9100033);

        -- Lineas de venta: num de venta, medicamento, lote (el que vence primero con existencias), cantidad, descuento
        DECLARE @plan_linea TABLE (id INT IDENTITY(1,1) PRIMARY KEY, num_venta INT, codigo_barras VARCHAR(50),
                                   numero_lote VARCHAR(50), cantidad INT, descuento DECIMAL(10,2));
        INSERT INTO @plan_linea (num_venta, codigo_barras, numero_lote, cantidad, descuento) VALUES
        (1, '7449900000110', 'SRO279-2603', 1, 0.00),
        (1, '7449900000219', 'CLT1-2603', 3, 0.00),
        (2, '7449900000196', 'AMB030-2604', 2, 0.00),
        (3, '7449900000158', 'MET850-2604', 1, 0.00),
        (3, '7449900000059', 'NAP550-2602', 1, 0.00),
        (4, '7449900000110', 'SRO279-2603', 1, 0.00),
        (5, '7449900000042', 'IBS100-2603', 1, 0.00),
        (5, '7449900000073', 'AZT500-2603', 2, 0.00),
        (6, '7449900000028', 'ACJ120-2603', 1, 0.00),
        (7, '7449900000134', 'LOS050-2604', 1, 0.00),
        (7, '7449900000035', 'IBU400-2604', 2, 185.00),
        (8, '7449900000110', 'SRO279-2603', 1, 0.00),
        (9, '7449900000042', 'IBS100-2603', 1, 0.00),
        (10, '7449900000035', 'IBU400-2604', 2, 0.00),
        (10, '7449900000219', 'CLT1-2603', 1, 115.00),
        (11, '7449900000103', 'OME020-2604', 1, 0.00),
        (11, '7449900000059', 'NAP550-2602', 2, 0.00),
        (12, '7449900000141', 'ENA020-2603', 2, 0.00),
        (13, '7449900000127', 'LOP002-2509', 2, 0.00),
        (14, '7449900000042', 'IBS100-2603', 3, 0.00),
        (14, '7449900000066', 'AMX500-2604', 3, 0.00),
        (14, '7449900000110', 'SRO279-2603', 1, 0.00),
        (15, '7449900000110', 'SRO279-2603', 2, 0.00),
        (15, '7449900000103', 'OME020-2604', 1, 0.00),
        (16, '7449900000011', 'ACT500-2604', 1, 0.00),
        (17, '7449900000141', 'ENA020-2603', 2, 0.00),
        (18, '7449900000165', 'VTC1G-2605', 1, 0.00),
        (19, '7449900000011', 'ACT500-2604', 2, 0.00),
        (20, '7449900000134', 'LOS050-2604', 2, 0.00),
        (20, '7449900000035', 'IBU400-2604', 2, 0.00),
        (21, '7449900000011', 'ACT500-2604', 1, 0.00),
        (22, '7449900000059', 'NAP550-2602', 2, 0.00),
        (23, '7449900000165', 'VTC1G-2605', 1, 0.00),
        (23, '7449900000189', 'SAL100-2603', 1, 0.00),
        (24, '7449900000035', 'IBU400-2604', 1, 0.00),
        (24, '7449900000066', 'AMX500-2604', 3, 0.00),
        (25, '7449900000196', 'AMB030-2604', 1, 0.00),
        (25, '7449900000165', 'VTC1G-2605', 2, 0.00),
        (26, '7449900000110', 'SRO279-2603', 2, 0.00),
        (27, '7449900000011', 'ACT500-2604', 1, 0.00),
        (27, '7449900000103', 'OME020-2604', 1, 0.00),
        (28, '7449900000059', 'NAP550-2602', 1, 145.00),
        (29, '7449900000042', 'IBS100-2603', 1, 0.00),
        (30, '7449900000011', 'ACT500-2604', 2, 0.00),
        (30, '7449900000219', 'CLT1-2603', 3, 0.00),
        (31, '7449900000066', 'AMX500-2604', 1, 240.00),
        (32, '7449900000103', 'OME020-2604', 3, 0.00),
        (32, '7449900000059', 'NAP550-2602', 2, 0.00),
        (32, '7449900000042', 'IBS100-2603', 1, 170.00),
        (33, '7449900000134', 'LOS050-2604', 1, 0.00),
        (34, '7449900000158', 'MET850-2604', 1, 0.00),
        (34, '7449900000028', 'ACJ120-2603', 2, 0.00),
        (35, '7449900000011', 'ACT500-2604', 2, 0.00),
        (35, '7449900000189', 'SAL100-2603', 2, 0.00),
        (36, '7449900000066', 'AMX500-2604', 1, 240.00),
        (36, '7449900000097', 'CET005-2510', 2, 0.00),
        (37, '7449900000110', 'SRO279-2603', 1, 0.00),
        (37, '7449900000011', 'ACT500-2604', 2, 0.00),
        (38, '7449900000134', 'LOS050-2604', 2, 520.00),
        (38, '7449900000080', 'LOR010-2604', 1, 0.00),
        (39, '7449900000059', 'NAP550-2602', 1, 145.00),
        (39, '7449900000097', 'CET005-2510', 1, 0.00),
        (39, '7449900000172', 'CXB-2604', 2, 0.00),
        (40, '7449900000042', 'IBS100-2603', 2, 0.00),
        (40, '7449900000141', 'ENA020-2603', 1, 0.00),
        (41, '7449900000196', 'AMB030-2604', 1, 0.00),
        (41, '7449900000103', 'OME020-2604', 2, 0.00),
        (42, '7449900000011', 'ACT500-2604', 1, 0.00),
        (43, '7449900000158', 'MET850-2604', 1, 0.00),
        (43, '7449900000103', 'OME020-2604', 3, 0.00),
        (44, '7449900000059', 'NAP550-2602', 2, 0.00),
        (45, '7449900000028', 'ACJ120-2603', 2, 0.00),
        (46, '7449900000066', 'AMX500-2604', 3, 0.00),
        (47, '7449900000066', 'AMX500-2604', 1, 0.00),
        (48, '7449900000042', 'IBS100-2603', 2, 0.00),
        (49, '7449900000080', 'LOR010-2604', 2, 0.00),
        (50, '7449900000165', 'VTC1G-2605', 1, 0.00),
        (50, '7449900000011', 'ACT500-2604', 1, 0.00),
        (51, '7449900000202', 'HDC1-2603', 2, 0.00),
        (52, '7449900000035', 'IBU400-2604', 1, 0.00),
        (53, '7449900000103', 'OME020-2604', 2, 0.00),
        (53, '7449900000080', 'LOR010-2604', 1, 0.00),
        (53, '7449900000028', 'ACJ120-2603', 2, 265.00),
        (54, '7449900000196', 'AMB030-2604', 2, 0.00),
        (55, '7449900000042', 'IBS100-2603', 2, 0.00),
        (55, '7449900000110', 'SRO279-2603', 1, 0.00),
        (56, '7449900000134', 'LOS050-2604', 1, 0.00),
        (57, '7449900000028', 'ACJ120-2603', 1, 0.00),
        (58, '7449900000165', 'VTC1G-2605', 2, 0.00),
        (58, '7449900000011', 'ACT500-2604', 1, 0.00),
        (59, '7449900000127', 'LOP002-2509', 1, 0.00),
        (59, '7449900000035', 'IBU400-2604', 3, 278.00),
        (60, '7449900000035', 'IBU400-2604', 1, 0.00),
        (60, '7449900000011', 'ACT500-2604', 1, 0.00),
        (60, '7449900000158', 'MET850-2604', 1, 0.00),
        (61, '7449900000059', 'NAP550-2602', 1, 0.00),
        (62, '7449900000011', 'ACT500-2604', 1, 0.00),
        (62, '7449900000073', 'AZT500-2603', 1, 0.00),
        (63, '7449900000134', 'LOS050-2604', 1, 0.00),
        (64, '7449900000011', 'ACT500-2604', 2, 0.00),
        (65, '7449900000097', 'CET005-2510', 2, 0.00),
        (66, '7449900000011', 'ACT500-2604', 1, 0.00),
        (66, '7449900000042', 'IBS100-2603', 3, 510.00),
        (67, '7449900000011', 'ACT500-2604', 1, 0.00),
        (67, '7449900000066', 'AMX500-2604', 1, 0.00),
        (67, '7449900000103', 'OME020-2604', 2, 0.00),
        (68, '7449900000196', 'AMB030-2604', 1, 0.00),
        (68, '7449900000097', 'CET005-2510', 1, 0.00),
        (69, '7449900000097', 'CET005-2510', 1, 0.00),
        (70, '7449900000035', 'IBU400-2604', 1, 93.00),
        (70, '7449900000042', 'IBS100-2603', 1, 0.00),
        (71, '7449900000028', 'ACJ120-2603', 1, 0.00),
        (72, '7449900000110', 'SRO279-2603', 1, 0.00),
        (72, '7449900000141', 'ENA020-2603', 2, 0.00),
        (73, '7449900000219', 'CLT1-2603', 2, 0.00),
        (73, '7449900000097', 'CET005-2510', 1, 0.00),
        (74, '7449900000189', 'SAL100-2603', 2, 0.00),
        (75, '7449900000165', 'VTC1G-2605', 2, 0.00),
        (76, '7449900000103', 'OME020-2604', 1, 0.00),
        (77, '7449900000127', 'LOP002-2509', 1, 0.00),
        (78, '7449900000172', 'CXB-2604', 2, 0.00),
        (78, '7449900000028', 'ACJ120-2603', 2, 0.00),
        (78, '7449900000110', 'SRO279-2603', 1, 0.00),
        (79, '7449900000042', 'IBS100-2603', 1, 0.00),
        (79, '7449900000080', 'LOR010-2604', 2, 0.00),
        (79, '7449900000097', 'CET005-2510', 1, 0.00),
        (80, '7449900000165', 'VTC1G-2605', 3, 0.00),
        (81, '7449900000103', 'OME020-2604', 1, 0.00),
        (81, '7449900000028', 'ACJ120-2603', 1, 0.00),
        (82, '7449900000110', 'SRO279-2603', 2, 0.00),
        (83, '7449900000219', 'CLT1-2603', 1, 0.00),
        (84, '7449900000059', 'NAP550-2602', 1, 0.00),
        (85, '7449900000103', 'OME020-2604', 1, 0.00),
        (85, '7449900000066', 'AMX500-2604', 3, 0.00),
        (85, '7449900000035', 'IBU400-2604', 3, 0.00),
        (86, '7449900000110', 'SRO279-2603', 2, 0.00),
        (86, '7449900000028', 'ACJ120-2603', 2, 0.00),
        (87, '7449900000103', 'OME020-2604', 1, 0.00),
        (88, '7449900000141', 'ENA020-2603', 1, 0.00),
        (89, '7449900000080', 'LOR010-2604', 2, 0.00),
        (89, '7449900000042', 'IBS100-2603', 1, 0.00),
        (90, '7449900000059', 'NAP550-2602', 2, 0.00),
        (90, '7449900000073', 'AZT500-2603', 2, 0.00),
        (90, '7449900000103', 'OME020-2604', 3, 0.00),
        (91, '7449900000011', 'ACT500-2604', 1, 0.00),
        (91, '7449900000097', 'CET005-2510', 3, 585.00),
        (91, '7449900000110', 'SRO279-2603', 2, 0.00),
        (92, '7449900000110', 'SRO279-2603', 1, 0.00),
        (92, '7449900000219', 'CLT1-2603', 2, 0.00),
        (92, '7449900000011', 'ACT500-2604', 2, 0.00),
        (93, '7449900000011', 'ACT500-2604', 2, 0.00),
        (94, '7449900000158', 'MET850-2604', 1, 0.00),
        (94, '7449900000059', 'NAP550-2602', 1, 0.00),
        (95, '7449900000189', 'SAL100-2603', 1, 0.00),
        (95, '7449900000028', 'ACJ120-2603', 1, 133.00),
        (96, '7449900000097', 'CET005-2510', 2, 0.00),
        (97, '7449900000035', 'IBU400-2604', 3, 278.00),
        (97, '7449900000134', 'LOS050-2604', 1, 0.00),
        (98, '7449900000059', 'NAP550-2602', 1, 0.00),
        (98, '7449900000042', 'IBS100-2609', 2, 0.00),
        (99, '7449900000028', 'ACJ120-2603', 1, 0.00),
        (100, '7449900000066', 'AMX500-2604', 1, 0.00),
        (100, '7449900000028', 'ACJ120-2603', 2, 0.00),
        (100, '7449900000011', 'ACT500-2604', 2, 0.00);

        /* --- 6.1 Validaciones previas: todo lo referenciado debe existir --- */
        IF EXISTS (SELECT 1 FROM @plan_compra pc WHERE NOT EXISTS (SELECT 1 FROM dbo.proveedor p WHERE p.identificacion = pc.proveedor))
            THROW 50101, 'Falta un proveedor de las compras de prueba.', 1;
        IF EXISTS (SELECT 1 FROM @plan_lote pl WHERE NOT EXISTS (SELECT 1 FROM dbo.medicamento m WHERE m.codigo_barras = pl.codigo_barras))
            THROW 50102, 'Falta un medicamento de los lotes de prueba.', 1;
        IF EXISTS (SELECT 1 FROM @plan_venta pv WHERE NOT EXISTS (SELECT 1 FROM dbo.cliente c WHERE c.identificacion = pv.cliente))
            THROW 50103, 'Falta un cliente de las ventas de prueba.', 1;
        IF EXISTS (SELECT 1 FROM @plan_receta pr INNER JOIN dbo.receta r ON r.numero_receta = pr.numero_receta)
            THROW 50104, 'Ya existen recetas con los numeros reservados para los datos de prueba (9100001-9100035).', 1;

        /* --- 6.2 Compras con sus lotes --- */
        DECLARE @map_compra TABLE (num INT PRIMARY KEY, id_compra INT, fecha DATETIME);
        DECLARE @c INT = (SELECT MIN(num) FROM @plan_compra),
                @l INT, @id_compra INT, @id_lote INT, @id_detalle_lote INT, @fecha_compra DATETIME;

        WHILE @c IS NOT NULL
        BEGIN
            SET @fecha_compra = (SELECT DATEADD(HOUR, 9, CAST(DATEADD(DAY, -dias, @hoy) AS DATETIME)) FROM @plan_compra WHERE num = @c);

            -- Encabezado: total = suma de (cantidad x costo) + IVA de cada linea
            INSERT INTO dbo.compra (id_proveedor, id_usuario, fecha_compra, numero_factura, total, impuesto)
            SELECT (SELECT MIN(p.id_proveedor) FROM dbo.proveedor p WHERE p.identificacion = pc.proveedor),
                   @id_admin, @fecha_compra, pc.factura,
                   SUM(x.subtotal + x.iva), SUM(x.iva)
            FROM @plan_compra pc
            INNER JOIN @plan_lote pl ON pl.compra = pc.num
            INNER JOIN dbo.medicamento m ON m.codigo_barras = pl.codigo_barras
            CROSS APPLY (SELECT pl.cantidad * m.precio_costo AS subtotal,
                                ROUND(pl.cantidad * m.precio_costo * m.iva_porcentaje / 100, 2) AS iva) x
            WHERE pc.num = @c
            GROUP BY pc.proveedor, pc.factura;
            SET @id_compra = SCOPE_IDENTITY();
            INSERT INTO @map_compra VALUES (@c, @id_compra, @fecha_compra);

            -- Cada linea crea su lote, su detalle de lote y su detalle de compra
            SET @l = (SELECT MIN(id) FROM @plan_lote WHERE compra = @c);
            WHILE @l IS NOT NULL
            BEGIN
                INSERT INTO dbo.lote (numero_lote, fecha_fabricacion, fecha_vencimiento, cantidad_inicial, estado)
                SELECT numero_lote, DATEADD(DAY, -fab_dias, @hoy), DATEADD(DAY, venc_dias, @hoy), cantidad, 'Activo'
                FROM @plan_lote WHERE id = @l;
                SET @id_lote = SCOPE_IDENTITY();

                INSERT INTO dbo.detalle_lote (id_medicamento, id_lote, numero_lote, fecha_fabricacion, fecha_vencimiento, cantidad_inicial, estado)
                SELECT m.id_medicamento, @id_lote, pl.numero_lote, DATEADD(DAY, -pl.fab_dias, @hoy), DATEADD(DAY, pl.venc_dias, @hoy), pl.cantidad, 'Activo'
                FROM @plan_lote pl INNER JOIN dbo.medicamento m ON m.codigo_barras = pl.codigo_barras
                WHERE pl.id = @l;
                SET @id_detalle_lote = SCOPE_IDENTITY();

                INSERT INTO dbo.detalle_compra (id_compra, id_medicamento, id_detalle_lote, cantidad, costo_unidad, subtotal, total)
                SELECT @id_compra, m.id_medicamento, @id_detalle_lote, pl.cantidad, m.precio_costo,
                       pl.cantidad * m.precio_costo,
                       pl.cantidad * m.precio_costo + ROUND(pl.cantidad * m.precio_costo * m.iva_porcentaje / 100, 2)
                FROM @plan_lote pl INNER JOIN dbo.medicamento m ON m.codigo_barras = pl.codigo_barras
                WHERE pl.id = @l;

                SET @l = (SELECT MIN(id) FROM @plan_lote WHERE compra = @c AND id > @l);
            END

            SET @c = (SELECT MIN(num) FROM @plan_compra WHERE num > @c);
        END
        DECLARE @n_compras INT = (SELECT COUNT(*) FROM @map_compra), @n_lotes INT = (SELECT COUNT(*) FROM @plan_lote);
        PRINT CONCAT('Compras insertadas: ', @n_compras, ' | lotes: ', @n_lotes);

        /* --- 6.3 Recetas --- */
        INSERT INTO dbo.receta (id_cliente, id_medicamento, numero_receta, fecha_emision, fecha_vencimiento,
                                indicaciones, duracion, diagnostico, observaciones, dosis)
        SELECT (SELECT MIN(c.id_cliente) FROM dbo.cliente c WHERE c.identificacion = pr.cliente),
               m.id_medicamento, pr.numero_receta,
               DATEADD(DAY, -pr.emision_dias, @hoy),
               DATEADD(DAY, pr.vigencia - pr.emision_dias, @hoy),
               i.indicaciones, i.duracion, i.diagnostico, NULL, i.dosis
        FROM @plan_receta pr
        INNER JOIN dbo.medicamento m ON m.codigo_barras = pr.codigo_barras
        LEFT  JOIN @indicacion i     ON i.codigo_barras = pr.codigo_barras;
        PRINT CONCAT('Recetas insertadas: ', @@ROWCOUNT);

        /* --- 6.4 Ventas ---
           Mismo calculo que venta_nueva.php:
             linea.subtotal = cantidad x precio
             linea.total    = (subtotal - descuento) + IVA
             venta.total    = subtotal - descuento + impuesto */
        DECLARE @linea_calc TABLE (id INT PRIMARY KEY, num_venta INT, id_detalle_lote INT, cantidad INT,
                                   precio DECIMAL(10,2), descuento DECIMAL(10,2), subtotal DECIMAL(10,2), iva DECIMAL(10,2));
        INSERT INTO @linea_calc
        SELECT pl.id, pl.num_venta, dl.id_detalle_lote, pl.cantidad, m.precio_venta, pl.descuento,
               pl.cantidad * m.precio_venta,
               ROUND((pl.cantidad * m.precio_venta - pl.descuento) * m.iva_porcentaje / 100, 2)
        FROM @plan_linea pl
        INNER JOIN dbo.medicamento m   ON m.codigo_barras = pl.codigo_barras
        INNER JOIN dbo.detalle_lote dl ON dl.id_medicamento = m.id_medicamento AND dl.numero_lote = pl.numero_lote;

        IF (SELECT COUNT(*) FROM @linea_calc) <> (SELECT COUNT(*) FROM @plan_linea)
            THROW 50105, 'Alguna linea de venta no encontro su lote (o el lote esta repetido).', 1;

        DECLARE @map_venta TABLE (num INT PRIMARY KEY, id_venta INT, fecha DATETIME, estado VARCHAR(30));
        DECLARE @v INT = (SELECT MIN(num) FROM @plan_venta), @id_venta INT, @fecha_venta DATETIME;

        WHILE @v IS NOT NULL
        BEGIN
            SET @fecha_venta = (SELECT DATEADD(MINUTE, minuto, CAST(DATEADD(DAY, -dias, @hoy) AS DATETIME)) FROM @plan_venta WHERE num = @v);

            INSERT INTO dbo.venta (id_cliente, id_receta, fecha_venta, subtotal, impuesto, descuento, total, metodo_pago, estado)
            SELECT (SELECT MIN(c.id_cliente) FROM dbo.cliente c WHERE c.identificacion = pv.cliente),
                   (SELECT r.id_receta FROM dbo.receta r WHERE r.numero_receta = pv.numero_receta),
                   @fecha_venta,
                   t.subtotal, t.iva, t.descuento, t.subtotal - t.descuento + t.iva,
                   pv.metodo_pago, pv.estado
            FROM @plan_venta pv
            CROSS APPLY (SELECT SUM(lc.subtotal) AS subtotal, SUM(lc.descuento) AS descuento, SUM(lc.iva) AS iva
                         FROM @linea_calc lc WHERE lc.num_venta = pv.num) t
            WHERE pv.num = @v;
            SET @id_venta = SCOPE_IDENTITY();
            INSERT INTO @map_venta SELECT @v, @id_venta, @fecha_venta, estado FROM @plan_venta WHERE num = @v;

            INSERT INTO dbo.detalle_venta (id_venta, id_detalle_lote, cantidad, precio_unidad, descuento, subtotal, total)
            SELECT @id_venta, lc.id_detalle_lote, lc.cantidad, lc.precio, lc.descuento, lc.subtotal,
                   lc.subtotal - lc.descuento + lc.iva
            FROM @linea_calc lc
            WHERE lc.num_venta = @v
            ORDER BY lc.id;

            SET @v = (SELECT MIN(num) FROM @plan_venta WHERE num > @v);
        END
        DECLARE @n_ventas INT = (SELECT COUNT(*) FROM @map_venta), @n_lineas INT = (SELECT COUNT(*) FROM @linea_calc);
        PRINT CONCAT('Ventas insertadas: ', @n_ventas, ' | lineas: ', @n_lineas);

        /* --- 6.5 Auditoria (igual que la aplicacion: CREAR al registrar, ANULAR al anular) --- */
        INSERT INTO dbo.auditoria (id_usuario, id_registro_afectado, tabla_afectada, accion, fecha_hora)
        SELECT @id_admin, id_compra, 'compra', 'CREAR', fecha FROM @map_compra
        UNION ALL
        SELECT @id_admin, id_venta, 'venta', 'CREAR', fecha FROM @map_venta
        UNION ALL
        SELECT @id_admin, id_venta, 'venta', 'ANULAR', DATEADD(MINUTE, 30, fecha) FROM @map_venta WHERE estado = 'Anulada';
        PRINT CONCAT('Registros de auditoria insertados: ', @@ROWCOUNT);

        /* --- 6.6 Validaciones finales: si algo no cuadra se revierte todo --- */

        -- Ningun lote puede quedar con stock negativo (stock = inicial - vendido en ventas no anuladas)
        IF EXISTS (
            SELECT 1 FROM dbo.detalle_lote dl
            WHERE dl.cantidad_inicial < (SELECT ISNULL(SUM(dv.cantidad), 0)
                                         FROM dbo.detalle_venta dv INNER JOIN dbo.venta v ON v.id_venta = dv.id_venta
                                         WHERE dv.id_detalle_lote = dl.id_detalle_lote AND v.estado <> 'Anulada')
        )
            THROW 50106, 'Hay lotes con stock negativo.', 1;

        -- Cada venta usa un lote ya comprado y no vencido en la fecha de la venta
        IF EXISTS (
            SELECT 1
            FROM @map_venta mv
            INNER JOIN dbo.detalle_venta dv   ON dv.id_venta = mv.id_venta
            INNER JOIN dbo.detalle_lote dl    ON dl.id_detalle_lote = dv.id_detalle_lote
            INNER JOIN dbo.detalle_compra dc  ON dc.id_detalle_lote = dl.id_detalle_lote
            INNER JOIN dbo.compra c           ON c.id_compra = dc.id_compra
            WHERE mv.fecha < c.fecha_compra OR CAST(mv.fecha AS DATE) > dl.fecha_vencimiento
        )
            THROW 50107, 'Hay ventas de lotes no comprados aun o vencidos en la fecha de la venta.', 1;

        -- Los medicamentos con receta se venden con una receta vigente del mismo cliente y medicamento
        IF EXISTS (
            SELECT 1
            FROM @map_venta mv
            INNER JOIN dbo.venta v          ON v.id_venta = mv.id_venta
            INNER JOIN dbo.detalle_venta dv ON dv.id_venta = v.id_venta
            INNER JOIN dbo.detalle_lote dl  ON dl.id_detalle_lote = dv.id_detalle_lote
            INNER JOIN dbo.medicamento m    ON m.id_medicamento = dl.id_medicamento
            LEFT  JOIN dbo.receta r         ON r.id_receta = v.id_receta
            WHERE m.requiere_receta = 1
              AND (r.id_receta IS NULL OR r.id_cliente <> v.id_cliente OR r.id_medicamento <> m.id_medicamento
                   OR CAST(v.fecha_venta AS DATE) NOT BETWEEN r.fecha_emision AND r.fecha_vencimiento)
        )
            THROW 50108, 'Hay ventas de medicamentos con receta sin una receta valida.', 1;
    END

    COMMIT TRANSACTION;
    PRINT 'Proceso terminado sin errores (COMMIT).';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    PRINT 'ERROR: no se cargo ningun dato (se hizo ROLLBACK).';
    THROW;
END CATCH
GO

/* =====================================================================
   VERIFICACION (solo lectura). Se puede ejecutar sola en cualquier momento.
   ===================================================================== */

-- 1. Cantidad de registros en las 14 tablas
SELECT 'categoria' AS tabla, COUNT(*) AS registros FROM dbo.categoria
UNION ALL SELECT 'presentacion',   COUNT(*) FROM dbo.presentacion
UNION ALL SELECT 'proveedor',      COUNT(*) FROM dbo.proveedor
UNION ALL SELECT 'usuario',        COUNT(*) FROM dbo.usuario
UNION ALL SELECT 'cliente',        COUNT(*) FROM dbo.cliente
UNION ALL SELECT 'lote',           COUNT(*) FROM dbo.lote
UNION ALL SELECT 'medicamento',    COUNT(*) FROM dbo.medicamento
UNION ALL SELECT 'detalle_lote',   COUNT(*) FROM dbo.detalle_lote
UNION ALL SELECT 'receta',         COUNT(*) FROM dbo.receta
UNION ALL SELECT 'venta',          COUNT(*) FROM dbo.venta
UNION ALL SELECT 'detalle_venta',  COUNT(*) FROM dbo.detalle_venta
UNION ALL SELECT 'compra',         COUNT(*) FROM dbo.compra
UNION ALL SELECT 'detalle_compra', COUNT(*) FROM dbo.detalle_compra
UNION ALL SELECT 'auditoria',      COUNT(*) FROM dbo.auditoria;

-- 2. Comprobaciones de consistencia (todas deben dar 0 problemas)
SELECT 'Ventas cuyo total no coincide con sus lineas' AS comprobacion, COUNT(*) AS problemas
FROM dbo.venta v
CROSS APPLY (SELECT SUM(subtotal) AS s, SUM(descuento) AS d, SUM(total) AS t FROM dbo.detalle_venta dv WHERE dv.id_venta = v.id_venta) x
WHERE v.subtotal <> x.s OR v.descuento <> x.d OR v.total <> x.t
UNION ALL
SELECT 'Compras cuyo total no coincide con sus lineas', COUNT(*)
FROM dbo.compra c
CROSS APPLY (SELECT SUM(total) AS t FROM dbo.detalle_compra dc WHERE dc.id_compra = c.id_compra) x
WHERE c.total <> x.t
UNION ALL
SELECT 'Lotes con stock negativo', COUNT(*)
FROM dbo.detalle_lote dl
WHERE dl.cantidad_inicial < (SELECT ISNULL(SUM(dv.cantidad), 0) FROM dbo.detalle_venta dv
                             INNER JOIN dbo.venta v ON v.id_venta = dv.id_venta
                             WHERE dv.id_detalle_lote = dl.id_detalle_lote AND v.estado <> 'Anulada')
UNION ALL
SELECT 'Lotes cuya cantidad no coincide con lo comprado', COUNT(*)
FROM dbo.detalle_lote dl
INNER JOIN dbo.lote l ON l.id_lote = dl.id_lote
CROSS APPLY (SELECT SUM(dc.cantidad) AS comprado FROM dbo.detalle_compra dc WHERE dc.id_detalle_lote = dl.id_detalle_lote) x
WHERE dl.cantidad_inicial <> l.cantidad_inicial OR dl.cantidad_inicial <> x.comprado
UNION ALL
SELECT 'Ventas con receta sin receta valida', COUNT(*)
FROM dbo.venta v
INNER JOIN dbo.detalle_venta dv ON dv.id_venta = v.id_venta
INNER JOIN dbo.detalle_lote dl  ON dl.id_detalle_lote = dv.id_detalle_lote
INNER JOIN dbo.medicamento m    ON m.id_medicamento = dl.id_medicamento
LEFT  JOIN dbo.receta r         ON r.id_receta = v.id_receta
WHERE m.requiere_receta = 1
  AND (r.id_receta IS NULL OR r.id_cliente <> v.id_cliente OR r.id_medicamento <> m.id_medicamento);

-- 3. Inventario por medicamento: comprado, vendido (sin anuladas) y disponible
SELECT m.nombre, m.concentracion, p.nombre AS presentacion,
       SUM(dl.cantidad_inicial) AS comprado,
       SUM(dl.cantidad_inicial - st.disponible) AS vendido,
       SUM(st.disponible) AS disponible,
       SUM(CASE WHEN dl.fecha_vencimiento < CAST(GETDATE() AS DATE) THEN st.disponible ELSE 0 END) AS vencido
FROM dbo.medicamento m
LEFT JOIN dbo.presentacion p  ON p.id_presentacion = m.id_presentacion
INNER JOIN dbo.detalle_lote dl ON dl.id_medicamento = m.id_medicamento
OUTER APPLY (SELECT dl.cantidad_inicial - ISNULL(SUM(dv.cantidad), 0) AS disponible
             FROM dbo.detalle_venta dv INNER JOIN dbo.venta v ON v.id_venta = dv.id_venta
             WHERE dv.id_detalle_lote = dl.id_detalle_lote AND v.estado <> 'Anulada') st
GROUP BY m.id_medicamento, m.nombre, m.concentracion, p.nombre
ORDER BY vendido DESC, m.nombre;

-- 4. Lotes que generan alertas en la aplicacion
SELECT dl.numero_lote, m.nombre, dl.fecha_vencimiento, st.disponible,
       CASE WHEN dl.fecha_vencimiento < CAST(GETDATE() AS DATE) AND st.disponible > 0 THEN 'Vencido con existencias'
            WHEN st.disponible = 0 THEN 'Agotado'
            ELSE 'Por vencer (90 dias)' END AS alerta
FROM dbo.detalle_lote dl
INNER JOIN dbo.medicamento m ON m.id_medicamento = dl.id_medicamento
OUTER APPLY (SELECT dl.cantidad_inicial - ISNULL(SUM(dv.cantidad), 0) AS disponible
             FROM dbo.detalle_venta dv INNER JOIN dbo.venta v ON v.id_venta = dv.id_venta
             WHERE dv.id_detalle_lote = dl.id_detalle_lote AND v.estado <> 'Anulada') st
WHERE (dl.fecha_vencimiento < CAST(GETDATE() AS DATE) AND st.disponible > 0)
   OR st.disponible = 0
   OR (dl.fecha_vencimiento <= DATEADD(DAY, 90, CAST(GETDATE() AS DATE)) AND st.disponible > 0)
ORDER BY dl.fecha_vencimiento;

-- 5. Ventas por mes y estado (base para el Data Warehouse)
SELECT FORMAT(v.fecha_venta, 'yyyy-MM') AS mes, v.estado, COUNT(*) AS ventas, SUM(v.total) AS total
FROM dbo.venta v
GROUP BY FORMAT(v.fecha_venta, 'yyyy-MM'), v.estado
ORDER BY mes, v.estado;

-- 6. Reporte con cursor: todo el periodo de los datos de prueba y el mes actual
DECLARE @inicio DATE = DATEADD(DAY, -200, CAST(GETDATE() AS DATE));
DECLARE @hoy DATE = CAST(GETDATE() AS DATE);
DECLARE @inicio_mes DATE = DATEFROMPARTS(YEAR(@hoy), MONTH(@hoy), 1);

EXEC dbo.sp_reporte_ventas_medicamento @desde = @inicio, @hasta = @hoy;
EXEC dbo.sp_reporte_ventas_medicamento @desde = @inicio_mes, @hasta = @hoy;
GO

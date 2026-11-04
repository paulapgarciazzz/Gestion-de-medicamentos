/* =====================================================================
   Procedimientos almacenados del negocio (base farmacia)
   Motor: SQL Server 2016+ (T-SQL, usa OPENJSON y CREATE OR ALTER)

     dbo.sp_registrar_venta   -> venta + detalle_venta + auditoria
     dbo.sp_anular_venta      -> marca la venta como Anulada + auditoria
     dbo.sp_registrar_compra  -> compra + detalle_compra + lote/detalle_lote + auditoria

   Los usa la aplicación (venta_nueva.php, ventas.php, compra_nueva.php).
   Cada uno trabaja dentro de una TRANSACCION explicita:
       BEGIN TRY
           ...validaciones...
           BEGIN TRANSACTION
           ...inserciones / actualizaciones...
           COMMIT TRANSACTION
       END TRY
       BEGIN CATCH
           IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION   -- se deshace TODO
           THROW                                      -- el error llega a PHP
       END CATCH
   Si algo falla a mitad de camino (por ejemplo el stock de la segunda
   línea), no queda nada a medias: ni la venta ni sus líneas ni la auditoría.

   Errores de negocio: THROW con números 51000-51999. La aplicación muestra
   su mensaje tal cual al usuario; cualquier otro error se registra en el log.

   Las líneas llegan como JSON en un parámetro NVARCHAR(MAX), por ejemplo:
     venta:  [{"lote":3,"cant":2,"desc":0},{"lote":5,"cant":1,"desc":150.00}]
     compra: [{"med":1,"lote":"A-01","fab":"2026-01-10","venc":"2028-01-10","cant":50,"costo":1250.00}]

   Se puede ejecutar varias veces (CREATE OR ALTER).
   ===================================================================== */

USE farmacia;
GO

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

/* ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_registrar_venta
    @id_usuario  INT,
    @id_cliente  INT,
    @id_receta   INT = NULL,
    @metodo_pago VARCHAR(30),
    @lineas      NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;   -- cualquier error de ejecución deja la transacción para deshacer

    DECLARE @hoy DATE = CAST(GETDATE() AS DATE),
            @msg NVARCHAR(400),
            @id_venta INT,
            @med_receta INT;

    DECLARE @json TABLE (lote INT NULL, cant INT NULL, descu DECIMAL(12,2) NULL);
    DECLARE @entrada TABLE (id_detalle_lote INT NOT NULL PRIMARY KEY, cantidad INT NOT NULL, descuento DECIMAL(12,2) NOT NULL);
    DECLARE @detalle TABLE (
        id_detalle_lote   INT           NOT NULL PRIMARY KEY,
        id_medicamento    INT           NOT NULL,
        nombre            VARCHAR(100)  NOT NULL,
        estado            VARCHAR(30)   NOT NULL,
        fecha_vencimiento DATE          NOT NULL,
        requiere_receta   BIT           NOT NULL,
        cantidad          INT           NOT NULL,
        disponible        INT           NOT NULL,
        precio            DECIMAL(12,2) NOT NULL,
        iva_pct           DECIMAL(5,2)  NOT NULL,
        descuento         DECIMAL(12,2) NOT NULL,
        bruto             DECIMAL(14,2) NULL,
        iva               DECIMAL(14,2) NULL,
        total             DECIMAL(14,2) NULL
    );

    BEGIN TRY
        /* 1. Encabezado */
        IF NOT EXISTS (SELECT 1 FROM dbo.usuario WHERE id_usuario = @id_usuario AND estado = 1)
            THROW 51001, N'El usuario no es válido o está inactivo.', 1;
        IF NOT EXISTS (SELECT 1 FROM dbo.cliente WHERE id_cliente = @id_cliente)
            THROW 51002, N'Seleccione un cliente válido.', 1;
        IF @metodo_pago IS NULL OR @metodo_pago NOT IN ('Efectivo', 'Tarjeta', 'SINPE Móvil', 'Transferencia')
            THROW 51003, N'Seleccione un método de pago válido.', 1;
        IF ISJSON(@lineas) <> 1 OR LEFT(LTRIM(@lineas), 1) <> N'['
            THROW 51004, N'El detalle de la venta no es válido.', 1;

        /* 2. Líneas: se leen del JSON y se agrupan por lote */
        INSERT INTO @json (lote, cant, descu)
        SELECT TRY_CONVERT(INT, lote), TRY_CONVERT(INT, cant), TRY_CONVERT(DECIMAL(12,2), descu)
        FROM OPENJSON(@lineas) WITH (lote NVARCHAR(20) '$.lote', cant NVARCHAR(20) '$.cant', descu NVARCHAR(30) '$.desc');

        IF NOT EXISTS (SELECT 1 FROM @json)
            THROW 51005, N'Agregue al menos un medicamento a la venta.', 1;
        IF (SELECT COUNT(*) FROM @json) > 60
            THROW 51006, N'Demasiadas líneas en una sola venta.', 1;
        IF EXISTS (SELECT 1 FROM @json WHERE lote IS NULL OR lote < 1 OR cant IS NULL OR cant < 1 OR descu IS NULL OR descu < 0)
            THROW 51007, N'Hay una línea con datos no válidos.', 1;

        INSERT INTO @entrada (id_detalle_lote, cantidad, descuento)
        SELECT lote, SUM(cant), SUM(ROUND(descu, 2)) FROM @json GROUP BY lote;

        BEGIN TRANSACTION;

        /* 3. Bloquear los lotes hasta el COMMIT (UPDLOCK + HOLDLOCK):
              dos cajeros no pueden vender las mismas unidades a la vez */
        IF (SELECT COUNT(*) FROM dbo.detalle_lote WITH (UPDLOCK, HOLDLOCK)
            WHERE id_detalle_lote IN (SELECT id_detalle_lote FROM @entrada)) <> (SELECT COUNT(*) FROM @entrada)
            THROW 51010, N'Uno de los lotes de la venta ya no existe.', 1;

        /* 4. Precio, IVA y stock se toman de la base, nunca del navegador */
        INSERT INTO @detalle (id_detalle_lote, id_medicamento, nombre, estado, fecha_vencimiento, requiere_receta,
                              cantidad, disponible, precio, iva_pct, descuento)
        SELECT dl.id_detalle_lote, m.id_medicamento, m.nombre, dl.estado, dl.fecha_vencimiento, m.requiere_receta,
               e.cantidad,
               dl.cantidad_inicial - ISNULL((SELECT SUM(dv.cantidad)
                                             FROM dbo.detalle_venta dv
                                             INNER JOIN dbo.venta v ON v.id_venta = dv.id_venta
                                             WHERE dv.id_detalle_lote = dl.id_detalle_lote AND v.estado <> 'Anulada'), 0),
               m.precio_venta, m.iva_porcentaje, e.descuento
        FROM @entrada e
        INNER JOIN dbo.detalle_lote dl ON dl.id_detalle_lote = e.id_detalle_lote
        INNER JOIN dbo.medicamento m   ON m.id_medicamento = dl.id_medicamento;

        -- subtotal = cant x precio; total = (subtotal - descuento) + IVA
        UPDATE @detalle SET bruto = ROUND(cantidad * precio, 2);
        UPDATE @detalle SET iva = ROUND((bruto - descuento) * iva_pct / 100, 2);
        UPDATE @detalle SET total = bruto - descuento + iva;

        SELECT TOP (1) @msg = CASE
                WHEN UPPER(LTRIM(RTRIM(estado))) NOT IN ('ACTIVO', 'DISPONIBLE', 'POR VENCER')
                    THEN CONCAT(N'El lote de «', nombre, N'» no está activo.')
                WHEN fecha_vencimiento < @hoy
                    THEN CONCAT(N'El lote de «', nombre, N'» está vencido.')
                WHEN cantidad > disponible
                    THEN CONCAT(N'Stock insuficiente de «', nombre, N'» (disponible: ', disponible, N').')
                ELSE CONCAT(N'El descuento de «', nombre, N'» supera el valor de la línea.') END
        FROM @detalle
        WHERE UPPER(LTRIM(RTRIM(estado))) NOT IN ('ACTIVO', 'DISPONIBLE', 'POR VENCER')
           OR fecha_vencimiento < @hoy OR cantidad > disponible OR descuento > bruto
        ORDER BY id_detalle_lote;
        IF @msg IS NOT NULL THROW 51011, @msg, 1;

        /* 5. Receta: obligatoria si hay medicamentos con receta; debe ser vigente,
              del mismo cliente y del medicamento que cubre */
        IF @id_receta IS NOT NULL OR EXISTS (SELECT 1 FROM @detalle WHERE requiere_receta = 1)
        BEGIN
            IF @id_receta IS NULL
                THROW 51020, N'La venta incluye medicamentos que requieren receta: seleccione la receta del cliente.', 1;

            SELECT @med_receta = id_medicamento
            FROM dbo.receta
            WHERE id_receta = @id_receta AND id_cliente = @id_cliente
              AND fecha_vencimiento >= @hoy AND fecha_emision <= @hoy;

            IF @med_receta IS NULL
                THROW 51021, N'La receta no pertenece a este cliente o no está vigente.', 1;
            IF EXISTS (SELECT 1 FROM @detalle WHERE requiere_receta = 1 AND id_medicamento <> @med_receta)
                THROW 51022, N'La receta seleccionada solo cubre el medicamento indicado en ella. Registre los demás medicamentos con receta en otra venta.', 1;
        END

        /* 6. Guardar venta, líneas y auditoría */
        INSERT INTO dbo.venta (id_cliente, id_receta, subtotal, impuesto, descuento, total, metodo_pago, estado)
        SELECT @id_cliente, @id_receta, SUM(bruto), SUM(iva), SUM(descuento), SUM(bruto) - SUM(descuento) + SUM(iva),
               @metodo_pago, 'Completada'
        FROM @detalle;
        SET @id_venta = SCOPE_IDENTITY();

        INSERT INTO dbo.detalle_venta (id_venta, id_detalle_lote, cantidad, precio_unidad, descuento, subtotal, total)
        SELECT @id_venta, id_detalle_lote, cantidad, precio, descuento, bruto, total FROM @detalle;

        INSERT INTO dbo.auditoria (id_usuario, id_registro_afectado, tabla_afectada, accion)
        VALUES (@id_usuario, @id_venta, 'venta', 'CREAR');

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH

    SELECT @id_venta AS id_venta;
END
GO

/* ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_anular_venta
    @id_venta   INT,
    @id_usuario INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.usuario
                       WHERE id_usuario = @id_usuario AND estado = 1 AND rol IN ('Administrador', 'Farmaceutico'))
            THROW 51030, N'Solo un Administrador o Farmacéutico activo puede anular ventas.', 1;

        BEGIN TRANSACTION;

        -- El stock se calcula con las ventas no anuladas: al anular, las unidades vuelven solas al inventario
        UPDATE dbo.venta SET estado = 'Anulada' WHERE id_venta = @id_venta AND estado <> 'Anulada';
        IF @@ROWCOUNT = 0
            THROW 51031, N'La venta no existe o ya estaba anulada.', 1;

        INSERT INTO dbo.auditoria (id_usuario, id_registro_afectado, tabla_afectada, accion)
        VALUES (@id_usuario, @id_venta, 'venta', 'ANULAR');

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END
GO

/* ===================================================================== */
CREATE OR ALTER PROCEDURE dbo.sp_registrar_compra
    @id_usuario       INT,
    @id_proveedor     INT,
    @numero_factura   INT,
    @fecha_compra     DATE,
    @actualizar_costo BIT = 1,
    @lineas           NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @hoy DATE = CAST(GETDATE() AS DATE),
            @msg NVARCHAR(400),
            @id_compra INT;

    DECLARE @c TABLE (
        pos            INT           NOT NULL PRIMARY KEY,   -- número de línea (1, 2, 3...)
        id_medicamento INT           NULL,
        nombre         VARCHAR(100)  NULL,
        iva_pct        DECIMAL(5,2)  NULL,
        numero_lote    NVARCHAR(100) NULL,
        fab            DATE          NULL,
        venc           DATE          NULL,
        cantidad       INT           NULL,
        costo          DECIMAL(12,2) NULL,
        bruto          DECIMAL(14,2) NULL,
        iva            DECIMAL(14,2) NULL
    );

    BEGIN TRY
        /* 1. Encabezado */
        IF NOT EXISTS (SELECT 1 FROM dbo.usuario
                       WHERE id_usuario = @id_usuario AND estado = 1 AND rol IN ('Administrador', 'Farmaceutico'))
            THROW 51040, N'Solo un Administrador o Farmacéutico activo puede registrar compras.', 1;
        IF NOT EXISTS (SELECT 1 FROM dbo.proveedor WHERE id_proveedor = @id_proveedor)
            THROW 51041, N'Seleccione un proveedor válido.', 1;
        IF @numero_factura IS NULL OR @numero_factura < 1
            THROW 51042, N'Ingrese un número de factura válido (solo números).', 1;
        IF @fecha_compra IS NULL OR @fecha_compra > @hoy
            THROW 51043, N'La fecha de la compra no es válida.', 1;
        IF ISJSON(@lineas) <> 1 OR LEFT(LTRIM(@lineas), 1) <> N'['
            THROW 51044, N'El detalle de la compra no es válido.', 1;

        /* 2. Líneas */
        INSERT INTO @c (pos, id_medicamento, numero_lote, fab, venc, cantidad, costo)
        SELECT CAST(j.[key] AS INT) + 1,
               TRY_CONVERT(INT, x.med),
               LTRIM(RTRIM(x.lote)),
               TRY_CONVERT(DATE, NULLIF(x.fab, N''), 23),
               TRY_CONVERT(DATE, NULLIF(x.venc, N''), 23),
               TRY_CONVERT(INT, x.cant),
               TRY_CONVERT(DECIMAL(12,2), NULLIF(x.costo, N''))
        FROM OPENJSON(@lineas) j
        CROSS APPLY OPENJSON(j.value) WITH (med NVARCHAR(20) '$.med', lote NVARCHAR(100) '$.lote',
                                            fab NVARCHAR(30) '$.fab', venc NVARCHAR(30) '$.venc',
                                            cant NVARCHAR(20) '$.cant', costo NVARCHAR(30) '$.costo') x;

        IF NOT EXISTS (SELECT 1 FROM @c)
            THROW 51045, N'Agregue al menos un medicamento a la compra.', 1;
        IF (SELECT COUNT(*) FROM @c) > 60
            THROW 51046, N'Demasiadas líneas en una sola compra.', 1;

        UPDATE c SET nombre = m.nombre, iva_pct = m.iva_porcentaje
        FROM @c c INNER JOIN dbo.medicamento m ON m.id_medicamento = c.id_medicamento;

        SELECT TOP (1) @msg = CASE
                WHEN nombre IS NULL THEN CONCAT(N'Línea ', pos, N': el medicamento no existe.')
                ELSE CONCAT(N'Línea ', pos, N' (', nombre, N'): ', CASE
                    WHEN ISNULL(numero_lote, N'') = N'' OR LEN(numero_lote) > 50 THEN N'indique el número de lote (máx. 50 caracteres).'
                    WHEN fab IS NULL OR venc IS NULL THEN N'fechas de fabricación y vencimiento no válidas.'
                    WHEN fab > @hoy   THEN N'la fecha de fabricación no puede ser futura.'
                    WHEN venc <= @hoy THEN N'el lote ya está vencido; no se puede ingresar al inventario.'
                    WHEN venc <= fab  THEN N'el vencimiento debe ser posterior a la fabricación.'
                    WHEN cantidad IS NULL OR cantidad < 1 THEN N'la cantidad debe ser al menos 1.'
                    ELSE N'costo no válido.' END) END
        FROM @c
        WHERE nombre IS NULL OR ISNULL(numero_lote, N'') = N'' OR LEN(numero_lote) > 50
           OR fab IS NULL OR venc IS NULL OR fab > @hoy OR venc <= @hoy OR venc <= fab
           OR cantidad IS NULL OR cantidad < 1 OR costo IS NULL OR costo < 0
        ORDER BY pos;
        IF @msg IS NOT NULL THROW 51047, @msg, 1;

        UPDATE @c SET bruto = ROUND(cantidad * costo, 2);
        UPDATE @c SET iva = ROUND(bruto * iva_pct / 100, 2);

        BEGIN TRANSACTION;

        /* 3. Factura repetida (con bloqueo para que dos usuarios no la registren a la vez) */
        IF EXISTS (SELECT 1 FROM dbo.compra WITH (UPDLOCK, HOLDLOCK)
                   WHERE id_proveedor = @id_proveedor AND numero_factura = @numero_factura)
            THROW 51048, N'Ya existe una compra registrada con esa factura para este proveedor.', 1;

        INSERT INTO dbo.compra (id_proveedor, id_usuario, fecha_compra, numero_factura, total, impuesto)
        SELECT @id_proveedor, @id_usuario,
               CAST(@fecha_compra AS DATETIME) + CAST(CAST(GETDATE() AS TIME) AS DATETIME),
               @numero_factura, SUM(bruto) + SUM(iva), SUM(iva)
        FROM @c;
        SET @id_compra = SCOPE_IDENTITY();

        /* 4. Cada línea crea su lote o suma unidades a un lote existente (mismas fechas) */
        DECLARE @pos INT = 1, @n INT = (SELECT MAX(pos) FROM @c),
                @med INT, @nombre VARCHAR(100), @lote NVARCHAR(100), @fab DATE, @venc DATE,
                @cant INT, @costo DECIMAL(12,2), @bruto DECIMAL(14,2), @iva DECIMAL(14,2),
                @id_dl INT, @id_lote INT, @ex_fab DATE, @ex_venc DATE, @ex_estado VARCHAR(30);

        WHILE @pos <= @n
        BEGIN
            SELECT @med = id_medicamento, @nombre = nombre, @lote = numero_lote, @fab = fab, @venc = venc,
                   @cant = cantidad, @costo = costo, @bruto = bruto, @iva = iva
            FROM @c WHERE pos = @pos;

            SET @id_dl = NULL;
            SELECT TOP (1) @id_dl = id_detalle_lote, @id_lote = id_lote, @ex_fab = fecha_fabricacion,
                           @ex_venc = fecha_vencimiento, @ex_estado = estado
            FROM dbo.detalle_lote WITH (UPDLOCK, HOLDLOCK)
            WHERE id_medicamento = @med AND numero_lote = @lote
            ORDER BY id_detalle_lote;

            IF @id_dl IS NOT NULL
            BEGIN
                IF @ex_fab <> @fab OR @ex_venc <> @venc
                BEGIN
                    SET @msg = CONCAT(N'El lote «', @lote, N'» ya existe para ese medicamento con fechas de fabricación o vencimiento diferentes. Verifique las fechas antes de registrar la compra.');
                    THROW 51049, @msg, 1;
                END
                IF UPPER(LTRIM(RTRIM(@ex_estado))) NOT IN ('ACTIVO', 'DISPONIBLE', 'POR VENCER')
                BEGIN
                    SET @msg = CONCAT(N'El lote «', @lote, N'» de «', @nombre, N'» está «', @ex_estado, N'»: no se le pueden sumar unidades. Use otro número de lote o reactívelo en Lotes.');
                    THROW 51050, @msg, 1;
                END
                UPDATE dbo.detalle_lote SET cantidad_inicial = cantidad_inicial + @cant WHERE id_detalle_lote = @id_dl;
                UPDATE dbo.lote SET cantidad_inicial = cantidad_inicial + @cant WHERE id_lote = @id_lote;
            END
            ELSE
            BEGIN
                INSERT INTO dbo.lote (numero_lote, fecha_fabricacion, fecha_vencimiento, cantidad_inicial, estado)
                VALUES (@lote, @fab, @venc, @cant, 'Activo');
                SET @id_lote = SCOPE_IDENTITY();

                INSERT INTO dbo.detalle_lote (id_medicamento, id_lote, numero_lote, fecha_fabricacion, fecha_vencimiento, cantidad_inicial, estado)
                VALUES (@med, @id_lote, @lote, @fab, @venc, @cant, 'Activo');
                SET @id_dl = SCOPE_IDENTITY();
            END

            INSERT INTO dbo.detalle_compra (id_compra, id_medicamento, id_detalle_lote, cantidad, costo_unidad, subtotal, total)
            VALUES (@id_compra, @med, @id_dl, @cant, @costo, @bruto, @bruto + @iva);

            IF @actualizar_costo = 1
                UPDATE dbo.medicamento SET precio_costo = @costo WHERE id_medicamento = @med;

            SET @pos += 1;
        END

        INSERT INTO dbo.auditoria (id_usuario, id_registro_afectado, tabla_afectada, accion)
        VALUES (@id_usuario, @id_compra, 'compra', 'CREAR');

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH

    SELECT @id_compra AS id_compra;
END
GO

PRINT 'Procedimientos del negocio listos: sp_registrar_venta, sp_anular_venta, sp_registrar_compra.';
GO

/* =====================================================================
   Data Warehouse: procedimientos del ETL (Extraer - Transformar - Cargar)

   Orden de carga (etl.sp_ejecutar_etl):
     1. dim_fecha        -> inserta los dias que falten
     2. dim_medicamento  -> MERGE: inserta nuevos, actualiza cambios (SCD tipo 1)
     3. dim_cliente      -> MERGE: inserta nuevos, actualiza cambios (SCD tipo 1)
     4. dim_metodo_pago  -> inserta los que falten
     5. fact_ventas      -> MERGE por id_detalle_venta: inserta lineas nuevas y
                            actualiza las que cambiaron (por ejemplo una venta
                            que se anulo despues de la ultima carga)

   Idempotente: ejecutarlo varias veces no duplica filas (claves naturales unicas
   + MERGE). Solo LEE la base farmacia a traves de los sinonimos del esquema origen.
   ===================================================================== */

USE FarmaciaDW;
GO

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

/* --------------------------- 1. Dimension fecha --------------------------- */
CREATE OR ALTER PROCEDURE etl.sp_cargar_dim_fecha
    @desde      DATE,
    @hasta      DATE,
    @insertadas INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    IF @desde IS NULL OR @hasta IS NULL OR @desde > @hasta
        THROW 50301, 'Rango de fechas no valido para dim_fecha.', 1;

    WITH numeros AS (
        SELECT TOP (DATEDIFF(DAY, @desde, @hasta) + 1)
               ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) - 1 AS n
        FROM sys.all_objects a CROSS JOIN sys.all_objects b
    ),
    dias AS (
        SELECT DATEADD(DAY, n, @desde) AS fecha FROM numeros
    )
    INSERT INTO dw.dim_fecha (fecha_key, fecha, anio, trimestre, mes, nombre_mes, anio_mes,
                              dia, dia_semana, nombre_dia, es_fin_semana)
    SELECT YEAR(d.fecha) * 10000 + MONTH(d.fecha) * 100 + DAY(d.fecha),
           d.fecha,
           YEAR(d.fecha),
           DATEPART(QUARTER, d.fecha),
           MONTH(d.fecha),
           CHOOSE(MONTH(d.fecha), N'Enero', N'Febrero', N'Marzo', N'Abril', N'Mayo', N'Junio',
                  N'Julio', N'Agosto', N'Septiembre', N'Octubre', N'Noviembre', N'Diciembre'),
           CONVERT(CHAR(7), d.fecha, 120),
           DAY(d.fecha),
           s.dia_semana,
           CHOOSE(s.dia_semana, N'Lunes', N'Martes', N'Miércoles', N'Jueves', N'Viernes', N'Sábado', N'Domingo'),
           IIF(s.dia_semana >= 6, 1, 0)
    FROM dias d
    -- 1900-01-01 fue lunes: asi el dia de la semana no depende de SET DATEFIRST ni del idioma
    CROSS APPLY (SELECT DATEDIFF(DAY, '19000101', d.fecha) % 7 + 1 AS dia_semana) s
    WHERE NOT EXISTS (SELECT 1 FROM dw.dim_fecha f WHERE f.fecha = d.fecha);

    SET @insertadas = @@ROWCOUNT;
END
GO

/* --------------------------- 2. Dimension medicamento --------------------------- */
CREATE OR ALTER PROCEDURE etl.sp_cargar_dim_medicamento
    @insertados   INT OUTPUT,
    @actualizados INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @acciones TABLE (accion NVARCHAR(10));

    MERGE dw.dim_medicamento AS d
    USING (
        SELECT m.id_medicamento,
               m.nombre,
               m.concentracion,
               ISNULL(p.nombre, N'Sin presentación') AS presentacion,
               LTRIM(RTRIM(CONCAT(m.nombre, ' ' + m.concentracion, ' ' + p.nombre))) AS descripcion_completa,
               c.nombre AS categoria,
               m.requiere_receta,
               m.iva_porcentaje,
               m.codigo_barras
        FROM origen.medicamento m
        INNER JOIN origen.categoria c    ON c.id_categoria = m.id_categoria
        LEFT  JOIN origen.presentacion p ON p.id_presentacion = m.id_presentacion
    ) AS s
    ON d.id_medicamento = s.id_medicamento
    WHEN MATCHED AND EXISTS (
            SELECT s.nombre, s.concentracion, s.presentacion, s.descripcion_completa, s.categoria,
                   s.requiere_receta, s.iva_porcentaje, s.codigo_barras
            EXCEPT
            SELECT d.nombre, d.concentracion, d.presentacion, d.descripcion_completa, d.categoria,
                   d.requiere_receta, d.iva_porcentaje, d.codigo_barras)
        THEN UPDATE SET nombre = s.nombre, concentracion = s.concentracion, presentacion = s.presentacion,
                        descripcion_completa = s.descripcion_completa, categoria = s.categoria,
                        requiere_receta = s.requiere_receta, iva_porcentaje = s.iva_porcentaje,
                        codigo_barras = s.codigo_barras, fecha_actualizacion = SYSDATETIME()
    WHEN NOT MATCHED BY TARGET
        THEN INSERT (id_medicamento, nombre, concentracion, presentacion, descripcion_completa, categoria,
                     requiere_receta, iva_porcentaje, codigo_barras)
             VALUES (s.id_medicamento, s.nombre, s.concentracion, s.presentacion, s.descripcion_completa, s.categoria,
                     s.requiere_receta, s.iva_porcentaje, s.codigo_barras)
    OUTPUT $action INTO @acciones;

    SELECT @insertados   = ISNULL(SUM(IIF(accion = N'INSERT', 1, 0)), 0),
           @actualizados = ISNULL(SUM(IIF(accion = N'UPDATE', 1, 0)), 0)
    FROM @acciones;
END
GO

/* --------------------------- 3. Dimension cliente --------------------------- */
CREATE OR ALTER PROCEDURE etl.sp_cargar_dim_cliente
    @insertados   INT OUTPUT,
    @actualizados INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @acciones TABLE (accion NVARCHAR(10));

    MERGE dw.dim_cliente AS d
    USING (
        SELECT c.id_cliente,
               LTRIM(RTRIM(CONCAT(c.primer_nombre, ' ', ISNULL(c.segundo_nombre + ' ', ''),
                                  c.primer_apellido, ' ', ISNULL(c.segundo_apellido, '')))) AS nombre_completo,
               c.identificacion,
               CASE WHEN NULLIF(LTRIM(RTRIM(c.direccion)), '') IS NULL THEN N'Sin dirección'
                    WHEN CHARINDEX(',', c.direccion) > 0 THEN LTRIM(RTRIM(LEFT(c.direccion, CHARINDEX(',', c.direccion) - 1)))
                    ELSE LTRIM(RTRIM(c.direccion)) END AS provincia,
               IIF(c.identificacion = '0', 1, 0) AS es_cliente_general
        FROM origen.cliente c
    ) AS s
    ON d.id_cliente = s.id_cliente
    WHEN MATCHED AND EXISTS (
            SELECT s.nombre_completo, s.identificacion, s.provincia, s.es_cliente_general
            EXCEPT
            SELECT d.nombre_completo, d.identificacion, d.provincia, d.es_cliente_general)
        THEN UPDATE SET nombre_completo = s.nombre_completo, identificacion = s.identificacion,
                        provincia = s.provincia, es_cliente_general = s.es_cliente_general,
                        fecha_actualizacion = SYSDATETIME()
    WHEN NOT MATCHED BY TARGET
        THEN INSERT (id_cliente, nombre_completo, identificacion, provincia, es_cliente_general)
             VALUES (s.id_cliente, s.nombre_completo, s.identificacion, s.provincia, s.es_cliente_general)
    OUTPUT $action INTO @acciones;

    SELECT @insertados   = ISNULL(SUM(IIF(accion = N'INSERT', 1, 0)), 0),
           @actualizados = ISNULL(SUM(IIF(accion = N'UPDATE', 1, 0)), 0)
    FROM @acciones;
END
GO

/* --------------------------- 4. Dimension metodo de pago --------------------------- */
CREATE OR ALTER PROCEDURE etl.sp_cargar_dim_metodo_pago
    @insertados INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO dw.dim_metodo_pago (metodo_pago)
    SELECT DISTINCT v.metodo_pago
    FROM origen.venta v
    WHERE NOT EXISTS (SELECT 1 FROM dw.dim_metodo_pago d WHERE d.metodo_pago = v.metodo_pago);

    SET @insertados = @@ROWCOUNT;
END
GO

/* --------------------------- 5. Hechos de ventas --------------------------- */
CREATE OR ALTER PROCEDURE etl.sp_cargar_fact_ventas
    @insertados   INT OUTPUT,
    @actualizados INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @acciones TABLE (accion NVARCHAR(10));

    MERGE dw.fact_ventas AS d
    USING (
        SELECT dv.id_detalle_venta,
               v.id_venta,
               YEAR(v.fecha_venta) * 10000 + MONTH(v.fecha_venta) * 100 + DAY(v.fecha_venta) AS fecha_key,
               dm.medicamento_key,
               dc.cliente_key,
               mp.metodo_pago_key,
               dl.numero_lote,
               v.estado AS estado_venta,
               CAST(IIF(v.estado = 'Anulada', 1, 0) AS BIT) AS es_anulada,
               CAST(IIF(v.id_receta IS NULL, 0, 1) AS BIT) AS con_receta,
               dv.cantidad,
               dv.precio_unidad AS precio_unitario,
               dv.subtotal,
               dv.descuento,
               dv.subtotal - dv.descuento AS ingreso_neto,
               dv.total - (dv.subtotal - dv.descuento) AS impuesto,
               dv.total,
               cu.costo_unitario,
               CAST(dv.cantidad * cu.costo_unitario AS DECIMAL(14,2)) AS costo_total,
               CAST(dv.subtotal - dv.descuento - dv.cantidad * cu.costo_unitario AS DECIMAL(14,2)) AS utilidad_bruta
        FROM origen.detalle_venta dv
        INNER JOIN origen.venta v          ON v.id_venta = dv.id_venta
        INNER JOIN origen.detalle_lote dl  ON dl.id_detalle_lote = dv.id_detalle_lote
        INNER JOIN origen.medicamento m    ON m.id_medicamento = dl.id_medicamento
        INNER JOIN dw.dim_medicamento dm   ON dm.id_medicamento = dl.id_medicamento
        INNER JOIN dw.dim_cliente dc       ON dc.id_cliente = v.id_cliente
        INNER JOIN dw.dim_metodo_pago mp   ON mp.metodo_pago = v.metodo_pago
        -- Costo real del lote: promedio ponderado de sus compras; si no tiene compras, el costo actual
        OUTER APPLY (SELECT CAST(ISNULL(SUM(c.subtotal) / NULLIF(SUM(c.cantidad), 0), m.precio_costo) AS DECIMAL(12,2)) AS costo_unitario
                     FROM origen.detalle_compra c
                     WHERE c.id_detalle_lote = dv.id_detalle_lote) cu
    ) AS s
    ON d.id_detalle_venta = s.id_detalle_venta
    WHEN MATCHED AND EXISTS (
            SELECT s.id_venta, s.fecha_key, s.medicamento_key, s.cliente_key, s.metodo_pago_key, s.numero_lote,
                   s.estado_venta, s.es_anulada, s.con_receta, s.cantidad, s.precio_unitario, s.subtotal,
                   s.descuento, s.impuesto, s.total, s.costo_unitario
            EXCEPT
            SELECT d.id_venta, d.fecha_key, d.medicamento_key, d.cliente_key, d.metodo_pago_key, d.numero_lote,
                   d.estado_venta, d.es_anulada, d.con_receta, d.cantidad, d.precio_unitario, d.subtotal,
                   d.descuento, d.impuesto, d.total, d.costo_unitario)
        THEN UPDATE SET id_venta = s.id_venta, fecha_key = s.fecha_key, medicamento_key = s.medicamento_key,
                        cliente_key = s.cliente_key, metodo_pago_key = s.metodo_pago_key, numero_lote = s.numero_lote,
                        estado_venta = s.estado_venta, es_anulada = s.es_anulada, con_receta = s.con_receta,
                        cantidad = s.cantidad, precio_unitario = s.precio_unitario, subtotal = s.subtotal,
                        descuento = s.descuento, ingreso_neto = s.ingreso_neto, impuesto = s.impuesto,
                        total = s.total, costo_unitario = s.costo_unitario, costo_total = s.costo_total,
                        utilidad_bruta = s.utilidad_bruta, fecha_actualizacion = SYSDATETIME()
    WHEN NOT MATCHED BY TARGET
        THEN INSERT (id_detalle_venta, id_venta, fecha_key, medicamento_key, cliente_key, metodo_pago_key,
                     numero_lote, estado_venta, es_anulada, con_receta, cantidad, precio_unitario, subtotal,
                     descuento, ingreso_neto, impuesto, total, costo_unitario, costo_total, utilidad_bruta)
             VALUES (s.id_detalle_venta, s.id_venta, s.fecha_key, s.medicamento_key, s.cliente_key, s.metodo_pago_key,
                     s.numero_lote, s.estado_venta, s.es_anulada, s.con_receta, s.cantidad, s.precio_unitario, s.subtotal,
                     s.descuento, s.ingreso_neto, s.impuesto, s.total, s.costo_unitario, s.costo_total, s.utilidad_bruta)
    OUTPUT $action INTO @acciones;

    SELECT @insertados   = ISNULL(SUM(IIF(accion = N'INSERT', 1, 0)), 0),
           @actualizados = ISNULL(SUM(IIF(accion = N'UPDATE', 1, 0)), 0)
    FROM @acciones;

    -- Toda linea de venta del origen debe estar en el DW
    IF EXISTS (SELECT 1 FROM origen.detalle_venta dv
               WHERE NOT EXISTS (SELECT 1 FROM dw.fact_ventas f WHERE f.id_detalle_venta = dv.id_detalle_venta))
        THROW 50302, 'Hay lineas de venta que no se pudieron cargar en fact_ventas.', 1;
END
GO

/* --------------------------- Orquestador --------------------------- */
CREATE OR ALTER PROCEDURE etl.sp_ejecutar_etl
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_carga INT,
            @fechas INT, @med_ins INT, @med_act INT, @cli_ins INT, @cli_act INT,
            @met_ins INT, @hec_ins INT, @hec_act INT,
            @desde DATE, @hasta DATE;

    -- La bitacora se escribe fuera de la transaccion para conservar el registro aunque falle la carga
    INSERT INTO etl.log_carga (inicio, estado) VALUES (SYSDATETIME(), N'En proceso');
    SET @id_carga = SCOPE_IDENTITY();

    BEGIN TRY
        BEGIN TRANSACTION;

        -- Calendario: anos completos que cubren todas las ventas (o el ano actual si no hay)
        SELECT @desde = DATEFROMPARTS(YEAR(ISNULL(MIN(fecha_venta), GETDATE())), 1, 1),
               @hasta = DATEFROMPARTS(YEAR(ISNULL(MAX(fecha_venta), GETDATE())), 12, 31)
        FROM origen.venta;

        EXEC etl.sp_cargar_dim_fecha @desde, @hasta, @fechas OUTPUT;
        EXEC etl.sp_cargar_dim_medicamento @med_ins OUTPUT, @med_act OUTPUT;
        EXEC etl.sp_cargar_dim_cliente @cli_ins OUTPUT, @cli_act OUTPUT;
        EXEC etl.sp_cargar_dim_metodo_pago @met_ins OUTPUT;
        EXEC etl.sp_cargar_fact_ventas @hec_ins OUTPUT, @hec_act OUTPUT;

        COMMIT TRANSACTION;

        UPDATE etl.log_carga
           SET fin = SYSDATETIME(), estado = N'Correcto',
               fechas_insertadas = @fechas, medicamentos_insertados = @med_ins, medicamentos_actualizados = @med_act,
               clientes_insertados = @cli_ins, clientes_actualizados = @cli_act, metodos_insertados = @met_ins,
               hechos_insertados = @hec_ins, hechos_actualizados = @hec_act
         WHERE id_carga = @id_carga;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        UPDATE etl.log_carga
           SET fin = SYSDATETIME(), estado = N'Error', mensaje = ERROR_MESSAGE()
         WHERE id_carga = @id_carga;
        THROW;
    END CATCH

    SELECT * FROM etl.log_carga WHERE id_carga = @id_carga;
END
GO

PRINT 'Procedimientos del ETL listos.';
GO

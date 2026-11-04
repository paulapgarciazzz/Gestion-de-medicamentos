/* =====================================================================
   Procedimiento: dbo.sp_reporte_ventas_medicamento
   Motor: SQL Server (T-SQL)
   Proposito: reporte de los 10 medicamentos mas vendidos en un rango de
              fechas, calculado con un CURSOR explicito que recorre cada
              linea de venta (detalle_venta).
   Uso:       reportes.php (tabla "10 medicamentos mas vendidos")
   Notas:
     - Solo lectura: no modifica ninguna tabla; acumula en una tabla variable.
     - No cuenta las ventas anuladas.
     - Agrupa por id_medicamento, asi dos medicamentos con el mismo nombre
       (distinta presentacion o concentracion) salen por separado.
     - El monto es detalle_venta.total (descuento e IVA ya aplicados).
     - Se puede ejecutar varias veces (CREATE OR ALTER, SQL Server 2016 SP1+).
   ===================================================================== */

USE farmacia;
GO

-- Opciones con las que queda guardado el procedimiento (SSMS las trae activadas; sqlcmd no)
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER PROCEDURE dbo.sp_reporte_ventas_medicamento
    @desde DATE,
    @hasta DATE
AS
BEGIN
    SET NOCOUNT ON;  -- evita mensajes de "filas afectadas": PHP recibe un solo resultado

    IF @desde IS NULL OR @hasta IS NULL OR @desde > @hasta
        THROW 50001, 'El rango de fechas no es valido: @desde debe ser menor o igual que @hasta.', 1;

    -- Resumen por medicamento que se va llenando dentro del cursor
    DECLARE @resumen TABLE (
        id_medicamento INT           NOT NULL PRIMARY KEY,
        nombre         VARCHAR(100)  NOT NULL,
        concentracion  VARCHAR(50)   NULL,
        presentacion   VARCHAR(50)   NULL,
        unidades       INT           NOT NULL,
        monto          DECIMAL(14,2) NOT NULL
    );

    -- Variables que reciben cada fila del cursor
    DECLARE @id_medicamento INT,
            @nombre         VARCHAR(100),
            @concentracion  VARCHAR(50),
            @presentacion   VARCHAR(50),
            @cantidad       INT,
            @total          DECIMAL(10,2);

    BEGIN TRY
        -- 1. DECLARE CURSOR: una fila por cada linea de venta del periodo
        DECLARE cur_ventas CURSOR LOCAL FAST_FORWARD FOR
            SELECT m.id_medicamento, m.nombre, m.concentracion, p.nombre, dv.cantidad, dv.total
            FROM dbo.detalle_venta dv
            INNER JOIN dbo.venta v         ON v.id_venta = dv.id_venta
            INNER JOIN dbo.detalle_lote dl ON dl.id_detalle_lote = dv.id_detalle_lote
            INNER JOIN dbo.medicamento m   ON m.id_medicamento = dl.id_medicamento
            LEFT  JOIN dbo.presentacion p  ON p.id_presentacion = m.id_presentacion
            WHERE v.estado <> 'Anulada'
              AND v.fecha_venta >= @desde
              AND v.fecha_venta <  DATEADD(DAY, 1, @hasta);

        -- 2. OPEN
        OPEN cur_ventas;

        -- 3. Primer FETCH NEXT
        FETCH NEXT FROM cur_ventas
            INTO @id_medicamento, @nombre, @concentracion, @presentacion, @cantidad, @total;

        -- 4. WHILE: se repite mientras el ultimo FETCH haya traido una fila
        WHILE @@FETCH_STATUS = 0
        BEGIN
            IF EXISTS (SELECT 1 FROM @resumen WHERE id_medicamento = @id_medicamento)
                UPDATE @resumen
                   SET unidades = unidades + @cantidad,
                       monto    = monto + @total
                 WHERE id_medicamento = @id_medicamento;
            ELSE
                INSERT INTO @resumen (id_medicamento, nombre, concentracion, presentacion, unidades, monto)
                VALUES (@id_medicamento, @nombre, @concentracion, @presentacion, @cantidad, @total);

            FETCH NEXT FROM cur_ventas
                INTO @id_medicamento, @nombre, @concentracion, @presentacion, @cantidad, @total;
        END

        -- 5. CLOSE y 6. DEALLOCATE
        CLOSE cur_ventas;
        DEALLOCATE cur_ventas;
    END TRY
    BEGIN CATCH
        -- Liberacion segura: CURSOR_STATUS = 1/0 abierto, -1 cerrado, -3 no existe
        IF CURSOR_STATUS('local', 'cur_ventas') >= 0
            CLOSE cur_ventas;
        IF CURSOR_STATUS('local', 'cur_ventas') >= -1
            DEALLOCATE cur_ventas;

        THROW;  -- relanza el error original a quien llamo el procedimiento
    END CATCH

    -- Resultado: los 10 medicamentos con mas unidades vendidas
    SELECT TOP (10) id_medicamento, nombre, concentracion, presentacion, unidades, monto
    FROM @resumen
    ORDER BY unidades DESC, monto DESC, id_medicamento;
END
GO

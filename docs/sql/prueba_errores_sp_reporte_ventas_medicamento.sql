/* =====================================================================
   Pruebas de ERRORES INTENCIONALES de dbo.sp_reporte_ventas_medicamento
   Ejecutar en SSMS por separado de las pruebas normales.
   Cada caso va en su propio lote (GO) y dentro de TRY/CATCH, asi un
   error no detiene los siguientes casos. Solo lee datos.
   ===================================================================== */

USE farmacia;
GO

/* Caso 1: @desde posterior a @hasta -> se espera el error 50001 */
BEGIN TRY
    EXEC dbo.sp_reporte_ventas_medicamento @desde = '2026-12-31', @hasta = '2026-01-01';
    SELECT 'Caso 1' AS caso, 'FALLO: no se produjo el error esperado' AS resultado;
END TRY
BEGIN CATCH
    SELECT 'Caso 1: rango invertido' AS caso,
           ERROR_NUMBER() AS numero_error, ERROR_MESSAGE() AS mensaje,
           IIF(ERROR_NUMBER() = 50001, 'OK', 'FALLO') AS resultado;
END CATCH
GO

/* Caso 2: fecha NULL -> se espera el error 50001 */
BEGIN TRY
    EXEC dbo.sp_reporte_ventas_medicamento @desde = NULL, @hasta = '2026-12-31';
    SELECT 'Caso 2' AS caso, 'FALLO: no se produjo el error esperado' AS resultado;
END TRY
BEGIN CATCH
    SELECT 'Caso 2: fecha nula' AS caso,
           ERROR_NUMBER() AS numero_error, ERROR_MESSAGE() AS mensaje,
           IIF(ERROR_NUMBER() = 50001, 'OK', 'FALLO') AS resultado;
END CATCH
GO

/* Caso 3: fecha inexistente -> SQL Server rechaza la conversion a DATE (error 8114) */
BEGIN TRY
    EXEC dbo.sp_reporte_ventas_medicamento @desde = '2026-02-30', @hasta = '2026-12-31';
    SELECT 'Caso 3' AS caso, 'FALLO: no se produjo el error esperado' AS resultado;
END TRY
BEGIN CATCH
    SELECT 'Caso 3: fecha inexistente' AS caso,
           ERROR_NUMBER() AS numero_error, ERROR_MESSAGE() AS mensaje,
           IIF(ERROR_NUMBER() = 8114, 'OK', 'FALLO') AS resultado;
END CATCH
GO

/* Despues de los errores no debe quedar ningun cursor abierto (0 filas) */
SELECT * FROM sys.dm_exec_cursors(@@SPID);
IF NOT EXISTS (SELECT 1 FROM sys.dm_exec_cursors(@@SPID))
    PRINT 'OK: no quedan cursores abiertos despues de los errores.';
ELSE
    PRINT 'ERROR: quedo un cursor abierto.';
GO

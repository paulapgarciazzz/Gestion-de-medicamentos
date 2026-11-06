/* =====================================================================
   Respaldo COMPLETO de la base farmacia
   - Carpeta: <ruta de backups de la instancia>\farmacia  (fuera del repositorio;
     el servicio de SQL Server ya tiene permisos sobre esa ruta)
   - Nombre:  farmacia_FULL_aaaaMMdd_HHmmss.bak (un archivo nuevo en cada ejecucion,
     no sobrescribe respaldos anteriores)
   - Opciones: CHECKSUM (detecta paginas danadas) y COMPRESSION (solo si la
     edicion la admite; SQL Server Express no)
   - Al terminar comprueba el archivo con RESTORE VERIFYONLY
   - Es un backup normal (no COPY_ONLY): inicia la cadena de respaldos que
     usan el diferencial y el de log.
   ===================================================================== */

USE master;
GO

SET NOCOUNT ON;

BEGIN TRY
    DECLARE @base    SYSNAME       = N'farmacia';
    DECLARE @carpeta NVARCHAR(400) = CAST(SERVERPROPERTY('InstanceDefaultBackupPath') AS NVARCHAR(400)) + N'\farmacia';
    DECLARE @marca   NVARCHAR(20)  = FORMAT(SYSDATETIME(), 'yyyyMMdd_HHmmss');
    DECLARE @archivo NVARCHAR(500) = @carpeta + N'\farmacia_FULL_' + @marca + N'.bak';
    DECLARE @nombre  NVARCHAR(128) = N'farmacia - completo ' + @marca;

    IF DB_ID(@base) IS NULL
        THROW 50200, 'La base farmacia no existe en esta instancia.', 1;

    EXEC master.dbo.xp_create_subdir @carpeta;  -- crea la carpeta si no existe

    -- COMPRESSION no existe en SQL Server Express (EngineEdition = 4): solo se usa si la edicion la admite
    DECLARE @sql NVARCHAR(MAX) = N'BACKUP DATABASE ' + QUOTENAME(@base)
        + N' TO DISK = @archivo WITH INIT, CHECKSUM'
        + CASE WHEN CAST(SERVERPROPERTY('EngineEdition') AS INT) <> 4 THEN N', COMPRESSION' ELSE N'' END
        + N', NAME = @nombre, STATS = 25;';
    EXEC sys.sp_executesql @sql, N'@archivo NVARCHAR(500), @nombre NVARCHAR(128)', @archivo, @nombre;

    RESTORE VERIFYONLY FROM DISK = @archivo WITH CHECKSUM;

    PRINT CONCAT('OK: backup completo creado y verificado: ', @archivo);
END TRY
BEGIN CATCH
    PRINT 'ERROR: el backup completo no se pudo crear o verificar.';
    THROW;
END CATCH
GO

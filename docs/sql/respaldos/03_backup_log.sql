/* =====================================================================
   Respaldo del LOG DE TRANSACCIONES de la base farmacia
   - Solo es posible si el modelo de recuperacion es FULL o BULK_LOGGED y
     ya existe un backup completo que inicio la cadena del log.
   - Permite recuperar hasta el ultimo log respaldado (o un punto en el tiempo).
   - Nombre: farmacia_LOG_aaaaMMdd_HHmmss.trn en la misma carpeta.
   ===================================================================== */

USE master;
GO

SET NOCOUNT ON;

BEGIN TRY
    DECLARE @base    SYSNAME       = N'farmacia';
    DECLARE @carpeta NVARCHAR(400) = CAST(SERVERPROPERTY('InstanceDefaultBackupPath') AS NVARCHAR(400)) + N'\farmacia';
    DECLARE @marca   NVARCHAR(20)  = FORMAT(SYSDATETIME(), 'yyyyMMdd_HHmmss');
    DECLARE @archivo NVARCHAR(500) = @carpeta + N'\farmacia_LOG_' + @marca + N'.trn';
    DECLARE @nombre  NVARCHAR(128) = N'farmacia - log ' + @marca;
    DECLARE @modelo  NVARCHAR(60)  = (SELECT recovery_model_desc FROM sys.databases WHERE name = @base);

    IF @modelo NOT IN (N'FULL', N'BULK_LOGGED')
        THROW 50220, 'El modelo de recuperacion de farmacia es SIMPLE: no admite backups de log.', 1;

    -- last_log_backup_lsn es NULL mientras no exista un backup completo que inicie la cadena
    IF (SELECT last_log_backup_lsn FROM sys.database_recovery_status WHERE database_id = DB_ID(@base)) IS NULL
        THROW 50221, 'La cadena del log no esta iniciada: ejecute primero 01_backup_completo.sql.', 1;

    EXEC master.dbo.xp_create_subdir @carpeta;

    -- COMPRESSION no existe en SQL Server Express (EngineEdition = 4): solo se usa si la edicion la admite
    DECLARE @sql NVARCHAR(MAX) = N'BACKUP LOG ' + QUOTENAME(@base)
        + N' TO DISK = @archivo WITH INIT, CHECKSUM'
        + CASE WHEN CAST(SERVERPROPERTY('EngineEdition') AS INT) <> 4 THEN N', COMPRESSION' ELSE N'' END
        + N', NAME = @nombre, STATS = 25;';
    EXEC sys.sp_executesql @sql, N'@archivo NVARCHAR(500), @nombre NVARCHAR(128)', @archivo, @nombre;

    RESTORE VERIFYONLY FROM DISK = @archivo WITH CHECKSUM;

    PRINT CONCAT('OK: backup de log creado y verificado: ', @archivo);
END TRY
BEGIN CATCH
    PRINT 'ERROR: el backup de log no se pudo crear o verificar.';
    THROW;
END CATCH
GO

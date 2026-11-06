/* =====================================================================
   Respaldo DIFERENCIAL de la base farmacia
   - Guarda solo las paginas que cambiaron desde el ultimo backup COMPLETO.
   - Requiere que exista un backup completo normal (no COPY_ONLY):
     ejecute antes 01_backup_completo.sql.
   - Nombre: farmacia_DIFF_aaaaMMdd_HHmmss.bak en la misma carpeta.
   ===================================================================== */

USE master;
GO

SET NOCOUNT ON;

BEGIN TRY
    DECLARE @base    SYSNAME       = N'farmacia';
    DECLARE @carpeta NVARCHAR(400) = CAST(SERVERPROPERTY('InstanceDefaultBackupPath') AS NVARCHAR(400)) + N'\farmacia';
    DECLARE @marca   NVARCHAR(20)  = FORMAT(SYSDATETIME(), 'yyyyMMdd_HHmmss');
    DECLARE @archivo NVARCHAR(500) = @carpeta + N'\farmacia_DIFF_' + @marca + N'.bak';
    DECLARE @nombre  NVARCHAR(128) = N'farmacia - diferencial ' + @marca;

    -- Base del diferencial: el ultimo backup completo (no COPY_ONLY)
    IF NOT EXISTS (SELECT 1 FROM msdb.dbo.backupset
                   WHERE database_name = @base AND type = 'D' AND is_copy_only = 0)
        THROW 50210, 'No hay un backup completo de farmacia: ejecute primero 01_backup_completo.sql.', 1;

    EXEC master.dbo.xp_create_subdir @carpeta;

    -- COMPRESSION no existe en SQL Server Express (EngineEdition = 4): solo se usa si la edicion la admite
    DECLARE @sql NVARCHAR(MAX) = N'BACKUP DATABASE ' + QUOTENAME(@base)
        + N' TO DISK = @archivo WITH DIFFERENTIAL, INIT, CHECKSUM'
        + CASE WHEN CAST(SERVERPROPERTY('EngineEdition') AS INT) <> 4 THEN N', COMPRESSION' ELSE N'' END
        + N', NAME = @nombre, STATS = 25;';
    EXEC sys.sp_executesql @sql, N'@archivo NVARCHAR(500), @nombre NVARCHAR(128)', @archivo, @nombre;

    RESTORE VERIFYONLY FROM DISK = @archivo WITH CHECKSUM;

    PRINT CONCAT('OK: backup diferencial creado y verificado: ', @archivo);
END TRY
BEGIN CATCH
    PRINT 'ERROR: el backup diferencial no se pudo crear o verificar.';
    THROW;
END CATCH
GO

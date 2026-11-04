/* =====================================================================
   Restauracion de PRUEBA de farmacia en una base SEPARADA
   - Nunca toca la base farmacia: crea una base nueva llamada
     farmacia_restauracion_aaaaMMdd_HHmmss con sus propios archivos .mdf/.ldf.
   - No usa WITH REPLACE y se detiene si la base destino ya existe.
   - Aplica la cadena completa encontrada en msdb:
       ultimo backup completo -> su ultimo diferencial (si hay)
       -> los backups de log posteriores (si hay), en orden y sin huecos.
   - Al final compara la cantidad de filas de las 14 tablas entre farmacia y
     la copia restaurada (coinciden si no hubo cambios despues del ultimo respaldo).
   - La base restaurada queda disponible para revisarla; este script NO la
     elimina. Para borrarla, hagalo manualmente cuando ya no la necesite.
   ===================================================================== */

USE master;
GO

SET NOCOUNT ON;

DECLARE @origen  SYSNAME = N'farmacia';
DECLARE @destino SYSNAME = N'farmacia_restauracion_' + FORMAT(SYSDATETIME(), 'yyyyMMdd_HHmmss');

BEGIN TRY
    IF @destino = @origen OR DB_ID(@destino) IS NOT NULL
        THROW 50230, 'La base destino ya existe o es farmacia: no se restaura para no sobrescribir nada.', 1;

    /* --- 1. Cadena de respaldos --- */
    DECLARE @full_archivo NVARCHAR(500), @full_checkpoint NUMERIC(25,0), @base_last_lsn NUMERIC(25,0), @full_fecha DATETIME;
    SELECT TOP (1) @full_archivo = f.physical_device_name, @full_checkpoint = b.checkpoint_lsn,
                   @base_last_lsn = b.last_lsn, @full_fecha = b.backup_finish_date
    FROM msdb.dbo.backupset b
    INNER JOIN msdb.dbo.backupmediafamily f ON f.media_set_id = b.media_set_id
    WHERE b.database_name = @origen AND b.type = 'D' AND b.is_copy_only = 0
    ORDER BY b.backup_finish_date DESC;

    IF @full_archivo IS NULL
        THROW 50231, 'No hay backup completo de farmacia: ejecute primero 01_backup_completo.sql.', 1;

    DECLARE @diff_archivo NVARCHAR(500), @diff_last_lsn NUMERIC(25,0);
    SELECT TOP (1) @diff_archivo = f.physical_device_name, @diff_last_lsn = b.last_lsn
    FROM msdb.dbo.backupset b
    INNER JOIN msdb.dbo.backupmediafamily f ON f.media_set_id = b.media_set_id
    WHERE b.database_name = @origen AND b.type = 'I' AND b.differential_base_lsn = @full_checkpoint
    ORDER BY b.backup_finish_date DESC;

    IF @diff_archivo IS NOT NULL SET @base_last_lsn = @diff_last_lsn;

    DECLARE @logs TABLE (orden INT IDENTITY(1,1) PRIMARY KEY, archivo NVARCHAR(500), first_lsn NUMERIC(25,0), last_lsn NUMERIC(25,0));
    INSERT INTO @logs (archivo, first_lsn, last_lsn)
    SELECT f.physical_device_name, b.first_lsn, b.last_lsn
    FROM msdb.dbo.backupset b
    INNER JOIN msdb.dbo.backupmediafamily f ON f.media_set_id = b.media_set_id
    WHERE b.database_name = @origen AND b.type = 'L' AND b.last_lsn > @base_last_lsn AND b.backup_finish_date >= @full_fecha
    ORDER BY b.first_lsn;

    -- El primer log debe cubrir el final del completo/diferencial y los siguientes deben ser continuos
    IF EXISTS (SELECT 1 FROM @logs WHERE orden = 1 AND first_lsn > @base_last_lsn)
       OR EXISTS (SELECT 1 FROM (SELECT first_lsn, LAG(last_lsn) OVER (ORDER BY orden) AS anterior FROM @logs) x
                  WHERE x.anterior IS NOT NULL AND x.first_lsn <> x.anterior)
        THROW 50232, 'La cadena de backups de log tiene un hueco: no se puede restaurar hasta el ultimo log.', 1;

    PRINT CONCAT('Base destino: ', @destino);
    PRINT CONCAT('Completo:     ', @full_archivo);
    PRINT CONCAT('Diferencial:  ', ISNULL(@diff_archivo, '(ninguno)'));
    DECLARE @n_logs INT = (SELECT COUNT(*) FROM @logs);
    PRINT CONCAT('Logs:         ', @n_logs);

    /* --- 2. Archivos nuevos para la copia (no reutiliza los de farmacia) --- */
    DECLARE @ruta_datos   NVARCHAR(400) = CAST(SERVERPROPERTY('InstanceDefaultDataPath') AS NVARCHAR(400));
    DECLARE @logico_datos SYSNAME = (SELECT name FROM sys.master_files WHERE database_id = DB_ID(@origen) AND type = 0);
    DECLARE @logico_log   SYSNAME = (SELECT name FROM sys.master_files WHERE database_id = DB_ID(@origen) AND type = 1);
    DECLARE @mdf NVARCHAR(500) = @ruta_datos + @destino + N'.mdf';
    DECLARE @ldf NVARCHAR(500) = @ruta_datos + @destino + N'_log.ldf';

    /* --- 3. Restaurar la cadena --- */
    RESTORE DATABASE @destino FROM DISK = @full_archivo
        WITH MOVE @logico_datos TO @mdf, MOVE @logico_log TO @ldf, NORECOVERY, CHECKSUM, STATS = 50;

    IF @diff_archivo IS NOT NULL
        RESTORE DATABASE @destino FROM DISK = @diff_archivo WITH NORECOVERY, CHECKSUM;

    DECLARE @i INT = 1, @log_archivo NVARCHAR(500);
    WHILE @i <= @n_logs
    BEGIN
        SET @log_archivo = (SELECT archivo FROM @logs WHERE orden = @i);
        RESTORE LOG @destino FROM DISK = @log_archivo WITH NORECOVERY, CHECKSUM;
        SET @i += 1;
    END

    RESTORE DATABASE @destino WITH RECOVERY;
    PRINT CONCAT('OK: restauracion terminada en ', @destino);

    /* --- 4. Comparar filas por tabla --- */
    DECLARE @sql NVARCHAR(MAX);
    SELECT @sql = STRING_AGG(CONVERT(NVARCHAR(MAX),
              N'SELECT ''' + t.name + N''' AS tabla, '
            + N'(SELECT COUNT(*) FROM ' + QUOTENAME(@origen)  + N'.dbo.' + QUOTENAME(t.name) + N') AS filas_farmacia, '
            + N'(SELECT COUNT(*) FROM ' + QUOTENAME(@destino) + N'.dbo.' + QUOTENAME(t.name) + N') AS filas_restaurada'),
            N' UNION ALL ') WITHIN GROUP (ORDER BY t.name)
    FROM farmacia.sys.tables t;

    SET @sql = N'SELECT x.*, CASE WHEN x.filas_farmacia = x.filas_restaurada THEN ''OK'' ELSE ''DIFERENTE'' END AS resultado FROM ('
             + @sql + N') x ORDER BY x.tabla;';
    EXEC sys.sp_executesql @sql;

    DBCC CHECKDB (@destino) WITH NO_INFOMSGS;
    PRINT CONCAT('OK: DBCC CHECKDB sin errores en ', @destino);
END TRY
BEGIN CATCH
    PRINT 'ERROR: la restauracion de prueba no se completo. farmacia no fue modificada.';
    THROW;
END CATCH
GO

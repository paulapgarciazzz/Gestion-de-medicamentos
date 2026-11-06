/* =====================================================================
   Respaldos desde la aplicación (pantalla Administración > Mantenimiento)
   - Tabla dbo.respaldo: bitácora de cada respaldo pedido desde la aplicación
     (quién, cuándo, tipo, archivo, resultado y mensaje de error si falló).
   - Procedimiento dbo.sp_respaldar_farmacia @tipo, @id_usuario:
       'Completo'    -> BACKUP DATABASE
       'Diferencial' -> BACKUP DATABASE ... WITH DIFFERENTIAL
       'Log'         -> BACKUP LOG
     Mismo formato y carpeta que los scripts 01-03 de esta carpeta, así que
     04_verificar_backups.sql y 05_restauracion_prueba.sql también los usan.
   - Solo un Administrador activo puede ejecutarlo.
   - BACKUP no se puede ejecutar dentro de una transacción: la bitácora se
     escribe antes ('En proceso') y se actualiza al final ('Correcto'/'Error').
   - La cuenta de la aplicación necesita el rol db_backupoperator
     (ver docs/sql/permisos_aplicacion.sql).
   Se puede ejecutar varias veces.
   ===================================================================== */

USE farmacia;
GO

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.respaldo', N'U') IS NULL
CREATE TABLE dbo.respaldo (
    id_respaldo INT IDENTITY(1,1) NOT NULL,
    tipo        VARCHAR(15)    NOT NULL,   -- Completo / Diferencial / Log
    archivo     NVARCHAR(500)  NOT NULL,
    id_usuario  INT            NOT NULL,
    inicio      DATETIME       NOT NULL CONSTRAINT DF_respaldo_inicio DEFAULT GETDATE(),
    fin         DATETIME       NULL,
    estado      VARCHAR(15)    NOT NULL,   -- En proceso / Correcto / Error
    verificado  BIT            NOT NULL CONSTRAINT DF_respaldo_verificado DEFAULT 0,
    mensaje     NVARCHAR(2000) NULL,
    CONSTRAINT PK_respaldo PRIMARY KEY (id_respaldo),
    CONSTRAINT FK_respaldo_usuario FOREIGN KEY (id_usuario) REFERENCES dbo.usuario (id_usuario),
    CONSTRAINT CK_respaldo_tipo CHECK (tipo IN ('Completo', 'Diferencial', 'Log')),
    CONSTRAINT CK_respaldo_estado CHECK (estado IN ('En proceso', 'Correcto', 'Error'))
);
GO

CREATE OR ALTER PROCEDURE dbo.sp_respaldar_farmacia
    @tipo       VARCHAR(15),
    @id_usuario INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @carpeta    NVARCHAR(400) = CAST(SERVERPROPERTY('InstanceDefaultBackupPath') AS NVARCHAR(400)) + N'\farmacia',
            @marca      NVARCHAR(20)  = FORMAT(SYSDATETIME(), 'yyyyMMdd_HHmmss'),
            @archivo    NVARCHAR(500),
            @nombre     NVARCHAR(128),
            @id         INT,
            @verificado BIT = 0,
            @msg        NVARCHAR(2000);

    IF @tipo IS NULL OR @tipo NOT IN ('Completo', 'Diferencial', 'Log')
        THROW 51060, N'Tipo de respaldo no válido.', 1;
    IF NOT EXISTS (SELECT 1 FROM dbo.usuario WHERE id_usuario = @id_usuario AND estado = 1 AND rol = 'Administrador')
        THROW 51061, N'Solo un Administrador activo puede crear respaldos.', 1;

    -- Requisitos de cada tipo (se revisan antes para dar un mensaje claro)
    IF @tipo = 'Diferencial'
       AND (SELECT differential_base_lsn FROM sys.database_files WHERE type = 0 AND file_id = 1) IS NULL
        THROW 51062, N'No hay un respaldo completo previo: haga primero un respaldo completo.', 1;
    IF @tipo = 'Log' AND (SELECT recovery_model_desc FROM sys.databases WHERE database_id = DB_ID()) = 'SIMPLE'
        THROW 51063, N'La base está en modelo de recuperación SIMPLE: no admite respaldos de log.', 1;
    IF @tipo = 'Log' AND (SELECT last_log_backup_lsn FROM sys.database_recovery_status WHERE database_id = DB_ID()) IS NULL
        THROW 51064, N'La cadena del log no está iniciada: haga primero un respaldo completo.', 1;

    SET @archivo = @carpeta + N'\farmacia_'
                 + CASE @tipo WHEN 'Completo' THEN N'FULL' WHEN 'Diferencial' THEN N'DIFF' ELSE N'LOG' END
                 + N'_' + @marca + CASE @tipo WHEN 'Log' THEN N'.trn' ELSE N'.bak' END;
    SET @nombre = N'farmacia - ' + LOWER(@tipo) + N' ' + @marca + N' (aplicación)';

    INSERT INTO dbo.respaldo (tipo, archivo, id_usuario, estado) VALUES (@tipo, @archivo, @id_usuario, 'En proceso');
    SET @id = SCOPE_IDENTITY();

    -- Crear la carpeta requiere sysadmin; normalmente ya existe (la crean los scripts 01-03)
    BEGIN TRY EXEC master.dbo.xp_create_subdir @carpeta; END TRY BEGIN CATCH END CATCH;

    -- COMPRESSION no existe en SQL Server Express (EngineEdition = 4): solo se usa si la edición la admite
    DECLARE @sql NVARCHAR(MAX) =
          CASE @tipo WHEN 'Log' THEN N'BACKUP LOG farmacia' ELSE N'BACKUP DATABASE farmacia' END
        + N' TO DISK = @archivo WITH '
        + CASE @tipo WHEN 'Diferencial' THEN N'DIFFERENTIAL, ' ELSE N'' END
        + N'INIT, CHECKSUM'
        + CASE WHEN CAST(SERVERPROPERTY('EngineEdition') AS INT) <> 4 THEN N', COMPRESSION' ELSE N'' END
        + N', NAME = @nombre;';

    BEGIN TRY
        EXEC sys.sp_executesql @sql, N'@archivo NVARCHAR(500), @nombre NVARCHAR(128)', @archivo, @nombre;

        -- Verificación del archivo (requiere permiso CREATE DATABASE; si no lo hay, el respaldo igual
        -- quedó protegido por CHECKSUM y se puede verificar luego con 04_verificar_backups.sql)
        BEGIN TRY
            RESTORE VERIFYONLY FROM DISK = @archivo WITH CHECKSUM;
            SET @verificado = 1;
        END TRY
        BEGIN CATCH
        END CATCH

        UPDATE dbo.respaldo
           SET fin = GETDATE(), estado = 'Correcto', verificado = @verificado,
               mensaje = CASE WHEN @verificado = 1
                              THEN N'Respaldo creado y verificado (RESTORE VERIFYONLY).'
                              ELSE N'Respaldo creado con CHECKSUM. Verifíquelo con 04_verificar_backups.sql.' END
         WHERE id_respaldo = @id;
    END TRY
    BEGIN CATCH
        SET @msg = ERROR_MESSAGE();
        UPDATE dbo.respaldo SET fin = GETDATE(), estado = 'Error', mensaje = @msg WHERE id_respaldo = @id;
        SET @msg = CONCAT(N'No se pudo crear el respaldo: ', @msg);
        THROW 51065, @msg, 1;
    END CATCH

    SELECT * FROM dbo.respaldo WHERE id_respaldo = @id;
END
GO

PRINT 'Tabla dbo.respaldo y procedimiento dbo.sp_respaldar_farmacia listos.';
GO

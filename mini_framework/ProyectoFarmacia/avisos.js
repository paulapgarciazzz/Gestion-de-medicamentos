(() => {
    const dialogo = document.getElementById('confirmar-eliminacion');
    const aceptar = document.getElementById('aceptar-eliminacion');
    const aviso = document.getElementById('aviso-temporal');
    let solicitudPendiente = null;
    let temporizador;

    function mostrarAviso(texto, error = false) {
        clearTimeout(temporizador);
        document.getElementById('texto-aviso').textContent = texto;
        aviso.classList.toggle('aviso-error', error);
        aviso.hidden = false;
        // Los errores permanecen visibles hasta que el usuario los cierre.
        if (!error) temporizador = setTimeout(() => { aviso.hidden = true; }, 4500);
    }

    aviso.querySelector('button').addEventListener('click', () => {
        clearTimeout(temporizador);
        aviso.hidden = true;
    });

    document.body.addEventListener('htmx:confirm', (event) => {
        if (!event.detail.question) return;
        event.preventDefault();
        if (dialogo.open) return;
        solicitudPendiente = event.detail.issueRequest;
        document.getElementById('texto-confirmacion').textContent = event.detail.question;
        aceptar.disabled = false;
        dialogo.showModal();
    });

    dialogo.querySelectorAll('[data-cancelar]').forEach((boton) => {
        boton.addEventListener('click', () => dialogo.close());
    });
    dialogo.addEventListener('close', () => { solicitudPendiente = null; });
    dialogo.addEventListener('cancel', () => { solicitudPendiente = null; });
    aceptar.addEventListener('click', () => {
        if (!solicitudPendiente) return;
        const enviar = solicitudPendiente;
        solicitudPendiente = null;
        aceptar.disabled = true;
        dialogo.close();
        enviar(true);
    });

    document.body.addEventListener('productoGuardado', () => mostrarAviso('Producto guardado correctamente.'));
    document.body.addEventListener('productoEliminado', () => mostrarAviso('Producto eliminado correctamente.'));
    document.body.addEventListener('htmx:sendError', () => mostrarAviso('No se pudo conectar con el servidor. Intenta nuevamente.', true));
    document.body.addEventListener('htmx:timeout', () => mostrarAviso('El servidor tardó demasiado en responder. Verifica el listado antes de reintentar.', true));
})();

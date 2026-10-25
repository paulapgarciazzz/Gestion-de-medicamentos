/* Comportamiento común de los módulos nuevos (avisos, cierre de modales, menú móvil). */
(() => {
    const aviso = document.getElementById('aviso-temporal');
    const texto = document.getElementById('texto-aviso');
    let temporizador;

    function mostrar(msg, error = false) {
        if (!aviso) return;
        clearTimeout(temporizador);
        texto.textContent = msg;
        aviso.classList.toggle('aviso-error', error);
        aviso.hidden = false;
        if (!error) temporizador = setTimeout(() => { aviso.hidden = true; }, 4500);
    }
    function limpiar() {
        const m = document.getElementById('modal-contenido');
        if (m) m.innerHTML = '';
        const l = document.getElementById('mensaje-lista');
        if (l) l.innerHTML = '';
    }

    document.body.addEventListener('aviso', (e) => mostrar(e.detail.texto, !!e.detail.error));
    ['crudGuardado', 'crudEliminado', 'cerrarModal'].forEach((n) => document.body.addEventListener(n, limpiar));

    // Permite mostrar los mensajes de error (4xx/5xx) que devuelve el servidor.
    document.body.addEventListener('htmx:beforeSwap', (e) => {
        if ([401, 403, 404, 409, 422, 500].includes(e.detail.xhr.status)) {
            e.detail.shouldSwap = true;
            e.detail.isError = false;
        }
    });

    document.querySelectorAll('[data-burger]').forEach((b) => b.addEventListener('click', () => {
        b.classList.toggle('is-active');
        document.getElementById('menu-principal').classList.toggle('is-active');
    }));
})();

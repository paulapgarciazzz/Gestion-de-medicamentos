<?php /* Diálogo de confirmación y aviso temporal (los usa avisos.js y app.js). */ ?>
<dialog id="confirmar-eliminacion" class="modal">
  <div class="modal-background"></div>
  <div class="modal-card">
    <section class="modal-card-body">
      <svg class="icono-confirmacion" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 2.4 17.5A2 2 0 0 0 4.1 20.5h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
      <p class="title is-5">Confirmar acción</p>
      <p id="texto-confirmacion"></p>
    </section>
    <footer class="modal-card-foot">
      <button type="button" class="button" data-cancelar>Cancelar</button>
      <button type="button" id="aceptar-eliminacion" class="button is-danger">Aceptar</button>
    </footer>
  </div>
</dialog>
<div id="aviso-temporal" class="notification aviso-temporal" role="status" hidden><button type="button" class="delete" aria-label="Cerrar"></button><span id="texto-aviso"></span></div>

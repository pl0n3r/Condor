/* Puerta del movimiento del sitio público. Va en el <head>, sin esperar a nada.
   El contenido es visible por defecto: esta puerta solo marca el documento para que el CSS parta del estado
   oculto que marketing-motion.js revela. Si ese script no llega a marcar «listo» a tiempo, todo vuelve a verse. */
(() => {
    const root = document.documentElement;

    if (!window.matchMedia) {
        return;
    }

    root.classList.add('mk-motion');
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        root.classList.add('mk-motion-calm');
    }

    window.setTimeout(() => {
        if (!root.classList.contains('mk-motion-ready')) {
            root.classList.remove('mk-motion', 'mk-motion-calm');
        }
    }, 3000);
})();

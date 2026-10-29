/* Movimiento del sitio público, con GSAP servido desde /lib/gsap.
   Un solo momento de autor: el título de cada página se revela palabra por palabra y su apoyo lo sigue.
   El resto es escalonado corto, una vez, al llegar a la vista. Con «reducir movimiento» solo cambia la opacidad.
   Los selectores de abajo deben coincidir con el estado oculto de marketing.css (html.mk-motion).
   No se usa ScrollTrigger: guarda y restaura estilos con style.cssText, y la política style-src 'self' del
   sitio lo bloquea. Quien decide cuándo entra cada bloque es un IntersectionObserver nativo. */
(() => {
    const root = document.documentElement;
    const giveUp = () => root.classList.remove('mk-motion', 'mk-motion-calm');
    const { gsap, SplitText } = window;

    if (!gsap || !SplitText || !('IntersectionObserver' in window)) {
        giveUp();
        return;
    }

    const EASE = 'expo.out';
    const TITLE = '.mk-site h1';
    const HERO_FOLLOW = '.mk-hero .mk-lead, .mk-hero .mk-cta-row, .mk-hero .mk-micro, .mk-pick, .mk-top .mk-lead';
    const HEADS = '.mk-section .mk-head';
    const ITEMS = '.mk-need, .mk-cat-row, .mk-col, .mk-pillars > div, .mk-steps li, .mk-win';
    const STOPS = '.mk-stop';
    const LINES = '.mk-route';
    const RAIL = '.mk-rail';
    const NARROW = '(max-width: 860px)';

    const staggerFor = (count, each, cap) => ({ amount: Math.min(cap, Math.max(0, count - 1) * each) });

    const revealTitle = (calm, timeline) => {
        const title = document.querySelector(TITLE);
        if (!title) {
            return;
        }
        gsap.set(title, { autoAlpha: 1 });
        const split = SplitText.create(title, { type: 'words' });
        const from = calm
            ? { autoAlpha: 0, duration: 0.4, stagger: 0.02 }
            : { autoAlpha: 0, y: 14, filter: 'blur(3px)', duration: 0.8, stagger: 0.045 };
        timeline.from(split.words, { ...from, clearProps: 'transform,filter' }, 0);
    };

    const revealHeroFollow = (calm, timeline) => {
        const targets = gsap.utils.toArray(HERO_FOLLOW);
        if (targets.length === 0) {
            return;
        }
        timeline.fromTo(
            targets,
            { autoAlpha: 0, y: calm ? 0 : 18 },
            { autoAlpha: 1, y: 0, duration: calm ? 0.4 : 0.7, stagger: 0.1, clearProps: 'transform' },
            calm ? 0.1 : 0.35,
        );
    };

    const revealHeads = (calm, batch) => gsap.fromTo(
        batch,
        { autoAlpha: 0, y: calm ? 0 : 20, filter: calm ? 'none' : 'blur(3px)' },
        { autoAlpha: 1, y: 0, filter: 'blur(0px)', duration: calm ? 0.4 : 0.7, ease: EASE, clearProps: 'transform,filter', overwrite: true },
    );

    const revealItems = (calm, batch) => gsap.fromTo(
        batch,
        { autoAlpha: 0, y: calm ? 0 : 16 },
        { autoAlpha: 1, y: 0, duration: calm ? 0.4 : 0.55, ease: EASE, stagger: staggerFor(batch.length, 0.06, 0.4), clearProps: 'transform', overwrite: true },
    );

    // Cada bloque entra una sola vez, al llegar a la vista. Lo que ya quedó por encima (recarga a mitad de
    // página, ancla) aparece sin animar para no quedarse oculto.
    const revealOnScroll = (calm) => {
        const observer = new IntersectionObserver((entries) => {
            const above = entries.filter((entry) => !entry.isIntersecting && entry.boundingClientRect.bottom < 0);
            const entering = entries.filter((entry) => entry.isIntersecting);
            [...above, ...entering].forEach((entry) => observer.unobserve(entry.target));
            if (above.length > 0) {
                gsap.set(above.map((entry) => entry.target), { autoAlpha: 1 });
            }
            const heads = entering.map((entry) => entry.target).filter((element) => element.matches(HEADS));
            const items = entering.map((entry) => entry.target).filter((element) => !element.matches(HEADS));
            if (heads.length > 0) {
                revealHeads(calm, heads);
            }
            if (items.length > 0) {
                revealItems(calm, items);
            }
        }, { rootMargin: '0px 0px -8% 0px' });

        gsap.utils.toArray(`${HEADS}, ${ITEMS}`).forEach((element) => observer.observe(element));
        return () => observer.disconnect();
    };

    // Plano de red: la línea se dibuja desde «Tu empresa» y sus estaciones aparecen en cadena. La primera línea
    // espera al titular; las demás se dibujan al llegar a la vista. Con «reducir movimiento» solo aparecen.
    const drawLine = (line, calm, delay) => {
        const rail = line.querySelector(RAIL);
        const stops = gsap.utils.toArray(line.querySelectorAll(STOPS));
        const vertical = window.matchMedia(NARROW).matches;
        const isNow = line.classList.contains('mk-route-vender');
        let duration = 0;
        if (!calm) {
            duration = isNow ? 1.1 : 0.8;
        }
        const timeline = gsap.timeline({ delay, defaults: { ease: EASE } });
        if (rail && !calm) {
            timeline.fromTo(rail, vertical ? { scaleY: 0 } : { scaleX: 0 }, { scaleX: 1, scaleY: 1, duration }, 0);
        }
        timeline.fromTo(
            stops,
            { autoAlpha: 0, scale: calm ? 1 : 0.9, x: vertical && !calm ? -8 : 0, y: !vertical && !calm ? 8 : 0, transformOrigin: 'left top' },
            { autoAlpha: 1, scale: 1, x: 0, y: 0, duration: calm ? 0.4 : 0.45, stagger: calm ? 0.04 : { amount: Math.max(0.2, duration * 0.8) }, clearProps: 'transform' },
            calm ? 0 : 0.12,
        );
    };

    const revealNet = (calm) => {
        let firstPaint = true;
        const observer = new IntersectionObserver((entries) => {
            const heroDelay = firstPaint && !calm ? 0.95 : 0;
            firstPaint = false;
            entries.forEach((entry) => {
                const above = !entry.isIntersecting && entry.boundingClientRect.bottom < 0;
                if (!entry.isIntersecting && !above) {
                    return;
                }
                observer.unobserve(entry.target);
                if (above) {
                    gsap.set(entry.target.querySelectorAll(STOPS), { autoAlpha: 1 });
                    gsap.set(entry.target.querySelector(RAIL), { scaleX: 1, scaleY: 1 });
                    return;
                }
                drawLine(entry.target, calm, heroDelay);
            });
        }, { rootMargin: '0px 0px -10% 0px', threshold: 0.15 });

        gsap.utils.toArray(LINES).forEach((line) => observer.observe(line));
        return () => observer.disconnect();
    };

    try {
        gsap.registerPlugin(SplitText);
        gsap.matchMedia().add(
            { full: '(prefers-reduced-motion: no-preference)', calm: '(prefers-reduced-motion: reduce)' },
            (context) => {
                try {
                    const calm = Boolean(context.conditions?.calm);
                    const timeline = gsap.timeline({ defaults: { ease: EASE } });
                    revealTitle(calm, timeline);
                    revealHeroFollow(calm, timeline);
                    const stopScroll = revealOnScroll(calm);
                    const stopNet = revealNet(calm);
                    return () => {
                        stopScroll();
                        stopNet();
                    };
                } catch {
                    // Si algo falla, el sitio se lee igual sin movimiento.
                    giveUp();
                }
            },
        );
        root.classList.add('mk-motion-ready');
    } catch {
        // Si GSAP no arranca, el sitio se lee igual sin movimiento.
        giveUp();
    }
})();

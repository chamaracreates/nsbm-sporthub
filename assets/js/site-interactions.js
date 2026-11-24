/* Header search dialog, administration actions and news image preview. */
(() => {
    'use strict';
    const icon = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>`;
    let dialog, input, results, triggers = [], previousFocus = null, request = null, timer = null;
    const isOpen = () => !!(dialog && !dialog.hidden);
    const clear = () => { while (results.firstChild) results.firstChild.remove(); };
    const status = (message) => {
        clear(); const p = document.createElement('p');
        p.className = 'phase14-search-status'; p.textContent = message; results.append(p);
    };
    const close = () => {
        if (!isOpen()) return;
        dialog.hidden = true; document.body.classList.remove('phase14-search-open');
        triggers.forEach(a => a.setAttribute('aria-expanded', 'false'));
        if (request) request.abort(); clearTimeout(timer);
        if (previousFocus instanceof HTMLElement) previousFocus.focus();
    };
    const open = () => {
        if (!dialog) return;
        if (isOpen()) return input.focus();
        previousFocus = document.activeElement;
        dialog.hidden = false;
        triggers.forEach(a => a.setAttribute('aria-expanded', 'true'));
        document.body.classList.add('phase14-search-open');
        input.value = ''; status('Search clubs, fixtures, tournaments, training, facilities and club news.');
        input.focus();
    };
    async function runQuery(value) {
        if (request) request.abort();
        request = new AbortController();
        try {
            const response = await fetch('/search.php?ajax=1&q=' + encodeURIComponent(value),
                { signal: request.signal, headers: { 'Accept': 'application/json' } });
            if (!response.ok) throw new Error('Search is unavailable');
            const payload = await response.json();
            if (!isOpen() || input.value.trim() !== value || !Array.isArray(payload.results)) return;
            clear();
            if (!payload.results.length) { status('No results found. Try a club name, sport or keyword.'); return; }
            let lastGroup = '';
            for (const result of payload.results) {
                if (result.group !== lastGroup) {
                    const h = document.createElement('h3'); h.className = 'phase14-search-category';
                    h.textContent = result.group; results.append(h); lastGroup = result.group;
                }
                if (typeof result.url !== 'string' || !result.url.startsWith('/') || result.url.startsWith('//')) continue;
                const a = document.createElement('a'); a.href = result.url;
                a.className = 'phase14-search-result';
                const line = document.createElement('span');
                const strong = document.createElement('strong'); strong.textContent = result.title;
                const small = document.createElement('small'); small.textContent = result.detail;
                line.append(strong, small); a.append(line); results.append(a);
            }
            if (payload.partial) {
                const note = document.createElement('p'); note.className = 'phase14-search-status';
                note.textContent = 'Some sections are temporarily unavailable.'; results.append(note);
            }
        } catch (e) {
            if (e.name !== 'AbortError' && isOpen()) status('Search is temporarily unavailable.');
        }
    }
    document.addEventListener('keydown', e => {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault(); e.stopImmediatePropagation();
            isOpen() ? close() : open();
        } else if (e.key === 'Escape' && isOpen()) {
            e.preventDefault(); close();
        }
    }, true);
    document.addEventListener('DOMContentLoaded', () => {
        // Remove decorative icons from actions outside account navigation.
        document.querySelectorAll('.admin-hub-grid .phase13-menu-icon, .phase13-top-actions .phase13-menu-icon, .phase14-training-actions .phase13-menu-icon, .match-management-card-actions .phase13-menu-icon, .button > .phase13-menu-icon')
            .forEach(el => el.remove());
        // Keep one search trigger visible when header breakpoints overlap.
        const syncSearchTriggers = () => {
            const desktop = document.querySelector('.account-nav > .phase13-search-trigger');
            const mobile = document.querySelector('.mobile-controls > .phase13-search-trigger');
            if (!(desktop && mobile)) return;
            desktop.classList.remove('ui-search-duplicate');
            mobile.classList.remove('ui-search-duplicate');
            const displayed = (el) => {
                const parent = el.parentElement;
                return !!parent && getComputedStyle(parent).display !== 'none'
                    && getComputedStyle(parent).visibility !== 'hidden';
            };
            if (displayed(desktop) && displayed(mobile)) {
                if (window.matchMedia('(max-width: 1100px)').matches) {
                    desktop.classList.add('ui-search-duplicate');
                } else {
                    mobile.classList.add('ui-search-duplicate');
                }
            }
        };
        window.addEventListener('resize', syncSearchTriggers, { passive: true });
        syncSearchTriggers();
        triggers = [...document.querySelectorAll('.phase13-search-trigger')];
        for (const a of triggers) {
            a.setAttribute('aria-haspopup', 'dialog');
            a.setAttribute('aria-expanded', 'false');
            a.addEventListener('click', e => { e.preventDefault(); open(); });
        }
        dialog = document.createElement('div');
        dialog.className = 'phase14-search-shell'; dialog.hidden = true;
        dialog.innerHTML = `<div class="phase14-search-backdrop"></div>
          <section class="phase14-search-dialog" role="dialog" aria-modal="true" aria-label="Search SportsHub">
            <form class="phase14-search-field" role="search" autocomplete="off">
              ${icon}<label class="sr-only" for="phase14-site-search">Search SportsHub</label>
              <input id="phase14-site-search" name="q" type="search" maxlength="80" placeholder="Search SportsHub" aria-controls="phase14-search-results" autocomplete="off" />
              <button class="phase14-search-close" type="button" aria-label="Close search" title="Close search"><svg class="search-close-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
            </form>
            <div class="phase14-search-results" id="phase14-search-results" aria-live="polite"></div>
          </section>`;
        document.body.append(dialog);
        input = dialog.querySelector('#phase14-site-search');
        results = dialog.querySelector('#phase14-search-results');
        dialog.querySelector('.phase14-search-backdrop').addEventListener('click', close);
        dialog.querySelector('.phase14-search-close').addEventListener('click', close);
        dialog.querySelector('form').addEventListener('submit', e => {
            e.preventDefault();
            const first = results.querySelector('.phase14-search-result');
            if (first) location.assign(first.href);
        });
        input.addEventListener('input', () => {
            clearTimeout(timer);
            const value = input.value.trim();
            if (value.length < 2) { status(value ? 'Type at least two characters.' : 'Start typing to search public SportsHub content.'); return; }
            status('Searching…'); timer = setTimeout(() => runQuery(value), 200);
        });
        dialog.addEventListener('keydown', e => {
            if (e.key !== 'Tab') return;
            const tabbables = [...dialog.querySelectorAll('input,button,a[href]')].filter(el => el.getClientRects().length);
            if (!tabbables.length) return;
            if (e.shiftKey && document.activeElement === tabbables[0]) { e.preventDefault(); tabbables[tabbables.length - 1].focus(); }
            else if (!e.shiftKey && document.activeElement === tabbables[tabbables.length - 1]) { e.preventDefault(); tabbables[0].focus(); }
        });
        const upload = document.querySelector('#news-image');
        const preview = document.querySelector('#news-image-preview');
        const chosenFilename = document.querySelector('[data-news-file-name]');
        let objectUrl;
        if (upload && preview) upload.addEventListener('change', () => {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            const f = upload.files?.[0];
            if (chosenFilename) chosenFilename.textContent = f ? f.name : 'No image selected';
            if (f && f.type.startsWith('image/')) {
                objectUrl = URL.createObjectURL(f); preview.src = objectUrl; preview.hidden = false;
            } else { preview.hidden = true; preview.removeAttribute('src'); }
        });
    });
})();

/* Measure the homepage header and restore its background during scrolling. */
(() => {
    'use strict';
    const homepage = document.querySelector('main.homepage');
    const header = homepage?.previousElementSibling;
    if (!header?.matches('header.site-header')) return;

    const updateHeight = () => {
        header.style.setProperty('--homepage-header-height', `${header.offsetHeight}px`);
    };
    const updateScrollState = () => {
        header.classList.toggle('is-scrolled', window.scrollY > 16);
    };

    updateHeight();
    updateScrollState();
    header.classList.add('homepage-header');
    window.addEventListener('scroll', updateScrollState, { passive: true });
    window.addEventListener('resize', updateHeight, { passive: true });
    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(updateHeight);
        observer.observe(header);
    }
})();

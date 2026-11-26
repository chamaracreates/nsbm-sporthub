(() => {
    'use strict';
    const root = document.documentElement;
    const key = 'sportshub-theme';
    const buttons = [];
    let theme = 'dark';
    let hero, darkHeroSource;
    const icons = {
        light: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>',
        dark: '<path d="M20.8 14.1A9 9 0 0 1 9.9 3.2 9 9 0 1 0 20.8 14.1Z"/>'
    };
    function apply(value, save = false) {
        theme = value === 'light' ? 'light' : 'dark';
        root.dataset.theme = theme;
        if (hero) {
            const source = theme === 'light' ? hero.dataset.lightSrc : darkHeroSource;
            if (hero.getAttribute('src') !== source) hero.setAttribute('src', source);
        }
        if (save) { try { localStorage.setItem(key, theme); } catch {} }
        const next = theme === 'dark' ? 'light' : 'dark';
        const label = `Switch to ${next} mode`;
        for (const button of buttons) {
            button.setAttribute('aria-label', label);
            button.title = label;
            button.querySelector('svg').innerHTML = icons[next];
        }
    }
    try { apply(localStorage.getItem(key)); } catch { apply('dark'); }
    document.addEventListener('DOMContentLoaded', () => {
        hero = document.querySelector('.hero-section-visual img[data-light-src]');
        if (hero) darkHeroSource = hero.getAttribute('src');
        for (const selector of ['.account-nav', '.mobile-controls', '.admin-header-actions']) {
            const target = document.querySelector(selector);
            if (!target) continue;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'theme-toggle';
            button.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"></svg>';
            button.addEventListener('click', () => apply(theme === 'dark' ? 'light' : 'dark', true));
            target.prepend(button);
            buttons.push(button);
        }
        apply(theme);
    });
    window.addEventListener('storage', event => { if (event.key === key || event.key === null) apply(event.newValue); });
})();

/* Header search dialog, administration actions and news image preview. */
(() => {
    'use strict';
    const icon = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>`;
    let dialog, input, results, adminLayout, triggers = [], previousFocus = null, request = null, timer = null;
    let adminIndex = [];
    const searchLabel = () => adminLayout ? 'Search administration and reports' : 'Search SportsHub';
    const searchHint = () => adminLayout
        ? 'Search management pages, report sections and records displayed on this page.'
        : 'Search clubs, fixtures, tournaments, training, facilities and club news.';
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
        input.value = ''; status(searchHint());
        input.focus();
    };
    function buildAdminIndex() {
        const entries = [];
        const normalize = text => text.trim().replace(/\s+/g, ' ');
        const currentPage = location.pathname + location.search;
        const reportSections = [
            ['report-filters', 'Filter Reports'], ['participation-overview', 'Participation Overview'],
            ['student-participation', 'Student Participation Report'], ['tournament-participation', 'Tournament Participation Report'],
            ['match-registrations', 'Match Registration Report'], ['club-memberships', 'Club Membership Statistics'],
            ['training-attendance', 'Training Attendance Report'], ['match-performance', 'Match Performance Overview and Results']
        ];
        const dashboardSections = [['overview', 'Overview'], ['quick-actions', 'Quick actions'], ['recent-activities', 'Recent Activities']];
        const onReports = !!adminLayout.querySelector('#report-filters');
        const add = (group, title, detail, url) => entries.push({group, title, detail, url});
        for (const link of adminLayout.querySelectorAll('.sidebar-nav a')) {
            add('Administration', normalize(link.textContent), 'Management page', link.getAttribute('href'));
        }
        for (const [title, url] of [
            ['Staff assignments', '/clubs/admin/staff.php'], ['Create MIC or coach account', '/clubs/admin/create-staff.php'],
            ['Club leadership', '/clubs/admin/leaders.php'], ['Club news', '/admin/news.php'],
            ['Review facility bookings', '/bookings/admin/requests.php'], ['Tournament registrations', '/tournaments/registrations.php']
        ]) add('Administration', title, 'Management page', url);
        for (const [id, title] of dashboardSections) {
            add('Dashboard', title, 'Dashboard section', (onReports ? '/admin/dashboard.php' : currentPage) + '#' + id);
        }
        for (const [id, title] of reportSections) {
            add('Reports', title, 'Report section', (onReports ? currentPage : '/reports/index.php') + '#' + id);
        }
        for (const [i, node] of [...adminLayout.querySelectorAll('.stat-card, .activity-item, .report-table tbody tr')].entries()) {
            if (node.matches('tr') && node.querySelector('td[colspan]')) continue;
            const cells = [...node.querySelectorAll('td')];
            const title = normalize(node.querySelector('h3')?.textContent ?? cells[0]?.textContent ?? '');
            if (!title) continue;
            const section = node.closest('section');
            const group = normalize(section?.querySelector('h2')?.textContent ?? 'Records');
            const detail = cells.length ? cells.slice(1).map(cell => normalize(cell.textContent)).join(' · ') : normalize(node.textContent);
            node.id ||= 'search-record-' + (i + 1);
            add('Current page records', title, group + ' · ' + detail, currentPage + '#' + node.id);
        }
        return entries;
    }
    function renderResults(payload) {
        clear();
        if (!payload.results.length) {
            status(adminLayout ? 'No results found. Try a module, report title or displayed record.' : 'No results found. Try a club name, sport or keyword.');
            return;
        }
        let lastGroup = '';
        for (const result of payload.results) {
            if (typeof result.url !== 'string' || !result.url.startsWith('/') || result.url.startsWith('//')) continue;
            if (result.group !== lastGroup) {
                const h = document.createElement('h3'); h.className = 'phase14-search-category';
                h.textContent = result.group; results.append(h); lastGroup = result.group;
            }
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
    }
    async function runQuery(value) {
        if (adminLayout) {
            const words = value.toLowerCase().split(/\s+/);
            const matches = adminIndex.filter(item => {
                const text = `${item.group} ${item.title} ${item.detail}`.toLowerCase();
                return words.every(word => text.includes(word));
            });
            renderResults({results: matches.slice(0, 60)});
            if (matches.length > 60) {
                const note = document.createElement('p'); note.className = 'phase14-search-status';
                note.textContent = 'Showing the first 60 results. Refine your search to see more specific matches.'; results.append(note);
            }
            return;
        }
        if (request) request.abort();
        request = new AbortController();
        try {
            const response = await fetch('/search.php?ajax=1&q=' + encodeURIComponent(value),
                { signal: request.signal, headers: { 'Accept': 'application/json' } });
            if (!response.ok) throw new Error('Search is unavailable');
            const payload = await response.json();
            if (!isOpen() || input.value.trim() !== value || !Array.isArray(payload.results)) return;
            renderResults(payload);
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
        adminLayout = document.querySelector('.admin-layout');
        if (adminLayout) adminIndex = buildAdminIndex();
        triggers = [...document.querySelectorAll('.phase13-search-trigger')];
        for (const a of triggers) {
            a.setAttribute('aria-haspopup', 'dialog');
            a.setAttribute('aria-expanded', 'false');
            a.addEventListener('click', e => { e.preventDefault(); open(); });
        }
        dialog = document.createElement('div');
        dialog.className = 'phase14-search-shell'; dialog.hidden = true;
        dialog.innerHTML = `<div class="phase14-search-backdrop"></div>
          <section class="phase14-search-dialog" role="dialog" aria-modal="true" aria-label="${searchLabel()}">
            <form class="phase14-search-field" role="search" autocomplete="off">
              ${icon}<label class="sr-only" for="phase14-site-search">${searchLabel()}</label>
              <input id="phase14-site-search" name="q" type="search" maxlength="80" placeholder="${searchLabel()}" aria-controls="phase14-search-results" autocomplete="off" />
              <button class="phase14-search-close" type="button" aria-label="Close search" title="Close search"><svg class="search-close-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
            </form>
            <div class="phase14-search-results" id="phase14-search-results" aria-live="polite"></div>
          </section>`;
        document.body.append(dialog);
        input = dialog.querySelector('#phase14-site-search');
        results = dialog.querySelector('#phase14-search-results');
        dialog.querySelector('.phase14-search-backdrop').addEventListener('click', close);
        dialog.querySelector('.phase14-search-close').addEventListener('click', close);
        results.addEventListener('click', e => {
            if (e.target.closest('.phase14-search-result')) close();
        });
        dialog.querySelector('form').addEventListener('submit', e => {
            e.preventDefault();
            const first = results.querySelector('.phase14-search-result');
            if (first) first.click();
        });
        input.addEventListener('input', () => {
            clearTimeout(timer);
            const value = input.value.trim();
            if (value.length < 2) { status(value ? 'Type at least two characters.' : searchHint()); return; }
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
document.addEventListener('DOMContentLoaded', () => {
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
});

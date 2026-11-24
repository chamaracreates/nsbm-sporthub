(() => {
    'use strict';
    document.addEventListener('error', (event) => {
        const image = event.target;
        if (!(image instanceof HTMLImageElement) || image.dataset.fallbackApplied) return;
        if (!image.src.startsWith('https://d3c539pel8wzjz.cloudfront.net/')) return;
        image.dataset.fallbackApplied = 'true';
        image.alt = 'SportsHub representative image';
        image.src = '/assets/images/hero-main.webp';
    }, true);
    document.addEventListener('DOMContentLoaded', () => {
        // Close account popovers on outside clicks and Escape.
        const accountMenus = [...document.querySelectorAll('.site-header .header-profile-menu')];
        document.addEventListener('click', (event) => {
            accountMenus.forEach((menu) => {
                if (menu.open && !menu.contains(event.target)) menu.open = false;
            });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || event.defaultPrevented) return;
            accountMenus.forEach((menu) => {
                if (!menu.open) return;
                menu.open = false;
                if (menu.getClientRects().length) menu.querySelector('summary')?.focus();
                event.preventDefault();
            });
        });
    });
})();

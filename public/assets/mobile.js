(() => {
  'use strict';

  const menuButton = document.getElementById('menu-toggle');
  const menu = document.getElementById('site-navigation');
  const filterButton = document.getElementById('filter-toggle');
  const filters = document.getElementById('catalog-filters');

  // Without JavaScript the menu and filters stay visible and usable.
  if ((menuButton && menu) || (filterButton && filters)) {
    document.documentElement.classList.add('mobile-ready');
  }

  if (menuButton && menu) {
    const setOpen = (open) => {
      menu.classList.toggle('is-open', open);
      menuButton.setAttribute('aria-expanded', String(open));
    };

    menuButton.addEventListener('click', () => {
      setOpen(menuButton.getAttribute('aria-expanded') !== 'true');
    });

    menu.addEventListener('click', (event) => {
      if (event.target.closest('a')) setOpen(false);
    });

    document.addEventListener('pointerdown', (event) => {
      if (!menu.contains(event.target) && !menuButton.contains(event.target)) {
        setOpen(false);
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && menuButton.getAttribute('aria-expanded') === 'true') {
        setOpen(false);
        menuButton.focus();
      }
    });

    const desktop = window.matchMedia('(min-width: 741px)');
    desktop.addEventListener('change', (event) => {
      if (event.matches) setOpen(false);
    });
  }

  if (filterButton && filters) {
    const setOpen = (open) => {
      filters.classList.toggle('is-open', open);
      filterButton.setAttribute('aria-expanded', String(open));
    };

    // Keep selected filters expanded so the user can see/change them.
    setOpen(filters.dataset.hasActive === 'true');
    filterButton.addEventListener('click', () => {
      setOpen(filterButton.getAttribute('aria-expanded') !== 'true');
    });
  }

  const topButton = document.getElementById('to-top');
  if (topButton) {
    const update = () => {
      topButton.hidden = window.scrollY < Math.max(500, window.innerHeight);
    };

    topButton.addEventListener('click', () => {
      const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      window.scrollTo({ top: 0, behavior: reduceMotion ? 'instant' : 'smooth' });
    });
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
  }
})();

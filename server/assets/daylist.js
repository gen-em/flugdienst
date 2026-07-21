/* Einsatztage-Leiste: echtes Akkordeon auf beiden Ebenen.
 * Jahre schliessen sich gegenseitig, und innerhalb eines offenen Jahres
 * schliessen sich auch die Monate gegenseitig — beim Aufklappen eines
 * Elements gehen alle Geschwister derselben Ebene automatisch zu. */
(function () {
  'use strict';

  function verkoppeln(elemente) {
    elemente.forEach(el => {
      el.addEventListener('toggle', () => {
        if (!el.open) { return; }
        elemente.forEach(andere => {
          if (andere !== el) { andere.open = false; }
        });
      });
    });
  }

  const root = document.querySelector('.dayyears');
  if (!root) { return; }

  // Jahre: direkte Kinder von .dayyears
  verkoppeln(Array.from(root.children).filter(el => el.classList.contains('yearblock')));

  // Monate: je Jahr getrennt gruppieren, damit nur die Monate DESSELBEN
  // Jahres sich gegenseitig schliessen
  root.querySelectorAll('.yearblock').forEach(jahr => {
    verkoppeln(Array.from(jahr.querySelectorAll(':scope > .monthblock')));
  });
})();

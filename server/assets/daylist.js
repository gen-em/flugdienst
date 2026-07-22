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

  // Klick auf die Beschriftung oeffnet die Zeitraum-Uebersicht, Klick auf das
  // Dreieck klappt nur auf/zu. Ohne diese Trennung wuerde der Browser beim
  // Anklicken des Links zusaetzlich das <details> umschalten.
  root.querySelectorAll('summary').forEach(sum => {
    sum.addEventListener('click', ev => {
      const link = ev.target.closest('a.zeitlink');
      if (link) {
        ev.preventDefault();          // kein Auf-/Zuklappen
        window.location.href = link.href;
      }
    });
  });

  // Jahre: direkte Kinder von .dayyears
  verkoppeln(Array.from(root.children).filter(el => el.classList.contains('yearblock')));

  // Monate: je Jahr getrennt gruppieren, damit nur die Monate DESSELBEN
  // Jahres sich gegenseitig schliessen
  root.querySelectorAll('.yearblock').forEach(jahr => {
    verkoppeln(Array.from(jahr.querySelectorAll(':scope > .monthblock')));
  });
})();

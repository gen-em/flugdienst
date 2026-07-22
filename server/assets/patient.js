/* Geschuetzte PatientInnendaten — gemeinsame Hilfsfunktionen.
 *
 * Diese Datei rechnet nur; sie sieht die Daten erst, nachdem crypto.js sie
 * entschluesselt hat. Eingebunden von index.php, einsatz.php, einsatz_form.php
 * und zeitraum.php, damit alle vier dieselbe Altersberechnung verwenden.
 */
(function () {
  'use strict';

  /**
   * Alter zum Einsatzzeitpunkt aus dem Geburtsdatum.
   * @param {string} dob   Geburtsdatum "JJJJ-MM-TT"
   * @param {string} tag   Einsatztag "JJJJ-MM-TT" (ohne Angabe: heute)
   * @returns {number|null} volle Lebensjahre, oder null bei ungueltiger Eingabe
   */
  function alterAm(dob, tag) {
    if (!dob || !/^\d{4}-\d{2}-\d{2}$/.test(dob)) { return null; }
    const g = new Date(dob + 'T00:00:00');
    const b = (tag && /^\d{4}-\d{2}-\d{2}$/.test(tag))
      ? new Date(tag + 'T00:00:00') : new Date();
    if (isNaN(g.getTime()) || isNaN(b.getTime()) || g > b) { return null; }

    let jahre = b.getFullYear() - g.getFullYear();
    // Geburtstag im Bezugsjahr noch nicht erreicht? Dann ein Jahr abziehen.
    const vorGeburtstag = (b.getMonth() < g.getMonth())
      || (b.getMonth() === g.getMonth() && b.getDate() < g.getDate());
    if (vorGeburtstag) { jahre--; }
    return jahre >= 0 && jahre <= 130 ? jahre : null;
  }

  /**
   * Anzuzeigendes Alter: bevorzugt aus dem Geburtsdatum berechnet, sonst der
   * von Hand eingetragene Wert (z. B. geschaetzt bei unbekannter Person).
   */
  function alterAnzeige(pat, tag) {
    if (!pat) { return null; }
    const berechnet = alterAm(pat.dob, tag);
    if (berechnet !== null) { return berechnet; }
    return (pat.age != null) ? pat.age : null;
  }

  /** "Nachname, Vorname" — je nachdem, was vorhanden ist. */
  function name(pat) {
    if (!pat) { return ''; }
    const n = (pat.last || '').trim();
    const v = (pat.first || '').trim();
    if (n && v) { return n + ', ' + v; }
    return n || v || '';
  }

  /** "JJJJ-MM-TT" -> "TT.MM.JJJJ" */
  function datumDe(iso) {
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) { return ''; }
    const [j, m, t] = iso.split('-');
    return `${t}.${m}.${j}`;
  }

  window.EdPat = { alterAm, alterAnzeige, name, datumDe };
})();

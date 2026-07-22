<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/ui.php';   // auth_guard.php laedt sie bereits

/**
 * Alle Einsaetze eines Jahres oder Monats als Tabelle — bewusst ohne Karte,
 * ohne Farbmarkierung und ohne Tagesnummer, dafuer mit Datum. Die Daten holt
 * der Browser von api/range.php und entschluesselt die geschuetzten Angaben
 * selbst (wie auf der Tagesuebersicht).
 */

$jahr  = (string)($_GET['y'] ?? '');
$monat = (string)($_GET['m'] ?? '');
if (!preg_match('/^\d{4}$/', $jahr)) { header('Location: index.php'); exit; }
if ($monat !== '' && !preg_match('/^\d{2}$/', $monat)) { $monat = ''; }

$MONATSNAMEN = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
    'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
$titel = $monat !== ''
    ? $MONATSNAMEN[(int)$monat] . ' ' . $jahr
    : 'Jahr ' . $jahr;
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($titel) ?> · Einsatzdoku</title>
  <link rel="stylesheet" href="assets/style.css">
  <link rel="icon" type="image/png" href="assets/favicon.png">
</head>
<body>
<?php ui_topbar('uebersicht'); ?>
<div class="layout">
  <?php ui_days_sidebar(null); ?>

  <main class="page">
    <h1><?= e($titel) ?></h1>
    <p class="muted" id="summary">wird geladen …</p>
    <div id="loaderror" class="alert" hidden></div>

    <p id="lockbanner" class="alert alert-info" hidden>
      Geschützte Angaben sind gesperrt — bitte neu anmelden, um Einsatzort,
      Alter und Diagnose zu sehen.
    </p>

    <table class="data" id="rangetable">
      <thead><tr>
        <th class="sortable c-date" data-key="day">Datum</th>
        <th class="sortable c-mid"  data-key="start">Beginn</th>
        <th class="sortable c-mid"  data-key="dur">Dauer</th>
        <th class="sortable"        data-key="site">Einsatzort</th>
        <th class="sortable c-mid"  data-key="age">Alter</th>
        <th class="sortable"        data-key="dx">Diagnose</th>
        <th class="sortable c-winde" data-key="winch">Winde</th>
        <th class="sortable c-bw"    data-key="bw">Bergwacht</th>
        <th class="sortable c-sek"   data-key="sec">Sekundär<br>Transport</th>
        <th class="sortable c-mid"   data-key="km">Flug&nbsp;km</th>
      </tr></thead>
      <tbody id="rangebody"></tbody>
    </table>
    <p id="leer" class="muted" hidden>In diesem Zeitraum sind keine Einsätze erfasst.</p>
  </main>
</div>

<script src="assets/crypto.js"></script>
<script>
const JAHR  = <?= json_encode($jahr) ?>;
const MONAT = <?= json_encode($monat) ?>;
const PAT_WRAP = <?= json_encode($patWrapPw) ?>;

let missions = [];
let sortKey = 'day', sortAsc = true;

function esc(t){ const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
function fmtTag(iso){ const [y,m,d] = iso.split('-'); return `${d}.${m}.${y}`; }
function fmtDur(s){ if(s==null) return 'kein Ende'; const h=Math.floor(s/3600),m=Math.round(s%3600/60);
  return h? `${h}h ${String(m).padStart(2,'0')}min` : `${m}min`; }
function fmtKm(m){ return m==null ? '<span class="dash">–</span>' : (m/1000).toFixed(1).replace('.',',')+' km'; }
function extractOrt(addr){
  const parts = addr.split(',');
  let last = parts[parts.length - 1].trim();
  return last.replace(/^\d{4,5}\s+/, '');
}

function sortWert(m, key){
  switch(key){
    case 'day':   return m.day;
    case 'start': return m.start_hhmm;
    case 'dur':   return m.duration_s == null ? -1 : m.duration_s;
    case 'site':  return (m._ort || '').toLowerCase();
    case 'age':   return m._age == null ? -1 : m._age;
    case 'dx':    return (m._dx || '').toLowerCase();
    case 'winch': return m.winch ? 1 : 0;
    case 'bw':    return m.bergwacht ? 1 : 0;
    case 'sec':   return m.secondary ? 1 : 0;
    case 'km':    return m.distance_m == null ? -1 : m.distance_m;
  }
  return '';
}

function zeichne(){
  const tb = document.getElementById('rangebody');
  tb.innerHTML = '';
  const sortiert = missions.slice().sort((a,b) => {
    const x = sortWert(a, sortKey), y = sortWert(b, sortKey);
    const r = (x > y) - (x < y);
    return sortAsc ? r : -r;
  });
  sortiert.forEach(m => {
    const tr = document.createElement('tr');
    tr.className = 'clickable';
    tr.innerHTML =
      `<td class="mono c-date">${fmtTag(m.day)}</td>
       <td class="mono c-mid">${m.start_hhmm}</td>
       <td class="c-mid">${fmtDur(m.duration_s)}</td>
       <td${m._ort ? '' : ' class="dash"'}>${m._ort ? esc(m._ort) : '–'}</td>
       <td class="mono c-mid${m._age != null ? '' : ' dash'}">${m._age != null ? m._age : '–'}</td>
       <td${m._dx ? '' : ' class="dash"'}>${m._dx ? esc(m._dx) : '–'}</td>
       <td class="checkcol c-winde">${m.winch ? '✓' : ''}</td>
       <td class="checkcol c-bw">${m.bergwacht ? '✓' : ''}</td>
       <td class="checkcol c-sek">${m.secondary ? '✓' : ''}</td>
       <td class="mono c-mid">${fmtKm(m.distance_m)}</td>`;
    tr.addEventListener('click', () => { location.href = 'einsatz.php?id=' + m.id; });
    tb.appendChild(tr);
  });
  document.getElementById('leer').hidden = missions.length > 0;
  document.getElementById('rangetable').hidden = missions.length === 0;
}

document.querySelectorAll('#rangetable th.sortable').forEach(th => {
  th.addEventListener('click', () => {
    const k = th.dataset.key;
    if (sortKey === k) { sortAsc = !sortAsc; } else { sortKey = k; sortAsc = true; }
    document.querySelectorAll('#rangetable th .arrow').forEach(a => a.remove());
    const pfeil = document.createElement('span');
    pfeil.className = 'arrow';
    pfeil.textContent = sortAsc ? ' ▲' : ' ▼';
    th.appendChild(pfeil);
    zeichne();
  });
});

function zeigeFehler(msg){
  const box = document.getElementById('loaderror');
  box.textContent = 'Die Daten konnten nicht geladen werden: ' + msg;
  box.hidden = false;
  document.getElementById('summary').textContent = '';
}

(async () => {
  let d;
  try {
    const url = 'api/range.php?y=' + encodeURIComponent(JAHR)
              + (MONAT ? '&m=' + encodeURIComponent(MONAT) : '');
    const res = await fetch(url);
    const txt = await res.text();
    try { d = JSON.parse(txt); }
    catch (e) {
      zeigeFehler(txt.replace(/<[^>]*>/g, ' ').trim().slice(0, 300) || ('HTTP ' + res.status));
      return;
    }
    if (d.error) { zeigeFehler(d.error); return; }
  } catch (e) { zeigeFehler(e.message); return; }

  missions = d.missions;
  const km = missions.reduce((s, m) => s + (m.distance_m || 0), 0);
  document.getElementById('summary').textContent =
    `${missions.length} Einsätze an ${d.tage} Flugtagen · ${(km/1000).toFixed(1).replace('.', ',')} km`;
  zeichne();

  if (PAT_WRAP) {
    const ck = await EdCrypto.getContentKey(PAT_WRAP);
    const banner = document.getElementById('lockbanner');
    if (!ck) { banner.hidden = !missions.some(m => m.pat_blob); return; }
    banner.hidden = true;
    let geaendert = false;
    for (const m of missions) {
      if (!m.pat_blob) continue;
      try {
        const o = JSON.parse(await EdCrypto.decrypt(ck, m.pat_blob)) || {};
        if (o.dx != null)  { m._dx = o.dx; geaendert = true; }
        if (o.age != null) { m._age = o.age; geaendert = true; }
        if (o.loc && o.loc.addr) { m._ort = extractOrt(o.loc.addr); geaendert = true; }
      } catch (e) { /* einzelner Datensatz nicht lesbar: Rest trotzdem zeigen */ }
    }
    if (geaendert) { zeichne(); }
  }
})();
</script>
<?php ui_footer(); ?>
</body>
</html>

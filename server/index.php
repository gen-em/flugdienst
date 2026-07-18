<?php
declare(strict_types=1);
// Noch nicht eingerichtet? -> Installer starten (erledigt sich nach 1x selbst).
if (!file_exists(__DIR__ . '/config.php')) { header('Location: install.php'); exit; }
require_once __DIR__ . '/auth_guard.php';
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tagesübersicht — Einsatzdoku</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/png" href="assets/favicon.png">
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php"><img src="assets/logo-weiss.png" alt="GenEM Einsatzdoku"></a>
  <nav>
    <a class="active" href="index.php">Übersicht</a>
    <?php if ($userRole === 'admin'): ?><a href="admin.php">Verwaltung</a><?php endif; ?>
    <a href="geraete.php">Geräte</a> <a href="logout.php">Abmelden</a>
  </nav>
</header>

<div class="layout">
  <aside class="daylist">
    <h2>Einsatztage</h2>
    <ul id="days"></ul>
  </aside>

  <main class="page">
    <h1 id="daytitle">–</h1>
    <details class="daymeta" id="daymeta">
      <summary>Flugtag-Daten <span id="metahint" class="muted"></span></summary>
      <form id="dayform" class="meta-form">
        <label>Maschine <input name="aircraft" maxlength="64" placeholder="z. B. Christoph 17 / H145"></label>
        <label>Basis / Standort <input name="base" maxlength="64" placeholder="z. B. Kempten"></label>
        <label>Besatzung <input name="crew" maxlength="190" placeholder="z. B. Pilot, HEMS-TC, Notarzt"></label>
        <label>Notizen <textarea name="notes" rows="3" maxlength="2000"></textarea></label>
        <button type="submit" class="btn-primary">Speichern</button>
        <span id="savestate" class="muted"></span>
      </form>
    </details>
    <div id="map" class="map"></div>
    <table class="data" id="missions">
      <thead><tr><th></th><th>Beginn</th><th>Dauer</th><th>Kilometer</th></tr></thead>
      <tbody></tbody>
    </table>
    <p id="empty" class="muted" hidden>Für diesen Tag sind keine Einsätze dokumentiert.</p>
    <p><a href="einsatz_form.php" id="addmission" class="add-link">+ Einsatz nachtragen</a></p>
  </main>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const CSRF = '<?= e($_SESSION['csrf']) ?>';
const COLORS = ['#FF8F1F','#4280E5','#D63338','#1A2E4D','#0C8599','#9C36B5','#2F9E44','#8A5A00'];
let currentDay = null;

const map = L.map('map');
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
map.setView([48.5, 10.5], 7); // Fallback, bis Daten da sind

let layerGroup = L.layerGroup().addTo(map);

function fmtDay(iso){ const [y,m,d]=iso.split('-'); return `${d}.${m}.${y}`; }
function fmtDur(s){ if(s==null) return 'läuft'; const h=Math.floor(s/3600),m=Math.round(s%3600/60);
  return h? `${h} h ${String(m).padStart(2,'0')} min` : `${m} min`; }
function fmtKm(m){ return m==null ? '–' : (m/1000).toFixed(1).replace('.',',')+' km'; }

async function loadDay(day){
  const res = await fetch('api/day.php?day='+encodeURIComponent(day));
  const d = await res.json();
  currentDay = d.day;
  document.getElementById('daytitle').textContent = 'Betriebstag ' + fmtDay(d.day);

  // Flugtag-Felder befuellen
  const f = document.getElementById('dayform');
  ['aircraft','base','crew','notes'].forEach(k => {
    f.elements[k].value = (d.meta && d.meta[k]) ? d.meta[k] : '';
  });
  const filled = d.meta && [d.meta.aircraft, d.meta.base, d.meta.crew].filter(Boolean);
  document.getElementById('metahint').textContent =
    filled && filled.length ? '— ' + filled.join(' · ') : '';
  document.getElementById('savestate').textContent = '';
  document.getElementById('addmission').href = 'einsatz_form.php?day=' + d.day;

  layerGroup.clearLayers();
  const bounds = [];

  // Ruhe-Track: schwarz, dezent
  d.rest_segments.forEach(seg => {
    if (seg.length > 1) {
      layerGroup.addLayer(L.polyline(seg, { color:'#1A0500', weight:2, opacity:0.55 }));
      seg.forEach(p => bounds.push(p));
    }
  });

  // Einsaetze: je eigene Farbe
  const tbody = document.querySelector('#missions tbody');
  tbody.innerHTML = '';
  d.missions.forEach((m,i) => {
    const col = COLORS[i % COLORS.length];
    if (m.track.length > 1) {
      layerGroup.addLayer(L.polyline(m.track, { color: col, weight: 4 }));
      m.track.forEach(p => bounds.push(p));
    }
    const tr = document.createElement('tr');
    tr.innerHTML = `<td><span class="swatch" style="background:${col}"></span></td>
      <td class="mono">${m.start_hhmm}</td>
      <td>${fmtDur(m.duration_s)}</td>
      <td class="mono">${fmtKm(m.distance_m)}</td>`;
    tr.addEventListener('click', () => location.href = 'einsatz.php?id=' + m.id);
    tbody.appendChild(tr);
  });
  document.getElementById('empty').hidden = d.missions.length > 0;
  document.getElementById('missions').hidden = d.missions.length === 0;

  // Auto-Zoom: Track soll ca. 75 % der Karte einnehmen -> ~12.5 % Rand je Seite
  if (bounds.length) {
    const px = map.getSize();
    map.fitBounds(L.latLngBounds(bounds),
      { padding: [px.y * 0.125, px.x * 0.125] });
  }
}

async function init(){
  document.getElementById('dayform').addEventListener('submit', async ev => {
    ev.preventDefault();
    if (!currentDay) return;
    const f = ev.target;
    const body = { day: currentDay };
    ['aircraft','base','crew','notes'].forEach(k => body[k] = f.elements[k].value);
    const state = document.getElementById('savestate');
    state.textContent = 'Speichern…';
    const res = await fetch('api/day.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
      body: JSON.stringify(body)
    });
    state.textContent = res.ok ? 'Gespeichert.' : 'Fehler beim Speichern.';
    if (res.ok) loadDay(currentDay);
  });
  const res = await fetch('api/day.php');
  const d = await res.json();
  const ul = document.getElementById('days');
  d.days.forEach(day => {
    const li = document.createElement('li');
    const a = document.createElement('a');
    a.href = '#'; a.textContent = fmtDay(day);
    a.addEventListener('click', ev => { ev.preventDefault();
      document.querySelectorAll('#days a').forEach(x => x.classList.remove('active'));
      a.classList.add('active'); loadDay(day); });
    li.appendChild(a); ul.appendChild(li);
  });
  if (d.latest) { ul.querySelector('a')?.classList.add('active'); loadDay(d.latest); }
  else document.getElementById('daytitle').textContent = 'Noch keine Daten';
}
init();
</script>
<footer class="sitefooter">© Gen-EM · <a href="https://github.com/gen-em/einsatzdoku-luftrettung/blob/main/LICENSE" target="_blank" rel="noopener">AGPL-3.0</a></footer>
</body>
</html>

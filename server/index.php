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
</head>
<body>
<header class="topbar">
  <span class="brand">Einsatzdoku</span>
  <nav>
    <a class="active" href="index.php">Übersicht</a>
    <?php if ($userRole === 'admin'): ?><a href="admin.php">Verwaltung</a><?php endif; ?>
    <a href="logout.php">Abmelden</a>
  </nav>
</header>

<div class="layout">
  <aside class="daylist">
    <h2>Einsatztage</h2>
    <ul id="days"></ul>
  </aside>

  <main class="page">
    <h1 id="daytitle">–</h1>
    <div id="map" class="map"></div>
    <table class="data" id="missions">
      <thead><tr><th></th><th>Beginn</th><th>Dauer</th><th>Kilometer</th></tr></thead>
      <tbody></tbody>
    </table>
    <p id="empty" class="muted" hidden>Für diesen Tag sind keine Einsätze dokumentiert.</p>
  </main>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const COLORS = ['#E8590C','#1971C2','#2F9E44','#9C36B5','#E03131','#F08C00','#0C8599','#6741D9'];

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
  document.getElementById('daytitle').textContent = 'Betriebstag ' + fmtDay(d.day);

  layerGroup.clearLayers();
  const bounds = [];

  // Ruhe-Track: schwarz, dezent
  d.rest_segments.forEach(seg => {
    if (seg.length > 1) {
      layerGroup.addLayer(L.polyline(seg, { color:'#111', weight:2, opacity:0.55 }));
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
</body>
</html>

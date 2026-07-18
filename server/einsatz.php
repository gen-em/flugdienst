<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
$id = (int)($_GET['id'] ?? 0);
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Einsatz — Einsatzdoku</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" type="image/png" href="assets/favicon.png">
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php"><img src="assets/logo-weiss.png" alt="GenEM Einsatzdoku"></a>
  <nav><a href="index.php">Übersicht</a> <a href="geraete.php">Geräte</a> <a href="logout.php">Abmelden</a></nav>
</header>

<main class="page">
  <h1 id="title">Einsatz</h1>
  <p id="meta" class="muted"></p>
  <dl id="fieldlist" class="fieldlist" hidden></dl>
  <div id="map" class="map map-tall"></div>

  <section>
    <h2>Phasen</h2>
    <table class="data" id="phases">
      <thead><tr><th>Nr.</th><th>Phase</th><th>Uhrzeit</th></tr></thead>
      <tbody></tbody>
    </table>
  </section>

  <section id="resus-section" hidden>
    <div id="resus-tables"></div>
  </section>
</main>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const map = L.map('map');
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
map.setView([48.5, 10.5], 7);

function fmtKm(m){ return m==null ? '–' : (m/1000).toFixed(1).replace('.',',')+' km'; }
function fmtDay(iso){ const [y,m,d]=iso.split('-'); return `${d}.${m}.${y}`; }

async function init(){
  const res = await fetch('api/mission.php?id=<?= $id ?>');
  if (!res.ok) { document.getElementById('title').textContent = 'Einsatz nicht gefunden'; return; }
  const m = await res.json();

  document.getElementById('title').textContent = `Einsatz ${m.start_hhmm} Uhr`;
  document.getElementById('meta').innerHTML =
    `${fmtDay(m.day)} · ${m.start_hhmm}–${m.end_hhmm} Uhr · ${fmtKm(m.distance_m)}`
    + (m.ascent_m != null ? ` · ${m.ascent_m} Hm` : '')
    + (m.manual ? ' · <span class="badge-manual">manuell</span>' : '')
    + ` &nbsp; <a class="btn-edit" href="einsatz_form.php?id=${m.id}">Bearbeiten</a>`;

  // Zusatzfelder generisch anzeigen (Definition: mission_fields.php)
  if (m.fields && m.fields.length) {
    const dl = document.getElementById('fieldlist');
    dl.hidden = false;
    m.fields.forEach(f => {
      const dt = document.createElement('dt'); dt.textContent = f.label;
      const dd = document.createElement('dd'); dd.textContent = f.value;
      dl.append(dt, dd);
    });
  }

  if (m.track.length > 1) {
    const line = L.polyline(m.track, { color:'#FF8F1F', weight:4 }).addTo(map);
    const px = map.getSize();
    map.fitBounds(line.getBounds(), { padding: [px.y*0.125, px.x*0.125] });
    L.circleMarker(m.track[0], {radius:6, color:'#2F9E44', fillOpacity:1}).addTo(map).bindTooltip('Start');
    L.circleMarker(m.track[m.track.length-1], {radius:6, color:'#E03131', fillOpacity:1}).addTo(map).bindTooltip('Ende');
  }

  const pb = document.querySelector('#phases tbody');
  m.phases.forEach(p => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td><span class="chip">${p.phase}</span></td><td>${p.label}</td><td class="mono">${p.time}</td>`;
    pb.appendChild(tr);
  });

  if (m.resus && m.resus.length) {
    document.getElementById('resus-section').hidden = false;
    const wrap = document.getElementById('resus-tables');
    m.resus.forEach((session, idx) => {
      const h = document.createElement('h2');
      h.textContent = m.resus.length > 1 ? `Reanimation ${idx + 1}` : 'Reanimation';
      wrap.appendChild(h);
      const table = document.createElement('table');
      table.className = 'data';
      table.innerHTML = '<thead><tr><th>Ereignis</th><th>Uhrzeit</th></tr></thead><tbody></tbody>';
      const tb = table.querySelector('tbody');
      session.forEach(e => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${e.label}</td><td class="mono">${e.time}</td>`;
        tb.appendChild(tr);
      });
      wrap.appendChild(table);
    });
  }
}
init();
</script>
<footer class="sitefooter">© Gen-EM · <a href="https://github.com/gen-em/einsatzdoku-luftrettung/blob/main/LICENSE" target="_blank" rel="noopener">AGPL-3.0</a></footer>
</body>
</html>

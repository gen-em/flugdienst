<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';

// Einsatz-ID einlesen und Eigentum pruefen (liefert auch den Tag fuer die
// Einsatztage-Leiste). Ohne Treffer: sauberes 404.
$mid = (int)($_GET['id'] ?? 0);
$mq = db()->prepare('SELECT day FROM missions WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
$mq->execute([$mid, $userId]);
$missionDay = $mq->fetchColumn();
if ($missionDay === false) { http_response_code(404); exit('Einsatz nicht gefunden.'); }
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
<?php ui_topbar('uebersicht'); ?>

<div class="layout">
  <?php ui_days_sidebar($missionDay); ?>

  <main class="page">
  <div class="pagehead">
    <div class="pagehead-text">
      <h1 id="title">Einsatz</h1>
      <p id="meta" class="muted"></p>
    </div>
    <div class="pagehead-actions">
      <a class="btn-edit" href="einsatz_form.php?id=<?= $mid ?>">Bearbeiten</a>
      <a class="btn-red" href="einsatz_loeschen.php?id=<?= $mid ?>">Löschen</a>
    </div>
  </div>

  <dl class="fieldlist" id="fieldlist" hidden></dl>

  <div id="map" class="map map-tall"></div>
  <p><button id="phasetoggle" class="btn-danger" hidden>Phasen ausblenden</button></p>

  <section>
    <h2>Phasen</h2>
    <table class="data" id="phases">
      <thead><tr><th>Nr.</th><th>Phase</th><th>Uhrzeit</th></tr></thead>
      <tbody id="phasebody"></tbody>
    </table>
  </section>

  <section id="resus-section" hidden>
    <div id="resus-tables"></div>
  </section>

  <?php ui_footer(); ?>
  </main>
</div>

<script src="assets/crypto.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const MID = <?= $mid ?>;

function esc(t){ const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
function fmtDay(d){ const p = d.split('-'); return `${p[2]}.${p[1]}.${p[0]}`; }
function fmtKm(m){ return m == null ? '–' : (m / 1000).toFixed(1).replace('.', ',') + ' km'; }

const map = L.map('map');
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);

// Tracklinien: Staerke waechst beim Rauszoomen, damit kurze Tracks auf der
// Uebersicht sichtbar bleiben (smoothFactor 0: keine Wegvereinfachung).
const trackLines = [];
// Einsatzort als klassischer Karten-Pin in der Einsatzfarbe (SVG-DivIcon)
function locPin(color){
  return L.divIcon({
    className: 'locpin',
    html: `<svg width="30" height="42" viewBox="0 0 30 42" xmlns="http://www.w3.org/2000/svg">
      <path d="M15 1C7.3 1 1 7.2 1 14.9 1 25.4 15 41 15 41s14-15.6 14-26.1C29 7.2 22.7 1 15 1z"
            fill="${color}" stroke="#fff" stroke-width="2"/>
      <circle cx="15" cy="14.5" r="5" fill="#fff"/></svg>`,
    iconSize: [30, 42], iconAnchor: [15, 41], popupAnchor: [0, -34]
  });
}

function trackWeight(){
  const z = map.getZoom();
  return z >= 14 ? 4 : z >= 12 ? 5 : z >= 10 ? 6 : 7;
}
map.on('zoomend', () => {
  const w = trackWeight();
  trackLines.forEach(l => l.setStyle({ weight: w }));
});

let phaseMarkers = [];        // [{marker, idx}]
let phasesVisible = true;

function buildPhaseMarkers(phases){
  // Kachel an der GPS-Position des Zeitstempels (nur wo die Uhr Fix hatte);
  // gestapelte gleiche Positionen leicht nebeneinander versetzt.
  const groups = {};
  phases.forEach((p, idx) => {
    if (p.lat == null || p.lon == null) return;
    const key = p.lat.toFixed(4) + ',' + p.lon.toFixed(4);
    (groups[key] = groups[key] || []).push({ p, idx });
  });
  Object.values(groups).forEach(list => {
    list.forEach((e2, k) => {
      const icon = L.divIcon({
        className: 'phase-marker',
        html: `<span class="chip pm-chip" data-idx="${e2.idx}">${e2.p.phase}</span>`,
        iconSize: [24, 24],
        iconAnchor: [12 - k * 20, 12]
      });
      const mk = L.marker([e2.p.lat, e2.p.lon], { icon, keyboard: false }).addTo(map);
      phaseMarkers.push({ marker: mk, idx: e2.idx });
      bindMarkerHover(mk, e2.idx);
    });
  });
  const btn = document.getElementById('phasetoggle');
  if (phaseMarkers.length) {
    btn.hidden = false;
    btn.addEventListener('click', () => {
      phasesVisible = !phasesVisible;
      phaseMarkers.forEach(e2 => phasesVisible ? e2.marker.addTo(map) : map.removeLayer(e2.marker));
      btn.textContent = phasesVisible ? 'Phasen ausblenden' : 'Phasen anzeigen';
    });
  }
}

function bindMarkerHover(mk, idx){
  const el = mk.getElement();
  if (!el) return;
  el.addEventListener('mouseenter', () => hlPhase(idx, true));
  el.addEventListener('mouseleave', () => hlPhase(idx, false));
  el.addEventListener('click', () => hlPhase(idx, 'toggle'));
}

let hlActive = {};
function hlPhase(idx, on){
  if (on === 'toggle') { on = !hlActive[idx]; }
  hlActive[idx] = on;
  const row = document.querySelector(`#phasebody tr[data-idx="${idx}"]`);
  if (row) row.classList.toggle('hl', on);
  const chip = document.querySelector(`.pm-chip[data-idx="${idx}"]`);
  if (chip) {
    chip.classList.toggle('hl', on);
    const pm = phaseMarkers.find(e2 => e2.idx === idx);
    if (pm) pm.marker.setZIndexOffset(on ? 1000 : 0);
  }
}

async function init(){
  const res = await fetch('api/mission.php?id=' + MID);
  if (!res.ok) { document.getElementById('title').textContent = 'Einsatz nicht gefunden'; return; }
  const m = await res.json();

  document.getElementById('title').textContent =
    `Einsatz ${m.day_no} · ${m.start_hhmm} Uhr`;
  document.getElementById('meta').innerHTML =
    esc(`${fmtDay(m.day)} · ${m.start_hhmm}–${m.has_p9 ? m.end_hhmm : 'kein Ende'} Uhr · ${fmtKm(m.distance_m)}`)
    + (m.manual ? ' · <span class="badge-manual">manuell</span>' : '');

  // Zusatzfelder (Server liefert nur befuellte)
  const dl = document.getElementById('fieldlist');
  m.fields.forEach(f => {
    dl.insertAdjacentHTML('beforeend', `<dt>${esc(f.label)}</dt><dd>${esc(f.value)}</dd>`);
  });
  dl.hidden = dl.children.length === 0;

  // Karte: Track (Start gruen, Ende rot), Einsatzort-Pin in Trackfarbe
  const bounds = [];
  if (m.track.length > 1) {
    const line = L.polyline(m.track, { color: '#FF8F1F', weight: trackWeight(), smoothFactor: 0 }).addTo(map);
    trackLines.push(line);
    L.circleMarker(m.track[0], { radius: 6, color: '#1B8A3A', fillColor: '#1B8A3A', fillOpacity: 1 })
      .addTo(map).bindPopup('Start');
    L.circleMarker(m.track[m.track.length - 1], { radius: 6, color: '#C62828', fillColor: '#C62828', fillOpacity: 1 })
      .addTo(map).bindPopup('Ende');
    m.track.forEach(p => bounds.push(p));
  }
  if (bounds.length) { map.fitBounds(bounds, { padding: [30, 30] }); }
  else { map.setView([47.7, 10.3], 9); document.getElementById('map').classList.add('map-empty'); }

  // Phasen-Tabelle mit Hover-/Tipp-Kopplung zur Karte
  const pb = document.getElementById('phasebody');
  m.phases.forEach((p, idx) => {
    const tr = document.createElement('tr');
    tr.dataset.idx = idx;
    tr.innerHTML = `<td><span class="chip">${p.phase}</span></td><td>${esc(p.label)}</td><td class="mono">${p.time}</td>`;
    tr.addEventListener('mouseenter', () => hlPhase(idx, true));
    tr.addEventListener('mouseleave', () => hlPhase(idx, false));
    tr.addEventListener('click', () => hlPhase(idx, 'toggle'));
    pb.appendChild(tr);
  });
  // Phasen-Marker vorerst deaktiviert (auf Wunsch; Code bleibt fuer spaeter)
  // buildPhaseMarkers(m.phases);

  // Reanimationen: eine Zeiten-Tabelle je Sitzung
  if (m.resus && m.resus.length) {
    document.getElementById('resus-section').hidden = false;
    const wrap = document.getElementById('resus-tables');
    m.resus.forEach((events, i) => {
      const h = document.createElement('h2');
      h.textContent = m.resus.length > 1 ? `Reanimation ${i + 1}` : 'Reanimation';
      wrap.appendChild(h);
      const t = document.createElement('table');
      t.className = 'data';
      t.innerHTML = '<thead><tr><th>Ereignis</th><th>Uhrzeit</th></tr></thead>';
      const tb = document.createElement('tbody');
      events.forEach(e2 => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${esc(e2.label)}</td><td class="mono">${e2.time}</td>`;
        tb.appendChild(tr);
      });
      t.appendChild(tb);
      wrap.appendChild(t);
    });
  }

  // Verschluesselte Angaben (Diagnose, Alter, Einsatzort) lokal entschluesseln
  if (m.pat_blob && m.pat_wrap) {
    const ck = await EdCrypto.getContentKey(m.pat_wrap);
    if (ck) {
      try {
        const o = JSON.parse(await EdCrypto.decrypt(ck, m.pat_blob)) || {};
        if (o.dx != null) {
          dl.insertAdjacentHTML('beforeend', `<dt>Diagnose 🔒</dt><dd>${esc(String(o.dx))}</dd>`);
        }
        if (o.age != null) {
          dl.insertAdjacentHTML('beforeend', `<dt>Alter 🔒</dt><dd>${esc(String(o.age))}</dd>`);
        }
        if (o.loc && o.loc.addr) {
          dl.insertAdjacentHTML('beforeend', `<dt>Einsatzort 🔒</dt><dd>${esc(o.loc.addr)}</dd>`);
          if (o.loc.lat != null) {
            L.marker([o.loc.lat, o.loc.lon], { icon: locPin('#FF8F1F'), keyboard: false })
              .addTo(map).bindPopup('Einsatzort<br>' + esc(o.loc.addr));
            if (!bounds.length) { map.setView([o.loc.lat, o.loc.lon], 13); }
          }
        }
        dl.hidden = dl.children.length === 0;
      } catch (e) { /* Blob passt nicht zum Schluessel */ }
    } else {
      dl.insertAdjacentHTML('beforeend',
        '<dt>Verschlüsselt 🔒</dt><dd class="muted">gesperrt — <a href="einrichtung.php">entsperren</a> oder neu anmelden</dd>');
      dl.hidden = false;
    }
  }
}
init();
</script>
</body>
</html>

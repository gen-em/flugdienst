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
<?php ui_topbar('uebersicht'); ?>

<div class="layout">
  <?php ui_days_sidebar($missionDay); ?>

<main class="page page-center">
  <h1 id="title">Einsatz</h1>
  <p id="meta" class="muted"></p>
  <p class="actionbar">
    <a class="btn-edit" href="einsatz_form.php?id=<?= $mid ?>">Bearbeiten</a>
    <form method="post" action="einsatz_loeschen.php" style="display:inline"
          onsubmit="return confirm('Diesen Einsatz endgültig löschen? Phasen, Reanimationen und Track werden mit entfernt.')">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $mid ?>">
      <button class="btn-danger">Löschen</button>
    </form>
  </p>
  <dl id="fieldlist" class="fieldlist" hidden></dl>
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
function esc(t){ const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
const map = L.map('map');
let phaseMarkers = [];        // [{marker, idx}]
let phasesVisible = true;

function buildPhaseMarkers(phases){
  // Marker an der GPS-Position des Zeitstempels; Kachel-Design wie in der
  // Tabelle, pixelfest beim Zoomen. Gestapelte (gleicher Ort) leicht versetzt.
  const groups = {};
  phases.forEach((p, idx) => {
    if (p.lat == null || p.lon == null) return;
    const key = p.lat.toFixed(4) + ',' + p.lon.toFixed(4);
    (groups[key] = groups[key] || []).push({p, idx});
  });
  Object.values(groups).forEach(list => {
    list.forEach((e2, k) => {
      const icon = L.divIcon({
        className: 'phase-marker',
        html: `<span class="chip pm-chip" data-idx="${e2.idx}">${e2.p.phase}</span>`,
        iconSize: [24, 24],
        iconAnchor: [12 - k * 20, 12]          // Stapel: nebeneinander versetzt
      });
      const mk = L.marker([e2.p.lat, e2.p.lon], { icon, keyboard: false }).addTo(map);
      phaseMarkers.push({ marker: mk, idx: e2.idx });
      mk.on('add', () => bindMarkerHover(mk, e2.idx));
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
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
map.setView([48.5, 10.5], 7);

function fmtKm(m){ return m==null ? '–' : (m/1000).toFixed(1).replace('.',',')+' km'; }
function fmtDay(iso){ const [y,m,d]=iso.split('-'); return `${d}.${m}.${y}`; }

async function init(){
  const res = await fetch('api/mission.php?id=<?= $id ?>');
  if (!res.ok) { document.getElementById('title').textContent = 'Einsatz nicht gefunden'; return; }
  const m = await res.json();

  document.getElementById('title').textContent =
    `Einsatz ${m.day_no} · ${m.start_hhmm} Uhr`;
  document.getElementById('meta').innerHTML =
    `${fmtDay(m.day)} · ${m.start_hhmm}–${m.has_p9 ? m.end_hhmm : 'kein Ende'} Uhr · ${fmtKm(m.distance_m)}`
    + (m.ascent_m != null ? ` · ${m.ascent_m} Hm` : '')
    + (m.manual ? ' · <span class="badge-manual">manuell</span>' : '');

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
  m.phases.forEach((p, idx) => {
    const tr = document.createElement('tr');
    tr.dataset.idx = idx;
    tr.innerHTML = `<td><span class="chip">${p.phase}</span></td><td>${p.label}</td><td class="mono">${p.time}</td>`;
    tr.addEventListener('mouseenter', () => hlPhase(idx, true));
    tr.addEventListener('mouseleave', () => hlPhase(idx, false));
    tr.addEventListener('click', () => hlPhase(idx, 'toggle'));   // mobil: Tipp
    pb.appendChild(tr);
  });

  // Einsatzort-Pin
  if (m.loc) {
    L.marker([m.loc.lat, m.loc.lon]).addTo(map)
      .bindPopup('Einsatzort' + (m.loc.addr ? '<br>' + m.loc.addr : ''));
  }

  buildPhaseMarkers(m.phases);

  // PatientInnendaten lokal entschluesseln und in die Feldliste haengen
  if (m.pat_blob && m.pat_wrap) {
    const PATF = { ln:'Nachname', fn:'Vorname', dx:'Diagnose', dob:'Geburtsdatum', age:'Alter' };
    const ck = await EdCrypto.getContentKey(m.pat_wrap);
    const dl = document.getElementById('fieldlist');
    if (ck) {
      try {
        const o = JSON.parse(await EdCrypto.decrypt(ck, m.pat_blob)) || {};
        m.pat_fields.forEach(k => {
          if (o[k] == null) return;
          let v = o[k];
          if (k === 'dob') { const p = String(v).split('-'); if (p.length === 3) v = `${p[2]}.${p[1]}.${p[0]}`; }
          dl.insertAdjacentHTML('beforeend',
            `<dt>${PATF[k]} 🔒</dt><dd>${esc(String(v))}</dd>`);
          dl.hidden = false;
        });
      } catch (e) { /* Blob passt nicht zum Schluessel */ }
    } else {
      dl.insertAdjacentHTML('beforeend',
        '<dt>PatientInnendaten 🔒</dt><dd class="muted">gesperrt — bitte neu anmelden</dd>');
      dl.hidden = false;
    }
  }

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
</body>
</html>

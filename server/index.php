<?php
declare(strict_types=1);
// Noch nicht eingerichtet? -> Installer starten (erledigt sich nach 1x selbst).
if (!file_exists(__DIR__ . '/config.php')) { header('Location: install.php'); exit; }
require_once __DIR__ . '/auth_guard.php';

// Gewaehlter Tag: ?day=YYYY-MM-DD, sonst der neueste
// Stammdaten fuer die Flugtag-Dropdowns
$SD_BASES = db()->prepare('SELECT id, name, is_default FROM bases WHERE user_id = ? ORDER BY name');
$SD_BASES->execute([$userId]); $SD_BASES = $SD_BASES->fetchAll();
$SD_AC = db()->prepare('SELECT id, registration, p1, p2, hems, fr, other, is_default FROM aircraft
                        WHERE user_id = ? ORDER BY registration');
$SD_AC->execute([$userId]); $SD_AC = $SD_AC->fetchAll();
$DEF_AC = 0; $DEF_BASE = 0;
foreach ($SD_AC as $a) { if ((int)($a['is_default'] ?? 0)) { $DEF_AC = (int)$a['id']; } }
$SD_CREW = db()->prepare('SELECT role, name FROM crew_presets WHERE user_id = ? ORDER BY name');
$SD_CREW->execute([$userId]);
$SD_PRESETS = ['p1' => [], 'p2' => [], 'hems' => [], 'fr' => [], 'other' => []];
foreach ($SD_CREW->fetchAll() as $c) { $SD_PRESETS[$c['role']][] = $c['name']; }

$selDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day'] ?? '') ? $_GET['day'] : null;
if ($selDay === null) {
    $q = db()->prepare('SELECT day FROM (
            SELECT day FROM missions WHERE user_id = ? AND deleted_at IS NULL
            UNION SELECT day FROM rest_segments WHERE user_id = ? AND deleted_at IS NULL
            UNION SELECT day FROM days WHERE user_id = ? AND deleted_at IS NULL
        ) t ORDER BY day DESC LIMIT 1');
    $q->execute([$userId, $userId, $userId]);
    $selDay = $q->fetchColumn() ?: null;
}
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
<?php ui_topbar('uebersicht'); ?>

<div class="layout">
  <?php ui_days_sidebar($selDay); ?>

  <main class="page">
    <h1 id="daytitle">–</h1>
    <div id="loaderror" class="alert" hidden></div>
    <details class="daymeta" id="daymeta">
      <summary>Flugtag-Daten <span id="metahint" class="muted"></span>
        <span id="metanotes" class="metanotes"></span></summary>
      <form id="dayform" class="meta-form">
        <label>Maschine
          <select name="aircraft_id" id="acsel">
            <option value="">–</option>
            <?php foreach ($SD_AC as $a): ?>
              <option value="<?= (int)$a['id'] ?>"
                data-roles='<?= json_encode(['p1'=>(int)$a['p1'],'p2'=>(int)$a['p2'],'hems'=>(int)$a['hems'],'fr'=>(int)$a['fr'],'other'=>(int)$a['other']]) ?>'>
                <?= e($a['registration']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Basis / Standort
          <select name="base_id">
            <option value="">–</option>
            <?php foreach ($SD_BASES as $b): ?>
              <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div id="crewfields">
          <?php $RL = ['p1'=>'Pilot 1','p2'=>'Pilot 2','hems'=>'HEMS','fr'=>'Flugretter','other'=>'Sonstige'];
          foreach ($RL as $rk => $lbl): ?>
            <label class="crewrole" data-role="<?= $rk ?>" hidden><?= e($lbl) ?>
              <select name="crew_<?= $rk ?>">
                <option value="">–</option>
                <?php foreach ($SD_PRESETS[$rk] as $n): ?>
                  <option value="<?= e($n) ?>"><?= e($n) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          <?php endforeach; ?>
        </div>
        <p class="muted" id="sd-hint" <?= ($SD_AC || $SD_BASES) ? 'hidden' : '' ?>>
          Noch keine Stammdaten hinterlegt — unter
          <a href="einstellungen.php?t=stammdaten">Einstellungen → Stammdaten</a> anlegen.</p>
        <label>Notizen <textarea name="notes" rows="3" maxlength="2000"></textarea></label>
        <button type="submit" class="btn-primary">Speichern</button>
        <span id="savestate" class="muted"></span>
      </form>
    </details>
    <div id="map" class="map"></div>
    <table class="data" id="missions">
      <thead><tr>
        <th class="c-swatch"></th>
        <th class="sortable c-no"   data-key="no">Nr.</th>
        <th class="sortable c-mid"  data-key="start">Beginn</th>
        <th class="sortable c-mid"  data-key="dur">Dauer</th>
        <th class="sortable"        data-key="site">Einsatzort</th>
        <th class="sortable c-mid"   data-key="age">Alter</th>
        <th class="sortable"        data-key="dx">Diagnose</th>
        <th class="sortable c-winde" data-key="winch">Winde</th>
        <th class="sortable c-bw"    data-key="bw">Bergwacht</th>
        <th class="sortable c-sek"   data-key="sec">Sekundär<br>Transport</th>
        <th class="sortable c-km"    data-key="km">Flug&nbsp;km</th>
      </tr></thead>
      <tbody></tbody>
    </table>
    <p id="empty" class="muted" hidden>Für diesen Tag sind keine Einsätze dokumentiert.</p>
    <div class="dayactions">
      <a href="einsatz_form.php" id="addmission" class="btn-primary">+ Einsatz nachtragen</a>
      <a class="btn-red" id="daydellink"
         href="flugtag_loeschen.php?day=<?= e((string)$selDay) ?>"
         <?= $selDay ? '' : 'hidden' ?>>Tag löschen</a>
    </div>

    <?php ui_footer(); ?>
  </main>
</div>

<script src="assets/crypto.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const CSRF = '<?= e($_SESSION['csrf']) ?>';
const SEL_DAY = <?= json_encode($selDay) ?>;
const DEF_AC = <?= (int)$DEF_AC ?>;
const PAT_WRAP = <?= json_encode($patWrapPw) ?>;
const DEF_BASE = <?php $d = 0; foreach ($SD_BASES as $b) { if ((int)($b['is_default'] ?? 0)) $d = (int)$b['id']; } echo $d; ?>;
const COLORS = ['#FF8F1F','#4280E5','#D63338','#1A2E4D','#0C8599','#9C36B5','#2F9E44','#8A5A00'];
let currentDay = null;

const map = L.map('map');
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
map.setView([48.5, 10.5], 7); // Fallback, bis Daten da sind

let layerGroup = L.layerGroup().addTo(map);
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

let mapHasBounds = false;
function trackWeight(){
  const z = map.getZoom();
  return z >= 14 ? 4 : z >= 12 ? 5 : z >= 10 ? 6 : 7;
}
map.on('zoomend', () => {
  const w = trackWeight();
  trackLines.forEach(l => l.setStyle({ weight: w }));
});

function fmtDay(iso){ const [y,m,d]=iso.split('-'); return `${d}.${m}.${y}`; }
let dayMissions = [];
let sortKey = 'start', sortDir = 1;

function sortVal(m, key){
  switch (key) {
    case 'no':
    case 'start': return m._no;
    case 'dur':   return m.duration_s == null ? -1 : m.duration_s;
    case 'site':  return (m._ort || '').toLowerCase();
    case 'age':   return m._age == null ? -1 : m._age;
    case 'dx':    return (m._dx || '').toLowerCase();
    case 'winch': return m.winch ? 1 : 0;
    case 'bw':    return m.bergwacht ? 1 : 0;
    case 'sec':   return m.secondary ? 1 : 0;
    case 'winch': return m.winch ? 1 : 0;
    case 'bw':    return m.bergwacht ? 1 : 0;
    case 'km':    return m.distance_m == null ? -1 : m.distance_m;
  }
  return 0;
}

function renderMissionTable(){
  const tbody = document.querySelector('#missions tbody');
  tbody.innerHTML = '';
  const list = [...dayMissions].sort((a, b) => {
    const va = sortVal(a, sortKey), vb = sortVal(b, sortKey);
    return (va < vb ? -1 : va > vb ? 1 : a._no - b._no) * sortDir;
  });
  list.forEach(m => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td class="c-swatch"><span class="swatch" style="background:${m._col}"></span></td>
      <td class="mono c-no">${m._no}</td>
      <td class="mono c-mid">${m.start_hhmm}</td>
      <td class="c-mid">${fmtDur(m.duration_s)}</td>
      <td${m._ort ? '' : ' class="dash"'}>${m._ort ? esc(m._ort) : '–'}</td>
      <td class="mono c-mid${m._age != null ? '' : ' dash'}">${m._age != null ? m._age : '–'}</td>
      <td${m._dx ? '' : ' class="dash"'}>${m._dx ? esc(m._dx) : '–'}</td>
      <td class="checkcol c-winde">${m.winch ? '✓' : ''}</td>
      <td class="checkcol c-bw">${m.bergwacht ? '✓' : ''}</td>
      <td class="checkcol c-sek">${m.secondary ? '✓' : ''}</td>
      <td class="mono c-km">${fmtKm(m.distance_m)}</td>`;
    tr.addEventListener('click', () => location.href = 'einsatz.php?id=' + m.id);
    tbody.appendChild(tr);
  });
  document.querySelectorAll('#missions th.sortable').forEach(th => {
    th.classList.toggle('sorted', th.dataset.key === sortKey);
    th.querySelector('.arrow')?.remove();
    if (th.dataset.key === sortKey) {
      const a = document.createElement('span');
      a.className = 'arrow';
      a.textContent = sortDir > 0 ? ' ▲' : ' ▼';
      th.appendChild(a);
    }
  });
}

function extractOrt(addr){
  const parts = addr.split(',');
  let last = parts[parts.length - 1].trim();
  last = last.replace(/^\d{4,5}\s+/, '');
  return last;
}

function esc(t){ const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

function fmtDur(s){ if(s==null) return 'kein Ende'; const h=Math.floor(s/3600),m=Math.round(s%3600/60);
  // kompakt ohne Leerzeichen vor der Einheit, damit die Spalte einzeilig bleibt
  return h? `${h}h ${String(m).padStart(2,'0')}min` : `${m}min`; }
function fmtKm(m){ return m==null ? '<span class="dash">–</span>' : (m/1000).toFixed(1).replace('.',',')+' km'; }

function showLoadError(msg){
  const box = document.getElementById('loaderror');
  box.textContent = 'Die Tagesdaten konnten nicht geladen werden: ' + msg;
  box.hidden = false;
}

async function loadDay(day){
  let d;
  try {
    const res = await fetch('api/day.php?day='+encodeURIComponent(day));
    const txt = await res.text();
    try { d = JSON.parse(txt); }
    catch (e) {
      // Kein JSON: meist ein Server-/SQL-Fehler. Anfang der Antwort zeigen,
      // damit die Ursache sofort sichtbar ist statt einer leeren Seite.
      showLoadError(txt.replace(/<[^>]*>/g, ' ').trim().slice(0, 300) || ('HTTP ' + res.status));
      return;
    }
    if (d.error) { showLoadError(d.error + (d.meldung ? ': ' + d.meldung : '')); return; }
  } catch (e) { showLoadError(e.message); return; }
  document.getElementById('loaderror').hidden = true;
  currentDay = d.day;
  document.getElementById('daytitle').textContent = 'Flugtag ' + fmtDay(d.day);
  const ddl = document.getElementById('daydellink');
  ddl.href = 'flugtag_loeschen.php?day=' + encodeURIComponent(d.day);
  ddl.hidden = false;

  // Flugtag-Felder befuellen
  const f = document.getElementById('dayform');
  // Vorbelegung: ohne gespeicherten Wert greifen Standard-Maschine/-Standort
  f.elements['aircraft_id'].value = (d.meta && d.meta.aircraft_id)
    ? d.meta.aircraft_id : (DEF_AC || '');
  f.elements['base_id'].value = (d.meta && d.meta.base_id)
    ? d.meta.base_id : (DEF_BASE || '');
  ['p1','p2','hems','fr','other'].forEach(r => {
    f.elements['crew_' + r].value = (d.meta && d.meta['crew_' + r]) ? d.meta['crew_' + r] : '';
  });
  f.elements['notes'].value = (d.meta && d.meta.notes) ? d.meta.notes : '';
  updateCrewFields();
  const parts = [];
  if (d.meta) {
    if (d.meta.aircraft_name) parts.push(d.meta.aircraft_name);
    if (d.meta.base_name) parts.push(d.meta.base_name);
    ['p1','p2','hems','fr','other'].forEach(r => { if (d.meta['crew_' + r]) parts.push(d.meta['crew_' + r]); });
    if (!parts.length && (d.meta.aircraft || d.meta.base || d.meta.crew)) {
      [d.meta.aircraft, d.meta.base, d.meta.crew].filter(Boolean).forEach(x => parts.push(x + ' (alt)'));
    }
  }
  document.getElementById('metahint').textContent = parts.length ? '— ' + parts.join(' · ') : '';
  document.getElementById('metanotes').textContent =
    (d.meta && d.meta.notes) ? d.meta.notes : '';
  document.getElementById('savestate').textContent = '';
  document.getElementById('addmission').href = 'einsatz_form.php?day=' + d.day;

  layerGroup.clearLayers();
  trackLines.length = 0;
  const bounds = [];

  // Ruhe-Track: schwarz, dezent
  d.rest_segments.forEach(seg => {
    if (seg.length > 1) {
      const rl = L.polyline(seg, { color:'#8A8378', weight: Math.max(3, trackWeight() - 1),
        opacity:0.9, smoothFactor:0 });
      layerGroup.addLayer(rl);
      trackLines.push(rl);
      seg.forEach(p => bounds.push(p));
    }
  });

  // Einsaetze: je eigene Farbe
  // Einsaetze: Nummer + Farbe stabil nach Alarmierungszeit vergeben
  // (API liefert aufsteigend nach Beginn), danach frei sortierbar.
  dayMissions = d.missions.map((m, i) => {
    m._no = i + 1;
    m._col = COLORS[i % COLORS.length];
    return m;
  });
  d.missions.forEach(m => {
    if (m.track.length > 1) {
      const line = L.polyline(m.track, { color: m._col, weight: trackWeight(), smoothFactor: 0 });
      layerGroup.addLayer(line);
      trackLines.push(line);
      m.track.forEach(p => bounds.push(p));
    }
  });
  renderMissionTable();
  if (PAT_WRAP) {
    (async () => {
      const ck = await EdCrypto.getContentKey(PAT_WRAP);
      const banner = document.getElementById('lockbanner');
      if (!ck) { if (banner) banner.hidden = !dayMissions.some(m => m.pat_blob); return; }
      if (banner) banner.hidden = true;
      let changed = false;
      const pinBounds = [];
      for (const m of dayMissions) {
        if (!m.pat_blob) continue;
        try {
          const o = JSON.parse(await EdCrypto.decrypt(ck, m.pat_blob)) || {};
          if (o.dx != null) { m._dx = o.dx; changed = true; }
          if (o.age != null) { m._age = o.age; changed = true; }
          if (o.loc && o.loc.addr) {
            m._ort = extractOrt(o.loc.addr);
            changed = true;
            if (o.loc.lat != null) {
              layerGroup.addLayer(L.marker([o.loc.lat, o.loc.lon],
                { icon: locPin(m._col), keyboard: false })
                .bindPopup(`Einsatz ${m._no}<br>` + esc(o.loc.addr)));
              pinBounds.push([o.loc.lat, o.loc.lon]);
            }
          }
        } catch (e) { }
      }
      if (changed) renderMissionTable();
      if (pinBounds.length && !mapHasBounds) { map.fitBounds(pinBounds, { padding: [30, 30] }); }
    })();
  }

  document.getElementById('empty').hidden = d.missions.length > 0;
  document.getElementById('missions').hidden = d.missions.length === 0;

  // Auto-Zoom: Track soll ca. 75 % der Karte einnehmen -> ~12.5 % Rand je Seite
  if (bounds.length) {
    mapHasBounds = true;
    const px = map.getSize();
    map.fitBounds(L.latLngBounds(bounds),
      { padding: [px.y * 0.125, px.x * 0.125] });
  }
}

function updateCrewFields(){
  const sel = document.getElementById('acsel');
  const opt = sel.options[sel.selectedIndex];
  const roles = (opt && opt.dataset.roles) ? JSON.parse(opt.dataset.roles) : {};
  document.querySelectorAll('.crewrole').forEach(el => {
    el.hidden = !roles[el.dataset.role];
  });
}

async function init(){
  document.getElementById('acsel').addEventListener('change', updateCrewFields);
  document.querySelectorAll('#missions th.sortable').forEach(th => {
    th.addEventListener('click', () => {
      if (sortKey === th.dataset.key) { sortDir = -sortDir; }
      else { sortKey = th.dataset.key; sortDir = 1; }
      renderMissionTable();
    });
  });
  document.getElementById('dayform').addEventListener('submit', async ev => {
    ev.preventDefault();
    if (!currentDay) return;
    const f = ev.target;
    const body = { day: currentDay,
      aircraft_id: f.elements['aircraft_id'].value || null,
      base_id: f.elements['base_id'].value || null,
      notes: f.elements['notes'].value };
    ['p1','p2','hems','fr','other'].forEach(r => body['crew_' + r] = f.elements['crew_' + r].value);
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
  if (SEL_DAY) { loadDay(SEL_DAY); }
  else document.getElementById('daytitle').textContent = 'Noch keine Daten';
}
init();
</script>
</body>
</html>

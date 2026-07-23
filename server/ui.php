<?php
declare(strict_types=1);
/**
 * Gemeinsame Layout-Bausteine (Topbar, Einsatztage-Leiste, Fusszeile).
 * Voraussetzung: auth_guard.php ist geladen ($userId, $userRole, $userEmail, $userName).
 */

function ui_user_label(): string {
    global $userName, $userEmail;
    return ($userName !== null && $userName !== '') ? $userName : (string)$userEmail;
}

/** Kopfleiste: Vogel-Icon + Titel + Name; Menü Übersicht / Administration / ⚙ */
function ui_topbar(string $active): void {
    global $userRole; ?>
<header class="topbar">
  <a class="brand" href="index.php">
    <img src="<?= asset('assets/images/gen-em_logo_helicopter_weiss.svg') ?>" alt="">
    <span>Einsatzdokumentation Luftrettung – <?= e(ui_user_label()) ?></span>
  </a>
  <nav class="mainnav">
    <a href="index.php" <?= $active === 'uebersicht' ? 'class="active"' : '' ?>>Übersicht</a>
    <a class="gearlink <?= $active === 'einstellungen' ? 'active' : '' ?>"
       href="einstellungen.php?t=profil" title="Einstellungen" aria-label="Einstellungen">
      <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor" aria-hidden="true">
        <path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>
        <circle cx="12" cy="12" r="1.9"/>
      </svg></a>
  </nav>
</header>
<?php }

/**
 * Untermenue der Einstellungen — identisch auf einstellungen.php, admin.php
 * und admin_user.php. Die Administration erscheint nur fuer Admins.
 * $active: profil | stammdaten | backup | geraete | admin
 */
function ui_settings_sidebar(string $active): void {
    global $userRole;
    $items = [
        'profil'     => ['einstellungen.php?t=profil', 'Profil'],
        'stammdaten' => ['einstellungen.php?t=stammdaten', 'Standortdaten'],
        'backup'     => ['einstellungen.php?t=backup', 'Backup'],
        'geraete'    => ['einstellungen.php?t=geraete', 'Geräte'],
    ];
    if ($userRole === 'admin') {
        $items['admin'] = ['admin.php', 'Administration'];
    }
    ?>
  <aside class="daylist">
    <h2>Einstellungen</h2>
    <ul>
      <?php foreach ($items as $key => [$href, $label]): ?>
        <li><a href="<?= $href ?>" <?= $active === $key ? 'class="active"' : '' ?>><?= $label ?></a></li>
      <?php endforeach; ?>
      <li><a href="logout.php" data-confirm="Wirklich abmelden?" data-confirm-ok="Abmelden"
             data-confirm-tone="normal">Abmelden</a></li>
    </ul>
  </aside>
<?php }

/** Einsatztage-Leiste (serverseitig, auf allen Inhaltsseiten identisch) */
function ui_days_sidebar(?string $currentDay): void {
    global $userId;
    $st = db()->prepare(
        'SELECT day FROM (
            SELECT day FROM missions WHERE user_id = ? AND deleted_at IS NULL
            UNION SELECT day FROM rest_segments WHERE user_id = ? AND deleted_at IS NULL
            UNION SELECT day FROM days WHERE user_id = ? AND deleted_at IS NULL
         ) t ORDER BY day DESC LIMIT 500');
    $st->execute([$userId, $userId, $userId]);
    $days = $st->fetchAll(PDO::FETCH_COLUMN);

    // Nach Jahr -> Monat gruppieren (je Y => M => [Tage]), Reihenfolge bleibt
    // absteigend, da $days bereits absteigend sortiert aus der DB kommt.
    $monatsnamen = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    $baum = [];
    foreach ($days as $d) {
        $y = substr($d, 0, 4);
        $m = substr($d, 5, 2);
        $baum[$y][$m][] = $d;
    }

    // Welches Jahr/Monat soll offen sein? Der aktuell gewaehlte Tag hat
    // Vorrang, sonst der juengste vorhandene Tag (oberstes Jahr/oberster Monat).
    $offenesJahr = null; $offenerMonat = null;
    if ($currentDay !== null && isset($baum[substr($currentDay, 0, 4)][substr($currentDay, 5, 2)])) {
        $offenesJahr  = substr($currentDay, 0, 4);
        $offenerMonat = substr($currentDay, 5, 2);
    } elseif ($days) {
        $offenesJahr  = substr($days[0], 0, 4);
        $offenerMonat = substr($days[0], 5, 2);
    }
    ?>
<aside class="daylist">
  <h2>Einsatztage</h2>
  <div class="dayyears">
    <?php if (!$baum): ?><p class="muted daylist-empty">noch keine</p><?php endif; ?>
    <?php foreach ($baum as $jahr => $monate):
        // Achtung: PHP macht aus numerischen Array-Schluesseln Integer
        // ("2026" -> 2026, "12" -> 12, "07" bleibt String). Deshalb ueberall
        // ausdruecklich nach String wandeln — sonst bricht e() unter
        // strict_types ab und Monatsvergleiche schlagen ab Oktober fehl.
        $jahrS = (string)$jahr; ?>
      <details class="yearblock" <?= $jahrS === $offenesJahr ? 'open' : '' ?>>
        <summary><a class="zeitlink" href="zeitraum.php?y=<?= e($jahrS) ?>"><?= e($jahrS) ?></a></summary>
        <?php foreach ($monate as $monat => $tage):
            $monatS = str_pad((string)$monat, 2, '0', STR_PAD_LEFT); ?>
          <details class="monthblock"
                    <?= ($jahrS === $offenesJahr && $monatS === $offenerMonat) ? 'open' : '' ?>>
            <summary><a class="zeitlink"
                        href="zeitraum.php?y=<?= e($jahrS) ?>&amp;m=<?= e($monatS) ?>"><?= e($monatsnamen[(int)$monatS]) ?></a></summary>
            <ul>
              <?php foreach ($tage as $d):
                  $dt = DateTime::createFromFormat('Y-m-d', $d); ?>
                <li><a href="index.php?day=<?= e($d) ?>"
                       <?= $d === $currentDay ? 'class="active"' : '' ?>><?= $dt ? $dt->format('d.m.Y') : e($d) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endforeach; ?>
      </details>
    <?php endforeach; ?>
  </div>
    <?php
      require_once __DIR__ . '/trash_lib.php';
      $trashLeer = !trash_list_days($userId) && !trash_list_missions($userId);
    ?>
    <a class="dayadd" href="flugtag_neu.php" title="Flugtag von Hand anlegen">
      + Flugtag anlegen
    </a>
    <a class="trashlink<?= $trashLeer ? ' leer' : '' ?>" href="papierkorb.php"
       title="<?= $trashLeer ? 'Papierkorb ist leer' : 'Papierkorb' ?>">
      <svg width="26" height="26" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
           fill="currentColor" aria-hidden="true">
        <path d="M9 3h6l1 1h4v2H4V4h4l1-1zM6 7h12l-1 13.1c-.06.5-.5.9-1 .9H8c-.5 0-.94-.4-1-.9L6 7zm3.5 2.6v9h1.6v-9H9.5zm3.4 0v9h1.6v-9h-1.6z"/>
      </svg>
      <span>Papierkorb</span>
    </a>

</aside>
<?php }

/** Fusszeile: im Dokumentfluss, rechtsbündig unter dem Inhalt */
function ui_footer(): void { ?>
  <script src="<?= asset('assets/confirm.js') ?>"></script>
  <script src="<?= asset('assets/daylist.js') ?>"></script>
<footer class="sitefooter">© Gen-EM – OpenSource Software –
  <a href="https://github.com/gen-em/einsatzdoku-luftrettung/blob/main/LICENSE"
     target="_blank" rel="noopener">AGPL-3.0</a>
  <span class="ver">v<?= e(WEB_VERSION) ?></span></footer>
<?php }

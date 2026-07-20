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
    <img src="assets/icon-weiss.png" alt="">
    <span>Einsatzdokumentation Luftrettung – <?= e(ui_user_label()) ?></span>
  </a>
  <nav class="mainnav">
    <a href="index.php" <?= $active === 'uebersicht' ? 'class="active"' : '' ?>>Übersicht</a>
    <?php if ($userRole === 'admin'): ?>
      <a href="admin.php" <?= $active === 'admin' ? 'class="active"' : '' ?>>Administration</a>
    <?php endif; ?>
    <a class="gearlink <?= $active === 'einstellungen' ? 'active' : '' ?>"
       href="einstellungen.php?t=profil" title="Einstellungen" aria-label="Einstellungen">
      <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor" aria-hidden="true">
        <path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>
        <circle cx="12" cy="12" r="1.9"/>
      </svg></a>
  </nav>
</header>
<?php }

/** Einsatztage-Leiste (serverseitig, auf allen Inhaltsseiten identisch) */
function ui_days_sidebar(?string $currentDay): void {
    global $userId;
    $st = db()->prepare(
        'SELECT day FROM (
            SELECT day FROM missions WHERE user_id = ?
            UNION SELECT day FROM rest_segments WHERE user_id = ?
            UNION SELECT day FROM days WHERE user_id = ?
         ) t ORDER BY day DESC LIMIT 90');
    $st->execute([$userId, $userId, $userId]);
    $days = $st->fetchAll(PDO::FETCH_COLUMN); ?>
<aside class="daylist">
  <h2>Einsatztage</h2>
  <ul>
    <?php if (!$days): ?><li class="muted">noch keine</li><?php endif; ?>
    <?php foreach ($days as $d):
        $dt = DateTime::createFromFormat('Y-m-d', $d); ?>
      <li><a href="index.php?day=<?= e($d) ?>"
             <?= $d === $currentDay ? 'class="active"' : '' ?>><?= $dt ? $dt->format('d.m.Y') : e($d) ?></a></li>
    <?php endforeach; ?>
  </ul>
</aside>
<?php }

/** Fusszeile: im Dokumentfluss, rechtsbündig unter dem Inhalt */
function ui_footer(): void { ?>
<footer class="sitefooter">© Gen-EM – OpenSource Software –
  <a href="https://github.com/gen-em/einsatzdoku-luftrettung/blob/main/LICENSE"
     target="_blank" rel="noopener">AGPL-3.0</a></footer>
<?php }

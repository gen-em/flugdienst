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
       href="einstellungen.php?t=profil" title="Einstellungen">⚙</a>
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

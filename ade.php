<?php
require __DIR__ . '/auth.php';
disown_require_admin();
require __DIR__ . '/db.php';

$currentAdminUser = disown_current_admin_user();
$isDevMode = basename(__DIR__) === 'disown-dev';
$appVersion = $isDevMode ? '2.3-dev' : '2.3';
$sourceRepoUrl = 'https://github.com/mail896/bbs_mdm_disown_public';
$appBasePath = rtrim(disown_admin_base_path(), '/');
$adminPath = $appBasePath . '/admin.php';
$adePath = $appBasePath . '/ade.php';
$kukPath = $appBasePath . '/kuk/';
$auditLogPath = $appBasePath . '/audit_log.php';
$logoutPath = $appBasePath . '/logout.php';
$faviconPath = $appBasePath . '/favicon.svg';
$searchJsUrl = disown_asset_url($appBasePath, 'assets/search.js');
$adeCssUrl = disown_asset_url($appBasePath, 'assets/ade.css');
$adeJsUrl = disown_asset_url($appBasePath, 'assets/ade.js');

$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? 'all'));
$days = (int) ($_GET['days'] ?? 30);
$days = in_array($days, [7, 30, 90, 365, 0], true) ? $days : 30;
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
$types = '';

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(serial LIKE ? OR asm_order_number LIKE ? OR jamf_device_name LIKE ? OR jamf_asset_tag LIKE ? OR jamf_owner_name LIKE ? OR jamf_owner_username LIKE ? OR jamf_owner_email LIKE ?)';
    for ($i = 0; $i < 7; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}

if ($status === 'missing') {
    $where[] = "jamf_state = 'missing'";
} elseif ($status === 'trash') {
    $where[] = "jamf_state = 'trash'";
} elseif ($status === 'enrolled') {
    $where[] = "jamf_state = 'active'";
} elseif ($status === 'updated') {
    $where[] = 'asm_updated_at IS NOT NULL AND asm_added_at IS NOT NULL AND ABS(TIMESTAMPDIFF(MINUTE, asm_added_at, asm_updated_at)) > 60';
} else {
    $status = 'all';
}

if ($days > 0) {
    $where[] = 'asm_updated_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
    $params[] = $days;
    $types .= 'i';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$exportParams = [
    'q' => $q,
    'status' => $status,
    'days' => $days,
    'export' => 'csv',
];
$exportUrl = $adePath . '?' . http_build_query(array_filter($exportParams, static fn ($value) => $value !== '' && $value !== null));
$orderSql = $status === 'updated'
    ? 'ORDER BY asm_updated_at DESC, id DESC'
    : 'ORDER BY asm_added_at DESC, id DESC';

if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $mysqli->prepare(
        "SELECT *
         FROM ade_enrollments
         {$whereSql}
         {$orderSql}"
    );
    if (!$stmt) {
        http_response_code(500);
        exit('Datenbankfehler');
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ade-aufnahmen-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'serial',
        'asm_updated_at',
        'asm_added_at',
        'asm_model',
        'asm_order_number',
        'asm_mdm_server_name',
        'jamf_state',
        'jamf_seen',
        'jamf_device_name',
        'jamf_asset_tag',
        'jamf_owner_name',
        'jamf_owner_username',
        'jamf_model',
        'last_sync_at',
    ]);
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, [
            $row['serial'],
            $row['asm_updated_at'],
            $row['asm_added_at'],
            $row['asm_model'],
            $row['asm_order_number'],
            $row['asm_mdm_server_name'],
            $row['jamf_state'],
            (int) $row['jamf_seen'],
            $row['jamf_device_name'],
            $row['jamf_asset_tag'],
            $row['jamf_owner_name'],
            $row['jamf_owner_username'],
            $row['jamf_model'],
            $row['last_sync_at'],
        ]);
    }
    fclose($out);
    $stmt->close();
    exit;
}

$countStmt = $mysqli->prepare("SELECT COUNT(*) AS count FROM ade_enrollments {$whereSql}");
if (!$countStmt) {
    die('Datenbankfehler: ' . htmlspecialchars($mysqli->error));
}
if ($params) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = (int) ($countStmt->get_result()->fetch_assoc()['count'] ?? 0);
$countStmt->close();

$stmt = $mysqli->prepare(
    "SELECT *
     FROM ade_enrollments
     {$whereSql}
     {$orderSql}
     LIMIT ? OFFSET ?"
);
if (!$stmt) {
    die('Datenbankfehler: ' . htmlspecialchars($mysqli->error));
}
$queryParams = $params;
$queryTypes = $types . 'ii';
$queryParams[] = $perPage;
$queryParams[] = $offset;
$stmt->bind_param($queryTypes, ...$queryParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$summary = [
    'total' => 0,
    'missing' => 0,
    'trash' => 0,
    'enrolled' => 0,
    'updated_recent' => 0,
    'last_sync_at' => null,
];
$summaryResult = $mysqli->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN jamf_state = 'missing' THEN 1 ELSE 0 END), 0) AS missing,
            COALESCE(SUM(CASE WHEN jamf_state = 'trash' THEN 1 ELSE 0 END), 0) AS trash,
            COALESCE(SUM(CASE WHEN jamf_state = 'active' THEN 1 ELSE 0 END), 0) AS enrolled,
            COALESCE(SUM(CASE WHEN asm_updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND asm_added_at IS NOT NULL AND ABS(TIMESTAMPDIFF(MINUTE, asm_added_at, asm_updated_at)) > 60 THEN 1 ELSE 0 END), 0) AS updated_recent,
            MAX(last_sync_at) AS last_sync_at
     FROM ade_enrollments"
);
if ($summaryResult) {
    $summary = array_merge($summary, $summaryResult->fetch_assoc() ?: []);
}

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$baseParams = ['q' => $q, 'status' => $status, 'days' => $days];

function ade_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ade_display_datetime(?string $value): string
{
    if (!$value) {
        return 'n/a';
    }
    try {
        return (new DateTimeImmutable($value))->format('d.m.Y H:i');
    } catch (Exception) {
        return $value;
    }
}

function ade_display_value(?string $value): string
{
    $value = trim((string) $value);
    return $value === '' ? 'n/a' : $value;
}

function ade_url(array $params): string
{
    $basePath = rtrim(disown_admin_base_path(), '/');
    return $basePath . '/ade.php?' . http_build_query(array_filter($params, static fn ($value) => $value !== '' && $value !== null));
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="<?=ade_h($faviconPath)?>">
<title>ADE-Aufnahmen</title>
<link rel="stylesheet" href="<?=ade_h($adeCssUrl)?>">
</head>
<body>
<div class="page">
    <header class="header">
        <div>
            <h1 class="page-title">ADE-Aufnahmen</h1>
            <p class="hint-text">Neue und erneut zugewiesene ASM/ADE-Geräte mit Jamf-Abgleich anzeigen.</p>
        </div>
        <div class="header-actions">
            <a class="admin-user" href="<?=ade_h($logoutPath)?>">👤 <?=ade_h($currentAdminUser ?: 'Admin')?></a>
            <div>
                <a class="button button-secondary admin-nav-link admin-home-link" href="<?=ade_h($adminPath)?>">Adminportal</a>
                <a class="button button-secondary admin-nav-link" href="<?=ade_h($kukPath)?>">KUK-Geräte</a>
                <a class="button button-secondary admin-nav-link" href="<?=ade_h($auditLogPath)?>">Audit-Log</a>
                <a class="button button-primary" href="<?=ade_h($exportUrl)?>">CSV exportieren</a>
            </div>
        </div>
    </header>

    <section class="stats" aria-label="Zusammenfassung">
        <span class="stat">Gesamt <strong><?=ade_h((string) $summary['total'])?></strong></span>
        <span class="stat warn">Nicht in Jamf <strong><?=ade_h((string) $summary['missing'])?></strong></span>
        <span class="stat danger">Trash <strong><?=ade_h((string) $summary['trash'])?></strong></span>
        <span class="stat ok">Enrolled <strong><?=ade_h((string) $summary['enrolled'])?></strong></span>
        <span class="stat">Kürzlich aktualisiert <strong><?=ade_h((string) $summary['updated_recent'])?></strong></span>
        <span class="stat">Letzter Sync <strong><?=ade_h(ade_display_datetime($summary['last_sync_at'] ?? null))?></strong></span>
    </section>

    <form class="toolbar" id="adeSearchForm" method="get">
        <div class="search">
            <label for="q">Suche</label>
            <div class="search-field">
                <input id="q" name="q" value="<?=ade_h($q)?>" placeholder="Seriennummer, Name, Asset Tag, Owner oder Order" autocomplete="off">
                <?php if ($q !== ''): ?>
                    <a class="clear-search" href="<?=ade_h(ade_url(array_merge($baseParams, ['q' => '', 'page' => 1])))?>" aria-label="Suche löschen">×</a>
                <?php endif; ?>
            </div>
            <select id="daysSelect" name="days" aria-label="Zeitraum">
                <option value="7" <?=$days === 7 ? 'selected' : ''?>>7 Tage</option>
                <option value="30" <?=$days === 30 ? 'selected' : ''?>>30 Tage</option>
                <option value="90" <?=$days === 90 ? 'selected' : ''?>>90 Tage</option>
                <option value="365" <?=$days === 365 ? 'selected' : ''?>>365 Tage</option>
                <option value="0" <?=$days === 0 ? 'selected' : ''?>>Alle</option>
            </select>
            <input type="hidden" name="status" value="<?=ade_h($status)?>">
            <input type="hidden" name="page" value="1">
            <button class="button-primary" type="submit">Suchen</button>
            <a class="button button-secondary" href="<?=ade_h($adePath)?>">Zurücksetzen</a>
        </div>
    </form>

    <nav class="tabs" aria-label="Statusfilter">
        <a class="tab <?=$status === 'all' ? 'active' : ''?>" href="<?=ade_h(ade_url(array_merge($baseParams, ['status' => 'all', 'page' => 1])))?>">Alle</a>
        <a class="tab <?=$status === 'missing' ? 'active' : ''?>" href="<?=ade_h(ade_url(array_merge($baseParams, ['status' => 'missing', 'page' => 1])))?>">Nicht in Jamf</a>
        <a class="tab <?=$status === 'trash' ? 'active' : ''?>" href="<?=ade_h(ade_url(array_merge($baseParams, ['status' => 'trash', 'page' => 1])))?>">Trash</a>
        <a class="tab <?=$status === 'enrolled' ? 'active' : ''?>" href="<?=ade_h(ade_url(array_merge($baseParams, ['status' => 'enrolled', 'page' => 1])))?>">Enrolled</a>
        <a class="tab <?=$status === 'updated' ? 'active' : ''?>" href="<?=ade_h(ade_url(array_merge($baseParams, ['status' => 'updated', 'page' => 1])))?>">Kürzlich aktualisiert</a>
    </nav>

    <section class="table-wrap">
        <?php if (!$rows): ?>
            <div class="empty">Keine ADE-Aufnahmen gefunden.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ASM hinzugefügt</th>
                        <th>Seriennummer</th>
                        <th>ASM</th>
                        <th>Jamf</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td data-label="ASM">
                                <?=ade_h(ade_display_datetime($row['asm_added_at'] ?? null))?>
                                <div class="subline">aktualisiert: <?=ade_h(ade_display_datetime($row['asm_updated_at'] ?? null))?></div>
                            </td>
                            <td data-label="Seriennummer">
                                <div class="mono"><?=ade_h($row['serial'])?></div>
                                <div class="subline"><?=ade_h(ade_display_value($row['asm_model'] ?? null))?></div>
                            </td>
                            <td data-label="ASM-Ziel">
                                <strong><?=ade_h(ade_display_value($row['asm_mdm_server_name'] ?? null))?></strong>
                                <div class="subline">Order: <?=ade_h(ade_display_value($row['asm_order_number'] ?? null))?></div>
                                <div class="subline">Quelle: <?=ade_h(ade_display_value($row['asm_purchase_source_type'] ?? null))?></div>
                            </td>
                            <td data-label="Jamf">
                                <?php if (($row['jamf_state'] ?? '') !== 'missing'): ?>
                                    <strong><?=ade_h(ade_display_value($row['jamf_device_name'] ?? null))?></strong>
                                    <div class="subline">Asset: <?=ade_h(ade_display_value($row['jamf_asset_tag'] ?? null))?></div>
                                    <div class="subline">Owner: <?=ade_h(ade_display_value($row['jamf_owner_name'] ?? null))?></div>
                                    <div class="subline"><?=ade_h(ade_display_value($row['jamf_model'] ?? null))?></div>
                                    <?php if (($row['jamf_state'] ?? '') === 'trash'): ?>
                                        <div class="subline">Letzter Check-in: <?=ade_h(ade_display_datetime($row['jamf_last_checkin'] ?? null))?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">n/a</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Status">
                                <?php if (($row['jamf_state'] ?? '') === 'active'): ?>
                                    <span class="badge badge-ok">Enrolled</span>
                                <?php elseif (($row['jamf_state'] ?? '') === 'trash'): ?>
                                    <span class="badge badge-danger">Trash</span>
                                <?php else: ?>
                                    <span class="badge badge-warn">Nicht in Jamf</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <div class="pagination">
            <?php $prevParams = array_merge($baseParams, ['page' => max(1, $page - 1)]); ?>
            <?php $nextParams = array_merge($baseParams, ['page' => min($totalPages, $page + 1)]); ?>
            <a class="button button-secondary" href="<?=ade_h(ade_url($prevParams))?>">‹ Zurück</a>
            <span>Seite <?=ade_h((string) $page)?> von <?=ade_h((string) $totalPages)?></span>
            <a class="button button-secondary" href="<?=ade_h(ade_url($nextParams))?>">Weiter ›</a>
        </div>
    </section>

    <footer class="footer">
        Stand: <?=ade_h(ade_display_datetime($summary['last_sync_at'] ?? null))?> ·
        © 2026 Marc Schulz · <a href="<?=ade_h($sourceRepoUrl)?>">Version <?=ade_h($appVersion)?></a> · ADE-Aufnahmen
    </footer>
</div>
<script src="<?=ade_h($searchJsUrl)?>" defer></script>
<script src="<?=ade_h($adeJsUrl)?>" defer></script>
</body>
</html>

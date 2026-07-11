<?php
require __DIR__ . '/auth.php';
disown_require_admin();
require 'db.php';

$currentAdminUser = disown_current_admin_user();
$appBasePath = rtrim(disown_admin_base_path(), '/');
$adminPath = $appBasePath . '/admin.php';
$adePath = $appBasePath . '/ade.php';
$kukPath = $appBasePath . '/kuk/';
$auditLogPath = $appBasePath . '/audit_log.php';
$logoutPath = $appBasePath . '/logout.php';
$faviconPath = $appBasePath . '/favicon.svg';
$siteImagePath = $appBasePath . '/images/Site-Image.png';
$searchJsUrl = disown_asset_url($appBasePath, 'assets/search.js');
$auditLogCssUrl = disown_asset_url($appBasePath, 'assets/audit_log.css');
$auditLogJsUrl = disown_asset_url($appBasePath, 'assets/audit_log.js');

$filterUser = trim((string) ($_GET['user'] ?? ''));
$filterAction = trim((string) ($_GET['action'] ?? ''));
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 100;
$offset = ($page - 1) * $perPage;
$whereParts = [];
$params = [];
$types = '';

if ($filterUser !== '') {
    $whereParts[] = 'admin_user = ?';
    $params[] = $filterUser;
    $types .= 's';
}
if ($filterAction !== '') {
    $whereParts[] = 'action = ?';
    $params[] = $filterAction;
    $types .= 's';
}
if ($searchTerm !== '') {
    $whereParts[] = '(admin_user LIKE ? OR action LIKE ? OR CAST(request_id AS CHAR) LIKE ? OR details LIKE ?)';
    $likeSearch = '%' . $searchTerm . '%';
    for ($i = 0; $i < 4; $i++) {
        $params[] = $likeSearch;
        $types .= 's';
    }
}

$whereSql = $whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '';
$exportParams = [];
if ($searchTerm !== '') {
    $exportParams['q'] = $searchTerm;
}
if ($filterUser !== '') {
    $exportParams['user'] = $filterUser;
}
if ($filterAction !== '') {
    $exportParams['action'] = $filterAction;
}
$exportParams['export'] = 'csv';
$exportUrl = $auditLogPath . '?' . http_build_query($exportParams);
$hasFilters = $filterUser !== '' || $filterAction !== '' || $searchTerm !== '';

if (($_GET['export'] ?? '') === 'csv') {
    $exportStmt = $mysqli->prepare(
        "SELECT created_at, admin_user, action, request_id, details
         FROM request_audit_log
         {$whereSql}
         ORDER BY created_at DESC, id DESC"
    );

    if (!$exportStmt) {
        http_response_code(500);
        echo 'Datenbankfehler';
        exit;
    }
    if ($params) {
        $exportStmt->bind_param($types, ...$params);
    }
    if (!$exportStmt->execute()) {
        http_response_code(500);
        echo 'Datenbankfehler';
        exit;
    }
    $exportResult = $exportStmt->get_result();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="disown-audit-log-' . date('Ymd') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['created_at', 'admin_user', 'action', 'request_id', 'details']);

    while ($row = $exportResult->fetch_assoc()) {
        fputcsv($output, [
            $row['created_at'],
            $row['admin_user'],
            $row['action'],
            $row['request_id'],
            $row['details'],
        ]);
    }
    fclose($output);
    $exportStmt->close();
    exit;
}

$countStmt = $mysqli->prepare("SELECT COUNT(*) AS count FROM request_audit_log {$whereSql}");
if (!$countStmt) {
    die('Datenbankfehler: ' . htmlspecialchars($mysqli->error));
}
if ($params) {
    $countStmt->bind_param($types, ...$params);
}
if (!$countStmt->execute()) {
    die('Datenbankfehler: ' . htmlspecialchars($countStmt->error));
}
$totalRows = (int) ($countStmt->get_result()->fetch_assoc()['count'] ?? 0);
$countStmt->close();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = $mysqli->prepare(
    "SELECT created_at, admin_user, action, request_id, details
     FROM request_audit_log
     {$whereSql}
     ORDER BY created_at DESC, id DESC
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
if (!$stmt->execute()) {
    die('Datenbankfehler: ' . htmlspecialchars($stmt->error));
}
$result = $stmt->get_result();

function audit_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function audit_url(array $params): string
{
    $basePath = rtrim(disown_admin_base_path(), '/');
    return $basePath . '/audit_log.php?' . http_build_query(array_filter($params, static fn ($value) => $value !== '' && $value !== null));
}

function audit_display_user(?string $value): string
{
    $user = (string) $value;
    return str_ends_with($user, '@example.org') ? substr($user, 0, -strlen('@example.org')) : $user;
}

function audit_normalize_admin_user(?string $value): string
{
    $user = trim((string) $value);
    if ($user === '') {
        return 'unbekannt';
    }

    $shortUser = audit_display_user($user);
    $aliases = [];

    return $aliases[$shortUser] ?? $shortUser;
}

function audit_display_action(?string $value): string
{
    $action = (string) $value;
    $labels = [
        'AUTH_LOGIN_SUCCESS' => 'Login',
        'AUTH_LOGOUT' => 'Logout',
        'AUTH_LOGIN_DENIED' => 'Login abgelehnt',
        'JAMF_UNENROLL_SUCCESS' => 'Jamf erledigt',
        'JAMF_UNENROLL_FAILED' => 'Jamf Fehler',
        'ASM_MANUAL_DONE' => 'ASM erledigt',
        'ASM_BROKER_RELEASE_SUCCESS' => 'ASM/ADE',
        'ASM_BROKER_RELEASE_FAILED' => 'ASM/ADE Fehler',
        'MAIL_SENT' => 'Mail gesendet',
        'BULK_JAMF_UNENROLL_SUCCESS' => 'Bulk Jamf',
        'BULK_JAMF_UNENROLL_FAILED' => 'Bulk Jamf Fehler',
        'BULK_ASM_DONE' => 'Bulk ASM',
        'BULK_ASM_SERIAL_LIST' => 'Bulk Serienliste',
        'BULK_ASM_BROKER_RELEASE_SUCCESS' => 'Bulk ASM/ADE',
        'BULK_ASM_BROKER_RELEASE_DRYRUN' => 'Bulk ASM/ADE DEV',
        'BULK_ASM_BROKER_RELEASE_FAILED' => 'Bulk ASM/ADE Fehler',
        'BULK_ASM_BROKER_RELEASE_SUMMARY' => 'Bulk ASM/ADE Liste',
        'BULK_MAIL_SENT' => 'Bulk Mail',
        'BULK_MAIL_SENT_DEV' => 'Bulk Mail DEV',
        'MAIL_FAILED_COMPLETED' => 'Mail Fehler',
        'MAIL_PARTIAL_FAILED_COMPLETED' => 'Mail Teilfehler',
        'BULK_MAIL_FAILED_COMPLETED' => 'Bulk Mail Fehler',
        'BULK_MAIL_PARTIAL_FAILED_COMPLETED' => 'Bulk Mail Teilfehler',
        'DEVICE_CASE_SAVED' => 'Klärfall',
        'DEVICE_CASE_DELETED' => 'Klärfall gelöscht',
        'TEMPLATE_UPDATED' => 'Vorlage',
    ];

    return $labels[$action] ?? ucwords(strtolower(str_replace('_', ' ', $action)));
}

$currentYear = (int) date('Y');
$currentMonth = (int) date('n');
$schoolYearStartYear = $currentMonth >= 8 ? $currentYear : $currentYear - 1;
$schoolYearStart = sprintf('%04d-08-01 00:00:00', $schoolYearStartYear);
$schoolYearEnd = sprintf('%04d-08-01 00:00:00', $schoolYearStartYear + 1);
$schoolYearLabel = sprintf('%04d/%04d', $schoolYearStartYear, $schoolYearStartYear + 1);
$unenrollWhereSql = "action IN ('MAIL_SENT', 'BULK_MAIL_SENT', 'BULK_MAIL_SENT_DEV') AND request_id > 0";
$unenrollStats = [
    'total' => 0,
    'school_year' => 0,
    'today' => 0,
    'last_30_days' => 0,
];

$statsQueries = [
    'total' => "SELECT COUNT(DISTINCT request_id) AS count FROM request_audit_log WHERE {$unenrollWhereSql}",
    'school_year' => "SELECT COUNT(DISTINCT request_id) AS count FROM request_audit_log WHERE {$unenrollWhereSql} AND created_at >= ? AND created_at < ?",
    'today' => "SELECT COUNT(DISTINCT request_id) AS count FROM request_audit_log WHERE {$unenrollWhereSql} AND DATE(created_at) = CURDATE()",
    'last_30_days' => "SELECT COUNT(DISTINCT request_id) AS count FROM request_audit_log WHERE {$unenrollWhereSql} AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
];

foreach ($statsQueries as $statKey => $statsSql) {
    $statsStmt = $mysqli->prepare($statsSql);
    if ($statsStmt) {
        if ($statKey === 'school_year') {
            $statsStmt->bind_param('ss', $schoolYearStart, $schoolYearEnd);
        }
        if ($statsStmt->execute()) {
            $unenrollStats[$statKey] = (int) ($statsStmt->get_result()->fetch_assoc()['count'] ?? 0);
        }
        $statsStmt->close();
    }
}

$adminUnenrollCounts = [];
$adminStatsResult = $mysqli->query(
    "SELECT admin_user, COUNT(DISTINCT request_id) AS count
     FROM request_audit_log
     WHERE {$unenrollWhereSql}
     GROUP BY admin_user"
);
if ($adminStatsResult) {
    while ($adminStatsRow = $adminStatsResult->fetch_assoc()) {
        $adminName = audit_normalize_admin_user($adminStatsRow['admin_user'] ?? '');
        $adminUnenrollCounts[$adminName] = ($adminUnenrollCounts[$adminName] ?? 0) + (int) $adminStatsRow['count'];
    }
    arsort($adminUnenrollCounts, SORT_NUMERIC);
}

$baseParams = ['q' => $searchTerm, 'user' => $filterUser, 'action' => $filterAction];
$fromRow = $totalRows === 0 ? 0 : $offset + 1;
$toRow = min($offset + $perPage, $totalRows);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="<?=audit_h($faviconPath)?>">
<title>Audit-Log</title>
<style>
:root {
    --disown-site-image: url("<?=audit_h($siteImagePath)?>");
}
</style>
<link rel="stylesheet" href="<?=audit_h($auditLogCssUrl)?>">
</head>
<body>
<div class="page">
    <div class="header">
        <div class="header-main">
            <h1 class="page-title">Audit-Log</h1>
            <p class="hint-text">Neueste Einträge zuerst. Details werden kompakt und umbrochen angezeigt.</p>
        </div>
        <div class="header-actions">
            <a class="admin-user" href="<?=audit_h($logoutPath)?>"><span class="admin-user-icon">👤</span><span class="admin-user-name"><?=htmlspecialchars($currentAdminUser)?></span></a>
            <a class="button button-secondary admin-nav-link admin-home-link" href="<?=audit_h($adminPath)?>">Adminportal</a>
            <a class="button button-secondary admin-nav-link" href="<?=audit_h($adePath)?>">ADE-Aufnahmen</a>
            <a class="button button-secondary admin-nav-link" href="<?=audit_h($kukPath)?>">KUK-Geräte</a>
            <a class="button button-secondary export-link" href="<?=htmlspecialchars($exportUrl)?>">CSV exportieren</a>
        </div>
    </div>

    <section class="audit-dashboard" aria-label="Freigabe-Dashboard">
        <div class="audit-metrics">
            <div class="metric-card">
                <span class="metric-label">Erledigte Freigaben gesamt</span>
                <span class="metric-value"><?=audit_h((string) $unenrollStats['total'])?></span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Heute</span>
                <span class="metric-value"><?=audit_h((string) $unenrollStats['today'])?></span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Letzte 30 Tage</span>
                <span class="metric-value"><?=audit_h((string) $unenrollStats['last_30_days'])?></span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Schuljahr <?=audit_h($schoolYearLabel)?></span>
                <span class="metric-value"><?=audit_h((string) $unenrollStats['school_year'])?></span>
            </div>
        </div>
        <div class="audit-admins">
            <div class="audit-admins-title">Erledigte Freigaben nach Admin</div>
            <?php if ($adminUnenrollCounts): ?>
                <?php foreach ($adminUnenrollCounts as $adminName => $adminCount): ?>
                    <div class="admin-rank">
                        <span class="admin-rank-name" title="<?=audit_h($adminName)?>"><?=audit_h($adminName)?></span>
                        <span class="admin-rank-count"><?=audit_h((string) $adminCount)?></span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-admin-stats">Noch keine vollständig erledigten Freigaben im Audit-Log.</div>
            <?php endif; ?>
        </div>
    </section>

    <form class="search-toolbar" method="get" action="<?=audit_h($auditLogPath)?>">
        <label for="auditSearch">Suche</label>
        <div class="search-input-wrap">
            <input id="auditSearch" class="search-input" name="q" type="search" value="<?=audit_h($searchTerm)?>" placeholder="Benutzer, Aktion, Antrag-ID oder Details">
            <?php if ($searchTerm !== ''): ?>
                <a class="clear-search" href="<?=audit_h(audit_url(array_merge($baseParams, ['q' => '', 'page' => 1])))?>" aria-label="Suche löschen">×</a>
            <?php endif; ?>
        </div>
        <?php if ($filterUser !== ''): ?>
            <input type="hidden" name="user" value="<?=audit_h($filterUser)?>">
        <?php endif; ?>
        <?php if ($filterAction !== ''): ?>
            <input type="hidden" name="action" value="<?=audit_h($filterAction)?>">
        <?php endif; ?>
        <button class="button button-secondary" type="submit">Suchen</button>
        <a class="button button-secondary" href="<?=audit_h($auditLogPath)?>">Zurücksetzen</a>
    </form>

    <?php if ($hasFilters): ?>
        <div class="filter-summary">
            <span>Filter:</span>
            <?php if ($searchTerm !== ''): ?>
                <span class="filter-pill">Suche: <?=htmlspecialchars($searchTerm)?></span>
            <?php endif; ?>
            <?php if ($filterUser !== ''): ?>
                <span class="filter-pill">Benutzer: <?=htmlspecialchars($filterUser)?></span>
            <?php endif; ?>
            <?php if ($filterAction !== ''): ?>
                <span class="filter-pill">Aktion: <?=htmlspecialchars($filterAction)?></span>
            <?php endif; ?>
            <a class="filter-pill" href="<?=audit_h($auditLogPath)?>">Filter zurücksetzen</a>
        </div>
    <?php endif; ?>

    <div class="card table-wrap">
        <div class="result-summary">
            <span><?=audit_h((string) $fromRow)?>-<?=audit_h((string) $toRow)?> von <?=audit_h((string) $totalRows)?> Einträgen</span>
            <span>Seite <?=audit_h((string) $page)?> von <?=audit_h((string) $totalPages)?></span>
        </div>
        <table>
            <thead>
                <tr>
                    <th class="time-cell">Zeit</th>
                    <th class="user-cell">Benutzer</th>
                    <th class="action-cell">Aktion</th>
                    <th class="request-cell">Antrag-ID</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td class="time-cell" data-label="Zeit">
                            <span class="time-date"><?=audit_h(date('d.m.Y', strtotime($row['created_at'])))?></span>
                            <span class="time-time"><?=audit_h(date('H:i:s', strtotime($row['created_at'])))?></span>
                        </td>
                        <td class="user-cell" data-label="Benutzer"><a class="table-link" href="<?=audit_h(audit_url(array_merge($baseParams, ['user' => $row['admin_user'], 'page' => 1])))?>" title="<?=audit_h($row['admin_user'])?>"><?=audit_h(audit_display_user($row['admin_user']))?></a></td>
                        <td class="action-cell" data-label="Aktion"><a class="table-link action-pill" href="<?=audit_h(audit_url(array_merge($baseParams, ['action' => $row['action'], 'page' => 1])))?>" title="<?=audit_h($row['action'])?>"><?=audit_h(audit_display_action($row['action']))?></a></td>
                        <td class="request-cell" data-label="Antrag"><?=audit_h($row['request_id'])?></td>
                        <td class="details" data-label="Details"><?=audit_h($row['details'] ?? '')?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <nav class="pagination" aria-label="Seitennavigation">
            <span>Seite <?=audit_h((string) $page)?> von <?=audit_h((string) $totalPages)?></span>
            <div class="pagination-links">
                <a class="pagination-link <?=$page <= 1 ? 'disabled' : ''?>" href="<?=audit_h(audit_url(array_merge($baseParams, ['page' => 1])))?>">« Erste</a>
                <a class="pagination-link <?=$page <= 1 ? 'disabled' : ''?>" href="<?=audit_h(audit_url(array_merge($baseParams, ['page' => max(1, $page - 1)])))?>">‹ Zurück</a>
                <a class="pagination-link <?=$page >= $totalPages ? 'disabled' : ''?>" href="<?=audit_h(audit_url(array_merge($baseParams, ['page' => min($totalPages, $page + 1)])))?>">Weiter ›</a>
                <a class="pagination-link <?=$page >= $totalPages ? 'disabled' : ''?>" href="<?=audit_h(audit_url(array_merge($baseParams, ['page' => $totalPages])))?>">Letzte »</a>
            </div>
        </nav>
    </div>
</div>
<script src="<?=audit_h($searchJsUrl)?>" defer></script>
<script src="<?=audit_h($auditLogJsUrl)?>" defer></script>
</body>
</html>

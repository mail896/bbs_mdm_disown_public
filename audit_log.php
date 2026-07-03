<?php
require __DIR__ . '/auth.php';
disown_require_admin();
require 'db.php';

$currentAdminUser = disown_current_admin_user();
$appBasePath = rtrim(disown_admin_base_path(), '/');
$adminPath = $appBasePath . '/admin.php';
$auditLogPath = $appBasePath . '/audit_log.php';
$logoutPath = $appBasePath . '/logout.php';
$faviconPath = $appBasePath . '/favicon.svg';
$siteImagePath = $appBasePath . '/images/Site-Image.png';

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
    return preg_replace('/@.+$/', '', $user) ?? $user;
}

function audit_normalize_admin_user(?string $value): string
{
    $user = trim((string) $value);
    if ($user === '') {
        return 'unbekannt';
    }

    return audit_display_user($user);
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
        'MAIL_SENT' => 'Mail gesendet',
        'BULK_JAMF_UNENROLL_SUCCESS' => 'Bulk Jamf',
        'BULK_JAMF_UNENROLL_FAILED' => 'Bulk Jamf Fehler',
        'BULK_ASM_DONE' => 'Bulk ASM',
        'BULK_MAIL_SENT' => 'Bulk Mail',
        'BULK_MAIL_SENT_DEV' => 'Bulk Mail DEV',
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
    color-scheme: light;
    font-family: Inter, Arial, sans-serif;
    color: #1f2937;
    background: #f3f5f9;
}
* {
    box-sizing: border-box;
}
body {
    margin: 0;
    min-height: 100vh;
    background:
        linear-gradient(rgba(243, 245, 249, 0.86), rgba(243, 245, 249, 0.94)),
        url("<?=audit_h($siteImagePath)?>") center top / min(1717px, 118vw) auto no-repeat fixed;
}
.page {
    max-width: 1280px;
    margin: 0 auto;
    padding: 24px;
}
.header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
    margin-bottom: 20px;
}
.header-main {
    flex: 1 1 360px;
    min-width: 0;
}
.page-title {
    font-size: 1.75rem;
    margin: 0;
}
.hint-text {
    margin: 8px 0 0;
    color: #64748b;
    font-size: 0.95rem;
}
.header-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    flex: 0 0 auto;
    gap: 10px;
    min-width: max-content;
}
.admin-user {
    align-items: center;
    color: #64748b;
    display: inline-flex;
    font-size: 0.95rem;
    gap: 0.35rem;
    justify-content: flex-end;
    max-width: none;
    min-width: 8rem;
    overflow: visible;
    text-decoration: none;
    white-space: nowrap;
}
.admin-user-icon,
.admin-user-name {
    display: inline-block;
    flex: 0 0 auto;
}
.admin-user:hover {
    text-decoration: underline;
}
.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    padding: 0.65rem 1rem;
    border-radius: 999px;
    text-decoration: none;
    font-weight: 600;
    border: 1px solid transparent;
    cursor: pointer;
}
.button-secondary {
    background: #e2e8f0;
    color: #1f2937;
}
.export-link {
    font-size: 0.9rem;
    font-weight: 500;
    padding: 0.45rem 0.75rem;
}
.search-toolbar {
    align-items: center;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 14px;
    padding: 12px;
}
.search-toolbar label {
    color: #334155;
    font-weight: 700;
}
.search-input-wrap {
    flex: 1 1 360px;
    min-width: 240px;
    position: relative;
}
.search-input {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    color: #111827;
    font: inherit;
    min-height: 42px;
    padding: 0.55rem 2.45rem 0.55rem 0.75rem;
    width: 100%;
}
.search-input::-webkit-search-cancel-button,
.search-input::-webkit-search-decoration {
    appearance: none;
}
.clear-search {
    align-items: center;
    background: #e2e8f0;
    border-radius: 999px;
    color: #334155;
    display: inline-flex;
    font-size: 1.05rem;
    font-weight: 800;
    height: 26px;
    justify-content: center;
    position: absolute;
    right: 8px;
    text-decoration: none;
    top: 50%;
    transform: translateY(-50%);
    width: 26px;
}
.audit-dashboard {
    display: grid;
    gap: 14px;
    grid-template-columns: minmax(0, 1.15fr) minmax(260px, 0.85fr);
    margin-bottom: 14px;
}
.audit-metrics,
.audit-admins {
    background: rgba(255, 255, 255, 0.94);
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
    padding: 14px;
}
.audit-metrics {
    align-items: stretch;
    display: grid;
    gap: 10px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}
.metric-card {
    border-left: 1px solid #e2e8f0;
    min-width: 0;
    padding-left: 12px;
}
.metric-card:first-child {
    border-left: 0;
    padding-left: 0;
}
.metric-label {
    color: #64748b;
    display: block;
    font-size: 0.78rem;
    font-weight: 700;
    line-height: 1.25;
    margin-bottom: 4px;
}
.metric-value {
    color: #1f2937;
    display: block;
    font-size: 1.28rem;
    font-weight: 800;
    line-height: 1.1;
}
.audit-admins-title {
    color: #334155;
    font-size: 0.9rem;
    font-weight: 800;
    margin-bottom: 10px;
}
.admin-rank {
    align-items: center;
    display: grid;
    gap: 10px;
    grid-template-columns: minmax(0, 1fr) auto;
    margin-top: 7px;
}
.admin-rank:first-of-type {
    margin-top: 0;
}
.admin-rank-name {
    color: #334155;
    font-size: 0.86rem;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.admin-rank-count {
    background: #e2e8f0;
    border-radius: 999px;
    color: #1f2937;
    font-size: 0.8rem;
    font-weight: 800;
    min-width: 2.4rem;
    padding: 0.25rem 0.55rem;
    text-align: center;
}
.empty-admin-stats {
    color: #64748b;
    font-size: 0.88rem;
}
.filter-summary {
    align-items: center;
    color: #64748b;
    display: flex;
    flex-wrap: wrap;
    font-size: 0.9rem;
    gap: 8px;
    margin-bottom: 14px;
}
.filter-pill {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    color: #334155;
    padding: 0.35rem 0.65rem;
    text-decoration: none;
}
.filter-pill:hover {
    background: #f8fafc;
    text-decoration: none;
}
.table-link {
    color: #2563eb;
    text-decoration: none;
}
.table-link:hover {
    text-decoration: underline;
}
.card {
    background: rgba(255, 255, 255, 0.94);
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 20px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
}
.card::before {
    background: url("<?=audit_h($siteImagePath)?>") center top / min(1500px, 115vw) auto no-repeat;
    content: "";
    inset: 0;
    opacity: 0.05;
    pointer-events: none;
    position: absolute;
}
.card > * {
    position: relative;
    z-index: 1;
}
.table-wrap {
    overflow-x: auto;
}
table {
    width: 100%;
    min-width: 0;
    border-collapse: collapse;
    table-layout: fixed;
}
th,
td {
    padding: 9px 10px;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.88rem;
    text-align: left;
    vertical-align: top;
}
th {
    background: #f8fafc;
    color: #334155;
    font-size: 0.95rem;
    font-weight: 600;
}
tr:hover {
    background: #f8fafc;
}
.nowrap {
    white-space: nowrap;
}
.time-cell {
    width: 7.5rem;
}
.time-date,
.time-time {
    display: block;
    white-space: nowrap;
}
.action-cell {
    width: 10.5rem;
}
.request-cell {
    width: 5.5rem;
}
.user-cell {
    width: 7.5rem;
}
.action-pill {
    background: #eef2ff;
    border-radius: 999px;
    color: #334155;
    display: inline-flex;
    font-size: 0.72rem;
    font-weight: 700;
    max-width: 100%;
    overflow-wrap: normal;
    padding: 0.22rem 0.48rem;
    white-space: nowrap;
}
.details {
    color: #475569;
    font-family: "SFMono-Regular", Consolas, monospace;
    font-size: 0.78rem;
    line-height: 1.45;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}
.result-summary,
.pagination {
    align-items: center;
    color: #64748b;
    display: flex;
    flex-wrap: wrap;
    font-size: 0.9rem;
    gap: 8px;
    justify-content: space-between;
    margin-top: 14px;
}
.pagination-links {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.pagination-link {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    color: #334155;
    padding: 0.45rem 0.75rem;
    text-decoration: none;
}
.pagination-link.disabled {
    color: #94a3b8;
    pointer-events: none;
}
@media (max-width: 720px) {
    .audit-dashboard {
        grid-template-columns: 1fr;
    }
    .audit-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .metric-card:nth-child(3) {
        border-left: 0;
        padding-left: 0;
    }
    .header {
        align-items: flex-start;
        flex-direction: column;
    }
    .header-actions {
        align-items: flex-start;
        min-width: 0;
        width: 100%;
    }
    .search-toolbar {
        align-items: stretch;
        flex-direction: column;
    }
    .search-input-wrap {
        min-width: 0;
        width: 100%;
    }
}
@media (max-width: 640px) {
    body {
        background-attachment: scroll;
        font-size: 15px;
    }
    .page {
        padding: 14px 10px;
    }
    .page-title {
        font-size: 1.55rem;
    }
    .header-actions {
        gap: 8px;
    }
    .header-actions .button,
    .export-link {
        width: 100%;
    }
    .audit-metrics {
        grid-template-columns: 1fr;
    }
    .metric-card,
    .metric-card:nth-child(3) {
        border-left: 0;
        border-top: 1px solid #e2e8f0;
        padding-left: 0;
        padding-top: 10px;
    }
    .metric-card:first-child {
        border-top: 0;
        padding-top: 0;
    }
    .search-toolbar {
        display: grid;
        gap: 8px;
        grid-template-columns: minmax(0, 1fr) auto;
        margin-bottom: 12px;
        padding: 10px;
    }
    .search-toolbar label {
        display: none;
    }
    .search-input {
        min-height: 42px;
        padding: 9px 42px 9px 12px;
    }
    .search-toolbar button[type="submit"] {
        min-height: 42px;
        padding: 0.55rem 0.85rem;
        width: auto;
    }
    .search-toolbar .button[href] {
        grid-column: 1 / -1;
        min-height: 40px;
        padding: 0.55rem 0.85rem;
        width: 100%;
    }
    .filter-summary {
        gap: 6px;
        margin-bottom: 10px;
    }
    .card {
        border-radius: 14px;
        padding: 12px;
    }
    .table-wrap {
        overflow-x: visible;
    }
    table,
    thead,
    tbody,
    tr,
    td {
        display: block;
        width: 100%;
    }
    table {
        table-layout: auto;
    }
    thead {
        display: none;
    }
    tbody tr {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        margin-bottom: 12px;
        padding: 10px 12px;
    }
    tbody tr:hover {
        background: #ffffff;
    }
    td {
        border-bottom: 0;
        display: grid;
        gap: 6px;
        grid-template-columns: minmax(84px, 32%) minmax(0, 1fr);
        padding: 8px 0;
    }
    td::before {
        color: #64748b;
        content: attr(data-label);
        font-size: 0.78rem;
        font-weight: 800;
        text-transform: uppercase;
    }
    .time-cell,
    .user-cell,
    .action-cell,
    .request-cell {
        width: auto;
    }
    .details {
        font-size: 0.76rem;
        max-height: 9rem;
        overflow: auto;
    }
    .result-summary,
    .pagination {
        align-items: flex-start;
        flex-direction: column;
    }
    .pagination-links {
        width: 100%;
    }
    .pagination-link {
        flex: 1 1 auto;
        text-align: center;
    }
}
</style>
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
            <a class="button button-secondary export-link" href="<?=htmlspecialchars($exportUrl)?>">CSV exportieren</a>
            <a class="button button-secondary" href="<?=audit_h($adminPath)?>">Zurück zur Übersicht</a>
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
<script>
const auditSearchInput = document.getElementById('auditSearch');
let auditSearchDebounceTimer = null;

if (auditSearchInput) {
    auditSearchInput.addEventListener('input', () => {
        clearTimeout(auditSearchDebounceTimer);
        auditSearchDebounceTimer = setTimeout(() => {
            const url = new URL(window.location.href);
            const term = auditSearchInput.value.trim();

            if (term) {
                url.searchParams.set('q', term);
            } else {
                url.searchParams.delete('q');
            }
            url.searchParams.set('page', '1');
            url.searchParams.set('live', '1');
            url.searchParams.delete('export');

            window.location.href = url.toString();
        }, 400);
    });

    if (new URL(window.location.href).searchParams.get('live') === '1') {
        auditSearchInput.focus();
        auditSearchInput.setSelectionRange(auditSearchInput.value.length, auditSearchInput.value.length);
    }
}
</script>
</body>
</html>

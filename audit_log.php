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

$filterUser = trim((string) ($_GET['user'] ?? ''));
$filterAction = trim((string) ($_GET['action'] ?? ''));
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

$whereSql = $whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '';
$exportParams = [];
if ($filterUser !== '') {
    $exportParams['user'] = $filterUser;
}
if ($filterAction !== '') {
    $exportParams['action'] = $filterAction;
}
$exportParams['export'] = 'csv';
$exportUrl = $auditLogPath . '?' . http_build_query($exportParams);
$hasFilters = $filterUser !== '' || $filterAction !== '';

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

$stmt = $mysqli->prepare(
    "SELECT created_at, admin_user, action, request_id, details
     FROM request_audit_log
     {$whereSql}
     ORDER BY created_at DESC, id DESC
     LIMIT 500"
);

if (!$stmt) {
    die('Datenbankfehler: ' . htmlspecialchars($mysqli->error));
}
if ($params) {
    $stmt->bind_param($types, ...$params);
}
if (!$stmt->execute()) {
    die('Datenbankfehler: ' . htmlspecialchars($stmt->error));
}
$result = $stmt->get_result();
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="<?=htmlspecialchars($faviconPath)?>">
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
    background: #f3f5f9;
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
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 20px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
}
.table-wrap {
    overflow-x: auto;
}
table {
    width: 100%;
    min-width: 920px;
    border-collapse: collapse;
}
th,
td {
    padding: 12px 14px;
    border-bottom: 1px solid #e5e7eb;
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
.details {
    color: #475569;
    line-height: 1.45;
}
@media (max-width: 720px) {
    .header {
        align-items: flex-start;
        flex-direction: column;
    }
    .header-actions {
        align-items: flex-start;
        min-width: 0;
        width: 100%;
    }
}
</style>
</head>
<body>
<div class="page">
    <div class="header">
        <div class="header-main">
            <h1 class="page-title">Audit-Log</h1>
            <p class="hint-text">Neueste Einträge zuerst, maximal 500 Einträge.</p>
        </div>
        <div class="header-actions">
            <a class="admin-user" href="<?=htmlspecialchars($logoutPath)?>"><span class="admin-user-icon">👤</span><span class="admin-user-name"><?=htmlspecialchars($currentAdminUser)?></span></a>
            <a class="button button-secondary export-link" href="<?=htmlspecialchars($exportUrl)?>">CSV exportieren</a>
            <a class="button button-secondary" href="<?=htmlspecialchars($adminPath)?>">Zurück zur Übersicht</a>
        </div>
    </div>

    <?php if ($hasFilters): ?>
        <div class="filter-summary">
            <span>Filter:</span>
            <?php if ($filterUser !== ''): ?>
                <span class="filter-pill">Benutzer: <?=htmlspecialchars($filterUser)?></span>
            <?php endif; ?>
            <?php if ($filterAction !== ''): ?>
                <span class="filter-pill">Aktion: <?=htmlspecialchars($filterAction)?></span>
            <?php endif; ?>
            <a class="filter-pill" href="<?=htmlspecialchars($auditLogPath)?>">Filter zurücksetzen</a>
        </div>
    <?php endif; ?>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Zeit</th>
                    <th>Benutzer</th>
                    <th>Aktion</th>
                    <th>Antrag-ID</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td class="nowrap"><?=htmlspecialchars(date('d.m.Y H:i:s', strtotime($row['created_at'])))?></td>
                        <td class="nowrap"><a class="table-link" href="<?=htmlspecialchars($auditLogPath)?>?user=<?=htmlspecialchars(rawurlencode($row['admin_user']))?>"><?=htmlspecialchars($row['admin_user'])?></a></td>
                        <td class="nowrap"><a class="table-link" href="<?=htmlspecialchars($auditLogPath)?>?action=<?=htmlspecialchars(rawurlencode($row['action']))?>"><?=htmlspecialchars($row['action'])?></a></td>
                        <td class="nowrap"><?=htmlspecialchars($row['request_id'])?></td>
                        <td class="details"><?=htmlspecialchars($row['details'] ?? '')?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>

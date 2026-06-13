<?php
require __DIR__ . '/auth.php';
disown_require_admin();
require __DIR__ . '/db.php';

$currentAdminUser = disown_current_admin_user();
$isDevMode = basename(__DIR__) === 'disown-dev';
$appVersion = $isDevMode ? '1.6-dev' : '1.6';

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
$exportUrl = 'ade.php?' . http_build_query(array_filter($exportParams, static fn ($value) => $value !== '' && $value !== null));
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
    return 'ade.php?' . http_build_query(array_filter($params, static fn ($value) => $value !== '' && $value !== null));
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<title>ADE-Aufnahmen</title>
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
    max-width: 1480px;
    margin: 0 auto;
    padding: 24px;
}
.header {
    align-items: flex-start;
    display: flex;
    gap: 18px;
    justify-content: space-between;
    margin-bottom: 22px;
}
.page-title {
    font-size: 2rem;
    margin: 0;
}
.hint-text {
    color: #64748b;
    font-size: 0.95rem;
    margin: 8px 0 0;
}
.header-actions {
    align-items: flex-end;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.admin-user {
    color: #64748b;
    text-decoration: none;
    white-space: nowrap;
}
.button,
button {
    align-items: center;
    border: 1px solid transparent;
    border-radius: 999px;
    cursor: pointer;
    display: inline-flex;
    font-weight: 700;
    justify-content: center;
    min-height: 44px;
    padding: 0.65rem 1rem;
    text-decoration: none;
}
.button-primary {
    background: #2563eb;
    color: #fff;
}
.button-secondary {
    background: #e2e8f0;
    color: #1f2937;
}
.toolbar,
.stats {
    align-items: center;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 18px;
    padding: 14px;
}
.stat {
    border-right: 1px solid #e2e8f0;
    color: #64748b;
    padding: 0.15rem 0.85rem 0.15rem 0;
}
.stat:last-child {
    border-right: 0;
}
.stat strong {
    color: #1f2937;
}
.stat.warn strong {
    color: #b45309;
}
.stat.danger strong {
    color: #b91c1c;
}
.stat.ok strong {
    color: #047857;
}
.search {
    align-items: center;
    display: grid;
    gap: 10px;
    grid-template-columns: auto minmax(240px, 1fr) auto auto auto auto;
    width: 100%;
}
label {
    color: #334155;
    font-weight: 700;
}
input,
select {
    background: #fff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    color: #111827;
    font: inherit;
    min-height: 44px;
    padding: 0.65rem 0.85rem;
}
.search-field {
    min-width: 0;
    position: relative;
}
.search-field input {
    padding-right: 2.6rem;
    width: 100%;
}
.clear-search {
    align-items: center;
    background: #e2e8f0;
    border-radius: 999px;
    color: #334155;
    display: inline-flex;
    font-size: 1.2rem;
    font-weight: 800;
    height: 28px;
    justify-content: center;
    line-height: 1;
    position: absolute;
    right: 8px;
    text-decoration: none;
    top: 50%;
    transform: translateY(-50%);
    width: 28px;
}
.clear-search:hover {
    background: #cbd5e1;
}
.tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 18px;
}
.tab {
    background: #fff;
    border-radius: 999px;
    color: #1f2937;
    font-weight: 700;
    padding: 0.75rem 1.1rem;
    text-decoration: none;
}
.tab.active {
    background: #1f2937;
    color: #fff;
}
.table-wrap {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow-x: auto;
    padding: 16px;
}
table {
    border-collapse: collapse;
    min-width: 1280px;
    width: 100%;
}
th,
td {
    border-bottom: 1px solid #e5e7eb;
    padding: 0.9rem 0.75rem;
    text-align: left;
    vertical-align: top;
}
th {
    background: #f8fafc;
    color: #334155;
    font-weight: 800;
}
tr:last-child td {
    border-bottom: 0;
}
.mono {
    font-family: "SFMono-Regular", Consolas, monospace;
}
.muted {
    color: #64748b;
}
.subline {
    color: #64748b;
    font-size: 0.88rem;
    margin-top: 0.2rem;
}
.badge {
    border-radius: 999px;
    display: inline-flex;
    font-size: 0.85rem;
    font-weight: 800;
    padding: 0.3rem 0.55rem;
}
.badge-warn {
    background: #fef3c7;
    color: #92400e;
}
.badge-danger {
    background: #fee2e2;
    color: #991b1b;
}
.badge-ok {
    background: #dcfce7;
    color: #166534;
}
.pagination {
    align-items: center;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: 16px;
}
.empty {
    color: #64748b;
    padding: 24px;
    text-align: center;
}
.footer {
    color: #64748b;
    font-size: 0.9rem;
    margin-top: 24px;
}
@media (max-width: 900px) {
    .header,
    .header-actions {
        align-items: stretch;
        flex-direction: column;
    }
    .search {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<div class="page">
    <header class="header">
        <div>
            <h1 class="page-title">ADE-Aufnahmen</h1>
            <p class="hint-text">Neue und erneut zugewiesene ASM/ADE-Geräte mit Jamf-Abgleich anzeigen.</p>
        </div>
        <div class="header-actions">
            <a class="admin-user" href="logout.php">👤 <?=ade_h($currentAdminUser ?: 'Admin')?></a>
            <div>
                <a class="button button-secondary" href="admin.php">Adminportal</a>
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
            <a class="button button-secondary" href="ade.php">Zurücksetzen</a>
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
                            <td>
                                <?=ade_h(ade_display_datetime($row['asm_added_at'] ?? null))?>
                                <div class="subline">aktualisiert: <?=ade_h(ade_display_datetime($row['asm_updated_at'] ?? null))?></div>
                            </td>
                            <td>
                                <div class="mono"><?=ade_h($row['serial'])?></div>
                                <div class="subline"><?=ade_h(ade_display_value($row['asm_model'] ?? null))?></div>
                            </td>
                            <td>
                                <strong><?=ade_h(ade_display_value($row['asm_mdm_server_name'] ?? null))?></strong>
                                <div class="subline">Order: <?=ade_h(ade_display_value($row['asm_order_number'] ?? null))?></div>
                                <div class="subline">Quelle: <?=ade_h(ade_display_value($row['asm_purchase_source_type'] ?? null))?></div>
                            </td>
                            <td>
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
                            <td>
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
        © 2026 Project maintainer · Version <?=ade_h($appVersion)?> · ADE-Aufnahmen
    </footer>
</div>
<script>
const adeSearchForm = document.getElementById('adeSearchForm');
const adeSearchInput = document.getElementById('q');
const adeDaysSelect = document.getElementById('daysSelect');
let adeSearchTimer = null;

function submitAdeSearch() {
    if (!adeSearchForm) {
        return;
    }
    if (typeof adeSearchForm.requestSubmit === 'function') {
        adeSearchForm.requestSubmit();
        return;
    }
    adeSearchForm.submit();
}

if (adeSearchInput) {
    if (adeSearchInput.value !== '') {
        window.setTimeout(() => {
            adeSearchInput.focus();
            adeSearchInput.setSelectionRange(adeSearchInput.value.length, adeSearchInput.value.length);
        }, 0);
    }

    adeSearchInput.addEventListener('input', () => {
        window.clearTimeout(adeSearchTimer);
        adeSearchTimer = window.setTimeout(submitAdeSearch, 450);
    });
}

if (adeDaysSelect) {
    adeDaysSelect.addEventListener('change', submitAdeSearch);
}
</script>
</body>
</html>

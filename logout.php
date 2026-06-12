<?php
$currentAdminUser = trim((string) ($_SERVER['REMOTE_USER'] ?? ''));
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Abmelden</title>
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
    max-width: 760px;
    margin: 0 auto;
    padding: 24px;
}
.card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
}
.page-title {
    font-size: 1.75rem;
    margin: 0 0 12px;
}
.hint-text {
    color: #475569;
    line-height: 1.6;
    margin: 0 0 20px;
}
.admin-user {
    color: #64748b;
    font-size: 0.95rem;
    margin-bottom: 16px;
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
    background: #e2e8f0;
    color: #1f2937;
}
</style>
</head>
<body>
<div class="page">
    <div class="card">
        <h1 class="page-title">Abmelden</h1>
        <?php if ($currentAdminUser !== ''): ?>
            <div class="admin-user">👤 <?=htmlspecialchars($currentAdminUser)?></div>
        <?php endif; ?>
        <p class="hint-text">Sie verwenden eine Browser-Anmeldung. Um sich vollständig abzumelden, schließen Sie bitte dieses Browserfenster oder beenden Sie den Browser.</p>
        <a class="button" href="admin">Zurück zur Anmeldung</a>
    </div>
</div>
</body>
</html>

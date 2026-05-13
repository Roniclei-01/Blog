<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
    header("Location: login.php");
    exit;
}

if (($_SESSION['admin_nivel'] ?? '') !== 'super') {
    http_response_code(403);
    exit('Acesso negado.');
}

if (!tvr_is_same_origin_request()) {
    http_response_code(403);
    exit('Origem invalida.');
}

if (empty($_GET['csrf']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_GET['csrf'])) {
    http_response_code(403);
    exit('Requisicao invalida (CSRF).');
}

require_once __DIR__ . '/../db.php';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="newsletter_' . date('Ymd_His') . '.csv"');

echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Email', 'Status', 'Criado em', 'Confirmado em'], ';');

$sql = "
    SELECT id, email, status, created_at, confirmed_at
    FROM newsletter_subscribers
    ORDER BY created_at DESC
";

$res = $conn->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            $r['id'],
            $r['email'],
            $r['status'],
            $r['created_at'],
            $r['confirmed_at'],
        ], ';');
    }
}

fclose($out);
$conn->close();
exit;

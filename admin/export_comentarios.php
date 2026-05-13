<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
    header("Location: login.php");
    exit;
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

// ----------------------------------------
// Filtro de periodo
// period: 7d | 30d | 90d | all
// ou custom: from=YYYY-MM-DD&to=YYYY-MM-DD
// ----------------------------------------
$period = (string)($_GET['period'] ?? '30d');
$allowed = ['7d', '30d', '90d', 'all'];
if (!in_array($period, $allowed, true)) {
    $period = '30d';
}

$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));

$where = '';
$params = [];
$types = '';

$customOk = false;
if ($from !== '' && $to !== '') {
    $d1 = DateTime::createFromFormat('Y-m-d', $from);
    $d2 = DateTime::createFromFormat('Y-m-d', $to);

    if ($d1 && $d2 && $d1->format('Y-m-d') === $from && $d2->format('Y-m-d') === $to && $from <= $to) {
        $customOk = true;
        $where = "WHERE c.created_at >= ? AND c.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
        $types = 'ss';
        $params[] = $from . ' 00:00:00';
        $params[] = $to . ' 00:00:00';
    }
}

if (!$customOk) {
    if ($period === '7d') {
        $where = "WHERE c.created_at >= (NOW() - INTERVAL 7 DAY)";
    } elseif ($period === '30d') {
        $where = "WHERE c.created_at >= (NOW() - INTERVAL 30 DAY)";
    } elseif ($period === '90d') {
        $where = "WHERE c.created_at >= (NOW() - INTERVAL 90 DAY)";
    } else {
        $where = '';
    }
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="comentarios_' . date('Ymd_His') . '.csv"');

// BOM UTF-8 para Excel
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Artigo ID', 'Artigo', 'Nome', 'Email', 'Status', 'Conteudo', 'Data', 'Aprovado em'], ';');

$sql = "
    SELECT
      c.id, c.artigo_id, a.titulo AS artigo_titulo, c.nome, c.email, c.status, c.conteudo, c.created_at, c.aprovado_em
    FROM comentarios c
    INNER JOIN artigos a ON a.id = c.artigo_id
    $where
    ORDER BY c.created_at DESC
";

if ($types !== '') {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            $r['id'],
            $r['artigo_id'],
            $r['artigo_titulo'],
            $r['nome'],
            $r['email'],
            $r['status'],
            preg_replace('/\s+/', ' ', (string)$r['conteudo']),
            $r['created_at'],
            $r['aprovado_em'],
        ], ';');
    }
    $stmt->close();
} else {
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            fputcsv($out, [
                $r['id'],
                $r['artigo_id'],
                $r['artigo_titulo'],
                $r['nome'],
                $r['email'],
                $r['status'],
                preg_replace('/\s+/', ' ', (string)$r['conteudo']),
                $r['created_at'],
                $r['aprovado_em'],
            ], ';');
        }
    }
}

fclose($out);
$conn->close();
exit;

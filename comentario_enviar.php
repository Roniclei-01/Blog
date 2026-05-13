<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
tvr_secure_session_start(false);
tvr_security_headers(false);

require_once __DIR__ . '/db.php';

function go_article(int $id, string $cm): void
{
    header('Location: single.php?id=' . $id . '&cm=' . urlencode($cm));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$artigo_id = (int)($_POST['artigo_id'] ?? 0);
if ($artigo_id <= 0) {
    header('Location: index.php');
    exit;
}

// Honeypot anti-bot
if (!empty($_POST['website'] ?? '')) {
    go_article($artigo_id, 'ok');
}

if (!tvr_is_same_origin_request()) {
    go_article($artigo_id, 'csrf');
}

// Rate limit em sessão/IP (camada extra)
if (tvr_rate_limit_hit('comment_submit', 10, 300)) {
    go_article($artigo_id, 'flood');
}

// CSRF do formulário público
$csrf = (string)($_POST['comment_csrf'] ?? '');
if (!tvr_csrf_validate($csrf, 'comment_csrf')) {
    go_article($artigo_id, 'csrf');
}

// Verifica artigo existente
$stmt = $conn->prepare("SELECT id FROM artigos WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $artigo_id);
$stmt->execute();
$exists = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$exists) {
    header('Location: index.php');
    exit;
}

$nome = trim((string)($_POST['nome'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$conteudo = trim((string)($_POST['conteudo'] ?? ''));

if (strlen($nome) < 2 || strlen($nome) > 120) {
    go_article($artigo_id, 'nome');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    go_article($artigo_id, 'email');
}

if (strlen($conteudo) < 5 || strlen($conteudo) > 3000) {
    go_article($artigo_id, 'conteudo');
}

$ip = tvr_client_ip();
$user_agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

// 1 comentário por minuto por artigo/IP
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM comentarios
    WHERE artigo_id = ?
      AND ip_address = ?
      AND created_at >= (NOW() - INTERVAL 60 SECOND)
");
$stmt->bind_param("is", $artigo_id, $ip);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ((int)($row['total'] ?? 0) > 0) {
    go_article($artigo_id, 'flood');
}

// Máximo 6 comentários por IP em 10 minutos (global)
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM comentarios
    WHERE ip_address = ?
      AND created_at >= (NOW() - INTERVAL 10 MINUTE)
");
$stmt->bind_param("s", $ip);
$stmt->execute();
$rowGlobal = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ((int)($rowGlobal['total'] ?? 0) >= 6) {
    go_article($artigo_id, 'flood');
}

$stmt = $conn->prepare("
    INSERT INTO comentarios (artigo_id, nome, email, conteudo, status, ip_address, user_agent)
    VALUES (?, ?, ?, ?, 'pendente', ?, ?)
");
$stmt->bind_param("isssss", $artigo_id, $nome, $email, $conteudo, $ip, $user_agent);
$ok = $stmt->execute();
$stmt->close();

tvr_csrf_rotate('comment_csrf');

if ($ok) {
    go_article($artigo_id, 'ok');
}

go_article($artigo_id, 'erro');

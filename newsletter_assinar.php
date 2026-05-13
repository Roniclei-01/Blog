<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
tvr_secure_session_start(false);
tvr_security_headers(false);

require_once __DIR__ . '/db.php';

function redirect_home(string $code): void
{
    header('Location: index.php?nl=' . urlencode($code));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_home('metodo');
}

if (!tvr_is_same_origin_request()) {
    tvr_csrf_rotate('newsletter_csrf');
    redirect_home('csrf');
}

if (tvr_rate_limit_hit('newsletter_submit', 5, 900)) {
    tvr_csrf_rotate('newsletter_csrf');
    redirect_home('muitas_tentativas');
}

if (!tvr_csrf_validate((string)($_POST['newsletter_csrf'] ?? ''), 'newsletter_csrf')) {
    tvr_csrf_rotate('newsletter_csrf');
    redirect_home('csrf');
}

// Honeypot anti-bot
if (!empty($_POST['website'] ?? '')) {
    tvr_csrf_rotate('newsletter_csrf');
    redirect_home('confirmar_email');
}

$email = trim((string)($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    tvr_csrf_rotate('newsletter_csrf');
    redirect_home('email_invalido');
}

$token = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+48 hours'));

$ip = tvr_client_ip();
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

// Limite diário por IP (defesa extra)
$stmtIp = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM newsletter_subscribers
    WHERE ip_address = ?
      AND created_at >= (NOW() - INTERVAL 1 DAY)
");
$stmtIp->bind_param("s", $ip);
$stmtIp->execute();
$ipRow = $stmtIp->get_result()->fetch_assoc();
$stmtIp->close();

if ((int)($ipRow['total'] ?? 0) >= 20) {
    tvr_csrf_rotate('newsletter_csrf');
    redirect_home('muitas_tentativas');
}

// Verifica se já existe
$stmt = $conn->prepare("SELECT id, status FROM newsletter_subscribers WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    $id = (int)$existing['id'];
    $status = (string)$existing['status'];

    if ($status === 'active') {
        tvr_csrf_rotate('newsletter_csrf');
        redirect_home('ja_ativo');
    }

    $stmt = $conn->prepare("
        UPDATE newsletter_subscribers
        SET status='pending', confirm_token=?, token_expires_at=?, ip_address=?, user_agent=?
        WHERE id=?
    ");
    $stmt->bind_param("ssssi", $token, $expiresAt, $ip, $ua, $id);
    $stmt->execute();
    $stmt->close();
} else {
    $stmt = $conn->prepare("
        INSERT INTO newsletter_subscribers
        (email, status, confirm_token, token_expires_at, ip_address, user_agent)
        VALUES (?, 'pending', ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssss", $email, $token, $expiresAt, $ip, $ua);
    $stmt->execute();
    $stmt->close();
}

// Monta URL de confirmação
$scheme = tvr_is_https() ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/.');
$confirmUrl = $scheme . '://' . $host . ($basePath ? $basePath : '') . '/newsletter_confirmar.php?t=' . urlencode($token);

// Envio de email (mail nativo)
$subject = 'Confirme sua inscricao - TechVidaReal';
$message = "Ola!\n\nConfirme sua inscricao clicando no link abaixo:\n\n{$confirmUrl}\n\nEsse link expira em 48 horas.\n\nSe nao foi voce, ignore este e-mail.";
$headers = "From: no-reply@techvidareal.local\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$sent = @mail($email, $subject, $message, $headers);

// Debug local sem SMTP
if (!$sent && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    $_SESSION['newsletter_debug_link'] = $confirmUrl;
}

tvr_csrf_rotate('newsletter_csrf');
redirect_home('confirmar_email');

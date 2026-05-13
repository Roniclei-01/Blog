<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$token = trim((string)($_GET['t'] ?? ''));
$msg = '';
$type = 'erro';

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    $msg = 'Token inválido.';
} else {
    $stmt = $conn->prepare("
        SELECT id, email
        FROM newsletter_subscribers
        WHERE confirm_token = ?
          AND status = 'pending'
          AND token_expires_at >= NOW()
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $msg = 'Link inválido ou expirado.';
    } else {
        $id = (int)$row['id'];

        $stmt = $conn->prepare("
            UPDATE newsletter_subscribers
            SET status='active',
                confirmed_at=NOW(),
                confirm_token=NULL,
                token_expires_at=NULL
            WHERE id=?
            LIMIT 1
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        $msg = 'Inscrição confirmada com sucesso.';
        $type = 'ok';
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confirmação Newsletter</title>
<style>
body { font-family: Arial, sans-serif; background:#f8fafc; margin:0; padding:40px; }
.box { max-width:680px; margin:0 auto; background:#fff; border-radius:12px; padding:24px; border:1px solid #e2e8f0; }
.ok { background:#f0fdf4; color:#166534; border-left:4px solid #22c55e; padding:14px; border-radius:8px; }
.erro { background:#fef2f2; color:#991b1b; border-left:4px solid #ef4444; padding:14px; border-radius:8px; }
a { color:#0ea5e9; text-decoration:none; font-weight:600; }
</style>
</head>
<body>
  <div class="box">
    <h2>Newsletter Codexa</h2>
    <div class="<?= $type === 'ok' ? 'ok' : 'erro' ?>"><?= h($msg) ?></div>
    <p style="margin-top:18px;"><a href="index.php">Voltar para a home</a></p>
  </div>
</body>
</html>

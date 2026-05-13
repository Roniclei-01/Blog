<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
    header("Location: login.php");
    exit;
}

if (($_SESSION['admin_nivel'] ?? '') !== 'super') {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Escolher Painel</title>
</head>
<body>

<h2>Bem-vindo, Super Admin</h2>
<p>Escolha para onde deseja ir:</p>

<a href="index.php">
    <button type="button">Entrar como Editor</button>
</a>

<a href="gerenciar_admins.php">
    <button type="button">Gerenciar Administradores</button>
</a>

</body>
</html>

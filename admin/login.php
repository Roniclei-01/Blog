<?php
declare(strict_types=1);

// ================================================
// admin/login.php - Login com nivel (super/editor)
// Protecoes: session hardening, headers, CSRF, brute force
// ================================================
require_once __DIR__ . '/bootstrap.php';

// Se ja estiver logado, vai direto para o painel
if (isset($_SESSION['admin_logado']) && $_SESSION['admin_logado'] === true) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/../db.php';

$erro = '';
$usuario_preenchido = '';
$csrfToken = tvr_csrf_get('csrf_token');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit adicional por IP/sessao (defesa extra)
    if (tvr_rate_limit_hit('admin_login_post', 20, 300)) {
        $erro = 'Muitas tentativas no momento. Aguarde alguns minutos.';
        tvr_csrf_rotate('csrf_token');
    } elseif (!tvr_is_same_origin_request()) {
        $erro = 'Origem da requisicao invalida.';
        tvr_csrf_rotate('csrf_token');
    } elseif (!tvr_csrf_validate((string)($_POST['csrf_token'] ?? ''), 'csrf_token')) {
        $erro = 'Requisicao invalida. Tente novamente.';
        tvr_csrf_rotate('csrf_token');
    } else {
        $usuario_preenchido = trim((string)($_POST['usuario'] ?? ''));
        $senha = (string)($_POST['senha'] ?? '');

        $stmt = $conn->prepare(
            "SELECT id, usuario, senha_hash, nivel, ativo, tentativas, bloqueado_ate
             FROM admins
             WHERE usuario = ?
             LIMIT 1"
        );

        if (!$stmt) {
            $erro = 'Erro interno ao processar login.';
        } else {
            $stmt->bind_param("s", $usuario_preenchido);
            $stmt->execute();
            $admin = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$admin) {
                $erro = 'Usuario ou senha incorretos.';
                usleep(250000);
            } else {
                if ((int)$admin['ativo'] !== 1) {
                    $erro = 'Conta inativa. Fale com um super admin.';
                } else {
                    $bloqueado = false;
                    if (!empty($admin['bloqueado_ate'])) {
                        if (new DateTime() < new DateTime((string)$admin['bloqueado_ate'])) {
                            $bloqueado = true;
                            $erro = 'Conta bloqueada temporariamente. Tente novamente em alguns minutos.';
                        } else {
                            $stmtReset = $conn->prepare("UPDATE admins SET tentativas = 0, bloqueado_ate = NULL WHERE id = ?");
                            $stmtReset->bind_param("i", $admin['id']);
                            $stmtReset->execute();
                            $stmtReset->close();
                            $admin['tentativas'] = 0;
                        }
                    }

                    if (!$bloqueado) {
                        if (password_verify($senha, (string)$admin['senha_hash'])) {
                            if (!in_array((string)$admin['nivel'], ['super', 'editor'], true)) {
                                $erro = 'Nivel de acesso invalido para este usuario.';
                            } else {
                                $stmtOk = $conn->prepare(
                                    "UPDATE admins
                                     SET tentativas = 0, bloqueado_ate = NULL, ultimo_login = NOW()
                                     WHERE id = ?"
                                );
                                $stmtOk->bind_param("i", $admin['id']);
                                $stmtOk->execute();
                                $stmtOk->close();

                                session_regenerate_id(true);

                                $_SESSION['admin_logado']  = true;
                                $_SESSION['admin_id']      = (int)$admin['id'];
                                $_SESSION['admin_usuario'] = (string)$admin['usuario'];
                                $_SESSION['admin_nome']    = (string)$admin['usuario'];
                                $_SESSION['admin_nivel']   = (string)$admin['nivel'];

                                tvr_csrf_rotate('csrf_token');

                                header("Location: index.php");
                                exit;
                            }
                        } else {
                            $novas_tentativas = (int)$admin['tentativas'] + 1;
                            $bloquear_ate = null;

                            if ($novas_tentativas >= 5) {
                                $bloquear_ate = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                                $novas_tentativas = 0;
                                $erro = 'Muitas tentativas incorretas. Conta bloqueada por 10 minutos.';
                            } else {
                                $restantes = 5 - $novas_tentativas;
                                $erro = "Usuario ou senha incorretos. {$restantes} tentativa(s) restante(s).";
                            }

                            $stmtFail = $conn->prepare(
                                "UPDATE admins SET tentativas = ?, bloqueado_ate = ? WHERE id = ?"
                            );
                            $stmtFail->bind_param("isi", $novas_tentativas, $bloquear_ate, $admin['id']);
                            $stmtFail->execute();
                            $stmtFail->close();

                            usleep(250000);
                        }
                    }
                }
            }
        }

        tvr_csrf_rotate('csrf_token');
        $csrfToken = tvr_csrf_get('csrf_token');
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Painel TechVidaReal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Montserrat:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --azul-escuro: #0A2540;
            --ciano: #00B4D8;
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0A2540, #1E3A5F);
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-box {
            background: white;
            padding: 48px 40px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
            width: 100%;
            max-width: 420px;
            text-align: center;
        }

        .logo {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--azul-escuro);
            margin-bottom: 4px;
        }

        .subtitulo {
            font-size: 0.9rem;
            color: #64748b;
            margin-bottom: 32px;
        }

        .campo {
            width: 100%;
            padding: 13px 16px;
            margin-bottom: 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            transition: border-color 0.2s;
            display: block;
        }

        .campo:focus {
            outline: none;
            border-color: var(--ciano);
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: var(--ciano);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            margin-top: 6px;
        }

        .btn-login:hover  { background: #0099bb; }
        .btn-login:active { transform: scale(0.98); }

        .erro {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.875rem;
            margin-bottom: 20px;
            text-align: left;
        }

        .rodape {
            margin-top: 28px;
            font-size: 0.8rem;
            color: #94a3b8;
        }
    </style>
</head>
<body>

<div class="login-box">
    <div class="logo">TechVidaReal</div>
    <p class="subtitulo">Acesso ao painel administrativo</p>

    <?php if (!empty($erro)): ?>
        <div class="erro"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <input
            class="campo"
            type="text"
            name="usuario"
            placeholder="Usuario"
            required
            autofocus
            autocomplete="username"
            maxlength="60"
            value="<?= htmlspecialchars($usuario_preenchido, ENT_QUOTES, 'UTF-8') ?>"
        >

        <input
            class="campo"
            type="password"
            name="senha"
            placeholder="Senha"
            required
            autocomplete="current-password"
            maxlength="128"
        >

        <button type="submit" class="btn-login">Entrar</button>
    </form>

    <p class="rodape">TechVidaReal &copy; <?= date('Y') ?></p>
</div>

</body>
</html>

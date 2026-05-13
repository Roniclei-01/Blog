<?php
// ================================================
// admin/gerenciar_admins.php
// Somente admins com nivel "super" podem acessar
// ================================================
require_once __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
    header("Location: login.php");
    exit;
}

if (($_SESSION['admin_nivel'] ?? '') !== 'super') {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/../db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mensagem = '';
$tipo_msg = '';
$current_admin_id = (int)($_SESSION['admin_id'] ?? 0);
$niveis_validos = ['super', 'editor'];

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function buscarAdminPorId(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("SELECT id, usuario, nome_completo, nivel, ativo FROM admins WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function contarSupersAtivos(mysqli $conn): int
{
    $res = $conn->query("SELECT COUNT(*) AS total FROM admins WHERE nivel = 'super' AND ativo = 1");
    $row = $res->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tvr_is_same_origin_request()) {
        $mensagem = 'Origem invalida.';
        $tipo_msg = 'erro';
    } elseif (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $mensagem = 'Requisicao invalida (CSRF).';
        $tipo_msg = 'erro';
    } else {
        $acao = $_POST['acao'] ?? '';

        // ---------------------------
        // Criar admin
        // ---------------------------
        if ($acao === 'criar') {
            $novo_usuario = trim((string)($_POST['novo_usuario'] ?? ''));
            $novo_nome = trim((string)($_POST['novo_nome'] ?? ''));
            $nova_senha = (string)($_POST['nova_senha'] ?? '');
            $novo_nivel = (string)($_POST['novo_nivel'] ?? 'editor');

            if (!preg_match('/^[a-zA-Z0-9_]{3,60}$/', $novo_usuario)) {
                $mensagem = 'Usuario invalido. Use 3-60 caracteres (letras, numeros e _).';
                $tipo_msg = 'erro';
            } elseif (strlen($novo_nome) < 3 || strlen($novo_nome) > 120) {
                $mensagem = 'Nome completo deve ter entre 3 e 120 caracteres.';
                $tipo_msg = 'erro';
            } elseif (strlen($nova_senha) < 8 || strlen($nova_senha) > 128) {
                $mensagem = 'Senha deve ter entre 8 e 128 caracteres.';
                $tipo_msg = 'erro';
            } elseif (!in_array($novo_nivel, $niveis_validos, true)) {
                $mensagem = 'Nivel invalido.';
                $tipo_msg = 'erro';
            } else {
                $hash = password_hash($nova_senha, PASSWORD_BCRYPT, ['cost' => 12]);

                $stmt = $conn->prepare(
                    "INSERT INTO admins (usuario, nome_completo, senha_hash, nivel, ativo, criado_por)
                     VALUES (?, ?, ?, ?, 1, ?)"
                );
                $stmt->bind_param("ssssi", $novo_usuario, $novo_nome, $hash, $novo_nivel, $current_admin_id);

                if ($stmt->execute()) {
                    $mensagem = "Admin '" . e($novo_usuario) . "' criado com sucesso.";
                    $tipo_msg = 'ok';
                } else {
                    $mensagem = 'Erro ao criar admin. Usuario pode ja existir.';
                    $tipo_msg = 'erro';
                }
                $stmt->close();
            }
        }

        // ---------------------------
        // Alterar nivel
        // ---------------------------
        if ($acao === 'alterar_nivel') {
            $alvo_id = (int)($_POST['alvo_id'] ?? 0);
            $novo_nivel = (string)($_POST['novo_nivel'] ?? '');

            if ($alvo_id <= 0 || !in_array($novo_nivel, $niveis_validos, true)) {
                $mensagem = 'Dados invalidos para alteracao de nivel.';
                $tipo_msg = 'erro';
            } else {
                $alvo = buscarAdminPorId($conn, $alvo_id);

                if (!$alvo) {
                    $mensagem = 'Admin nao encontrado.';
                    $tipo_msg = 'erro';
                } elseif ($alvo_id === $current_admin_id && $novo_nivel !== 'super') {
                    $mensagem = 'Voce nao pode remover seu proprio nivel super.';
                    $tipo_msg = 'erro';
                } else {
                    $vai_remover_super_ativo = (
                        $alvo['nivel'] === 'super' &&
                        (int)$alvo['ativo'] === 1 &&
                        $novo_nivel !== 'super'
                    );

                    if ($vai_remover_super_ativo && contarSupersAtivos($conn) <= 1) {
                        $mensagem = 'Nao e permitido remover o ultimo super ativo.';
                        $tipo_msg = 'erro';
                    } else {
                        $stmt = $conn->prepare("UPDATE admins SET nivel = ? WHERE id = ?");
                        $stmt->bind_param("si", $novo_nivel, $alvo_id);
                        $stmt->execute();
                        $stmt->close();

                        $mensagem = 'Nivel atualizado com sucesso.';
                        $tipo_msg = 'ok';
                    }
                }
            }
        }

        // ---------------------------
        // Alterar status (ativar/desativar)
        // ---------------------------
        if ($acao === 'alterar_status') {
            $alvo_id = (int)($_POST['alvo_id'] ?? 0);
            $novo_status = (int)($_POST['novo_status'] ?? -1); // 0 ou 1

            if ($alvo_id <= 0 || ($novo_status !== 0 && $novo_status !== 1)) {
                $mensagem = 'Dados invalidos para alteracao de status.';
                $tipo_msg = 'erro';
            } else {
                $alvo = buscarAdminPorId($conn, $alvo_id);

                if (!$alvo) {
                    $mensagem = 'Admin nao encontrado.';
                    $tipo_msg = 'erro';
                } elseif ($alvo_id === $current_admin_id && $novo_status === 0) {
                    $mensagem = 'Voce nao pode desativar sua propria conta.';
                    $tipo_msg = 'erro';
                } else {
                    $vai_remover_super_ativo = (
                        $alvo['nivel'] === 'super' &&
                        (int)$alvo['ativo'] === 1 &&
                        $novo_status === 0
                    );

                    if ($vai_remover_super_ativo && contarSupersAtivos($conn) <= 1) {
                        $mensagem = 'Nao e permitido desativar o ultimo super ativo.';
                        $tipo_msg = 'erro';
                    } else {
                        if ($novo_status === 1) {
                            $stmt = $conn->prepare(
                                "UPDATE admins
                                 SET ativo = 1, tentativas = 0, bloqueado_ate = NULL
                                 WHERE id = ?"
                            );
                            $stmt->bind_param("i", $alvo_id);
                        } else {
                            $stmt = $conn->prepare("UPDATE admins SET ativo = 0 WHERE id = ?");
                            $stmt->bind_param("i", $alvo_id);
                        }

                        $stmt->execute();
                        $stmt->close();

                        $mensagem = $novo_status === 1
                            ? 'Admin reativado com sucesso.'
                            : 'Admin desativado com sucesso.';
                        $tipo_msg = 'ok';
                    }
                }
            }
        }

        // ---------------------------
        // Resetar senha
        // ---------------------------
        if ($acao === 'resetar_senha') {
            $alvo_id = (int)($_POST['alvo_id'] ?? 0);
            $nova_senha_reset = (string)($_POST['nova_senha_reset'] ?? '');

            if ($alvo_id <= 0) {
                $mensagem = 'Admin invalido para reset de senha.';
                $tipo_msg = 'erro';
            } elseif (strlen($nova_senha_reset) < 8 || strlen($nova_senha_reset) > 128) {
                $mensagem = 'Nova senha deve ter entre 8 e 128 caracteres.';
                $tipo_msg = 'erro';
            } else {
                $alvo = buscarAdminPorId($conn, $alvo_id);

                if (!$alvo) {
                    $mensagem = 'Admin nao encontrado.';
                    $tipo_msg = 'erro';
                } else {
                    $novo_hash = password_hash($nova_senha_reset, PASSWORD_BCRYPT, ['cost' => 12]);
                    $stmt = $conn->prepare(
                        "UPDATE admins
                         SET senha_hash = ?, tentativas = 0, bloqueado_ate = NULL
                         WHERE id = ?"
                    );
                    $stmt->bind_param("si", $novo_hash, $alvo_id);
                    $stmt->execute();
                    $stmt->close();

                    $mensagem = 'Senha redefinida com sucesso.';
                    $tipo_msg = 'ok';
                }
            }
        }

        // ---------------------------
        // Excluir admin
        // ---------------------------
        if ($acao === 'excluir') {
            $alvo_id = (int)($_POST['alvo_id'] ?? 0);

            if ($alvo_id <= 0) {
                $mensagem = 'ID invalido para exclusao.';
                $tipo_msg = 'erro';
            } elseif ($alvo_id === $current_admin_id) {
                $mensagem = 'Voce nao pode excluir sua propria conta.';
                $tipo_msg = 'erro';
            } else {
                $alvo = buscarAdminPorId($conn, $alvo_id);

                if (!$alvo) {
                    $mensagem = 'Admin nao encontrado.';
                    $tipo_msg = 'erro';
                } else {
                    $vai_remover_super_ativo = (
                        $alvo['nivel'] === 'super' &&
                        (int)$alvo['ativo'] === 1
                    );

                    if ($vai_remover_super_ativo && contarSupersAtivos($conn) <= 1) {
                        $mensagem = 'Nao e permitido excluir o ultimo super ativo.';
                        $tipo_msg = 'erro';
                    } else {
                        $stmt = $conn->prepare("DELETE FROM admins WHERE id = ?");
                        $stmt->bind_param("i", $alvo_id);
                        $stmt->execute();
                        $ok = $stmt->affected_rows > 0;
                        $stmt->close();

                        $mensagem = $ok ? 'Admin excluido com sucesso.' : 'Admin nao encontrado.';
                        $tipo_msg = $ok ? 'ok' : 'erro';
                    }
                }
            }
        }
    }

    // Regenera token apos qualquer POST
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Lista admins
$admins = [];
$res = $conn->query(
    "SELECT id, usuario, nome_completo, nivel, ativo, ultimo_login, criado_em
     FROM admins
     ORDER BY criado_em ASC, id ASC"
);

while ($row = $res->fetch_assoc()) {
    $admins[] = $row;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Admins - TechVidaReal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Montserrat:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root { --azul: #0A2540; --ciano: #00B4D8; }
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; }

        .admin-header {
            background: var(--azul); color: white;
            padding: 18px 40px; display: flex;
            justify-content: space-between; align-items: center;
        }
        .admin-header a { color: #94a3b8; text-decoration: none; font-size: 0.9rem; }
        .admin-header a:hover { color: white; }

        .container { max-width: 1100px; margin: 40px auto; padding: 0 20px; }

        h2 { color: var(--azul); margin: 0 0 24px; }

        .msg-ok  { background: #f0fdf4; border-left: 4px solid #22c55e; color: #166534; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; }
        .msg-err { background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; }

        .card {
            background: white; border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.07);
            padding: 28px 32px; margin-bottom: 32px;
        }
        .card h3 { color: var(--azul); margin: 0 0 20px; font-size: 1.1rem; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-grid .span2 { grid-column: span 2; }

        label { display: block; font-size: 0.85rem; font-weight: 500; color: #475569; margin-bottom: 5px; }
        input[type=text], input[type=password], select {
            width: 100%; padding: 11px 14px;
            border: 1.5px solid #e2e8f0; border-radius: 9px;
            font-size: 0.95rem; font-family: 'Inter', sans-serif;
        }
        input:focus, select:focus { outline: none; border-color: var(--ciano); }

        .btn-criar {
            background: var(--ciano); color: white; padding: 12px 28px;
            border: none; border-radius: 9px; font-size: 0.95rem;
            font-weight: 600; cursor: pointer; margin-top: 6px;
        }
        .btn-criar:hover { background: #0099bb; }

        table {
            width: 100%; border-collapse: collapse; background: white;
            box-shadow: 0 4px 20px rgba(0,0,0,0.07);
            border-radius: 14px; overflow: hidden;
        }
        th, td {
            padding: 14px 18px; text-align: left;
            border-bottom: 1px solid #f1f5f9; font-size: 0.9rem;
        }
        th { background: var(--azul); color: white; font-weight: 500; font-size: 0.85rem; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fafc; }

        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 20px;
            font-size: 0.78rem; font-weight: 600;
        }
        .badge-super  { background: #dbeafe; color: #1e40af; }
        .badge-editor { background: #f3f4f6; color: #374151; }
        .badge-ativo  { background: #dcfce7; color: #166534; }
        .badge-inativo{ background: #fee2e2; color: #991b1b; }

        .acoes { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .btn-sm {
            padding: 6px 12px; border-radius: 7px; font-size: 0.8rem;
            font-weight: 500; border: none; cursor: pointer; font-family: 'Inter', sans-serif;
        }
        .btn-des  { background: #fee2e2; color: #991b1b; }
        .btn-rei  { background: #dcfce7; color: #166534; }
        .btn-pwd  { background: #e0f2fe; color: #0c4a6e; }
        .btn-nivel { background: #f1f5f9; color: #334155; }
        .btn-del { background: #ef4444; color: white; }
        .btn-sm:hover { opacity: 0.85; }

        .mini-form { display: inline-flex; align-items: center; gap: 6px; }

        .modal-bg {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.45);
            align-items: center; justify-content: center; z-index: 100;
        }
        .modal-bg.open { display: flex; }
        .modal {
            background: white; padding: 32px; border-radius: 14px;
            width: 100%; max-width: 420px; box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .modal h3 { margin: 0 0 20px; color: var(--azul); }
        .modal input {
            width: 100%; padding: 12px 14px;
            border: 1.5px solid #e2e8f0; border-radius: 9px;
            font-size: 0.95rem; margin-bottom: 14px;
        }
        .modal-btns {
            display: flex; gap: 10px; justify-content: flex-end; margin-top: 4px;
        }
        .btn-cancelar {
            background: #f1f5f9; color: #475569; padding: 10px 20px;
            border: none; border-radius: 8px; cursor: pointer; font-size: 0.9rem;
        }
        .btn-confirmar {
            background: var(--ciano); color: white; padding: 10px 20px;
            border: none; border-radius: 8px; cursor: pointer; font-size: 0.9rem; font-weight: 600;
        }

        @media (max-width: 900px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .span2 { grid-column: span 1; }
            .admin-header { padding: 14px 16px; }
            table { display: block; overflow-x: auto; }
        }
    </style>
</head>
<body>

<div class="admin-header">
    <strong>TechVidaReal - Gerenciar Admins</strong>
    <div style="display:flex; gap:20px;">
        <a href="index.php">&larr; Painel</a>
        <a href="logout.php">Sair</a>
    </div>
</div>

<div class="container">

    <?php if (!empty($mensagem)): ?>
        <div class="<?= $tipo_msg === 'ok' ? 'msg-ok' : 'msg-err' ?>">
            <?= $mensagem ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h3>Adicionar novo administrador</h3>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="acao" value="criar">

            <div class="form-grid">
                <div>
                    <label>Nome completo</label>
                    <input type="text" name="novo_nome" required maxlength="120" placeholder="Ex: Maria Silva">
                </div>
                <div>
                    <label>Usuario (login)</label>
                    <input type="text" name="novo_usuario" required maxlength="60" pattern="[a-zA-Z0-9_]{3,60}" placeholder="Ex: maria_silva">
                </div>
                <div>
                    <label>Senha (min. 8 caracteres)</label>
                    <input type="password" name="nova_senha" required minlength="8" maxlength="128">
                </div>
                <div>
                    <label>Nivel de acesso</label>
                    <select name="novo_nivel" required>
                        <option value="editor">Editor - cria e edita artigos</option>
                        <option value="super">Super - gerencia admins e artigos</option>
                    </select>
                </div>
                <div class="span2">
                    <button type="submit" class="btn-criar">Criar administrador</button>
                </div>
            </div>
        </form>
    </div>

    <h2>Administradores cadastrados</h2>

    <table>
        <thead>
        <tr>
            <th>#</th>
            <th>Nome</th>
            <th>Usuario</th>
            <th>Nivel</th>
            <th>Status</th>
            <th>Ultimo login</th>
            <th>Acoes</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($admins as $a): ?>
            <?php
                $id = (int)$a['id'];
                $isSelf = ($id === $current_admin_id);
            ?>
            <tr>
                <td><?= $id ?></td>
                <td><?= e((string)$a['nome_completo']) ?></td>
                <td><code><?= e((string)$a['usuario']) ?></code> <?= $isSelf ? '<span style="color:#94a3b8;">(voce)</span>' : '' ?></td>
                <td>
                    <span class="badge <?= $a['nivel'] === 'super' ? 'badge-super' : 'badge-editor' ?>">
                        <?= e((string)$a['nivel']) ?>
                    </span>
                </td>
                <td>
                    <span class="badge <?= (int)$a['ativo'] === 1 ? 'badge-ativo' : 'badge-inativo' ?>">
                        <?= (int)$a['ativo'] === 1 ? 'Ativo' : 'Inativo' ?>
                    </span>
                </td>
                <td style="color:#64748b; font-size:0.85rem;">
                    <?= !empty($a['ultimo_login']) ? date('d/m/Y H:i', strtotime((string)$a['ultimo_login'])) : 'Nunca' ?>
                </td>
                <td>
                    <div class="acoes">
                        <form method="POST" class="mini-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="acao" value="alterar_nivel">
                            <input type="hidden" name="alvo_id" value="<?= $id ?>">
                            <select name="novo_nivel">
                                <option value="editor" <?= $a['nivel'] === 'editor' ? 'selected' : '' ?>>editor</option>
                                <option value="super" <?= $a['nivel'] === 'super' ? 'selected' : '' ?>>super</option>
                            </select>
                            <button class="btn-sm btn-nivel" type="submit">Nivel</button>
                        </form>

                        <button
                            type="button"
                            class="btn-sm btn-pwd"
                            data-id="<?= $id ?>"
                            data-usuario="<?= e((string)$a['usuario']) ?>"
                            onclick="abrirReset(this)">
                            Senha
                        </button>

                        <?php if (!$isSelf): ?>
                            <form method="POST" class="mini-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="acao" value="alterar_status">
                                <input type="hidden" name="alvo_id" value="<?= $id ?>">
                                <input type="hidden" name="novo_status" value="<?= (int)$a['ativo'] === 1 ? 0 : 1 ?>">
                                <button class="btn-sm <?= (int)$a['ativo'] === 1 ? 'btn-des' : 'btn-rei' ?>" type="submit">
                                    <?= (int)$a['ativo'] === 1 ? 'Desativar' : 'Reativar' ?>
                                </button>
                            </form>

                            <form method="POST" class="mini-form" onsubmit="return confirm('Tem certeza que deseja excluir este admin? Esta acao e irreversivel.');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="acao" value="excluir">
                                <input type="hidden" name="alvo_id" value="<?= $id ?>">
                                <button class="btn-sm btn-del" type="submit">Excluir</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="modal-bg" id="modal-reset">
    <div class="modal">
        <h3>Redefinir senha</h3>
        <p style="color:#64748b; font-size:0.9rem; margin-bottom:16px;">
            Admin: <strong id="modal-usuario"></strong>
        </p>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="acao" value="resetar_senha">
            <input type="hidden" name="alvo_id" id="modal-id">

            <input type="password" name="nova_senha_reset" required minlength="8" maxlength="128" placeholder="Nova senha (min. 8 caracteres)">

            <div class="modal-btns">
                <button type="button" class="btn-cancelar" onclick="fecharModal()">Cancelar</button>
                <button type="submit" class="btn-confirmar">Salvar nova senha</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirReset(btn) {
    var id = btn.getAttribute('data-id');
    var usuario = btn.getAttribute('data-usuario');

    document.getElementById('modal-id').value = id;
    document.getElementById('modal-usuario').textContent = usuario;
    document.getElementById('modal-reset').classList.add('open');
}

function fecharModal() {
    document.getElementById('modal-reset').classList.remove('open');
}

document.getElementById('modal-reset').addEventListener('click', function (e) {
    if (e.target === this) fecharModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') fecharModal();
});
</script>

</body>
</html>

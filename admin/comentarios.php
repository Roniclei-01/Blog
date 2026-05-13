<?php
// ================================================
// admin/comentarios.php - Moderacao de comentarios
// ================================================
require_once __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function redirect_self(string $status, string $q, int $p): void
{
    $qs = '?status=' . urlencode($status) . '&p=' . max(1, $p);
    if ($q !== '') {
        $qs .= '&q=' . urlencode($q);
    }
    header('Location: comentarios.php' . $qs);
    exit;
}

$allowed_status = ['todos', 'pendente', 'aprovado', 'rejeitado', 'spam'];

// ------------------------------------------------
// POST actions
// ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ret_status = (string)($_POST['ret_status'] ?? 'pendente');
    $ret_q = trim((string)($_POST['ret_q'] ?? ''));
    $ret_p = (int)($_POST['ret_p'] ?? 1);

    if (!in_array($ret_status, $allowed_status, true)) {
        $ret_status = 'pendente';
    }

    if (!tvr_is_same_origin_request()) {
        $_SESSION['cm_msg'] = 'Origem invalida.';
        $_SESSION['cm_type'] = 'erro';
        redirect_self($ret_status, $ret_q, $ret_p);
    }

    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $_SESSION['cm_msg'] = 'Requisicao invalida (CSRF).';
        $_SESSION['cm_type'] = 'erro';
        redirect_self($ret_status, $ret_q, $ret_p);
    }

    $acao = (string)($_POST['acao'] ?? '');
    $admin_id = (int)($_SESSION['admin_id'] ?? 0);

    // Aprovar todos pendentes
    if ($acao === 'aprovar_todos_pendentes') {
        $stmt = $conn->prepare("
            UPDATE comentarios
            SET status = 'aprovado', moderado_por = ?, aprovado_em = NOW()
            WHERE status = 'pendente'
        ");
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        $afetados = $stmt->affected_rows;
        $stmt->close();

        $_SESSION['cm_msg'] = $afetados > 0
            ? "Foram aprovados {$afetados} comentario(s) pendente(s)."
            : 'Nao ha comentarios pendentes para aprovar.';
        $_SESSION['cm_type'] = $afetados > 0 ? 'ok' : 'erro';

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        redirect_self($ret_status, $ret_q, $ret_p);
    }

    // Acoes por comentario individual
    $comentario_id = (int)($_POST['comentario_id'] ?? 0);
    if ($comentario_id <= 0) {
        $_SESSION['cm_msg'] = 'Comentario invalido.';
        $_SESSION['cm_type'] = 'erro';
        redirect_self($ret_status, $ret_q, $ret_p);
    }

    if ($acao === 'excluir') {
        $stmt = $conn->prepare("DELETE FROM comentarios WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $comentario_id);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();

        $_SESSION['cm_msg'] = $ok ? 'Comentario excluido com sucesso.' : 'Comentario nao encontrado.';
        $_SESSION['cm_type'] = $ok ? 'ok' : 'erro';

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        redirect_self($ret_status, $ret_q, $ret_p);
    }

    $map_status = [
        'aprovar' => 'aprovado',
        'rejeitar' => 'rejeitado',
        'spam' => 'spam',
    ];

    if (!isset($map_status[$acao])) {
        $_SESSION['cm_msg'] = 'Acao invalida.';
        $_SESSION['cm_type'] = 'erro';
        redirect_self($ret_status, $ret_q, $ret_p);
    }

    $novo_status = $map_status[$acao];

    if ($novo_status === 'aprovado') {
        $stmt = $conn->prepare("
            UPDATE comentarios
            SET status = ?, moderado_por = ?, aprovado_em = NOW()
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("sii", $novo_status, $admin_id, $comentario_id);
    } else {
        $stmt = $conn->prepare("
            UPDATE comentarios
            SET status = ?, moderado_por = ?, aprovado_em = NULL
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("sii", $novo_status, $admin_id, $comentario_id);
    }

    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();

    $_SESSION['cm_msg'] = $ok ? 'Comentario atualizado com sucesso.' : 'Nenhuma alteracao realizada.';
    $_SESSION['cm_type'] = $ok ? 'ok' : 'erro';

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    redirect_self($ret_status, $ret_q, $ret_p);
}

// ------------------------------------------------
// Filtros e listagem
// ------------------------------------------------
$status = (string)($_GET['status'] ?? 'pendente');
$q = trim((string)($_GET['q'] ?? ''));
$pagina = max(1, (int)($_GET['p'] ?? 1));
$por_pagina = 20;

if (!in_array($status, $allowed_status, true)) {
    $status = 'pendente';
}

// Stats
$stats = [
    'total' => 0,
    'pendente' => 0,
    'aprovado' => 0,
    'rejeitado' => 0,
    'spam' => 0,
];

$res_stats = $conn->query("
    SELECT
      COUNT(*) AS total,
      SUM(status = 'pendente') AS pendente,
      SUM(status = 'aprovado') AS aprovado,
      SUM(status = 'rejeitado') AS rejeitado,
      SUM(status = 'spam') AS spam
    FROM comentarios
");
if ($res_stats) {
    $rowStats = $res_stats->fetch_assoc();
    if ($rowStats) {
        $stats = $rowStats;
    }
}

// WHERE dinamico
$where_parts = [];
$params = [];
$types = '';

if ($status !== 'todos') {
    $where_parts[] = "c.status = ?";
    $types .= 's';
    $params[] = $status;
}

if ($q !== '') {
    $where_parts[] = "(c.nome LIKE ? OR c.email LIKE ? OR c.conteudo LIKE ? OR a.titulo LIKE ?)";
    $types .= 'ssss';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where_sql = '';
if (!empty($where_parts)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_parts);
}

// Count filtrado
$sql_total = "
    SELECT COUNT(*) AS total
    FROM comentarios c
    INNER JOIN artigos a ON a.id = c.artigo_id
    $where_sql
";
$stmt_total = $conn->prepare($sql_total);
if (!empty($params)) {
    $stmt_total->bind_param($types, ...$params);
}
$stmt_total->execute();
$total_filtrado = (int)$stmt_total->get_result()->fetch_assoc()['total'];
$stmt_total->close();

$total_paginas = max(1, (int)ceil($total_filtrado / $por_pagina));
$pagina = min($pagina, $total_paginas);
$offset = ($pagina - 1) * $por_pagina;

// Lista comentarios
$sql = "
    SELECT
      c.id, c.artigo_id, c.nome, c.email, c.conteudo, c.status, c.created_at, c.aprovado_em,
      a.titulo AS artigo_titulo,
      am.nome_completo AS moderador_nome,
      am.usuario AS moderador_usuario
    FROM comentarios c
    INNER JOIN artigos a ON a.id = c.artigo_id
    LEFT JOIN admins am ON am.id = c.moderado_por
    $where_sql
    ORDER BY
      CASE WHEN c.status = 'pendente' THEN 0 ELSE 1 END,
      c.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $params_exec = $params;
    $params_exec[] = $por_pagina;
    $params_exec[] = $offset;
    $stmt->bind_param($types . 'ii', ...$params_exec);
} else {
    $stmt->bind_param('ii', $por_pagina, $offset);
}
$stmt->execute();
$comentarios = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$mensagem = $_SESSION['cm_msg'] ?? '';
$tipo_msg = $_SESSION['cm_type'] ?? '';
unset($_SESSION['cm_msg'], $_SESSION['cm_type']);

$export_csrf = $_SESSION['csrf_token'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Moderar Comentarios - TechVidaReal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Montserrat:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root { --azul: #0A2540; --ciano: #00B4D8; }
        * { box-sizing: border-box; }
        body { margin:0; font-family:'Inter', sans-serif; background:#f8fafc; }

        .admin-header {
            background: var(--azul); color: white;
            padding: 18px 40px; display: flex; justify-content: space-between; align-items: center;
        }
        .admin-header a { color:#94a3b8; text-decoration:none; font-size:0.9rem; }
        .admin-header a:hover { color:white; }

        .container { max-width: 1250px; margin: 30px auto; padding: 0 20px; }

        .topo { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:20px; }
        .topo h2 { margin:0; color:var(--azul); font-size:1.25rem; flex:1; }

        .filtro-form { display:flex; gap:8px; flex-wrap:wrap; }
        .filtro-form input, .filtro-form select {
            padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:0.9rem;
        }
        .filtro-form button {
            padding:9px 14px; border:1.5px solid #e2e8f0; border-radius:8px; background:#f1f5f9; cursor:pointer;
        }

        .acoes-topo {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .btn-topo {
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #334155;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 0.85rem;
            text-decoration: none;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
        }
        .btn-topo:hover { background: #f8fafc; }
        .btn-topo.ok { background: #dcfce7; color: #166534; border-color: #bbf7d0; }

        .msg-ok  { background:#f0fdf4; border-left:4px solid #22c55e; color:#166534; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
        .msg-erro{ background:#fef2f2; border-left:4px solid #ef4444; color:#991b1b; padding:12px 16px; border-radius:8px; margin-bottom:16px; }

        .stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; }
        .card {
            background:#fff; border:1px solid #e2e8f0; border-radius:10px;
            min-width:120px; padding:12px 16px; text-align:center;
        }
        .card .num { font-size:1.4rem; color:var(--azul); font-weight:600; }
        .card .lbl { font-size:0.78rem; color:#64748b; }

        .tabela-wrap { background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.07); }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:12px 14px; border-bottom:1px solid #f1f5f9; vertical-align:top; font-size:0.86rem; }
        th { background:var(--azul); color:#fff; font-size:0.8rem; text-align:left; }
        tr:hover td { background:#f8fafc; }

        .badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:0.74rem; font-weight:600; }
        .b-pendente { background:#fef3c7; color:#92400e; }
        .b-aprovado { background:#dcfce7; color:#166534; }
        .b-rejeitado{ background:#fee2e2; color:#991b1b; }
        .b-spam     { background:#e5e7eb; color:#374151; }

        .acoes { display:flex; gap:6px; flex-wrap:wrap; }
        .btn {
            border:none; border-radius:7px; padding:6px 10px; font-size:0.76rem; cursor:pointer; font-weight:600;
        }
        .btn-aprovar { background:#dcfce7; color:#166534; }
        .btn-rejeitar{ background:#fee2e2; color:#991b1b; }
        .btn-spam    { background:#e5e7eb; color:#374151; }
        .btn-excluir { background:#ef4444; color:#fff; }

        .preview { color:#475569; line-height:1.5; max-width:380px; }

        .paginacao { display:flex; gap:6px; justify-content:center; margin-top:18px; flex-wrap:wrap; }
        .paginacao a, .paginacao span {
            border:1px solid #e2e8f0; border-radius:8px; padding:7px 12px; text-decoration:none; font-size:0.85rem;
            color:#475569; background:#fff;
        }
        .paginacao .atual { background:var(--azul); color:#fff; border-color:var(--azul); }

        @media (max-width: 900px) {
            .admin-header { padding:14px 16px; }
            .container { padding:0 12px; }
            table { display:block; overflow-x:auto; }
        }
    </style>
</head>
<body>

<div class="admin-header">
    <strong>TechVidaReal - Moderar Comentarios</strong>
    <div style="display:flex; gap:18px; align-items:center;">
        <span style="color:#94a3b8; font-size:0.9rem;">Ola, <?= e($_SESSION['admin_nome'] ?? $_SESSION['admin_usuario'] ?? 'Admin') ?></span>
        <a href="index.php">&larr; Painel</a>
        <a href="logout.php">Sair</a>
    </div>
</div>

<div class="container">
    <?php if ($mensagem !== ''): ?>
        <div class="<?= $tipo_msg === 'ok' ? 'msg-ok' : 'msg-erro' ?>"><?= e($mensagem) ?></div>
    <?php endif; ?>

    <div class="topo">
        <h2>Comentarios</h2>

        <form class="filtro-form" method="GET">
            <select name="status">
                <option value="pendente" <?= $status === 'pendente' ? 'selected' : '' ?>>Pendentes</option>
                <option value="aprovado" <?= $status === 'aprovado' ? 'selected' : '' ?>>Aprovados</option>
                <option value="rejeitado" <?= $status === 'rejeitado' ? 'selected' : '' ?>>Rejeitados</option>
                <option value="spam" <?= $status === 'spam' ? 'selected' : '' ?>>Spam</option>
                <option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todos</option>
            </select>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por nome, email, artigo...">
            <button type="submit">Filtrar</button>
        </form>

        <div class="acoes-topo">
            <a class="btn-topo" href="export_comentarios.php?csrf=<?= urlencode($export_csrf) ?>&period=7d">Exportar 7d</a>
            <a class="btn-topo" href="export_comentarios.php?csrf=<?= urlencode($export_csrf) ?>&period=30d">Exportar 30d</a>
            <a class="btn-topo" href="export_comentarios.php?csrf=<?= urlencode($export_csrf) ?>&period=90d">Exportar 90d</a>
            <a class="btn-topo" href="export_comentarios.php?csrf=<?= urlencode($export_csrf) ?>&period=all">Exportar tudo</a>

            <form method="POST" onsubmit="return confirm('Aprovar todos os comentarios pendentes agora?');">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="acao" value="aprovar_todos_pendentes">
                <input type="hidden" name="ret_status" value="<?= e($status) ?>">
                <input type="hidden" name="ret_q" value="<?= e($q) ?>">
                <input type="hidden" name="ret_p" value="<?= (int)$pagina ?>">
                <button class="btn-topo ok" type="submit">Aprovar todos pendentes</button>
            </form>
        </div>
    </div>

    <div class="stats">
        <div class="card"><div class="num"><?= (int)$stats['total'] ?></div><div class="lbl">Total</div></div>
        <div class="card"><div class="num"><?= (int)$stats['pendente'] ?></div><div class="lbl">Pendentes</div></div>
        <div class="card"><div class="num"><?= (int)$stats['aprovado'] ?></div><div class="lbl">Aprovados</div></div>
        <div class="card"><div class="num"><?= (int)$stats['rejeitado'] ?></div><div class="lbl">Rejeitados</div></div>
        <div class="card"><div class="num"><?= (int)$stats['spam'] ?></div><div class="lbl">Spam</div></div>
    </div>

    <div class="tabela-wrap">
        <?php if (empty($comentarios)): ?>
            <div style="padding:40px; text-align:center; color:#94a3b8;">Nenhum comentario encontrado.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Autor</th>
                    <th>Comentario</th>
                    <th>Artigo</th>
                    <th>Status</th>
                    <th>Data</th>
                    <th>Acoes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($comentarios as $c): ?>
                    <?php
                        $badgeClass = 'b-pendente';
                        if ($c['status'] === 'aprovado') $badgeClass = 'b-aprovado';
                        if ($c['status'] === 'rejeitado') $badgeClass = 'b-rejeitado';
                        if ($c['status'] === 'spam') $badgeClass = 'b-spam';

                        $conteudoPreview = trim((string)$c['conteudo']);
                        if (function_exists('mb_strimwidth')) {
                            $preview = mb_strimwidth($conteudoPreview, 0, 140, '...', 'UTF-8');
                        } else {
                            $preview = strlen($conteudoPreview) > 140 ? substr($conteudoPreview, 0, 140) . '...' : $conteudoPreview;
                        }
                    ?>
                    <tr>
                        <td><?= (int)$c['id'] ?></td>
                        <td>
                            <strong><?= e((string)$c['nome']) ?></strong><br>
                            <span style="color:#64748b; font-size:0.8rem;"><?= e((string)$c['email']) ?></span>
                        </td>
                        <td class="preview"><?= nl2br(e($preview)) ?></td>
                        <td>
                            <a href="../single.php?id=<?= (int)$c['artigo_id'] ?>" target="_blank" style="color:#0c4a6e; text-decoration:none;">
                                <?= e((string)$c['artigo_titulo']) ?>
                            </a>
                        </td>
                        <td>
                            <span class="badge <?= $badgeClass ?>"><?= e((string)$c['status']) ?></span>
                        </td>
                        <td style="white-space:nowrap;">
                            <?= date('d/m/Y H:i', strtotime((string)$c['created_at'])) ?>
                        </td>
                        <td>
                            <div class="acoes">
                                <?php if ($c['status'] !== 'aprovado'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="acao" value="aprovar">
                                        <input type="hidden" name="comentario_id" value="<?= (int)$c['id'] ?>">
                                        <input type="hidden" name="ret_status" value="<?= e($status) ?>">
                                        <input type="hidden" name="ret_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="ret_p" value="<?= (int)$pagina ?>">
                                        <button class="btn btn-aprovar" type="submit">Aprovar</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($c['status'] !== 'rejeitado'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="acao" value="rejeitar">
                                        <input type="hidden" name="comentario_id" value="<?= (int)$c['id'] ?>">
                                        <input type="hidden" name="ret_status" value="<?= e($status) ?>">
                                        <input type="hidden" name="ret_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="ret_p" value="<?= (int)$pagina ?>">
                                        <button class="btn btn-rejeitar" type="submit">Rejeitar</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($c['status'] !== 'spam'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="acao" value="spam">
                                        <input type="hidden" name="comentario_id" value="<?= (int)$c['id'] ?>">
                                        <input type="hidden" name="ret_status" value="<?= e($status) ?>">
                                        <input type="hidden" name="ret_q" value="<?= e($q) ?>">
                                        <input type="hidden" name="ret_p" value="<?= (int)$pagina ?>">
                                        <button class="btn btn-spam" type="submit">Spam</button>
                                    </form>
                                <?php endif; ?>

                                <form method="POST" onsubmit="return confirm('Excluir este comentario? Esta acao e irreversivel.');">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="acao" value="excluir">
                                    <input type="hidden" name="comentario_id" value="<?= (int)$c['id'] ?>">
                                    <input type="hidden" name="ret_status" value="<?= e($status) ?>">
                                    <input type="hidden" name="ret_q" value="<?= e($q) ?>">
                                    <input type="hidden" name="ret_p" value="<?= (int)$pagina ?>">
                                    <button class="btn btn-excluir" type="submit">Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php if ($total_paginas > 1): ?>
        <div class="paginacao">
            <?php
            $qbase = 'status=' . urlencode($status);
            if ($q !== '') $qbase .= '&q=' . urlencode($q);

            if ($pagina > 1):
            ?>
                <a href="?<?= $qbase ?>&p=<?= $pagina - 1 ?>">&larr; Anterior</a>
            <?php endif; ?>

            <?php
            $ini = max(1, $pagina - 2);
            $fim = min($total_paginas, $pagina + 2);
            for ($i = $ini; $i <= $fim; $i++):
                if ($i === $pagina):
            ?>
                <span class="atual"><?= $i ?></span>
            <?php else: ?>
                <a href="?<?= $qbase ?>&p=<?= $i ?>"><?= $i ?></a>
            <?php endif; endfor; ?>

            <?php if ($pagina < $total_paginas): ?>
                <a href="?<?= $qbase ?>&p=<?= $pagina + 1 ?>">Proxima &rarr;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>

<?php
// ================================================
// admin/index.php — Painel com paginação + ações
// ================================================
require_once __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../db.php';

// Gera token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// —— Exclusão via POST ————————————————————————————————————————————————
$mensagem = '';
$tipo_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    if (!tvr_is_same_origin_request()) {
        $mensagem = 'Origem inválida.';
        $tipo_msg = 'erro';
    } elseif (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $mensagem = 'Requisição inválida.';
        $tipo_msg = 'erro';
    } else {
        $id_excluir = (int)($_POST['artigo_id'] ?? 0);
        if ($id_excluir > 0) {
            $stmt = $conn->prepare("DELETE FROM artigos WHERE id = ?");
            $stmt->bind_param("i", $id_excluir);
            $stmt->execute();
            $afetados = $stmt->affected_rows;
            $stmt->close();

            $mensagem = $afetados > 0 ? 'Artigo excluído com sucesso.' : 'Artigo não encontrado.';
            $tipo_msg = $afetados > 0 ? 'ok' : 'erro';
        }
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// —— Paginação ———————————————————————————————————————————————————————————
$por_pagina = 15;
$pagina = max(1, (int)($_GET['p'] ?? 1));

$filtro = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
$tipos = '';

if ($filtro !== '') {
    $where = "WHERE titulo LIKE ? OR categoria LIKE ?";
    $like = "%{$filtro}%";
    $params = [$like, $like];
    $tipos = 'ss';
}

// Total filtrado
$sql_total = "SELECT COUNT(*) AS total FROM artigos $where";
$stmt_t = $conn->prepare($sql_total);
if (!empty($params)) {
    $stmt_t->bind_param($tipos, ...$params);
}
$stmt_t->execute();
$total = (int)($stmt_t->get_result()->fetch_assoc()['total'] ?? 0);
$stmt_t->close();

$total_paginas = max(1, (int)ceil($total / $por_pagina));
$pagina = min($pagina, $total_paginas);
$offset = ($pagina - 1) * $por_pagina;

// Lista artigos
$sql = "SELECT id, titulo, categoria, data_publicacao, tempo_leitura, criado_em
        FROM artigos $where
        ORDER BY criado_em DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $params_exec = $params;
    $params_exec[] = $por_pagina;
    $params_exec[] = $offset;
    $stmt->bind_param($tipos . 'ii', ...$params_exec);
} else {
    $stmt->bind_param('ii', $por_pagina, $offset);
}
$stmt->execute();
$artigos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// —— Top 5 mais lidos da semana ——————————————————————————————————————
$top_lidos = [];
$sql_top = "
    SELECT
        a.id,
        a.titulo,
        a.categoria,
        COUNT(v.id) AS views_semana
    FROM artigos a
    INNER JOIN artigo_visualizacoes v
        ON v.artigo_id = a.id
       AND v.viewed_at >= (NOW() - INTERVAL 7 DAY)
    GROUP BY a.id, a.titulo, a.categoria
    ORDER BY views_semana DESC, a.criado_em DESC
    LIMIT 5
";
$res_top = $conn->query($sql_top);
if ($res_top && $res_top->num_rows > 0) {
    while ($row = $res_top->fetch_assoc()) {
        $top_lidos[] = $row;
    }
}

// —— Métricas rápidas ————————————————————————————————————————————————
$metricas = [
    'comentarios_pendentes' => 0,
    'newsletter_ativos' => 0,
    'views_7d' => 0,
];

$res_m1 = $conn->query("SELECT COUNT(*) AS total FROM comentarios WHERE status = 'pendente'");
if ($res_m1) {
    $metricas['comentarios_pendentes'] = (int)($res_m1->fetch_assoc()['total'] ?? 0);
}

$res_m2 = $conn->query("SELECT COUNT(*) AS total FROM newsletter_subscribers WHERE status = 'active'");
if ($res_m2) {
    $metricas['newsletter_ativos'] = (int)($res_m2->fetch_assoc()['total'] ?? 0);
}

$res_m3 = $conn->query("SELECT COUNT(*) AS total FROM artigo_visualizacoes WHERE viewed_at >= (NOW() - INTERVAL 7 DAY)");
if ($res_m3) {
    $metricas['views_7d'] = (int)($res_m3->fetch_assoc()['total'] ?? 0);
}

$csrf_export = $_SESSION['csrf_token'];
$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel — TechVidaReal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Montserrat:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root { --azul: #0A2540; --ciano: #00B4D8; }
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f8fafc; margin: 0; }

        .admin-header {
            background: var(--azul); color: white;
            padding: 18px 40px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .admin-header a { color: #94a3b8; text-decoration: none; font-size: 0.9rem; }
        .admin-header a:hover { color: white; }
        .admin-header span { color: #94a3b8; font-size: 0.9rem; }

        .container { max-width: 1200px; margin: 36px auto; padding: 0 24px; }

        .topo {
            display: flex; align-items: center;
            gap: 12px; margin-bottom: 24px; flex-wrap: wrap;
        }
        .topo h2 { color: var(--azul); margin: 0; font-size: 1.3rem; flex: 1; }

        .btn-novo {
            background: var(--ciano); color: white;
            padding: 10px 22px; border-radius: 9px;
            text-decoration: none; font-weight: 600; font-size: 0.9rem;
            white-space: nowrap;
        }
        .btn-novo:hover { background: #0099bb; }

        .busca-form { display: flex; gap: 8px; }
        .busca-form input {
            padding: 9px 14px; border: 1.5px solid #e2e8f0;
            border-radius: 9px; font-size: 0.9rem; font-family: 'Inter', sans-serif;
            width: 220px;
        }
        .busca-form input:focus { outline: none; border-color: var(--ciano); }
        .busca-form button {
            background: #f1f5f9; border: 1.5px solid #e2e8f0;
            border-radius: 9px; padding: 9px 16px;
            font-size: 0.9rem; cursor: pointer; font-family: 'Inter', sans-serif;
        }
        .busca-form button:hover { background: #e2e8f0; }

        .msg-ok  { background: #f0fdf4; border-left: 4px solid #22c55e; color: #166534; padding: 13px 18px; border-radius: 8px; margin-bottom: 20px; }
        .msg-erro{ background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; padding: 13px 18px; border-radius: 8px; margin-bottom: 20px; }

        .stats { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
        .stat-card {
            background: white; border-radius: 10px;
            border: 1px solid #e2e8f0;
            padding: 14px 20px; text-align: center; min-width: 110px;
        }
        .stat-num   { font-size: 1.6rem; font-weight: 600; color: var(--azul); }
        .stat-label { font-size: 0.78rem; color: #64748b; margin-top: 2px; }

        .stats-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .stats-actions a {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 12px;
            text-decoration: none;
            font-size: 0.85rem;
            color: #475569;
        }
        .stats-actions a:hover { background: #f8fafc; }

        .top5-wrap {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 24px;
        }
        .top5-titulo {
            margin: 0 0 14px;
            color: var(--azul);
            font-size: 1.02rem;
        }
        .top5-lista { display: grid; gap: 8px; }
        .top5-item {
            display: grid;
            grid-template-columns: 36px 1fr auto;
            align-items: center;
            gap: 10px;
            border: 1px solid #f1f5f9;
            border-radius: 9px;
            padding: 8px 10px;
            background: #fff;
        }
        .top5-rank {
            width: 28px; height: 28px;
            border-radius: 50%;
            background: #e0f2fe;
            color: #0c4a6e;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.78rem; font-weight: 700;
        }
        .top5-info a {
            color: var(--azul);
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 500;
        }
        .top5-info a:hover { color: var(--ciano); }
        .top5-cat { font-size: 0.75rem; color: #64748b; margin-top: 2px; }
        .top5-views {
            font-size: 0.8rem;
            color: #0ea5e9;
            font-weight: 600;
            white-space: nowrap;
        }
        .top5-vazio { color: #94a3b8; font-size: 0.9rem; padding: 8px 2px; }

        .tabela-wrap {
            background: white; border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.07); overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 13px 18px; text-align: left; font-size: 0.88rem; }
        th {
            background: var(--azul); color: white;
            font-weight: 500; font-size: 0.82rem; white-space: nowrap;
        }
        td { border-bottom: 1px solid #f1f5f9; color: #334155; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fafc; }

        .titulo-col { max-width: 280px; }
        .titulo-col a {
            color: var(--azul); text-decoration: none; font-weight: 500;
            display: -webkit-box; -webkit-line-clamp: 2;
            -webkit-box-orient: vertical; overflow: hidden;
        }
        .titulo-col a:hover { color: var(--ciano); }

        .badge-cat {
            display: inline-block; padding: 3px 10px;
            border-radius: 20px; font-size: 0.75rem; font-weight: 500;
            background: #e0f2fe; color: #0c4a6e; white-space: nowrap;
        }

        .acoes { display: flex; gap: 6px; }
        .btn-acao {
            padding: 6px 13px; border-radius: 7px; font-size: 0.8rem;
            font-weight: 500; border: none; cursor: pointer;
            font-family: 'Inter', sans-serif; text-decoration: none;
            display: inline-block; white-space: nowrap;
        }
        .btn-ver    { background: #f1f5f9; color: #475569; }
        .btn-editar { background: #e0f2fe; color: #0c4a6e; }
        .btn-excluir{ background: #fee2e2; color: #991b1b; }
        .btn-acao:hover { opacity: 0.8; }

        .paginacao {
            display: flex; justify-content: center; align-items: center;
            gap: 6px; margin-top: 28px; flex-wrap: wrap;
        }
        .paginacao a, .paginacao span {
            padding: 8px 14px; border-radius: 8px; font-size: 0.88rem;
            text-decoration: none; border: 1px solid #e2e8f0;
        }
        .paginacao a       { color: #475569; background: white; }
        .paginacao a:hover { background: #f1f5f9; }
        .paginacao .atual  { background: var(--azul); color: white; border-color: var(--azul); }
        .paginacao .desab  { color: #cbd5e1; pointer-events: none; background: #f8fafc; }

        .vazio { text-align: center; padding: 60px 20px; color: #94a3b8; font-size: 1rem; }

        .modal-bg {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.45);
            align-items: center; justify-content: center; z-index: 100;
        }
        .modal-bg.open { display: flex; }
        .modal {
            background: white; padding: 32px; border-radius: 14px;
            width: 100%; max-width: 420px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .modal h3 { margin: 0 0 10px; color: #991b1b; }
        .modal p  { color: #475569; font-size: 0.95rem; margin: 0 0 24px; }
        .modal-btns { display: flex; gap: 10px; justify-content: flex-end; }
        .btn-cancelar  { background: #f1f5f9; color: #475569; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-size: 0.9rem; }
        .btn-confirmar { background: #ef4444; color: white;   padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-size: 0.9rem; font-weight: 600; }

        @media (max-width: 768px) {
            .admin-header { padding: 14px 16px; flex-wrap: wrap; gap: 8px; }
            .container { padding: 0 12px; }
            .topo { flex-direction: column; align-items: flex-start; }
            th:nth-child(4), td:nth-child(4),
            th:nth-child(5), td:nth-child(5) { display: none; }
            .top5-item { grid-template-columns: 32px 1fr; }
            .top5-views { grid-column: 2; justify-self: start; }
        }
    </style>
</head>
<body>

<div class="admin-header">
    <strong>TechVidaReal — Painel Admin</strong>
    <div style="display:flex; gap:20px; align-items:center; flex-wrap:wrap;">
        <span>Olá, <?= htmlspecialchars($_SESSION['admin_nome'] ?? $_SESSION['admin_usuario']) ?></span>
        <a href="comentarios.php">Comentários</a>
        <?php if (($_SESSION['admin_nivel'] ?? '') === 'super'): ?>
            <a href="gerenciar_admins.php">Admins</a>
        <?php endif; ?>
        <a href="../index.php" target="_blank">Ver site</a>
        <a href="logout.php">Sair</a>
    </div>
</div>

<div class="container">

    <?php if (!empty($mensagem)): ?>
        <div class="msg-<?= $tipo_msg === 'ok' ? 'ok' : 'erro' ?>">
            <?= htmlspecialchars($mensagem) ?>
        </div>
    <?php endif; ?>

    <div class="topo">
        <h2>Artigos</h2>

        <form class="busca-form" method="GET">
            <input type="text" name="q" value="<?= htmlspecialchars($filtro) ?>" placeholder="Buscar artigos...">
            <button type="submit">Buscar</button>
            <?php if ($filtro !== ''): ?>
                <a href="index.php" style="padding:9px 14px; color:#64748b; text-decoration:none; font-size:0.9rem;">✕ Limpar</a>
            <?php endif; ?>
        </form>

        <a href="cadastrar.php" class="btn-novo">+ Novo artigo</a>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="stat-num"><?= number_format($total) ?></div>
            <div class="stat-label"><?= $filtro === '' ? 'Total de artigos' : 'Resultados' ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $total_paginas ?></div>
            <div class="stat-label">Páginas</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $pagina ?></div>
            <div class="stat-label">Página atual</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= number_format($metricas['comentarios_pendentes']) ?></div>
            <div class="stat-label">Comentários pendentes</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= number_format($metricas['newsletter_ativos']) ?></div>
            <div class="stat-label">Newsletter ativos</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= number_format($metricas['views_7d']) ?></div>
            <div class="stat-label">Views (7 dias)</div>
        </div>
    </div>

    <div class="stats-actions">
        <a href="export_comentarios.php?csrf=<?= urlencode($csrf_export) ?>&period=30d">Exportar comentários CSV (30d)</a>
        <?php if (($_SESSION['admin_nivel'] ?? '') === 'super'): ?>
            <a href="export_newsletter.php?csrf=<?= urlencode($csrf_export) ?>">Exportar newsletter CSV</a>
        <?php endif; ?>
    </div>

    <div class="top5-wrap">
        <h3 class="top5-titulo">Top 5 mais lidos da semana</h3>

        <?php if (empty($top_lidos)): ?>
            <div class="top5-vazio">Ainda não há visualizações suficientes para montar o ranking.</div>
        <?php else: ?>
            <div class="top5-lista">
                <?php foreach ($top_lidos as $idx => $item): ?>
                    <?php $views = (int)$item['views_semana']; ?>
                    <div class="top5-item">
                        <div class="top5-rank"><?= $idx + 1 ?></div>
                        <div class="top5-info">
                            <a href="../single.php?id=<?= (int)$item['id'] ?>" target="_blank">
                                <?= htmlspecialchars($item['titulo']) ?>
                            </a>
                            <div class="top5-cat"><?= htmlspecialchars($item['categoria']) ?></div>
                        </div>
                        <div class="top5-views">
                            <?= $views ?> visualiza<?= $views === 1 ? 'ção' : 'ções' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="tabela-wrap">
        <?php if (empty($artigos)): ?>
            <div class="vazio">
                <?= $filtro !== '' ? "Nenhum artigo encontrado para \"" . htmlspecialchars($filtro) . "\"." : 'Nenhum artigo cadastrado ainda.' ?>
                <?php if ($filtro === ''): ?>
                    <br><br><a href="cadastrar.php" style="color:var(--ciano);">Cadastre o primeiro artigo →</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Título</th>
                    <th>Categoria</th>
                    <th>Data</th>
                    <th>Leitura</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($artigos as $row): ?>
                <tr>
                    <td style="color:#94a3b8; font-size:0.82rem;"><?= (int)$row['id'] ?></td>
                    <td class="titulo-col">
                        <a href="../single.php?id=<?= (int)$row['id'] ?>" target="_blank">
                            <?= htmlspecialchars((string)$row['titulo']) ?>
                        </a>
                    </td>
                    <td><span class="badge-cat"><?= htmlspecialchars((string)$row['categoria']) ?></span></td>
                    <td style="white-space:nowrap; color:#64748b;">
                        <?= date('d/m/Y', strtotime((string)$row['data_publicacao'])) ?>
                    </td>
                    <td style="color:#64748b;"><?= (int)$row['tempo_leitura'] ?> min</td>
                    <td>
                        <div class="acoes">
                            <a href="../single.php?id=<?= (int)$row['id'] ?>" target="_blank" class="btn-acao btn-ver">Ver</a>
                            <a href="editar.php?id=<?= (int)$row['id'] ?>" class="btn-acao btn-editar">Editar</a>
                            <button
                                class="btn-acao btn-excluir"
                                type="button"
                                onclick='confirmarExclusao(<?= (int)$row['id'] ?>, <?= json_encode((string)$row['titulo'], JSON_UNESCAPED_UNICODE) ?>)'>
                                Excluir
                            </button>
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
        $qs = $filtro !== '' ? '&q=' . urlencode($filtro) : '';

        if ($pagina > 1):
        ?>
            <a href="?p=<?= $pagina - 1 . $qs ?>">← Anterior</a>
        <?php else: ?>
            <span class="desab">← Anterior</span>
        <?php endif; ?>

        <?php
        $inicio = max(1, $pagina - 2);
        $fim = min($total_paginas, $pagina + 2);
        if ($inicio > 1) echo '<span style="color:#94a3b8;">...</span>';

        for ($i = $inicio; $i <= $fim; $i++):
            if ($i === $pagina):
        ?>
            <span class="atual"><?= $i ?></span>
        <?php else: ?>
            <a href="?p=<?= $i . $qs ?>"><?= $i ?></a>
        <?php
            endif;
        endfor;

        if ($fim < $total_paginas) echo '<span style="color:#94a3b8;">...</span>';

        if ($pagina < $total_paginas):
        ?>
            <a href="?p=<?= $pagina + 1 . $qs ?>">Próxima →</a>
        <?php else: ?>
            <span class="desab">Próxima →</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<div class="modal-bg" id="modal-exclusao">
    <div class="modal">
        <h3>Excluir artigo</h3>
        <p>Tem certeza que deseja excluir o artigo:<br><strong id="modal-titulo"></strong><br><br>Esta ação não pode ser desfeita.</p>
        <form method="POST" id="form-excluir">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="acao" value="excluir">
            <input type="hidden" name="artigo_id" id="modal-artigo-id">
            <div class="modal-btns">
                <button type="button" class="btn-cancelar" onclick="fecharModal()">Cancelar</button>
                <button type="submit" class="btn-confirmar">Sim, excluir</button>
            </div>
        </form>
    </div>
</div>

<script>
function confirmarExclusao(id, titulo) {
    document.getElementById('modal-artigo-id').value = id;
    document.getElementById('modal-titulo').textContent = titulo;
    document.getElementById('modal-exclusao').classList.add('open');
}
function fecharModal() {
    document.getElementById('modal-exclusao').classList.remove('open');
}
document.getElementById('modal-exclusao').addEventListener('click', function(e) {
    if (e.target === this) fecharModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') fecharModal();
});
</script>

</body>
</html>

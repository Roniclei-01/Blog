<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
tvr_secure_session_start(false);

// CSRF newsletter (form público)
if (empty($_SESSION['newsletter_csrf'])) {
    $_SESSION['newsletter_csrf'] = bin2hex(random_bytes(32));
}

include 'header.php';
?>

<!-- HERO PRINCIPAL -->
<section class="hero-home">
    <div class="container">
        <h1>Tecnologia simples para a vida real</h1>
        <p class="hero-subtitle">
            Seu guia confiável que explica a tecnologia de forma clara, prática e útil.<br>
            Sem complicação. Sem jargões.
        </p>
        <a href="#artigos" class="btn-acao">Ver os últimos guias</a>
    </div>
</section>

<!-- CATEGORIAS EM DESTAQUE -->
<section class="categorias-destaque">
    <div class="container">
        <div class="categoria-grid">
            <a href="categoria.php?categoria=Notícias de Tecnologia" class="categoria-card">
                <span class="categoria-icon">📰</span>
                <h3>Notícias</h3>
                <p>Notícias quentes explicadas de forma simples</p>
            </a>

            <a href="categoria.php?categoria=Tecnologia na Prática" class="categoria-card">
                <span class="categoria-icon">🛠️</span>
                <h3>Na Prática</h3>
                <p>Tutoriais passo a passo para o dia a dia</p>
            </a>

            <a href="categoria.php?categoria=Curiosidades Tech" class="categoria-card">
                <span class="categoria-icon">🔍</span>
                <h3>Curiosidades</h3>
                <p>Fatos interessantes e conteúdo viral</p>
            </a>

            <a href="categoria.php?categoria=Ferramentas e Apps" class="categoria-card">
                <span class="categoria-icon">📱</span>
                <h3>Ferramentas</h3>
                <p>Melhores apps e ferramentas recomendadas</p>
            </a>
        </div>
    </div>
</section>

<!-- ÚLTIMOS ARTIGOS -->
<section id="artigos" class="ultimos-artigos">
    <div class="container">
        <div class="section-header">
            <h2>Últimos Guias</h2>
            <a href="todos-artigos.php" class="ver-todos">Ver todos os artigos →</a>
        </div>

        <div class="articles-grid">
            <?php
            $sql = "SELECT id, titulo, categoria, data_publicacao, tempo_leitura, imagem
                    FROM artigos
                    ORDER BY criado_em DESC
                    LIMIT 6";

            $result = $conn->query($sql);

            if ($result && $result->num_rows > 0):
                while ($row = $result->fetch_assoc()):
            ?>
                <div class="post-card">
                    <?php if (!empty($row['imagem'])): ?>
                        <div class="post-thumbnail">
                            <a href="single.php?id=<?= (int)$row['id'] ?>">
                                <img src="<?= htmlspecialchars((string)$row['imagem'], ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars((string)$row['titulo'], ENT_QUOTES, 'UTF-8') ?>">
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="post-content">
                        <div class="post-category">
                            <?= htmlspecialchars((string)$row['categoria'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <h3 class="post-title">
                            <a href="single.php?id=<?= (int)$row['id'] ?>">
                                <?= htmlspecialchars((string)$row['titulo'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </h3>
                        <div class="post-meta">
                            <?= date('d M, Y', strtotime((string)$row['data_publicacao'])) ?>
                            •
                            <?= (int)$row['tempo_leitura'] ?> min de leitura
                        </div>
                    </div>
                </div>
            <?php
                endwhile;
            else:
            ?>
                <p style="grid-column: 1 / -1; text-align: center; padding: 60px 20px; font-size: 1.1rem;">
                    Nenhum artigo cadastrado ainda.<br><br>
                    <a href="admin/cadastrar.php" style="color: var(--ciano); font-weight: 600;">
                        Cadastre o primeiro artigo no painel admin →
                    </a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- MAIS LIDOS DA SEMANA -->
<section class="ultimos-artigos">
    <div class="container">
        <div class="section-header">
            <h2>Mais lidos da semana</h2>
            <a href="todos-artigos.php" class="ver-todos">Explorar artigos →</a>
        </div>

        <div class="articles-grid">
            <?php
            $populares = [];
            $sql_pop = "
                SELECT
                    a.id, a.titulo, a.categoria, a.data_publicacao, a.tempo_leitura, a.imagem,
                    COUNT(v.id) AS views_semana
                FROM artigos a
                INNER JOIN artigo_visualizacoes v
                    ON v.artigo_id = a.id
                   AND v.viewed_at >= (NOW() - INTERVAL 7 DAY)
                GROUP BY a.id, a.titulo, a.categoria, a.data_publicacao, a.tempo_leitura, a.imagem
                ORDER BY views_semana DESC, a.data_publicacao DESC
                LIMIT 6
            ";

            $res_pop = $conn->query($sql_pop);
            if ($res_pop && $res_pop->num_rows > 0) {
                while ($r = $res_pop->fetch_assoc()) {
                    $populares[] = $r;
                }
            } else {
                $sql_fb = "
                    SELECT id, titulo, categoria, data_publicacao, tempo_leitura, imagem, 0 AS views_semana
                    FROM artigos
                    ORDER BY criado_em DESC
                    LIMIT 6
                ";
                $res_fb = $conn->query($sql_fb);
                if ($res_fb && $res_fb->num_rows > 0) {
                    while ($r = $res_fb->fetch_assoc()) {
                        $populares[] = $r;
                    }
                }
            }

            if (!empty($populares)):
                foreach ($populares as $row):
                    $viewsSemana = (int)($row['views_semana'] ?? 0);
            ?>
                <div class="post-card">
                    <?php if (!empty($row['imagem'])): ?>
                        <div class="post-thumbnail">
                            <a href="single.php?id=<?= (int)$row['id'] ?>">
                                <img src="<?= htmlspecialchars((string)$row['imagem'], ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars((string)$row['titulo'], ENT_QUOTES, 'UTF-8') ?>">
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="post-content">
                        <div class="post-category">
                            <?= htmlspecialchars((string)$row['categoria'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <h3 class="post-title">
                            <a href="single.php?id=<?= (int)$row['id'] ?>">
                                <?= htmlspecialchars((string)$row['titulo'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </h3>
                        <div class="post-meta">
                            <?= date('d M, Y', strtotime((string)$row['data_publicacao'])) ?>
                            •
                            <?= (int)$row['tempo_leitura'] ?> min
                        </div>
                        <div class="post-meta" style="margin-top: 4px; color:#0ea5e9; font-weight:600;">
                            <?= $viewsSemana ?> visualiza<?= $viewsSemana === 1 ? 'ção' : 'ções' ?> nos últimos 7 dias
                        </div>
                    </div>
                </div>
            <?php
                endforeach;
            else:
            ?>
                <p style="grid-column: 1 / -1; text-align: center; padding: 40px 20px; font-size: 1rem; color:#64748b;">
                    Ainda não há dados de leitura suficientes para montar este ranking.
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- NEWSLETTER -->
<section class="newsletter-cta">
    <div class="container">
        <div class="newsletter-box">
            <h2>Fique por dentro da tecnologia simples</h2>
            <p>Receba toda semana dicas práticas e explicações claras diretamente no seu e-mail.</p>

            <?php if (!empty($_GET['nl'])): ?>
                <div style="margin:12px 0 18px; text-align:center;">
                    <?php
                    $nl = (string)$_GET['nl'];
                    $map = [
                        'confirmar_email' => ['ok', 'Quase lá! Verifique seu e-mail para confirmar a inscrição.'],
                        'ja_ativo' => ['ok', 'Este e-mail já está inscrito na newsletter.'],
                        'email_invalido' => ['erro', 'Informe um e-mail válido.'],
                        'metodo' => ['erro', 'Requisição inválida.'],
                        'csrf' => ['erro', 'Sessão expirada. Recarregue a página e tente novamente.'],
                        'muitas_tentativas' => ['erro', 'Muitas tentativas. Aguarde alguns minutos e tente novamente.'],
                    ];
                    $entry = $map[$nl] ?? ['erro', 'Não foi possível concluir sua inscrição.'];
                    ?>
                    <div style="
                        display:inline-block;
                        padding:10px 14px;
                        border-radius:8px;
                        border-left:4px solid <?= $entry[0] === 'ok' ? '#22c55e' : '#ef4444' ?>;
                        background: <?= $entry[0] === 'ok' ? '#f0fdf4' : '#fef2f2' ?>;
                        color: <?= $entry[0] === 'ok' ? '#166534' : '#991b1b' ?>;
                        font-size:0.92rem;
                    ">
                        <?= htmlspecialchars($entry[1], ENT_QUOTES, 'UTF-8') ?>
                        <?php if (!empty($_SESSION['newsletter_debug_link'] ?? '')): ?>
                            <br>
                            <a href="<?= htmlspecialchars((string)$_SESSION['newsletter_debug_link'], ENT_QUOTES, 'UTF-8') ?>" style="color:#0ea5e9;">
                                (debug local) abrir link de confirmação
                            </a>
                            <?php unset($_SESSION['newsletter_debug_link']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form class="newsletter-form" action="newsletter_assinar.php" method="POST" autocomplete="off">
                <input type="email" name="email" placeholder="Seu melhor e-mail" required>
                <input type="hidden" name="newsletter_csrf" value="<?= htmlspecialchars((string)$_SESSION['newsletter_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;">
                <button type="submit" class="btn-acao">Inscrever-se gratuitamente</button>
            </form>

            <small>Zero spam. Você pode cancelar quando quiser.</small>
        </div>
    </div>
</section>

<?php
$conn->close();
include 'footer.php';
?>

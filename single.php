<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
tvr_secure_session_start(false);

require_once __DIR__ . '/db.php';

// ================================================
// Helpers
// ================================================
function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function slugify_pt(string $text): string {
    $text = trim($text);

    $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($conv !== false) {
        $text = $conv;
    }

    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');

    return $text;
}

function to_abs_single(string $url, string $baseUrl): string {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    if (strpos($url, '/') === 0) return rtrim($baseUrl, '/') . $url;
    return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
}

$scheme = tvr_is_https() ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/single.php';
$basePath = str_replace('\\', '/', dirname($scriptName));
$basePath = ($basePath === '/' || $basePath === '\\' || $basePath === '.') ? '' : rtrim($basePath, '/');
$baseUrl = $scheme . '://' . $host . $basePath;

// CSRF para comentário público
$commentCsrf = tvr_csrf_get('comment_csrf');

// ================================================
// Valida ID
// ================================================
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    $seo_title = 'Artigo nao encontrado | Codexa';
    $seo_description = 'O artigo solicitado nao foi encontrado.';
    $seo_url = $baseUrl . '/single.php';
    $seo_type = 'website';
    include 'header.php';

    echo "<div class='container' style='margin-top:100px; text-align:center;'><h2>Artigo nao encontrado.</h2><a href='index.php' class='btn-acao'>Voltar para Home</a></div>";

    include 'footer.php';
    $conn->close();
    exit;
}

// ================================================
// Busca artigo
// ================================================
$sql = "SELECT * FROM artigos WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();

    $seo_title = 'Artigo nao encontrado | Codexa';
    $seo_description = 'O artigo solicitado nao foi encontrado.';
    $seo_url = $baseUrl . '/single.php';
    $seo_type = 'website';
    include 'header.php';

    echo "<div class='container' style='margin-top:100px; text-align:center;'><h2>Artigo nao encontrado.</h2><a href='index.php' class='btn-acao'>Voltar para Home</a></div>";

    include 'footer.php';
    $conn->close();
    exit;
}

$article = $result->fetch_assoc();
$stmt->close();

// ================================================
// Registra visualizacao (1 por artigo por sessao a cada 60 min)
// ================================================
if (!isset($_SESSION['views_cache']) || !is_array($_SESSION['views_cache'])) {
    $_SESSION['views_cache'] = [];
}

$viewKey = 'artigo_' . $id;
$nowTs = time();
$deveRegistrar = true;

if (isset($_SESSION['views_cache'][$viewKey])) {
    $ultimo = (int)$_SESSION['views_cache'][$viewKey];
    if (($nowTs - $ultimo) < 3600) {
        $deveRegistrar = false;
    }
}

if ($deveRegistrar) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $stmt_view = $conn->prepare("
        INSERT INTO artigo_visualizacoes (artigo_id, ip_address, user_agent)
        VALUES (?, ?, ?)
    ");
    if ($stmt_view) {
        $stmt_view->bind_param("iss", $id, $ip, $ua);
        $stmt_view->execute();
        $stmt_view->close();
    }

    $_SESSION['views_cache'][$viewKey] = $nowTs;

    if (count($_SESSION['views_cache']) > 200) {
        $_SESSION['views_cache'] = array_slice($_SESSION['views_cache'], -200, null, true);
    }
}

// ================================================
// SEO dinâmico da página
// ================================================
$artigo_url = $baseUrl . '/single.php?id=' . $id;
$descricao_base = trim(strip_tags((string)($article['conteudo'] ?? '')));
$descricao_base = preg_replace('/\s+/', ' ', $descricao_base);
$seo_description = substr($descricao_base, 0, 160);
if (strlen($descricao_base) > 160) {
    $seo_description .= '...';
}

$seo_title = (string)$article['titulo'] . ' | Codexa';
$seo_url = $artigo_url;
$seo_type = 'article';
$seo_section = (string)$article['categoria'];
$seo_published_time = date(DATE_ATOM, strtotime((string)$article['data_publicacao']));
$seo_modified_time = $seo_published_time;
$seo_image = !empty($article['imagem'])
    ? to_abs_single((string)$article['imagem'], $baseUrl)
    : $baseUrl . '/assets/imagem1.png';

include 'header.php';

// ================================================
// Parse do conteúdo com suporte a ## e ###
// ================================================
$conteudo_raw = (string)($article['conteudo'] ?? '');
$conteudo_raw = str_replace(["\r\n", "\r"], "\n", $conteudo_raw);
$linhas = explode("\n", $conteudo_raw);

$toc_items = [];
$conteudo_blocos = [];
$paragrafo_buffer = [];
$ids_usados = [];
$contador_secao = 0;

$flush_paragrafo = function () use (&$paragrafo_buffer, &$conteudo_blocos) {
    if (empty($paragrafo_buffer)) return;
    $conteudo_blocos[] = '<p>' . implode('<br>', $paragrafo_buffer) . '</p>';
    $paragrafo_buffer = [];
};

foreach ($linhas as $linha) {
    $trim = trim($linha);

    if (preg_match('/^(#{2,3})\s+(.+)$/u', $trim, $m)) {
        $flush_paragrafo();

        $nivel = strlen($m[1]) === 2 ? 2 : 3;
        $titulo_secao = trim($m[2]);

        $contador_secao++;
        $slug_base = slugify_pt($titulo_secao);
        if ($slug_base === '') $slug_base = 'secao-' . $contador_secao;

        $slug = $slug_base;
        $sufixo = 2;
        while (isset($ids_usados[$slug])) {
            $slug = $slug_base . '-' . $sufixo;
            $sufixo++;
        }
        $ids_usados[$slug] = true;

        $toc_items[] = [
            'id' => $slug,
            'titulo' => $titulo_secao,
            'nivel' => $nivel,
        ];

        $conteudo_blocos[] = "<h{$nivel} id=\"" . e($slug) . "\" class=\"post-heading\">" . e($titulo_secao) . "</h{$nivel}>";
        continue;
    }

    if ($trim === '') {
        $flush_paragrafo();
        continue;
    }

    $paragrafo_buffer[] = e($trim);
}
$flush_paragrafo();

if (empty($conteudo_blocos)) {
    $conteudo_renderizado = nl2br(e($conteudo_raw));
} else {
    $conteudo_renderizado = implode("\n", $conteudo_blocos);
}

// ================================================
// Artigos relacionados
// ================================================
$relacionados = [];

$sql_rel = "SELECT id, titulo, categoria, data_publicacao, tempo_leitura, imagem
            FROM artigos
            WHERE id <> ? AND categoria = ?
            ORDER BY data_publicacao DESC, criado_em DESC
            LIMIT 3";

$stmt_rel = $conn->prepare($sql_rel);
$stmt_rel->bind_param("is", $id, $article['categoria']);
$stmt_rel->execute();
$relacionados = $stmt_rel->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_rel->close();

if (count($relacionados) < 3) {
    $faltam = 3 - count($relacionados);

    $ids_excluir = [$id];
    foreach ($relacionados as $r) {
        $ids_excluir[] = (int)$r['id'];
    }

    $placeholders = implode(',', array_fill(0, count($ids_excluir), '?'));
    $tipos = str_repeat('i', count($ids_excluir)) . 'i';

    $sql_fb = "SELECT id, titulo, categoria, data_publicacao, tempo_leitura, imagem
               FROM artigos
               WHERE id NOT IN ($placeholders)
               ORDER BY data_publicacao DESC, criado_em DESC
               LIMIT ?";

    $stmt_fb = $conn->prepare($sql_fb);
    $params = array_merge($ids_excluir, [$faltam]);
    $stmt_fb->bind_param($tipos, ...$params);
    $stmt_fb->execute();
    $fallback = $stmt_fb->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_fb->close();

    $relacionados = array_merge($relacionados, $fallback);
}

// ================================================
// Top 5 mais lidos da semana
// ================================================
$top_lidos_semana = [];

$sql_top = "
    SELECT a.id, a.titulo, a.categoria, COUNT(v.id) AS views_semana
    FROM artigos a
    INNER JOIN artigo_visualizacoes v
        ON v.artigo_id = a.id
       AND v.viewed_at >= (NOW() - INTERVAL 7 DAY)
    WHERE a.id <> ?
    GROUP BY a.id, a.titulo, a.categoria
    ORDER BY views_semana DESC, a.data_publicacao DESC
    LIMIT 5
";

$stmt_top = $conn->prepare($sql_top);
if ($stmt_top) {
    $stmt_top->bind_param("i", $id);
    $stmt_top->execute();
    $top_lidos_semana = $stmt_top->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_top->close();
}

// ================================================
// Comentarios aprovados
// ================================================
$comentarios = [];
$total_comentarios = 0;

$stmt_c = $conn->prepare("
    SELECT id, nome, conteudo, created_at
    FROM comentarios
    WHERE artigo_id = ? AND status = 'aprovado'
    ORDER BY created_at DESC
");
if ($stmt_c) {
    $stmt_c->bind_param("i", $id);
    $stmt_c->execute();
    $comentarios = $stmt_c->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_c->close();
}
$total_comentarios = count($comentarios);

// Mensagens do envio de comentário
$cm = (string)($_GET['cm'] ?? '');
$cm_map = [
    'ok' => ['ok', 'Comentario enviado com sucesso. Ele sera publicado apos moderacao.'],
    'csrf' => ['erro', 'Sessao expirada. Recarregue a pagina e tente novamente.'],
    'nome' => ['erro', 'Informe um nome valido (2 a 120 caracteres).'],
    'email' => ['erro', 'Informe um e-mail valido.'],
    'conteudo' => ['erro', 'Comentario deve ter entre 5 e 3000 caracteres.'],
    'flood' => ['erro', 'Aguarde um minuto antes de enviar outro comentario.'],
    'erro' => ['erro', 'Nao foi possivel enviar seu comentario. Tente novamente.'],
];
$cm_msg = $cm_map[$cm] ?? null;

// ================================================
// Schema.org
// ================================================
$article_schema = [
    "@context" => "https://schema.org",
    "@type" => "Article",
    "headline" => (string)$article['titulo'],
    "description" => $seo_description,
    "datePublished" => date('Y-m-d', strtotime((string)$article['data_publicacao'])),
    "mainEntityOfPage" => $artigo_url,
    "articleSection" => (string)$article['categoria'],
    "author" => [
        "@type" => "Organization",
        "name" => "Codexa"
    ],
    "publisher" => [
        "@type" => "Organization",
        "name" => "Codexa"
    ]
];

if (!empty($seo_image)) {
    $article_schema["image"] = [$seo_image];
}

$breadcrumb_schema = [
    "@context" => "https://schema.org",
    "@type" => "BreadcrumbList",
    "itemListElement" => [
        [
            "@type" => "ListItem",
            "position" => 1,
            "name" => "Home",
            "item" => $baseUrl . "/index.php"
        ],
        [
            "@type" => "ListItem",
            "position" => 2,
            "name" => (string)$article['categoria'],
            "item" => $baseUrl . "/categoria.php?categoria=" . rawurlencode((string)$article['categoria'])
        ],
        [
            "@type" => "ListItem",
            "position" => 3,
            "name" => (string)$article['titulo'],
            "item" => $artigo_url
        ]
    ]
];
?>


<div class="container" style="margin-top: 30px; margin-bottom: 10px;">
    <nav aria-label="breadcrumb" style="font-size: 0.95rem; color: #64748b;">
        <a href="index.php" style="color: var(--ciano);">Home</a> &raquo;
        <a href="categoria.php?categoria=<?= urlencode((string)$article['categoria']) ?>" style="color: var(--ciano);">
            <?= e((string)$article['categoria']) ?>
        </a> &raquo;
        <span style="color: var(--texto);"><?= e((string)$article['titulo']) ?></span>
    </nav>
</div>

<section class="article-hero">
    <div class="container">
        <div class="article-meta">
            <span><?= e((string)$article['categoria']) ?></span>
            <span>•</span>
            <span><?= date('d \d\e F \d\e Y', strtotime((string)$article['data_publicacao'])) ?></span>
            <span>•</span>
            <span><?= (int)$article['tempo_leitura'] ?> min de leitura</span>
        </div>
        <h1 class="article-title"><?= e((string)$article['titulo']) ?></h1>
    </div>
</section>

<div class="article-content container">

    <?php if (!empty($article['imagem'])): ?>
        <img src="<?= e((string)$article['imagem']) ?>"
             alt="<?= e((string)$article['titulo']) ?>"
             class="article-image">
    <?php endif; ?>

    <?php if (!empty($toc_items)): ?>
        <aside class="toc-box">
            <h2>Sumario do artigo</h2>
            <ul class="toc-list">
                <?php foreach ($toc_items as $item): ?>
                    <li class="n<?= (int)$item['nivel'] ?>">
                        <a href="#<?= e((string)$item['id']) ?>"><?= e((string)$item['titulo']) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>
    <?php endif; ?>

    <aside class="top5-inline">
        <h3>Mais lidos da semana</h3>
        <?php if (empty($top_lidos_semana)): ?>
            <div class="top5-empty">Ainda sem dados suficientes.</div>
        <?php else: ?>
            <div class="top5-list">
                <?php foreach ($top_lidos_semana as $idx => $top): ?>
                    <?php $views = (int)$top['views_semana']; ?>
                    <a class="top5-item" href="single.php?id=<?= (int)$top['id'] ?>">
                        <span class="top5-rank"><?= $idx + 1 ?></span>
                        <span>
                            <span class="top5-title"><?= e((string)$top['titulo']) ?></span>
                            <span class="top5-meta"><?= e((string)$top['categoria']) ?> • <?= $views ?> visualiza<?= $views === 1 ? 'ção' : 'ções' ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </aside>

    <div class="post-content" id="post-content">
        <?= $conteudo_renderizado ?>
 <?php
// ================================================
// TRECHO PARA ADICIONAR NO single.php


// Renderiza os blocos de imagem interna (se existirem)
function renderizar_bloco_img(string $img, string $link, string $alt): string
{
    $img  = trim($img);
    $link = trim($link);
    $alt  = trim($alt) ?: 'Imagem do artigo';

    if ($img === '') {
        return '';
    }

    $img_escaped  = htmlspecialchars($img,  ENT_QUOTES, 'UTF-8');
    $alt_escaped  = htmlspecialchars($alt,  ENT_QUOTES, 'UTF-8');
    $link_escaped = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

    $tag_img = "<img src=\"{$img_escaped}\" alt=\"{$alt_escaped}\" loading=\"lazy\">";

    if ($link !== '') {
        $conteudo_interno = "<a href=\"{$link_escaped}\" target=\"_blank\" rel=\"noopener noreferrer\">{$tag_img}</a>";
    } else {
        $conteudo_interno = $tag_img;
    }

    return "<div class=\"img-interna-bloco\">{$conteudo_interno}</div>";
}

// Gera os blocos (use os campos do $article carregado no topo do single.php)
$bloco1 = renderizar_bloco_img(
    (string)($article['img_interna_1']  ?? ''),
    (string)($article['link_interno_1'] ?? ''),
    (string)($article['alt_interno_1']  ?? '')
);

$bloco2 = renderizar_bloco_img(
    (string)($article['img_interna_2']  ?? ''),
    (string)($article['link_interno_2'] ?? ''),
    (string)($article['alt_interno_2']  ?? '')
);

// Exibe os blocos
echo $bloco1;
echo $bloco2;

// ================================================
// HTML GERADO (exemplo de saída):
//
// <div class="img-interna-bloco">
//   <a href="https://siteexterno.com" target="_blank" rel="noopener noreferrer">
//     <img src="assets/uploads/xyz.jpg" alt="Descrição" loading="lazy">
//   </a>
// </div>
// ================================================
?>       
    </div>

    <div class="destaque-pratico">
        <strong>Dica pratica:</strong> Ajude a divulgar as novidades do mundo da tecnologia compartilhando este conteúdo com outros entusiastas da área.
    </div>

    <div style="margin: 50px 0 40px; text-align: center;">
        <p style="margin-bottom: 15px; font-weight: 600; color: #475569;">Compartilhe este artigo com seus amigos:</p>
        <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
            <a href="https://wa.me/?text=<?= urlencode((string)$article['titulo'] . ' - ' . $artigo_url) ?>" target="_blank"
               style="background:#25D366; color:white; padding:12px 24px; border-radius:50px; text-decoration:none; font-weight:600;">WhatsApp</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($artigo_url) ?>" target="_blank"
               style="background:#1877F2; color:white; padding:12px 24px; border-radius:50px; text-decoration:none; font-weight:600;">Facebook</a>
            <a href="https://twitter.com/intent/tweet?url=<?= urlencode($artigo_url) ?>&text=<?= urlencode((string)$article['titulo']) ?>" target="_blank"
               style="background:#000000; color:white; padding:12px 24px; border-radius:50px; text-decoration:none; font-weight:600;">X (Twitter)</a>
            <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= urlencode($artigo_url) ?>" target="_blank"
               style="background:#0A66C2; color:white; padding:12px 24px; border-radius:50px; text-decoration:none; font-weight:600;">LinkedIn</a>
        </div>
    </div>

    <section class="comments-section" id="comentarios">
        <h2>Comentarios (<?= $total_comentarios ?>)</h2>

        <?php if ($cm_msg): ?>
            <div class="<?= $cm_msg[0] === 'ok' ? 'comment-msg-ok' : 'comment-msg-erro' ?>">
                <?= e((string)$cm_msg[1]) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="comentario_enviar.php" class="comment-form" autocomplete="off">
            <input type="hidden" name="artigo_id" value="<?= (int)$id ?>">
            <input type="hidden" name="comment_csrf" value="<?= e($commentCsrf) ?>">
            <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute; left:-9999px;">

            <div class="comment-grid">
                <div>
                    <label style="display:block; font-size:0.85rem; color:#475569; margin-bottom:6px;">Nome</label>
                    <input type="text" name="nome" required maxlength="120" placeholder="Seu nome">
                </div>
                <div>
                    <label style="display:block; font-size:0.85rem; color:#475569; margin-bottom:6px;">E-mail</label>
                    <input type="email" name="email" required maxlength="190" placeholder="voce@email.com">
                </div>
            </div>

            <div style="margin-top:10px;">
                <label style="display:block; font-size:0.85rem; color:#475569; margin-bottom:6px;">Comentario</label>
                <textarea name="conteudo" required maxlength="3000" placeholder="Escreva seu comentario..."></textarea>
            </div>

            <button type="submit" class="btn-acao" style="margin:12px 0 0;">Enviar comentario</button>
            <small style="display:block; margin-top:8px; color:#64748b;">Seu comentario passara por moderacao antes de aparecer publicamente.</small>
        </form>

        <?php if (empty($comentarios)): ?>
            <div class="comment-empty">Ainda nao ha comentarios aprovados. Seja o primeiro a comentar.</div>
        <?php else: ?>
            <div class="comment-list">
                <?php foreach ($comentarios as $c): ?>
                    <article class="comment-item">
                        <div class="comment-head">
                            <span class="comment-name"><?= e((string)$c['nome']) ?></span>
                            <span class="comment-date"><?= date('d/m/Y H:i', strtotime((string)$c['created_at'])) ?></span>
                        </div>
                        <div class="comment-body"><?= nl2br(e((string)$c['conteudo'])) ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if (!empty($relacionados)): ?>
        <section class="related-section">
            <h2>Artigos relacionados</h2>
            <div class="related-grid">
                <?php foreach ($relacionados as $rel): ?>
                    <article class="related-card">
                        <?php if (!empty($rel['imagem'])): ?>
                            <a href="single.php?id=<?= (int)$rel['id'] ?>" class="related-thumb">
                                <img src="<?= e((string)$rel['imagem']) ?>" alt="<?= e((string)$rel['titulo']) ?>">
                            </a>
                        <?php endif; ?>
                        <div class="related-body">
                            <div class="related-category"><?= e((string)$rel['categoria']) ?></div>
                            <h3 class="related-title">
                                <a href="single.php?id=<?= (int)$rel['id'] ?>"><?= e((string)$rel['titulo']) ?></a>
                            </h3>
                            <div class="related-meta">
                                <?= date('d/m/Y', strtotime((string)$rel['data_publicacao'])) ?> • <?= (int)$rel['tempo_leitura'] ?> min
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <a href="index.php" class="btn-acao">← Voltar para a Pagina Inicial</a>

    <script type="application/ld+json">
    <?= json_encode($article_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    </script>
    <script type="application/ld+json">
    <?= json_encode($breadcrumb_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    </script>
</div>

<script>
document.querySelectorAll('.toc-list a').forEach(function(link) {
    link.addEventListener('click', function(e) {
        var id = this.getAttribute('href').substring(1);
        var target = document.getElementById(id);
        if (!target) return;
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        history.replaceState(null, '', '#' + id);
    });
});

(function() {
    var links = Array.prototype.slice.call(document.querySelectorAll('.toc-list a'));
    if (!links.length) return;

    var map = {};
    links.forEach(function(link) {
        var id = link.getAttribute('href').substring(1);
        var el = document.getElementById(id);
        if (el) map[id] = { link: link, el: el };
    });

    var items = Object.keys(map).map(function(k){ return map[k]; });
    if (!items.length) return;

    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                links.forEach(function(l){ l.classList.remove('active'); });
                var id = entry.target.getAttribute('id');
                if (map[id]) map[id].link.classList.add('active');
            }
        });
    }, {
        root: null,
        rootMargin: '-25% 0px -60% 0px',
        threshold: 0.01
    });

    items.forEach(function(item) {
        observer.observe(item.el);
    });
})();
</script>

<?php
$conn->close();
include 'footer.php';
?>
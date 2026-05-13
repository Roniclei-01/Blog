<?php 
include 'header.php'; 

// Recebe o termo de busca
$termo = isset($_GET['q']) ? trim($_GET['q']) : '';
$resultados = [];

if (!empty($termo)) {
    // Busca segura com LIKE em título e conteúdo
    $sql = "SELECT id, titulo, categoria, data_publicacao, tempo_leitura, imagem 
            FROM artigos 
            WHERE titulo LIKE ? 
               OR conteudo LIKE ? 
            ORDER BY criado_em DESC";

    $stmt = $conn->prepare($sql);
    $like = "%" . $termo . "%";
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $resultados[] = $row;
    }
    $stmt->close();
}
?>

<div class="container" style="margin-top: 40px;">
    
    <!-- Formulário de Busca -->
    <div style="max-width: 700px; margin: 0 auto 50px; text-align: center;">
        <h1>Buscar no Blog</h1>
        <form method="GET" action="busca.php" style="margin-top: 25px;">
            <input type="text" 
                   name="q" 
                   value="<?= htmlspecialchars($termo) ?>" 
                   placeholder="Digite o que você está procurando..." 
                   style="width: 100%; padding: 16px 20px; font-size: 1.1rem; border: 2px solid var(--ciano); border-radius: 10px; outline: none;"
                   required>
            <button type="submit" 
                    class="btn-acao" 
                    style="margin-top: 15px; width: 100%; max-width: 300px;">
                Buscar
            </button>
        </form>
    </div>

    <?php if (!empty($termo)): ?>
        <h2 style="margin-bottom: 30px;">
            Resultados para: <span style="color: var(--ciano);">"<?= htmlspecialchars($termo) ?>"</span>
        </h2>

        <?php if (count($resultados) > 0): ?>
            <div class="articles-grid">
                <?php foreach ($resultados as $row): ?>
                    <div class="post-card">
                        <?php if (!empty($row['imagem'])): ?>
                            <div class="post-thumbnail">
                                <a href="single.php?id=<?= $row['id'] ?>">
                                    <img src="<?= htmlspecialchars($row['imagem']) ?>" 
                                         alt="<?= htmlspecialchars($row['titulo']) ?>">
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <div class="post-content">
                            <div class="post-category">
                                <?= htmlspecialchars($row['categoria']) ?>
                            </div>
                            <h3 class="post-title">
                                <a href="single.php?id=<?= $row['id'] ?>">
                                    <?= htmlspecialchars($row['titulo']) ?>
                                </a>
                            </h3>
                            <div class="post-meta">
                                <?= date('d M, Y', strtotime($row['data_publicacao'])) ?> 
                                • 
                                <?= $row['tempo_leitura'] ?> min de leitura
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="text-align: center; padding: 80px 20px; font-size: 1.2rem; color: #64748b;">
                Nenhum artigo encontrado para "<strong><?= htmlspecialchars($termo) ?></strong>".<br><br>
                Tente usar outras palavras ou <a href="index.php" style="color: var(--ciano);">volte para a página inicial</a>.
            </p>
        <?php endif; ?>
    <?php endif; ?>

</div>

<?php 
$conn->close(); 
include 'footer.php'; 
?>

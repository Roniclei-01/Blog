<?php 
include 'header.php'; 
?>

<div class="container" style="margin-top: 40px;">
    <h1 style="text-align:center; margin-bottom: 50px;">Todos os Artigos</h1>

    <div class="articles-grid">
        <?php
        $sql = "SELECT id, titulo, categoria, data_publicacao, tempo_leitura, imagem 
                FROM artigos 
                ORDER BY criado_em DESC";

        $result = $conn->query($sql);

        if ($result->num_rows > 0):
            while($row = $result->fetch_assoc()):
        ?>
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

        <?php 
            endwhile;
        else:
        ?>
            <p style="grid-column: 1 / -1; text-align: center; padding: 100px 20px; font-size: 1.2rem;">
                Nenhum artigo cadastrado ainda.<br><br>
                <a href="admin/cadastrar.php" style="color: var(--ciano);">Cadastre seu primeiro artigo no painel →</a>
            </p>
        <?php endif; ?>
    </div>
</div>

<?php 
$conn->close(); 
include 'footer.php'; 
?>

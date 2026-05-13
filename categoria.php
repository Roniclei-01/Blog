<?php 
include 'header.php'; 

// Recebe a categoria da URL
$categoria = isset($_GET['categoria']) ? trim($_GET['categoria']) : '';

if (empty($categoria)) {
    echo "<p style='text-align:center; padding:100px;'>Categoria não informada.</p>";
    include 'footer.php';
    exit;
}
?>

<div class="container" style="margin-top: 40px;">
    <h1 style="text-align:center; margin-bottom: 50px;">
        Categoria: <span style="color: var(--ciano);"><?= htmlspecialchars($categoria) ?></span>
    </h1>

    <div class="articles-grid">
        <?php
        $sql = "SELECT id, titulo, categoria, data_publicacao, tempo_leitura, imagem 
                FROM artigos 
                WHERE categoria = ? 
                ORDER BY criado_em DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $categoria);
        $stmt->execute();
        $result = $stmt->get_result();

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
            <p style="grid-column: 1 / -1; text-align: center; padding: 80px 20px; font-size: 1.2rem;">
                Nenhum artigo encontrado nesta categoria ainda.<br><br>
                <a href="admin/cadastrar.php" style="color: var(--ciano);">Cadastre o primeiro artigo aqui →</a>
            </p>
        <?php endif; ?>
    </div>
</div>

<?php 
$stmt->close();
$conn->close(); 
include 'footer.php'; 
?>

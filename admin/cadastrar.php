<?php
declare(strict_types=1);
// ================================================
// admin/cadastrar.php — Com upload de capa + 2 blocos imagem interna
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

$mensagem = '';
$tipo_msg = '';
$erros    = [];

$categorias_validas = [
    'Notícias de Tecnologia',
    'Tecnologia na Prática',
    'Curiosidades Tech',
    'Ferramentas e Apps',
];

// Extensões e tamanho permitidos para upload
$extensoes_permitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
$tamanho_maximo       = 5 * 1024 * 1024; // 5 MB
$pasta_upload         = __DIR__ . '/../assets/uploads/';

// Garante que a pasta de uploads existe
if (!is_dir($pasta_upload)) {
    mkdir($pasta_upload, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tvr_is_same_origin_request()) {
        $mensagem = 'Origem inválida. Recarregue a página e tente novamente.';
        $tipo_msg = 'erro';
    } elseif (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $mensagem = 'Requisição inválida. Recarregue a página e tente novamente.';
        $tipo_msg = 'erro';
    } else {
        $titulo          = trim((string)($_POST['titulo']          ?? ''));
        $categoria       = trim((string)($_POST['categoria']       ?? ''));
        $data_publicacao = trim((string)($_POST['data_publicacao'] ?? ''));
        $tempo_leitura   = (int)($_POST['tempo_leitura']           ?? 0);
        $conteudo        = trim((string)($_POST['conteudo']        ?? ''));
        $imagem_url      = trim((string)($_POST['imagem_url']      ?? ''));  // URL externa da capa

        // Blocos de imagem interna
        $img_interna_1  = trim((string)($_POST['img_interna_1']  ?? ''));
        $link_interno_1 = trim((string)($_POST['link_interno_1'] ?? ''));
        $alt_interno_1  = trim((string)($_POST['alt_interno_1']  ?? ''));
        $img_interna_2  = trim((string)($_POST['img_interna_2']  ?? ''));
        $link_interno_2 = trim((string)($_POST['link_interno_2'] ?? ''));
        $alt_interno_2  = trim((string)($_POST['alt_interno_2']  ?? ''));

        // Validações gerais
        if ($titulo === '') {
            $erros[] = 'O título é obrigatório.';
        } elseif (strlen($titulo) > 255) {
            $erros[] = 'O título deve ter no máximo 255 caracteres.';
        }
        if (!in_array($categoria, $categorias_validas, true)) {
            $erros[] = 'Categoria inválida.';
        }
        if ($data_publicacao === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_publicacao)) {
            $erros[] = 'Data de publicação inválida.';
        }
        if ($tempo_leitura < 1 || $tempo_leitura > 999) {
            $erros[] = 'Tempo de leitura deve ser entre 1 e 999 minutos.';
        }
        if ($conteudo === '') {
            $erros[] = 'O conteúdo é obrigatório.';
        }

        // ── Processa imagem de capa (upload ou URL) ──────────────────────────
        $imagem_final  = '';   // URL final que vai para o banco
        $imagem_upload = '';   // caminho do arquivo uploadado (se houver)

        $tem_upload = isset($_FILES['imagem_upload']) && $_FILES['imagem_upload']['error'] !== UPLOAD_ERR_NO_FILE;

        if ($tem_upload) {
            $file  = $_FILES['imagem_upload'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $erros[] = 'Erro no upload da imagem de capa (código ' . $file['error'] . ').';
            } elseif ($file['size'] > $tamanho_maximo) {
                $erros[] = 'A imagem de capa deve ter no máximo 5 MB.';
            } else {
                // Valida extensão pelo conteúdo real (não só pelo nome)
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                $mimes_permitidos = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                if (!in_array($mime, $mimes_permitidos, true)) {
                    $erros[] = 'Tipo de imagem não permitido. Use JPG, PNG, WebP ou GIF.';
                } else {
                    $ext         = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $nome_seguro = bin2hex(random_bytes(16)) . '_' . time() . '.' . $ext;
                    $destino     = $pasta_upload . $nome_seguro;

                    if (!move_uploaded_file($file['tmp_name'], $destino)) {
                        $erros[] = 'Falha ao salvar a imagem. Verifique as permissões da pasta uploads/.';
                    } else {
                        $imagem_upload = 'assets/uploads/' . $nome_seguro;
                        $imagem_final  = $imagem_upload;
                    }
                }
            }
        } elseif ($imagem_url !== '') {
            // URL externa — validação básica
            if (!filter_var($imagem_url, FILTER_VALIDATE_URL) && !preg_match('/^assets\//', $imagem_url)) {
                $erros[] = 'URL da imagem de capa inválida.';
            } elseif (strlen($imagem_url) > 500) {
                $erros[] = 'URL da imagem de capa muito longa.';
            } else {
                $imagem_final = $imagem_url;
            }
        }

        // Valida links internos
        foreach (['link_interno_1' => $link_interno_1, 'link_interno_2' => $link_interno_2] as $campo => $val) {
            if ($val !== '' && !filter_var($val, FILTER_VALIDATE_URL)) {
                $erros[] = "O link do bloco de imagem interna é inválido (deve começar com https://).";
                break;
            }
        }

        // ── Salva no banco ───────────────────────────────────────────────────
        if (empty($erros)) {
            $sql = "INSERT INTO artigos
                        (titulo, categoria, data_publicacao, tempo_leitura,
                         imagem, imagem_upload,
                         img_interna_1, link_interno_1, alt_interno_1,
                         img_interna_2, link_interno_2, alt_interno_2,
                         conteudo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param(
                    "sssisssssssss",
                    $titulo, $categoria, $data_publicacao, $tempo_leitura,
                    $imagem_final, $imagem_upload,
                    $img_interna_1, $link_interno_1, $alt_interno_1,
                    $img_interna_2, $link_interno_2, $alt_interno_2,
                    $conteudo
                );

                if ($stmt->execute()) {
                    $id_novo  = $stmt->insert_id;
                    $mensagem = 'Artigo cadastrado com sucesso!';
                    $tipo_msg = 'ok';
                    // Limpa campos
                    $titulo = $categoria = $data_publicacao = $conteudo = '';
                    $imagem_url = $img_interna_1 = $link_interno_1 = $alt_interno_1 = '';
                    $img_interna_2 = $link_interno_2 = $alt_interno_2 = '';
                    $tempo_leitura = 8;
                } else {
                    $mensagem = 'Erro ao cadastrar o artigo. Tente novamente.';
                    $tipo_msg = 'erro';
                }
                $stmt->close();
            } else {
                $mensagem = 'Erro interno. Contate o administrador.';
                $tipo_msg = 'erro';
            }
        } else {
            $mensagem = implode('<br>', array_map('htmlspecialchars', $erros));
            $tipo_msg = 'erro';
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastrar Artigo — TechVidaReal</title>
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
        .admin-header span { font-size: 0.9rem; color: #94a3b8; }

        .container { max-width: 900px; margin: 40px auto; padding: 0 20px; }

        .card {
            background: white; border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08); padding: 40px;
            margin-bottom: 24px;
        }
        .card h2 { color: var(--azul); margin: 0 0 24px; font-size: 1.3rem; }
        .card h3 {
            color: var(--azul); font-size: 1rem; margin: 0 0 18px;
            padding-bottom: 10px; border-bottom: 1.5px solid #e2e8f0;
        }

        .msg-ok  { background: #f0fdf4; border-left: 4px solid #22c55e; color: #166534; padding: 14px 18px; border-radius: 8px; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; }
        .msg-erro{ background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; padding: 14px 18px; border-radius: 8px; margin-bottom: 24px; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .span2 { grid-column: span 2; }
        .span3 { grid-column: span 3; }

        label { display: block; font-size: 0.85rem; font-weight: 500; color: #475569; margin-bottom: 6px; }
        label .obrigatorio { color: #ef4444; margin-left: 2px; }
        label .opcional { color: #94a3b8; font-weight: 400; margin-left: 4px; font-size: 0.8rem; }

        input[type=text], input[type=date], input[type=number], input[type=url],
        select, textarea {
            width: 100%; padding: 11px 14px;
            border: 1.5px solid #e2e8f0; border-radius: 9px;
            font-size: 0.95rem; font-family: 'Inter', sans-serif;
            color: #1e293b; transition: border-color 0.2s;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--ciano); }
        textarea { height: 380px; resize: vertical; line-height: 1.6; }

        /* Upload de imagem */
        .capa-tabs { display: flex; gap: 8px; margin-bottom: 14px; }
        .capa-tab {
            padding: 7px 16px; border-radius: 8px; font-size: 0.85rem;
            font-weight: 500; cursor: pointer; border: 1.5px solid #e2e8f0;
            background: #f8fafc; color: #64748b; transition: all 0.2s;
        }
        .capa-tab.active { background: var(--ciano); color: white; border-color: var(--ciano); }

        .upload-area {
            border: 2px dashed #cbd5e1; border-radius: 12px;
            padding: 32px 20px; text-align: center; cursor: pointer;
            transition: all 0.2s; background: #f8fafc;
        }
        .upload-area:hover, .upload-area.dragover { border-color: var(--ciano); background: #e0f7fb; }
        .upload-area input[type=file] { display: none; }
        .upload-icon { font-size: 2rem; margin-bottom: 8px; }
        .upload-label { font-size: 0.9rem; color: #475569; }
        .upload-label strong { color: var(--ciano); cursor: pointer; }
        .upload-hint { font-size: 0.78rem; color: #94a3b8; margin-top: 4px; }
        .upload-preview { margin-top: 12px; display: none; }
        .upload-preview img { max-height: 120px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .upload-preview .remover-preview {
            display: inline-block; margin-top: 6px; font-size: 0.8rem;
            color: #ef4444; cursor: pointer; text-decoration: underline;
        }

        /* Blocos internos */
        .bloco-interno {
            background: #f8fafc; border: 1.5px solid #e2e8f0;
            border-radius: 12px; padding: 20px; margin-bottom: 16px;
        }
        .bloco-interno h4 { font-size: 0.9rem; color: var(--azul); margin: 0 0 14px; font-weight: 500; }
        .bloco-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }

        .bloco-preview-wrap { grid-column: span 3; display: none; }
        .bloco-preview-wrap img { max-height: 80px; border-radius: 6px; border: 1px solid #e2e8f0; margin-top: 6px; }

        .contador { font-size: 0.78rem; color: #94a3b8; text-align: right; margin-top: 4px; }
        .contador.limite { color: #ef4444; }

        .acoes { display: flex; gap: 12px; align-items: center; margin-top: 8px; }
        .btn-salvar {
            background: var(--ciano); color: white; padding: 13px 32px;
            border: none; border-radius: 9px; font-size: 1rem; font-weight: 600;
            font-family: 'Inter', sans-serif; cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }
        .btn-salvar:hover  { background: #0099bb; }
        .btn-salvar:active { transform: scale(0.98); }
        .btn-voltar { color: #64748b; text-decoration: none; font-size: 0.9rem; }
        .btn-voltar:hover { color: var(--azul); }

        @media (max-width: 640px) {
            .form-grid { grid-template-columns: 1fr; }
            .bloco-grid { grid-template-columns: 1fr; }
            .span2, .bloco-preview-wrap { grid-column: span 1; }
            .card { padding: 24px; }
            .admin-header { padding: 14px 16px; }
        }
    </style>
</head>
<body>

<div class="admin-header">
    <strong>TechVidaReal — Cadastrar Artigo</strong>
    <div style="display:flex; gap:20px; align-items:center;">
        <span>Olá, <?= htmlspecialchars((string)($_SESSION['admin_nome'] ?? $_SESSION['admin_usuario']), ENT_QUOTES, 'UTF-8') ?></span>
        <a href="index.php">← Painel</a>
        <a href="logout.php">Sair</a>
    </div>
</div>

<div class="container">

    <?php if (!empty($mensagem)): ?>
        <div class="msg-<?= $tipo_msg === 'ok' ? 'ok' : 'erro' ?>">
            <?= $tipo_msg === 'ok' ? '✅' : '❌' ?>
            <span><?= $mensagem ?></span>
            <?php if ($tipo_msg === 'ok' && isset($id_novo)): ?>
                &nbsp;— <a href="../single.php?id=<?= (int)$id_novo ?>" target="_blank" style="color:#166534;">Ver artigo →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="form-artigo">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

        <!-- ── Card: Dados principais ── -->
        <div class="card">
            <h2>Cadastrar novo artigo</h2>
            <div class="form-grid">

                <div class="span2">
                    <label>Título <span class="obrigatorio">*</span></label>
                    <input type="text" name="titulo" id="titulo"
                           value="<?= htmlspecialchars((string)($titulo ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Digite o título do artigo..." maxlength="255" required>
                    <div class="contador" id="cont-titulo">0 / 255</div>
                </div>

                <div>
                    <label>Categoria <span class="obrigatorio">*</span></label>
                    <select name="categoria" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($categorias_validas as $cat): ?>
                            <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                                <?= ($categoria ?? '') === $cat ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Data de publicação <span class="obrigatorio">*</span></label>
                    <input type="date" name="data_publicacao"
                           value="<?= htmlspecialchars((string)($data_publicacao ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div>
                    <label>Tempo de leitura (min) <span class="obrigatorio">*</span></label>
                    <input type="number" name="tempo_leitura"
                           value="<?= (int)($tempo_leitura ?? 8) ?>" min="1" max="999" required>
                </div>

                <!-- Imagem de capa -->
                <div class="span2">
                    <label>Imagem de capa <span class="opcional">(opcional)</span></label>

                    <div class="capa-tabs">
                        <button type="button" class="capa-tab active" onclick="trocarAba('upload')">⬆️ Upload</button>
                        <button type="button" class="capa-tab" onclick="trocarAba('url')">🔗 URL externa</button>
                    </div>

                    <!-- Aba: Upload -->
                    <div id="aba-upload">
                        <div class="upload-area" id="drop-zone" onclick="document.getElementById('imagem_upload').click()">
                            <input type="file" name="imagem_upload" id="imagem_upload"
                                   accept="image/jpeg,image/png,image/webp,image/gif">
                            <div class="upload-icon">🖼️</div>
                            <div class="upload-label">
                                <strong>Clique para selecionar</strong> ou arraste a imagem aqui
                            </div>
                            <div class="upload-hint">JPG, PNG, WebP ou GIF — máx. 5 MB</div>
                        </div>
                        <div class="upload-preview" id="preview-upload">
                            <img id="preview-img" src="" alt="Prévia">
                            <br>
                            <span class="remover-preview" onclick="removerPreview()">✕ Remover imagem</span>
                        </div>
                    </div>

                    <!-- Aba: URL -->
                    <div id="aba-url" style="display:none;">
                        <input type="text" name="imagem_url" id="imagem_url"
                               value="<?= htmlspecialchars((string)($imagem_url ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="https://exemplo.com/imagem.jpg ou assets/imagens/foto.jpg"
                               maxlength="500">
                        <div id="preview-url-wrap" style="margin-top:10px; display:none;">
                            <img id="preview-url-img" src="" alt="Prévia"
                                 style="max-height:120px; border-radius:8px; border:1px solid #e2e8f0;">
                        </div>
                    </div>
                </div>

                <!-- Conteúdo -->
                <div class="span2">
                    <label>Conteúdo <span class="obrigatorio">*</span></label>
                    <textarea name="conteudo" id="conteudo" required><?= htmlspecialchars((string)($conteudo ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div class="contador" id="cont-conteudo">0 caracteres</div>
                </div>
            </div>
        </div>

        <!-- ── Card: Imagens internas ── -->
        <div class="card">
            <h3>🖼️ Imagens com link dentro do artigo <span style="font-weight:400; color:#94a3b8;">(opcional)</span></h3>
            <p style="font-size:0.85rem; color:#64748b; margin: -10px 0 20px;">
                Essas imagens aparecem no final do conteúdo do artigo, clicáveis com link externo em nova aba.
            </p>

            <!-- Bloco 1 -->
            <div class="bloco-interno">
                <h4>Bloco 1</h4>
                <div class="bloco-grid">
                    <div>
                        <label>URL da imagem</label>
                        <input type="text" name="img_interna_1" id="img_interna_1"
                               value="<?= htmlspecialchars((string)($img_interna_1 ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="https://... ou assets/..."
                               maxlength="500"
                               oninput="previewBloco('img_interna_1','prev_bloco_1')">
                    </div>
                    <div>
                        <label>Link de destino (URL)</label>
                        <input type="url" name="link_interno_1"
                               value="<?= htmlspecialchars((string)($link_interno_1 ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="https://siteexterno.com"
                               maxlength="500">
                    </div>
                    <div>
                        <label>Texto alternativo (alt)</label>
                        <input type="text" name="alt_interno_1"
                               value="<?= htmlspecialchars((string)($alt_interno_1 ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Descrição da imagem"
                               maxlength="255">
                    </div>
                    <div class="bloco-preview-wrap" id="prev_bloco_1">
                        <small style="color:#64748b;">Prévia:</small><br>
                        <img src="" alt="prévia">
                    </div>
                </div>
            </div>

            <!-- Bloco 2 -->
            <div class="bloco-interno">
                <h4>Bloco 2</h4>
                <div class="bloco-grid">
                    <div>
                        <label>URL da imagem</label>
                        <input type="text" name="img_interna_2" id="img_interna_2"
                               value="<?= htmlspecialchars((string)($img_interna_2 ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="https://... ou assets/..."
                               maxlength="500"
                               oninput="previewBloco('img_interna_2','prev_bloco_2')">
                    </div>
                    <div>
                        <label>Link de destino (URL)</label>
                        <input type="url" name="link_interno_2"
                               value="<?= htmlspecialchars((string)($link_interno_2 ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="https://siteexterno.com"
                               maxlength="500">
                    </div>
                    <div>
                        <label>Texto alternativo (alt)</label>
                        <input type="text" name="alt_interno_2"
                               value="<?= htmlspecialchars((string)($alt_interno_2 ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Descrição da imagem"
                               maxlength="255">
                    </div>
                    <div class="bloco-preview-wrap" id="prev_bloco_2">
                        <small style="color:#64748b;">Prévia:</small><br>
                        <img src="" alt="prévia">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Ações ── -->
        <div class="acoes" style="margin-bottom:40px;">
            <button type="submit" class="btn-salvar">Publicar artigo</button>
            <a href="index.php" class="btn-voltar">Cancelar</a>
        </div>

    </form>
</div>

<script>
// ── Abas capa ────────────────────────────────────────────────────────────────
function trocarAba(aba) {
    document.getElementById('aba-upload').style.display = aba === 'upload' ? 'block' : 'none';
    document.getElementById('aba-url').style.display    = aba === 'url'    ? 'block' : 'none';
    document.querySelectorAll('.capa-tab').forEach((t, i) => {
        t.classList.toggle('active', (i === 0 && aba === 'upload') || (i === 1 && aba === 'url'));
    });
    // Limpa o campo inativo para não enviar os dois
    if (aba === 'url') {
        document.getElementById('imagem_upload').value = '';
        removerPreview();
    } else {
        document.getElementById('imagem_url').value = '';
        document.getElementById('preview-url-wrap').style.display = 'none';
    }
}

// ── Preview upload ───────────────────────────────────────────────────────────
document.getElementById('imagem_upload').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('preview-img').src = e.target.result;
        document.getElementById('preview-upload').style.display = 'block';
    };
    reader.readAsDataURL(file);
});

function removerPreview() {
    document.getElementById('imagem_upload').value = '';
    document.getElementById('preview-img').src = '';
    document.getElementById('preview-upload').style.display = 'none';
}

// ── Drag and drop ────────────────────────────────────────────────────────────
const dropZone = document.getElementById('drop-zone');
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    const file = e.dataTransfer.files[0];
    if (!file || !file.type.startsWith('image/')) return;
    const input = document.getElementById('imagem_upload');
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    input.dispatchEvent(new Event('change'));
});

// ── Preview URL externa ──────────────────────────────────────────────────────
document.getElementById('imagem_url').addEventListener('input', function() {
    const url = this.value.trim();
    const wrap = document.getElementById('preview-url-wrap');
    const img  = document.getElementById('preview-url-img');
    if (url) { img.src = url; wrap.style.display = 'block'; }
    else      { wrap.style.display = 'none'; }
});

// ── Preview blocos internos ──────────────────────────────────────────────────
function previewBloco(inputId, previewId) {
    const url  = document.getElementById(inputId).value.trim();
    const wrap = document.getElementById(previewId);
    const img  = wrap.querySelector('img');
    if (url) { img.src = url; wrap.style.display = 'block'; }
    else      { wrap.style.display = 'none'; }
}

// Dispara preview se já tiver valor (ex: volta de erro de validação)
['img_interna_1','img_interna_2'].forEach(id => {
    const el = document.getElementById(id);
    if (el && el.value) previewBloco(id, id === 'img_interna_1' ? 'prev_bloco_1' : 'prev_bloco_2');
});

// ── Contadores ───────────────────────────────────────────────────────────────
const titulo  = document.getElementById('titulo');
const contTit = document.getElementById('cont-titulo');
titulo.addEventListener('input', function() {
    const n = this.value.length;
    contTit.textContent = n + ' / 255';
    contTit.className = 'contador' + (n >= 240 ? ' limite' : '');
});
contTit.textContent = titulo.value.length + ' / 255';

const conteudo = document.getElementById('conteudo');
const contCont = document.getElementById('cont-conteudo');
conteudo.addEventListener('input', function() {
    contCont.textContent = this.value.length.toLocaleString('pt-BR') + ' caracteres';
});
contCont.textContent = conteudo.value.length.toLocaleString('pt-BR') + ' caracteres';

// ── Aviso ao sair com alterações ─────────────────────────────────────────────
let alterado = false;
document.getElementById('form-artigo').addEventListener('input', () => alterado = true);
document.getElementById('form-artigo').addEventListener('submit', () => alterado = false);
window.addEventListener('beforeunload', e => {
    if (alterado) { e.preventDefault(); e.returnValue = ''; }
});
</script>

</body>
</html>
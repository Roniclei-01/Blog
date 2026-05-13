<?php
// db.php - Conexão com o banco de dados (Versão Recomendada)

$host     = 'localhost';
$dbname   = 'techvidareal_db';
$username = 'root';
$password = '';           // Deixe vazio no XAMPP local

// Criando a conexão
$conn = new mysqli($host, $username, $password, $dbname);

// Verifica se houve erro na conexão
if ($conn->connect_error) {
    error_log('DB connection error: ' . $conn->connect_error);
http_response_code(503);
die('Serviço temporariamente indisponível.');
}

// Define o charset para suportar acentuação corretamente
$conn->set_charset("utf8mb4");

?>
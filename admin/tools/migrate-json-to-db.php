<?php
declare(strict_types=1);

$root = dirname(__DIR__);

if (PHP_SAPI === 'cli') {
    global $config;
    $configPath = $root . '/config.local.php';
    if (!is_file($configPath)) {
        $configPath = $root . '/config.example.php';
    }
    $config = require $configPath;
    require $root . '/api/database.php';
} else {
    require $root . '/api/bootstrap.php';
}

if (!admin_use_database()) {
    fwrite(STDERR, "Ativa use_database no config ou CMS_USE_DATABASE=1\n");
    exit(1);
}

$pdo = admin_pdo();
if (!$pdo) {
    fwrite(STDERR, "Nao foi possivel ligar a base de dados.\n");
    exit(1);
}

$jsonPath = $root . '/content.json';
if (!is_file($jsonPath)) {
    fwrite(STDERR, "Ficheiro nao encontrado: admin/content.json\n");
    exit(1);
}

$doc = json_decode((string) file_get_contents($jsonPath), true);
if (!is_array($doc)) {
    fwrite(STDERR, "content.json invalido\n");
    exit(1);
}

if (!cms_save_document_to_db($doc, 'migrate')) {
    fwrite(STDERR, "Falha ao gravar na base de dados\n");
    exit(1);
}

fwrite(STDOUT, 'OK - ' . count($doc) . " chaves importadas para cms_content\n");

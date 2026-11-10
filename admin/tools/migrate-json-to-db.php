<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$projectRoot = dirname($root);

require $root . '/api/bootstrap.php';

if (!admin_use_database()) {
    fwrite(STDERR, "Ativa use_database no config ou CMS_USE_DATABASE=1\n");
    exit(1);
}

$pdo = admin_pdo();
if (!$pdo) {
    fwrite(STDERR, "Não foi possível ligar à base de dados.\n");
    exit(1);
}

$jsonPath = $root . '/content.json';
if (!is_file($jsonPath)) {
    fwrite(STDERR, "Ficheiro não encontrado: admin/content.json\n");
    exit(1);
}

$doc = json_decode((string) file_get_contents($jsonPath), true);
if (!is_array($doc)) {
    fwrite(STDERR, "content.json inválido\n");
    exit(1);
}

if (!cms_save_document_to_db($doc, 'migrate')) {
    fwrite(STDERR, "Falha ao gravar na base de dados\n");
    exit(1);
}

fwrite(STDOUT, "OK — " . count($doc) . " chaves importadas para cms_content\n");

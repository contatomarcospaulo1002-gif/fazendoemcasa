<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Media;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Projects\ProjectRepository;

/**
 * Armazenamento de mídia dentro do projeto.
 *
 * Escrita permitida APENAS dentro de `<projeto>/uploads/`. O nome no
 * disco é gerado pelo sistema (hash + extensão derivada do MIME real),
 * nunca do nome enviado — elimina path traversal e extensões forjadas.
 *
 * A pasta recebe um `.htaccess` que desativa execução de PHP, como
 * segunda barreira além da validação de conteúdo.
 */
final class MediaStorage
{
    private readonly UploadValidator $validator;

    public function __construct(
        private readonly ProjectRepository $projects,
        ?UploadValidator $validator = null,
    ) {
        $this->validator = $validator ?? new UploadValidator();
    }

    /**
     * @return array{ok: bool, error: ?string, media: array<string, mixed>|null}
     */
    public function store(int $projectId, string $tmpPath, string $originalName, ?int $userId = null, string $category = 'geral'): array
    {
        $project = $this->projects->find($projectId);
        if ($project === null) {
            return ['ok' => false, 'error' => 'Projeto não encontrado.', 'media' => null];
        }

        $check = $this->validator->validate($tmpPath, $originalName);
        if (!$check['ok']) {
            return ['ok' => false, 'error' => $check['error'], 'media' => null];
        }

        $dir = $this->uploadsDir($projectId);
        if ($dir === null) {
            return ['ok' => false, 'error' => 'Não foi possível preparar a pasta de uploads.', 'media' => null];
        }

        $checksum = (string) hash_file('sha256', $tmpPath);
        $name = substr($checksum, 0, 24)
            . '-' . substr(bin2hex(random_bytes(4)), 0, 8)
            . '.' . $check['extension'];

        $target = $dir . DIRECTORY_SEPARATOR . $name;

        // A pasta está confirmada dentro do projeto; o nome é gerado
        // pelo sistema, portanto não há caminho vindo do utilizador.
        if (!$this->isInsideProject($projectId, $target)) {
            return ['ok' => false, 'error' => 'Destino fora do projeto.', 'media' => null];
        }

        if (!@move_uploaded_file($tmpPath, $target) && !@rename($tmpPath, $target) && !@copy($tmpPath, $target)) {
            return ['ok' => false, 'error' => 'Falha ao guardar o ficheiro.', 'media' => null];
        }
        @chmod($target, 0644);

        $this->writeHtaccess($dir);

        $relPath = $this->relativePath($projectId, $target);
        if ($relPath === null) {
            @unlink($target);
            return ['ok' => false, 'error' => 'Falha ao calcular o caminho relativo.', 'media' => null];
        }

        $existing = Db::one(
            'SELECT id FROM cms_media WHERE project_id = :p AND checksum = :c LIMIT 1',
            ['p' => $projectId, 'c' => $checksum]
        );

        if ($existing !== null) {
            @unlink($target);
            $media = Db::one('SELECT * FROM cms_media WHERE id = :id', ['id' => (int) $existing['id']]);
            return ['ok' => true, 'error' => null, 'media' => $media];
        }

        Db::exec(
            'INSERT INTO cms_media
                (project_id, rel_path, original_name, mime, extension, size_bytes,
                 width, height, checksum, category, uploaded_by, created_at, updated_at)
             VALUES
                (:p, :rel, :orig, :mime, :ext, :size,
                 :w, :h, :sum, :cat, :u, NOW(), NOW())',
            [
                'p' => $projectId,
                'rel' => $relPath,
                'orig' => mb_substr($originalName, 0, 255),
                'mime' => $check['mime'],
                'ext' => $check['extension'],
                'size' => $check['size'],
                'w' => $check['width'],
                'h' => $check['height'],
                'sum' => $checksum,
                'cat' => mb_substr($category, 0, 60),
                'u' => $userId,
            ]
        );

        return [
            'ok' => true,
            'error' => null,
            'media' => Db::one('SELECT * FROM cms_media WHERE id = :id', ['id' => (int) Db::lastId()]),
        ];
    }

    /** Caminho absoluto da pasta de uploads, criada se necessário. */
    public function uploadsDir(int $projectId): ?string
    {
        $projectDir = $this->projects->resolveProjectDir($projectId);
        $dir = rtrim($projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads';

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $real = realpath($dir);
        $base = realpath($projectDir);
        if ($real === false || $base === false) {
            return null;
        }
        if (!str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    private function isInsideProject(int $projectId, string $target): bool
    {
        $projectDir = $this->projects->resolveProjectDir($projectId);
        $base = realpath($projectDir);
        $dir = realpath(dirname($target));

        if ($base === false || $dir === false) {
            return false;
        }
        return str_starts_with($dir . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR);
    }

    private function relativePath(int $projectId, string $target): ?string
    {
        $project = $this->projects->find($projectId);
        if ($project === null) {
            return null;
        }

        $projectDir = $this->projects->resolveProjectDir($projectId);
        $real = realpath($target);
        $base = realpath($projectDir);
        if ($real === false || $base === false) {
            return null;
        }

        $prefix = trim((string) $project['rel_path'], '/');
        $inside = str_replace('\\', '/', substr($real, strlen($base) + 1));

        return ($prefix !== '' ? $prefix . '/' : '') . $inside;
    }

    /**
     * Impede execução de código dentro da pasta de uploads.
     * Segunda barreira: mesmo um ficheiro malicioso que passasse não
     * seria executado.
     */
    private function writeHtaccess(string $dir): void
    {
        $path = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (is_file($path)) {
            return;
        }

        $rules = "# Biblioteca de mídia — sem execução de código\n"
            . "php_flag engine off\n"
            . "AddType text/plain .php .php3 .php4 .php5 .php7 .php8 .phtml .phar\n"
            . "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh)$\">\n"
            . "  Require all denied\n"
            . "</FilesMatch>\n"
            . "Options -ExecCGI -Indexes\n";

        @file_put_contents($path, $rules);
    }
}

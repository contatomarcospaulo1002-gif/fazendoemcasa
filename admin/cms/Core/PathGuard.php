<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Core;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Guard de caminhos — a barreira de isolamento entre projetos.
 *
 * Regras invioláveis:
 *  1. O caminho relativo vem SEMPRE do banco, nunca do request.
 *  2. Todo alvo é resolvido com realpath() antes da comparação.
 *  3. O alvo precisa começar com a raiz autorizada + separador.
 *  4. Segmentos '..', byte nulo, caminho absoluto e UNC são rejeitados.
 *  5. Symlink que escape da raiz é rejeitado (realpath resolve).
 */
final class PathGuard
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false) {
            throw new PathException('Raiz autorizada inexistente: ' . $root);
        }
        $this->root = rtrim($real, DIRECTORY_SEPARATOR);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Valida um caminho relativo (vindo do banco) e devolve o caminho
     * absoluto real. Garante que não escapa da raiz.
     */
    public function resolveDirectory(string $relative): string
    {
        $clean = $this->sanitizeRelative($relative);
        $abs = $this->root . DIRECTORY_SEPARATOR . $clean;
        $real = realpath($abs);

        if ($real === false) {
            throw new PathException('Diretório inexistente: ' . $relative);
        }
        if (!is_dir($real)) {
            throw new PathException('Não é um diretório: ' . $relative);
        }
        $this->assertInside($real, $relative);

        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    /**
     * Resolve um arquivo que DEVE existir.
     */
    public function resolveFile(string $relative): string
    {
        $clean = $this->sanitizeRelative($relative);
        $abs = $this->root . DIRECTORY_SEPARATOR . $clean;
        $real = realpath($abs);

        if ($real === false) {
            throw new PathException('Arquivo inexistente: ' . $relative);
        }
        if (!is_file($real)) {
            throw new PathException('Não é um arquivo: ' . $relative);
        }
        $this->assertInside($real, $relative);

        return $real;
    }

    /**
     * Resolve um caminho de destino para escrita, que pode ainda não existir.
     * Valida o diretório pai e o nome do arquivo.
     */
    public function resolveForWrite(string $relative): string
    {
        $clean = $this->sanitizeRelative($relative);
        $abs = $this->root . DIRECTORY_SEPARATOR . $clean;
        $dir = dirname($abs);

        $realDir = realpath($dir);
        if ($realDir === false) {
            throw new PathException('Diretório de destino inexistente: ' . dirname($relative));
        }
        $this->assertInside($realDir, $relative);

        $base = basename($abs);
        if ($base === '' || $base === '.' || $base === '..') {
            throw new PathException('Nome de arquivo inválido.');
        }

        return $realDir . DIRECTORY_SEPARATOR . $base;
    }

    /**
     * Normaliza e valida um caminho relativo em forma de string.
     * Rejeita: absolutos, UNC, byte nulo, '..' e segmentos vazios suspeitos.
     */
    public function sanitizeRelative(string $relative): string
    {
        // Byte nulo é sempre ataque.
        if (str_contains($relative, "\0")) {
            throw new PathException('Caminho contém byte nulo.');
        }

        $relative = trim($relative);
        if ($relative === '') {
            throw new PathException('Caminho vazio.');
        }

        // Normaliza separadores (Windows aceita ambos).
        $relative = str_replace('\\', '/', $relative);

        // Caminho absoluto (Unix ou Windows) e UNC.
        if (str_starts_with($relative, '/')) {
            throw new PathException('Caminho absoluto não permitido.');
        }
        if (preg_match('#^[A-Za-z]:#', $relative) === 1) {
            throw new PathException('Letra de unidade não permitida.');
        }
        if (str_starts_with($relative, '//')) {
            throw new PathException('Caminho UNC não permitido.');
        }

        // Decodifica %2e%2e etc. antes de checar traversal.
        $decoded = rawurldecode($relative);
        if ($decoded !== $relative) {
            throw new PathException('Caminho com escape de URL não permitido.');
        }

        $segments = explode('/', $relative);
        $out = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new PathException('Travessia de diretório não permitida.');
            }
            if (preg_match('/[\x00-\x1F<>:"|?*]/', $segment) === 1) {
                throw new PathException('Caractere inválido no caminho.');
            }
            $out[] = $segment;
        }

        if ($out === []) {
            throw new PathException('Caminho sem segmentos válidos.');
        }

        return implode('/', $out);
    }

    /**
     * O alvo resolvido precisa estar dentro da raiz.
     */
    private function assertInside(string $realPath, string $original): void
    {
        $prefix = $this->root . DIRECTORY_SEPARATOR;
        if ($realPath === $this->root) {
            return;
        }
        if (!str_starts_with($realPath, $prefix)) {
            throw new PathException(
                'Caminho escapa da raiz autorizada: ' . $original
            );
        }
    }

    /**
     * Verifica se dois caminhos são o mesmo projeto (usado para garantir
     * que uma operação não vaze para outro projeto).
     */
    public function sameTree(string $a, string $b): bool
    {
        $ra = $this->resolveDirectory($a);
        $rb = $this->resolveDirectory($b);
        if ($ra === $rb) {
            return true;
        }
        return str_starts_with($ra . DIRECTORY_SEPARATOR, $rb . DIRECTORY_SEPARATOR)
            || str_starts_with($rb . DIRECTORY_SEPARATOR, $ra . DIRECTORY_SEPARATOR);
    }
}

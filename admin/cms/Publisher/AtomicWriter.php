<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Publisher;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Escrita atómica.
 *
 * O conteúdo novo é escrito num ficheiro temporário ao lado do
 * destino, verificado, e só então movido por rename — que é atómico no
 * mesmo volume. Um corte de energia no meio da escrita deixa ou o
 * ficheiro antigo ou o novo completo, nunca um ficheiro pela metade.
 */
final class AtomicWriter
{
    /**
     * @param callable(string): bool|null $verify devolve true se o conteúdo novo é aceitável
     * @return array{ok: bool, error: ?string, hash: ?string}
     */
    public function write(string $absPath, string $content, ?callable $verify = null): array
    {
        $dir = dirname($absPath);
        if (!is_dir($dir)) {
            return ['ok' => false, 'error' => 'Diretório de destino não existe.', 'hash' => null];
        }

        if (is_file($absPath) && !is_writable($absPath) && !is_writable($dir)) {
            return ['ok' => false, 'error' => 'Destino sem permissão de escrita.', 'hash' => null];
        }

        $tmp = $dir . DIRECTORY_SEPARATOR . basename($absPath)
            . '.cms-tmp-' . bin2hex(random_bytes(6));

        $bytes = @file_put_contents($tmp, $content);
        if ($bytes === false) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Falha ao escrever o ficheiro temporário.', 'hash' => null];
        }

        // O temporário deve ter exatamente o conteúdo pretendido.
        if (hash_file('sha256', $tmp) !== hash('sha256', $content)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Verificação do temporário falhou.', 'hash' => null];
        }

        if ($verify !== null && !$verify($content)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Verificação de conteúdo rejeitou a escrita.', 'hash' => null];
        }

        // Limpa cache de stat para o rename refletir o estado real.
        clearstatcache(true, $tmp);
        if (!@rename($tmp, $absPath)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Falha ao substituir o destino.', 'hash' => null];
        }

        @chmod($absPath, 0644);
        clearstatcache(true, $absPath);

        $after = (string) @file_get_contents($absPath);
        if (hash('sha256', $after) !== hash('sha256', $content)) {
            return ['ok' => false, 'error' => 'Disco não reflete o conteúdo escrito.', 'hash' => null];
        }

        return ['ok' => true, 'error' => null, 'hash' => hash('sha256', $content)];
    }
}

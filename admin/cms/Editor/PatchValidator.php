<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Editor;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Um patch é um mapa `element_id => novo_valor`.
 *
 * Esta classe valida o patch ANTES de qualquer gravação:
 *   - o elemento existe e pertence à página/projeto;
 *   - o elemento é gravável (nível editable ou needs_adapter);
 *   - o valor respeita o tipo (texto, URL, imagem);
 *   - o valor não excede limites de tamanho.
 *
 * Um patch inválido é rejeitado inteiro — nunca aplicado em parte.
 */
final class PatchValidator
{
    /** Limite de caracteres por valor de texto. */
    public const MAX_TEXT = 20000;

    /** Limite de caracteres para URLs. */
    public const MAX_URL = 2000;

    /** Tipos que aceitam qualquer texto. */
    private const TEXT_TYPES = ['paragraph', 'title', 'subtitle', 'button', 'price', 'metadata'];

    /** Tipos que exigem URL/caminho válido. */
    private const URL_TYPES = ['image', 'logo', 'banner', 'background', 'link', 'video', 'icon'];

    /**
     * @param array<string, mixed> $patch
     * @param array<int, array<string, mixed>> $elements elementos da página, indexados por id
     * @return array{ok: bool, errors: list<string>, clean: array<int, string>}
     */
    public function validate(array $patch, array $elements): array
    {
        $errors = [];
        $clean = [];

        if ($patch === []) {
            return ['ok' => false, 'errors' => ['Patch vazio.'], 'clean' => []];
        }

        foreach ($patch as $rawId => $value) {
            $id = (int) $rawId;
            if ($id <= 0) {
                $errors[] = 'Identificador de elemento inválido.';
                continue;
            }

            $element = $elements[$id] ?? null;
            if ($element === null) {
                $errors[] = 'Elemento ' . $id . ' não pertence a esta página.';
                continue;
            }

            $level = (string) ($element['editability'] ?? '');
            if (!in_array($level, ['editable', 'needs_adapter'], true)) {
                $errors[] = 'Elemento ' . $id . ' não é editável (nível: ' . $level . ').';
                continue;
            }

            if (!is_scalar($value)) {
                $errors[] = 'Valor do elemento ' . $id . ' deve ser texto.';
                continue;
            }

            $text = (string) $value;
            $type = (string) ($element['type'] ?? 'paragraph');

            $typeError = $this->validateType($type, $text);
            if ($typeError !== null) {
                $errors[] = 'Elemento ' . $id . ': ' . $typeError;
                continue;
            }

            $clean[$id] = $text;
        }

        // Tudo ou nada: um patch com qualquer erro não devolve nada
        // para gravação. O chamador nunca precisa de revalidar.
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'clean' => []];
        }

        return [
            'ok' => true,
            'errors' => [],
            'clean' => $clean,
        ];
    }

    private function validateType(string $type, string $value): ?string
    {
        if (in_array($type, self::URL_TYPES, true)) {
            if (mb_strlen($value) > self::MAX_URL) {
                return 'URL demasiado longa (máx. ' . self::MAX_URL . ').';
            }
            if ($value === '') {
                return 'URL não pode ficar vazia.';
            }
            // Aceita caminho relativo ou URL absoluta http(s).
            if (preg_match('#^(https?://|/|\.\.?/)#i', $value) !== 1) {
                return 'URL deve ser um caminho relativo ou http(s).';
            }
            // Bloqueia esquemas perigosos mesmo em posição relativa.
            // Normaliza removendo espaços e controlos antes de comparar,
            // para que "java\tscript:" não escape ao filtro.
            $normalized = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $value));
            if (preg_match('/^(javascript|data|vbscript|file):/', $normalized) === 1) {
                return 'Esquema de URL não permitido.';
            }
            return null;
        }

        if (in_array($type, self::TEXT_TYPES, true)) {
            if (mb_strlen($value) > self::MAX_TEXT) {
                return 'Texto demasiado longo (máx. ' . self::MAX_TEXT . ').';
            }
            return null;
        }

        // html_component e tipos desconhecidos: limite conservador.
        if (mb_strlen($value) > self::MAX_TEXT) {
            return 'Conteúdo demasiado longo.';
        }
        return null;
    }
}

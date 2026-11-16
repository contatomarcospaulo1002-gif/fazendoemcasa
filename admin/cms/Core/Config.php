<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Core;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Configuração do CMS multi-projeto.
 *
 * Lê admin/config.local.php (não versionado) com fallback para
 * config.example.php. Nunca expõe segredos em resposta HTTP.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function load(): self
    {
        $path = CMS_ADMIN_ROOT . '/config.local.php';
        if (!is_file($path)) {
            $path = CMS_ADMIN_ROOT . '/config.example.php';
        }
        /** @var array<string, mixed> $data */
        $data = is_file($path) ? (require $path) : [];
        return new self(is_array($data) ? $data : []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->data[$key] ?? null;
        return $v === null ? $default : (bool) $v;
    }

    /**
     * Raiz autorizada dos projetos. Tudo fora daqui é inacessível.
     * Configurável por 'projects_root' no config; padrão: raiz do site.
     */
    public function projectsRoot(): string
    {
        $env = getenv('CMS_PROJECTS_ROOT');
        if (is_string($env) && $env !== '') {
            $real = realpath($env);
            if ($real === false) {
                throw new \RuntimeException('CMS_PROJECTS_ROOT inválido: ' . $env);
            }
            return $real;
        }
        $configured = (string) $this->get('projects_root', '');
        $candidate = $configured !== '' ? $configured : CMS_PROJECT_ROOT;
        $real = realpath($candidate);
        if ($real === false) {
            throw new \RuntimeException('Raiz de projetos inválida: ' . $candidate);
        }
        return $real;
    }

    /**
     * Pastas que o discovery nunca deve considerar como projeto.
     * @return list<string>
     */
    public function discoveryExclusions(): array
    {
        $default = [
            'admin', 'docs', 'scripts', 'docker', 'user', 'img', 'video',
            '.git', '.github', '.vscode', '.well-known', 'node_modules',
            'vendor', 'backups', 'tmp', 'cache',
        ];
        $configured = $this->get('discovery_exclusions', null);
        if (!is_array($configured)) {
            return $default;
        }
        $merged = array_merge($default, array_map('strval', $configured));
        return array_values(array_unique($merged));
    }

    /** @return array<string, mixed> */
    public function db(): array
    {
        $db = $this->get('db', []);
        return is_array($db) ? $db : [];
    }
}

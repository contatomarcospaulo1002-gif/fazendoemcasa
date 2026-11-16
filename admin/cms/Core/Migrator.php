<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Core;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Runner de migrations versionadas e reversíveis.
 *
 * Cada migration é um arquivo em admin/cms/Migrations/ com nome
 * YYYYMMDDHHMMSS_descricao.php, retornando um array com 'up' e 'down'.
 *
 * Regra: nenhuma migration altera tabelas pré-existentes
 * (cms_content, cms_revisions, cms_media, admin_users, member_users,
 * login_tentativas). Só cria tabelas novas com prefixo cms_.
 */
final class Migrator
{
    public function __construct(private readonly string $dir)
    {
    }

    public function ensureRegistry(): void
    {
        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(160) NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> */
    public function available(): array
    {
        $files = glob($this->dir . '/*.php') ?: [];
        $names = array_map(static fn (string $p): string => basename($p, '.php'), $files);
        sort($names);
        return array_values($names);
    }

    /** @return list<string> */
    public function applied(): array
    {
        $this->ensureRegistry();
        $rows = Db::all('SELECT migration FROM cms_migrations ORDER BY migration');
        return array_map(static fn (array $r): string => (string) $r['migration'], $rows);
    }

    /** @return list<string> */
    public function pending(): array
    {
        $applied = $this->applied();
        return array_values(array_filter(
            $this->available(),
            static fn (string $m): bool => !in_array($m, $applied, true)
        ));
    }

    /** @return list<string> nomes aplicados nesta execução */
    public function migrate(): array
    {
        $this->ensureRegistry();
        $done = [];
        foreach ($this->pending() as $name) {
            $migration = $this->load($name);
            ($migration['up'])();
            Db::exec('INSERT INTO cms_migrations (migration) VALUES (:m)', ['m' => $name]);
            $done[] = $name;
        }
        return $done;
    }

    /** @return list<string> nomes revertidos */
    public function rollback(int $steps = 1): array
    {
        $this->ensureRegistry();
        $applied = array_reverse($this->applied());
        $undone = [];
        foreach (array_slice($applied, 0, max(0, $steps)) as $name) {
            $migration = $this->load($name);
            ($migration['down'])();
            Db::exec('DELETE FROM cms_migrations WHERE migration = :m', ['m' => $name]);
            $undone[] = $name;
        }
        return $undone;
    }

    /** @return array{up: callable, down: callable} */
    private function load(string $name): array
    {
        $path = $this->dir . '/' . $name . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('Migration não encontrada: ' . $name);
        }
        $migration = require $path;
        if (!is_array($migration) || !isset($migration['up'], $migration['down'])) {
            throw new \RuntimeException('Migration inválida (falta up/down): ' . $name);
        }
        return $migration;
    }
}

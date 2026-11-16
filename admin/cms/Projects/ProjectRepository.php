<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Projects;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\PathException;
use FazendoEmCasa\Cms\Core\PathGuard;

/**
 * Repositório de projetos.
 *
 * O caminho real de um projeto vem SEMPRE do banco (rel_path), nunca do
 * request. Um project_id forjado só pode resultar em 404 ou no próprio
 * projeto do usuário — jamais em outro diretório.
 */
final class ProjectRepository implements ProjectLookup
{
    public function __construct(private readonly PathGuard $guard)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return Db::one('SELECT * FROM cms_projects WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return Db::one('SELECT * FROM cms_projects WHERE slug = :s LIMIT 1', ['s' => $slug]);
    }

    /** @return array<string, mixed>|null */
    public function findByPath(string $relPath): ?array
    {
        return Db::one('SELECT * FROM cms_projects WHERE rel_path = :p LIMIT 1', ['p' => $relPath]);
    }

    /**
     * @param array{status?:string} $filters
     * @return list<array<string, mixed>>
     */
    public function listAll(array $filters = []): array
    {
        $sql = 'SELECT * FROM cms_projects';
        $params = [];
        if (isset($filters['status'])) {
            $sql .= ' WHERE status = :status';
            $params['status'] = (string) $filters['status'];
        }
        $sql .= ' ORDER BY name ASC';
        return Db::all($sql, $params);
    }

    /**
     * Cria um projeto. O rel_path é validado contra a raiz autorizada
     * antes de qualquer gravação.
     *
     * @param array{slug:string,name:string,rel_path:string,description?:string,base_url?:string,discovery?:string} $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $slug = $this->normalizeSlug((string) ($data['slug'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $relPath = (string) ($data['rel_path'] ?? '');

        if ($slug === '') {
            throw new ProjectException('Slug inválido.');
        }
        if ($name === '') {
            throw new ProjectException('Nome é obrigatório.');
        }

        // Valida que o diretório existe e está dentro da raiz autorizada.
        $this->guard->resolveDirectory($relPath);
        $cleanRel = $this->guard->sanitizeRelative($relPath);

        if ($this->findBySlug($slug) !== null) {
            throw new ProjectException('Já existe um projeto com este slug.');
        }
        if ($this->findByPath($cleanRel) !== null) {
            throw new ProjectException('Já existe um projeto neste diretório.');
        }

        Db::exec(
            'INSERT INTO cms_projects (slug, name, description, rel_path, base_url, discovery)
             VALUES (:slug, :name, :desc, :path, :url, :disc)',
            [
                'slug' => $slug,
                'name' => $name,
                'desc' => (string) ($data['description'] ?? ''),
                'path' => $cleanRel,
                'url' => (string) ($data['base_url'] ?? ''),
                'disc' => in_array($data['discovery'] ?? 'manual', ['manual', 'discovered'], true)
                    ? (string) ($data['discovery'] ?? 'manual')
                    : 'manual',
            ]
        );

        $id = Db::lastId();
        $row = $this->find($id);
        if ($row === null) {
            throw new ProjectException('Falha ao criar projeto.');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $current = $this->find($id);
        if ($current === null) {
            throw new ProjectException('Projeto não encontrado.');
        }

        $fields = [];
        $params = ['id' => $id];

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new ProjectException('Nome não pode ficar vazio.');
            }
            $fields[] = 'name = :name';
            $params['name'] = $name;
        }
        if (isset($data['description'])) {
            $fields[] = 'description = :desc';
            $params['desc'] = (string) $data['description'];
        }
        if (isset($data['base_url'])) {
            $fields[] = 'base_url = :url';
            $params['url'] = (string) $data['base_url'];
        }
        if (isset($data['status'])) {
            $status = (string) $data['status'];
            if (!in_array($status, ['active', 'disabled'], true)) {
                throw new ProjectException('Status inválido.');
            }
            $fields[] = 'status = :status';
            $params['status'] = $status;
        }
        if (isset($data['rel_path'])) {
            $this->guard->resolveDirectory((string) $data['rel_path']);
            $clean = $this->guard->sanitizeRelative((string) $data['rel_path']);
            $other = $this->findByPath($clean);
            if ($other !== null && (int) $other['id'] !== $id) {
                throw new ProjectException('Outro projeto já usa este diretório.');
            }
            $fields[] = 'rel_path = :path';
            $params['path'] = $clean;
        }

        if ($fields === []) {
            return $current;
        }

        Db::exec('UPDATE cms_projects SET ' . implode(', ', $fields) . ' WHERE id = :id', $params);
        $row = $this->find($id);
        if ($row === null) {
            throw new ProjectException('Projeto desapareceu após atualização.');
        }
        return $row;
    }

    public function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new ProjectException('Status inválido.');
        }
        Db::exec('UPDATE cms_projects SET status = :s WHERE id = :id', ['s' => $status, 'id' => $id]);
    }

    public function touchScan(int $id): void
    {
        Db::exec('UPDATE cms_projects SET last_scan_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    /**
     * Resolve o diretório absoluto de um projeto, validando isolamento.
     * Este é o único caminho permitido para chegar ao disco.
     */
    public function resolveProjectDir(int $id): string
    {
        $project = $this->find($id);
        if ($project === null) {
            throw new ProjectException('Projeto não encontrado.');
        }
        if ((string) $project['status'] !== 'active') {
            throw new ProjectException('Projeto desativado.');
        }
        try {
            return $this->guard->resolveDirectory((string) $project['rel_path']);
        } catch (PathException $e) {
            throw new ProjectException('Diretório do projeto inválido: ' . $e->getMessage());
        }
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }
}

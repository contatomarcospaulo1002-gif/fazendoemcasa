<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\History;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Json;

/**
 * Registo de auditoria — append-only.
 *
 * Nenhum método de edição ou remoção de propósito. O histórico de
 * auditoria que pode ser alterado não é auditoria.
 */
final class AuditLog
{
    /**
     * @param array<string, mixed> $detail
     */
    public static function record(
        string $action,
        string $targetType,
        ?int $targetId,
        ?int $userId = null,
        ?int $projectId = null,
        array $detail = [],
    ): int {
        Db::exec(
            'INSERT INTO cms_audit_logs (user_id, project_id, action, target_type, target_id, detail_json, created_at)
             VALUES (:u, :p, :a, :tt, :ti, :d, NOW())',
            [
                'u' => $userId,
                'p' => $projectId,
                'a' => mb_substr($action, 0, 60),
                'tt' => mb_substr($targetType, 0, 30),
                'ti' => $targetId,
                'd' => $detail === [] ? null : Json::encode($detail),
            ]
        );
        return (int) Db::lastId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recent(?int $projectId = null, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        // EMULATE_PREPARES=false não aceita :p em IS NULL; a condição é
        // montada por branch, com valor sempre parametrizado.
        if ($projectId !== null) {
            $sql = 'SELECT a.*, u.nome AS user_name
                    FROM cms_audit_logs a
                    LEFT JOIN admin_users u ON u.id = a.user_id
                    WHERE a.project_id = :p
                    ORDER BY a.id DESC LIMIT ' . $limit;
            return Db::all($sql, ['p' => $projectId]);
        }

        $sql = 'SELECT a.*, u.nome AS user_name
                FROM cms_audit_logs a
                LEFT JOIN admin_users u ON u.id = a.user_id
                ORDER BY a.id DESC LIMIT ' . $limit;
        return Db::all($sql);
    }
}

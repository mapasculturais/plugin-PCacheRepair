<?php

namespace PCacheRepair;

use MapasCulturais\App;
use MapasCulturais\Entity;
use MapasCulturais\Entities\User;

/**
 * Encontra e repara as entidades de um usuário que não têm linha no pcache para ele
 */
class Repairer
{
    /**
     * Tipos de entidade verificados, na ordem de reparo, com a condição que
     * seleciona as entidades que pertencem ao usuário (:user_id)
     */
    const ENTITY_TYPES = [
        'Agent' => 'e.user_id = :user_id',
        'Registration' => 'e.agent_id IN (SELECT id FROM agent WHERE user_id = :user_id)',
        'Opportunity' => 'e.agent_id IN (SELECT id FROM agent WHERE user_id = :user_id)',
        'Space' => 'e.agent_id IN (SELECT id FROM agent WHERE user_id = :user_id)',
        'Project' => 'e.agent_id IN (SELECT id FROM agent WHERE user_id = :user_id)',
        'Event' => 'e.agent_id IN (SELECT id FROM agent WHERE user_id = :user_id)',
    ];

    /**
     * Retorna os ids das entidades do tipo que pertencem ao usuário e não têm
     * linha no pcache para ele, ordenados por id, a partir de $after_id
     */
    static function findMissing(int $user_id, string $type, int $after_id, int $limit): array
    {
        $app = App::i();
        $class = "MapasCulturais\\Entities\\{$type}";
        $metadata = $app->em->getClassMetadata($class);
        $table = $metadata->getTableName();
        // o pcache guarda a classe raiz (ex: Opportunity para AgentOpportunity)
        $object_type = $metadata->rootEntityName ?: $class;
        $owned_condition = self::ENTITY_TYPES[$type];
        $limit = max(1, $limit);

        $ids = $app->em->getConnection()->fetchColumn("
            SELECT e.id
            FROM {$table} e
            WHERE
                {$owned_condition} AND
                e.id > :after_id AND
                NOT EXISTS (
                    SELECT 1
                    FROM pcache p
                    WHERE
                        p.object_type = CAST(:object_type AS object_type) AND
                        p.object_id = e.id AND
                        p.user_id = :user_id
                )
            ORDER BY e.id ASC
            LIMIT {$limit}", [
            'user_id' => $user_id,
            'after_id' => $after_id,
            'object_type' => $object_type,
        ]);

        return array_map('intval', $ids);
    }

    static function hasMissing(int $user_id): bool
    {
        foreach (array_keys(self::ENTITY_TYPES) as $type) {
            if (self::findMissing($user_id, $type, 0, 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Repara na hora até $limit agentes do usuário
     *
     * @return int quantidade de agentes reparados
     */
    static function repairAgentsNow(User $user, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $app = App::i();
        $repaired = 0;

        foreach (self::findMissing($user->id, 'Agent', 0, $limit) as $agent_id) {
            if ($agent = $app->repo('Agent')->find($agent_id)) {
                self::repairEntity($user, $agent);
                $repaired++;
            }
        }

        return $repaired;
    }

    /**
     * Cria as linhas do pcache da entidade apenas para o usuário.
     *
     * Não apaga linhas existentes e não dispara cascata para entidades relacionadas.
     */
    static function repairEntity(User $user, Entity $entity)
    {
        $app = App::i();

        // com o controle de acesso desligado o canUser() retorna true para
        // qualquer ação, o que daria ao usuário permissões que ele não tem
        $disabled_count = 0;
        while (!$app->isAccessControlEnabled()) {
            $app->enableAccessControl();
            $disabled_count++;
        }

        try {
            $entity->createPermissionsCacheForUsers([$user], false, false);
        } finally {
            for ($i = 0; $i < $disabled_count; $i++) {
                $app->disableAccessControl();
            }
        }
    }
}

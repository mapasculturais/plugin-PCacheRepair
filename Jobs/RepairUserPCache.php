<?php

namespace PCacheRepair\Jobs;

use MapasCulturais\App;
use MapasCulturais\Definitions\JobType;
use MapasCulturais\Entities\Job;
use PCacheRepair\Plugin;
use PCacheRepair\Repairer;

/**
 * Repara um lote do pcache de um usuário e enfileira o próximo lote.
 *
 * Cada lote tem um id de job próprio (usuário + número do lote), porque um
 * job de uma iteração só é apagado logo depois de executar.
 */
class RepairUserPCache extends JobType
{
    const SLUG = 'RepairUserPCache';

    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
    {
        return "{$data['targetUserId']}:{$data['batch']}";
    }

    protected function _execute(Job $job)
    {
        $app = App::i();
        $plugin = Plugin::$instance;
        $config = $plugin->config;

        $user = $job->user;
        if (!$user) {
            return true;
        }

        $batch = (int) $job->batch;
        $type_index = (int) $job->typeIndex;
        $after_id = (int) $job->afterId;
        $delay = max(1, (int) $config['batchDelaySeconds']);

        // a vaga ainda está ocupada (a estimativa da senha errou): volta para o
        // fim da fila, assim a espera cresce junto com o tamanho da fila
        if (Plugin::countRunningJobs($job->id) >= max(1, (int) $config['maxConcurrentJobs'])) {
            Plugin::enqueueBatch($user, $batch + 1, $type_index, $after_id, $delay);
            $plugin->log("user {$user->id}: batch {$batch} postponed, all slots busy");
            return true;
        }

        $types = array_keys(Repairer::ENTITY_TYPES);
        $batch_size = max(1, (int) $config['batchSize']);
        $max_seconds = max(1, (int) $config['maxSecondsPerBatch']);
        $start_time = microtime(true);
        $processed = 0;
        $errors = 0;

        while ($type_index < count($types) && $processed < $batch_size && (microtime(true) - $start_time) < $max_seconds) {
            $type = $types[$type_index];
            $ids = Repairer::findMissing($user->id, $type, $after_id, $batch_size - $processed);

            if (!$ids) {
                $type_index++;
                $after_id = 0;
                continue;
            }

            foreach ($ids as $id) {
                // o cursor sempre avança, então uma entidade que continuar sem
                // pcache depois do reparo não é processada de novo nesta sequência
                $after_id = $id;
                $processed++;

                try {
                    if ($entity = $app->repo($type)->find($id)) {
                        Repairer::repairEntity($user, $entity);
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $app->log->error("PCacheRepair: user {$user->id}: {$type}:{$id}: {$e->getMessage()}");
                }

                if ((microtime(true) - $start_time) >= $max_seconds) {
                    break;
                }
            }
        }

        $finished = $type_index >= count($types);
        $seconds = number_format(microtime(true) - $start_time, 2);
        $status = $finished ? 'finished' : 'continues';
        $plugin->log("user {$user->id}: batch {$batch} repaired {$processed} entities ({$errors} errors) in {$seconds}s, {$status}");

        if (!$finished) {
            Plugin::enqueueBatch($user, $batch + 1, $type_index, $after_id, $delay);
        }

        return true;
    }
}

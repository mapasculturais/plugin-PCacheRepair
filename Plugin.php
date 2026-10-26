<?php

namespace PCacheRepair;

use MapasCulturais\App;
use MapasCulturais\Entities\User;

/**
 * Repara o cache de permissão (pcache) do usuário logado.
 *
 * Na primeira requisição autenticada de cada sessão, verifica se o usuário
 * perdeu linhas do pcache de entidades que são dele. Os agentes do usuário
 * são reparados na hora (limitado por syncAgentsLimit), para que ele consiga
 * se inscrever em oportunidades na mesma sessão. O restante é reparado em
 * segundo plano pelo job RepairUserPCache, em lotes pequenos.
 */
class Plugin extends \MapasCulturais\Plugin
{
    const SESSION_KEY = 'pcacheRepair.checkedUserId';

    // chave das travas do Postgres (pg_advisory_lock) usadas pelo plugin. A segunda
    // chave é o id do usuário, ou 0 para a trava da fila (ids de usuário começam em 1)
    const LOCK_KEY = 7273;
    const QUEUE_LOCK_ID = 0;

    /** @var Plugin */
    public static $instance;

    function __construct(array $config = [])
    {
        $config += [
            // a variável de ambiente serve apenas para desligar o plugin
            'enabled' => env('PCACHE_REPAIR_ENABLED', true),

            // máximo de agentes reparados na hora, durante a requisição
            'syncAgentsLimit' => (int) env('PCACHE_REPAIR_SYNC_AGENTS_LIMIT', 5),

            // máximo de entidades reparadas por execução do job
            'batchSize' => (int) env('PCACHE_REPAIR_BATCH_SIZE', 50),

            // tempo máximo de cada execução do job
            'maxSecondsPerBatch' => (int) env('PCACHE_REPAIR_MAX_SECONDS_PER_BATCH', 5),

            // espera mínima entre dois lotes do mesmo usuário
            'batchDelaySeconds' => (int) env('PCACHE_REPAIR_BATCH_DELAY_SECONDS', 10),

            // máximo de jobs de reparo rodando ao mesmo tempo (somando todos os usuários)
            'maxConcurrentJobs' => (int) env('PCACHE_REPAIR_MAX_CONCURRENT_JOBS', 1),

            // job marcado como "processando" há mais tempo que isso é considerado morto
            'staleJobMinutes' => (int) env('PCACHE_REPAIR_STALE_JOB_MINUTES', 10),

            'log' => env('PCACHE_REPAIR_LOG', false),
        ];

        parent::__construct($config);

        self::$instance = $this;
    }

    function _init()
    {
        if (!$this->config['enabled']) {
            return;
        }

        $app = App::i();
        $plugin = $this;

        // um novo login verifica o usuário de novo, mesmo na mesma sessão
        $app->hook('auth.successful', function () {
            unset($_SESSION[Plugin::SESSION_KEY]);
        });

        $app->hook('mapasculturais.run:before', function () use ($plugin) {
            $plugin->checkCurrentUser();
        });
    }

    function register()
    {
        $app = App::i();

        // registrado mesmo com o plugin desligado, para os jobs já enfileirados terminarem
        $app->registerJobType(new Jobs\RepairUserPCache(Jobs\RepairUserPCache::SLUG));
    }

    /**
     * Executa uma vez por sessão para cada usuário autenticado
     */
    function checkCurrentUser()
    {
        // sem sessão (cli, token de api) não há como executar uma vez só
        if (php_sapi_name() === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $app = App::i();
        $user = $app->user;

        if ($user->is('guest')) {
            return;
        }

        if (($_SESSION[self::SESSION_KEY] ?? null) === $user->id) {
            return;
        }

        // marcado antes do reparo para que uma falha não se repita a cada requisição
        $_SESSION[self::SESSION_KEY] = $user->id;

        $this->repairUser($user);
    }

    /**
     * Repara na hora os agentes do usuário e enfileira o reparo do restante
     */
    function repairUser(User $user)
    {
        $app = App::i();

        // admins nunca têm linhas no pcache (ver createPermissionsCacheForUsers)
        if ($user->is('admin')) {
            return;
        }

        // logo após o login o navegador dispara várias requisições ao mesmo tempo e
        // todas chegam antes da marca na sessão ser gravada. Só uma delas verifica
        if (!self::tryLock($user->id)) {
            return;
        }

        try {
            $repaired = Repairer::repairAgentsNow($user, $this->config['syncAgentsLimit']);

            if ($repaired) {
                $this->log("user {$user->id}: {$repaired} agents repaired synchronously");
            }

            if (Repairer::hasMissing($user->id) && !self::hasActiveJob($user->id)) {
                $delay = self::enqueueBatch($user, 0, 0, 0, 0);
                $this->log("user {$user->id}: background repair enqueued, starts in {$delay}s");
            }
        } catch (\Throwable $e) {
            $app->log->error("PCacheRepair: user {$user->id}: {$e->getMessage()}");
        } finally {
            self::unlock($user->id);
        }
    }

    /**
     * Enfileira um lote do job de reparo do usuário no próximo horário livre
     * da fila (ver nextSlotDelay)
     *
     * @param int $min_delay_seconds espera mínima, mesmo com a fila vazia
     * @return int em quantos segundos o lote vai começar
     */
    static function enqueueBatch(User $user, int $batch, int $type_index, int $after_id, int $min_delay_seconds): int
    {
        $app = App::i();

        $data = [
            'targetUserId' => $user->id,
            'batch' => $batch,
            'typeIndex' => $type_index,
            'afterId' => $after_id,
        ];

        // trava a fila enquanto calcula o horário e grava o job, para que dois
        // enfileiramentos simultâneos não recebam o mesmo horário
        $conn = $app->em->getConnection();
        $conn->fetchScalar("SELECT pg_advisory_lock(:key, :id)", ['key' => self::LOCK_KEY, 'id' => self::QUEUE_LOCK_ID]);

        try {
            $delay_seconds = self::nextSlotDelay($min_delay_seconds);
            $app->enqueueJob(Jobs\RepairUserPCache::SLUG, $data, "+{$delay_seconds} seconds", '', 1, false, $user);
        } finally {
            self::unlock(self::QUEUE_LOCK_ID);
        }

        return $delay_seconds;
    }

    /**
     * Tenta pegar a trava do id informado, sem esperar
     */
    static function tryLock(int $id): bool
    {
        $conn = App::i()->em->getConnection();

        return (bool) $conn->fetchScalar("SELECT pg_try_advisory_lock(:key, :id)", ['key' => self::LOCK_KEY, 'id' => $id]);
    }

    static function unlock(int $id)
    {
        $conn = App::i()->em->getConnection();
        $conn->fetchScalar("SELECT pg_advisory_unlock(:key, :id)", ['key' => self::LOCK_KEY, 'id' => $id]);
    }

    /**
     * Calcula em quantos segundos o próximo lote deve começar.
     *
     * Funciona como uma senha de atendimento: o lote é marcado para depois do
     * último lote já agendado, com um intervalo de maxSecondsPerBatch dividido
     * por maxConcurrentJobs. Assim os lotes ficam espaçados no tempo e cada um
     * roda na sua vez, sem ficar acordando para conferir se a vaga abriu.
     */
    static function nextSlotDelay(int $min_delay_seconds): int
    {
        $app = App::i();
        $config = self::$instance->config;

        $slot_seconds = max(1, (int) $config['maxSecondsPerBatch']) / max(1, (int) $config['maxConcurrentJobs']);

        $last_scheduled = $app->em->getConnection()->fetchScalar("
            SELECT MAX(next_execution_timestamp)
            FROM job
            WHERE
                name = :slug AND
                status = 0", [
            'slug' => Jobs\RepairUserPCache::SLUG,
        ]);

        $delay = $min_delay_seconds;

        // os horários dos jobs são gravados e comparados pelo PHP (ver App::executeJob),
        // por isso a conta é feita aqui e não com o now() do banco
        if ($last_scheduled) {
            $last_timestamp = (new \DateTime($last_scheduled))->getTimestamp();
            $delay = max($delay, $last_timestamp - time() + $slot_seconds);
        }

        return (int) ceil(max(0, $delay));
    }

    /**
     * Verifica se o usuário já tem um job de reparo aguardando ou rodando
     */
    static function hasActiveJob(int $user_id): bool
    {
        $app = App::i();

        $exists = $app->em->getConnection()->fetchScalar("
            SELECT 1
            FROM job
            WHERE
                name = :slug AND
                user_id = :user_id AND
                (status = 0 OR next_execution_timestamp > :stale_limit)
            LIMIT 1", [
            'slug' => Jobs\RepairUserPCache::SLUG,
            'user_id' => $user_id,
            'stale_limit' => self::staleLimit(),
        ]);

        return (bool) $exists;
    }

    /**
     * Conta os jobs de reparo rodando agora, exceto o informado
     */
    static function countRunningJobs(string $except_job_id): int
    {
        $app = App::i();

        return (int) $app->em->getConnection()->fetchScalar("
            SELECT COUNT(*)
            FROM job
            WHERE
                name = :slug AND
                status = 1 AND
                id <> :job_id AND
                next_execution_timestamp > :stale_limit", [
            'slug' => Jobs\RepairUserPCache::SLUG,
            'job_id' => $except_job_id,
            'stale_limit' => self::staleLimit(),
        ]);
    }

    /**
     * Horário antes do qual um job marcado como "processando" é considerado morto
     */
    static function staleLimit(): string
    {
        $stale_minutes = max(1, (int) self::$instance->config['staleJobMinutes']);

        return date('Y-m-d H:i:s', time() - $stale_minutes * 60);
    }

    function log(string $message)
    {
        if ($this->config['log']) {
            App::i()->log->info("PCacheRepair: {$message}");
        }
    }
}

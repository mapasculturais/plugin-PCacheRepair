# PCacheRepair

Recria o cache de permissão (pcache) do usuário logado quando ele está faltando.

## Por que existe

O Mapas guarda numa tabela, a `pcache`, quem pode fazer o quê em cada entidade. Se essa tabela perde linhas (por exemplo, uma reconstrução completa interrompida no meio), o sistema deixa de reconhecer que a pessoa é dona dos próprios cadastros.

O sintoma mais visível: o botão "Fazer inscrição" responde "selecione o agente responsável", porque a lista de agentes da pessoa volta vazia.

O plugin conserta isso sozinho, conforme as pessoas entram no Mapas, sem pesar no servidor.

## Requisitos

- Mapas Culturais 7.8.x (testado na 7.8.20).
- PostgreSQL. O plugin usa as travas do Postgres (`pg_advisory_lock`).
- O executor de jobs ligado (`jobs-cron.sh`, que já sobe no container padrão do Mapas). Sem ele, os agentes continuam sendo reparados no login, mas o resto (espaços, projetos, eventos e afins) nunca é reparado.

## Instalação passo a passo

### 1. Colocar o código do plugin na instalação

A pasta do plugin precisa ficar em `src/plugins/PCacheRepair`. **O nome da pasta precisa ser exatamente `PCacheRepair`**, porque é o nome usado para carregar as classes.

**Em produção (imagem Docker da instalação):** inclua o plugin no build da imagem. Escolha uma das formas:

- copiando a pasta no `Dockerfile`:
  ```dockerfile
  COPY plugins/plugin-PCacheRepair /var/www/src/plugins/PCacheRepair
  ```
- ou como submódulo git do repositório da instalação:
  ```bash
  git submodule add <url-do-repositorio-do-plugin> src/plugins/PCacheRepair
  ```

**Em desenvolvimento ou homologação (docker compose):** monte a pasta como volume no serviço do Mapas, trocando `<caminho-do-plugin>` pelo lugar onde você clonou o plugin:

```yaml
services:
  mapas:
    volumes:
      - <caminho-do-plugin>:/var/www/src/plugins/PCacheRepair
```

### 2. Habilitar o plugin

Adicione `PCacheRepair` à lista de plugins, no `config/plugins.php` ou num arquivo em `config/config.d/`:

```php
<?php

return [
    'plugins' => [
        // ... outros plugins da instalação
        'PCacheRepair',
    ],
];
```

### 3. Reiniciar a aplicação

- **Produção:** publique a imagem nova e faça o deploy normalmente.
- **Docker compose:** recrie o container do Mapas (no ambiente `dev/`, `./start.sh`). Só reiniciar não basta quando um volume novo foi adicionado.

Não há banco para atualizar: o plugin não cria tabela nem coluna.

### 4. Conferir se o plugin carregou

Abra o console do Mapas dentro do container:

```bash
./scripts/shell.sh
```

E rode:

```php
isset($app->plugins['PCacheRepair']);                      // deve responder true
(bool) $app->getRegisteredJobType('RepairUserPCache');     // deve responder true
```

### 5. Conferir se está reparando

Com o log ligado (`PCACHE_REPAIR_LOG=true`, veja a configuração abaixo), quando um usuário com pcache faltando entra no Mapas aparecem linhas como:

```
PCacheRepair: user 58085: 1 agents repaired synchronously
PCacheRepair: user 58085: background repair enqueued, starts in 0s
```

Essas duas saem no log da aplicação web. As linhas dos lotes em segundo plano (`batch 0 repaired 15 entities ...`) saem na saída do executor de jobs, que no container padrão fica em `/nohup.out`.

## Configuração

Todas as opções têm um valor padrão seguro. Na maioria das instalações, não é preciso configurar nada.

### Por variável de ambiente

| Variável | Padrão | Para quê |
|---|---|---|
| `PCACHE_REPAIR_ENABLED` | `true` | `false` desliga a verificação. O tipo de job continua registrado, para os jobs que já estão na fila terminarem |
| `PCACHE_REPAIR_SYNC_AGENTS_LIMIT` | `5` | quantos agentes são reparados na hora, durante a requisição. `0` manda tudo para segundo plano |
| `PCACHE_REPAIR_BATCH_SIZE` | `50` | quantas entidades cada execução do job repara, no máximo |
| `PCACHE_REPAIR_MAX_SECONDS_PER_BATCH` | `5` | tempo máximo de cada execução. Também é o espaço reservado para cada lote na fila |
| `PCACHE_REPAIR_BATCH_DELAY_SECONDS` | `10` | espera mínima entre dois lotes do mesmo usuário, quando a fila está vazia |
| `PCACHE_REPAIR_MAX_CONCURRENT_JOBS` | `1` | quantos reparos podem rodar ao mesmo tempo, somando todos os usuários |
| `PCACHE_REPAIR_STALE_JOB_MINUTES` | `10` | um job "processando" há mais tempo que isso é considerado morto |
| `PCACHE_REPAIR_LOG` | `false` | registra cada reparo no log, com o prefixo `PCacheRepair:` |

### Pelo arquivo de configuração

As mesmas opções podem ser definidas no `config/plugins.php` (ou em `config/config.d/`). Nesse formato, **o `namespace` é obrigatório**:

```php
<?php

return [
    'plugins' => [
        'PCacheRepair' => [
            'namespace' => 'PCacheRepair',
            'config' => [
                'syncAgentsLimit' => 5,
                'batchSize' => 50,
                'maxSecondsPerBatch' => 5,
                'batchDelaySeconds' => 10,
                'maxConcurrentJobs' => 1,
                'staleJobMinutes' => 10,
                'log' => true,
            ],
        ],
    ],
];
```

Quando a mesma opção aparece nos dois lugares, **vale o arquivo de configuração**. A variável de ambiente só é usada para o que o arquivo não define.

### Recomendações

- **Primeiros dias em produção:** ligue `PCACHE_REPAIR_LOG=true` para acompanhar os reparos. Desligue depois que a fila estabilizar.
- **Mantenha `PCACHE_REPAIR_MAX_CONCURRENT_JOBS=1`.** O executor de jobs do Mapas também cuida de e-mails e mudanças de fase dos editais. Nunca use um valor maior que o `NUM_PROCESSES` do container.
- **O ritmo real também depende do executor de jobs.** Ele inicia no máximo 1 job a cada `JOBS_INTERVAL` segundos (padrão 1). Com os padrões do plugin, cada usuário ocupa um espaço de 5 segundos na fila: 600 usuários esperando levam cerca de 50 minutos para esvaziar. Nos testes, cada lote levou menos de 1 segundo. Para esvaziar mais rápido, reduza `PCACHE_REPAIR_MAX_SECONDS_PER_BATCH` (por exemplo para 2).
- **Para ir mais devagar,** aumente `PCACHE_REPAIR_MAX_SECONDS_PER_BATCH` e diminua `PCACHE_REPAIR_BATCH_SIZE`.

## Acompanhamento

Fila de reparos (quantos estão esperando e até quando vai):

```sql
SELECT count(*) AS lotes, min(next_execution_timestamp) AS proximo, max(next_execution_timestamp) AS ultimo
FROM job
WHERE name = 'RepairUserPCache';
```

Usuários ativos cujo agente de perfil ainda está sem pcache (deve cair conforme as pessoas entram):

```sql
SELECT count(*)
FROM usr u
JOIN agent a ON a.id = u.profile_id
WHERE u.status = 1
  AND a.status = 1
  AND NOT EXISTS (
    SELECT 1 FROM pcache p
    WHERE p.user_id = u.id
      AND p.object_type = 'MapasCulturais\Entities\Agent'
      AND p.object_id = u.profile_id
      AND p.action = '@control'
  );
```

Esse número nunca chega a zero: admins não têm pcache por regra do Mapas, e quem não entrar no sistema não é reparado.

## Como desligar ou remover

- **Desligar sem deploy:** defina `PCACHE_REPAIR_ENABLED=false` e reinicie o container. Os lotes que já estavam na fila terminam normalmente.
- **Remover de vez:**
  1. desligue com `PCACHE_REPAIR_ENABLED=false` e espere a fila esvaziar (consulta acima), **ou** apague os lotes pendentes:
     ```sql
     DELETE FROM job WHERE name = 'RepairUserPCache';
     ```
  2. tire `PCacheRepair` da lista de plugins e remova a pasta.

  Não pule o passo 1. Um job que sobrar na fila sem o plugin instalado gera erro no executor de jobs.

## Como funciona

1. Na primeira requisição autenticada de cada sessão, e de novo após cada login, o plugin verifica se o usuário tem entidades sem pcache para ele. Cobre os agentes do usuário e as inscrições, oportunidades, espaços, projetos e eventos desses agentes.
2. Os agentes são recriados na hora, até `syncAgentsLimit`. Assim a pessoa já consegue se inscrever na mesma sessão.
3. O resto vai para o job `RepairUserPCache`. Cada execução repara no máximo `batchSize` entidades, ou para quando passa de `maxSecondsPerBatch` segundos.
4. **Senha de atendimento:** cada lote é agendado para depois do último lote que já está na fila, com um intervalo de `maxSecondsPerBatch / maxConcurrentJobs` segundos. Os lotes ficam espaçados e cada um roda na sua vez, sem ficar acordando para conferir se a vaga abriu. Com 10 ou com 600 usuários esperando, o esforço do servidor por minuto é o mesmo. Só muda quanto tempo a fila leva para esvaziar.
5. O próximo lote de um mesmo usuário também vai para o fim da fila. Assim um usuário com muitas entidades não segura os outros.
6. Se, na hora marcada, já houver `maxConcurrentJobs` reparos rodando (a estimativa errou), o lote volta para o fim da fila.
7. **Travas contra duplicidade:** logo após o login, o navegador faz várias requisições ao mesmo tempo. Uma trava do Postgres por usuário garante que só uma delas faça a verificação. Outra trava, curta, garante que dois logins simultâneos não recebam o mesmo horário na fila.

O reparo usa `createPermissionsCacheForUsers([$user], false, false)`, a mesma função que o Mapas usa para montar o pcache. Ela só grava as linhas daquele usuário, não apaga nada e não dispara reparo em cascata para entidades relacionadas.

## Limitações

O plugin **não** repara:

- admins, que por regra do Mapas não têm pcache;
- usuários sem sessão (scripts de linha de comando, acesso por token de API);
- usuários que nunca mais entrarem no Mapas;
- permissões que não vêm de ser dono: controle por relação com entidades de outras pessoas, acesso de avaliador, notificações e o cadastro do próprio usuário (`User`).

Para recriar o pcache da instalação inteira de uma vez, **não use** `scripts/recreate-pcache.sh` em produção: ele apaga o cache de todo mundo antes de reconstruir, e se for interrompido deixa os usuários sem permissão.

## Teste local (ambiente dev)

Os comandos abaixo rodam na pasta `dev/` do Mapas, com o plugin montado e habilitado (passos 1 e 2) e `PCACHE_REPAIR_LOG=true` no `docker-compose.yml`.

```bash
cd dev && ./start.sh
```

1. Escolha um usuário de teste e apague o pcache dele **no banco local**:
   ```bash
   docker compose exec db psql -U mapas mapas -c "DELETE FROM pcache WHERE user_id = <ID>;"
   ```
2. Entre como esse usuário (autenticação Fake do dev): `http://localhost/autenticacao/fakeLogin/?fake_authentication_user_id=<ID>`. A verificação acontece na requisição seguinte ao login, por exemplo ao abrir o painel.
3. Confira que os agentes voltaram na hora:
   ```bash
   docker compose exec db psql -U mapas mapas -c "SELECT object_type, count(*) FROM pcache WHERE user_id = <ID> GROUP BY 1;"
   ```
4. Confira os lotes na fila e o horário marcado de cada um (a "senha"):
   ```bash
   docker compose exec db psql -U mapas mapas -c "SELECT id, status, next_execution_timestamp, metadata FROM job WHERE name = 'RepairUserPCache';"
   ```
5. Confira o sintoma original. Logado como o usuário, abra `http://localhost/api/agent/find/?@select=id,name&@permissions=@control&type=EQ(1)`. A resposta deve trazer o agente dele, e não `[]`.

Outros testes úteis:

- **Só o caminho em segundo plano:** suba com `PCACHE_REPAIR_SYNC_AGENTS_LIMIT=0`.
- **Fila espaçando os lotes:** apague o pcache de vários usuários e entre com cada um. No log aparece `background repair enqueued, starts in Ns`, com o N crescendo a cada usuário.
- **Repetir o teste com o mesmo usuário:** basta abrir de novo a URL de login. Cada login refaz a verificação.

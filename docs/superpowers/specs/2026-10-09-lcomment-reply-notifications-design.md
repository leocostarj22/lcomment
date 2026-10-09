# LComment — Fase 2e: Assinaturas/Notificações (Notificação de Resposta)

## Contexto do projeto

Quinta e última sub-entrega da Fase 2 (Engajamento), depois do Escopo
Granular de Contexto (Fase 2a), das Respostas Aninhadas Reais (Fase 2b),
das Reações (Fase 2c) e das Avaliações/Votos de Utilidade (Fase 2d),
todas já implementadas, testadas ao vivo e enviadas. Esta sub-entrega
notifica por e-mail o autor de um comentário quando alguém responde
diretamente a ele.

**Revisão de desenho (2026-10-09):** a primeira versão deste spec
assumia envio síncrono do e-mail no momento em que a resposta é
publicada ("melhor esforço, uma tentativa só"). O usuário partilhou
capturas de ecrã do admin do JComments (extensão de comentários Joomla
concorrente/madura — ver memória `reference-jcomments-feature-map`) como
referência, e pediu para adaptar o padrão de **fila de notificações com
tentativas** que o JComments usa, seguindo sempre as convenções já
estabelecidas neste projeto (não uma cópia literal da interface do
JComments). Esta versão substitui a anterior por completo.

Decisões já tomadas com o usuário durante o brainstorming (mantidas da
versão anterior, salvo onde indicado):
- **Só resposta direta notifica**, automaticamente, sem subscrição
  explícita a um item inteiro — usa o `parent_id` já existente desde a
  Fase 2b.
- **Só utilizadores registados recebem notificações.** Um comentário de
  visitante como pai nunca dispara notificação, mesmo tendo
  `guest_email` guardado. A *resposta* em si pode ser de um visitante ou
  de um registado; só o **pai** precisa de ser de um utilizador
  registado.
- **Notifica só quando a resposta é publicada**, não no momento da
  submissão se ficar pendente de moderação.
- **Sem opção de desativar por utilizador nesta entrega** — só um
  interruptor global nas Opções do componente, mesmo padrão já usado em
  `enable_reactions`/`enable_votes`.
- **Sem autonotificação**: se o autor do comentário-pai responder ao
  próprio comentário, não notifica a si mesmo.
- **(Novo) Fila com tentativas, não envio síncrono**: o disparo
  **enfileira** a notificação; um processador separado (acionado por um
  botão no admin, ou automaticamente por uma Tarefa Agendada do Joomla)
  é que de facto envia o e-mail, com reenvio até um limite de tentativas.
- **(Novo) `com_scheduler` incluído nesta entrega**, não adiado — um
  terceiro plugin no pacote (grupo `task`) regista uma rotina que
  processa a fila periodicamente, além do botão manual no admin.

Este spec cobre **apenas** esta sub-entrega — a última da Fase 2. Não há
mais sub-entregas planeadas depois desta dentro da Fase 2.

## Alvo técnico

Mesmo alvo das fases anteriores: Joomla 6.1.4 real (ambiente de validação
ao vivo), PHP 8.1+. Duas APIs do Joomla nunca usadas antes neste
projeto, já verificadas contra o código-fonte real antes de escrever
este spec:

- **Mail**: `Joomla\CMS\Factory::getMailer()` existe em Joomla 6 (devolve
  uma cópia pré-configurada com o remetente global do site) e a classe
  `Joomla\CMS\Mail\Mail` expõe `setSender()`, `setSubject()`,
  `setBody()`, `addRecipient()`, `isHtml()`, `Send()` — confirmado em
  `libraries/src/Factory.php` e `libraries/src/Mail/Mail.php` no branch
  `5.4-dev`.
- **Tarefas agendadas (`com_scheduler`)**: um plugin do grupo `task`
  usa `Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait`,
  subscreve `onTaskOptionsList` (anuncia a(s) rotina(s) disponível(eis)
  ao admin) e `onExecuteTask` (executa a rotina, recebe um
  `ExecuteTaskEvent`, devolve um código de
  `Joomla\Component\Scheduler\Administrator\Task\Status`) — confirmado
  lendo o plugin nativo `plugins/task/sessiongc` (manifesto
  `sessiongc.xml` com `group="task"`, e
  `src/Extension/SessionGC.php`) no branch `5.4-dev`. Depois de
  instalado, o admin ainda precisa de ir a **Sistema → Tarefas
  Agendadas** e criar manualmente uma tarefa escolhendo a rotina deste
  plugin na lista — o Joomla não cria a tarefa sozinho na instalação,
  isso é comportamento nativo do `com_scheduler`, não algo a
  implementar aqui.

A assinatura exata de `AdminModel::publish()` (para o override no
`CommentModel` do admin, reaproveitado desta versão) fica para
verificação no momento do plano.

## Modelo de dados

### `#__lcomment_comments` — uma coluna nova

| Campo | Tipo | Notas |
|---|---|---|
| `item_url` | VARCHAR(500) NULL | URL da página capturada no momento da submissão (mesmo valor já usado como `return` no `CommentController::save()`). **Continua necessária mesmo com a fila**: o ponto de disparo no admin (publicar) não tem acesso a nenhuma página de origem, só ao que já estiver gravado no comentário desde a sua criação — sem esta coluna, uma notificação enfileirada a partir do admin não teria link nenhum para compor. Esta é a única sobra da versão síncrona anterior do spec; a outra coluna que essa versão propunha, `notified_at`, não é mais necessária — a deduplicação agora vive na `UNIQUE KEY` da tabela de notificações, abaixo.

### Nova tabela `#__lcomment_notifications`

| Campo | Tipo | Notas |
|---|---|---|
| `id` | INT UNSIGNED PK AUTO_INCREMENT | |
| `comment_id` | INT UNSIGNED NOT NULL | a resposta que originou esta notificação — chave de deduplicação |
| `user_id` | INT UNSIGNED NOT NULL | destinatário (sempre um utilizador registado, pela política) |
| `subject` | VARCHAR(255) NOT NULL | já composto no momento de enfileirar |
| `body` | TEXT NOT NULL | já composto no momento de enfileirar — o processador não precisa de voltar a consultar o comentário |
| `url` | VARCHAR(500) NULL | link para o comentário, capturado no momento de enfileirar |
| `attempts` | TINYINT UNSIGNED NOT NULL DEFAULT 0 | incrementado a cada tentativa falhada |
| `created` | DATETIME NOT NULL | |
| `sent_at` | DATETIME NULL | `NULL` = ainda pendente |

```sql
UNIQUE KEY `idx_comment` (`comment_id`)
```

A `UNIQUE KEY` em `comment_id` é o que garante — a nível de banco de
dados, não só por lógica de aplicação — que a mesma resposta nunca é
enfileirada duas vezes (ex.: despublicar e republicar), mesma disciplina
já aplicada às reações/votos depois da revisão final da Fase 2c.

A coluna `notified_at` que a versão anterior deste spec propunha
adicionar a `#__lcomment_comments` não é mais necessária — substituída
pela `UNIQUE KEY` acima. `item_url` continua, pelo motivo já explicado.

### Migração

- Versão do pacote sobe de `0.4.0` para `0.5.0` em `com_lcomment.xml`.
- `install.mysql.sql`: `item_url` entra direto na definição de
  `#__lcomment_comments` (instalações novas); a nova tabela
  `#__lcomment_notifications` também.
- Novo `sql/updates/mysql/0.5.0.sql` com **dois** comandos (upgrades de
  instalações existentes): um `ALTER TABLE` adicionando `item_url` a
  `#__lcomment_comments`, e um `CREATE TABLE IF NOT EXISTS` byte-idêntico
  ao bloco em `install.mysql.sql` para `#__lcomment_notifications`.

## `ReplyNotificationPolicy` (classe de domínio pura, testável)

Sem alterações desta parte em relação à versão anterior do spec — mesmo
padrão de `ReactionToggle`/`VoteToggle`/`ScopeEvaluator`: PHP puro, sem
dependência do Joomla, testável via PHPUnit.

```
Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotificationPolicy::shouldNotify(
    int $state,                   // estado atual da resposta
    int $parentId,                // 0 = não é resposta a nada
    ?int $parentAuthorUserId,     // null = pai é de visitante
    ?int $replyAuthorUserId,      // null = resposta é de visitante
    bool $alreadyQueued
): bool
```

Só devolve `true` quando **todas** as condições se verificam:
1. `$state === 1` (publicada).
2. `$parentId !== 0` (é mesmo uma resposta).
3. `$parentAuthorUserId !== null` (o pai é de um utilizador registado).
4. `$parentAuthorUserId !== $replyAuthorUserId` (sem autonotificação).
5. `!$alreadyQueued` (renomeado de `$alreadyNotified` na versão
   anterior — agora significa "já existe uma linha na fila para esta
   resposta", não "já foi enviado").

## `ReplyNotifier` (enfileira, não envia)

```
Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotifier::notifyIfNeeded(int $commentId): void
```

Chamado dos mesmos **dois pontos** da versão anterior, sem duplicar
lógica de decisão:

1. **`CommentController::save()`** (site) — depois de `$table->store()`
   ter sucesso, se `$policyResult->initialState === 1`.
2. **`CommentModel::publish()`** (admin, sobrescrito — mesmo padrão já
   usado para sobrescrever `getForm()` nesse mesmo model) — depois de
   `parent::publish($pks, $state)`, para cada id em `$pks` quando
   `$state == 1`. **Verificado contra a fonte real do Joomla 6**
   (`AdminModel::publish(&$pks, $value = 1)`, em
   `libraries/src/MVC/Model/AdminModel.php`): `$pks` é passado **por
   referência** e o próprio Joomla já remove dali (`unset($pks[$i])`)
   qualquer id que já estivesse no estado pedido antes da chamada — ou
   seja, depois de `parent::publish()` devolver, `$pks` já contém só os
   ids que **realmente mudaram de estado nesta chamada**. Isso significa
   que republicar um comentário já publicado nunca chega a invocar
   `notifyIfNeeded()` para ele — o próprio Joomla filtra antes do nosso
   código correr, sem precisarmos de detetar a transição nós mesmos.

### O que faz

1. Verifica o interruptor global (`enable_reply_notifications`) — se
   desligado, não faz nada.
2. Busca o comentário (`$commentId`) e, se tiver `parent_id`, busca o
   comentário-pai.
3. Chama `ReplyNotificationPolicy::shouldNotify(...)`.
4. Se `true`: **carrega explicitamente o idioma do site**
   (`Factory::getApplication()->getLanguage()->load('com_lcomment',
   \JPATH_SITE, Factory::getApplication()->get('language', 'en-GB'),
   true)` — `Factory::getLanguage()` está *deprecated* no Joomla 6,
   confirmado no código-fonte real; `$app->get('language', 'en-GB')` é a
   forma real de obter o idioma padrão configurado do site, visto em
   `SiteApplication::initialiseApp()` no mesmo branch — não o idioma do
   contexto onde o gatilho correu) antes de compor o assunto/corpo —
   necessário porque
   este serviço corre tanto no lado do site quanto no admin, e o idioma
   ativo da aplicação admin não é necessariamente o idioma padrão do
   site; sem isto, uma notificação enfileirada a partir da publicação no
   admin poderia sair com chaves de idioma por traduzir em vez de texto,
   mesmo a regra "sempre o idioma do site" já decidida. Depois busca o
   e-mail e nome do autor do pai, compõe o assunto e o corpo do e-mail
   (nome de quem respondeu, excerto de 200 caracteres do texto da
   resposta, e o link — lido diretamente de `item_url` no próprio
   comentário buscado no passo 2, não reconstruído), e **insere uma
   linha** em
   `#__lcomment_notifications` — `INSERT` simples via
   `DatabaseInterface`, mesmo estilo já usado em
   `ReactionController::applyDecision()`, dentro de um `try`/`catch` de
   `ExecutionFailureException` que trata uma violação da `UNIQUE KEY`
   como no-op (mesma disciplina já corrigida nas reações depois da
   revisão final da Fase 2c — aqui o risco real de corrida é baixo, já
   que os dois pontos de disparo nunca disparam para o mesmo
   `comment_id` ao mesmo tempo, mas a defesa fica de qualquer forma, sem
   custo extra). Não envia nada diretamente.

## `NotificationQueueProcessor` (processa a fila — enviar de verdade)

```
Lcsilva\Component\Lcomment\Administrator\Service\NotificationQueueProcessor::process(int $limit = 50): int
// devolve quantas notificações foram processadas (enviadas com sucesso ou falhadas) nesta chamada
```

Serviço único, **chamado de dois pontos** (botão manual no admin e
rotina da tarefa agendada), mesma filosofia de não duplicar lógica já
usada em `ReplyNotifier`:

1. Busca até `$limit` linhas de `#__lcomment_notifications` com
   `sent_at IS NULL` e `attempts < 3`, ordenadas por `created ASC`.
2. Para cada linha: tenta enviar via `Factory::getMailer()` (já devolve
   uma cópia pré-configurada com o remetente global do site, não precisa
   de chamar `setSender()` de novo — só `addRecipient` com o e-mail já
   resolvido no momento de enfileirar — não precisa de voltar a
   consultar `#__users`, `setSubject`/`setBody` com o conteúdo já
   pronto, `isHtml(false)`, `Send()`), dentro de `try`/`catch`.
3. Sucesso → grava `sent_at = NOW()`. Falha (exceção, ou `Send()`
   devolve `false`) → incrementa `attempts`. Ao atingir 3 tentativas
   falhadas, a linha simplesmente para de ser selecionada pelo passo 1
   (continua na tabela, visível no admin, mas não é mais tentada) — sem
   coluna de "estado de falha" nova, `attempts >= 3 AND sent_at IS NULL`
   já identifica isso para quem olhar a lista.
4. O limite (`$limit`, padrão 50) evita que um backlog grande torne uma
   única execução (clique no botão, ou disparo da tarefa agendada)
   excessivamente longa.

## Interface admin — nova view "Notifications"

Mesmo padrão de `CommentsController`/`CommentModel`
(`AdminController`/`AdminModel`, sem formulário de edição — só
listagem e ações de lista). Ganha uma entrada no submenu do admin em
`com_lcomment.xml` (`<submenu><menu view="notifications">`), mesmo
padrão das entradas já existentes para Contexts/Comments — sem isso a
view fica inacessível pela navegação normal do admin (lição já no
histórico do projeto: "admin sys.ini needing the submenu labels" na
Fase 1).

- Listagem: destinatário, assunto, tentativas, criado em, enviado em
  (vazio = pendente).
- Botão de toolbar **"Enviar notificações"**: nova tarefa no controller
  que chama `NotificationQueueProcessor::process()` e mostra quantas
  foram processadas.
- Eliminar (seleção) — herdado de `AdminController`, sem ação nova.
  "Eliminar tudo" do JComments fica de fora — selecionar tudo e eliminar
  já cobre o mesmo caso, sem precisar de um botão dedicado (YAGNI).

## Plugin de tarefa agendada (`plg_task_lcomment`)

Terceiro plugin no pacote, grupo `task`, elemento `lcomment` (mesmo
nome de elemento que `plg_content_lcomment`/`plg_system_lcomment` já
usam nos seus respetivos grupos — `<folder plugin="lcomment">` no
manifesto, `PluginHelper::getPlugin('task', 'lcomment')` no
`services/provider.php`), namespace `Lcsilva\Plugin\Task\Lcomment`
(mesma convenção de namespace por grupo já usada nos outros dois
plugins).

**Verificado contra a fonte real do Joomla 6**
(`administrator/components/com_scheduler/src/Traits/TaskPluginTrait.php`):
os três métodos que o plugin nativo `plg_task_sessiongc` usa como
handlers de evento (`advertiseRoutines`, `standardRoutineHandler`,
`enhanceTaskItemForm`) já vêm prontos na própria trait — o plugin não
precisa de escrever nenhum deles, só usar `use TaskPluginTrait;` e
apontar `getSubscribedEvents()` para eles, exatamente como
`SessionGC::getSubscribedEvents()` faz. A chave `'form'` de cada entrada
em `TASKS_MAP` é opcional (`self::TASKS_MAP[$routineId]['form'] ?? ''`,
confirmado na trait) — como não há parâmetros configuráveis nesta
entrega, a nossa única rotina (`lcomment.process_notifications`) não
define essa chave, e o manifesto não precisa de uma pasta `forms/`.

A rotina em si (um método privado, mesmo padrão do `sessionGC()` do
plugin nativo) chama `NotificationQueueProcessor::process()` com o
limite padrão e devolve `Status::OK`. Sem parâmetros configuráveis por
tarefa nesta entrega (YAGNI — o limite fica fixo no código).

Empacotamento:
- `pkg_lcomment.xml` ganha um terceiro `<file type="plugin" id="lcomment"
  group="task">`.
- `build.sh` ganha um terceiro `zip`.
- `packages/script.php`: o `postflight()` já ativa os dois plugins
  existentes com uma query `WHERE element = 'lcomment' AND folder IN
  ('content', 'system')` — como o novo plugin também usa
  `element = 'lcomment'`, só precisa de estender essa lista para
  `('content', 'system', 'task')`, sem duplicar a query. Sem isto, o
  plugin de tarefa ficaria instalado mas desativado por padrão (mesmo
  comportamento nativo do Joomla para plugins não-editor que o
  `postflight()` já existe precisamente para contornar), e a rotina
  nunca apareceria disponível para escolher numa Tarefa Agendada.

## Conteúdo do e-mail

Sem alterações em relação à versão anterior do spec:
- **Para:** e-mail do autor do comentário-pai.
- **Assunto:** "Alguém respondeu ao seu comentário em `<nome do
  site>`".
- **Corpo:** nome de quem respondeu (nome do utilizador registado, ou
  `guest_name` se a resposta for de um visitante), um excerto do texto
  da resposta (primeiros 200 caracteres, com "..." no fim se cortado), e
  o link.
- **Remetente:** configuração global de e-mail do Joomla.
- Idioma: sempre o idioma do site.

## Interruptor global (Opções do componente)

Sem alterações: novo campo `enable_reply_notifications` em
`config.xml`, mesmo padrão visual de
`allow_guests`/`enable_reactions`/`enable_votes` (interruptor rádio,
ligado por padrão). Controla só o **enfileiramento** — desligado,
`ReplyNotifier::notifyIfNeeded()` não insere nada; não afeta o
processamento de linhas já enfileiradas antes de desligar.

## Fluxo de erro

- Resposta de visitante a um comentário de visitante → nunca enfileira.
- Resposta de um utilizador registado ao **próprio** comentário → nunca
  enfileira.
- Resposta fica pendente de moderação → não enfileira agora; enfileira
  quando (e se) for publicada no admin.
- Comentário despublicado e republicado mais tarde → não enfileira de
  novo (a `UNIQUE KEY` em `comment_id` garante isso a nível de banco de
  dados, não só por lógica de aplicação).
- Interruptor global desligado → `notifyIfNeeded()` não enfileira nada.
- Falha no envio de uma linha já enfileirada → `attempts` incrementa;
  depois de 3 tentativas, a linha para de ser tentada automaticamente,
  mas continua visível no admin (nunca apagada automaticamente).

## Fora de escopo nesta sub-entrega

Subscrição explícita a um item inteiro; notificação para visitantes;
opção de desativar por utilizador; notificação multilíngue por
destinatário; digest/resumo periódico em vez de um e-mail por resposta;
parâmetros configuráveis por tarefa agendada (intervalo, limite por
execução — fica fixo no código); ação "Eliminar tudo" dedicada na
listagem de notificações; qualquer mudança em reações, votos ou
respostas aninhadas.

## Critério de aceite (verificação manual, Joomla 6.1.4 real)

1. Com moderação desligada no Context, responder (como utilizador B) a
   um comentário de um utilizador registado A → aparece uma linha
   pendente em `Components → LComment → Notifications` imediatamente
   após a submissão, `sent_at` vazio.
2. Clicar "Enviar notificações" no admin → a linha passa a ter
   `sent_at` preenchido, e o e-mail chega mesmo à caixa de entrada de A,
   com o link correto para o comentário.
3. Com moderação ligada, responder a um comentário de A → nenhuma linha
   na fila ainda; publicar a resposta no admin → só então aparece a
   linha pendente.
4. Responder ao **próprio** comentário (logado como A) → nenhuma linha
   enfileirada.
5. Um visitante responde a um comentário de um utilizador registado A →
   enfileira normalmente.
6. Um utilizador registado responde a um comentário de **visitante** →
   nenhuma linha enfileirada.
7. Despublicar e depois republicar a mesma resposta no admin → não cria
   uma segunda linha na fila para a mesma resposta.
8. Desligar o interruptor "Ativar notificações de resposta" nas Opções
   → nenhuma linha nova é enfileirada, mesmo com respostas publicadas.
9. Em **Sistema → Tarefas Agendadas**, criar uma nova tarefa escolhendo
   a rotina deste plugin, e executá-la manualmente (botão "Executar
   agora", se disponível, ou aguardar o agendamento) → processa a fila
   pendente, mesmo resultado do botão manual no admin do LComment.
10. Com o servidor de e-mail do Joomla propositadamente mal configurado
    → ao processar a fila, `attempts` incrementa e `sent_at` continua
    vazio, sem erro visível que quebre a página do admin; a linha
    continua disponível para nova tentativa até ao limite de 3.

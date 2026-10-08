# LComment — Fase 2c: Reações

## Contexto do projeto

Terceira sub-entrega da Fase 2 (Engajamento), depois do Escopo Granular de
Contexto (Fase 2a) e das Respostas Aninhadas Reais (Fase 2b), ambas já
implementadas e enviadas. Esta sub-entrega adiciona reações por emoji a
cada comentário (incluindo respostas, já que ambos são linhas da mesma
tabela `#__lcomment_comments` — a reação não distingue comentário de
topo de resposta).

Decisões já tomadas com o usuário durante o brainstorming:
- **Múltiplos emojis**, estilo Facebook/Slack: 6 tipos fixos no código
  (`like`, `love`, `haha`, `wow`, `sad`, `angry`), não configuráveis nesta
  sub-entrega.
- **Uma só reação ativa por pessoa, por comentário** (estilo Facebook):
  clicar noutro emoji troca a reação; clicar de novo no emoji já ativo
  remove a reação. Nunca duas reações simultâneas da mesma pessoa no
  mesmo comentário.
- **Visitantes e utilizadores registados podem reagir.** Identidade do
  visitante para efeito de "já reagiu": IP + id da sessão Joomla atual —
  deliberadamente uma dedupe "boa o suficiente", não à prova de abuso
  (mesmo raciocínio já aplicado ao campo `ip`, reservado para blacklist na
  Fase 3). Sem cookie novo, sem fingerprint.
- **Envio via JavaScript (fetch), com fallback funcional sem JS**
  (progressive enhancement): cada botão de emoji é também um `<form>` real
  que funciona por POST+redirect; o JS intercepta o `submit` e troca para
  `fetch` quando disponível, atualizando as contagens no DOM sem recarregar
  a página. A funcionalidade nunca depende exclusivamente do JS — mesma
  disciplina já seguida no resto do componente (validação sempre
  server-side, UI é só uma camada por cima).

Este spec cobre **apenas** esta sub-entrega. As demais sub-entregas da
Fase 2 (avaliações, assinaturas/notificações) têm seus próprios specs
futuros.

## Alvo técnico

Mesmo alvo das fases anteriores: Joomla 6.1.4 real (ambiente de validação
ao vivo), PHP 8.1+. Qualquer comportamento do framework Joomla envolvido
deve ser verificado contra o código-fonte real (`joomla-cms` 5.4-dev no
GitHub) antes de ser assumido — em particular, **o comprimento real que
`Joomla\Session\Session::getId()` pode devolver** deve ser confirmado
antes de finalizar a largura da coluna `guest_session_id` (este spec
propõe `VARCHAR(191)` como valor conservador, mas isso precisa ser
confirmado contra a fonte real, não assumido).

## Modelo de dados

### Nova tabela `#__lcomment_reactions`

| Campo | Tipo | Notas |
|---|---|---|
| id | INT UNSIGNED PK AUTO_INCREMENT | |
| comment_id | INT UNSIGNED NOT NULL | aponta para `#__lcomment_comments.id`, sem FK real (mesmo padrão das tabelas existentes) |
| user_id | INT UNSIGNED NULL | preenchido se a reação foi de um utilizador autenticado |
| guest_ip | VARCHAR(45) NULL | preenchido se a reação foi de um visitante (mesma largura já usada em `#__lcomment_comments.ip`) |
| guest_session_id | VARCHAR(191) NULL | id da sessão Joomla do visitante no momento da reação — ver nota de verificação acima |
| reaction_type | VARCHAR(20) NOT NULL | um dos 6 valores fixos: `like`, `love`, `haha`, `wow`, `sad`, `angry` |
| created | DATETIME NOT NULL | |

Índice em `(comment_id)` para a query de contagem agregada. Sem UNIQUE KEY
rígida a nível de BD para a regra "uma reação por pessoa, por comentário"
— `user_id` sendo NULL para visitantes não se presta bem a uma constraint
única em MySQL (múltiplos NULLs são permitidos num índice único). A regra
é garantida pela lógica de domínio (`ReactionToggle`, abaixo), que sempre
busca a linha existente da identidade antes de decidir inserir, atualizar
ou remover.

Cada linha representa a reação **atual** de uma identidade (utilizador ou
visitante) num comentário — nunca duas linhas da mesma identidade no
mesmo comentário.

### Migração

- Versão do pacote sobe de `0.2.0` para `0.3.0` em `com_lcomment/com_lcomment.xml`.
- `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`
  ganha a nova tabela `#__lcomment_reactions` (para instalações novas).
- Novo `com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.3.0.sql`
  com o `CREATE TABLE` da nova tabela (para upgrades de instalações
  existentes — mesmo padrão do `0.2.0.sql` da Fase 2a, que fez um `ALTER
  TABLE`; aqui é um `CREATE TABLE IF NOT EXISTS` porque é uma tabela nova,
  não uma coluna nova numa tabela existente).
- `com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql`
  ganha um `DROP TABLE IF EXISTS` para a nova tabela.

## `ReactionToggle` (classe de domínio pura, testável)

Mesmo padrão de `CommentTreeBuilder`/`ScopeEvaluator`/`SubmissionPolicy`:
PHP puro, sem dependência do Joomla, sem o guard `_JEXEC`, testável via
PHPUnit.

```
Lcsilva\Component\Lcomment\Administrator\Service\ReactionToggle::decide(
    ?string $existingType,
    string $requestedType
): array   // ['action' => 'insert'|'update'|'delete', 'type' => string|null]
```

Regras:

1. `$existingType === null` (identidade ainda não tem reação neste
   comentário) → `['action' => 'insert', 'type' => $requestedType]`.
2. `$existingType === $requestedType` (clicar de novo no emoji já ativo)
   → `['action' => 'delete', 'type' => null]`.
3. `$existingType !== $requestedType` e `$existingType !== null` (troca
   de emoji) → `['action' => 'update', 'type' => $requestedType]`.

Esta classe não sabe nada sobre identidade (utilizador vs. visitante),
nem sobre a lista de tipos válidos — essas responsabilidades ficam no
controller (próxima seção). `$requestedType` chega aqui já validado
contra a lista fixa de 6 tipos; esta classe não revalida isso.

## `ReactionController::save()` — validação e persistência

Novo controller `com_lcomment/components/com_lcomment/src/Controller/ReactionController.php`,
tarefa `reaction.save`, mesmo padrão do `CommentController::save()` já
existente (`Session::checkToken('post')`, lê `$input`, valida, persiste,
responde).

### Validação do POST

- `comment_id` (int, obrigatório): o comentário referenciado precisa
  **existir e estar publicado** (`state = 1`). Reagir a um comentário na
  lixeira, ou ainda pendente de moderação, é rejeitado — um comentário
  pendente só é visível ao seu próprio autor (regra já existente desde a
  Fase 1), reagir a ele não se aplica.
- `reaction_type` (string, obrigatório): precisa ser um dos 6 valores
  fixos (`like`, `love`, `haha`, `wow`, `sad`, `angry`). Qualquer outro
  valor é rejeitado — mesma disciplina de nunca confiar que a UI só
  oferece os valores válidos, já aplicada a `item_id`/`parent_id` nas
  fases anteriores.

### Identidade do actor

- Utilizador autenticado (`$user->id > 0`) → identidade é `user_id`.
- Visitante → identidade é o par `(guest_ip, guest_session_id)`, lidos de
  `$input->server->getString('REMOTE_ADDR', '')` e da sessão Joomla atual
  (`Factory::getApplication()->getSession()->getId()`).

### Persistência

Uma consulta busca a reação existente dessa identidade nesse
`comment_id` (por `user_id`, ou por `guest_ip` + `guest_session_id`).
`ReactionToggle::decide()` decide a ação; o controller aplica
`insert`/`update`/`delete` na tabela via `DatabaseInterface` direto (mesmo
estilo cru já usado em `CommentModel`), sem uma `Table` dedicada (a
operação é simples demais para justificar uma).

### Resposta — dois caminhos pelo mesmo endpoint

- **Sem JS** (POST normal de formulário, sem o cabeçalho abaixo):
  redireciona de volta (`$app->redirect($returnUrl)`), igual ao
  `comment.save` hoje.
- **Com JS** (fetch do `lcomment-reactions.js`): a requisição inclui o
  cabeçalho `X-LComment-Ajax: 1`; o controller detecta isso e, em vez de
  redirecionar, devolve JSON com as contagens atualizadas do comentário e
  qual reação (se alguma) é a da identidade atual:
  ```json
  {"counts": {"like": 3, "love": 1}, "mine": "like"}
  ```
  (tipos com contagem zero não aparecem em `counts`; `mine` é `null` se a
  identidade não tem reação ativa após a operação).

## Leitura das contagens (`CommentModel::getReactionsFor()`)

Novo método no `CommentModel` do site (mesmo padrão de
`getCategoryId()`/`parentBelongsToItem()` da Fase 2a/2b — código de
framework, sem PHPUnit possível, validado manualmente):

```
CommentModel::getReactionsFor(array $commentIds): array
// [commentId => ['counts' => ['like' => 3, ...], 'mine' => 'like'|null]]
```

Uma única query agregada (`GROUP BY comment_id, reaction_type`) para as
contagens de **todos** os comentários da página de uma vez — nunca uma
query por comentário. Uma segunda query simples descobre a reação da
identidade atual (por `user_id`, ou por `guest_ip`+`guest_session_id`)
dentro desse mesmo conjunto de `comment_id`s.

Chamado uma vez em `plg_content_lcomment`, junto com `getItemsFor()`, e
passado para o layout. Sem cache nem contador desnormalizado — contagem
ao vivo a cada render, igual à filosofia do resto do projeto (nenhuma
camada de cache existe em lado nenhum ainda).

## UI no layout e JS progressivo

Em `layouts/comment.php`, dentro da renderização recursiva de cada nó
(depois do texto do comentário, antes do `<details>` de resposta já
existente da Fase 2b): uma linha com os 6 botões de emoji, cada um dentro
de um `<form method="post">` real — mesmo padrão dos formulários de
comentário/resposta (funciona sem JS):

- Campos ocultos: `comment_id` (o comentário sendo reagido), `reaction_type`
  (fixo por botão, um dos 6 valores), token CSRF (`HTMLHelper::form.token`).
- O emoji da reação atual da identidade (se houver, vindo de
  `getReactionsFor()[$commentId]['mine']`) ganha uma classe CSS de
  destaque (`lcomment-reaction-active`).
- Cada botão mostra a contagem daquele tipo só quando for maior que
  zero (um emoji sem reações nenhumas aparece sem número ao lado).

Novo ficheiro `com_lcomment/media/js/lcomment-reactions.js`, registado em
`joomla.asset.json` (mesmo padrão do `lcomment.js` já existente, carregado
com `defer`): intercepta o evento `submit` de cada
`form.lcomment-reaction-form`, chama `preventDefault()`, faz `fetch` com
método POST, o corpo do próprio formulário (`FormData`), e o cabeçalho
`X-LComment-Ajax: 1`. Na resposta JSON, atualiza as contagens e a classe
de destaque desse comentário no DOM, sem recarregar a página. Se o
`fetch` falhar (rede, ou JS desativado/bloqueado), o `submit` nunca foi
interceptado com sucesso e o formulário segue o caminho normal de
POST+redirect do `ReactionController` — a funcionalidade central nunca
depende do JS.

## Fluxo de erro

- `comment_id` ausente, não-numérico, ou apontando para um comentário
  inexistente/na lixeira/pendente → rejeitado; sem JS, mensagem de erro
  via `enqueueMessage` + redirect (mesmo padrão do `comment.save`); com
  JS (cabeçalho `X-LComment-Ajax` presente), resposta HTTP 400 com corpo
  `{"error": "COM_LCOMMENT_ERROR_INVALID_REACTION_TARGET"}` — o
  `lcomment-reactions.js` apenas deixa as contagens como estavam ao
  receber um status não-200, sem tentar adivinhar um novo estado.
- `reaction_type` fora da lista fixa de 6 valores → rejeitado da mesma
  forma (HTTP 400, `{"error": "COM_LCOMMENT_ERROR_INVALID_REACTION_TYPE"}`
  no caminho com JS). Isto nunca deveria acontecer pela UI normal, mas um
  POST forjado deve ser rejeitado no servidor, mesma disciplina já
  aplicada a `item_id`/`parent_id`.
- Token CSRF inválido/ausente → mesmo comportamento já existente em
  `Session::checkToken('post')` (die com `JINVALID_TOKEN`), igual ao
  `comment.save` hoje.
- Dois cliques muito rápidos no mesmo emoji (ex.: duplo-clique) → cada
  POST é tratado independentemente pela lógica de `ReactionToggle`; o
  resultado prático é a reação ligar e desligar alternadamente a cada
  clique processado — comportamento aceitável, não é um bug a prevenir
  nesta sub-entrega (mesmo raciocínio de "boa o suficiente" já aplicado à
  identidade do visitante).

## Fora de escopo nesta sub-entrega

Indicador de reações na listagem do admin (`Components → LComment →
Comments`); moderação ou limpeza de reações pelo admin; conjunto de
emojis configurável (fixo no código por decisão já tomada); qualquer
identidade de visitante mais robusta que IP+sessão (cookie de longa
duração, fingerprint, etc. — a dedupe atual é deliberadamente "boa o
suficiente", não à prova de abuso real); migração/fusão da reação de um
visitante para a conta de utilizador quando ele faz login depois de já
ter reagido como visitante; limite de taxa (rate limiting) nas reações;
qualquer mudança em respostas aninhadas, avaliações ou
assinaturas/notificações — essas são a Fase 2b (já implementada) e outras
sub-entregas futuras da Fase 2.

## Critério de aceite (verificação manual, Joomla 6.1.4 real)

1. Num artigo com comentários existentes, clicar num emoji de reação.
   Expect: a contagem desse emoji sobe em 1, sem recarregar a página, e o
   emoji fica visualmente destacado como "a minha reação".
2. Clicar noutro emoji no mesmo comentário.
   Expect: a contagem do emoji anterior desce em 1, a do novo emoji sobe
   em 1, e o destaque visual passa para o novo emoji (nunca os dois
   destacados ao mesmo tempo).
3. Clicar de novo no emoji já destacado (ativo).
   Expect: a contagem desse emoji desce em 1 e o destaque visual
   desaparece — nenhum emoji fica marcado como "a minha reação".
4. Desativar JavaScript no navegador e repetir o passo 1.
   Expect: a página recarrega (POST+redirect normal), mas a reação é
   registada e a contagem/destaque aparecem corretos após o reload.
5. Reagir a um comentário como visitante, depois reagir ao mesmo
   comentário usando outro navegador/dispositivo (IP e sessão diferentes).
   Expect: tratado como uma identidade diferente — ambas as reações
   contam separadamente.
6. Forjar um POST para `reaction.save` com `reaction_type` fora da lista
   de 6 valores válidos.
   Expect: rejeitado, nenhuma linha criada na tabela de reações.
7. Reagir a uma resposta aninhada (não um comentário de topo).
   Expect: funciona exatamente igual a reagir a um comentário de topo
   (mesma tabela, mesma lógica, sem distinção).

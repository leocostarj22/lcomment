# LComment — Fase 2d: Avaliações (Votos de Utilidade)

## Contexto do projeto

Quarta sub-entrega da Fase 2 (Engajamento), depois do Escopo Granular de
Contexto (Fase 2a), das Respostas Aninhadas Reais (Fase 2b) e das Reações
(Fase 2c), todas já implementadas e enviadas. Esta sub-entrega adiciona
votos de utilidade a cada comentário (incluindo respostas, já que ambos
são linhas da mesma tabela `#__lcomment_comments`): outros utilizadores
marcam se um comentário foi útil ou não, estilo Amazon/Reddit — distinto
da reação emocional por emoji da Fase 2c.

Decisões já tomadas com o usuário durante o brainstorming:
- **Útil / Não útil**, não é uma nota de estrelas nem avalia o
  artigo/item — avalia a utilidade do comentário em si.
- **Uma só escolha ativa por identidade, por comentário** (mesmo padrão
  "estilo Facebook" das reações): clicar no voto oposto troca; clicar de
  novo no voto já ativo remove.
- **Visitantes e utilizadores registados podem votar**, mesma identidade
  "boa o suficiente" já usada nas reações (`user_id` para registados;
  `guest_ip` + id da sessão Joomla para visitantes).
- **Dois contadores separados sempre visíveis** (👍 N / 👎 N) — nunca um
  saldo único, nunca esconder o "não útil".
- **Autovoto bloqueado**: o autor de um comentário (quando é um
  utilizador registado, com `user_id` no comentário) não pode votar na
  utilidade do próprio comentário. Não se aplica a comentários de
  visitante (sem `user_id` para comparar) nem a votantes visitantes (sem
  conta para igualar ao autor) — mesma folga já aceita para a identidade
  de visitante nas reações.
- **Votos são só informativos**: não afetam a ordem de exibição dos
  comentários (continua cronológica, via `CommentTreeBuilder` já
  existente) nesta sub-entrega.
- **Tabela e classe de domínio próprias**, não reaproveitando
  `#__lcomment_reactions`/`ReactionToggle` da Fase 2c — são conceitos de
  produto diferentes (reação emocional vs. utilidade), com necessidades
  de exibição e evoluções futuras prováveis independentes. O código
  duplicado entre `VoteToggle` e `ReactionToggle` é pequeno (~15 linhas) e
  este projeto já prefere isso a acoplar funcionalidades por economia de
  linhas.

Este spec cobre **apenas** esta sub-entrega. A última sub-entrega da
Fase 2 (assinaturas/notificações) tem seu próprio spec futuro.

## Alvo técnico

Mesmo alvo das fases anteriores: Joomla 6.1.4 real (ambiente de validação
ao vivo), PHP 8.1+. Qualquer comportamento do framework Joomla envolvido
deve ser verificado contra o código-fonte real antes de ser assumido —
toda a base de API Joomla necessária aqui (query builder com `whereIn`/
`group`/`select` em array, `bind()` dentro de `set()`/`values()`,
`$app->setHeader('status', ...)` + `sendHeaders()`, `$app->close()`,
`$app->getSession()->getId()`, `Joomla\Database\Exception\ExecutionFailureException`)
já foi verificada contra a fonte real durante a Fase 2c e reaproveitada
aqui sem necessidade de reverificação, por ser literalmente o mesmo uso.

## Modelo de dados

### Nova tabela `#__lcomment_votes`

| Campo | Tipo | Notas |
|---|---|---|
| id | INT UNSIGNED PK AUTO_INCREMENT | |
| comment_id | INT UNSIGNED NOT NULL | aponta para `#__lcomment_comments.id`, sem FK real |
| user_id | INT UNSIGNED NULL | preenchido se o voto foi de um utilizador autenticado |
| guest_ip | VARCHAR(45) NULL | preenchido se o voto foi de um visitante |
| guest_session_id | VARCHAR(192) NULL | id da sessão Joomla do visitante no momento do voto |
| vote_type | VARCHAR(20) NOT NULL | um dos 2 valores fixos: `helpful`, `unhelpful` |
| created | DATETIME NOT NULL | |

Índices — **desde já incluindo a lição aprendida na revisão final da
Fase 2c**, que só descobriu a necessidade destas duas chaves únicas depois
de implementado (aqui entram na primeira versão):

```sql
KEY `idx_comment_id` (`comment_id`),
UNIQUE KEY `idx_user_comment` (`comment_id`, `user_id`),
UNIQUE KEY `idx_guest_comment` (`comment_id`, `guest_ip`, `guest_session_id`)
```

`UNIQUE (comment_id, user_id)` não afeta linhas de visitante (`user_id`
NULL, e MySQL permite múltiplos NULLs num índice único); `UNIQUE
(comment_id, guest_ip, guest_session_id)` não afeta linhas de utilizador
registado (colunas de visitante NULL), pelo mesmo motivo. Juntas,
garantem a nível de banco de dados a regra "nunca duas linhas da mesma
identidade no mesmo comentário", mesmo sob requisições concorrentes
(duplo-clique, troca rápida de voto) — não apenas confiando num
bloqueio no lado do cliente.

### Migração

- Versão do pacote sobe de `0.3.0` para `0.4.0` em `com_lcomment.xml`.
- `install.mysql.sql` ganha a nova tabela (instalações novas).
- Novo `sql/updates/mysql/0.4.0.sql` com o mesmo `CREATE TABLE` (upgrades
  de instalações existentes) — byte-idêntico ao bloco em
  `install.mysql.sql`, mesma disciplina já seguida nas fases anteriores.
- `uninstall.mysql.sql` ganha `DROP TABLE IF EXISTS` para a nova tabela.

## `VoteToggle` (classe de domínio pura, testável)

Mesmo padrão de `ReactionToggle`/`CommentTreeBuilder`/`ScopeEvaluator`/
`SubmissionPolicy`: PHP puro, sem dependência do Joomla, testável via
PHPUnit.

```
Lcsilva\Component\Lcomment\Administrator\Service\VoteToggle::decide(
    ?string $existingType,
    string $requestedType
): array   // ['action' => 'insert'|'update'|'delete', 'type' => string|null]

VoteToggle::VALID_TYPES = ['helpful', 'unhelpful']
```

Regras idênticas às do `ReactionToggle`:

1. `$existingType === null` → `['action' => 'insert', 'type' => $requestedType]`.
2. `$existingType === $requestedType` (clicar de novo no voto já ativo) →
   `['action' => 'delete', 'type' => null]`.
3. `$existingType !== $requestedType` e não nulo (clicar no voto oposto)
   → `['action' => 'update', 'type' => $requestedType]`.

Não valida `$requestedType` contra `VALID_TYPES` — isso é
responsabilidade de quem chama (o controller), mesma separação de
responsabilidades já usada em `ReactionToggle`.

## `VoteController::save()` — validação, autovoto e persistência

Novo controller `com_lcomment/components/com_lcomment/src/Controller/VoteController.php`,
tarefa `vote.save`, mesmo padrão do `ReactionController::save()` da Fase
2c: `Session::checkToken('post')`, identidade do actor (`user_id` ou
`guest_ip`+`guest_session_id`), resposta dupla (redirect sem o cabeçalho
`X-LComment-Ajax`; JSON com `$app->close()` depois do `echo` quando o
cabeçalho está presente).

### Validação, na ordem

1. `vote_type` precisa estar em `VoteToggle::VALID_TYPES` → senão
   `COM_LCOMMENT_ERROR_INVALID_VOTE_TYPE`.
2. O comentário referenciado precisa existir e estar publicado
   (`state = 1`) → senão `COM_LCOMMENT_ERROR_INVALID_VOTE_TARGET`. Mesma
   checagem de `state` que o `ReactionController` já faz — implementada
   de novo aqui (não extraída para um método partilhado): este projeto já
   não partilha estas pequenas queries de framework entre controllers
   (nem `CommentController`/`ReactionController` partilham hoje), então
   este spec segue o padrão já estabelecido em vez de forçar uma
   extração nova.
3. **Autovoto**: se o votante está autenticado (`user_id` conhecido) e o
   `user_id` do comentário votado é o mesmo → rejeitado com
   `COM_LCOMMENT_ERROR_SELF_VOTE_NOT_ALLOWED`. Não se aplica quando o
   comentário é de um visitante (`user_id` nulo, nada para comparar) nem
   quando o próprio votante é um visitante.

### Persistência

Mesmo fluxo do `ReactionController::applyDecision()`: busca o voto
existente da identidade nesse `comment_id`, `VoteToggle::decide(...)`
decide a ação, aplica `insert`/`update`/`delete` via `DatabaseInterface`
direto. O `insert` é protegido por `try`/`catch` de
`ExecutionFailureException`: se a inserção falhar por violar uma das duas
`UNIQUE KEY` (corrida entre requisições concorrentes da mesma
identidade), e uma nova busca confirmar que a linha da identidade já
existe, trata como corrida perdida (nada a fazer); qualquer outra falha
é relançada. Mesma lógica exata já corrigida no `ReactionController`
durante a revisão final da Fase 2c, aplicada aqui desde a primeira
versão.

### Resposta — dois caminhos pelo mesmo endpoint

Idêntico ao `reaction.save`:
- Sem o cabeçalho `X-LComment-Ajax`: redireciona de volta.
- Com o cabeçalho: HTTP 200 com
  `{"counts": {"helpful": 12, "unhelpful": 3}, "mine": "helpful"|"unhelpful"|null}`,
  ou HTTP 400 com `{"error": "<chave de idioma>"}` em caso de rejeição —
  sempre com `$app->close()` logo depois do `echo`, para que o Joomla
  nunca anexe HTML depois do corpo JSON.

## Leitura das contagens (`CommentModel::getVotesFor()`)

Mesmo padrão de `getReactionsFor()` da Fase 2c:

```
CommentModel::getVotesFor(array $commentIds): array
// [commentId => ['counts' => ['helpful' => 12, 'unhelpful' => 3], 'mine' => 'helpful'|'unhelpful'|null]]
```

Duas queries agregadas (`GROUP BY comment_id, vote_type` para as
contagens; uma segunda para "o meu voto" por identidade), para todos os
comentários da página de uma vez — nunca uma por comentário. Chamado uma
vez em `plg_content_lcomment`, junto com `getItemsFor()`/
`getReactionsFor()`, e passado para o layout. Sem cache, mesma filosofia
do resto do projeto.

## UI no layout e JS progressivo

Em `layouts/comment.php`, dentro da renderização recursiva de cada nó,
logo abaixo da linha de reações já existente da Fase 2c: dois botões
reais — 👍 "Útil" (contagem) e 👎 "Não útil" (contagem) — cada um dentro
de um `<form method="post">` real (funciona sem JS), com campos ocultos
`comment_id`, `vote_type` (fixo por botão), token CSRF. O voto ativo da
identidade (se houver) ganha destaque visual. Mesmo princípio dos botões
de reação: a contagem de cada lado aparece mesmo quando for zero (ao
contrário das reações, onde um emoji sem contagem não mostra número) —
útil/não útil sempre mostra os dois números, mesmo "0", para deixar claro
que já houve avaliação do comentário e qual o saldo.

Novo ficheiro `com_lcomment/media/js/lcomment-votes.js`, registado em
`joomla.asset.json`: mesmo padrão do `lcomment-reactions.js` — intercepta
o `submit` de cada `form.lcomment-vote-form`, `fetch` com o cabeçalho
`X-LComment-Ajax`, atualiza o DOM a partir da resposta JSON. Inclui desde
já o bloqueio por contentor enquanto uma requisição está em curso (lição
da Fase 2c aplicada aqui na primeira versão, não como correção
posterior). Se o `fetch` falhar, o DOM fica exatamente como estava —
mesma política das reações, sem tentar adivinhar um novo estado.

## Fluxo de erro

- `vote_type` fora da lista fixa de 2 valores → rejeitado (HTTP 400 com
  JS; `enqueueMessage`+redirect sem JS).
- `comment_id` inexistente, na lixeira ou pendente → rejeitado com
  `COM_LCOMMENT_ERROR_INVALID_VOTE_TARGET`.
- Autor autenticado tentando votar no próprio comentário → rejeitado com
  `COM_LCOMMENT_ERROR_SELF_VOTE_NOT_ALLOWED`.
- Duas requisições concorrentes da mesma identidade no mesmo comentário
  (duplo-clique, troca rápida) → nunca duas linhas; a segunda perde a
  corrida de forma transparente (ver `VoteController::save()` acima).
- Token CSRF inválido/ausente → mesmo comportamento já existente
  (`Session::checkToken('post')`).

## Fora de escopo nesta sub-entrega

Ordenação dos comentários por utilidade (votos são só informativos,
decisão já tomada); indicador de votos na listagem do admin; moderação
de votos pelo admin; tipos de voto configuráveis (fixos no código);
identidade de visitante mais robusta que IP+sessão; fusão do voto de um
visitante com a conta ao fazer login depois de já ter votado como
visitante; limite de taxa (rate limiting); qualquer mudança em reações,
respostas aninhadas ou assinaturas/notificações — essas são outras
sub-entregas (2c já implementada; assinaturas/notificações é a próxima).

## Critério de aceite (verificação manual, Joomla 6.1.4 real)

1. Clicar em "Útil" num comentário de outro utilizador/visitante →
   contador de "Útil" sobe 1, sem recarregar, botão destacado.
2. Clicar em "Não útil" no mesmo comentário → "Útil" desce 1, "Não útil"
   sobe 1, destaque passa para "Não útil".
3. Clicar de novo no voto já destacado → esse contador desce 1, nenhum
   botão fica destacado.
4. Desativar JavaScript e repetir o passo 1 → página recarrega
   (POST+redirect), voto registado corretamente após o reload.
5. Como utilizador registado, tentar votar no próprio comentário →
   rejeitado com a mensagem de autovoto; sem JS, mensagem de erro
   aparece; com JS, nenhum contador muda.
6. Votar como visitante, depois votar no mesmo comentário de outro
   navegador/dispositivo (IP e sessão diferentes) → contam
   separadamente.
7. Forjar um POST para `vote.save` com `vote_type` fora dos 2 valores
   válidos → rejeitado, nenhuma linha criada.
8. Votar numa resposta aninhada (não um comentário de topo) → funciona
   igual a um comentário de topo.
9. Votar num comentário pendente de moderação ou na lixeira → rejeitado
   com `COM_LCOMMENT_ERROR_INVALID_VOTE_TARGET`; resposta AJAX é JSON
   puro, sem HTML anexado.
10. Clique duplo rápido no mesmo botão, ou troca rápida útil→não útil →
    nunca mais de uma linha na tabela `#__lcomment_votes` para a mesma
    identidade+comentário.

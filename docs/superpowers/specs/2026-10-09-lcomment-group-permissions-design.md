# LComment — Fase 3a: ACL Granular por Grupo

## Contexto do projeto

Primeira sub-entrega da Fase 3 (Moderação & Segurança), decompose em 6
sub-entregas (3a-3f: ACL por grupo, Denúncias, Blacklist, Anti-flood,
Filtro de palavrões, Anti-spam/CAPTCHA) seguindo o mesmo padrão da Fase 2.
Esta sub-entrega introduz uma matriz de permissões por grupo Joomla,
inspirada no inventário do JComments (ver memória
`reference-jcomments-feature-map`): cada grupo (Público/Visitante,
Registado, Autor, Editor, Publicador, Super Utilizadores, e quaisquer
grupos customizados) ganha controlo independente sobre cinco capacidades:
publicar novos comentários, publicar respostas, publicação automática
(ignorar a moderação do Contexto), editar o próprio comentário, e
eliminar o próprio comentário.

Decisões já tomadas com o usuário durante o brainstorming:
- **Matriz única, global** — não configurável por Contexto. Mesmo padrão
  do JComments (Settings → Permissions é global). Mais simples, evita
  configuração duplicada por contexto.
- **Grupo com `autopublish` ignora a moderação do Contexto**: se o
  Contexto tem moderação ativa mas o grupo do utilizador tem
  `autopublish`, o comentário publica direto. A moderação do Contexto
  continua a valer para grupos sem essa permissão.
- **Resolução por OR entre grupos**: um utilizador Joomla pode pertencer
  a vários grupos (incluindo herança) — se qualquer grupo autorizado
  concede uma permissão, ela é concedida.
- **Editar/eliminar o próprio comentário é uma funcionalidade nova e
  real** (não apenas um toggle preparado para o futuro) — introduzida
  nesta sub-entrega, não adiada.
- **Editar/eliminar o próprio comentário só para utilizadores
  registados** — nunca para visitantes, mesmo com a mesma identidade
  IP+sessão já usada em reações/votos. Editar/eliminar conteúdo é mais
  sensível a abuso por partilha de rede/sessão do que um toggle
  reversível de reação.
- **Sem limite de tempo** para editar/eliminar o próprio comentário
  (YAGNI).
- **Editar um comentário publicado, num contexto moderado, por um
  utilizador sem `autopublish`, manda-o de volta a pendente** — mesma
  regra de "precisa de moderação?" usada na submissão nova, reutilizada
  para a edição.
- **Eliminar o próprio comentário usa o mesmo mecanismo de reciclagem do
  admin** (`state = -2`) — não um estado novo. Se tiver respostas, estas
  continuam visíveis e aninhadas; o comentário eliminado mostra um texto
  fixo ("Comentário removido") em vez do texto original. Isto exige
  alterar a consulta do site e o `CommentTreeBuilder` para **incluir**
  comentários `state = -2` com pelo menos uma resposta não eliminada —
  hoje são simplesmente excluídos, o que também corrige retroativamente
  um "minor" conhecido da Fase 2b (uma resposta "perdia" visualmente o
  pai se este desaparecesse) para qualquer trash, não só o do próprio
  autor.
- **O toggle global `allow_guests` é substituído pela matriz**: a linha
  "Visitante" da nova matriz passa a ser o único controlo sobre se
  visitantes podem comentar/responder. Na migração, o valor atual de
  `allow_guests` é copiado para essa linha antes do campo ser removido
  do `config.xml`, preservando o comportamento sem reconfiguração manual.

Este spec cobre **apenas** esta sub-entrega (3a). Denúncias, blacklist,
anti-flood, filtro de palavrões e anti-spam/CAPTCHA têm cada um o seu
próprio spec futuro (3b-3f).

## Alvo técnico

Mesmo alvo das fases anteriores: Joomla 6.1.4 real (ambiente de validação
ao vivo — site real e/ou o ambiente local Podman+MariaDB+imagem oficial
`joomla:6.1.4-apache`, ver memória `reference-local-joomla-test-env`),
PHP 8.1+. API Joomla nova usada aqui e verificada contra o código-fonte
real (`joomla-cms` `6.1-dev`) antes deste spec ser escrito:

- `Joomla\CMS\User\User::getAuthorisedGroups()` (`libraries/src/User/User.php`)
  devolve `Access::getGroupsByUser($this->id)` — um array de ids de
  grupo, incluindo herança (grupos ancestrais), e funciona uniformemente
  para utilizadores registados **e visitantes**: para um utilizador
  visitante (`id === 0`), `Access::getGroupsByUser(0, true)`
  (`libraries/src/Access/Access.php`) consulta a partir do grupo de
  visitante configurado em `com_users` (`guest_usergroup`, por padrão 1 =
  Público) e devolve os seus ancestrais. **Não é preciso nenhum
  tratamento especial para visitantes** — basta chamar
  `$app->getIdentity()->getAuthorisedGroups()` sempre, registado ou não.

## Modelo de dados

### Nova tabela `#__lcomment_group_permissions`

| Campo | Tipo | Notas |
|---|---|---|
| `group_id` | `INT UNSIGNED NOT NULL` | Chave primária — id de `#__usergroups`. Sem `AUTO_INCREMENT`, uma linha por grupo. |
| `can_post` | `TINYINT NOT NULL DEFAULT 0` | Publicar comentários novos (parent_id = 0). |
| `can_reply` | `TINYINT NOT NULL DEFAULT 0` | Publicar respostas (parent_id ≠ 0). |
| `autopublish` | `TINYINT NOT NULL DEFAULT 0` | Ignora a moderação do Contexto. |
| `can_edit_own` | `TINYINT NOT NULL DEFAULT 0` | Editar o próprio comentário (só aplicável a utilizadores registados — ver `CommentController::edit()`). |
| `can_delete_own` | `TINYINT NOT NULL DEFAULT 0` | Eliminar o próprio comentário (idem). |

```sql
CREATE TABLE IF NOT EXISTS `#__lcomment_group_permissions` (
    `group_id` INT UNSIGNED NOT NULL,
    `can_post` TINYINT NOT NULL DEFAULT 0,
    `can_reply` TINYINT NOT NULL DEFAULT 0,
    `autopublish` TINYINT NOT NULL DEFAULT 0,
    `can_edit_own` TINYINT NOT NULL DEFAULT 0,
    `can_delete_own` TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Sem chave estrangeira explícita para `#__usergroups` (o próprio núcleo
Joomla evita FKs entre tabelas de extensões e tabelas core, para não
bloquear a eliminação de um grupo por uma extensão terceira) — se um
grupo for eliminado no Joomla, a linha correspondente aqui fica órfã e
simplesmente nunca mais é lida (nenhum utilizador pertence a um grupo
que já não existe); não é limpa automaticamente, mas também não causa
nenhum erro.

### Seed na instalação

O script de instalação do componente (`script.php`, método `install()`)
insere uma linha por cada grupo existente em `#__usergroups` no momento
da instalação, com os seguintes padrões:

- **Grupo Público (id configurado em `com_users.guest_usergroup`, por
  padrão 1)**: `can_post`/`can_reply` = valor atual de `allow_guests`
  (lido de `ComponentHelper::getParams('com_lcomment')` antes deste
  parâmetro ser removido do form); `autopublish`/`can_edit_own`/
  `can_delete_own` = `0`.
- **Grupo Registado (id 2)**: `can_post`/`can_reply`/`can_edit_own`/
  `can_delete_own` = `1`; `autopublish` = `0`.
- **Todos os outros grupos** (Autor, Editor, Publicador, Super
  Utilizadores, e quaisquer grupos customizados): todas as 5 permissões
  = `1`.

Um novo grupo Joomla criado **depois** da instalação do LComment não tem
nenhuma linha nesta tabela — `GroupPermissionResolver` (abaixo) trata a
ausência de linha como todas as permissões `false` (negar por padrão,
mais seguro), até o administrador configurar esse grupo explicitamente
na nova view de Permissões.

### Migração (`0.6.0.sql`)

```sql
CREATE TABLE IF NOT EXISTS `#__lcomment_group_permissions` (
    `group_id` INT UNSIGNED NOT NULL,
    `can_post` TINYINT NOT NULL DEFAULT 0,
    `can_reply` TINYINT NOT NULL DEFAULT 0,
    `autopublish` TINYINT NOT NULL DEFAULT 0,
    `can_edit_own` TINYINT NOT NULL DEFAULT 0,
    `can_delete_own` TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Seguida da mesma lógica de seed acima, executada pelo método `update()`
do `script.php` (chamado pelo instalador do Joomla numa atualização,
distinto de `install()` numa instalação nova) — lê o `allow_guests`
ainda presente na base de dados (campo só removido do `config.xml`
nesta versão, o valor histórico continua nos `params` da extensão até
ser lido aqui) antes de preencher a linha do grupo Público. Depois deste
seed, `allow_guests` deixa de ser lido por qualquer código.

Versão do pacote: `0.5.0` → `0.6.0`.

## `GroupPermissionResolver` (classe de domínio pura, testável)

```php
final class GroupPermissionResolver
{
    /**
     * @param array<int, array{can_post: bool, can_reply: bool, autopublish: bool, can_edit_own: bool, can_delete_own: bool}> $rowsByGroupId
     *        Todas as linhas configuradas, indexadas por group_id.
     * @param int[] $authorisedGroupIds Os grupos do utilizador atual (getAuthorisedGroups()).
     * @return array{can_post: bool, can_reply: bool, autopublish: bool, can_edit_own: bool, can_delete_own: bool}
     */
    public static function resolve(array $rowsByGroupId, array $authorisedGroupIds): array
    {
        $result = [
            'can_post' => false,
            'can_reply' => false,
            'autopublish' => false,
            'can_edit_own' => false,
            'can_delete_own' => false,
        ];

        foreach ($authorisedGroupIds as $groupId) {
            if (!isset($rowsByGroupId[$groupId])) {
                continue;
            }

            foreach ($result as $key => $value) {
                $result[$key] = $value || $rowsByGroupId[$groupId][$key];
            }
        }

        return $result;
    }
}
```

Pura função — sem dependência de Joomla, sem acesso a base de dados
(recebe os dados já carregados). Testada com: nenhum grupo configurado
→ tudo `false`; um grupo concede `can_post`, outro não, utilizador tem
ambos → `can_post` = `true` (OR); grupo inexistente na tabela → ignorado
sem erro; todas as 5 permissões testadas independentemente.

A leitura da tabela (`SELECT * FROM #__lcomment_group_permissions`,
indexado por `group_id`) é feita por um novo método
`CommentModel::getGroupPermissions(): array` (site), reaproveitando o
padrão de query direta via `DatabaseInterface` já usado no resto do
componente — sem cache nesta sub-entrega (tabela pequena, uma leitura
por página, YAGNI).

## Aplicação no envio de comentários (`SubmissionPolicy`)

`SubmissionRequest` perde o campo `guestsAllowed` e ganha três novos,
todos resolvidos pelo `CommentController::save()` antes de construir o
request (via `GroupPermissionResolver::resolve()` com os grupos do
utilizador atual, registado ou visitante):

```php
final class SubmissionRequest
{
    public function __construct(
        public readonly bool $contextActive,
        public readonly bool $contextModeration,
        public readonly ?int $userId,
        public readonly string $text,
        public readonly int $minLength,
        public readonly int $maxLength,
        public readonly string $guestName = '',
        public readonly string $guestEmail = '',
        public readonly bool $itemIncluded = true,
        public readonly bool $parentValid = true,
        public readonly bool $isReply = false,
        public readonly bool $canPost = false,
        public readonly bool $canReply = false,
        public readonly bool $autopublish = false,
    ) {
    }
}
```

`$isReply` é `$parentId !== 0`, já calculado pelo `CommentController`
antes desta chamada.

`SubmissionPolicy::evaluate()` substitui o bloco:

```php
if ($request->userId === null && !$request->guestsAllowed) {
    return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED']);
}
```

por um único bloco, válido para visitantes e registados, verificado
**depois** de `contextActive`/`itemIncluded`/`parentValid` (que são
problemas estruturais do contexto/item, não da identidade) e **antes**
da validação dos dados do visitante (nome/e-mail) ou do texto:

```php
$permitted = $request->isReply ? $request->canReply : $request->canPost;

if (!$permitted) {
    return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED']);
}
```

E o cálculo do estado inicial passa de:

```php
$request->contextModeration ? 0 : 1
```

para:

```php
($request->contextModeration && !$request->autopublish) ? 0 : 1
```

A mensagem de erro antiga `COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED` é
removida do idioma (já não é usada por nenhum código); a nova
`COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED` é genérica o suficiente para
cobrir tanto "visitante sem permissão" como "grupo registado sem
permissão de responder", sem expor qual permissão específica falhou.

## Editar e eliminar o próprio comentário

Dois novos métodos no `CommentController` (site), espelhando o padrão
já usado por `save()` (token CSRF, resolução do modelo, redirect com
mensagem flash):

### `CommentController::edit(): bool`

1. `Session::checkToken('post')`.
2. Exige utilizador registado — `$app->getIdentity()->id > 0`, senão
   `COM_LCOMMENT_ERROR_NOT_OWNER` (mesma mensagem genérica do passo 4,
   para não revelar se o comentário existe).
3. Carrega o `CommentTable` pelo `id` recebido. Se não existir, mesma
   mensagem genérica.
4. Verifica `(int) $table->user_id === (int) $user->id`, senão
   `COM_LCOMMENT_ERROR_NOT_OWNER`.
5. Resolve `can_edit_own` para os grupos do utilizador via
   `GroupPermissionResolver`. Se `false`, `COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED`.
6. Valida o novo texto com `CommentValidator::validate()` (mesmas regras
   de `min_length`/`max_length` já existentes).
7. Calcula o novo estado com a mesma fórmula de
   `($contextModeration && !$autopublish) ? 0 : 1` — reconsultando o
   Contexto e as permissões do utilizador, nunca o estado anterior do
   comentário.
8. `$table->comment_text` + `$table->state` + `$table->modified` atualizados,
   `$table->store()`.
9. Mensagem de sucesso: `COM_LCOMMENT_SAVE_SUCCESS_PUBLISHED` ou
   `_PENDING`, reaproveitadas (mesmo texto já usado na submissão nova,
   faz sentido igualmente para uma edição).

### `CommentController::delete(): bool`

Passos 1-4 idênticos a `edit()`. Depois:

5. Resolve `can_delete_own`. Se `false`, erro genérico.
6. `$table->state = -2` (reciclagem — mesmo mecanismo do admin), `$table->store()`.
7. Mensagem de sucesso: nova chave `COM_LCOMMENT_DELETE_SUCCESS`
   ("O seu comentário foi eliminado.").

Nenhum dos dois métodos aceita `extension`/`view`/`item_id` do input —
são lidos do próprio comentário carregado (`$table->extension` etc.),
nunca confiando em valores possivelmente adulterados do formulário, para
decidir a que Contexto este comentário pertence.

### Visibilidade de comentários eliminados (site)

`CommentModel::getItemsFor()` (site) hoje filtra implicitamente por
`state = 1` (só publicados). Passa a trazer também linhas `state = -2`
**desde que** exista pelo menos uma outra linha, no mesmo `item_id`, com
`parent_id` igual ao `id` dessa linha eliminada e `state` diferente de
`-2` — ou seja, só busca o "cadáver" quando ele ainda tem descendência
viva, nunca um eliminado sem respostas (que continua simplesmente
ausente, like today). Implementado com uma subquery `EXISTS` na mesma
`getListQuery`-like method, sem nova classe.

O layout do comentário (`layouts/comment/item.php` ou equivalente) passa
a verificar `(int) $item->state === -2` e, nesse caso, renderizar
`Text::_('COM_LCOMMENT_DELETED_PLACEHOLDER')` ("Comentário removido") no
lugar do `comment_text`, sem nome de autor, sem botões de reação/voto/
editar/eliminar/responder (um comentário eliminado não aceita novas
respostas). As respostas dele (que existem e não estão eliminadas)
continuam a renderizar normalmente, aninhadas por baixo — o
`CommentTreeBuilder` já suporta isto sem alteração, pois só precisa que
o nó do pai exista na lista recebida com o `id` certo; o que muda é
apenas que a API de leitura agora entrega esse nó.

Isto vale **tanto para eliminação pelo próprio autor quanto pela
reciclagem do admin** — mesma coluna `state = -2`, mesmo comportamento
de exibição. Fecha retroativamente o "minor" da Fase 2b ("uma resposta
perdia visualmente o pai se este desaparecesse entre o carregamento da
página e o envio") para o caso de o pai ter sido trashed por qualquer
via, não apenas o caso original (pai rejeitado antes de ser aprovado,
que continua a comportar-se como antes — um pai que nunca foi publicado
não tem `state = -2`, tem `state = 0`, e esse caso não é alterado por
este spec).

## Interface admin — view "Permissões"

Nova view `permissions` (view simples, sem lista paginada — uma única
página com um formulário). Submenu: `<menu view="permissions">COM_LCOMMENT_MENU_PERMISSIONS</menu>`.

- **Model** (`PermissionsModel`, não um `ListModel` nem `AdminModel` —
  um `BaseDatabaseModel` simples): `getGroups(): array` devolve todos os
  grupos de `#__usergroups` (id, title, nível de indentação, mesma
  consulta que o Joomla usa no seu próprio seletor de grupos, para
  preservar a hierarquia visualmente) juntos com as 5 permissões
  atuais (`LEFT JOIN` com `#__lcomment_group_permissions`, `0`/`false`
  para qualquer grupo sem linha). `save(array $rows): bool` faz um
  `REPLACE INTO` em lote (uma linha por grupo recebido do formulário).
- **Controller** (`PermissionsController extends BaseController`): só
  `display()` (herdado) e um novo `save()` que lê `$input->get('groups',
  [], 'array')` — array de `group_id => ['can_post' => '1', ...]` — e
  chama o Model.
- **Template**: uma tabela com uma linha por grupo (indentação visual
  conforme o nível), 5 colunas de checkbox (`groups[<id>][can_post]`
  etc.), um botão "Guardar" no toolbar (`ToolbarHelper::custom('permissions.save', ...)`,
  mesmo padrão de botão custom já usado em `Notifications`).

## UI no layout e JS

Cada item do layout do comentário (`layouts/comment/item.php`) ganha,
junto às reações/votos já existentes, dois novos elementos **só quando**
`(int) $item->user_id === (int) utilizador atual->id` (comentário é do
próprio visitante, exige registado) **e** a permissão correspondente
está concedida (os dados de permissão do utilizador atual são
calculados uma vez por página, no plugin de conteúdo, e passados ao
layout como mais um array, igual a `$reactions`/`$votes`):

- **"Editar"**: reaproveita o mesmo padrão `<details>`/`<summary>` já
  usado para "Responder" — ao abrir, troca o texto estático visível por
  um `<textarea>` pré-preenchido com `comment_text`, com um botão
  "Guardar edição" que submete para `task=comment.edit`.
- **"Eliminar"**: um botão simples que dispara `confirm()` em JS
  (`Tem a certeza que quer eliminar este comentário?`) antes de
  submeter um formulário mínimo (`id` + token) para `task=comment.delete`.

Sem AJAX nesta sub-entrega (mesmo padrão síncrono de recarregar a
página já usado por `save()`/`reply()` — reações/votos é que usam AJAX,
por serem toggles de um clique; editar/eliminar justificam o recarregar
de página, como a própria submissão de um comentário novo).

## Fluxo de erro

Mesma convenção já estabelecida: `$app->enqueueMessage($mensagem, 'error')`
+ redirect para o `return` da requisição. Nenhuma das novas verificações
(dono incorreto, grupo sem permissão) distingue a mensagem exibida de
"erro genérico" — nunca revelar ao visitante se um comentário existe ou
pertence a outra pessoa além do necessário para recusar a ação.

## Novas chaves de idioma

Site (`com_lcomment.ini`): `COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED`,
`COM_LCOMMENT_ERROR_NOT_OWNER`, `COM_LCOMMENT_DELETE_SUCCESS`,
`COM_LCOMMENT_DELETED_PLACEHOLDER`, `COM_LCOMMENT_EDIT_LABEL`,
`COM_LCOMMENT_DELETE_LABEL`, `COM_LCOMMENT_DELETE_CONFIRM`.

Admin (`com_lcomment.ini`/`.sys.ini`): `COM_LCOMMENT_MENU_PERMISSIONS`,
`COM_LCOMMENT_PERMISSIONS_TITLE`, `COM_LCOMMENT_PERMISSIONS_GROUP_LABEL`,
`COM_LCOMMENT_PERMISSIONS_CAN_POST_LABEL`,
`COM_LCOMMENT_PERMISSIONS_CAN_REPLY_LABEL`,
`COM_LCOMMENT_PERMISSIONS_AUTOPUBLISH_LABEL`,
`COM_LCOMMENT_PERMISSIONS_CAN_EDIT_OWN_LABEL`,
`COM_LCOMMENT_PERMISSIONS_CAN_DELETE_OWN_LABEL`,
`COM_LCOMMENT_PERMISSIONS_SAVED_MESSAGE`.

Removida (já não usada por nenhum código): `COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED`.
`COM_LCOMMENT_CONFIG_ALLOW_GUESTS_LABEL` também é removida junto do
campo `allow_guests` do `config.xml`.

## Fora de escopo nesta sub-entrega

Denúncias, blacklist, anti-flood, filtro de palavrões, anti-spam/CAPTCHA
(3b-3f). Edição/eliminação de comentários de outros utilizadores pelo
admin continua a ser feita só pela reciclagem/publicação já existentes
— nenhuma nova interface admin para editar o *texto* de um comentário de
terceiros. Histórico de edições (versões anteriores do texto) não é
guardado. Notificação ao autor de uma resposta quando o comentário-pai é
editado ou eliminado não é alterada por este spec (continua a disparar,
se aplicável, só na publicação de uma resposta — Fase 2e). Limite de
quantas vezes um comentário pode ser editado não existe.

## Critério de aceite (verificação manual, Joomla 6.1.4 real)

1. Instalar a atualização sem erros; `#__lcomment_group_permissions` tem
   uma linha por grupo existente, com os padrões descritos.
2. Com o grupo Registado tendo `can_post` desligado nas Permissões, um
   utilizador registado não consegue submeter um comentário novo (erro
   genérico); com `can_reply` desligado, não consegue responder, mas
   continua a conseguir comentar um novo tópico se `can_post` estiver
   ligado.
3. Grupo Público com `can_post`/`can_reply` desligados bloqueia
   visitantes nas mesmas condições — confirma que a matriz substituiu
   `allow_guests` (campo já não existe nas Opções).
4. Num Contexto com moderação ativa, um utilizador cujo grupo tem
   `autopublish` ligado tem o comentário publicado imediatamente; um
   sem essa permissão fica pendente.
5. Um utilizador registado com `can_edit_own` consegue editar o próprio
   comentário; o botão "Editar" não aparece no comentário de outro
   utilizador, nem para visitantes no próprio.
6. Editar um comentário publicado, num Contexto moderado, sem
   `autopublish`, manda-o de volta a pendente (desaparece até
   reaprovação); com `autopublish`, mantém-se publicado.
7. Um utilizador registado com `can_delete_own` consegue eliminar o
   próprio comentário; se ele tiver respostas publicadas, estas
   continuam visíveis e aninhadas, e o comentário eliminado mostra
   "Comentário removido" em vez do texto original.
8. Eliminar, pelo admin (reciclagem já existente), um comentário com
   respostas produz o mesmo efeito do ponto 7 no site.
9. A nova view admin "Permissões" lista todos os grupos (incluindo
   customizados, se existirem), guarda corretamente as 5 colunas, e
   reflete a mudança imediatamente nas verificações do site.

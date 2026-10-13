# LComment — Fase 3c: Blacklist

## Contexto do projeto

Terceira sub-entrega da Fase 3 (Moderação & Segurança), depois da ACL
Granular por Grupo (Fase 3a) e Denúncias (Fase 3b), ambas já
implementadas, testadas ao vivo e enviadas. Esta sub-entrega introduz
uma blacklist: o admin bloqueia um utilizador registado (por
`user_id`) e/ou um IP, impedindo qualquer interação futura com os
comentários — inspirado no inventário do JComments (ver memória
`reference-jcomments-feature-map`: "Blacklist by IP and/or user_id,
with Reason/Notes/created-by/created-at"). A coluna `ip` em
`#__lcomment_comments` já estava reservada para isto desde a Fase 1.

Decisões já tomadas com o usuário durante o brainstorming:
- **Um IP bloqueado bloqueia qualquer submissão a partir dele, mesmo de
  um utilizador registado** — não só convidados. Defesa mais forte
  contra contornar o bloqueio com uma conta nova a partir do mesmo
  computador.
- **A blacklist bloqueia TODAS as ações de interação site-side, não só
  comentar**: comentar, responder, editar o próprio, eliminar o
  próprio, reagir, votar, denunciar. Nenhuma destas 7 ações continua
  disponível para uma identidade bloqueada.
- **Bloquear não afeta conteúdo já existente**: comentários antigos de
  um utilizador agora bloqueado continuam visíveis e publicados
  normalmente — a blacklist só impede novas ações a partir do momento
  em que a entrada é criada.
- **Adicionar à blacklist e eliminar (reciclagem) um comentário são
  decisões independentes.** A ação rápida de bloquear a partir da
  lista de Comentários nunca despublica/recicla o comentário de
  origem automaticamente — se o admin também quiser isso, usa a ação
  "Reciclagem" já existente, à parte.
- **Sem toggle global `enable_blacklist`** — ao contrário de
  reações/votos/denúncias (funcionalidades opcionais), a blacklist é
  um mecanismo de segurança sempre ativo; "desligar" significa
  simplesmente não ter entradas.
- **Fluxo de criação de entradas**: formulário dedicado na nova view
  "Blacklist" (tipo, valor, motivo) **mais** uma ação rápida por linha
  na lista de Comentários admin ("Bloquear IP" / "Bloquear
  utilizador") que pré-preenche esse mesmo formulário — nada é
  bloqueado automaticamente só por abrir o formulário, o admin
  confirma e grava.
- **Mensagem de erro genérica e única** para as 7 ações bloqueadas —
  nunca revela que existe uma blacklist nem o motivo. Mensagens
  customizáveis por admin (ex.: "está na blacklist") ficam para a Fase
  4, como já estava no roadmap.

## Alvo técnico

Joomla 6.1.4 real (ver `reference_local_joomla_test_env` para o
ambiente de testes local). Package `com_lcomment` sobe de `0.7.0` para
`0.8.0`.

## Modelo de dados

### Nova tabela `#__lcomment_blacklist`

```sql
CREATE TABLE IF NOT EXISTS `#__lcomment_blacklist` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(10) NOT NULL,
    `value` VARCHAR(45) NOT NULL,
    `reason` VARCHAR(255) NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_type_value` (`type`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- `type`: `'ip'` ou `'user'`. Validado em PHP contra uma lista fixa
  (`BlacklistPolicy::VALID_TYPES`), não um `ENUM` da BD — mesmo padrão
  já usado em `reports.reason`.
- `value`: o IP (string, até IPv6 em `VARCHAR(45)`) ou o `user_id`
  (armazenado como string, convertido para `int` ao comparar).
- `reason`: nota livre do admin, nunca mostrada ao utilizador
  bloqueado — só aparece na view admin "Blacklist".
- `UNIQUE KEY (type, value)` — impede duas entradas idênticas ao nível
  da BD, mas só como rede de segurança. Verifiquei o código real de
  `Joomla\CMS\Table\Table::store()`: apanha qualquer `\Exception` da
  inserção e faz `$this->setError($e->getMessage())` — a mensagem
  **crua do driver** (ex.: `SQLSTATE[23000]: ... Duplicate entry ...`),
  não uma mensagem amigável. Por isso `BlacklistTable::check()`
  (chamado pelo `AdminModel::save()` *antes* de `store()`) faz a
  validação amigável primeiro: chama `BlacklistPolicy::validateEntry()`
  e, se passar, uma consulta `SELECT` própria por `type`+`value` iguais
  (excluindo o próprio `id` quando a editar) — se já existir,
  `setError(Text::_('COM_LCOMMENT_ERROR_DUPLICATE_BLACKLIST_ENTRY'))` e
  devolve `false`, exatamente como o precedente `ContextTable::check()`
  já faz para a sua própria validação. A `UNIQUE KEY` da BD nunca
  deveria ser alcançada em condições normais (um admin de cada vez,
  sem o mesmo risco de corrida concorrente que motivou o
  catch-and-recheck manual no site) — fica só como garantia estrutural
  final.

### Migração (`0.8.0.sql`)

Mesmo bloco `CREATE TABLE IF NOT EXISTS` acima, em
`administrator/components/com_lcomment/sql/updates/mysql/0.8.0.sql`,
byte-idêntico ao bloco adicionado em `sql/install.mysql.sql`.
`sql/uninstall.mysql.sql` ganha `DROP TABLE IF EXISTS
#__lcomment_blacklist;`.

Sem novas Opções globais nesta sub-entrega (sem `enable_blacklist`).

## `BlacklistPolicy` (classe de domínio pura, testável)

```php
final class BlacklistPolicy
{
    public const VALID_TYPES = ['ip', 'user'];

    /**
     * Valida uma nova entrada antes de a gravar. Devolve uma lista de
     * chaves de erro (vazia se válida).
     */
    public static function validateEntry(string $type, string $value): array
    {
        $errors = [];

        if (!\in_array($type, self::VALID_TYPES, true)) {
            $errors[] = 'COM_LCOMMENT_ERROR_INVALID_BLACKLIST_TYPE';

            return $errors;
        }

        $value = trim($value);

        if ($value === '') {
            $errors[] = 'COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE';

            return $errors;
        }

        if ($type === 'ip' && filter_var($value, FILTER_VALIDATE_IP) === false) {
            $errors[] = 'COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE';
        }

        if ($type === 'user' && (!ctype_digit($value) || (int) $value <= 0)) {
            $errors[] = 'COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE';
        }

        return $errors;
    }

    /**
     * Decide se uma identidade está bloqueada a partir dos dois factos
     * já apurados pela consulta à BD (BlacklistChecker). Mantido como
     * método próprio, testável, apesar de trivial, para documentar e
     * fixar o comportamento OR.
     */
    public static function isBlocked(bool $userMatch, bool $ipMatch): bool
    {
        return $userMatch || $ipMatch;
    }
}
```

Casos de teste exigidos: tipo inválido; valor vazio; IP malformado
(`'not-an-ip'`); IP válido IPv4 e IPv6; user id não numérico; user id
zero/negativo; user id válido; `isBlocked` nas 4 combinações de
booleanos.

## `BlacklistChecker` (glue, consulta real à BD)

Classe não-pura, em `Administrator\Service`, chamada diretamente pela
`AbstractLcommentController` (ver secção seguinte) — não passa por
nenhum `getModel()`, para que a base partilhada não dependa de qual
controller concreto a está a chamar.

```php
final class BlacklistChecker
{
    public static function isBlocked(?int $userId, string $ip): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('type'))
            ->from($db->quoteName('#__lcomment_blacklist'));

        $conditions = [
            $db->quoteName('type') . ' = ' . $db->quote('ip') . ' AND ' . $db->quoteName('value') . ' = ' . $db->quote($ip),
        ];

        if ($userId !== null) {
            $conditions[] = $db->quoteName('type') . ' = ' . $db->quote('user') . ' AND ' . $db->quoteName('value') . ' = ' . $db->quote((string) $userId);
        }

        $query->where('(' . implode(') OR (', $conditions) . ')');

        $types = $db->setQuery($query)->loadColumn() ?: [];

        return BlacklistPolicy::isBlocked(
            \in_array('user', $types, true),
            \in_array('ip', $types, true)
        );
    }
}
```

Sem testes automatizados (classe de glue com acesso real à BD, mesmo
tratamento dado a `ReplyNotifier`/`GroupPermissionResolver`'s caller
etc. — só verificação manual/ao vivo).

## `AbstractLcommentController` — ponto único de aplicação

Nova classe em
`com_lcomment/components/com_lcomment/src/Controller/AbstractLcommentController.php`:

```php
abstract class AbstractLcommentController extends BaseController
{
    public function execute($task)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $ip = $app->getInput()->server->getString('REMOTE_ADDR', '');
        $userId = ($user !== null && $user->id > 0) ? (int) $user->id : null;

        if (BlacklistChecker::isBlocked($userId, $ip)) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_BLACKLISTED'), 'error');
            $returnUrl = base64_decode($app->getInput()->getBase64('return', ''));
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        return parent::execute($task);
    }
}
```

Verificado contra o código real do `Joomla\CMS\MVC\Controller\BaseController::execute()`
instalado (Joomla 6.1.4): o método só resolve `$task` para o nome do
método via `$this->taskMap` e invoca-o — nenhum outro efeito lateral
antes disso, por isso interceptar aqui é seguro e cobre qualquer task
(incluindo `__default`).

`CommentController`, `ReactionController`, `VoteController` e
`ReportController` passam a `extends AbstractLcommentController` em
vez de `extends BaseController` directamente — mudança de uma linha
por ficheiro, sem outras alterações a esses controllers.

Uma identidade bloqueada nunca chega a correr `save()`/`edit()`/
`delete()` nestes 4 controllers — o `parent::execute($task)` nunca é
chamado, por isso o `Session::checkToken()` de cada método também
nunca corre para essa pessoa (irrelevante para a segurança: a decisão
de bloqueio já não depende do token).

## Interface admin — view "Blacklist"

Segue o precedente já existente no projeto para o Contexto (Fase
1/2a) — par `FormController`/`AdminController` com formulário real,
ao contrário do padrão "sem formulário" usado em Denúncias/
Notificações/Permissões:

- `BlacklistTable extends Table` — `#__lcomment_blacklist`.
- `BlacklistModel extends AdminModel`:
  - `getForm()` devolve o form real `forms/blacklist.xml` (não
    `false`).
  - `getItem($pk = null)`: quando `$pk` é `0`/vazio (novo registo) e o
    input tiver `bl_type`/`bl_value` (GET, só usados neste caso),
    pré-preenche `type`/`value` no item devolvido antes de o form os
    ligar — é assim que a ação rápida da lista de Comentários
    funciona, sem lógica adicional no form XML nem JS.
  - `save()` (herdado de `AdminModel`) grava `created_by` = utilizador
    admin autenticado e `created` = agora, antes de persistir — via
    `prepareTable()`.
- `BlacklistsModel extends ListModel` — colunas Tipo, Valor, Motivo,
  Criado por (`LEFT JOIN #__users ON a.created_by = u.id`), Data.
- `BlacklistController extends FormController` — `$text_prefix =
  'COM_LCOMMENT_BLACKLIST'`.
- `BlacklistsController extends AdminController` — mesmo
  `$text_prefix`, usa o `delete()` herdado para eliminação em lote.
- `forms/blacklist.xml`: `id` (oculto, readonly), `type` (radio:
  IP/Utilizador), `value` (texto), `reason` (textarea), `created_by` e
  `created` (ocultos, preenchidos pelo Model, nunca editáveis no
  form).
- `tmpl/blacklist/default.php` (lista) e `tmpl/blacklist/edit.php`
  (formulário), seguindo o layout dos templates de Contexto já
  existentes.
- Nova entrada no menu admin (`com_lcomment.xml`): `<menu
  view="blacklist">COM_LCOMMENT_MENU_BLACKLIST</menu>`.

### Ação rápida na lista de Comentários

`tmpl/comments/default.php` ganha uma nova coluna "Ações" com dois
links condicionais por linha:
- "Bloquear IP" — só visível se `$item->ip` não for vazio. Aponta para
  `index.php?option=com_lcomment&task=blacklist.add&bl_type=ip&bl_value=<ip
  urlencoded>`.
- "Bloquear utilizador" — só visível se `$item->user_id` estiver
  definido (comentário não é de convidado). Aponta para
  `index.php?option=com_lcomment&task=blacklist.add&bl_type=user&bl_value=<user_id>`.

Abrem o formulário de criação da Blacklist já pré-preenchido; o admin
escreve o motivo e grava explicitamente — a ação rápida nunca bloqueia
nada por si só, e nunca toca no estado do comentário de origem.

## Fluxo de erro

Quando `AbstractLcommentController::execute()` bloqueia qualquer uma
das 7 ações (comentar, responder, editar, eliminar, reagir, votar,
denunciar), mostra sempre a mesma mensagem genérica —
`COM_LCOMMENT_ERROR_BLACKLISTED` ("Não tem permissão para realizar
esta ação.") — e redireciona para o `return` da submissão, igual ao
padrão de erro já usado em todo o componente. Nunca menciona a
blacklist nem o motivo da entrada.

No formulário admin, uma entrada duplicada ou inválida é apanhada em
`BlacklistTable::check()` (descrito acima) e mostra a mensagem de erro
correspondente no próprio formulário, sem perder os dados já
introduzidos.

## Novas chaves de idioma

**Site** (`components/com_lcomment/language/{en-GB,pt-PT}/*.com_lcomment.ini`):
- `COM_LCOMMENT_ERROR_BLACKLISTED`

**Admin** (`administrator/components/com_lcomment/language/{en-GB,pt-PT}/*.com_lcomment.ini` e `.sys.ini`):
- `COM_LCOMMENT_MENU_BLACKLIST`
- `COM_LCOMMENT_BLACKLIST_*` (título da view, colunas da lista, labels
  do formulário: Tipo, Valor, Motivo, Criado por, Data)
- `COM_LCOMMENT_ERROR_INVALID_BLACKLIST_TYPE`
- `COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE`
- `COM_LCOMMENT_ERROR_DUPLICATE_BLACKLIST_ENTRY`
- `COM_LCOMMENT_COMMENTS_ACTIONS_LABEL`,
  `COM_LCOMMENT_COMMENTS_BLOCK_IP_LABEL`,
  `COM_LCOMMENT_COMMENTS_BLOCK_USER_LABEL` (coluna e links novos na
  lista de Comentários)

`COM_LCOMMENT_ERROR_BLACKLISTED` é a única chave duplicada em ambos os
lados (site mostra a mensagem de erro; admin não precisa dela) — mesma
disciplina já aplicada em Fase 3b (admin não carrega automaticamente o
`.ini` do site).

## Fora de escopo nesta sub-entrega

- Intervalos de IP/CIDR — só IP exato, string a string.
- Bloqueio por `guest_email`/`guest_name` — só `ip` e `user_id`, como
  no roadmap original.
- Expiração automática de uma entrada — permanente até o admin a
  eliminar manualmente.
- Mensagens de erro customizáveis/específicas por admin — Fase 4.
- Qualquer efeito retroativo sobre comentários já existentes do
  utilizador/IP bloqueado (ocultar, despublicar, reciclar) — sempre
  uma ação manual e independente do admin.
- Toggle global `enable_blacklist`.

## Critério de aceite (verificação manual, Joomla 6.1.4 real)

1. Admin cria uma entrada `type=user` com o `user_id` de uma conta de
   teste → essa conta deixa de conseguir comentar, responder, editar o
   próprio comentário, eliminar o próprio comentário, reagir, votar ou
   denunciar — as 7 ações recusadas com a mesma mensagem genérica.
2. Admin cria uma entrada `type=ip` → um convidado a partir desse IP é
   bloqueado nas mesmas 7 ações; uma conta registada a submeter a
   partir desse IP também é bloqueada, mesmo que o seu `user_id` não
   esteja na blacklist.
3. Os comentários antigos, já publicados, de uma identidade agora
   bloqueada continuam visíveis e publicados normalmente no site.
4. A ação "Bloquear IP"/"Bloquear utilizador" na lista de Comentários
   abre o formulário de criação da Blacklist já com o tipo e valor
   corretos pré-preenchidos.
5. Bloquear alguém a partir da lista de Comentários não altera o
   estado do comentário de origem — continua no mesmo `state` até o
   admin decidir, à parte, enviá-lo para a reciclagem.
6. Tentar criar uma entrada `type`+`value` já existente é rejeitada
   com uma mensagem clara, sem erro genérico de BD.
7. Um `type` desconhecido, `value` vazio, IP malformado, ou `user_id`
   não numérico/≤0 no formulário é recusado com uma mensagem de
   validação clara.
8. Eliminar uma entrada da Blacklist (seleção + eliminação em lote)
   desbloqueia imediatamente essa identidade — uma nova tentativa das
   7 ações volta a funcionar.
9. Um pedido forjado diretamente (`curl`) para qualquer uma das 7
   ações, vindo de um IP/user_id bloqueado, é recusado da mesma forma
   — a aplicação não depende só de esconder os formulários no HTML.

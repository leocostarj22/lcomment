# LComment — Fase 2e: Assinaturas/Notificações (Notificação de Resposta)

## Contexto do projeto

Quinta e última sub-entrega da Fase 2 (Engajamento), depois do Escopo
Granular de Contexto (Fase 2a), das Respostas Aninhadas Reais (Fase 2b),
das Reações (Fase 2c) e das Avaliações/Votos de Utilidade (Fase 2d),
todas já implementadas, testadas ao vivo e enviadas. Esta sub-entrega
notifica por e-mail o autor de um comentário quando alguém responde
diretamente a ele.

Decisões já tomadas com o usuário durante o brainstorming:
- **Só resposta direta notifica**, automaticamente, sem subscrição
  explícita a um item inteiro (essa opção foi descartada) — usa o
  `parent_id` já existente desde a Fase 2b.
- **Só utilizadores registados recebem notificações.** Um comentário de
  visitante como pai nunca dispara notificação, mesmo tendo
  `guest_email` guardado — decisão deliberada, não uma limitação técnica.
  A *resposta* em si pode ser de um visitante ou de um registado; só o
  **pai** precisa de ser de um utilizador registado.
- **Notifica só quando a resposta é publicada**, não no momento da
  submissão se ficar pendente de moderação — evita notificar sobre algo
  que ainda pode ser rejeitado. Isso significa dois pontos de disparo no
  código: submissão com moderação desligada (publica na hora) e a ação
  de publicar no admin (para o que ficou pendente).
- **Sem opção de desativar por utilizador nesta entrega** (nem link de
  cancelamento no e-mail, nem preferência de perfil) — só um interruptor
  global nas Opções do componente, mesmo padrão já usado em
  `enable_reactions`/`enable_votes`.
- **Sem autonotificação**: se o autor do comentário-pai responder ao
  próprio comentário, não notifica a si mesmo.

Este spec cobre **apenas** esta sub-entrega — a última da Fase 2. Não há
mais sub-entregas planeadas depois desta dentro da Fase 2.

## Alvo técnico

Mesmo alvo das fases anteriores: Joomla 6.1.4 real (ambiente de validação
ao vivo), PHP 8.1+. Primeira vez que este projeto envia e-mail — já
verificado contra o código-fonte real antes de escrever este spec:
`Joomla\CMS\Factory::getMailer()` existe em Joomla 6 (devolve uma cópia
pré-configurada com o remetente global do site) e a classe
`Joomla\CMS\Mail\Mail` expõe `setSender()`, `setSubject()`, `setBody()`,
`addRecipient()`, `isHtml()`, `Send()` — confirmado em
`libraries/src/Factory.php` e `libraries/src/Mail/Mail.php` no branch
`5.4-dev`. A assinatura exata de `AdminModel::publish()` (para o
override no `CommentModel` do admin) fica para verificação no momento do
plano, mesma disciplina já seguida nas fases anteriores.

## Modelo de dados

### `#__lcomment_comments` — duas colunas novas, sem tabela nova

| Campo | Tipo | Notas |
|---|---|---|
| `item_url` | VARCHAR(500) NULL | URL da página capturada no momento da submissão (mesmo valor já usado como `return` no `CommentController::save()`) — usada para montar o link no e-mail. Guardada porque o segundo ponto de disparo (publicar no admin) não tem acesso à página de origem. |
| `notified_at` | DATETIME NULL | Marca quando a notificação desta resposta já foi enviada (ou pelo menos tentada — ver "Falhas de envio" abaixo). Evita notificar duas vezes se o estado mudar várias vezes (ex.: publicar → despublicar → publicar de novo). |

### Migração

- Versão do pacote sobe de `0.4.0` para `0.5.0` em `com_lcomment.xml`.
- `install.mysql.sql`: as duas colunas entram direto na definição da
  tabela `#__lcomment_comments` (instalações novas).
- Novo `sql/updates/mysql/0.5.0.sql` com `ALTER TABLE` adicionando as
  duas colunas (upgrades de instalações existentes) — desta vez não é
  `CREATE TABLE IF NOT EXISTS` byte-idêntico como nas fases anteriores,
  porque é uma alteração de tabela existente, não uma tabela nova.

## `ReplyNotificationPolicy` (classe de domínio pura, testável)

Mesmo padrão de `ReactionToggle`/`VoteToggle`/`ScopeEvaluator`: PHP puro,
sem dependência do Joomla, testável via PHPUnit.

```
Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotificationPolicy::shouldNotify(
    int $state,                   // estado atual da resposta
    int $parentId,                // 0 = não é resposta a nada
    ?int $parentAuthorUserId,     // null = pai é de visitante
    ?int $replyAuthorUserId,      // null = resposta é de visitante
    bool $alreadyNotified
): bool
```

Só devolve `true` quando **todas** as condições se verificam:
1. `$state === 1` (publicada).
2. `$parentId !== 0` (é mesmo uma resposta).
3. `$parentAuthorUserId !== null` (o pai é de um utilizador registado).
4. `$parentAuthorUserId !== $replyAuthorUserId` (sem autonotificação —
   quando `$replyAuthorUserId` é `null`, a comparação com um
   `$parentAuthorUserId` não-nulo já é naturalmente `false`, então um
   visitante respondendo nunca é bloqueado por engano aqui).
5. `!$alreadyNotified`.

Não decide mais nada — não sabe nada sobre o conteúdo do e-mail, nem
sobre o interruptor global (isso é responsabilidade de quem chama,
`ReplyNotifier`, abaixo).

## `ReplyNotifier` (serviço com acesso a BD e Mail, não puro)

```
Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotifier::notifyIfNeeded(int $commentId): void
```

Chamado de **dois pontos**, sempre com o mesmo método, sem duplicar
lógica de decisão (a condição "ainda não foi notificado" já cobre os
dois casos sem precisar detetar transição de estado explicitamente):

1. **`CommentController::save()`** (site) — logo depois de
   `$table->store()` ter sucesso, se `$policyResult->initialState === 1`
   (moderação desligada, publica na hora).
2. **`CommentModel::publish()`** (admin, sobrescrito — hoje usa o
   `AdminModel::publish()` genérico do Joomla sem override, mesmo padrão
   já usado para sobrescrever `getForm()` nesse mesmo model) — depois de
   `parent::publish($pks, $state)`, para cada id em `$pks` quando
   `$state == 1`.

### O que faz

1. Verifica o interruptor global (`enable_reply_notifications`, Opções
   do componente) — se desligado, não faz nada. Os dois pontos de
   disparo chamam sempre `notifyIfNeeded()`; é o serviço que decide
   internamente se está ativo, não quem chama.
2. Busca o comentário (`$commentId`) e, se tiver `parent_id`, busca o
   comentário-pai.
3. Chama `ReplyNotificationPolicy::shouldNotify(...)` com os dados
   encontrados.
4. Se `true`: busca o e-mail do autor do pai (`#__users.email`), compõe
   e envia o e-mail via `Factory::getMailer()`, e grava `notified_at`.

### Conteúdo do e-mail

- **Para:** e-mail do autor do comentário-pai.
- **Assunto:** "Alguém respondeu ao seu comentário em `<nome do
  site>`" (nome do site via `Factory::getConfig()->get('sitename')`).
- **Corpo:** nome de quem respondeu (nome do utilizador registado, ou
  `guest_name` se a resposta for de um visitante — só o pai precisa de
  ser registado), um excerto do texto da resposta (primeiros 200
  caracteres, com "..." no fim se for cortado), e o link guardado em
  `item_url` desse comentário de resposta.
- **Remetente:** configuração global de e-mail do Joomla
  (`Factory::getMailer()` já vem pré-configurado com o "From" do site —
  não precisa de configuração nova).
- Idioma: sempre o idioma do site (via `Text::_()` com as strings já
  carregadas), sem negociação por destinatário.

### Falhas de envio nunca bloqueiam nada

O envio fica dentro de `try`/`catch`. Uma falha de SMTP (ou qualquer
exceção do mailer) nunca impede o comentário de ser gravado no
`CommentController::save()`, nem a ação de publicar no admin de
funcionar — a falha é descartada silenciosamente (sem infraestrutura de
log neste projeto ainda). `notified_at` é gravado mesmo que o envio
falhe — decisão deliberada: sem fila nem mecanismo de reenvio nesta
entrega, cada ponto de disparo só chama `notifyIfNeeded()` uma vez para
o mesmo evento real, então não há "próxima tentativa" de qualquer forma.
É "melhor esforço, uma única tentativa", mesma filosofia já aceite para
a identidade "boa o suficiente" de visitante nas reações/votos.

## Interruptor global (Opções do componente)

Novo campo `enable_reply_notifications` em `config.xml`, mesmo padrão
visual de `allow_guests`/`enable_reactions`/`enable_votes` (interruptor
rádio, ligado por padrão).

## Fluxo de erro

- Resposta de visitante a um comentário de visitante → nunca notifica
  (sem utilizador registado em nenhum dos dois lados).
- Resposta de um utilizador registado ao **próprio** comentário → nunca
  notifica (regra 4 da política).
- Resposta fica pendente de moderação → não notifica agora; notifica
  quando (e se) for publicada no admin.
- Comentário despublicado e republicado mais tarde → não notifica de
  novo (já tinha `notified_at` gravado da primeira vez).
- Interruptor global desligado → `notifyIfNeeded()` não faz nada em
  nenhum dos dois pontos de disparo.
- Falha no envio do e-mail (SMTP mal configurado, etc.) → comentário
  continua gravado/publicado normalmente; `notified_at` é gravado mesmo
  assim (ver "Falhas de envio" acima).

## Fora de escopo nesta sub-entrega

Subscrição explícita a um item inteiro (avisar de todos os comentários
novos, não só respostas diretas) — descartada no brainstorming;
notificação para visitantes, mesmo tendo `guest_email` guardado; opção
de desativar por utilizador (preferência de perfil ou link de
cancelamento no e-mail); reenvio ou fila para e-mails que falharam;
notificação multilíngue por destinatário; digest/resumo periódico em vez
de um e-mail por resposta; qualquer mudança em reações, votos ou
respostas aninhadas — essas já são sub-entregas anteriores da Fase 2,
todas implementadas.

## Critério de aceite (verificação manual, Joomla 6.1.4 real)

1. Com moderação desligada no Context, responder (como utilizador B) a
   um comentário de um utilizador registado A → A recebe um e-mail
   assim que a resposta é submetida, com o link correto para o
   comentário.
2. Com moderação ligada, responder a um comentário de A → nenhum e-mail
   ainda; publicar a resposta no admin → A recebe o e-mail só então.
3. Responder ao **próprio** comentário (logado como A, respondendo a um
   comentário de A) → nenhum e-mail enviado.
4. Um visitante responde a um comentário de um utilizador registado A →
   A recebe o e-mail normalmente (só o pai precisa de ser registado).
5. Um utilizador registado responde a um comentário de **visitante** →
   nenhum e-mail enviado (visitantes nunca recebem notificações).
6. Despublicar e depois republicar a mesma resposta no admin → não
   envia um segundo e-mail.
7. Desligar o interruptor "Ativar notificações de resposta" nas Opções
   → nenhum e-mail é enviado para nenhuma resposta nova, mesmo
   publicada.
8. Com o servidor de e-mail do Joomla propositadamente mal configurado
   (ou indisponível) → a resposta continua a ser submetida e publicada
   normalmente, sem erro visível ao utilizador.

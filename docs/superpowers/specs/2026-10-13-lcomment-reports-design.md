# LComment — Fase 3b: Denúncias

## Contexto do projeto

Segunda sub-entrega da Fase 3 (Moderação & Segurança), depois da ACL
Granular por Grupo (Fase 3a, já implementada, testada ao vivo e
enviada). Esta sub-entrega introduz denúncias: um utilizador registado
assinala um comentário de outra pessoa como impróprio, escolhendo um
motivo de uma lista fixa; ao acumular um número configurável de
denúncias, o comentário volta automaticamente a pendente para revisão
do admin — inspirado no inventário do JComments (ver memória
`reference-jcomments-feature-map`: "Maximum number of reports per
comment", "Number of reports to auto-unpublish a comment", "Require a
reason for the report").

Decisões já tomadas com o usuário durante o brainstorming:
- **Só utilizadores registados podem denunciar** — nunca visitantes,
  mesmo por identidade IP+sessão. Denunciar tem uma consequência real
  (pode despublicar automaticamente), diferente do risco aceite para um
  toggle reversível como uma reação.
- **Uma denúncia por utilizador, por comentário, sem desfazer** — ao
  contrário de reações/votos (que são um toggle), denunciar não é
  revertível pelo próprio denunciante. Uma segunda tentativa do mesmo
  utilizador no mesmo comentário é rejeitada com mensagem clara, não
  silenciosamente ignorada nem tratada como erro genérico de BD.
- **Autodenúncia bloqueada**, mesmo padrão já usado no autovoto (Fase
  2d).
- **Motivo obrigatório, de uma lista fixa**: Spam, Ofensivo/abusivo,
  Discurso de ódio, Outro. Sem texto livre nesta sub-entrega.
- **"Despublicar automaticamente" = `state: 1 → 0`** (volta a pendente,
  o mesmo estado de um comentário novo à espera de moderação) — nunca
  `-2` (reciclagem). O admin vê-o no filtro "Não publicado" já
  existente da lista de Comentários e decide: republicar, ou eliminar
  (reciclagem) pela via já existente.
- **Limite de denúncias configurável** nas Opções do componente
  (`report_threshold`, padrão `3`), verificado no momento em que uma
  nova denúncia é inserida — não é uma verificação periódica.
- **Denúncias existentes não são limpas automaticamente** quando o
  admin republica manualmente um comentário despublicado por denúncias
  — ficam na base de dados até o admin as eliminar explicitamente na
  nova view "Denúncias" (ação "Eliminar", herdada do `AdminController`,
  mesmo padrão já usado em Comentários/Notificações). Sem isto, bastava
  **uma única** denúncia nova a seguir à republicação para disparar de
  novo a despublicação automática — aceitável, mas o admin tem de saber
  que precisa de limpar as antigas se quiser reiniciar o contador.
- **Denúncias não fazem parte da matriz de permissões por grupo da
  Fase 3a** — seguem o padrão mais simples já usado por reações/votos:
  um único toggle global `enable_reports`, sem gating por grupo. Mantém
  a Fase 3a (já em produção) intocada e usa o precedente já existente
  para funcionalidades de interação entre utilizadores (distintas de
  publicar/editar/eliminar o próprio conteúdo, que é o que a matriz da
  Fase 3a cobre).
- **Só se pode denunciar um comentário atualmente publicado**
  (`state === 1`) — não um pendente, já despublicado ou eliminado.

Este spec cobre **apenas** esta sub-entrega (3b). Blacklist, anti-flood,
filtro de palavrões e anti-spam/CAPTCHA (3c-3f) têm cada um o seu
próprio spec futuro.

## Alvo técnico

Mesmo alvo das fases anteriores: Joomla 6.1.4 real (site real e/ou o
ambiente local Podman+MariaDB+imagem oficial `joomla:6.1.4-apache`, ver
memória `reference-local-joomla-test-env`), PHP 8.1+. Toda a API Joomla
necessária aqui já foi verificada contra o código-fonte real em fases
anteriores e reaproveitada sem necessidade de reverificação, por ser
literalmente o mesmo uso: `Joomla\Database\Exception\ExecutionFailureException`
(catch-and-recheck em UNIQUE KEY, Fase 2c/2d/2e), o par
`AdminModel`/`Table` para uma nova view com ação "Eliminar" (corrigido
retroativamente na Fase 2e para `Notifications`, aplicado aqui desde o
início), e o padrão `ListModel` com `LEFT JOIN` (Fase 2e/3a).

## Modelo de dados

### Nova tabela `#__lcomment_reports`

| Campo | Tipo | Notas |
|---|---|---|
| `id` | `INT UNSIGNED NOT NULL AUTO_INCREMENT` | Chave primária. |
| `comment_id` | `INT UNSIGNED NOT NULL` | |
| `user_id` | `INT UNSIGNED NOT NULL` | Sempre um registado — nunca `NULL`, ao contrário de reações/votos. |
| `reason` | `VARCHAR(20) NOT NULL` | Um dos valores de `ReportPolicy::VALID_REASONS` (abaixo). |
| `created` | `DATETIME NOT NULL` | |

```sql
CREATE TABLE IF NOT EXISTS `#__lcomment_reports` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comment_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `reason` VARCHAR(20) NOT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_comment_id` (`comment_id`),
    UNIQUE KEY `idx_user_comment` (`comment_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

A `UNIQUE KEY (comment_id, user_id)` é a garantia real contra denúncia
duplicada sob concorrência — a verificação "já denunciou?" feita antes
de inserir é só para dar uma mensagem clara no caso comum, não a
proteção em si (mesma lição já aplicada em reações/votos/notificações
desde a Fase 2c).

### Migração (`0.7.0.sql`)

```sql
CREATE TABLE IF NOT EXISTS `#__lcomment_reports` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comment_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `reason` VARCHAR(20) NOT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_comment_id` (`comment_id`),
    UNIQUE KEY `idx_user_comment` (`comment_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Byte-idêntico ao bloco do `install.mysql.sql`. Versão do pacote: `0.6.0`
→ `0.7.0`.

### Novas Opções globais (`config.xml`)

- `enable_reports` — `radio`/`joomla.form.field.radio.switcher`,
  padrão `1`, mesmo padrão de `enable_reactions`/`enable_votes`.
- `report_threshold` — `number`, padrão `3`, mesmo padrão de
  `min_length`/`max_length`.

## `ReportPolicy` (classe de domínio pura, testável)

```php
final class ReportPolicy
{
    public const VALID_REASONS = ['spam', 'abusive', 'hate_speech', 'other'];

    /**
     * @return string[] Chaves de idioma dos erros; vazio quando permitido.
     */
    public static function canReport(
        int $commentState,
        ?int $commentAuthorUserId,
        int $reporterUserId,
        bool $alreadyReported,
        string $reason
    ): array {
        if ($commentState !== 1) {
            return ['COM_LCOMMENT_ERROR_INVALID_REPORT_TARGET'];
        }

        if ($commentAuthorUserId !== null && $commentAuthorUserId === $reporterUserId) {
            return ['COM_LCOMMENT_ERROR_SELF_REPORT_NOT_ALLOWED'];
        }

        if ($alreadyReported) {
            return ['COM_LCOMMENT_ERROR_ALREADY_REPORTED'];
        }

        if (!\in_array($reason, self::VALID_REASONS, true)) {
            return ['COM_LCOMMENT_ERROR_INVALID_REPORT_REASON'];
        }

        return [];
    }

    public static function reachesAutoUnpublishThreshold(int $totalReportsAfterInsert, int $threshold): bool
    {
        return $totalReportsAfterInsert >= $threshold;
    }
}
```

Pura função — sem dependência de Joomla, sem acesso a base de dados
(recebe os dados já carregados pelo `ReportController`). Testada com:
cada condição de `canReport()` isoladamente e a sua prioridade relativa
(estado inválido antes de autodenúncia antes de já-denunciado antes de
motivo inválido — mesma convenção de "a verificação estrutural manda
primeiro" já usada em `SubmissionPolicy`); `reachesAutoUnpublishThreshold()`
nos limites exatos (`total - 1` → `false`, `total` → `true`, acima →
`true`).

## `ReportController::save()` — validação e persistência

Mesmo padrão estrutural de `ReactionController`/`VoteController`, mas
síncrono (formulário + redirect), não AJAX — denunciar exige escolher
um motivo antes de confirmar, não é um clique único reversível como uma
reação.

1. `Session::checkToken('post')`.
2. Verifica `enable_reports` (Opções). Se desligado,
   `COM_LCOMMENT_ERROR_REPORTS_DISABLED`.
3. Exige utilizador registado (`$user->id > 0`); visitante recebe
   `COM_LCOMMENT_ERROR_NOT_OWNER` (mesma chave genérica já usada por
   `edit()`/`delete()` para "não tens conta/não é teu" — reaproveitada
   aqui porque o efeito para o visitante é o mesmo: a ação não está
   disponível).
4. Carrega `comment_id`, `state` e `user_id` do comentário via uma
   consulta direta (mesmo padrão de `VoteController::findCommentForVoting()`).
   Comentário inexistente é tratado como `state` inválido (mesma
   mensagem).
5. Verifica se já existe uma denúncia deste utilizador para este
   comentário (`SELECT COUNT(*) ... WHERE comment_id AND user_id`).
6. Chama `ReportPolicy::canReport()` com os dados acima mais o `reason`
   recebido do formulário. Qualquer erro → mensagem + redirect.
7. Insere a denúncia, com o mesmo `try/catch (ExecutionFailureException)`
   de catch-and-recheck já usado em `ReactionController`/`VoteController`
   (se a `UNIQUE KEY` disparar por uma corrida concorrente, trata como
   "já denunciado" silenciosamente; qualquer outra falha é relançada).
8. Conta o total de denúncias para este `comment_id` (agora incluindo a
   que acabou de inserir). Se
   `ReportPolicy::reachesAutoUnpublishThreshold($total, $threshold)` e
   o comentário **ainda** está `state === 1` (reconsultado, nunca
   assumido do passo 4 — pode ter mudado entretanto), atualiza para
   `state = 0`.
9. Mensagem de sucesso (`COM_LCOMMENT_REPORT_SUCCESS`, "Denúncia
   registada.") e redirect.

## Interface admin — view "Denúncias"

Nova view `reports`, mesmo padrão de `Comments`/`Notifications`:
- **`ReportsModel extends ListModel`**: `getListQuery()` com `LEFT
  JOIN` em `#__lcomment_comments` (para mostrar um excerto do texto e
  o `item_url`, mesma coluna "Página" já usada em Comentários) e em
  `#__users` (para o nome do denunciante). Colunas: Página, Comentário
  (excerto), Denunciante, Motivo, Data.
- **`ReportModel extends AdminModel`** + **`ReportTable extends
  Table`**: só para que `AdminController::delete()` (herdado,
  `ToolbarHelper::deleteList()`) funcione — `getForm()` devolve `false`
  (nunca se edita uma denúncia, só se elimina), mesmo padrão já
  corrigido retroativamente para `Notifications` na Fase 2e, aplicado
  aqui desde o início em vez de descoberto numa revisão.
- **`ReportsController extends AdminController`**: `$text_prefix =
  'COM_LCOMMENT_REPORTS'`, `getModel($name = 'Report', ...)` (singular,
  mesma convenção).
- **Submenu**: `<menu view="reports">COM_LCOMMENT_MENU_REPORTS</menu>`.

## UI no layout e JS

Junto aos botões de editar/eliminar já existentes (visíveis só no
próprio comentário do utilizador), um botão "Denunciar" aparece em
**qualquer outro** comentário publicado (`state === 1`, autor ≠
utilizador atual) quando:
- o utilizador está registado (`$currentUserId !== null`);
- `enable_reports` está ativo;
- o utilizador **ainda não denunciou** este comentário.

O terceiro ponto exige que o plugin de conteúdo calcule, uma vez por
página (mesmo padrão de `$authorNames`/`$userPermissions`), o conjunto
de `comment_id`s que o utilizador atual já denunciou — novo método
`CommentModel::getReportedCommentIds(int $userId): array` (site),
devolvido como `$reportedCommentIds` ao layout.

Reaproveita o mesmo padrão `<details>`/`<summary>` já usado para
"Responder"/"Editar": ao abrir, mostra um `<select>` com os 4 motivos
fixos e um botão "Confirmar denúncia", submetendo para
`task=report.save`.

## Fluxo de erro

Mesma convenção já estabelecida: `$app->enqueueMessage($mensagem,
'error')` + redirect para o `return` da requisição.

## Novas chaves de idioma

Site (`com_lcomment.ini`): `COM_LCOMMENT_ERROR_REPORTS_DISABLED`,
`COM_LCOMMENT_ERROR_INVALID_REPORT_TARGET`,
`COM_LCOMMENT_ERROR_SELF_REPORT_NOT_ALLOWED`,
`COM_LCOMMENT_ERROR_ALREADY_REPORTED`,
`COM_LCOMMENT_ERROR_INVALID_REPORT_REASON`,
`COM_LCOMMENT_REPORT_SUCCESS`, `COM_LCOMMENT_REPORT_LABEL`,
`COM_LCOMMENT_REPORT_CONFIRM_LABEL`,
`COM_LCOMMENT_REPORT_REASON_LABEL`,
`COM_LCOMMENT_REPORT_REASON_SPAM`,
`COM_LCOMMENT_REPORT_REASON_ABUSIVE`,
`COM_LCOMMENT_REPORT_REASON_HATE_SPEECH`,
`COM_LCOMMENT_REPORT_REASON_OTHER`.

Admin (`com_lcomment.ini`/`.sys.ini`): `COM_LCOMMENT_MENU_REPORTS`,
`COM_LCOMMENT_REPORTS_TITLE`, `COM_LCOMMENT_REPORTS_PAGE_LABEL`,
`COM_LCOMMENT_REPORTS_COMMENT_LABEL`,
`COM_LCOMMENT_REPORTS_REPORTER_LABEL`,
`COM_LCOMMENT_REPORTS_REASON_LABEL`,
`COM_LCOMMENT_REPORTS_CREATED_LABEL`,
`COM_LCOMMENT_REPORTS_N_ITEMS_DELETED_1`,
`COM_LCOMMENT_REPORTS_N_ITEMS_DELETED_MORE`,
`COM_LCOMMENT_CONFIG_ENABLE_REPORTS_LABEL`,
`COM_LCOMMENT_CONFIG_REPORT_THRESHOLD_LABEL`. As 4 chaves de motivo
(`_REASON_SPAM` etc.) são usadas tanto pelo `<select>` do site como
pela coluna "Motivo" da lista admin — **duplicadas em ambos os
ficheiros** (`com_lcomment.ini` do site e do admin), não carregadas de
um lado só: o admin não carrega automaticamente o ficheiro de idioma do
site, e este projeto já duplica chaves partilhadas entre os dois lados
quando necessário (ex. as mensagens de erro de reações/votos existem
nos dois ficheiros desde a Fase 2c/2d) em vez de assumir carregamento
cruzado.

## Fora de escopo nesta sub-entrega

Blacklist, anti-flood, filtro de palavrões, anti-spam/CAPTCHA (3c-3f).
Texto livre como motivo adicional. Notificar o admin por e-mail quando
um comentário é despublicado por denúncias (poderia reaproveitar o
mecanismo de fila da Fase 2e no futuro, não decidido agora). Limite de
denúncias por Contexto (é global, como a matriz da Fase 3a). Eliminar
automaticamente as denúncias antigas quando o admin republica um
comentário (decisão explícita: ficam, o admin limpa manualmente).
Impedir um utilizador de ver quantas denúncias já existem no próprio
comentário dele.

## Critério de aceite (verificação manual, Joomla 6.1.4 real)

1. Instalar a atualização sem erros; `#__lcomment_reports` existe;
   novas Opções (`enable_reports`, `report_threshold`) aparecem.
2. Um visitante não vê o botão "Denunciar" em nenhum comentário.
3. Um utilizador registado não vê o botão no próprio comentário.
4. Um utilizador registado denuncia o comentário de outra pessoa,
   escolhendo um motivo — sucesso, linha aparece na nova view admin
   "Denúncias" com o motivo correto.
5. O mesmo utilizador tenta denunciar o mesmo comentário outra vez —
   rejeitado com mensagem clara, sem segunda linha na BD.
6. Com `report_threshold = 3`, ao atingir a 3.ª denúncia de 3
   utilizadores diferentes, o comentário desaparece do site (volta a
   `state = 0`) e aparece em Comentários → filtro "Não publicado".
7. O admin republica manualmente esse comentário — volta a aparecer no
   site; as 3 denúncias continuam na view "Denúncias" até serem
   eliminadas manualmente.
8. Desativar `enable_reports` nas Opções remove o botão do site e
   bloqueia `task=report.save` mesmo por pedido direto.
9. A ação "Eliminar" na view "Denúncias" remove as linhas selecionadas
   sem afetar o comentário denunciado.

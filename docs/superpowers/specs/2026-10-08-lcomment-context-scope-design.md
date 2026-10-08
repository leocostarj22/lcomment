# LComment — Fase 2a: Escopo Granular de Contexto

## Contexto do projeto

Esta é a primeira sub-entrega da Fase 2 (Engajamento) do LComment. A Fase 1
(Fundação) entregou um Context com granularidade apenas de
`extension + view` (ex.: todos os artigos de `com_content`/`article` têm
comentários, ou nenhum tem). O spec original do produto exige algo mais
fino: "incluir ou excluir comentários apenas para categorias ou itens
específicos" — esse recurso é o diferencial central do LComment e precisa
ser genuinamente customizável, não um atalho superficial.

Decisão já tomada com o usuário: regras por categoria são **dinâmicas** —
uma categoria marcada como excluída continua excluindo artigos futuros
criados nela, não é apenas uma lista de IDs congelada no momento do save.

Este spec cobre **apenas** esta sub-entrega (escopo granular). As demais
sub-entregas da Fase 2 (respostas aninhadas, reações, avaliações,
assinaturas/notificações) têm seus próprios specs futuros.

## Alvo técnico

Mesmo alvo da Fase 1 (`docs/superpowers/specs/2026-10-07-lcomment-foundation-design.md`),
com uma correção aprendida durante a validação ao vivo da Fase 1: **o
ambiente real de testes é Joomla 6.1.4** (não 5.x — o Joomla 6 não tinha
release estável quando o spec da Fase 1 foi escrito). O código desta
sub-entrega deve ser escrito e, sempre que possível, verificado contra o
código-fonte real do `joomla-cms` (branch `5.4-dev` no GitHub, que reflete
a mesma API usada pelo 6.1.4 para as classes aqui envolvidas) antes de
assumir uma assinatura de método ou atributo de formulário — vários bugs
da Fase 1 vieram de assumir comportamento a partir de páginas de tutorial
resumidas, não do código real.

## Modelo de dados

Nenhuma tabela nova. Reaproveita a coluna `#__lcomment_contexts.params`
(TEXT, já reservada na Fase 1 para "opções futuras") e adiciona uma nova
coluna:

| Campo | Tipo | Notas |
|---|---|---|
| `scope_mode` | VARCHAR(20) NOT NULL DEFAULT 'all' | `all` \| `exclude` \| `include` |

`params` passa a guardar, quando `scope_mode != 'all'`, um JSON no formato:

```json
{
  "scope_rules": [
    {"type": "category", "value": 12},
    {"type": "item", "value": 345}
  ]
}
```

- `type`: `"category"` (só válido quando `context.extension = 'com_content'`,
  `value` é um `catid` de `#__categories`) ou `"item"` (válido para
  qualquer extensão, `value` é o `item_id`).
- A lista é avaliada com lógica **OU**: um item casa com o escopo se
  **qualquer** regra da lista casar com ele (por `item_id` igual, ou por
  `catid` igual quando a regra é do tipo categoria).
- `scope_mode = 'exclude'`: comentários ativos em todos os itens do
  context, **exceto** os que casarem com alguma regra.
- `scope_mode = 'include'`: comentários ativos **apenas** nos itens que
  casarem com alguma regra; lista vazia = nenhum item tem comentários
  (ver Fluxo de erro).

SQL de migração (`sql/updates/mysql/0.2.0.sql`, referenciado via
`<update><schemas><schemapath type="mysql">sql/updates/mysql</schemapath>`
no manifesto — ainda não existia na Fase 1, precisa ser adicionado):

```sql
ALTER TABLE `#__lcomment_contexts`
    ADD COLUMN `scope_mode` VARCHAR(20) NOT NULL DEFAULT 'all' AFTER `moderation`;
```

## `ScopeEvaluator` (classe de domínio pura, testável)

Mesmo padrão das classes da Fase 1 (`ContextResolver`, `CommentValidator`,
`SubmissionPolicy`): PHP puro, sem dependência do Joomla, sem o guard
`_JEXEC`, unit-testável via PHPUnit.

```
Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator::isItemIncluded(
    string $scopeMode,
    array $rules,       // cada item: ['type' => 'category'|'item', 'value' => int]
    int $itemId,
    ?int $categoryId    // null quando a extensão não é com_content
): bool
```

Regras de decisão:

1. `scopeMode === 'all'` → sempre `true`.
2. Qualquer `scopeMode` fora de `{'all', 'exclude', 'include'}` (dado
   corrompido) → tratado como `'exclude'` com lista vazia, ou seja,
   **inclui tudo** — falha a favor do comportamento padrão da Fase 1, já
   que `scope_mode` corrompido é mais provável de ser um bug de
   dados/migração do que uma restrição intencional do admin.
3. Uma regra do tipo `'category'` só pode casar quando `$categoryId !== null`;
   nunca compara `$categoryId` com `null` como se fossem iguais a zero.
4. `scopeMode === 'exclude'`: `true` a menos que alguma regra case.
5. `scopeMode === 'include'`: `true` **somente se** alguma regra casar —
   lista vazia em modo `include` deliberadamente exclui tudo (ver Fluxo de
   erro).

## Admin — formulário de Context

Adições a `forms/context.xml`:

- `scope_mode`: `type="radio"`, `layout="joomla.form.field.radio.switcher"`
  não se aplica aqui (3 opções, não 2) — usar `type="list"` com as 3
  opções (Todos / Exceto / Apenas), default `all`.
- `scope_rules`: `type="subform"`, `multiple="true"`,
  `formsource="forms/context_scope_rule.xml"`,
  `showon="scope_mode:exclude,include"`. Serializa automaticamente como
  array JSON — é isso que vai para `params` (chave `scope_rules`).

Novo `forms/context_scope_rule.xml` (uma linha do subform):

- `rule_type`: `type="list"` com duas opções, "Categoria" e "Item
  específico". Quando `extension` do context pai não é `com_content`,
  "Categoria" não deve aparecer — a verificação exata de como
  condicionar uma opção de um campo dentro de um subform a um valor do
  formulário *pai* precisa ser confirmada durante a implementação (o
  `showon` padrão do Joomla referencia campos do mesmo formulário, não
  necessariamente do pai); se não for viável de forma limpa, a
  alternativa é sempre mostrar as duas opções e validar no
  `ContextTable::check()` que regras `category` só são aceitas quando
  `extension = com_content`, rejeitando o save com erro claro.
- `rule_value`: quando `rule_type = category`, idealmente
  `type="category"` com `extension="com_content"` (seletor nativo em
  árvore); quando `rule_type = item`, campo numérico simples
  (`type="number"`) — um seletor de artigo em modal fica como melhoria
  posterior, não bloqueia esta sub-entrega.

## Avaliação em runtime

### `plg_content_lcomment`

Depois de obter `$commentContext` (já existe desde a Fase 1), antes de
decidir renderizar o bloco:

```php
$rules = json_decode((string) ($commentContext->params ?? ''), true)['scope_rules'] ?? [];
$categoryId = $extension === 'com_content' ? ($item->catid ?? null) : null;

if (!ScopeEvaluator::isItemIncluded($commentContext->scope_mode ?? 'all', $rules, $itemId, $categoryId)) {
    return;
}
```

`$item->catid` precisa ser confirmado contra o objeto real recebido em
`AfterDisplayEvent::getArgument('item')` durante a implementação (é um
campo padrão e bem estabelecido de artigos do `com_content`, mas deve ser
verificado, não assumido, seguindo a lição da Fase 1).

### `CommentController::save()` (site)

A mesma verificação deve acontecer **no servidor**, antes de aceitar a
submissão — não só a exibição do bloco depende disso. Isso estende o
`SubmissionPolicy` existente: `SubmissionRequest` ganha um novo campo
`itemIncluded: bool`, calculado pelo controller da mesma forma que o
plugin calcula (reaproveitando `ScopeEvaluator`), e `SubmissionPolicy`
passa a checar isso logo após o check de `contextActive` — mesma
prioridade de erro, nova chave de idioma
`COM_LCOMMENT_ERROR_ITEM_EXCLUDED`.

## Fluxo de erro

- `params` com JSON inválido/corrompido → `json_decode` falha
  silenciosamente para `null`; o código trata isso como lista de regras
  vazia (`?? []`), não lança exceção.
- `scope_mode = 'include'` com lista de regras vazia → **exclui tudo**
  deliberadamente (ponto 5 do `ScopeEvaluator` acima). Isso é
  surpreendente o suficiente para um admin que acabou de trocar o modo
  sem ainda ter adicionado uma regra, então o formulário deve mostrar um
  aviso inline (`description` do campo `scope_mode` ou um texto de ajuda
  junto ao subform) quando `include` estiver selecionado e nenhuma regra
  existir.
- Regra do tipo `category` presente num context cuja `extension` não é
  `com_content` → nunca casa (ver regra 3 do `ScopeEvaluator`); o
  formulário deve impedir isso na origem (ver seção anterior), mas o
  avaliador também não deve quebrar se acontecer mesmo assim.

## Fora de escopo nesta sub-entrega

Seletor de artigo em modal (fica como melhoria futura, `rule_value` para
itens é numérico simples por agora); suporte a categorias/itens de
extensões além de `com_content` nas regras do tipo `category` (continua
restrito a `com_content`, como já era a visão original); qualquer mudança
em reações, avaliações, respostas aninhadas ou assinaturas — essas são
sub-entregas separadas da Fase 2.

## Critério de aceite (verificação manual, Joomla 6.1.4 real)

1. Editar o Context `com_content`/`article`, trocar `scope_mode` para
   "Exceto", adicionar uma regra de categoria apontando para uma
   categoria existente com pelo menos um artigo. Salvar.
2. Abrir um artigo **dessa** categoria → bloco de comentários **não**
   aparece.
3. Abrir um artigo de **outra** categoria → bloco aparece normalmente.
4. Criar um artigo **novo** na categoria excluída → bloco não aparece
   nele também, sem precisar editar o Context de novo (confirma que a
   regra de categoria é dinâmica).
5. Tentar forjar um POST para `comment.save` usando o `item_id` de um
   artigo da categoria excluída → rejeitado com
   `COM_LCOMMENT_ERROR_ITEM_EXCLUDED`, mesmo sem o bloco ter sido
   renderizado.
6. Trocar `scope_mode` para "Apenas", sem adicionar nenhuma regra ainda
   → nenhum artigo mostra comentários; o aviso inline sobre lista vazia
   aparece no formulário.
7. Adicionar uma regra de item específico (`item_id` de um artigo
   pontual) em modo "Apenas" → só aquele artigo mostra comentários.

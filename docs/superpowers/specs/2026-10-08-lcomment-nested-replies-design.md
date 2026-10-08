# LComment — Fase 2b: Respostas Aninhadas Reais

## Contexto do projeto

Segunda sub-entrega da Fase 2 (Engajamento), depois do Escopo Granular de
Contexto (Fase 2a, já implementada e enviada). A Fase 1 já grava
`parent_id` em `#__lcomment_comments`, mas sempre `0` — a exibição é
sempre uma lista plana. Esta sub-entrega faz `parent_id` funcionar de
verdade: um visitante ou utilizador pode responder diretamente a um
comentário específico, e a exibição vira uma árvore.

Decisões já tomadas com o usuário:
- Profundidade de aninhamento **ilimitada** (sem limite de níveis nos
  dados).
- Se o comentário-pai estiver oculto (despublicado, na lixeira, ou
  pertencente a outro item), a resposta **também fica oculta**, sem
  promoção a comentário de topo — a regra se aplica em cascata por toda a
  cadeia de ancestrais, não só ao pai direto.
- UI de "Responder" via `<details>`/`<summary>` HTML, sem JavaScript
  novo.
- Indentação visual limitada a uma profundidade razoável (proposto: 5
  níveis), sem afetar a estrutura de dados nem a ordem das respostas mais
  profundas — só achata visualmente além desse ponto.

Este spec cobre **apenas** esta sub-entrega. As demais sub-entregas da
Fase 2 (reações, avaliações, assinaturas/notificações) têm seus próprios
specs futuros.

## Alvo técnico

Mesmo alvo da Fase 1/2a: Joomla 6.1.4 real (ambiente de validação ao
vivo), PHP 8.1+. Qualquer comportamento do framework Joomla envolvido
deve ser verificado contra o código-fonte real (`joomla-cms` 5.4-dev no
GitHub) antes de ser assumido, mesma disciplina das fases anteriores.

## Modelo de dados

Nenhuma mudança de schema. `parent_id` (já existe, `INT UNSIGNED NOT NULL
DEFAULT 0`) passa a ser gravado com o id do comentário-pai quando a
submissão é uma resposta.

## `CommentTreeBuilder` (classe de domínio pura, testável)

Mesmo padrão de `ScopeEvaluator`/`SubmissionPolicy`: PHP puro, sem
dependência do Joomla, sem o guard `_JEXEC`, testável via PHPUnit.

```
Lcsilva\Component\Lcomment\Administrator\Service\CommentTreeBuilder::build(
    array $flatComments  // cada item é um objeto/array com pelo menos ->id e ->parent_id
): array                 // lista de nós raiz, cada nó = ['comment' => ..., 'replies' => [...]]
```

Regras:

1. `$flatComments` já vem filtrado por visibilidade (mesma regra de
   `CommentModel::getItemsFor()` da Fase 1: publicado, ou pendente do
   próprio autor) — o builder não decide visibilidade de estado, só
   decide visibilidade estrutural (se o pai existe no conjunto recebido).
2. Um comentário com `parent_id = 0` é candidato a raiz.
3. Um comentário com `parent_id != 0` só entra na árvore se seu pai
   **também** estiver presente no conjunto recebido (e, recursivamente,
   se o pai do pai também estiver, e assim por diante até a raiz). Se a
   cadeia quebrar em qualquer ponto, o comentário inteiro (e todos os
   seus próprios descendentes) fica de fora — nunca promovido a raiz,
   nunca exibido "órfão".
4. Defensivo contra dados corrompidos: um `parent_id` que aponta para um
   id inexistente no conjunto, ou uma referência circular (que não deve
   ocorrer em uso normal, já que `parent_id` só é gravado na criação e
   nunca editado depois — ver "Fora de escopo"), nunca deve causar loop
   infinito nem estourar a pilha de chamadas. O algoritmo processa cada
   id no máximo uma vez.
5. A ordem dentro de cada nível é cronológica (mesma ordenação `created
   ASC` que `getItemsFor()` já aplica à lista plana).

## Submissão de respostas

### Formulário (`layouts/comment.php`)

- Cada comentário renderizado ganha um link "Responder" dentro de um
  `<details><summary>Responder</summary>...</details>` contendo uma cópia
  do formulário de comentário, com um campo oculto adicional
  `parent_id` = id desse comentário.
- O formulário de nível superior (fora de qualquer comentário) continua
  enviando `parent_id = 0`, como hoje.
- Renderização recursiva: o layout desenha os filhos de cada comentário
  chamando a si mesmo (ou um sub-layout) para cada nó da árvore
  devolvida por `CommentTreeBuilder::build()`.
- Indentação visual via uma classe CSS que usa o mínimo entre a
  profundidade real e 5 (ex.: `lcomment-depth-1` até `lcomment-depth-5`,
  sendo que qualquer profundidade maior reusa `lcomment-depth-5`) — a
  profundidade real continua guiando a estrutura HTML/aninhamento, só a
  indentação visual é limitada.

### `CommentController::save()` (validação server-side)

Novo parâmetro lido do POST: `parent_id` (inteiro, padrão `0` se ausente
ou não-numérico — nunca falha por isso, só vira comentário de topo).

Quando `parent_id != 0`, validação nova antes de aceitar a submissão: o
comentário referenciado precisa existir, **não** estar na lixeira
(`state != -2`), e pertencer ao **mesmo** `extension + view + item_id` do
comentário sendo escrito. Isso usa uma nova consulta simples (algo como
`CommentModel::parentBelongsToItem(int $parentId, string $extension,
string $view, int $itemId): bool`) — framework, sem teste automatizado
possível aqui, mesmo padrão de `getCategoryId()` na Fase 2a.

Esta validação é exatamente do mesmo tipo que já fazemos para
`item_id`/escopo desde a Fase 1/2a: nunca confiar que o `parent_id`
escondido no formulário renderizado é o único caminho de entrada — um
POST forjado apontando para um comentário de outro artigo precisa ser
rejeitado no servidor, mesmo que a UI nunca tivesse oferecido essa opção.
Erro novo: `COM_LCOMMENT_ERROR_INVALID_PARENT`.

Essa validação estende `SubmissionPolicy` da mesma forma que o
`itemIncluded` da Fase 2a: `SubmissionRequest` ganha um campo
`parentValid: bool` (default `true`), checado logo após o check de
`itemIncluded` e antes do check de visitantes — mesma prioridade de
erro que as checagens de contexto/escopo já seguem.

## Fluxo de erro

- `parent_id` ausente, vazio ou não-numérico no POST → tratado como `0`
  (comentário de topo), nunca bloqueia a submissão.
- `parent_id` aponta para um comentário que não existe, está na lixeira,
  ou pertence a outro `item_id`/`extension`/`view` → rejeitado com
  `COM_LCOMMENT_ERROR_INVALID_PARENT`.
- `parent_id` aponta para um comentário **pendente** (ainda não
  moderado, do mesmo item) → **permitido** submeter a resposta
  normalmente; a visibilidade da resposta na árvore já é resolvida
  naturalmente pela regra em cascata do `CommentTreeBuilder` (a resposta
  só aparece quando o pai também estiver visível para quem está vendo a
  página).
- Dados corrompidos/inconsistentes no `CommentTreeBuilder` (ciclo,
  `parent_id` referenciando um id fora do conjunto) → nunca lança
  exceção nem entra em loop infinito; o comentário problemático
  simplesmente não aparece na árvore (ver regra 4 acima).

## Fora de escopo nesta sub-entrega

Edição de comentários existentes (então `parent_id` é sempre imutável
após a criação, o que é parte de por que ciclos não ocorrem em uso
normal); limite de profundidade aplicado no servidor (threads
patologicamente profundas — milhares de níveis — não são impedidas,
apenas consideradas improváveis em uso real; se isso virar um problema
real, entra como endurecimento anti-abuso numa fase futura, mesmo
raciocínio já usado para `item_id` inexistente na Fase 1); indicador de
"respondendo a" na listagem do admin (`Components → LComment →
Comments` continua mostrando linhas; mostrar a relação pai/filho ali
fica como melhoria futura, não bloqueia esta entrega); qualquer mudança
em reações, avaliações ou assinaturas/notificações — essas são outras
sub-entregas da Fase 2.

## Critério de aceite (verificação manual, Joomla 6.1.4 real)

1. Num artigo com comentários existentes, clicar "Responder" num
   comentário, preencher e enviar.
   Expect: a resposta aparece aninhada visualmente sob o comentário
   correto, não na lista principal.
2. Responder a uma resposta (2º nível).
   Expect: aninha corretamente sob a resposta, não sob o comentário
   original.
3. Com moderação ativa no Context, enviar uma resposta como visitante.
   Expect: fica pendente (mesmo badge "Aguarda moderação" já existente),
   publicar no admin faz aparecer na posição aninhada correta.
4. Despublicar (ou mover para a lixeira) um comentário que tem respostas
   publicadas.
   Expect: tanto o comentário quanto todas as suas respostas (diretas e
   indiretas) somem do artigo.
5. Forjar um POST para `comment.save` com `parent_id` apontando para um
   comentário de **outro** artigo.
   Expect: rejeitado com a mensagem de erro de pai inválido.
6. Criar uma thread com mais de 5 níveis de profundidade.
   Expect: a estrutura de respostas continua correta (cada resposta sob
   seu pai real), mas a indentação visual para de aumentar a partir do
   5º nível.

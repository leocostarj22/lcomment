# LComment — Fase 1: Fundação

## Contexto do projeto

LComment é uma extensão Joomla (componente + plugins) que permite comentários em
qualquer conteúdo do site, com um conjunto amplo de recursos (reações, avaliações,
workflow de moderação, assinaturas/notificações, BBCode, multilíngue, denúncias,
blacklist, migração de outras extensões, anti-spam, GDPR, avatares, temas, etc.).

Dado o tamanho do escopo completo, o projeto foi decomposto em 5 fases, cada uma
com seu próprio spec → plano → implementação:

1. **Fundação** (este documento) — estrutura do pacote, schema de BD, integração
   universal via eventos de conteúdo, CRUD básico de comentários, admin mínimo.
2. **Engajamento** — reações, avaliações, aninhamento real de respostas,
   assinaturas/notificações, visitante vs. registrado (regras avançadas),
   escopo granular de contexto (incluir/excluir comentários por item
   específico e, para `com_content`, por categoria).
3. **Moderação & Segurança** — ACL granular por grupo, workflow de publicação,
   blacklist, denúncias, anti-spam/captcha, anti-flood, filtro de palavrões.
4. **Apresentação & Conteúdo** — BBCode, emojis/figurinhas, templates/temas e
   cores, avatares (redes sociais + Gravatar), multilíngue avançado.
5. **Avançado** — migração de extensões terceiras, busca em tempo real,
   conformidade RGPD (anonimização/exclusão), Web Services API, fallback de
   injeção universal (buffer) para extensões sem eventos de conteúdo.

Este spec cobre **apenas a Fase 1**.

## Alvo técnico

- Joomla 5.x estável (PHP 8.1+) como baseline de desenvolvimento e testes.
- Código escrito seguindo as convenções já publicadas para compatibilidade com
  Joomla 6.x (namespaces PSR-4, Web Asset Manager, evitar APIs marcadas como
  deprecated em 5.x), para minimizar retrabalho quando 6.x for lançado
  estável. Não há garantia de testes reais em Joomla 6 nesta fase, pois ainda
  não há release estável pública.
- Namespace PSR-4: `Lcsilva\Component\Lcomment` (componente),
  `Lcsilva\Plugin\Content\Lcomment` e `Lcsilva\Plugin\System\Lcomment`
  (plugins).
- Autor nos manifestos: `leocostadeveloper`. Sem atribuição a terceiros.

## Empacotamento

Um único pacote instalável `pkg_lcomment` contendo:

- `com_lcomment` — componente (admin + site).
- `plg_content_lcomment` — plugin de grupo *content*, habilitado por padrão.
- `plg_system_lcomment` — plugin de grupo *system*, habilitado por padrão
  (carrega assets CSS/JS; base para fallback de injeção universal em fases
  futuras).

Estrutura de pastas (nível raiz do projeto, cada subpasta é o conteúdo que
será zipado para seu respectivo instalador, e o `pkg_lcomment` referencia os
três pacotes filhos):

```
packages/
  pkg_lcomment/pkg_lcomment.xml
  com_lcomment/
    com_lcomment.xml
    script.php
    admin/
      src/Controller/
      src/Model/
      src/View/
      src/Table/
      services/provider.php
      sql/install/mysql/install.sql
      sql/uninstall/mysql/uninstall.sql
      language/en-GB/
      language/pt-PT/
    site/
      src/Controller/
      src/Model/
      src/View/
      services/provider.php
      language/en-GB/
      language/pt-PT/
  plg_content_lcomment/
    lcomment.xml
    services/provider.php
    src/Extension/Lcomment.php
    language/en-GB/
    language/pt-PT/
  plg_system_lcomment/
    lcomment.xml
    services/provider.php
    src/Extension/Lcomment.php
    language/en-GB/
    language/pt-PT/
```

## Banco de dados

### `#__lcomment_comments`

| Campo | Tipo | Notas |
|---|---|---|
| id | INT UNSIGNED PK AUTO_INCREMENT | |
| extension | VARCHAR(100) | ex.: `com_content` |
| view | VARCHAR(100) | ex.: `article` |
| item_id | INT UNSIGNED | id do item comentado (ex.: id do artigo) |
| parent_id | INT UNSIGNED DEFAULT 0 | reservado para respostas (fase 2); fase 1 sempre 0 |
| user_id | INT UNSIGNED NULL | null = visitante |
| guest_name | VARCHAR(150) NULL | preenchido apenas se visitante |
| guest_email | VARCHAR(254) NULL | preenchido apenas se visitante |
| comment_text | TEXT | texto puro nesta fase (sem BBCode/HTML) |
| state | TINYINT DEFAULT 0 | 0=pendente, 1=publicado, -2=lixeira |
| language | CHAR(7) DEFAULT '*' | código de idioma Joomla |
| ip | VARCHAR(45) NULL | reservado para blacklist (fase 3) |
| created | DATETIME | |
| modified | DATETIME NULL | |

Índice composto em `(extension, view, item_id, state)` para a query principal
de listagem.

### `#__lcomment_contexts`

| Campo | Tipo | Notas |
|---|---|---|
| id | INT UNSIGNED PK AUTO_INCREMENT | |
| extension | VARCHAR(100) | ex.: `com_content` |
| view | VARCHAR(100) | ex.: `article` |
| published | TINYINT DEFAULT 1 | permite desativar sem apagar |
| moderation | TINYINT DEFAULT 1 | 1 = comentários novos entram como pendentes |
| params | TEXT (JSON) | reservado para opções de fases futuras (não lido nesta fase) |

Chave única em `(extension, view)`.

## Componente — `com_lcomment`

### Backend (admin)

- **Contexts**: lista/gestão de contextos (`extension`, `view`, `published`,
  `moderation`). CRUD simples via MVC padrão Joomla (List view + Item/Form
  view). Sem seletor dinâmico de extensões instaladas nesta fase — o admin
  digita `extension`/`view` manualmente (ex.: `com_content` / `article`); um
  seletor com descoberta automática de views fica para fase futura.
- **Comments**: lista de comentários com filtros por estado/contexto/busca
  por texto, ações em lote de publicar/despublicar/mover para lixeira
  (padrão Joomla `JControllerAdmin`/`AdminModel`). ACL nesta fase é binária:
  usa a permissão padrão `core.manage` do componente (ACL granular por grupo
  de usuário fica para Fase 3).
- **Options**: formulário de configuração global do componente (ex.:
  comprimento mínimo/máximo do texto do comentário). Mantido mínimo nesta
  fase — só os campos necessários para o fluxo básico funcionar.

### Site (frontend)

- **Controller `comment`**: action `save` — recebe POST do formulário,
  valida token CSRF, valida texto (obrigatório, tamanho entre min/max
  configurado), valida contexto ativo, determina autor (usuário logado via
  `Factory::getApplication()->getIdentity()` ou nome/e-mail de visitante se
  anônimo e contexto permite), grava com `state` conforme `moderation` do
  contexto, redireciona (`Factory::getApplication()->enqueueMessage` +
  redirect para a URL de origem).
- **Model `Comments`**: lista comentários publicados (e pendentes do próprio
  usuário, se logado) de um dado `extension+view+item_id`, ordenados por
  `created DESC`.
- **View/layout**: layout reutilizável (`layouts/comments/default.php` ou
  `tmpl/comment/default.php`) que renderiza lista + formulário, sobrescrevível
  via template overrides, como qualquer layout Joomla padrão.

Permitir comentários de visitantes é uma opção do componente nesta fase
(global, não por contexto — granularidade por contexto fica para fase 2);
nenhuma conta é exigida para publicar quando habilitado.

## Plugins

### `plg_content_lcomment` (grupo *content*)

Evento `onContentAfterDisplay($context, &$item, &$params, $limitstart)`:

1. Deriva `extension`/`view` a partir do `$context` (ex.: `com_content.article`
   → `extension=com_content`, `view=article`).
2. Consulta `CommentService::isContextActive($extension, $view)` (cacheada em
   memória por request).
3. Se ativo, resolve `item_id` (tipicamente `$item->id`) e retorna o HTML
   renderizado do layout de comentários (lista + formulário) para ser
   anexado à saída do conteúdo.

Este é o mecanismo-padrão de integração do Joomla para este tipo de extensão
(mesma convenção usada por JComments e extensões de botões de partilha) e
funciona automaticamente com qualquer extensão que dispare
`onContentAfterDisplay` — incluindo com_content nativamente. Extensões que
não disparam esse evento não terão integração automática nesta fase (ver
fallback de buffer na Fase 5).

### `plg_system_lcomment` (grupo *system*)

Evento `onAfterRender` (ou `onBeforeCompileHead`, a confirmar durante
implementação): registra CSS/JS do LComment via `WebAssetManager` apenas
quando pelo menos um bloco de comentários foi renderizado na página atual
(evita carregar assets em páginas sem comentários). Nesta fase não há lógica
de fallback — a estrutura do plugin já existe para a Fase 5 adicionar o modo
de injeção por buffer sem precisar criar um novo plugin.

## Fluxo de erro

- Submissão sem texto ou fora dos limites de tamanho → mensagem de erro
  Joomla padrão (`enqueueMessage` tipo `error`), formulário repreenchido via
  sessão (padrão Joomla `getUserStateFromRequest`), sem perda de dados
  digitados.
- Token CSRF inválido → rejeitado pelo `Session::checkToken()` padrão Joomla
  (comportamento nativo, sem tratamento customizado).
- Contexto não encontrado/inativo → controller recusa o save e loga aviso
  (não exibe formulário para esse contexto desde o início, então este caso
  só ocorre em POST manual/forjado).

## Fora de escopo nesta fase

Reações, avaliações, respostas aninhadas reais, assinaturas/notificações,
BBCode, emojis, temas/cores customizáveis, blacklist, denúncias, anti-spam
avançado (captcha/anti-flood/filtro de palavrões), multilíngue avançado
(apenas o campo `language` é gravado, sem lógica de filtragem ainda),
migração de outras extensões, GDPR, avatares, Web Services API, fallback de
injeção por buffer. Todos dependem da base de dados e estrutura de plugins
desta fase.

## Critério de aceite (verificação manual)

Não há suíte automatizada de UI para extensões Joomla nesta fase. Aceite por
checklist manual em ambiente Joomla 5.x real:

1. Instalar `pkg_lcomment` sem erros.
2. No backend, cadastrar contexto `com_content` / `article`, com moderação
   habilitada.
3. Visitar um artigo no frontend → bloco de comentários aparece com
   formulário.
4. Publicar comentário como usuário logado → aparece como pendente (estado
   0) na listagem admin; publicar manualmente lá → aparece no frontend.
5. Habilitar comentários de visitante na config global → publicar comentário
   sem login, preenchendo nome/e-mail → mesmo fluxo de moderação.
6. Desativar moderação no contexto → novo comentário aparece já publicado
   sem intervenção do admin.
7. Despublicar/mover comentário para lixeira no admin → desaparece do
   frontend.
8. Desabilitar o contexto no admin → bloco de comentários desaparece do
   artigo.

# LComment — Fase 2c: Reações — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let visitors and registered users react to any comment (or reply) with one of 6 fixed emojis, one active reaction per person per comment, submitted via progressively-enhanced JavaScript that falls back to a normal page reload.

**Architecture:** A new `#__lcomment_reactions` table stores one row per (comment, identity) pair. A pure-PHP `ReactionToggle` domain class decides insert/update/delete from the existing and requested reaction type. `CommentModel::getReactionsFor()` aggregates counts and "mine" per comment for display. `ReactionController::save()` validates, persists via `ReactionToggle`, and replies either with a redirect (plain form POST) or a JSON body (when the request carries the `X-LComment-Ajax` header), using `$app->close()` after every JSON response so Joomla's normal page-render lifecycle never appends HTML after it. The layout renders 6 small real `<form>`s per comment (no-JS fallback) and `lcomment-reactions.js` progressively intercepts their submit to use `fetch` instead.

**Tech Stack:** PHP 8.1+, Joomla 6.1.4 MVC, PHPUnit 10.5 for the pure-PHP domain class, vanilla JavaScript (no framework, no new dependency) for the progressive-enhancement layer.

**Spec:** `docs/superpowers/specs/2026-10-08-lcomment-reactions-design.md`

## Global Constraints

- Target: Joomla 6.1.4 real environment, PHP 8.1+. Every Joomla framework
  API used below has already been verified against real source for this
  plan (not guessed) — see the per-task notes citing the exact method
  signatures confirmed.
- 6 fixed reaction types, not configurable: `like`, `love`, `haha`, `wow`,
  `sad`, `angry`. The authoritative list lives in
  `ReactionToggle::VALID_TYPES`.
- One active reaction per identity per comment (exclusive, Facebook-style):
  insert if none exists, delete if clicking the already-active type again,
  update (swap) if clicking a different type.
- Guest identity for dedup purposes is `(guest_ip, guest_session_id)` —
  deliberately "good enough", not abuse-proof (same reasoning already
  applied to the `ip` column reserved for Fase 3 blacklist work).
  `guest_session_id` is `VARCHAR(192)`, matching the width Joomla's own
  `#__session.session_id` column uses (`varbinary(192)`, confirmed in
  `installation/sql/mysql/base.sql`).
- Reacting works with JavaScript disabled (plain POST + redirect) and is
  enhanced, not replaced, by JavaScript (fetch, in-place DOM update). The
  server-side validation and persistence logic is identical either way —
  only the response format differs (redirect vs. JSON).
- No schema change to any existing table. New table only:
  `#__lcomment_reactions`. Package version bumps `0.2.0` → `0.3.0`.
- `ReactionToggle` is pure PHP (no `_JEXEC` guard, no Joomla dependency),
  testable via PHPUnit — same pattern as `CommentTreeBuilder`/
  `ScopeEvaluator`/`SubmissionPolicy`. `CommentModel::getReactionsFor()`
  and `ReactionController` are Joomla framework code with no automated
  test harness available in this project (same precedent as
  `CommentModel::getCategoryId()`/`parentBelongsToItem()`) — verified by
  manual checks instead.

## Review Focus

- Clicking the already-active reaction again must **remove** it (toggle
  off), never create a second row for the same identity on the same
  comment. Pinned by a `ReactionToggleTest` case (Task 2) at the domain
  layer; the full-stack behavior (DB lookup + decide + apply) is only
  exercised by the spec's manual acceptance criterion 3.
- An AJAX response (success **and** error) must be pure JSON with nothing
  appended after it — if `ReactionController` ever omits the `$app->close()`
  call after echoing JSON, Joomla's normal page-render lifecycle would
  append a full HTML document after the JSON body, breaking
  `response.json()` in the browser silently. Not in the spec's 7
  acceptance criteria as written; Task 4 adds an explicit manual check
  (inspect the raw response body in devtools), and the final manual
  verification section repeats it.
- Reacting to a comment that is **not published** (pending moderation or
  trashed) must be rejected — only `state = 1` comments are reactable.
  Not in the spec's 7 acceptance criteria as written; Task 4 adds an
  explicit manual check, and the final manual verification section
  repeats it.
- A forged `reaction_type` outside the 6 fixed values must be rejected
  server-side even though the rendered UI never offers one. Pinned by the
  spec's manual acceptance criterion 6, and by `ReactionToggleTest`'s
  assertion on the exact content of `ReactionToggle::VALID_TYPES` (Task 2)
  — a typo or accidental edit to that list would be caught immediately.
- A failed `fetch` (network error, or a non-2xx response) must leave the
  on-page counts and highlight exactly as they were — never guess a new
  state client-side. Not in the spec's 7 acceptance criteria as written;
  Task 6 adds an explicit manual check (simulate a dropped request via
  devtools' network throttling/offline mode).

---

### Task 1: Schema, migration, and package version

**Files:**
- Modify: `com_lcomment/com_lcomment.xml`
- Modify: `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`
- Modify: `com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql`
- Create: `com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.3.0.sql`

**Interfaces:**
- Consumes: nothing.
- Produces: the `#__lcomment_reactions` table, consumed by every later task
  (`comment_id`, `user_id`, `guest_ip`, `guest_session_id`,
  `reaction_type`, `created` columns, exactly as specified below).

- [ ] **Step 1: Bump the package version**

In `com_lcomment/com_lcomment.xml`, change:

```xml
    <version>0.2.0</version>
```

to:

```xml
    <version>0.3.0</version>
```

- [ ] **Step 2: Add the new table to the fresh-install schema**

Append to `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`:

```sql

CREATE TABLE IF NOT EXISTS `#__lcomment_reactions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comment_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `guest_ip` VARCHAR(45) NULL,
    `guest_session_id` VARCHAR(192) NULL,
    `reaction_type` VARCHAR(20) NOT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_comment_id` (`comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 3: Add the same table to the upgrade path**

Create `com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.3.0.sql`:

```sql
CREATE TABLE IF NOT EXISTS `#__lcomment_reactions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comment_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `guest_ip` VARCHAR(45) NULL,
    `guest_session_id` VARCHAR(192) NULL,
    `reaction_type` VARCHAR(20) NOT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_comment_id` (`comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 4: Add the table to the uninstall script**

In `com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql`, add a third line:

```sql
DROP TABLE IF EXISTS `#__lcomment_comments`;
DROP TABLE IF EXISTS `#__lcomment_contexts`;
DROP TABLE IF EXISTS `#__lcomment_reactions`;
```

- [ ] **Step 5: Verify the fresh-install and upgrade schemas are identical**

Run: `diff <(sed -n '/lcomment_reactions/,/utf8mb4;/p' com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql) com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.3.0.sql`
Expected: no output (the two `CREATE TABLE` blocks are byte-identical, so a fresh install and an upgrade end up with the same table).

- [ ] **Step 6: Verify the version bump**

Run: `grep -n '<version>' com_lcomment/com_lcomment.xml`
Expected: `8:    <version>0.3.0</version>`

- [ ] **Step 7: Run the full test suite to confirm nothing else broke**

Run: `vendor/bin/phpunit`
Expected: PASS — this task touches no PHP file, but confirm the suite is still green before building on top of it.

- [ ] **Step 8: Commit**

```bash
git add com_lcomment/com_lcomment.xml com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.3.0.sql
git commit -m "feat(lcomment): add lcomment_reactions table, bump package to 0.3.0"
```

---

### Task 2: `ReactionToggle` domain class

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Service/ReactionToggle.php`
- Test: `tests/Service/ReactionToggleTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `Lcsilva\Component\Lcomment\Administrator\Service\ReactionToggle::VALID_TYPES`
  (the fixed `array` `['like', 'love', 'haha', 'wow', 'sad', 'angry']`) and
  `ReactionToggle::decide(?string $existingType, string $requestedType): array`
  returning `['action' => 'insert'|'update'|'delete', 'type' => ?string]`.
  Consumed by Task 4 (`ReactionController`, for both the whitelist check
  and the decision) and Task 5 (layout, to enumerate the 6 buttons in a
  fixed order).

- [ ] **Step 1: Write the failing tests**

Create `tests/Service/ReactionToggleTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\ReactionToggle;
use PHPUnit\Framework\TestCase;

final class ReactionToggleTest extends TestCase
{
    public function testNoExistingReactionInsertsTheRequestedType(): void
    {
        self::assertSame(
            ['action' => 'insert', 'type' => 'like'],
            ReactionToggle::decide(null, 'like')
        );
    }

    public function testClickingTheSameActiveReactionRemovesIt(): void
    {
        self::assertSame(
            ['action' => 'delete', 'type' => null],
            ReactionToggle::decide('like', 'like')
        );
    }

    public function testClickingADifferentReactionSwapsToTheNewType(): void
    {
        self::assertSame(
            ['action' => 'update', 'type' => 'love'],
            ReactionToggle::decide('like', 'love')
        );
    }

    public function testValidTypesListsExactlyTheSixFixedReactions(): void
    {
        self::assertSame(
            ['like', 'love', 'haha', 'wow', 'sad', 'angry'],
            ReactionToggle::VALID_TYPES
        );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Service/ReactionToggleTest.php`
Expected: FAIL — `Class "Lcsilva\Component\Lcomment\Administrator\Service\ReactionToggle" not found`.

- [ ] **Step 3: Implement `ReactionToggle`**

Create `com_lcomment/administrator/components/com_lcomment/src/Service/ReactionToggle.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class ReactionToggle
{
    /**
     * The fixed, non-configurable set of reaction types this sub-delivery
     * supports. This is the authoritative whitelist — callers (controller
     * validation, layout rendering) must check a requested type against
     * this list themselves; decide() below does not revalidate it.
     */
    public const VALID_TYPES = ['like', 'love', 'haha', 'wow', 'sad', 'angry'];

    /**
     * @return array{action: 'insert'|'update'|'delete', type: ?string}
     */
    public static function decide(?string $existingType, string $requestedType): array
    {
        if ($existingType === null) {
            return ['action' => 'insert', 'type' => $requestedType];
        }

        if ($existingType === $requestedType) {
            return ['action' => 'delete', 'type' => null];
        }

        return ['action' => 'update', 'type' => $requestedType];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Service/ReactionToggleTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/ReactionToggle.php tests/Service/ReactionToggleTest.php
git commit -m "feat(lcomment): add ReactionToggle domain class"
```

---

### Task 3: `CommentModel::getReactionsFor()`

**Files:**
- Modify: `com_lcomment/components/com_lcomment/src/Model/CommentModel.php`

**Interfaces:**
- Consumes: the `#__lcomment_reactions` table from Task 1.
- Produces: `CommentModel::getReactionsFor(array $commentIds): array`
  returning `[commentId => ['counts' => ['like' => 3, ...], 'mine' => 'like'|null]]`
  — every id passed in is present in the result, defaulting to
  `['counts' => [], 'mine' => null]` when it has no reactions. Consumed by
  Task 4 (`ReactionController`, for the AJAX JSON response) and Task 5
  (plugin wiring, for the page render). No PHPUnit coverage possible (same
  precedent as `getCategoryId()`/`parentBelongsToItem()`): `whereIn()`,
  `group()` with an array, and `select()` with an array have been verified
  against the real `joomla-framework/database` 3.x-dev source
  (`QueryInterface::whereIn(string $keyName, array $keyValues, $dataType = ParameterType::INTEGER)`,
  `DatabaseQuery::group(array|string $columns)`,
  `DatabaseQuery::select(array|string $columns)`) before being used here.

- [ ] **Step 1: Add `getReactionsFor()`**

In `com_lcomment/components/com_lcomment/src/Model/CommentModel.php`, add this method after `parentBelongsToItem()` (before the class's closing `}`):

```php
    public function getReactionsFor(array $commentIds): array
    {
        $result = [];

        foreach ($commentIds as $commentId) {
            $result[(int) $commentId] = ['counts' => [], 'mine' => null];
        }

        if ($commentIds === []) {
            return $result;
        }

        $db = $this->getDatabase();

        $countsQuery = $db->getQuery(true)
            ->select([
                $db->quoteName('comment_id'),
                $db->quoteName('reaction_type'),
                'COUNT(*) AS ' . $db->quoteName('total'),
            ])
            ->from($db->quoteName('#__lcomment_reactions'))
            ->whereIn($db->quoteName('comment_id'), $commentIds)
            ->group([$db->quoteName('comment_id'), $db->quoteName('reaction_type')]);

        $db->setQuery($countsQuery);

        foreach ($db->loadObjectList() as $row) {
            $result[(int) $row->comment_id]['counts'][(string) $row->reaction_type] = (int) $row->total;
        }

        $app = \Joomla\CMS\Factory::getApplication();
        $user = $app->getIdentity();

        $mineQuery = $db->getQuery(true)
            ->select([$db->quoteName('comment_id'), $db->quoteName('reaction_type')])
            ->from($db->quoteName('#__lcomment_reactions'))
            ->whereIn($db->quoteName('comment_id'), $commentIds);

        if ($user && $user->id > 0) {
            $mineQuery->where($db->quoteName('user_id') . ' = :userId')
                ->bind(':userId', $user->id, ParameterType::INTEGER);
        } else {
            $guestIp = $app->getInput()->server->getString('REMOTE_ADDR', '');
            $guestSessionId = $app->getSession()->getId();

            $mineQuery->where($db->quoteName('guest_ip') . ' = :guestIp')
                ->where($db->quoteName('guest_session_id') . ' = :guestSessionId')
                ->bind(':guestIp', $guestIp, ParameterType::STRING)
                ->bind(':guestSessionId', $guestSessionId, ParameterType::STRING);
        }

        $db->setQuery($mineQuery);

        foreach ($db->loadObjectList() as $row) {
            $result[(int) $row->comment_id]['mine'] = (string) $row->reaction_type;
        }

        return $result;
    }
```

- [ ] **Step 2: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/components/com_lcomment/src/Model/CommentModel.php && vendor/bin/phpunit`
Expected: `No syntax errors detected...` followed by PASS on the full suite (this task adds no new automated tests of its own — framework/DB code, see Global Constraints).

- [ ] **Step 3: Commit**

```bash
git add com_lcomment/components/com_lcomment/src/Model/CommentModel.php
git commit -m "feat(lcomment): add CommentModel::getReactionsFor for aggregated reaction counts"
```

---

### Task 4: `ReactionController::save()`

**Files:**
- Create: `com_lcomment/components/com_lcomment/src/Controller/ReactionController.php`
- Modify: `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`

**Interfaces:**
- Consumes: `ReactionToggle::VALID_TYPES`/`ReactionToggle::decide()` (Task 2);
  `CommentModel::getReactionsFor()` (Task 3, for the AJAX JSON response body).
- Produces: task `reaction.save`, POST fields `comment_id` (int),
  `reaction_type` (string), `return` (base64 URL, same convention as
  `comment.save`). Request header `X-LComment-Ajax` (any non-empty value)
  switches the response from redirect to JSON. Success JSON body:
  `{"counts": {...}, "mine": "like"|null}`. Error JSON body (HTTP 400):
  `{"error": "<language key>"}`. Consumed by Task 5 (the form `action=`
  and hidden field names) and Task 6 (`lcomment-reactions.js`, the header
  name and both JSON shapes).
- No PHPUnit coverage possible (framework code, see Global Constraints).
  Every Joomla API below has been verified against real source before
  being used: `$app->setHeader($name, $value, $replace)` +
  `$app->sendHeaders()` (confirmed in `joomla-framework/application`
  3.x-dev `AbstractWebApplication.php` — a header named `'status'`,
  compared case-insensitively, is the documented way to set the HTTP
  response status line); `$app->close()` is literally `exit($code);`
  (confirmed in `AbstractApplication.php`) — calling it right after
  `echo json_encode(...)` is what stops Joomla's normal page-render
  lifecycle from appending a full HTML document after the JSON, exactly
  as `$app->redirect()` already relies on internally for the non-AJAX
  path; `$app->getSession()->getId(): string` (confirmed in
  `joomla-framework/session` 3.x-dev `Session.php`); placing `:name`
  bind placeholders inside `set()`/`values()` (not just `where()`) works
  because `DatabaseDriver::execute()` prepares the *entire* query string
  through the native PDO/mysqli statement layer and only then calls
  `bindParam()` for each registered name — placement within the SQL text
  makes no difference to that mechanism (confirmed in
  `joomla-framework/database` 3.x-dev `DatabaseDriver.php`).

- [ ] **Step 1: Add the new language keys**

Append to `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_ERROR_INVALID_REACTION_TYPE="That reaction is not available."
COM_LCOMMENT_ERROR_INVALID_REACTION_TARGET="You cannot react to that comment."
```

Append to `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_ERROR_INVALID_REACTION_TYPE="Essa reação não está disponível."
COM_LCOMMENT_ERROR_INVALID_REACTION_TARGET="Não é possível reagir a esse comentário."
```

- [ ] **Step 2: Create `ReactionController`**

Create `com_lcomment/components/com_lcomment/src/Controller/ReactionController.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Lcsilva\Component\Lcomment\Administrator\Service\ReactionToggle;

final class ReactionController extends BaseController
{
    public function save(): bool
    {
        Session::checkToken('post') or die(Text::_('JINVALID_TOKEN'));

        $app = Factory::getApplication();
        $input = $app->getInput();
        $isAjax = $input->server->getString('HTTP_X_LCOMMENT_AJAX', '') !== '';
        $returnUrl = base64_decode($input->getBase64('return', ''));

        $commentId = $input->getInt('comment_id', 0);
        $reactionType = $input->getCmd('reaction_type', '');

        if (!\in_array($reactionType, ReactionToggle::VALID_TYPES, true)) {
            return $this->fail($app, $isAjax, $returnUrl, 'COM_LCOMMENT_ERROR_INVALID_REACTION_TYPE');
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        if (!$this->commentIsPublished($db, $commentId)) {
            return $this->fail($app, $isAjax, $returnUrl, 'COM_LCOMMENT_ERROR_INVALID_REACTION_TARGET');
        }

        $user = $app->getIdentity();
        $userId = $user && $user->id > 0 ? (int) $user->id : null;
        $guestIp = $userId === null ? $input->server->getString('REMOTE_ADDR', '') : '';
        $guestSessionId = $userId === null ? $app->getSession()->getId() : '';

        $existing = $this->findExisting($db, $commentId, $userId, $guestIp, $guestSessionId);
        $decision = ReactionToggle::decide($existing['type'] ?? null, $reactionType);

        $this->applyDecision($db, $decision, $existing['id'] ?? null, $commentId, $userId, $guestIp, $guestSessionId);

        if ($isAjax) {
            /** @var \Lcsilva\Component\Lcomment\Site\Model\CommentModel $model */
            $model = $this->getModel('Comment', 'Site');
            $reactions = $model->getReactionsFor([$commentId]);

            $this->respondJson($app, 200, $reactions[$commentId]);

            return true;
        }

        $app->redirect($returnUrl ?: 'index.php');

        return true;
    }

    private function fail($app, bool $isAjax, string $returnUrl, string $errorKey): bool
    {
        if ($isAjax) {
            $this->respondJson($app, 400, ['error' => $errorKey]);

            return false;
        }

        $app->enqueueMessage(Text::_($errorKey), 'error');
        $app->redirect($returnUrl ?: 'index.php');

        return false;
    }

    private function respondJson($app, int $status, array $payload): void
    {
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('status', $status, true);
        $app->sendHeaders();

        echo json_encode($payload);

        $app->close();
    }

    private function commentIsPublished(DatabaseInterface $db, int $commentId): bool
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__lcomment_comments'))
            ->where($db->quoteName('id') . ' = :commentId')
            ->where($db->quoteName('state') . ' = 1')
            ->bind(':commentId', $commentId, ParameterType::INTEGER);

        $db->setQuery($query);

        return (int) $db->loadResult() > 0;
    }

    /**
     * @return array{id: int, type: string}|array{}
     */
    private function findExisting(DatabaseInterface $db, int $commentId, ?int $userId, string $guestIp, string $guestSessionId): array
    {
        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('reaction_type')])
            ->from($db->quoteName('#__lcomment_reactions'))
            ->where($db->quoteName('comment_id') . ' = :commentId')
            ->bind(':commentId', $commentId, ParameterType::INTEGER);

        if ($userId !== null) {
            $query->where($db->quoteName('user_id') . ' = :userId')
                ->bind(':userId', $userId, ParameterType::INTEGER);
        } else {
            $query->where($db->quoteName('guest_ip') . ' = :guestIp')
                ->where($db->quoteName('guest_session_id') . ' = :guestSessionId')
                ->bind(':guestIp', $guestIp, ParameterType::STRING)
                ->bind(':guestSessionId', $guestSessionId, ParameterType::STRING);
        }

        $db->setQuery($query);

        $row = $db->loadAssoc();

        return $row !== null ? ['id' => (int) $row['id'], 'type' => (string) $row['reaction_type']] : [];
    }

    private function applyDecision(
        DatabaseInterface $db,
        array $decision,
        ?int $existingId,
        int $commentId,
        ?int $userId,
        string $guestIp,
        string $guestSessionId
    ): void {
        if ($decision['action'] === 'delete') {
            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__lcomment_reactions'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $existingId, ParameterType::INTEGER);

            $db->setQuery($query);
            $db->execute();

            return;
        }

        if ($decision['action'] === 'update') {
            $type = $decision['type'];

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__lcomment_reactions'))
                ->set($db->quoteName('reaction_type') . ' = :type')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':type', $type, ParameterType::STRING)
                ->bind(':id', $existingId, ParameterType::INTEGER);

            $db->setQuery($query);
            $db->execute();

            return;
        }

        $type = $decision['type'];
        $created = Factory::getDate()->toSql();
        $userIdType = $userId !== null ? ParameterType::INTEGER : ParameterType::NULL;
        $guestValueType = $userId === null ? ParameterType::STRING : ParameterType::NULL;
        $guestIpValue = $userId === null ? $guestIp : null;
        $guestSessionValue = $userId === null ? $guestSessionId : null;

        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__lcomment_reactions'))
            ->columns([
                $db->quoteName('comment_id'),
                $db->quoteName('user_id'),
                $db->quoteName('guest_ip'),
                $db->quoteName('guest_session_id'),
                $db->quoteName('reaction_type'),
                $db->quoteName('created'),
            ])
            ->values(':commentId, :userId, :guestIp, :guestSessionId, :type, :created')
            ->bind(':commentId', $commentId, ParameterType::INTEGER)
            ->bind(':userId', $userId, $userIdType)
            ->bind(':guestIp', $guestIpValue, $guestValueType)
            ->bind(':guestSessionId', $guestSessionValue, $guestValueType)
            ->bind(':type', $type, ParameterType::STRING)
            ->bind(':created', $created, ParameterType::STRING);

        $db->setQuery($query);
        $db->execute();
    }
}
```

- [ ] **Step 3: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/components/com_lcomment/src/Controller/ReactionController.php && vendor/bin/phpunit`
Expected: `No syntax errors detected...` followed by PASS on the full suite.

- [ ] **Step 4: Commit**

```bash
git add com_lcomment/components/com_lcomment/src/Controller/ReactionController.php com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini
git commit -m "feat(lcomment): add ReactionController::save with dual redirect/JSON response"
```

- [ ] **Step 5: Manual check to perform later, during live Joomla verification (not automatable here)**

When this reaches the live Joomla acceptance pass (after Task 6), in addition to the spec's 7 criteria, also check: (a) react to a comment that is still pending moderation, or trashed — expect `COM_LCOMMENT_ERROR_INVALID_REACTION_TARGET`, no row created; (b) open the browser's Network tab, trigger a reaction via JS, and inspect the raw response body of the `reaction.save` request — expect it to be exactly the JSON object, with no HTML before or after it.

---

### Task 5: Plugin wiring and reaction buttons in the layout

**Files:**
- Modify: `plg_content_lcomment/src/Extension/Lcomment.php`
- Modify: `com_lcomment/components/com_lcomment/layouts/comment.php`
- Modify: `com_lcomment/media/css/lcomment.css`

**Interfaces:**
- Consumes: `CommentModel::getReactionsFor()` (Task 3, shape
  `[commentId => ['counts' => [...], 'mine' => ?string]]`);
  `ReactionToggle::VALID_TYPES` (Task 2, to enumerate the 6 buttons in a
  fixed order); the `reaction.save` task name and POST field names
  `comment_id`/`reaction_type`/`return` (Task 4).
- Produces: a `.lcomment-reactions` container per comment (root or reply)
  with `data-comment-id`, each holding a `.lcomment-reaction-form` (one
  per emoji, carrying class `lcomment-reaction-active` when it is the
  viewer's current reaction) wrapping a `.lcomment-reaction-button`
  (`.lcomment-reaction-emoji` + optional `.lcomment-reaction-count`).
  Consumed by Task 6 (`lcomment-reactions.js`, which selects these exact
  class names).

- [ ] **Step 1: Pass reaction data from the plugin into the layout**

In `plg_content_lcomment/src/Extension/Lcomment.php`, change:

```php
        $items = $model->getItemsFor($extension, $view, $itemId);
        $returnUrl = Uri::getInstance()->toString();
```

to:

```php
        $items = $model->getItemsFor($extension, $view, $itemId);
        $reactions = $model->getReactionsFor(array_map(static fn ($comment) => (int) $comment->id, $items));
        $returnUrl = Uri::getInstance()->toString();
```

Then change:

```php
            [
                'extension' => $extension,
                'view' => $view,
                'itemId' => $itemId,
                'items' => $items,
                'returnUrl' => $returnUrl,
            ],
```

to:

```php
            [
                'extension' => $extension,
                'view' => $view,
                'itemId' => $itemId,
                'items' => $items,
                'reactions' => $reactions,
                'returnUrl' => $returnUrl,
            ],
```

- [ ] **Step 2: Render reaction buttons in the layout**

Replace the full contents of `com_lcomment/components/com_lcomment/layouts/comment.php` with:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Lcsilva\Component\Lcomment\Administrator\Service\CommentTreeBuilder;
use Lcsilva\Component\Lcomment\Administrator\Service\ReactionToggle;

/**
 * Expected keys in $displayData (array or object):
 * @var string $extension
 * @var string $view
 * @var int    $itemId
 * @var array  $items
 * @var array  $reactions
 * @var string $returnUrl
 *
 * Overridable via templates/<template>/html/layouts/comment.php
 */
extract((array) $displayData);

$app = Factory::getApplication();
$user = $app->getIdentity();

// Read-once: repopulate the form after a rejected submission, then forget it.
$stateKey = 'com_lcomment.comment.state.' . $extension . '.' . $view . '.' . $itemId;
$previous = (array) $app->getUserState($stateKey, []);
$app->setUserState($stateKey, null);

$prefillParentId = (int) ($previous['parent_id'] ?? 0);
$prefillText = $previous['text'] ?? '';
$prefillGuestName = $previous['guest_name'] ?? '';
$prefillGuestEmail = $previous['guest_email'] ?? '';

$tree = CommentTreeBuilder::build($items);

$reactionEmoji = [
    'like' => '👍',
    'love' => '❤️',
    'haha' => '😂',
    'wow' => '😮',
    'sad' => '😢',
    'angry' => '😡',
];

// Anonymous closures, not named functions: this file is included via
// LayoutHelper::render() once per onContentAfterDisplay call, and a page
// listing several items (e.g. a blog/category view) triggers that event
// once per item — so the file can be included more than once in the same
// request. A top-level `function` declaration would fatal on the second
// inclusion.
$renderForm = function (int $parentId, string $idSuffix, string $textValue) use (
    $extension,
    $view,
    $itemId,
    $returnUrl,
    $user,
    $prefillGuestName,
    $prefillGuestEmail
): void {
    ?>
    <form method="post" action="<?php echo Route::_('index.php?option=com_lcomment&task=comment.save'); ?>" class="lcomment-form">
        <input type="hidden" name="extension" value="<?php echo htmlspecialchars($extension); ?>">
        <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
        <input type="hidden" name="item_id" value="<?php echo (int) $itemId; ?>">
        <input type="hidden" name="parent_id" value="<?php echo $parentId; ?>">
        <input type="hidden" name="return" value="<?php echo base64_encode($returnUrl); ?>">

        <?php if (!$user || $user->id === 0) : ?>
            <div class="mb-2">
                <label for="lcomment-guest-name-<?php echo $idSuffix; ?>"><?php echo Text::_('COM_LCOMMENT_FORM_NAME_LABEL'); ?></label>
                <input type="text" id="lcomment-guest-name-<?php echo $idSuffix; ?>" name="guest_name" class="form-control" value="<?php echo htmlspecialchars($prefillGuestName); ?>">
            </div>
            <div class="mb-2">
                <label for="lcomment-guest-email-<?php echo $idSuffix; ?>"><?php echo Text::_('COM_LCOMMENT_FORM_EMAIL_LABEL'); ?></label>
                <input type="email" id="lcomment-guest-email-<?php echo $idSuffix; ?>" name="guest_email" class="form-control" value="<?php echo htmlspecialchars($prefillGuestEmail); ?>">
            </div>
        <?php endif; ?>

        <div class="mb-2">
            <label for="lcomment-comment-text-<?php echo $idSuffix; ?>"><?php echo Text::_('COM_LCOMMENT_FORM_TEXT_LABEL'); ?></label>
            <textarea id="lcomment-comment-text-<?php echo $idSuffix; ?>" name="comment_text" class="form-control" required><?php echo htmlspecialchars($textValue); ?></textarea>
        </div>

        <?php echo HTMLHelper::_('form.token'); ?>
        <button type="submit" class="btn btn-primary">
            <?php echo Text::_('COM_LCOMMENT_FORM_SUBMIT_LABEL'); ?>
        </button>
    </form>
    <?php
};

$renderReactions = function (int $commentId) use ($returnUrl, $reactions, $reactionEmoji): void {
    $mine = $reactions[$commentId]['mine'] ?? null;
    $counts = $reactions[$commentId]['counts'] ?? [];
    ?>
    <div class="lcomment-reactions" data-comment-id="<?php echo $commentId; ?>">
        <?php foreach (ReactionToggle::VALID_TYPES as $type) : ?>
            <?php $isMine = $mine === $type; ?>
            <form method="post" action="<?php echo Route::_('index.php?option=com_lcomment&task=reaction.save'); ?>" class="lcomment-reaction-form<?php echo $isMine ? ' lcomment-reaction-active' : ''; ?>">
                <input type="hidden" name="comment_id" value="<?php echo $commentId; ?>">
                <input type="hidden" name="reaction_type" value="<?php echo $type; ?>">
                <input type="hidden" name="return" value="<?php echo base64_encode($returnUrl); ?>">
                <?php echo HTMLHelper::_('form.token'); ?>
                <button type="submit" class="lcomment-reaction-button" aria-pressed="<?php echo $isMine ? 'true' : 'false'; ?>">
                    <span class="lcomment-reaction-emoji"><?php echo $reactionEmoji[$type]; ?></span>
                    <?php if (($counts[$type] ?? 0) > 0) : ?>
                        <span class="lcomment-reaction-count"><?php echo (int) $counts[$type]; ?></span>
                    <?php endif; ?>
                </button>
            </form>
        <?php endforeach; ?>
    </div>
    <?php
};

$renderNode = function (array $node, int $depth) use (&$renderNode, $renderForm, $renderReactions, $prefillParentId, $prefillText): void {
    $comment = $node['comment'];
    $commentId = (int) $comment->id;
    $depthClass = 'lcomment-depth-' . min($depth, 5);
    $isReplyOpen = $prefillParentId === $commentId;
    ?>
    <li class="lcomment-item <?php echo $depthClass; ?>" data-id="<?php echo $commentId; ?>">
        <strong>
            <?php echo htmlspecialchars($comment->guest_name ?: ('#' . (int) $comment->user_id)); ?>
        </strong>
        <?php if ((int) $comment->state === 0) : ?>
            <span class="badge bg-warning"><?php echo Text::_('COM_LCOMMENT_LIST_PENDING_BADGE'); ?></span>
        <?php endif; ?>
        <p><?php echo htmlspecialchars((string) $comment->comment_text); ?></p>

        <?php $renderReactions($commentId); ?>

        <details class="lcomment-reply"<?php echo $isReplyOpen ? ' open' : ''; ?>>
            <summary><?php echo Text::_('COM_LCOMMENT_REPLY_LABEL'); ?></summary>
            <?php $renderForm($commentId, (string) $commentId, $isReplyOpen ? $prefillText : ''); ?>
        </details>

        <?php if (!empty($node['replies'])) : ?>
            <ul class="lcomment-list list-unstyled">
                <?php foreach ($node['replies'] as $child) : ?>
                    <?php $renderNode($child, $depth + 1); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </li>
    <?php
};
?>
<div class="lcomment-block">
    <ul class="lcomment-list list-unstyled">
        <?php if (empty($tree)) : ?>
            <li><?php echo Text::_('COM_LCOMMENT_LIST_EMPTY'); ?></li>
        <?php endif; ?>
        <?php foreach ($tree as $node) : ?>
            <?php $renderNode($node, 1); ?>
        <?php endforeach; ?>
    </ul>

    <?php $renderForm(0, 'top', $prefillParentId === 0 ? $prefillText : ''); ?>
</div>
```

- [ ] **Step 3: Add reaction button CSS**

Append to `com_lcomment/media/css/lcomment.css`:

```css
.lcomment-reactions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
    margin-top: 0.5rem;
}

.lcomment-reaction-form {
    margin: 0;
}

.lcomment-reaction-button {
    background: none;
    border: 1px solid #ddd;
    border-radius: 999px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.1rem 0.5rem;
}

.lcomment-reaction-active .lcomment-reaction-button {
    background: #e7f1ff;
    border-color: #0d6efd;
}

.lcomment-reaction-count {
    font-size: 0.85em;
}
```

- [ ] **Step 4: Syntax-check and run the full test suite**

Run: `php -l plg_content_lcomment/src/Extension/Lcomment.php && php -l com_lcomment/components/com_lcomment/layouts/comment.php && vendor/bin/phpunit`
Expected: both `No syntax errors detected...` lines, then PASS on the full suite.

- [ ] **Step 5: Commit**

```bash
git add plg_content_lcomment/src/Extension/Lcomment.php com_lcomment/components/com_lcomment/layouts/comment.php com_lcomment/media/css/lcomment.css
git commit -m "feat(lcomment): render reaction buttons per comment"
```

---

### Task 6: Progressive-enhancement JavaScript

**Files:**
- Create: `com_lcomment/media/js/lcomment-reactions.js`
- Modify: `com_lcomment/media/joomla.asset.json`
- Modify: `plg_content_lcomment/src/Extension/Lcomment.php`

**Interfaces:**
- Consumes: the `.lcomment-reactions`/`.lcomment-reaction-form`/
  `.lcomment-reaction-button`/`.lcomment-reaction-count` class names and
  `data-comment-id` attribute (Task 5); the `X-LComment-Ajax` header name
  and the `{"counts": {...}, "mine": ?string}` JSON shape (Task 4).
- Produces: no new interface — this is the leaf of the chain. Verified by
  the spec's manual acceptance criteria 1, 2, 3, 4 (the no-JS fallback
  path exercises Task 4/5 directly, without this file).

- [ ] **Step 1: Create `lcomment-reactions.js`**

Create `com_lcomment/media/js/lcomment-reactions.js`:

```js
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.lcomment-reaction-form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const container = form.closest('.lcomment-reactions');

            fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                body: new FormData(form),
                headers: {
                    'X-LComment-Ajax': '1',
                },
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Request failed');
                    }

                    return response.json();
                })
                .then((data) => updateReactions(container, data))
                .catch(() => {
                    // Network error or rejected request: leave the DOM as it
                    // was and let the user retry — the server is still the
                    // source of truth, nothing is guessed on failure here.
                });
        });
    });
});

function updateReactions(container, data) {
    if (!container) {
        return;
    }

    container.querySelectorAll('.lcomment-reaction-form').forEach((form) => {
        const type = form.querySelector('input[name="reaction_type"]').value;
        const button = form.querySelector('.lcomment-reaction-button');
        const isMine = data.mine === type;
        const count = (data.counts && data.counts[type]) || 0;

        button.setAttribute('aria-pressed', isMine ? 'true' : 'false');
        form.classList.toggle('lcomment-reaction-active', isMine);

        let countEl = button.querySelector('.lcomment-reaction-count');

        if (count > 0) {
            if (!countEl) {
                countEl = document.createElement('span');
                countEl.className = 'lcomment-reaction-count';
                button.appendChild(countEl);
            }

            countEl.textContent = String(count);
        } else if (countEl) {
            countEl.remove();
        }
    });
}
```

- [ ] **Step 2: Register the new script asset**

In `com_lcomment/media/joomla.asset.json`, change:

```json
    "version": "0.1.0",
```

to:

```json
    "version": "0.2.0",
```

Then change:

```json
        {
            "name": "com_lcomment.comments",
            "type": "script",
            "uri": "js/lcomment.js",
            "attributes": {
                "defer": true
            }
        }
    ]
```

to:

```json
        {
            "name": "com_lcomment.comments",
            "type": "script",
            "uri": "js/lcomment.js",
            "attributes": {
                "defer": true
            }
        },
        {
            "name": "com_lcomment.reactions",
            "type": "script",
            "uri": "js/lcomment-reactions.js",
            "attributes": {
                "defer": true
            }
        }
    ]
```

- [ ] **Step 3: Load the new script from the plugin**

In `plg_content_lcomment/src/Extension/Lcomment.php`, change:

```php
        $webAssetManager->useStyle('com_lcomment.comments')
            ->useScript('com_lcomment.comments');
```

to:

```php
        $webAssetManager->useStyle('com_lcomment.comments')
            ->useScript('com_lcomment.comments')
            ->useScript('com_lcomment.reactions');
```

- [ ] **Step 4: Syntax-check the plugin and run the full test suite**

Run: `php -l plg_content_lcomment/src/Extension/Lcomment.php && node --check com_lcomment/media/js/lcomment-reactions.js && vendor/bin/phpunit`
Expected: `No syntax errors detected...`, no output from `node --check` (means the JS is syntactically valid), then PASS on the full suite. If `node` is not available in this environment, skip that one check and note it in the commit message — the browser will be the real check during manual verification either way.

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/media/js/lcomment-reactions.js com_lcomment/media/joomla.asset.json plg_content_lcomment/src/Extension/Lcomment.php
git commit -m "feat(lcomment): load lcomment-reactions.js for progressive reaction updates"
```

- [ ] **Step 6: Manual check to perform later, during live Joomla verification**

In addition to the spec's 7 criteria, also check: with the browser's devtools set to "offline" or throttled to fail the request, click a reaction emoji — expect the count/highlight to stay exactly as they were (no optimistic update), and expect the normal page-reload fallback to still work once the network is restored and the same button is clicked again as a plain form submit (disable JS entirely to confirm this half).

---

## Manual verification (real Joomla 6.1.4 site)

After `./build.sh` and installing the rebuilt `dist/pkg_lcomment.zip`
(this is an **upgrade**, not a fresh install — the Joomla extension
manager will run `0.3.0.sql` automatically; confirm the
`#__lcomment_reactions` table exists afterward), run through the spec's 7
acceptance criteria in order, plus the two extra checks flagged above
(Task 4 Step 5, Task 6 Step 6) that the spec's own list doesn't name
explicitly:

1. Click an emoji on a comment → count goes up by 1, no reload, emoji
   highlighted as "mine".
2. Click a different emoji on the same comment → old emoji's count goes
   down by 1, new one's goes up by 1, highlight moves (never both at once).
3. Click the already-highlighted emoji again → its count goes down by 1,
   no emoji stays highlighted.
4. Disable JavaScript, repeat step 1 → page reloads (plain POST+redirect),
   reaction is recorded and the count/highlight are correct after reload.
5. React as a guest, then react to the same comment from a different
   browser/device (different IP and session) → both reactions count
   separately.
6. Forge a POST to `reaction.save` with a `reaction_type` outside the 6
   valid values → rejected, no row created.
7. React to a nested reply (not a top-level comment) → works identically
   to a top-level comment.
8. *(extra, Task 4)* React to a pending or trashed comment →
   `COM_LCOMMENT_ERROR_INVALID_REACTION_TARGET`, no row created; inspect
   the raw AJAX response body in devtools → pure JSON, nothing appended.
9. *(extra, Task 6)* Simulate a dropped/failed request via devtools →
   DOM stays exactly as it was; confirm the no-JS fallback still works
   once JS is disabled entirely.

# LComment — Fase 2d: Avaliações (Votos de Utilidade) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let visitors and registered users mark any comment (or reply) as "helpful" or "unhelpful", one active vote per person per comment, with self-voting blocked for a registered author, submitted via the same progressively-enhanced pattern already shipped for reactions in Fase 2c.

**Architecture:** A new `#__lcomment_votes` table stores one row per (comment, identity) pair, with two `UNIQUE KEY`s enforcing "never two rows for the same identity" at the database level from the start (Fase 2c only added this after its final review caught the gap — this plan applies that lesson up front). A pure-PHP `VoteToggle` domain class decides insert/update/delete, identical in shape to `ReactionToggle` but intentionally not shared with it (different product concept, independent evolution). `CommentModel::getVotesFor()` aggregates counts and "mine" per comment. `VoteController::save()` validates (vote type, comment published, self-vote), persists via `VoteToggle`, and replies either with a redirect or JSON (mirroring `ReactionController::save()`, including the `$app->close()` after JSON and the race-safe insert with `ExecutionFailureException` handling, both already verified against real Joomla source during Fase 2c). The layout renders two real `<form>`s per comment (no-JS fallback) and a new `lcomment-votes.js` progressively intercepts their submit — same pattern as `lcomment-reactions.js`, including its in-flight-per-container guard from day one.

**Tech Stack:** PHP 8.1+, Joomla 6.1.4 MVC, PHPUnit 10.5 for the pure-PHP domain class, vanilla JavaScript (no framework, no new dependency).

**Spec:** `docs/superpowers/specs/2026-10-08-lcomment-votes-design.md`

## Global Constraints

- Target: Joomla 6.1.4 real environment, PHP 8.1+. Every Joomla framework
  API this plan uses (`whereIn`/`group`/`select` on arrays, `bind()`
  inside `set()`/`values()`, `$app->setHeader('status', ...)` +
  `sendHeaders()`, `$app->close()`, `$app->getSession()->getId()`,
  `Joomla\Database\Exception\ExecutionFailureException`) was already
  verified against real source during Fase 2c and is reused here
  unchanged — no re-verification needed, it's the same API used the same
  way.
- 2 fixed vote types, not configurable: `helpful`, `unhelpful`. The
  authoritative list lives in `VoteToggle::VALID_TYPES`.
- One active vote per identity per comment (exclusive, same
  insert/update/delete state machine as reactions): insert if none
  exists, delete if clicking the already-active type again, update
  (swap) if clicking the other type.
- Guest identity is `(guest_ip, guest_session_id)`, same "good enough"
  dedup already used for reactions — not abuse-proof by design.
- **Self-vote is blocked**: a registered user cannot vote on a comment
  whose `user_id` matches their own. Does not apply when the comment has
  no `user_id` (guest-authored) or the voter is a guest.
- **Votes never affect comment ordering** — informational only this
  sub-delivery. `CommentTreeBuilder`'s existing chronological order is
  unchanged.
- Voting works with JavaScript disabled (plain POST + redirect) and is
  enhanced, not replaced, by JavaScript. Same dual-response contract as
  `reaction.save`.
- No schema change to any existing table. New table only:
  `#__lcomment_votes`, with its two `UNIQUE KEY`s present from this
  table's very first version (not added later) — this is the one
  concrete fix Fase 2c's final review had to apply after the fact;
  applying it up front here is not optional.
- Package version bumps `0.3.0` → `0.4.0`.
- `VoteToggle` is pure PHP (no `_JEXEC` guard, no Joomla dependency),
  testable via PHPUnit — same pattern as `ReactionToggle`.
  `CommentModel::getVotesFor()` and `VoteController` are Joomla framework
  code with no automated test harness available in this project — same
  precedent as `ReactionController`/`getReactionsFor()` — verified by
  manual checks instead.
- **Both counters are always shown, including zero** — unlike the
  reaction buttons (which hide a type's count when it's zero), the
  helpful/unhelpful counters always display a number. Don't copy the
  reaction layout's `if (($counts[$type] ?? 0) > 0)` conditional-hide
  verbatim; the vote layout always echoes `$counts[$type] ?? 0`.

## Review Focus

- Clicking the already-active vote again must **remove** it, and
  clicking the opposite vote must **swap**, never leaving two rows for
  the same identity on the same comment. Pinned by a `VoteToggleTest`
  case (Task 2) at the domain layer; the full-stack behavior (DB lookup +
  decide + apply, including the concurrent-request race) is only
  exercised by the spec's manual acceptance criterion 10 — the two
  `UNIQUE KEY`s and the `ExecutionFailureException` handling are what
  actually guarantee it under concurrency, not just the state machine.
- A registered author must be rejected from voting on their own comment
  — even via a forged POST that never went through the UI — while a
  guest-authored comment or a guest voter must never trigger that block
  by accident (no `user_id` to compare on either side). Not covered by
  any PHPUnit test (framework code); pinned by the spec's manual
  acceptance criterion 5. Task 4's steps call out both halves (block
  fires; block doesn't fire when it shouldn't) explicitly.
- Voting on a comment that is pending moderation or trashed must be
  rejected — only `state = 1` comments are votable. Not PHPUnit-testable;
  pinned by the spec's manual acceptance criterion 9, with an explicit
  check that the AJAX response is pure JSON (same `$app->close()`
  concern already resolved for reactions, reused unchanged here).
- A forged `vote_type` outside `helpful`/`unhelpful` must be rejected
  server-side even though the UI only ever renders those two buttons.
  Pinned by the spec's manual acceptance criterion 7, and by
  `VoteToggleTest`'s assertion on the exact content of
  `VoteToggle::VALID_TYPES` (Task 2).
- The helpful/unhelpful counters must both always render a number
  (including `0`), never hide a zero count the way the reaction buttons
  do. Not PHPUnit-testable (view layer); Task 5 calls this out explicitly
  as the one place this plan's layout code must diverge from the
  reaction layout it otherwise mirrors.

---

### Task 1: Schema, migration, and package version

**Files:**
- Modify: `com_lcomment/com_lcomment.xml`
- Modify: `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`
- Modify: `com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql`
- Create: `com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.4.0.sql`

**Interfaces:**
- Consumes: nothing.
- Produces: the `#__lcomment_votes` table, consumed by every later task
  (`comment_id`, `user_id`, `guest_ip`, `guest_session_id`, `vote_type`,
  `created` columns, plus the two `UNIQUE KEY`s, exactly as specified
  below).

- [ ] **Step 1: Bump the package version**

In `com_lcomment/com_lcomment.xml`, change:

```xml
    <version>0.3.0</version>
```

to:

```xml
    <version>0.4.0</version>
```

- [ ] **Step 2: Add the new table to the fresh-install schema**

Append to `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`:

```sql

CREATE TABLE IF NOT EXISTS `#__lcomment_votes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comment_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `guest_ip` VARCHAR(45) NULL,
    `guest_session_id` VARCHAR(192) NULL,
    `vote_type` VARCHAR(20) NOT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_comment_id` (`comment_id`),
    UNIQUE KEY `idx_user_comment` (`comment_id`, `user_id`),
    UNIQUE KEY `idx_guest_comment` (`comment_id`, `guest_ip`, `guest_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 3: Add the same table to the upgrade path**

Create `com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.4.0.sql`:

```sql
CREATE TABLE IF NOT EXISTS `#__lcomment_votes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comment_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `guest_ip` VARCHAR(45) NULL,
    `guest_session_id` VARCHAR(192) NULL,
    `vote_type` VARCHAR(20) NOT NULL,
    `created` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_comment_id` (`comment_id`),
    UNIQUE KEY `idx_user_comment` (`comment_id`, `user_id`),
    UNIQUE KEY `idx_guest_comment` (`comment_id`, `guest_ip`, `guest_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 4: Add the table to the uninstall script**

In `com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql`, add a fourth line:

```sql
DROP TABLE IF EXISTS `#__lcomment_comments`;
DROP TABLE IF EXISTS `#__lcomment_contexts`;
DROP TABLE IF EXISTS `#__lcomment_reactions`;
DROP TABLE IF EXISTS `#__lcomment_votes`;
```

- [ ] **Step 5: Verify the fresh-install and upgrade schemas are identical**

Run: `diff <(sed -n '/lcomment_votes/,/utf8mb4;/p' com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql) com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.4.0.sql`
Expected: no output (the two `CREATE TABLE` blocks are byte-identical).

- [ ] **Step 6: Verify the version bump**

Run: `grep -n '<version>' com_lcomment/com_lcomment.xml`
Expected: `8:    <version>0.4.0</version>`

- [ ] **Step 7: Run the full test suite to confirm nothing else broke**

Run: `vendor/bin/phpunit`
Expected: PASS — this task touches no PHP file, but confirm the suite is still green before building on top of it.

- [ ] **Step 8: Commit**

```bash
git add com_lcomment/com_lcomment.xml com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.4.0.sql
git commit -m "feat(lcomment): add lcomment_votes table, bump package to 0.4.0"
```

---

### Task 2: `VoteToggle` domain class

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Service/VoteToggle.php`
- Test: `tests/Service/VoteToggleTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `Lcsilva\Component\Lcomment\Administrator\Service\VoteToggle::VALID_TYPES`
  (the fixed `array` `['helpful', 'unhelpful']`) and
  `VoteToggle::decide(?string $existingType, string $requestedType): array`
  returning `['action' => 'insert'|'update'|'delete', 'type' => ?string]`.
  Consumed by Task 4 (`VoteController`, for both the whitelist check and
  the decision) and Task 5 (layout, to enumerate the 2 buttons in a fixed
  order).

- [ ] **Step 1: Write the failing tests**

Create `tests/Service/VoteToggleTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\VoteToggle;
use PHPUnit\Framework\TestCase;

final class VoteToggleTest extends TestCase
{
    public function testNoExistingVoteInsertsTheRequestedType(): void
    {
        self::assertSame(
            ['action' => 'insert', 'type' => 'helpful'],
            VoteToggle::decide(null, 'helpful')
        );
    }

    public function testClickingTheSameActiveVoteRemovesIt(): void
    {
        self::assertSame(
            ['action' => 'delete', 'type' => null],
            VoteToggle::decide('helpful', 'helpful')
        );
    }

    public function testClickingTheOppositeVoteSwapsToTheNewType(): void
    {
        self::assertSame(
            ['action' => 'update', 'type' => 'unhelpful'],
            VoteToggle::decide('helpful', 'unhelpful')
        );
    }

    public function testValidTypesListsExactlyTheTwoFixedVotes(): void
    {
        self::assertSame(
            ['helpful', 'unhelpful'],
            VoteToggle::VALID_TYPES
        );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Service/VoteToggleTest.php`
Expected: FAIL — `Class "Lcsilva\Component\Lcomment\Administrator\Service\VoteToggle" not found`.

- [ ] **Step 3: Implement `VoteToggle`**

Create `com_lcomment/administrator/components/com_lcomment/src/Service/VoteToggle.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class VoteToggle
{
    /**
     * The fixed, non-configurable set of vote types this sub-delivery
     * supports. This is the authoritative whitelist — callers (controller
     * validation, layout rendering) must check a requested type against
     * this list themselves; decide() below does not revalidate it.
     */
    public const VALID_TYPES = ['helpful', 'unhelpful'];

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

Run: `vendor/bin/phpunit tests/Service/VoteToggleTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/VoteToggle.php tests/Service/VoteToggleTest.php
git commit -m "feat(lcomment): add VoteToggle domain class"
```

---

### Task 3: `CommentModel::getVotesFor()`

**Files:**
- Modify: `com_lcomment/components/com_lcomment/src/Model/CommentModel.php`

**Interfaces:**
- Consumes: the `#__lcomment_votes` table from Task 1.
- Produces: `CommentModel::getVotesFor(array $commentIds): array` returning
  `[commentId => ['counts' => ['helpful' => 12, ...], 'mine' => 'helpful'|'unhelpful'|null]]`
  — every id passed in is present in the result, defaulting to
  `['counts' => [], 'mine' => null]`. Consumed by Task 4 (`VoteController`,
  for the AJAX JSON response) and Task 5 (plugin wiring, for the page
  render). No PHPUnit coverage possible (same precedent as
  `getReactionsFor()`); this method is a line-for-line mirror of it with
  the table and column names swapped, so no new Joomla API verification
  is needed.

- [ ] **Step 1: Add `getVotesFor()`**

In `com_lcomment/components/com_lcomment/src/Model/CommentModel.php`, add this method after `getReactionsFor()` (before the class's closing `}`):

```php
    public function getVotesFor(array $commentIds): array
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
                $db->quoteName('vote_type'),
                'COUNT(*) AS ' . $db->quoteName('total'),
            ])
            ->from($db->quoteName('#__lcomment_votes'))
            ->whereIn($db->quoteName('comment_id'), $commentIds)
            ->group([$db->quoteName('comment_id'), $db->quoteName('vote_type')]);

        $db->setQuery($countsQuery);

        foreach ($db->loadObjectList() as $row) {
            $result[(int) $row->comment_id]['counts'][(string) $row->vote_type] = (int) $row->total;
        }

        $app = \Joomla\CMS\Factory::getApplication();
        $user = $app->getIdentity();

        $mineQuery = $db->getQuery(true)
            ->select([$db->quoteName('comment_id'), $db->quoteName('vote_type')])
            ->from($db->quoteName('#__lcomment_votes'))
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
            $result[(int) $row->comment_id]['mine'] = (string) $row->vote_type;
        }

        return $result;
    }
```

- [ ] **Step 2: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/components/com_lcomment/src/Model/CommentModel.php && vendor/bin/phpunit`
Expected: `No syntax errors detected...` followed by PASS on the full suite.

- [ ] **Step 3: Commit**

```bash
git add com_lcomment/components/com_lcomment/src/Model/CommentModel.php
git commit -m "feat(lcomment): add CommentModel::getVotesFor for aggregated vote counts"
```

---

### Task 4: `VoteController::save()`

**Files:**
- Create: `com_lcomment/components/com_lcomment/src/Controller/VoteController.php`
- Modify: `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`

**Interfaces:**
- Consumes: `VoteToggle::VALID_TYPES`/`VoteToggle::decide()` (Task 2);
  `CommentModel::getVotesFor()` (Task 3, for the AJAX JSON response body).
- Produces: task `vote.save`, POST fields `comment_id` (int), `vote_type`
  (string), `return` (base64 URL). Request header `X-LComment-Ajax`
  switches the response from redirect to JSON. Success JSON body:
  `{"counts": {...}, "mine": "helpful"|"unhelpful"|null}`. Error JSON body
  (HTTP 400): `{"error": "<language key>"}`. Consumed by Task 5 (the form
  `action=` and hidden field names) and Task 6 (`lcomment-votes.js`, the
  header name and both JSON shapes).
- No PHPUnit coverage possible (framework code). Every Joomla API used
  here is the same one already verified against real source for
  `ReactionController` during Fase 2c (see Global Constraints) — reused
  unchanged, no new verification needed.

- [ ] **Step 1: Add the new language keys**

Append to `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_ERROR_INVALID_VOTE_TYPE="That vote is not available."
COM_LCOMMENT_ERROR_INVALID_VOTE_TARGET="You cannot vote on that comment."
COM_LCOMMENT_ERROR_SELF_VOTE_NOT_ALLOWED="You cannot vote on your own comment."
```

Append to `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_ERROR_INVALID_VOTE_TYPE="Esse voto não está disponível."
COM_LCOMMENT_ERROR_INVALID_VOTE_TARGET="Não é possível votar nesse comentário."
COM_LCOMMENT_ERROR_SELF_VOTE_NOT_ALLOWED="Não é possível votar no seu próprio comentário."
```

- [ ] **Step 2: Create `VoteController`**

Create `com_lcomment/components/com_lcomment/src/Controller/VoteController.php`:

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
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;
use Lcsilva\Component\Lcomment\Administrator\Service\VoteToggle;

final class VoteController extends BaseController
{
    public function save(): bool
    {
        Session::checkToken('post') or die(Text::_('JINVALID_TOKEN'));

        $app = Factory::getApplication();
        $input = $app->getInput();
        $isAjax = $input->server->getString('HTTP_X_LCOMMENT_AJAX', '') !== '';
        $returnUrl = base64_decode($input->getBase64('return', ''));

        $commentId = $input->getInt('comment_id', 0);
        $voteType = $input->getCmd('vote_type', '');

        if (!\in_array($voteType, VoteToggle::VALID_TYPES, true)) {
            return $this->fail($app, $isAjax, $returnUrl, 'COM_LCOMMENT_ERROR_INVALID_VOTE_TYPE');
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $comment = $this->findCommentForVoting($db, $commentId);

        if ($comment === null || $comment['state'] !== 1) {
            return $this->fail($app, $isAjax, $returnUrl, 'COM_LCOMMENT_ERROR_INVALID_VOTE_TARGET');
        }

        $user = $app->getIdentity();
        $userId = $user && $user->id > 0 ? (int) $user->id : null;

        if ($userId !== null && $comment['user_id'] === $userId) {
            return $this->fail($app, $isAjax, $returnUrl, 'COM_LCOMMENT_ERROR_SELF_VOTE_NOT_ALLOWED');
        }

        $guestIp = $userId === null ? $input->server->getString('REMOTE_ADDR', '') : '';
        $guestSessionId = $userId === null ? $app->getSession()->getId() : '';

        $existing = $this->findExisting($db, $commentId, $userId, $guestIp, $guestSessionId);
        $decision = VoteToggle::decide($existing['type'] ?? null, $voteType);

        $this->applyDecision($db, $decision, $existing['id'] ?? null, $commentId, $userId, $guestIp, $guestSessionId);

        if ($isAjax) {
            /** @var \Lcsilva\Component\Lcomment\Site\Model\CommentModel $model */
            $model = $this->getModel('Comment', 'Site');
            $votes = $model->getVotesFor([$commentId]);

            $this->respondJson($app, 200, $votes[$commentId]);

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

    /**
     * @return array{state: int, user_id: ?int}|null
     */
    private function findCommentForVoting(DatabaseInterface $db, int $commentId): ?array
    {
        $query = $db->getQuery(true)
            ->select([$db->quoteName('state'), $db->quoteName('user_id')])
            ->from($db->quoteName('#__lcomment_comments'))
            ->where($db->quoteName('id') . ' = :commentId')
            ->bind(':commentId', $commentId, ParameterType::INTEGER);

        $db->setQuery($query);

        $row = $db->loadAssoc();

        if ($row === null) {
            return null;
        }

        return [
            'state' => (int) $row['state'],
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
        ];
    }

    /**
     * @return array{id: int, type: string}|array{}
     */
    private function findExisting(DatabaseInterface $db, int $commentId, ?int $userId, string $guestIp, string $guestSessionId): array
    {
        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('vote_type')])
            ->from($db->quoteName('#__lcomment_votes'))
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

        return $row !== null ? ['id' => (int) $row['id'], 'type' => (string) $row['vote_type']] : [];
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
                ->delete($db->quoteName('#__lcomment_votes'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $existingId, ParameterType::INTEGER);

            $db->setQuery($query);
            $db->execute();

            return;
        }

        if ($decision['action'] === 'update') {
            $type = $decision['type'];

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__lcomment_votes'))
                ->set($db->quoteName('vote_type') . ' = :type')
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
            ->insert($db->quoteName('#__lcomment_votes'))
            ->columns([
                $db->quoteName('comment_id'),
                $db->quoteName('user_id'),
                $db->quoteName('guest_ip'),
                $db->quoteName('guest_session_id'),
                $db->quoteName('vote_type'),
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

        try {
            $db->execute();
        } catch (ExecutionFailureException $exception) {
            // Same race as ReactionController::applyDecision() (Fase 2c):
            // a concurrent request for the same identity on the same
            // comment can trip the unique keys on (comment_id, user_id)
            // and (comment_id, guest_ip, guest_session_id). If a row for
            // this identity exists now, that race is exactly what
            // happened and there is nothing left to do; any other
            // failure still has no matching row, so it is rethrown.
            if ($this->findExisting($db, $commentId, $userId, $guestIp, $guestSessionId) === []) {
                throw $exception;
            }
        }
    }
}
```

- [ ] **Step 3: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/components/com_lcomment/src/Controller/VoteController.php && vendor/bin/phpunit`
Expected: `No syntax errors detected...` followed by PASS on the full suite.

- [ ] **Step 4: Commit**

```bash
git add com_lcomment/components/com_lcomment/src/Controller/VoteController.php com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini
git commit -m "feat(lcomment): add VoteController::save with self-vote block"
```

- [ ] **Step 5: Manual checks to perform later, during live Joomla verification (not automatable here)**

When this reaches the live Joomla acceptance pass (after Task 6), confirm both halves of the self-vote rule from the Review Focus section: (a) a registered user cannot vote on their own comment (`COM_LCOMMENT_ERROR_SELF_VOTE_NOT_ALLOWED`, no row created); (b) that same registered user CAN still vote normally on a guest-authored comment, and a guest CAN still vote on anyone's comment, including another guest's — the block must only fire for the exact "registered author voting on their own registered-authored comment" case. Also confirm: voting on a pending/trashed comment is rejected with a pure-JSON response (no HTML appended, same `$app->close()` concern already resolved for reactions).

---

### Task 5: Plugin wiring and vote buttons in the layout

**Files:**
- Modify: `plg_content_lcomment/src/Extension/Lcomment.php`
- Modify: `com_lcomment/components/com_lcomment/layouts/comment.php`
- Modify: `com_lcomment/media/css/lcomment.css`

**Interfaces:**
- Consumes: `CommentModel::getVotesFor()` (Task 3, shape
  `[commentId => ['counts' => [...], 'mine' => ?string]]`);
  `VoteToggle::VALID_TYPES` (Task 2, to enumerate the 2 buttons); the
  `vote.save` task name and POST field names `comment_id`/`vote_type`/
  `return` (Task 4).
- Produces: a `.lcomment-votes` container per comment (root or reply)
  with `data-comment-id`, each holding a `.lcomment-vote-form` (one per
  vote type, carrying class `lcomment-vote-active` when it is the
  viewer's current vote) wrapping a `.lcomment-vote-button`
  (`.lcomment-vote-label` + `.lcomment-vote-count`, **always** rendered,
  never conditionally hidden at zero). Consumed by Task 6
  (`lcomment-votes.js`, which selects these exact class names).

- [ ] **Step 1: Pass vote data from the plugin into the layout**

In `plg_content_lcomment/src/Extension/Lcomment.php`, change:

```php
        $items = $model->getItemsFor($extension, $view, $itemId);
        $reactions = $model->getReactionsFor(array_map(static fn ($comment) => (int) $comment->id, $items));
        $returnUrl = Uri::getInstance()->toString();
```

to:

```php
        $items = $model->getItemsFor($extension, $view, $itemId);
        $commentIds = array_map(static fn ($comment) => (int) $comment->id, $items);
        $reactions = $model->getReactionsFor($commentIds);
        $votes = $model->getVotesFor($commentIds);
        $returnUrl = Uri::getInstance()->toString();
```

Then change:

```php
                'items' => $items,
                'reactions' => $reactions,
                'returnUrl' => $returnUrl,
            ],
```

to:

```php
                'items' => $items,
                'reactions' => $reactions,
                'votes' => $votes,
                'returnUrl' => $returnUrl,
            ],
```

- [ ] **Step 2: Render vote buttons in the layout**

In `com_lcomment/components/com_lcomment/layouts/comment.php`, add `VoteToggle` to the `use` imports — change:

```php
use Lcsilva\Component\Lcomment\Administrator\Service\CommentTreeBuilder;
use Lcsilva\Component\Lcomment\Administrator\Service\ReactionToggle;
```

to:

```php
use Lcsilva\Component\Lcomment\Administrator\Service\CommentTreeBuilder;
use Lcsilva\Component\Lcomment\Administrator\Service\ReactionToggle;
use Lcsilva\Component\Lcomment\Administrator\Service\VoteToggle;
```

Update the `$displayData` docblock — change:

```php
 * @var array  $reactions
 * @var string $returnUrl
```

to:

```php
 * @var array  $reactions
 * @var array  $votes
 * @var string $returnUrl
```

Add a vote label map next to the existing `$reactionEmoji` map — change:

```php
$reactionEmoji = [
    'like' => '👍',
    'love' => '❤️',
    'haha' => '😂',
    'wow' => '😮',
    'sad' => '😢',
    'angry' => '😡',
];
```

to:

```php
$reactionEmoji = [
    'like' => '👍',
    'love' => '❤️',
    'haha' => '😂',
    'wow' => '😮',
    'sad' => '😢',
    'angry' => '😡',
];

$voteLabel = [
    'helpful' => '👍 ' . Text::_('COM_LCOMMENT_VOTE_HELPFUL_LABEL'),
    'unhelpful' => '👎 ' . Text::_('COM_LCOMMENT_VOTE_UNHELPFUL_LABEL'),
];
```

Add a `$renderVotes` closure right after the existing `$renderReactions` closure — change:

```php
$renderNode = function (array $node, int $depth) use (&$renderNode, $renderForm, $renderReactions, $prefillParentId, $prefillText): void {
```

to:

```php
$renderVotes = function (int $commentId) use ($returnUrl, $votes, $voteLabel): void {
    $mine = $votes[$commentId]['mine'] ?? null;
    $counts = $votes[$commentId]['counts'] ?? [];
    ?>
    <div class="lcomment-votes" data-comment-id="<?php echo $commentId; ?>">
        <?php foreach (VoteToggle::VALID_TYPES as $type) : ?>
            <?php $isMine = $mine === $type; ?>
            <form method="post" action="<?php echo Route::_('index.php?option=com_lcomment&task=vote.save'); ?>" class="lcomment-vote-form<?php echo $isMine ? ' lcomment-vote-active' : ''; ?>">
                <input type="hidden" name="comment_id" value="<?php echo $commentId; ?>">
                <input type="hidden" name="vote_type" value="<?php echo $type; ?>">
                <input type="hidden" name="return" value="<?php echo base64_encode($returnUrl); ?>">
                <?php echo HTMLHelper::_('form.token'); ?>
                <button type="submit" class="lcomment-vote-button" aria-pressed="<?php echo $isMine ? 'true' : 'false'; ?>">
                    <span class="lcomment-vote-label"><?php echo $voteLabel[$type]; ?></span>
                    <span class="lcomment-vote-count"><?php echo (int) ($counts[$type] ?? 0); ?></span>
                </button>
            </form>
        <?php endforeach; ?>
    </div>
    <?php
};

$renderNode = function (array $node, int $depth) use (&$renderNode, $renderForm, $renderReactions, $renderVotes, $prefillParentId, $prefillText): void {
```

Call it right after the existing `$renderReactions($commentId);` line — change:

```php
        <?php $renderReactions($commentId); ?>

        <details class="lcomment-reply"<?php echo $isReplyOpen ? ' open' : ''; ?>>
```

to:

```php
        <?php $renderReactions($commentId); ?>
        <?php $renderVotes($commentId); ?>

        <details class="lcomment-reply"<?php echo $isReplyOpen ? ' open' : ''; ?>>
```

- [ ] **Step 3: Add the new language keys**

Append to `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_VOTE_HELPFUL_LABEL="Helpful"
COM_LCOMMENT_VOTE_UNHELPFUL_LABEL="Not helpful"
```

Append to `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_VOTE_HELPFUL_LABEL="Útil"
COM_LCOMMENT_VOTE_UNHELPFUL_LABEL="Não útil"
```

- [ ] **Step 4: Add vote button CSS**

Append to `com_lcomment/media/css/lcomment.css`:

```css
.lcomment-votes {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.25rem;
}

.lcomment-vote-form {
    margin: 0;
}

.lcomment-vote-button {
    background: none;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.1rem 0.5rem;
    font-size: 0.85em;
}

.lcomment-vote-active .lcomment-vote-button {
    background: #e7f1ff;
    border-color: #0d6efd;
}
```

- [ ] **Step 5: Syntax-check and run the full test suite**

Run: `php -l plg_content_lcomment/src/Extension/Lcomment.php && php -l com_lcomment/components/com_lcomment/layouts/comment.php && vendor/bin/phpunit`
Expected: both `No syntax errors detected...` lines, then PASS on the full suite.

- [ ] **Step 6: Commit**

```bash
git add plg_content_lcomment/src/Extension/Lcomment.php com_lcomment/components/com_lcomment/layouts/comment.php com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini com_lcomment/media/css/lcomment.css
git commit -m "feat(lcomment): render helpful/unhelpful vote buttons per comment"
```

---

### Task 6: Progressive-enhancement JavaScript

**Files:**
- Create: `com_lcomment/media/js/lcomment-votes.js`
- Modify: `com_lcomment/media/joomla.asset.json`
- Modify: `plg_content_lcomment/src/Extension/Lcomment.php`

**Interfaces:**
- Consumes: the `.lcomment-votes`/`.lcomment-vote-form`/
  `.lcomment-vote-button`/`.lcomment-vote-count` class names and
  `data-comment-id` attribute (Task 5); the `X-LComment-Ajax` header and
  the `{"counts": {...}, "mine": ?string}` JSON shape (Task 4).
- Produces: no new interface — leaf of the chain. Verified by the spec's
  manual acceptance criteria 1-4 (the no-JS fallback exercises Task 4/5
  directly, without this file).

- [ ] **Step 1: Create `lcomment-votes.js`**

Create `com_lcomment/media/js/lcomment-votes.js`. Unlike the reaction
counters (which hide a zero count), vote counts always render a number —
so `updateVotes()` below always sets `textContent`, never adds/removes
the count element the way `updateReactions()` does for reactions:

```js
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.lcomment-vote-form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const container = form.closest('.lcomment-votes');

            if (container && container.dataset.pending === '1') {
                return;
            }

            if (container) {
                container.dataset.pending = '1';
            }

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
                .then((data) => updateVotes(container, data))
                .catch(() => {
                    // Network error or rejected request: leave the DOM as it
                    // was and let the user retry — the server is still the
                    // source of truth, nothing is guessed on failure here.
                })
                .finally(() => {
                    if (container) {
                        delete container.dataset.pending;
                    }
                });
        });
    });
});

function updateVotes(container, data) {
    if (!container) {
        return;
    }

    container.querySelectorAll('.lcomment-vote-form').forEach((form) => {
        const type = form.querySelector('input[name="vote_type"]').value;
        const button = form.querySelector('.lcomment-vote-button');
        const isMine = data.mine === type;
        const count = (data.counts && data.counts[type]) || 0;

        button.setAttribute('aria-pressed', isMine ? 'true' : 'false');
        form.classList.toggle('lcomment-vote-active', isMine);
        button.querySelector('.lcomment-vote-count').textContent = String(count);
    });
}
```

- [ ] **Step 2: Register the new script asset**

In `com_lcomment/media/joomla.asset.json`, change:

```json
    "version": "0.2.0",
```

to:

```json
    "version": "0.3.0",
```

Then change:

```json
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

to:

```json
        {
            "name": "com_lcomment.reactions",
            "type": "script",
            "uri": "js/lcomment-reactions.js",
            "attributes": {
                "defer": true
            }
        },
        {
            "name": "com_lcomment.votes",
            "type": "script",
            "uri": "js/lcomment-votes.js",
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
            ->useScript('com_lcomment.comments')
            ->useScript('com_lcomment.reactions');
```

to:

```php
        $webAssetManager->useStyle('com_lcomment.comments')
            ->useScript('com_lcomment.comments')
            ->useScript('com_lcomment.reactions')
            ->useScript('com_lcomment.votes');
```

- [ ] **Step 4: Syntax-check and run the full test suite**

Run: `php -l plg_content_lcomment/src/Extension/Lcomment.php && node --check com_lcomment/media/js/lcomment-votes.js && vendor/bin/phpunit`
Expected: `No syntax errors detected...`, no output from `node --check`, then PASS on the full suite.

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/media/js/lcomment-votes.js com_lcomment/media/joomla.asset.json plg_content_lcomment/src/Extension/Lcomment.php
git commit -m "feat(lcomment): load lcomment-votes.js for progressive vote updates"
```

---

## Manual verification (real Joomla 6.1.4 site)

After `./build.sh` and installing the rebuilt `dist/pkg_lcomment.zip`
(an **upgrade**; the Joomla extension manager runs `0.4.0.sql`
automatically — confirm `#__lcomment_votes` exists afterward), run
through the spec's 10 acceptance criteria in order:

1. Click "Helpful" on a comment from another user/guest → its count goes
   up by 1, no reload, button highlighted as "mine".
2. Click "Not helpful" on the same comment → "Helpful" goes down by 1,
   "Not helpful" goes up by 1, highlight moves (never both at once).
3. Click the already-highlighted vote again → its count goes down by 1,
   no button stays highlighted.
4. Disable JavaScript, repeat step 1 → page reloads (plain
   POST+redirect), vote recorded and counts correct after reload.
5. As a registered user, try to vote on your own comment → rejected
   with the self-vote message; no JS: error message shown; with JS: no
   counter changes.
6. Vote as a guest, then vote on the same comment from a different
   browser/device (different IP and session) → both count separately.
7. Forge a POST to `vote.save` with a `vote_type` outside the 2 valid
   values → rejected, no row created.
8. Vote on a nested reply (not a top-level comment) → works identically
   to a top-level comment.
9. Vote on a comment that is pending moderation or trashed → rejected
   with `COM_LCOMMENT_ERROR_INVALID_VOTE_TARGET`; the AJAX response is
   pure JSON, nothing appended.
10. Rapid double-click on the same button, or a quick helpful→unhelpful
    swap → never more than one row in `#__lcomment_votes` for the same
    identity+comment.

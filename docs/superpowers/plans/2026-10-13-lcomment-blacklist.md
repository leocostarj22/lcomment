# LComment — Fase 3c: Blacklist Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the admin block a registered user (by `user_id`) and/or
an IP from any further interaction with LComment's comments
(commenting, replying, editing/deleting own, reacting, voting,
reporting), without affecting content that identity already posted.

**Architecture:** A new `#__lcomment_blacklist` table (one row per
blocked `type`+`value`), a pure `BlacklistPolicy` for validation and
the block decision, a DB-querying `BlacklistChecker`, and a single
enforcement point — `AbstractLcommentController::execute()` — that
all four site controllers now extend instead of `BaseController`
directly, so a blocked identity's request never reaches the actual
action method. A standard Joomla admin CRUD view (`FormController` +
`AdminController` pair, mirroring the existing Context view) lets the
admin manage entries, plus a quick-action shortcut from the admin
Comments list that pre-fills the add form.

**Tech Stack:** PHP 8, Joomla 6.1.4 MVC (`BaseController`,
`AdminModel`, `ListModel`, `FormController`, `AdminController`,
`Table`), PHPUnit 10.5.

**Spec:** `docs/superpowers/specs/2026-10-13-lcomment-blacklist-design.md`

## Global Constraints

- Package version bumps `0.7.0` → `0.8.0` (from the spec's "Alvo
  técnico" section).
- `type` is `'ip'` or `'user'` only, validated in PHP against
  `BlacklistPolicy::VALID_TYPES`, never a DB `ENUM`.
- `value` is always stored as a string (the IP, or the `user_id` cast
  to string) in a single `VARCHAR(45)` column.
- No CIDR ranges, no `guest_email`/`guest_name` blocking, no entry
  expiry, no per-admin custom messages, no `enable_blacklist` toggle —
  all explicitly out of scope per the spec's "Fora de escopo" section.
- Blocking never touches existing comments — no auto-unpublish, no
  auto-trash, ever, from this feature.
- The generic error message (`COM_LCOMMENT_ERROR_BLACKLISTED`) is the
  only thing a blocked identity ever sees — never a mention of
  "blacklist" or a reason.
- Site controllers in this codebase never get PHPUnit coverage
  (framework glue, verified live) — only pure `Administrator\Service`
  classes do. Follow this project's existing split exactly.

## Review Focus

- **IPv6 addresses.** `REMOTE_ADDR` can be `::1` or a full IPv6
  literal (colons, up to 39 chars) — `BlacklistPolicy::validateEntry()`
  must accept these via `FILTER_VALIDATE_IP` (which validates both
  v4 and v6 by default), and the column is already `VARCHAR(45)` to
  fit one. Pinned in Task 2's tests.
- **A guest (`$userId === null`) must never cause a bind-parameter
  mismatch.** `BlacklistChecker::isBlocked()` is called for every
  single site request, including anonymous ones — the query must omit
  the `user` branch entirely when there's no user id, not bind an
  empty/null value against it. Covered by Task 3's manual verification
  steps (no PHPUnit — DB-dependent glue) and explicitly called out so
  the implementer tests it live before considering Task 3 done.
- **A duplicate or malformed entry must never surface a raw DB/500
  error.** `BlacklistTable::check()` must catch both cases with a
  friendly, translated message — pinned structurally in Task 5 by
  mirroring the existing `ContextTable::check()` pattern exactly (same
  `Text::_()`-wrapped `setError()` idiom, same reason it's needed:
  `FormController::save()` doesn't translate the error text).
- **A forged direct request (no referring page, no visible UI) must
  still be blocked.** The enforcement point lives in
  `AbstractLcommentController::execute()`, which runs for every task
  dispatch regardless of how the request arrived — not hidden behind
  conditionally-rendered HTML. Pinned in Task 4's manual verification
  step (a raw `curl` POST from a blocked IP).
- **Editing `BlacklistModel::getItem()`'s pre-fill must not leak into
  a real edit of an existing entry.** The `bl_type`/`bl_value` GET
  pre-fill is only for a brand-new entry (`$pk` empty/`0`) — loading an
  *existing* entry for editing must never have its real `type`/`value`
  silently overwritten by stray `bl_type`/`bl_value` query params left
  over in the browser's address bar. Pinned in Task 5's steps with an
  explicit `$pk` guard and a written-out verification step.

---

### Task 1: Schema — `#__lcomment_blacklist` table

**Files:**
- Modify: `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`
- Create: `com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.8.0.sql`
- Modify: `com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql`
- Modify: `com_lcomment/com_lcomment.xml`

**Interfaces:**
- Consumes: nothing.
- Produces: table `#__lcomment_blacklist` with columns `id`, `type`
  (`VARCHAR(10)`), `value` (`VARCHAR(45)`), `reason` (`VARCHAR(255)
  NULL`), `created_by` (`INT UNSIGNED`), `created` (`DATETIME`), and
  `UNIQUE KEY idx_type_value (type, value)` — every later task reads
  or writes this exact table/column set.

- [ ] **Step 1: Append the new table to `install.mysql.sql`**

Open `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`
and append, after the existing `#__lcomment_reports` block, exactly:

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

- [ ] **Step 2: Create the matching migration file**

Create `com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.8.0.sql`
with the exact same `CREATE TABLE` block as Step 1 (byte-identical —
this is the pattern every prior migration file in
`sql/updates/mysql/` already follows, e.g. `0.7.0.sql` for
`#__lcomment_reports`):

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

Expected: `diff <(tail -n +2 com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql | tail -11) com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.8.0.sql` shows no differences (the two `CREATE TABLE` blocks are identical).

- [ ] **Step 3: Append the matching `DROP TABLE` to `uninstall.mysql.sql`**

Open `com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql`
and append as the last line:

```sql
DROP TABLE IF EXISTS `#__lcomment_blacklist`;
```

- [ ] **Step 4: Bump the package version**

In `com_lcomment/com_lcomment.xml`, change:

```xml
    <version>0.7.0</version>
```

to:

```xml
    <version>0.8.0</version>
```

- [ ] **Step 5: Verify all four files by hand**

Run: `grep -c "lcomment_blacklist" com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.8.0.sql com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql`

Expected: `install.mysql.sql:1`, `0.8.0.sql:1`, `uninstall.mysql.sql:1`
(each file mentions the new table exactly once). Also run: `grep
version com_lcomment/com_lcomment.xml | head -1` — Expected:
`<version>0.8.0</version>`.

- [ ] **Step 6: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql \
        com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.8.0.sql \
        com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql \
        com_lcomment/com_lcomment.xml
git commit -m "feat(lcomment): add #__lcomment_blacklist table, bump to 0.8.0"
```

---

### Task 2: `BlacklistPolicy` (pure domain class, TDD)

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Service/BlacklistPolicy.php`
- Test: `tests/Service/BlacklistPolicyTest.php`

**Interfaces:**
- Consumes: nothing (pure class, no dependency on Task 1's table).
- Produces: `BlacklistPolicy::VALID_TYPES` (array `['ip', 'user']`),
  `BlacklistPolicy::validateEntry(string $type, string $value): array`
  (returns an array of error-key strings, empty if valid), and
  `BlacklistPolicy::isBlocked(bool $userMatch, bool $ipMatch): bool`
  — Task 4's `BlacklistChecker` and Task 5's `BlacklistTable::check()`
  both call these by these exact names.

- [ ] **Step 1: Write the failing tests**

Create `tests/Service/BlacklistPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\BlacklistPolicy;
use PHPUnit\Framework\TestCase;

final class BlacklistPolicyTest extends TestCase
{
    public function testRejectsUnknownType(): void
    {
        $errors = BlacklistPolicy::validateEntry('email', '1.2.3.4');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_TYPE'], $errors);
    }

    public function testRejectsEmptyValue(): void
    {
        $errors = BlacklistPolicy::validateEntry('ip', '   ');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'], $errors);
    }

    public function testRejectsMalformedIp(): void
    {
        $errors = BlacklistPolicy::validateEntry('ip', 'not-an-ip');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'], $errors);
    }

    public function testAcceptsValidIpv4(): void
    {
        self::assertSame([], BlacklistPolicy::validateEntry('ip', '203.0.113.42'));
    }

    public function testAcceptsValidIpv6(): void
    {
        self::assertSame([], BlacklistPolicy::validateEntry('ip', '2001:db8::1'));
    }

    public function testRejectsNonNumericUserValue(): void
    {
        $errors = BlacklistPolicy::validateEntry('user', 'abc');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'], $errors);
    }

    public function testRejectsZeroUserValue(): void
    {
        $errors = BlacklistPolicy::validateEntry('user', '0');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'], $errors);
    }

    public function testRejectsNegativeUserValue(): void
    {
        $errors = BlacklistPolicy::validateEntry('user', '-5');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'], $errors);
    }

    public function testAcceptsValidUserValue(): void
    {
        self::assertSame([], BlacklistPolicy::validateEntry('user', '42'));
    }

    public function testUnknownTypeTakesPriorityOverValueFormat(): void
    {
        $errors = BlacklistPolicy::validateEntry('email', '');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_TYPE'], $errors);
    }

    public function testIsBlockedWhenNeitherMatches(): void
    {
        self::assertFalse(BlacklistPolicy::isBlocked(false, false));
    }

    public function testIsBlockedWhenOnlyUserMatches(): void
    {
        self::assertTrue(BlacklistPolicy::isBlocked(true, false));
    }

    public function testIsBlockedWhenOnlyIpMatches(): void
    {
        self::assertTrue(BlacklistPolicy::isBlocked(false, true));
    }

    public function testIsBlockedWhenBothMatch(): void
    {
        self::assertTrue(BlacklistPolicy::isBlocked(true, true));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Service/BlacklistPolicyTest.php`
Expected: FAIL — `Class "Lcsilva\Component\Lcomment\Administrator\Service\BlacklistPolicy" not found`.

- [ ] **Step 3: Write the implementation**

Create `com_lcomment/administrator/components/com_lcomment/src/Service/BlacklistPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

\defined('_JEXEC') or die;

final class BlacklistPolicy
{
    public const VALID_TYPES = ['ip', 'user'];

    /**
     * Validates a new blacklist entry before it is saved. Returns a
     * list of error keys (empty if valid).
     */
    public static function validateEntry(string $type, string $value): array
    {
        if (!\in_array($type, self::VALID_TYPES, true)) {
            return ['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_TYPE'];
        }

        $value = trim($value);

        if ($value === '') {
            return ['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'];
        }

        if ($type === 'ip' && filter_var($value, FILTER_VALIDATE_IP) === false) {
            return ['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'];
        }

        if ($type === 'user' && (!ctype_digit($value) || (int) $value <= 0)) {
            return ['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'];
        }

        return [];
    }

    /**
     * Decides whether an identity is blocked from the two facts a
     * caller already looked up (one row matched by user_id, one row
     * matched by IP). Kept as its own method, despite being a simple
     * OR, to document and pin the combining rule.
     */
    public static function isBlocked(bool $userMatch, bool $ipMatch): bool
    {
        return $userMatch || $ipMatch;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Service/BlacklistPolicyTest.php`
Expected: `OK (14 tests, 14 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/BlacklistPolicy.php \
        tests/Service/BlacklistPolicyTest.php
git commit -m "feat(lcomment): add BlacklistPolicy domain class"
```

---

### Task 3: `BlacklistChecker` + `AbstractLcommentController` (enforcement point)

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Service/BlacklistChecker.php`
- Create: `com_lcomment/components/com_lcomment/src/Controller/AbstractLcommentController.php`
- Modify: `com_lcomment/components/com_lcomment/src/Controller/CommentController.php:12,25`
- Modify: `com_lcomment/components/com_lcomment/src/Controller/ReactionController.php:12,19`
- Modify: `com_lcomment/components/com_lcomment/src/Controller/VoteController.php:12,19`
- Modify: `com_lcomment/components/com_lcomment/src/Controller/ReportController.php:12,20`
- Modify: `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`

**Interfaces:**
- Consumes: `BlacklistPolicy::isBlocked(bool, bool): bool` (Task 2);
  table `#__lcomment_blacklist` (Task 1).
- Produces: `BlacklistChecker::isBlocked(?int $userId, string $ip):
  bool`; `AbstractLcommentController` (abstract, `extends
  BaseController`, overrides `execute($task)`) — Task 5's admin view
  does not use this (admin controllers are untouched), but any future
  site controller extends this instead of `BaseController` directly.

This task has no PHPUnit coverage of its own — `BlacklistChecker`
needs a real database connection (same treatment this codebase already
gives `ReplyNotifier`/`GroupPermissionResolver`'s DB-touching callers:
framework glue, verified live, not unit-tested) and
`AbstractLcommentController` needs a real Joomla application context.
Its completion contract is the full existing suite staying green (no
regressions) plus the manual verification steps below.

- [ ] **Step 1: Create `BlacklistChecker`**

Create `com_lcomment/administrator/components/com_lcomment/src/Service/BlacklistChecker.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

final class BlacklistChecker
{
    public static function isBlocked(?int $userId, string $ip): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('type'))
            ->from($db->quoteName('#__lcomment_blacklist'));

        $condition = '(' . $db->quoteName('type') . ' = ' . $db->quote('ip')
            . ' AND ' . $db->quoteName('value') . ' = :ip)';

        if ($userId !== null) {
            $condition .= ' OR (' . $db->quoteName('type') . ' = ' . $db->quote('user')
                . ' AND ' . $db->quoteName('value') . ' = :userId)';
        }

        $query->where($condition)
            ->bind(':ip', $ip, ParameterType::STRING);

        if ($userId !== null) {
            $userIdAsString = (string) $userId;
            $query->bind(':userId', $userIdAsString, ParameterType::STRING);
        }

        $types = (array) $db->setQuery($query)->loadColumn();

        return BlacklistPolicy::isBlocked(
            \in_array('user', $types, true),
            \in_array('ip', $types, true)
        );
    }
}
```

- [ ] **Step 2: Create `AbstractLcommentController`**

Create `com_lcomment/components/com_lcomment/src/Controller/AbstractLcommentController.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Lcsilva\Component\Lcomment\Administrator\Service\BlacklistChecker;

abstract class AbstractLcommentController extends BaseController
{
    public function execute($task)
    {
        $app = Factory::getApplication();
        $input = $app->getInput();
        $user = $app->getIdentity();
        $ip = $input->server->getString('REMOTE_ADDR', '');
        $userId = ($user !== null && $user->id > 0) ? (int) $user->id : null;

        if (BlacklistChecker::isBlocked($userId, $ip)) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_BLACKLISTED'), 'error');

            $returnUrl = base64_decode($input->getBase64('return', ''));
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        return parent::execute($task);
    }
}
```

- [ ] **Step 3: Switch the 4 site controllers to extend it**

In each of the 4 files below, remove the line `use
Joomla\CMS\MVC\Controller\BaseController;` and change `extends
BaseController` to `extends AbstractLcommentController` on the class
declaration line. No other change to any of these 4 files.

`com_lcomment/components/com_lcomment/src/Controller/CommentController.php`:
- Remove line 12: `use Joomla\CMS\MVC\Controller\BaseController;`
- Change line 25 from `final class CommentController extends BaseController` to `final class CommentController extends AbstractLcommentController`

`com_lcomment/components/com_lcomment/src/Controller/ReactionController.php`:
- Remove line 12: `use Joomla\CMS\MVC\Controller\BaseController;`
- Change line 19 from `final class ReactionController extends BaseController` to `final class ReactionController extends AbstractLcommentController`

`com_lcomment/components/com_lcomment/src/Controller/VoteController.php`:
- Remove line 12: `use Joomla\CMS\MVC\Controller\BaseController;`
- Change line 19 from `final class VoteController extends BaseController` to `final class VoteController extends AbstractLcommentController`

`com_lcomment/components/com_lcomment/src/Controller/ReportController.php`:
- Remove line 12: `use Joomla\CMS\MVC\Controller\BaseController;`
- Change line 20 from `final class ReportController extends BaseController` to `final class ReportController extends AbstractLcommentController`

- [ ] **Step 4: Add the error language key (site + admin, EN + PT)**

Append to `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_ERROR_BLACKLISTED="You are not allowed to perform this action."
```

Append to `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_ERROR_BLACKLISTED="Não tem permissão para realizar esta ação."
```

Append to `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_ERROR_BLACKLISTED="You are not allowed to perform this action."
```

Append to `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_ERROR_BLACKLISTED="Não tem permissão para realizar esta ação."
```

(The admin copy is never shown by this task's code, but every other
site-visible error key in this project is duplicated admin-side too —
see Fase 3b's spec correction on this exact point — so a future admin
screen referencing the same key doesn't produce an untranslated
string.)

- [ ] **Step 5: Run the full test suite — verify no regressions**

Run: `vendor/bin/phpunit`
Expected: all tests pass (same count as before this task, since this
task adds no new PHPUnit tests — e.g. if the suite was at N tests
before Task 2, it's at N+14 now from Task 2, and still N+14 here).

- [ ] **Step 6: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/BlacklistChecker.php \
        com_lcomment/components/com_lcomment/src/Controller/AbstractLcommentController.php \
        com_lcomment/components/com_lcomment/src/Controller/CommentController.php \
        com_lcomment/components/com_lcomment/src/Controller/ReactionController.php \
        com_lcomment/components/com_lcomment/src/Controller/VoteController.php \
        com_lcomment/components/com_lcomment/src/Controller/ReportController.php \
        com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini \
        com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini \
        com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini \
        com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini
git commit -m "feat(lcomment): enforce blacklist at the site controller dispatch point"
```

**Manual verification (no automated test — DB/Joomla-dependent, do this before considering the task done):**
1. On the local Joomla test site, with no blacklist entries yet,
   confirm commenting/reacting/voting/reporting all still work
   normally (the new check defaults to "not blocked" for everyone).
2. Insert a test row directly (`INSERT INTO jos_lcomment_blacklist
   (type, value, created_by, created) VALUES ('user', '<some test
   user_id>', 1, NOW())`) and confirm that user is rejected on all 7
   actions with the generic message, while every other user is
   unaffected.
3. Insert a `type='ip'` row for your own test machine's IP and confirm
   a guest (no `user_id`) from that IP is also rejected — this
   exercises the "no `:userId` bind when `$userId` is null" path from
   the Review Focus list.
4. With that same IP entry still in place, log in as a registered user
   NOT otherwise blocked, from that same IP, and confirm they are also
   rejected (the "IP blocks everyone, not just guests" decision).
5. Send a raw `curl -X POST` directly to `index.php?option=com_lcomment&task=comment.save`
   from the blocked IP (no browser, no referring page) and confirm it
   is still rejected — this is the "forged direct request" Review
   Focus item.
6. With the Step 2 `type='user'` entry still in place, open the site
   front-end as a different, unaffected visitor and confirm that
   blocked user's own past comments are still there, still published,
   exactly as before — blocking never hides or alters existing content
   (acceptance criterion 3).

---

### Task 4: Admin "Blacklist" CRUD view

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Table/BlacklistTable.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Model/BlacklistModel.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Model/BlacklistsModel.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Controller/BlacklistController.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Controller/BlacklistsController.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/View/Blacklist/HtmlView.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/View/Blacklists/HtmlView.php`
- Create: `com_lcomment/administrator/components/com_lcomment/forms/blacklist.xml`
- Create: `com_lcomment/administrator/components/com_lcomment/tmpl/blacklist/edit.php`
- Create: `com_lcomment/administrator/components/com_lcomment/tmpl/blacklists/default.php`
- Modify: `com_lcomment/com_lcomment.xml`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.sys.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.sys.ini`

**Interfaces:**
- Consumes: `BlacklistPolicy::validateEntry(string, string): array`
  (Task 2); table `#__lcomment_blacklist` (Task 1).
- Produces: the admin "Blacklist" view, reachable at
  `index.php?option=com_lcomment&view=blacklists` (list) and
  `index.php?option=com_lcomment&task=blacklist.add` /
  `blacklist.edit&id=N` (form) — Task 5 links to
  `blacklist.add&bl_type=...&bl_value=...` from the Comments list.

No PHPUnit coverage — this is the same kind of Joomla admin MVC glue
(`AdminModel`/`ListModel`/`FormController`/`AdminController`/`Table`)
already established as untested-by-design throughout this project
(e.g. `ContextModel`/`ContextsModel`/`ContextTable`). Verified
manually.

- [ ] **Step 1: Create `BlacklistTable`**

Create `com_lcomment/administrator/components/com_lcomment/src/Table/BlacklistTable.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Lcsilva\Component\Lcomment\Administrator\Service\BlacklistPolicy;

final class BlacklistTable extends Table
{
    public function __construct(DatabaseInterface $db)
    {
        parent::__construct('#__lcomment_blacklist', 'id', $db);
    }

    public function check(): bool
    {
        $errors = BlacklistPolicy::validateEntry((string) $this->type, (string) $this->value);

        if ($errors !== []) {
            // FormController::save() surfaces this via
            // Text::sprintf('JLIB_APPLICATION_ERROR_SAVE_FAILED', ...), which
            // does not translate its argument — resolve the key ourselves or
            // the admin sees the raw language key instead of a message.
            $this->setError(Text::_($errors[0]));

            return false;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName($this->_tbl))
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('value') . ' = :value')
            ->bind(':type', $this->type, ParameterType::STRING)
            ->bind(':value', $this->value, ParameterType::STRING);

        $currentId = (int) $this->id;

        if ($currentId > 0) {
            $query->where($db->quoteName('id') . ' != :id')
                ->bind(':id', $currentId, ParameterType::INTEGER);
        }

        $count = (int) $db->setQuery($query)->loadResult();

        if ($count > 0) {
            $this->setError(Text::_('COM_LCOMMENT_ERROR_DUPLICATE_BLACKLIST_ENTRY'));

            return false;
        }

        return true;
    }
}
```

- [ ] **Step 2: Create `BlacklistModel`**

Create `com_lcomment/administrator/components/com_lcomment/src/Model/BlacklistModel.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\AdminModel;

final class BlacklistModel extends AdminModel
{
    public function getTable($type = 'Blacklist', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_lcomment.blacklist',
            'blacklist',
            ['control' => 'jform', 'load_data' => $loadData]
        );

        return $form instanceof Form ? $form : null;
    }

    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        $isNewEntry = empty($item->id);

        if ($isNewEntry) {
            $input = Factory::getApplication()->getInput();
            $prefillType = $input->getWord('bl_type', '');
            $prefillValue = $input->getString('bl_value', '');

            if ($prefillType !== '') {
                $item->type = $prefillType;
            }

            if ($prefillValue !== '') {
                $item->value = $prefillValue;
            }
        }

        return $item;
    }

    public function save($data)
    {
        if (empty($data['id'])) {
            $data['created_by'] = Factory::getApplication()->getIdentity()->id;
            $data['created'] = Factory::getDate()->toSql();
        }

        return parent::save($data);
    }
}
```

- [ ] **Step 3: Create `BlacklistsModel`**

Create `com_lcomment/administrator/components/com_lcomment/src/Model/BlacklistsModel.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

final class BlacklistsModel extends ListModel
{
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['a.id', 'a.type', 'a.value', 'a.created'];
        }

        parent::__construct($config);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('a.*')
            ->select($db->quoteName('u.name', 'created_by_name'))
            ->from($db->quoteName('#__lcomment_blacklist', 'a'))
            ->join(
                'LEFT',
                $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('a.created_by')
            );

        $search = (string) $this->getState('filter.search', '');

        if ($search !== '') {
            $likeSearch = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->where($db->quoteName('a.value') . ' LIKE :search')
                ->bind(':search', $likeSearch, ParameterType::STRING);
        }

        $ordering = $this->state->get('list.ordering', 'a.created');
        $direction = $this->state->get('list.direction', 'DESC');
        $query->order($db->escape($ordering) . ' ' . $db->escape($direction));

        return $query;
    }
}
```

- [ ] **Step 4: Create the singular and plural controllers**

Create `com_lcomment/administrator/components/com_lcomment/src/Controller/BlacklistController.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\FormController;

final class BlacklistController extends FormController
{
    protected $text_prefix = 'COM_LCOMMENT_BLACKLIST';
}
```

Create `com_lcomment/administrator/components/com_lcomment/src/Controller/BlacklistsController.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\AdminController;

final class BlacklistsController extends AdminController
{
    protected $text_prefix = 'COM_LCOMMENT_BLACKLISTS';

    public function getModel($name = 'Blacklist', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }
}
```

- [ ] **Step 5: Create the two `HtmlView` classes**

Create `com_lcomment/administrator/components/com_lcomment/src/View/Blacklist/HtmlView.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\View\Blacklist;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

final class HtmlView extends BaseHtmlView
{
    public $item;
    public $form;

    public function display($tpl = null)
    {
        $this->item = $this->get('Item');
        $this->form = $this->get('Form');

        ToolbarHelper::title(Text::_('COM_LCOMMENT_BLACKLISTS_TITLE'));
        ToolbarHelper::apply('blacklist.apply');
        ToolbarHelper::save('blacklist.save');
        ToolbarHelper::cancel('blacklist.cancel');

        parent::display($tpl);
    }
}
```

Create `com_lcomment/administrator/components/com_lcomment/src/View/Blacklists/HtmlView.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\View\Blacklists;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

final class HtmlView extends BaseHtmlView
{
    public $items;
    public $pagination;
    public $state;

    public function display($tpl = null)
    {
        $this->items = $this->get('Items');
        $this->pagination = $this->get('Pagination');
        $this->state = $this->get('State');

        ToolbarHelper::title(Text::_('COM_LCOMMENT_BLACKLISTS_TITLE'));
        ToolbarHelper::addNew('blacklist.add');
        ToolbarHelper::editList('blacklist.edit');
        ToolbarHelper::deleteList('', 'blacklists.delete');
        ToolbarHelper::preferences('com_lcomment');

        parent::display($tpl);
    }
}
```

- [ ] **Step 6: Create the form XML**

Create `com_lcomment/administrator/components/com_lcomment/forms/blacklist.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<form>
    <fieldset name="blacklist">
        <field
            name="id"
            type="number"
            default="0"
            label="JGLOBAL_FIELD_ID_LABEL"
            readonly="true"
            class="readonly"
        />
        <field
            name="type"
            type="radio"
            layout="joomla.form.field.radio.switcher"
            default="ip"
            label="COM_LCOMMENT_BLACKLIST_TYPE_LABEL"
            required="true"
        >
            <option value="ip">COM_LCOMMENT_BLACKLIST_TYPE_IP</option>
            <option value="user">COM_LCOMMENT_BLACKLIST_TYPE_USER</option>
        </field>
        <field
            name="value"
            type="text"
            label="COM_LCOMMENT_BLACKLIST_VALUE_LABEL"
            description="COM_LCOMMENT_BLACKLIST_VALUE_DESC"
            required="true"
            filter="string"
        />
        <field
            name="reason"
            type="textarea"
            rows="3"
            label="COM_LCOMMENT_BLACKLIST_REASON_LABEL"
            filter="string"
        />
        <field
            name="created_by"
            type="hidden"
        />
        <field
            name="created"
            type="hidden"
        />
    </fieldset>
</form>
```

- [ ] **Step 7: Create the two templates**

Create `com_lcomment/administrator/components/com_lcomment/tmpl/blacklist/edit.php`:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Blacklist\HtmlView $this */

// The form-validate class below requires this script, or Joomla.submitbutton()
// throws on document.formvalidator being undefined and the Save/Apply/Close
// toolbar buttons silently do nothing.
Factory::getApplication()->getDocument()->getWebAssetManager()->useScript('form.validate');
?>
<form action="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_lcomment&layout=edit&id=' . (int) $this->item->id); ?>" method="post" name="adminForm" id="adminForm" class="form-validate">
    <?php foreach ($this->form->getFieldset() as $field) : ?>
        <div class="mb-3">
            <?php echo $field->renderField(); ?>
        </div>
    <?php endforeach; ?>
    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
```

Create `com_lcomment/administrator/components/com_lcomment/tmpl/blacklists/default.php`:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Blacklists\HtmlView $this */

$listOrder = $this->state->get('list.ordering', 'a.created');
$listDirn = $this->state->get('list.direction', 'DESC');
?>
<form action="<?php echo htmlspecialchars(\Joomla\CMS\Uri\Uri::getInstance()->toString()); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row mb-3">
        <div class="col-md-4">
            <input
                type="text"
                name="filter[search]"
                value="<?php echo htmlspecialchars((string) $this->state->get('filter.search', '')); ?>"
                class="form-control"
                placeholder="<?php echo Text::_('JSEARCH_FILTER'); ?>"
                onchange="this.form.submit()"
            >
        </div>
    </div>
    <table class="table">
        <thead>
            <tr>
                <td></td>
                <th><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_BLACKLIST_TYPE_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_BLACKLIST_VALUE_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_BLACKLIST_REASON_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_BLACKLIST_CREATED_BY_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_BLACKLIST_CREATED_LABEL'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->items as $i => $item) : ?>
            <tr>
                <td></td>
                <td><?php echo HTMLHelper::_('grid.id', $i, $item->id); ?></td>
                <td>
                    <a href="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_lcomment&task=blacklist.edit&id=' . (int) $item->id); ?>">
                        <?php echo $item->type === 'ip' ? Text::_('COM_LCOMMENT_BLACKLIST_TYPE_IP') : Text::_('COM_LCOMMENT_BLACKLIST_TYPE_USER'); ?>
                    </a>
                </td>
                <td><?php echo htmlspecialchars((string) $item->value); ?></td>
                <td><?php echo htmlspecialchars(mb_strimwidth((string) $item->reason, 0, 80, '…')); ?></td>
                <td><?php echo htmlspecialchars((string) ($item->created_by_name ?: ('#' . (int) $item->created_by))); ?></td>
                <td><?php echo htmlspecialchars((string) $item->created); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php echo $this->pagination->getListFooter(); ?>
    <input type="hidden" name="task" value="">
    <input type="hidden" name="boxchecked" value="0">
    <input type="hidden" name="filter_order" value="<?php echo htmlspecialchars($listOrder); ?>">
    <input type="hidden" name="filter_order_Dir" value="<?php echo htmlspecialchars($listDirn); ?>">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
```

- [ ] **Step 8: Add the menu entry**

In `com_lcomment/com_lcomment.xml`, change:

```xml
        <submenu>
            <menu view="contexts">COM_LCOMMENT_MENU_CONTEXTS</menu>
            <menu view="comments">COM_LCOMMENT_MENU_COMMENTS</menu>
            <menu view="notifications">COM_LCOMMENT_MENU_NOTIFICATIONS</menu>
            <menu view="permissions">COM_LCOMMENT_MENU_PERMISSIONS</menu>
            <menu view="reports">COM_LCOMMENT_MENU_REPORTS</menu>
        </submenu>
```

to:

```xml
        <submenu>
            <menu view="contexts">COM_LCOMMENT_MENU_CONTEXTS</menu>
            <menu view="comments">COM_LCOMMENT_MENU_COMMENTS</menu>
            <menu view="notifications">COM_LCOMMENT_MENU_NOTIFICATIONS</menu>
            <menu view="permissions">COM_LCOMMENT_MENU_PERMISSIONS</menu>
            <menu view="reports">COM_LCOMMENT_MENU_REPORTS</menu>
            <menu view="blacklists">COM_LCOMMENT_MENU_BLACKLIST</menu>
        </submenu>
```

- [ ] **Step 9: Add the new language keys**

Append to `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_MENU_BLACKLIST="Blacklist"
COM_LCOMMENT_BLACKLISTS_TITLE="LComment: Blacklist"
COM_LCOMMENT_BLACKLIST_TYPE_LABEL="Type"
COM_LCOMMENT_BLACKLIST_TYPE_IP="IP address"
COM_LCOMMENT_BLACKLIST_TYPE_USER="User"
COM_LCOMMENT_BLACKLIST_VALUE_LABEL="Value"
COM_LCOMMENT_BLACKLIST_VALUE_DESC="The IP address, or the numeric user ID, to block."
COM_LCOMMENT_BLACKLIST_REASON_LABEL="Reason"
COM_LCOMMENT_BLACKLIST_CREATED_BY_LABEL="Added by"
COM_LCOMMENT_BLACKLIST_CREATED_LABEL="Added on"
COM_LCOMMENT_ERROR_INVALID_BLACKLIST_TYPE="Please choose a valid type."
COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE="Please enter a valid value for the chosen type."
COM_LCOMMENT_ERROR_DUPLICATE_BLACKLIST_ENTRY="This entry already exists in the blacklist."
COM_LCOMMENT_BLACKLISTS_N_ITEMS_DELETED_1="Blacklist entry deleted."
COM_LCOMMENT_BLACKLISTS_N_ITEMS_DELETED_MORE="%d blacklist entries deleted."
```

Append to `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.sys.ini`:

```ini
COM_LCOMMENT_MENU_BLACKLIST="Blacklist"
```

Append to `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_MENU_BLACKLIST="Blacklist"
COM_LCOMMENT_BLACKLISTS_TITLE="LComment: Blacklist"
COM_LCOMMENT_BLACKLIST_TYPE_LABEL="Tipo"
COM_LCOMMENT_BLACKLIST_TYPE_IP="Endereço IP"
COM_LCOMMENT_BLACKLIST_TYPE_USER="Utilizador"
COM_LCOMMENT_BLACKLIST_VALUE_LABEL="Valor"
COM_LCOMMENT_BLACKLIST_VALUE_DESC="O endereço IP, ou o ID numérico do utilizador, a bloquear."
COM_LCOMMENT_BLACKLIST_REASON_LABEL="Motivo"
COM_LCOMMENT_BLACKLIST_CREATED_BY_LABEL="Adicionado por"
COM_LCOMMENT_BLACKLIST_CREATED_LABEL="Adicionado em"
COM_LCOMMENT_ERROR_INVALID_BLACKLIST_TYPE="Por favor, escolha um tipo válido."
COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE="Por favor, introduza um valor válido para o tipo escolhido."
COM_LCOMMENT_ERROR_DUPLICATE_BLACKLIST_ENTRY="Esta entrada já existe na blacklist."
COM_LCOMMENT_BLACKLISTS_N_ITEMS_DELETED_1="Entrada da blacklist eliminada."
COM_LCOMMENT_BLACKLISTS_N_ITEMS_DELETED_MORE="%d entradas da blacklist eliminadas."
```

Append to `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.sys.ini`:

```ini
COM_LCOMMENT_MENU_BLACKLIST="Blacklist"
```

- [ ] **Step 10: Run the full test suite — verify no regressions**

Run: `vendor/bin/phpunit`
Expected: same pass count as the end of Task 3 (this task adds no new
PHPUnit tests).

- [ ] **Step 11: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Table/BlacklistTable.php \
        com_lcomment/administrator/components/com_lcomment/src/Model/BlacklistModel.php \
        com_lcomment/administrator/components/com_lcomment/src/Model/BlacklistsModel.php \
        com_lcomment/administrator/components/com_lcomment/src/Controller/BlacklistController.php \
        com_lcomment/administrator/components/com_lcomment/src/Controller/BlacklistsController.php \
        com_lcomment/administrator/components/com_lcomment/src/View/Blacklist/HtmlView.php \
        com_lcomment/administrator/components/com_lcomment/src/View/Blacklists/HtmlView.php \
        com_lcomment/administrator/components/com_lcomment/forms/blacklist.xml \
        com_lcomment/administrator/components/com_lcomment/tmpl/blacklist/edit.php \
        com_lcomment/administrator/components/com_lcomment/tmpl/blacklists/default.php \
        com_lcomment/com_lcomment.xml \
        com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini \
        com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.sys.ini \
        com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini \
        com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.sys.ini
git commit -m "feat(lcomment): add admin Blacklist CRUD view"
```

**Manual verification (no automated test — Joomla admin UI, do this before considering the task done):**
1. On the local Joomla test site (upgraded to 0.8.0), open
   `Components → LComment → Blacklist`. Confirm the list renders
   (empty at first) and "Novo" opens the form.
2. Create a `type=ip` entry with a clearly invalid value (e.g.
   `not-an-ip`) — confirm it's rejected with
   `COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE`, not a raw error page.
3. Create a valid `type=user` entry, then try to create a second entry
   with the exact same `type`+`value` — confirm it's rejected with
   `COM_LCOMMENT_ERROR_DUPLICATE_BLACKLIST_ENTRY`.
4. Edit that same existing entry (change only the `reason` field,
   leave `type`/`value` untouched) and save — confirm it saves
   successfully (this is the Review Focus case: editing an existing
   row must not trip the duplicate check against itself).
5. Manually visit `index.php?option=com_lcomment&task=blacklist.edit&id=<existing id>&bl_type=ip&bl_value=9.9.9.9`
   for that same existing entry and confirm the form shows that
   entry's REAL `type`/`value`, not `ip`/`9.9.9.9` — confirms the
   `getItem()` pre-fill only applies to a brand-new entry, per the
   Review Focus item.
6. Select the entry and delete it via the list's bulk delete — confirm
   it's removed and the identity it blocked can act again.

---

### Task 5: Quick-action links in the admin Comments list

**Files:**
- Modify: `com_lcomment/administrator/components/com_lcomment/tmpl/comments/default.php`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`

**Interfaces:**
- Consumes: `index.php?option=com_lcomment&task=blacklist.add&bl_type=...&bl_value=...`
  (Task 4's `BlacklistModel::getItem()` pre-fill).
- Produces: nothing further downstream — this is the last task in the
  plan.

No automated test — this is a template-only change to an existing,
already-untested admin list view (`CommentsModel`'s query already
selects `c.*`, so `$item->ip` and `$item->user_id` are already
available with no model change needed). Verified manually.

- [ ] **Step 1: Add the "Ações" column**

In `com_lcomment/administrator/components/com_lcomment/tmpl/comments/default.php`,
change the `<thead>` block from:

```php
            <tr>
                <td></td>
                <th><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_COMMENTS_PAGE_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_COMMENTS_AUTHOR_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_COMMENTS_TEXT_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_PUBLISHED_LABEL'); ?></th>
            </tr>
```

to:

```php
            <tr>
                <td></td>
                <th><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_COMMENTS_PAGE_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_COMMENTS_AUTHOR_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_COMMENTS_TEXT_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_PUBLISHED_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_COMMENTS_ACTIONS_LABEL'); ?></th>
            </tr>
```

Then change the row's closing `</td>` for the published-state column
(the last `<td>` in the `<?php foreach ($this->items as $i => $item) : ?>` loop) from:

```php
                <td>
                    <?php if ((int) $item->state === 1) : ?>
                        <?php echo Text::_('JPUBLISHED'); ?>
                    <?php elseif ((int) $item->state === -2) : ?>
                        <?php echo Text::_('JTRASHED'); ?>
                    <?php else : ?>
                        <?php echo Text::_('JUNPUBLISHED'); ?>
                    <?php endif; ?>
                </td>
            </tr>
```

to:

```php
                <td>
                    <?php if ((int) $item->state === 1) : ?>
                        <?php echo Text::_('JPUBLISHED'); ?>
                    <?php elseif ((int) $item->state === -2) : ?>
                        <?php echo Text::_('JTRASHED'); ?>
                    <?php else : ?>
                        <?php echo Text::_('JUNPUBLISHED'); ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($item->ip)) : ?>
                        <a href="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_lcomment&task=blacklist.add&bl_type=ip&bl_value=' . rawurlencode((string) $item->ip)); ?>">
                            <?php echo Text::_('COM_LCOMMENT_COMMENTS_BLOCK_IP_LABEL'); ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($item->user_id)) : ?>
                        <br>
                        <a href="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_lcomment&task=blacklist.add&bl_type=user&bl_value=' . (int) $item->user_id); ?>">
                            <?php echo Text::_('COM_LCOMMENT_COMMENTS_BLOCK_USER_LABEL'); ?>
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
```

- [ ] **Step 2: Add the new language keys**

Append to `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_COMMENTS_ACTIONS_LABEL="Actions"
COM_LCOMMENT_COMMENTS_BLOCK_IP_LABEL="Block IP"
COM_LCOMMENT_COMMENTS_BLOCK_USER_LABEL="Block user"
```

Append to `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_COMMENTS_ACTIONS_LABEL="Ações"
COM_LCOMMENT_COMMENTS_BLOCK_IP_LABEL="Bloquear IP"
COM_LCOMMENT_COMMENTS_BLOCK_USER_LABEL="Bloquear utilizador"
```

- [ ] **Step 3: Run the full test suite — verify no regressions**

Run: `vendor/bin/phpunit`
Expected: same pass count as the end of Task 4.

- [ ] **Step 4: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/tmpl/comments/default.php \
        com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini \
        com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini
git commit -m "feat(lcomment): add blacklist quick-action links to the admin Comments list"
```

**Manual verification (no automated test — Joomla admin UI, do this before considering the task done):**
1. Open `Components → LComment → Comments`. Confirm the new "Ações"
   column shows "Bloquear IP" for any comment that has a recorded IP,
   and "Bloquear utilizador" for any comment from a registered author
   (not a guest).
2. Click "Bloquear IP" on a comment — confirm it opens the Blacklist
   add form with `type=IP` selected and `value` pre-filled with that
   comment's exact IP.
3. Click "Bloquear utilizador" on a different comment (from a
   registered author) — confirm it opens the form with `type=Utilizador`
   and `value` pre-filled with that author's numeric `user_id`.
4. In both cases, confirm the originating comment's own state
   (published/pending) is completely unchanged after merely opening
   the form — only saving the Blacklist entry has any effect, and even
   then never on the comment itself.

---

## Final rebuild

After Task 5 is complete and the full suite is green, rebuild the
distributable package before handing off for live testing:

```bash
./build.sh
```

This produces the updated `dist/pkg_lcomment.zip` (version `0.8.0`)
ready for the admin Upload-and-Install upgrade flow on the local
Joomla test site.

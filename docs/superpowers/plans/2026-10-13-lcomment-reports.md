# LComment — Fase 3b: Denúncias Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a registered user report another person's published comment with a fixed reason; once a comment accumulates a configurable number of distinct reporters, it automatically goes back to pending for the admin to review — plus a new admin "Denúncias" view listing every report.

**Architecture:** A pure-PHP `ReportPolicy` decides whether a report is allowed (not a self-report, not a duplicate from the same user, target still published, valid reason) and whether a comment's report count has crossed the configurable auto-unpublish threshold. A new `ReportController::save()` (synchronous form + redirect, not AJAX — reporting needs a chosen reason first) enqueues the report with the same `UNIQUE KEY` + catch-and-recheck race-safety already used by reactions/votes/notifications, then re-checks the comment's live state before unpublishing it. Reports are a peer-moderation action like reactions/votes, not part of the Fase 3a group-permission matrix — gated by a single global `enable_reports` toggle instead.

**Tech Stack:** PHP 8.1+, Joomla 6.1.4 MVC, PHPUnit 10.5 for the one pure-PHP domain class, manual verification for framework-glue code (same established split as every prior phase).

**Spec:** `docs/superpowers/specs/2026-10-13-lcomment-reports-design.md`

## Global Constraints

- Target: Joomla 6.1.4 real environment (live site and/or the local
  Podman/MariaDB/`joomla:6.1.4-apache` environment — see memory
  `reference-local-joomla-test-env`), PHP 8.1+.
- Only a registered user can report (`$user->id > 0`) — never a guest,
  even by IP+session identity.
- One report per user per comment, enforced by a `UNIQUE KEY
  (comment_id, user_id)` — never undoable by the reporter themselves.
- Self-report is always blocked.
- Reason is required, one of exactly four fixed values:
  `spam`, `abusive`, `hate_speech`, `other`.
- Only a currently-published comment (`state === 1`) can be reported.
- Reaching `report_threshold` (Options, default `3`) sets the
  comment's state to `0` (pending) — never `-2` (trash), and never if
  the comment's live state is no longer `1` at that moment (re-checked,
  never assumed from earlier in the same request).
- Existing report rows are never cleared automatically when an admin
  manually republishes a comment — only the new admin "Denúncias"
  view's inherited "Eliminar" (bulk delete) action removes them.
- Reports are not part of the Fase 3a `#__lcomment_group_permissions`
  matrix — gated only by the new global `enable_reports` toggle, same
  pattern as `enable_reactions`/`enable_votes`.
- Package version bumps `0.6.0` → `0.7.0`.
- Verified against real `joomla-cms`/this project's own history before
  this plan was written: the `UNIQUE KEY` + `ExecutionFailureException`
  catch-and-recheck race-safety pattern (Fase 2c final review, reused
  unchanged in Fase 2d/2e/3a); the `AdminModel`/`Table` pair needed
  purely so `AdminController::delete()` works on a list view (corrected
  retroactively onto `Notifications` in the Fase 2e final review,
  applied here from the start instead of discovered later); admin does
  **not** auto-load the site's language `.ini` file (confirmed by this
  project's own existing duplication of shared error keys in both
  `com_lcomment.ini` files since Fase 2c/2d) — the 4 reason labels are
  therefore defined in both the site and admin `.ini` files, not once.

## Review Focus

- A comment that is already pending or trashed when a report attempt
  arrives must be rejected with a clear "cannot report" error, never
  silently counted or silently accepted — a user racing a moderator's
  unpublish action must not be able to report a comment that just
  stopped being visible to everyone else. Pinned by a `ReportPolicyTest`
  case (Task 2) for the pure decision, and by `ReportController::save()`
  re-fetching live state rather than trusting any caller-supplied value
  (Task 3).
- The auto-unpublish check must use the comment's state **at the moment
  of insertion**, not whatever state a stale page load's form last saw
  — two reports arriving concurrently for the same comment must not
  both independently decide to unpublish it (harmless if both do, since
  the second `UPDATE` is a no-op once state is already `0`, but the
  check itself must still be a fresh read, not a value threaded through
  from several steps earlier in the request). Pinned by
  `unpublishIfStillPublished()` re-loading the row via `CommentTable`
  immediately before deciding, in Task 3 — not unit-testable (framework/
  DB code), verified by a live two-reporter walk-through in the
  acceptance criteria.
- A visitor (guest) must never see, or successfully submit through a
  forged direct POST, the report form on any comment — not even a
  guest who is somehow the comment's own author (guests can't be, since
  `user_id` is `NULL` for a guest-authored comment, but the layout gate
  must not assume this and skip the registered check). Pinned by
  `ReportController::save()`'s explicit `$user->id === 0` rejection
  (Task 3, same as `CommentController::edit()`/`delete()`'s own guard
  from Fase 3a) and by the layout only ever rendering the button when
  `$currentUserId !== null` (Task 4).
- Reporting a comment whose author is a **guest** (`user_id IS NULL`)
  must still work normally — the self-report check only compares when
  `$commentAuthorUserId !== null`, so a `NULL` author can never equal
  the (always non-null, since reporters must be registered) reporter
  id, and the report must proceed. Pinned by a `ReportPolicyTest` case
  (Task 2) passing `null` as the author id.
- The admin "Denúncias" list must still render a sensible row (page
  link, comment excerpt, reporter name) for a report whose target
  comment was later deleted by its own author (Fase 3a's `state = -2`
  feature) — the list's query must use a `LEFT JOIN`, never an inner
  join, against `#__lcomment_comments`, or a deleted comment's reports
  would silently vanish from the admin's view entirely instead of still
  being eliminable. Pinned by `ReportsModel::getListQuery()`'s `LEFT
  JOIN` in Task 5, verified live in the acceptance criteria by deleting
  a reported comment and confirming its report row still lists.

---

### Task 1: Schema, migration, and global Options

**Files:**
- Modify: `com_lcomment/com_lcomment.xml`
- Modify: `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`
- Modify: `com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql`
- Create: `com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.7.0.sql`
- Modify: `com_lcomment/administrator/components/com_lcomment/config.xml`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`

**Interfaces:**
- Consumes: nothing.
- Produces: the `#__lcomment_reports` table (`id`, `comment_id`,
  `user_id`, `reason`, `created`, `UNIQUE KEY (comment_id, user_id)`),
  and the `enable_reports`/`report_threshold` component params —
  consumed by Task 3 (`ReportController::save()`) and Task 4 (the
  content plugin's `enable_reports` check).

- [ ] **Step 1: Bump the package version**

In `com_lcomment/com_lcomment.xml`, change:

```xml
    <version>0.6.0</version>
```

to:

```xml
    <version>0.7.0</version>
```

- [ ] **Step 2: Add the new table to the fresh-install SQL**

In `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`, append at the end of the file:

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

- [ ] **Step 3: Add the table to the uninstall script**

In `com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql`, add a line:

```sql
DROP TABLE IF EXISTS `#__lcomment_comments`;
DROP TABLE IF EXISTS `#__lcomment_contexts`;
DROP TABLE IF EXISTS `#__lcomment_reactions`;
DROP TABLE IF EXISTS `#__lcomment_votes`;
DROP TABLE IF EXISTS `#__lcomment_notifications`;
DROP TABLE IF EXISTS `#__lcomment_group_permissions`;
DROP TABLE IF EXISTS `#__lcomment_reports`;
```

- [ ] **Step 4: Create the upgrade migration**

Create `com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.7.0.sql`:

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

- [ ] **Step 5: Add the two new global Options**

In `com_lcomment/administrator/components/com_lcomment/config.xml`, change:

```xml
        <field
            name="enable_reply_notifications"
            type="radio"
            layout="joomla.form.field.radio.switcher"
            default="1"
            label="COM_LCOMMENT_CONFIG_ENABLE_REPLY_NOTIFICATIONS_LABEL"
        >
            <option value="0">JNO</option>
            <option value="1">JYES</option>
        </field>
    </fieldset>
```

to:

```xml
        <field
            name="enable_reply_notifications"
            type="radio"
            layout="joomla.form.field.radio.switcher"
            default="1"
            label="COM_LCOMMENT_CONFIG_ENABLE_REPLY_NOTIFICATIONS_LABEL"
        >
            <option value="0">JNO</option>
            <option value="1">JYES</option>
        </field>
        <field
            name="enable_reports"
            type="radio"
            layout="joomla.form.field.radio.switcher"
            default="1"
            label="COM_LCOMMENT_CONFIG_ENABLE_REPORTS_LABEL"
        >
            <option value="0">JNO</option>
            <option value="1">JYES</option>
        </field>
        <field
            name="report_threshold"
            type="number"
            default="3"
            label="COM_LCOMMENT_CONFIG_REPORT_THRESHOLD_LABEL"
        />
    </fieldset>
```

- [ ] **Step 6: Add the Options' language keys**

Append to `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_CONFIG_ENABLE_REPORTS_LABEL="Enable comment reporting"
COM_LCOMMENT_CONFIG_REPORT_THRESHOLD_LABEL="Reports to auto-unpublish a comment"
```

Append to `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_CONFIG_ENABLE_REPORTS_LABEL="Ativar denúncia de comentários"
COM_LCOMMENT_CONFIG_REPORT_THRESHOLD_LABEL="Denúncias para despublicar automaticamente"
```

- [ ] **Step 7: Verify the version bump, the schema diff, and run the full test suite**

Run: `grep -n '<version>' com_lcomment/com_lcomment.xml`
Expected: `8:    <version>0.7.0</version>`

Run: `diff <(sed -n '/lcomment_reports/,/utf8mb4;/p' com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql) <(sed -n '/lcomment_reports/,/utf8mb4;/p' com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.7.0.sql)`
Expected: no output.

Run: `vendor/bin/phpunit`
Expected: PASS (this task touches no PHP file the suite exercises — expect the same count as before this task, 94/94).

- [ ] **Step 8: Commit**

```bash
git add com_lcomment/com_lcomment.xml com_lcomment/administrator/components/com_lcomment/sql com_lcomment/administrator/components/com_lcomment/config.xml com_lcomment/administrator/components/com_lcomment/language
git commit -m "feat(lcomment): add lcomment_reports table and enable_reports/report_threshold options"
```

---

### Task 2: `ReportPolicy` domain class

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Service/ReportPolicy.php`
- Test: `tests/Service/ReportPolicyTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces:
  `Lcsilva\Component\Lcomment\Administrator\Service\ReportPolicy::VALID_REASONS`
  (array of 4 strings);
  `ReportPolicy::canReport(int $commentState, ?int $commentAuthorUserId, int $reporterUserId, bool $alreadyReported, string $reason): array`
  (language-key errors, empty when allowed);
  `ReportPolicy::reachesAutoUnpublishThreshold(int $totalReportsAfterInsert, int $threshold): bool`.
  Both consumed by Task 3 (`ReportController::save()`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Service/ReportPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\ReportPolicy;
use PHPUnit\Framework\TestCase;

final class ReportPolicyTest extends TestCase
{
    public function testRejectsReportingAPendingComment(): void
    {
        $errors = ReportPolicy::canReport(0, 10, 20, false, 'spam');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_REPORT_TARGET'], $errors);
    }

    public function testRejectsReportingATrashedComment(): void
    {
        $errors = ReportPolicy::canReport(-2, 10, 20, false, 'spam');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_REPORT_TARGET'], $errors);
    }

    public function testRejectsSelfReport(): void
    {
        $errors = ReportPolicy::canReport(1, 20, 20, false, 'spam');

        self::assertSame(['COM_LCOMMENT_ERROR_SELF_REPORT_NOT_ALLOWED'], $errors);
    }

    public function testAllowsReportingAGuestAuthoredComment(): void
    {
        // A guest-authored comment has no user_id to ever match the
        // (always registered) reporter's id against.
        $errors = ReportPolicy::canReport(1, null, 20, false, 'spam');

        self::assertSame([], $errors);
    }

    public function testRejectsAlreadyReported(): void
    {
        $errors = ReportPolicy::canReport(1, 10, 20, true, 'spam');

        self::assertSame(['COM_LCOMMENT_ERROR_ALREADY_REPORTED'], $errors);
    }

    public function testRejectsInvalidReason(): void
    {
        $errors = ReportPolicy::canReport(1, 10, 20, false, 'not-a-real-reason');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_REPORT_REASON'], $errors);
    }

    public function testAllowsEachValidReason(): void
    {
        foreach (ReportPolicy::VALID_REASONS as $reason) {
            $errors = ReportPolicy::canReport(1, 10, 20, false, $reason);

            self::assertSame([], $errors, "Expected reason '{$reason}' to be accepted");
        }
    }

    public function testInvalidTargetTakesPriorityOverSelfReport(): void
    {
        $errors = ReportPolicy::canReport(0, 20, 20, false, 'spam');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_REPORT_TARGET'], $errors);
    }

    public function testSelfReportTakesPriorityOverAlreadyReported(): void
    {
        $errors = ReportPolicy::canReport(1, 20, 20, true, 'spam');

        self::assertSame(['COM_LCOMMENT_ERROR_SELF_REPORT_NOT_ALLOWED'], $errors);
    }

    public function testAlreadyReportedTakesPriorityOverInvalidReason(): void
    {
        $errors = ReportPolicy::canReport(1, 10, 20, true, 'not-a-real-reason');

        self::assertSame(['COM_LCOMMENT_ERROR_ALREADY_REPORTED'], $errors);
    }

    public function testBelowThresholdDoesNotTrigger(): void
    {
        self::assertFalse(ReportPolicy::reachesAutoUnpublishThreshold(2, 3));
    }

    public function testAtThresholdTriggers(): void
    {
        self::assertTrue(ReportPolicy::reachesAutoUnpublishThreshold(3, 3));
    }

    public function testAboveThresholdTriggers(): void
    {
        self::assertTrue(ReportPolicy::reachesAutoUnpublishThreshold(4, 3));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Service/ReportPolicyTest.php`
Expected: FAIL — `Class "Lcsilva\Component\Lcomment\Administrator\Service\ReportPolicy" not found`.

- [ ] **Step 3: Implement `ReportPolicy`**

Create `com_lcomment/administrator/components/com_lcomment/src/Service/ReportPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class ReportPolicy
{
    public const VALID_REASONS = ['spam', 'abusive', 'hate_speech', 'other'];

    /**
     * @return string[] Language keys of validation errors; empty when allowed.
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

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Service/ReportPolicyTest.php`
Expected: PASS (13 tests).

- [ ] **Step 5: Run the full suite and commit**

Run: `vendor/bin/phpunit`
Expected: PASS, full suite (94 + 13 = 107).

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/ReportPolicy.php tests/Service/ReportPolicyTest.php
git commit -m "feat(lcomment): add ReportPolicy domain class"
```

---

### Task 3: `ReportController::save()` — site action

**Files:**
- Create: `com_lcomment/components/com_lcomment/src/Controller/ReportController.php`
- Modify: `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`

**Interfaces:**
- Consumes: `ReportPolicy::canReport()`/`reachesAutoUnpublishThreshold()`
  (Task 2), the `#__lcomment_reports` table and
  `enable_reports`/`report_threshold` params (Task 1).
- Produces: `task=report.save`, reachable from the site. Consumed by
  Task 4 (the layout's new report form submits here).
- No PHPUnit coverage possible (framework/DB code, same established
  split as `ReactionController`/`VoteController`/`CommentController`).

- [ ] **Step 1: Create `ReportController`**

Create `com_lcomment/components/com_lcomment/src/Controller/ReportController.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;
use Lcsilva\Component\Lcomment\Administrator\Service\ReportPolicy;
use Lcsilva\Component\Lcomment\Administrator\Table\CommentTable;

final class ReportController extends BaseController
{
    public function save(): bool
    {
        Session::checkToken('post') or die(Text::_('JINVALID_TOKEN'));

        $app = Factory::getApplication();
        $input = $app->getInput();
        $returnUrl = base64_decode($input->getBase64('return', ''));

        $params = ComponentHelper::getParams('com_lcomment');

        if (!(bool) $params->get('enable_reports', 1)) {
            return $this->fail($app, $returnUrl, 'COM_LCOMMENT_ERROR_REPORTS_DISABLED');
        }

        $user = $app->getIdentity();

        if ($user === null || $user->id === 0) {
            return $this->fail($app, $returnUrl, 'COM_LCOMMENT_ERROR_NOT_OWNER');
        }

        $commentId = $input->getInt('comment_id', 0);
        $reason = $input->getCmd('reason', '');
        $reporterId = (int) $user->id;

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $comment = $this->findCommentForReporting($db, $commentId);

        $commentState = $comment['state'] ?? -99;
        $commentAuthorUserId = $comment['user_id'] ?? null;
        $alreadyReported = $this->hasAlreadyReported($db, $commentId, $reporterId);

        $errors = ReportPolicy::canReport($commentState, $commentAuthorUserId, $reporterId, $alreadyReported, $reason);

        if ($errors !== []) {
            return $this->fail($app, $returnUrl, $errors[0]);
        }

        $this->insertReport($db, $commentId, $reporterId, $reason);

        $threshold = (int) $params->get('report_threshold', 3);
        $total = $this->countReports($db, $commentId);

        if (ReportPolicy::reachesAutoUnpublishThreshold($total, $threshold)) {
            $this->unpublishIfStillPublished($db, $commentId);
        }

        $app->enqueueMessage(Text::_('COM_LCOMMENT_REPORT_SUCCESS'));
        $app->redirect($returnUrl ?: 'index.php');

        return true;
    }

    private function fail($app, string $returnUrl, string $errorKey): bool
    {
        $app->enqueueMessage(Text::_($errorKey), 'error');
        $app->redirect($returnUrl ?: 'index.php');

        return false;
    }

    /**
     * @return array{state: int, user_id: ?int}|null
     */
    private function findCommentForReporting(DatabaseInterface $db, int $commentId): ?array
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

    private function hasAlreadyReported(DatabaseInterface $db, int $commentId, int $userId): bool
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__lcomment_reports'))
            ->where($db->quoteName('comment_id') . ' = :commentId')
            ->where($db->quoteName('user_id') . ' = :userId')
            ->bind(':commentId', $commentId, ParameterType::INTEGER)
            ->bind(':userId', $userId, ParameterType::INTEGER);

        $db->setQuery($query);

        return (int) $db->loadResult() > 0;
    }

    private function insertReport(DatabaseInterface $db, int $commentId, int $userId, string $reason): void
    {
        $created = Factory::getDate()->toSql();

        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__lcomment_reports'))
            ->columns([
                $db->quoteName('comment_id'),
                $db->quoteName('user_id'),
                $db->quoteName('reason'),
                $db->quoteName('created'),
            ])
            ->values(':commentId, :userId, :reason, :created')
            ->bind(':commentId', $commentId, ParameterType::INTEGER)
            ->bind(':userId', $userId, ParameterType::INTEGER)
            ->bind(':reason', $reason, ParameterType::STRING)
            ->bind(':created', $created, ParameterType::STRING);

        $db->setQuery($query);

        try {
            $db->execute();
        } catch (ExecutionFailureException $exception) {
            // Same race-safety as reactions/votes/notifications since
            // Fase 2c: only swallow the failure if a row for this
            // identity now exists (confirms the UNIQUE KEY caught a
            // concurrent duplicate), otherwise this is a real failure
            // and must not be hidden.
            if (!$this->hasAlreadyReported($db, $commentId, $userId)) {
                throw $exception;
            }
        }
    }

    private function countReports(DatabaseInterface $db, int $commentId): int
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__lcomment_reports'))
            ->where($db->quoteName('comment_id') . ' = :commentId')
            ->bind(':commentId', $commentId, ParameterType::INTEGER);

        $db->setQuery($query);

        return (int) $db->loadResult();
    }

    private function unpublishIfStillPublished(DatabaseInterface $db, int $commentId): void
    {
        $table = new CommentTable($db);

        if (!$table->load($commentId) || (int) $table->state !== 1) {
            return;
        }

        $table->state = 0;
        $table->store();
    }
}
```

- [ ] **Step 2: Add the new language keys**

Append to `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_ERROR_REPORTS_DISABLED="Reporting is not enabled on this site."
COM_LCOMMENT_ERROR_INVALID_REPORT_TARGET="You cannot report that comment."
COM_LCOMMENT_ERROR_SELF_REPORT_NOT_ALLOWED="You cannot report your own comment."
COM_LCOMMENT_ERROR_ALREADY_REPORTED="You already reported this comment."
COM_LCOMMENT_ERROR_INVALID_REPORT_REASON="Please choose a valid reason."
COM_LCOMMENT_REPORT_SUCCESS="Your report was submitted."
COM_LCOMMENT_REPORT_LABEL="Report"
COM_LCOMMENT_REPORT_CONFIRM_LABEL="Submit report"
COM_LCOMMENT_REPORT_REASON_LABEL="Reason"
COM_LCOMMENT_REPORT_REASON_SPAM="Spam"
COM_LCOMMENT_REPORT_REASON_ABUSIVE="Abusive or offensive"
COM_LCOMMENT_REPORT_REASON_HATE_SPEECH="Hate speech"
COM_LCOMMENT_REPORT_REASON_OTHER="Other"
```

Append to `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_ERROR_REPORTS_DISABLED="As denúncias não estão ativadas neste site."
COM_LCOMMENT_ERROR_INVALID_REPORT_TARGET="Não é possível denunciar esse comentário."
COM_LCOMMENT_ERROR_SELF_REPORT_NOT_ALLOWED="Não pode denunciar o seu próprio comentário."
COM_LCOMMENT_ERROR_ALREADY_REPORTED="Já denunciou este comentário."
COM_LCOMMENT_ERROR_INVALID_REPORT_REASON="Por favor, escolha um motivo válido."
COM_LCOMMENT_REPORT_SUCCESS="A sua denúncia foi registada."
COM_LCOMMENT_REPORT_LABEL="Denunciar"
COM_LCOMMENT_REPORT_CONFIRM_LABEL="Confirmar denúncia"
COM_LCOMMENT_REPORT_REASON_LABEL="Motivo"
COM_LCOMMENT_REPORT_REASON_SPAM="Spam"
COM_LCOMMENT_REPORT_REASON_ABUSIVE="Ofensivo/abusivo"
COM_LCOMMENT_REPORT_REASON_HATE_SPEECH="Discurso de ódio"
COM_LCOMMENT_REPORT_REASON_OTHER="Outro"
```

- [ ] **Step 3: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/components/com_lcomment/src/Controller/ReportController.php && vendor/bin/phpunit`
Expected: `No syntax errors detected...` followed by PASS on the full suite.

- [ ] **Step 4: Commit**

```bash
git add com_lcomment/components/com_lcomment/src/Controller/ReportController.php com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini
git commit -m "feat(lcomment): add ReportController::save() site action"
```

---

### Task 4: Site model, layout UI, plugin wiring

**Files:**
- Modify: `com_lcomment/components/com_lcomment/src/Model/CommentModel.php`
- Modify: `com_lcomment/components/com_lcomment/layouts/comment.php`
- Modify: `plg_content_lcomment/src/Extension/Lcomment.php`

**Interfaces:**
- Consumes: `#__lcomment_reports` table (Task 1).
- Produces: `CommentModel::getReportedCommentIds(int $userId): array<int,
  true>` (site), passed to the layout as `$reportedCommentIds`. No
  later task consumes this directly — it's the UI leaf for the report
  button's visibility.
- No PHPUnit coverage possible (framework/DB/layout code).

- [ ] **Step 1: Add `getReportedCommentIds()` to the site `CommentModel`**

In `com_lcomment/components/com_lcomment/src/Model/CommentModel.php`, add this method (anywhere among the other public methods — e.g. right after `getGroupPermissions()`):

```php
    /**
     * @return array<int, true> Set of comment ids this user has already
     *   reported — presence in the array means "already reported", check
     *   with isset().
     */
    public function getReportedCommentIds(int $userId): array
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select($db->quoteName('comment_id'))
            ->from($db->quoteName('#__lcomment_reports'))
            ->where($db->quoteName('user_id') . ' = :userId')
            ->bind(':userId', $userId, ParameterType::INTEGER);

        $db->setQuery($query);

        $result = [];

        foreach ($db->loadColumn() as $commentId) {
            $result[(int) $commentId] = true;
        }

        return $result;
    }
```

- [ ] **Step 2: Wire `enable_reports` and the reported-ids set into the content plugin**

In `plg_content_lcomment/src/Extension/Lcomment.php`, change:

```php
        $params = ComponentHelper::getParams('com_lcomment');
        $reactionsEnabled = (bool) $params->get('enable_reactions', 1);
        $votesEnabled = (bool) $params->get('enable_votes', 1);

        // Skip the query entirely when the feature is off, not just the
        // display — no point aggregating data nobody can see or act on.
        $reactions = $reactionsEnabled ? $model->getReactionsFor($commentIds) : [];
        $votes = $votesEnabled ? $model->getVotesFor($commentIds) : [];
        $authorNames = $model->getAuthorNames(array_map(static fn ($comment) => $comment->user_id, $items));
        $returnUrl = Uri::getInstance()->toString();

        $currentUser = $app->getIdentity();
        $userPermissions = GroupPermissionResolver::resolve(
            $model->getGroupPermissions(),
            $currentUser->getAuthorisedGroups()
        );
```

to:

```php
        $params = ComponentHelper::getParams('com_lcomment');
        $reactionsEnabled = (bool) $params->get('enable_reactions', 1);
        $votesEnabled = (bool) $params->get('enable_votes', 1);
        $reportsEnabled = (bool) $params->get('enable_reports', 1);

        // Skip the query entirely when the feature is off, not just the
        // display — no point aggregating data nobody can see or act on.
        $reactions = $reactionsEnabled ? $model->getReactionsFor($commentIds) : [];
        $votes = $votesEnabled ? $model->getVotesFor($commentIds) : [];
        $authorNames = $model->getAuthorNames(array_map(static fn ($comment) => $comment->user_id, $items));
        $returnUrl = Uri::getInstance()->toString();

        $currentUser = $app->getIdentity();
        $userPermissions = GroupPermissionResolver::resolve(
            $model->getGroupPermissions(),
            $currentUser->getAuthorisedGroups()
        );

        // Only a registered user can report at all — never query this
        // for a guest, there's nothing it could ever return for one.
        $reportedCommentIds = ($reportsEnabled && $currentUser->id > 0)
            ? $model->getReportedCommentIds((int) $currentUser->id)
            : [];
```

Then change:

```php
                'authorNames' => $authorNames,
                'returnUrl' => $returnUrl,
                'userPermissions' => $userPermissions,
                'currentUserId' => $currentUser->id > 0 ? (int) $currentUser->id : null,
            ],
            \JPATH_ROOT . '/components/com_lcomment/layouts'
        );
```

to:

```php
                'authorNames' => $authorNames,
                'returnUrl' => $returnUrl,
                'userPermissions' => $userPermissions,
                'currentUserId' => $currentUser->id > 0 ? (int) $currentUser->id : null,
                'reportsEnabled' => $reportsEnabled,
                'reportedCommentIds' => $reportedCommentIds,
            ],
            \JPATH_ROOT . '/components/com_lcomment/layouts'
        );
```

- [ ] **Step 3: Render the "Denunciar" form in the layout**

In `com_lcomment/components/com_lcomment/layouts/comment.php`, add a new render closure right after `$renderDeleteButton` and before `$renderNode` — change:

```php
$renderNode = function (array $node, int $depth) use (&$renderNode, $renderForm, $renderReactions, $renderVotes, $renderEditForm, $renderDeleteButton, $prefillParentId, $prefillText, $authorNames, $reactionsEnabled, $votesEnabled, $userPermissions, $currentUserId, $returnUrl): void {
```

to:

```php
$renderReportForm = function (int $commentId, string $returnUrlLocal): void {
    ?>
    <details class="lcomment-report">
        <summary><?php echo Text::_('COM_LCOMMENT_REPORT_LABEL'); ?></summary>
        <form method="post" action="<?php echo Route::_('index.php?option=com_lcomment&task=report.save'); ?>" class="lcomment-form">
            <input type="hidden" name="comment_id" value="<?php echo $commentId; ?>">
            <input type="hidden" name="return" value="<?php echo base64_encode($returnUrlLocal); ?>">
            <div class="mb-2">
                <label for="lcomment-report-reason-<?php echo $commentId; ?>"><?php echo Text::_('COM_LCOMMENT_REPORT_REASON_LABEL'); ?></label>
                <select id="lcomment-report-reason-<?php echo $commentId; ?>" name="reason" class="form-select">
                    <option value="spam"><?php echo Text::_('COM_LCOMMENT_REPORT_REASON_SPAM'); ?></option>
                    <option value="abusive"><?php echo Text::_('COM_LCOMMENT_REPORT_REASON_ABUSIVE'); ?></option>
                    <option value="hate_speech"><?php echo Text::_('COM_LCOMMENT_REPORT_REASON_HATE_SPEECH'); ?></option>
                    <option value="other"><?php echo Text::_('COM_LCOMMENT_REPORT_REASON_OTHER'); ?></option>
                </select>
            </div>
            <?php echo HTMLHelper::_('form.token'); ?>
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <?php echo Text::_('COM_LCOMMENT_REPORT_CONFIRM_LABEL'); ?>
            </button>
        </form>
    </details>
    <?php
};

$renderNode = function (array $node, int $depth) use (&$renderNode, $renderForm, $renderReactions, $renderVotes, $renderEditForm, $renderDeleteButton, $renderReportForm, $prefillParentId, $prefillText, $authorNames, $reactionsEnabled, $votesEnabled, $userPermissions, $currentUserId, $returnUrl, $reportsEnabled, $reportedCommentIds): void {
```

Then, inside `$renderNode`'s non-deleted branch, change:

```php
            <?php if ($currentUserId !== null && (int) $comment->user_id === $currentUserId) : ?>
                <?php if ($userPermissions['can_edit_own']) : ?>
                    <?php $renderEditForm($commentId, (string) $comment->comment_text, $returnUrl); ?>
                <?php endif; ?>
                <?php if ($userPermissions['can_delete_own']) : ?>
                    <?php $renderDeleteButton($commentId, $returnUrl); ?>
                <?php endif; ?>
            <?php endif; ?>

            <details class="lcomment-reply"<?php echo $isReplyOpen ? ' open' : ''; ?>>
```

to:

```php
            <?php if ($currentUserId !== null && (int) $comment->user_id === $currentUserId) : ?>
                <?php if ($userPermissions['can_edit_own']) : ?>
                    <?php $renderEditForm($commentId, (string) $comment->comment_text, $returnUrl); ?>
                <?php endif; ?>
                <?php if ($userPermissions['can_delete_own']) : ?>
                    <?php $renderDeleteButton($commentId, $returnUrl); ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($reportsEnabled && $currentUserId !== null && (int) $comment->user_id !== $currentUserId && !isset($reportedCommentIds[$commentId])) : ?>
                <?php $renderReportForm($commentId, $returnUrl); ?>
            <?php endif; ?>

            <details class="lcomment-reply"<?php echo $isReplyOpen ? ' open' : ''; ?>>
```

- [ ] **Step 4: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/components/com_lcomment/src/Model/CommentModel.php && php -l com_lcomment/components/com_lcomment/layouts/comment.php && php -l plg_content_lcomment/src/Extension/Lcomment.php && vendor/bin/phpunit`
Expected: three `No syntax errors detected...` lines, then PASS on the full suite.

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/components/com_lcomment/src/Model/CommentModel.php com_lcomment/components/com_lcomment/layouts/comment.php plg_content_lcomment/src/Extension/Lcomment.php
git commit -m "feat(lcomment): render the report-comment UI and wire enable_reports"
```

- [ ] **Step 6: Manual check to perform later, during live Joomla verification (not automatable here)**

Confirm a guest-authored comment (no account) can still be reported
normally by a registered user — this is the Review Focus item about
`$commentAuthorUserId !== null` only mattering for the comparison, not
for whether reporting is allowed at all.

---

### Task 5: Admin "Denúncias" view, final packaging

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Model/ReportsModel.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Model/ReportModel.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Table/ReportTable.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Controller/ReportsController.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/View/Reports/HtmlView.php`
- Create: `com_lcomment/administrator/components/com_lcomment/tmpl/reports/default.php`
- Modify: `com_lcomment/com_lcomment.xml`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.sys.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.sys.ini`

**Interfaces:**
- Consumes: nothing from an earlier task directly (reads
  `#__lcomment_reports`/`#__lcomment_comments`/`#__users` — Task 1 and
  pre-existing tables — on its own).
- Produces: the admin screen at
  `index.php?option=com_lcomment&view=reports`. No later task consumes
  this.
- No PHPUnit coverage possible (framework/view code, same precedent as
  `Comments`/`Notifications`/`Permissions`).

- [ ] **Step 1: Add the submenu entry and the menu language keys**

In `com_lcomment/com_lcomment.xml`, change:

```xml
            <menu view="permissions">COM_LCOMMENT_MENU_PERMISSIONS</menu>
        </submenu>
```

to:

```xml
            <menu view="permissions">COM_LCOMMENT_MENU_PERMISSIONS</menu>
            <menu view="reports">COM_LCOMMENT_MENU_REPORTS</menu>
        </submenu>
```

Append to `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.sys.ini`:

```ini
COM_LCOMMENT_MENU_REPORTS="Reports"
```

Append to `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.sys.ini`:

```ini
COM_LCOMMENT_MENU_REPORTS="Denúncias"
```

- [ ] **Step 2: Add the view's own language keys**

Append to `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_MENU_REPORTS="Reports"
COM_LCOMMENT_REPORTS_TITLE="LComment: Reports"
COM_LCOMMENT_REPORTS_PAGE_LABEL="Page"
COM_LCOMMENT_REPORTS_COMMENT_LABEL="Comment"
COM_LCOMMENT_REPORTS_REPORTER_LABEL="Reporter"
COM_LCOMMENT_REPORTS_REASON_LABEL="Reason"
COM_LCOMMENT_REPORTS_CREATED_LABEL="Created"
COM_LCOMMENT_REPORTS_N_ITEMS_DELETED_1="Report deleted."
COM_LCOMMENT_REPORTS_N_ITEMS_DELETED_MORE="%d reports deleted."
COM_LCOMMENT_REPORT_REASON_SPAM="Spam"
COM_LCOMMENT_REPORT_REASON_ABUSIVE="Abusive or offensive"
COM_LCOMMENT_REPORT_REASON_HATE_SPEECH="Hate speech"
COM_LCOMMENT_REPORT_REASON_OTHER="Other"
```

(The four `COM_LCOMMENT_REPORT_REASON_*` keys are duplicated here from
the site's `.ini` — confirmed in this plan's Global Constraints that
the admin application does not auto-load the site's language file, so
the admin list's "Motivo" column needs its own copy of these same
keys, exactly like the site/admin split already used for other shared
strings since Fase 2c/2d.)

Append to `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_MENU_REPORTS="Denúncias"
COM_LCOMMENT_REPORTS_TITLE="LComment: Denúncias"
COM_LCOMMENT_REPORTS_PAGE_LABEL="Página"
COM_LCOMMENT_REPORTS_COMMENT_LABEL="Comentário"
COM_LCOMMENT_REPORTS_REPORTER_LABEL="Denunciante"
COM_LCOMMENT_REPORTS_REASON_LABEL="Motivo"
COM_LCOMMENT_REPORTS_CREATED_LABEL="Data"
COM_LCOMMENT_REPORTS_N_ITEMS_DELETED_1="Denúncia eliminada."
COM_LCOMMENT_REPORTS_N_ITEMS_DELETED_MORE="%d denúncias eliminadas."
COM_LCOMMENT_REPORT_REASON_SPAM="Spam"
COM_LCOMMENT_REPORT_REASON_ABUSIVE="Ofensivo/abusivo"
COM_LCOMMENT_REPORT_REASON_HATE_SPEECH="Discurso de ódio"
COM_LCOMMENT_REPORT_REASON_OTHER="Outro"
```

- [ ] **Step 3: Create `ReportTable` and `ReportModel`**

Create `com_lcomment/administrator/components/com_lcomment/src/Table/ReportTable.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;

final class ReportTable extends Table
{
    public function __construct(DatabaseInterface $db)
    {
        parent::__construct('#__lcomment_reports', 'id', $db);
    }
}
```

Create `com_lcomment/administrator/components/com_lcomment/src/Model/ReportModel.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\AdminModel;

final class ReportModel extends AdminModel
{
    public function getTable($type = 'Report', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function getForm($data = [], $loadData = true)
    {
        // Reports are logged events, never created or edited through a
        // form — only listed (ReportsModel) and deleted
        // (AdminController::delete(), inherited by ReportsController).
        return false;
    }
}
```

This pair exists purely so `AdminController::delete()` (inherited by
`ReportsController`) has a real `delete()` method to call —
`ListModel` (used by `ReportsModel` below, for the list page itself)
has no such method. Same pair, same reason, as `NotificationModel`/
`NotificationTable` added retroactively in the Fase 2e final review;
here it ships correctly from the start.

- [ ] **Step 4: Create `ReportsModel`**

Create `com_lcomment/administrator/components/com_lcomment/src/Model/ReportsModel.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;

final class ReportsModel extends ListModel
{
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'reason', 'created'];
        }

        parent::__construct($config);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();

        // LEFT JOIN, not an inner join: a reported comment the author
        // later deleted themselves (Fase 3a, state = -2) must still
        // show up here so the admin can still see and eliminate the
        // report — losing it from this list just because the comment
        // is gone would make an existing report permanently
        // un-eliminable through the normal UI.
        $query = $db->getQuery(true)
            ->select('r.*')
            ->select($db->quoteName('u.name', 'reporter_name'))
            ->select($db->quoteName('c.comment_text'))
            ->select($db->quoteName('c.item_url'))
            ->select($db->quoteName('c.extension'))
            ->select($db->quoteName('c.view'))
            ->from($db->quoteName('#__lcomment_reports', 'r'))
            ->join(
                'LEFT',
                $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('r.user_id')
            )
            ->join(
                'LEFT',
                $db->quoteName('#__lcomment_comments', 'c') . ' ON ' . $db->quoteName('c.id') . ' = ' . $db->quoteName('r.comment_id')
            );

        $ordering = $this->state->get('list.ordering', 'r.created');
        $direction = $this->state->get('list.direction', 'DESC');
        $query->order($db->escape($ordering) . ' ' . $db->escape($direction));

        return $query;
    }
}
```

- [ ] **Step 5: Create `ReportsController`**

Create `com_lcomment/administrator/components/com_lcomment/src/Controller/ReportsController.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\AdminController;

final class ReportsController extends AdminController
{
    protected $text_prefix = 'COM_LCOMMENT_REPORTS';

    public function getModel($name = 'Report', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }
}
```

- [ ] **Step 6: Create the view and template**

Create `com_lcomment/administrator/components/com_lcomment/src/View/Reports/HtmlView.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\View\Reports;

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

        ToolbarHelper::title(Text::_('COM_LCOMMENT_REPORTS_TITLE'));
        ToolbarHelper::deleteList('', 'reports.delete');
        ToolbarHelper::preferences('com_lcomment');

        parent::display($tpl);
    }
}
```

Create `com_lcomment/administrator/components/com_lcomment/tmpl/reports/default.php`:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Reports\HtmlView $this */

$listOrder = $this->state->get('list.ordering', 'r.created');
$listDirn = $this->state->get('list.direction', 'DESC');

$reasonLabels = [
    'spam' => 'COM_LCOMMENT_REPORT_REASON_SPAM',
    'abusive' => 'COM_LCOMMENT_REPORT_REASON_ABUSIVE',
    'hate_speech' => 'COM_LCOMMENT_REPORT_REASON_HATE_SPEECH',
    'other' => 'COM_LCOMMENT_REPORT_REASON_OTHER',
];
?>
<form action="<?php echo htmlspecialchars(\Joomla\CMS\Uri\Uri::getInstance()->toString()); ?>" method="post" name="adminForm" id="adminForm">
    <table class="table">
        <thead>
            <tr>
                <th><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_REPORTS_PAGE_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_REPORTS_COMMENT_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_REPORTS_REPORTER_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_REPORTS_REASON_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_REPORTS_CREATED_LABEL'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->items as $i => $item) : ?>
            <tr>
                <td><?php echo HTMLHelper::_('grid.id', $i, $item->id); ?></td>
                <td>
                    <?php $pageLabel = htmlspecialchars((string) $item->extension . ' / ' . (string) $item->view); ?>
                    <?php if (!empty($item->item_url)) : ?>
                        <a href="<?php echo htmlspecialchars((string) $item->item_url); ?>" target="_blank" rel="noopener"><?php echo $pageLabel; ?></a>
                    <?php else : ?>
                        <?php echo $pageLabel; ?>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars(mb_strimwidth((string) $item->comment_text, 0, 80, '…')); ?></td>
                <td><?php echo htmlspecialchars((string) ($item->reporter_name ?: ('#' . (int) $item->user_id))); ?></td>
                <td><?php echo Text::_($reasonLabels[$item->reason] ?? $item->reason); ?></td>
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

- [ ] **Step 7: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/administrator/components/com_lcomment/src/Table/ReportTable.php && php -l com_lcomment/administrator/components/com_lcomment/src/Model/ReportModel.php && php -l com_lcomment/administrator/components/com_lcomment/src/Model/ReportsModel.php && php -l com_lcomment/administrator/components/com_lcomment/src/Controller/ReportsController.php && php -l com_lcomment/administrator/components/com_lcomment/src/View/Reports/HtmlView.php && php -l com_lcomment/administrator/components/com_lcomment/tmpl/reports/default.php && vendor/bin/phpunit`
Expected: six `No syntax errors detected...` lines, then PASS on the full suite.

- [ ] **Step 8: Commit**

```bash
git add com_lcomment/com_lcomment.xml com_lcomment/administrator/components/com_lcomment/language com_lcomment/administrator/components/com_lcomment/src/Model/ReportsModel.php com_lcomment/administrator/components/com_lcomment/src/Model/ReportModel.php com_lcomment/administrator/components/com_lcomment/src/Table/ReportTable.php com_lcomment/administrator/components/com_lcomment/src/Controller/ReportsController.php com_lcomment/administrator/components/com_lcomment/src/View/Reports/HtmlView.php com_lcomment/administrator/components/com_lcomment/tmpl/reports/default.php
git commit -m "feat(lcomment): add admin Reports view"
```

- [ ] **Step 9: Rebuild the package**

Run: `./build.sh`
Expected: `Built .../dist/pkg_lcomment.zip` — no `build.sh` changes were
needed (no new plugin was added; the zip already archives the whole
`com_lcomment/` folder recursively).

- [ ] **Step 10: Manual check to perform later, during live Joomla verification (not automatable here)**

Delete a reported comment as its own author (Fase 3a's delete-own-
comment feature), then open the admin Reports view and confirm its
report row is still listed (page link and comment excerpt may show
stale/blank values for the comment side, but the row itself must not
vanish), and that selecting it and clicking "Eliminar" still removes
it cleanly. This is the Review Focus item about the `LEFT JOIN`.

---

## Manual verification (real Joomla 6.1.4 site)

After installing the rebuilt `dist/pkg_lcomment.zip` (an **upgrade** —
confirm `#__lcomment_reports` exists afterward, and that
`enable_reports`/`report_threshold` appear under Components → LComment
→ Options), run through the spec's 9 acceptance criteria in order (see
the spec's own "Critério de aceite" section), plus the two
manual-check notes left in Tasks 4 and 5 above.

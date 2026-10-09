# LComment — Fase 2e: Notificação de Resposta — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Email a comment's registered author when someone replies directly to it — only once the reply is published, via a retryable notification queue (not a synchronous send), with both a manual admin button and a Joomla Scheduled Task to process it.

**Architecture:** A pure-PHP `ReplyNotificationPolicy` decides *whether* to notify. `ReplyNotifier` (framework service) enqueues a fully-composed row into a new `#__lcomment_notifications` table from two trigger points — `CommentController::save()` (site, moderation off) and an overridden `CommentModel::publish()` (admin) — never sending anything itself. `NotificationQueueProcessor` (framework service) is the only thing that actually sends mail, called from a new admin "Notifications" list view's toolbar button and from a new third package plugin (`plg_task_lcomment`, group `task`) that registers a routine with Joomla's native `com_scheduler`. Every Joomla API this plan touches for the first time (Mail, task plugins, `AdminModel::publish()`'s by-reference pruning) was verified against real `joomla-cms` source while writing the spec — see the spec's "Alvo técnico" section and the per-task notes below.

**Tech Stack:** PHP 8.1+, Joomla 6.1.4 MVC, PHPUnit 10.5 for the one pure-PHP domain class, Joomla's native Mail API and `com_scheduler` task-plugin API (both first use in this project).

**Spec:** `docs/superpowers/specs/2026-10-09-lcomment-reply-notifications-design.md`

## Global Constraints

- Target: Joomla 6.1.4 real environment, PHP 8.1+.
- Only a registered user can receive a notification (the comment-pai's `user_id` must be non-null); a guest-authored parent never triggers one. The *reply* itself can be from a guest or a registered user.
- Notify only once a reply is **published** — never while pending moderation.
- No self-notification: a registered author replying to their own comment never triggers one.
- No per-user opt-out this sub-delivery — only a single global toggle,
  `enable_reply_notifications` in the component Options, same visual
  pattern as `allow_guests`/`enable_reactions`/`enable_votes`.
- Enqueue, never send synchronously. `ReplyNotifier` only ever inserts a
  row into `#__lcomment_notifications`; `NotificationQueueProcessor` is
  the only code that calls `Factory::getMailer()`.
- `UNIQUE KEY` on `comment_id` in `#__lcomment_notifications` is the
  real, DB-level guarantee against double-enqueuing the same reply —
  already-verified lesson from Fase 2c's final review, applied from the
  first version this time.
- `ReplyNotificationPolicy` is pure PHP (no `_JEXEC` guard, no Joomla
  dependency), testable via PHPUnit — same pattern as `ReactionToggle`/
  `VoteToggle`/`ScopeEvaluator`. `ReplyNotifier`, `NotificationQueueProcessor`,
  the admin MVC classes, and the task plugin are Joomla framework code
  with no automated test harness available in this project — verified
  by manual checks instead.
- Package version bumps `0.4.0` → `0.5.0`.
- Verified against real `joomla-cms` 5.4-dev source before this plan was
  written (cited again at point of use below): `Factory::getMailer()`/
  `Joomla\CMS\Mail\Mail` (methods `setSubject`, `setBody`, `addRecipient`,
  `isHtml`, `Send` — `Send()` returns `bool` and can also throw
  `MailDisabledException`/`phpmailerException`); `Factory::getLanguage()`
  is deprecated in Joomla 6, use `Factory::getApplication()->getLanguage()`;
  `$app->get('language', 'en-GB')` is the real site default language tag
  (seen in `SiteApplication`); `AdminModel::publish(&$pks, $value = 1)`
  prunes `$pks` **by reference** to only the ids that actually changed
  state, before our override's code runs; `TaskPluginTrait` already
  provides `advertiseRoutines`/`standardRoutineHandler`/`enhanceTaskItemForm`
  as ready-made event handlers — a task plugin only needs to point
  `getSubscribedEvents()` at them, matching the core `plg_task_sessiongc`
  plugin exactly; the `form` key in a `TASKS_MAP` entry is optional.

## Review Focus

- A reply to a comment authored by a **guest** must never enqueue a
  notification, even though the reply itself might be from a registered
  user. Pinned by a `ReplyNotificationPolicyTest` case (Task 2); the
  full-stack behavior (actually not enqueuing) is only exercised by the
  spec's manual acceptance criterion 6.
- A registered author replying to **their own** comment must never
  self-notify, even via a forged admin-publish batch that includes such
  an id. Pinned by a `ReplyNotificationPolicyTest` case (Task 2); full
  stack exercised by manual acceptance criterion 4.
- Despublishing and republishing the same reply must never create a
  second queue row — the `UNIQUE KEY` on `comment_id`, not just the
  policy's `alreadyQueued` check, is what actually guarantees this under
  any ordering of events. Not PHPUnit-testable (framework/DB code);
  pinned by the spec's manual acceptance criterion 7, and Task 3 adds
  the same defensive `ExecutionFailureException` handling already fixed
  for reactions/votes after Fase 2c's final review, applied up front
  here instead of after a finding.
- A failed send (bad SMTP config) must leave the row retryable, not
  crash the admin page nor silently lose the notification after one
  try. Not PHPUnit-testable; pinned by the spec's manual acceptance
  criterion 10. Task 4 calls out explicitly that `Mail::Send()` can
  *either* return `false` *or* throw, and both paths must be caught —
  confirmed by reading the real `Send()` body, where TLS-retry failure
  paths return `false` while disabled-mail/missing-`mail()`-function
  paths throw.
- The admin-triggered notification (publish a long-pending reply) must
  compose its email text in the site's configured default language, not
  whatever language the admin's own backend session happens to be using
  — easy to miss since `ReplyNotifier` runs from two different
  application contexts. Not PHPUnit-testable; Task 3's steps call out
  the exact verified API (`$app->get('language', 'en-GB')`, not the
  admin session's active language) and Task 7's manual check confirms
  it end-to-end with an admin backend set to a different language than
  the site default.

---

### Task 1: Schema, migration, and package version

**Files:**
- Modify: `com_lcomment/com_lcomment.xml`
- Modify: `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`
- Modify: `com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql`
- Create: `com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.5.0.sql`

**Interfaces:**
- Consumes: nothing.
- Produces: `#__lcomment_comments.item_url` (new nullable column) and the
  new `#__lcomment_notifications` table (`id`, `comment_id`, `user_id`,
  `subject`, `body`, `url`, `attempts`, `created`, `sent_at`, plus
  `UNIQUE KEY idx_comment (comment_id)`), consumed by every later task.

- [ ] **Step 1: Bump the package version**

In `com_lcomment/com_lcomment.xml`, change:

```xml
    <version>0.4.0</version>
```

to:

```xml
    <version>0.5.0</version>
```

- [ ] **Step 2: Add `item_url` to the fresh-install comments table, and the new table**

In `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`, change:

```sql
    `ip` VARCHAR(45) NULL,
    `created` DATETIME NOT NULL,
    `modified` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_context_state` (`extension`, `view`, `item_id`, `state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

to:

```sql
    `ip` VARCHAR(45) NULL,
    `item_url` VARCHAR(500) NULL,
    `created` DATETIME NOT NULL,
    `modified` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_context_state` (`extension`, `view`, `item_id`, `state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Then append, at the end of the file:

```sql

CREATE TABLE IF NOT EXISTS `#__lcomment_notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comment_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `body` TEXT NOT NULL,
    `url` VARCHAR(500) NULL,
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created` DATETIME NOT NULL,
    `sent_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_comment` (`comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 3: Create the upgrade migration**

Create `com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.5.0.sql`:

```sql
ALTER TABLE `#__lcomment_comments`
    ADD COLUMN `item_url` VARCHAR(500) NULL AFTER `ip`;

CREATE TABLE IF NOT EXISTS `#__lcomment_notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comment_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `body` TEXT NOT NULL,
    `url` VARCHAR(500) NULL,
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created` DATETIME NOT NULL,
    `sent_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_comment` (`comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 4: Add the table to the uninstall script**

In `com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql`, add a fifth line:

```sql
DROP TABLE IF EXISTS `#__lcomment_comments`;
DROP TABLE IF EXISTS `#__lcomment_contexts`;
DROP TABLE IF EXISTS `#__lcomment_reactions`;
DROP TABLE IF EXISTS `#__lcomment_votes`;
DROP TABLE IF EXISTS `#__lcomment_notifications`;
```

- [ ] **Step 5: Verify the fresh-install and upgrade `CREATE TABLE` blocks are identical**

Run: `diff <(sed -n '/lcomment_notifications/,/utf8mb4;/p' com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql) <(sed -n '/lcomment_notifications/,/utf8mb4;/p' com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.5.0.sql)`
Expected: no output.

- [ ] **Step 6: Verify the version bump**

Run: `grep -n '<version>' com_lcomment/com_lcomment.xml`
Expected: `8:    <version>0.5.0</version>`

- [ ] **Step 7: Run the full test suite to confirm nothing else broke**

Run: `vendor/bin/phpunit`
Expected: PASS — this task touches no PHP file.

- [ ] **Step 8: Commit**

```bash
git add com_lcomment/com_lcomment.xml com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.5.0.sql
git commit -m "feat(lcomment): add lcomment_notifications table and comments.item_url, bump package to 0.5.0"
```

---

### Task 2: `ReplyNotificationPolicy` domain class

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Service/ReplyNotificationPolicy.php`
- Test: `tests/Service/ReplyNotificationPolicyTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotificationPolicy::shouldNotify(int $state, int $parentId, ?int $parentAuthorUserId, ?int $replyAuthorUserId, bool $alreadyQueued): bool`.
  Consumed by Task 3 (`ReplyNotifier`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Service/ReplyNotificationPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotificationPolicy;
use PHPUnit\Framework\TestCase;

final class ReplyNotificationPolicyTest extends TestCase
{
    public function testDoesNotNotifyWhenReplyIsNotPublished(): void
    {
        self::assertFalse(ReplyNotificationPolicy::shouldNotify(0, 5, 10, 20, false));
    }

    public function testDoesNotNotifyWhenNotAReply(): void
    {
        self::assertFalse(ReplyNotificationPolicy::shouldNotify(1, 0, 10, 20, false));
    }

    public function testDoesNotNotifyWhenParentAuthorIsAGuest(): void
    {
        self::assertFalse(ReplyNotificationPolicy::shouldNotify(1, 5, null, 20, false));
    }

    public function testDoesNotNotifyOnSelfReply(): void
    {
        self::assertFalse(ReplyNotificationPolicy::shouldNotify(1, 5, 10, 10, false));
    }

    public function testDoesNotNotifyWhenAlreadyQueued(): void
    {
        self::assertFalse(ReplyNotificationPolicy::shouldNotify(1, 5, 10, 20, true));
    }

    public function testNotifiesWhenAllConditionsAreMet(): void
    {
        self::assertTrue(ReplyNotificationPolicy::shouldNotify(1, 5, 10, 20, false));
    }

    public function testNotifiesWhenTheReplyItselfIsFromAGuest(): void
    {
        self::assertTrue(ReplyNotificationPolicy::shouldNotify(1, 5, 10, null, false));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Service/ReplyNotificationPolicyTest.php`
Expected: FAIL — `Class "Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotificationPolicy" not found`.

- [ ] **Step 3: Implement `ReplyNotificationPolicy`**

Create `com_lcomment/administrator/components/com_lcomment/src/Service/ReplyNotificationPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class ReplyNotificationPolicy
{
    public static function shouldNotify(
        int $state,
        int $parentId,
        ?int $parentAuthorUserId,
        ?int $replyAuthorUserId,
        bool $alreadyQueued
    ): bool {
        if ($state !== 1) {
            return false;
        }

        if ($parentId === 0) {
            return false;
        }

        if ($parentAuthorUserId === null) {
            return false;
        }

        if ($parentAuthorUserId === $replyAuthorUserId) {
            return false;
        }

        if ($alreadyQueued) {
            return false;
        }

        return true;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Service/ReplyNotificationPolicyTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/ReplyNotificationPolicy.php tests/Service/ReplyNotificationPolicyTest.php
git commit -m "feat(lcomment): add ReplyNotificationPolicy domain class"
```

---

### Task 3: `ReplyNotifier` (enqueue service) and the global toggle

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Service/ReplyNotifier.php`
- Modify: `com_lcomment/administrator/components/com_lcomment/config.xml`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`
- Modify: `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`

**Interfaces:**
- Consumes: `ReplyNotificationPolicy::shouldNotify()` (Task 2); the
  `#__lcomment_comments.item_url` column and `#__lcomment_notifications`
  table (Task 1).
- Produces: `Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotifier::notifyIfNeeded(int $commentId): void`.
  Consumed by Task 6 (site `CommentController::save()`) and Task 7
  (admin `CommentModel::publish()` override).
- No PHPUnit coverage possible (framework code, see Global Constraints).
  Every Joomla API used here was verified against real source before
  this plan was written — see the file-level comment in the code below
  and the Global Constraints section.

- [ ] **Step 1: Add the global toggle to the component Options**

In `com_lcomment/administrator/components/com_lcomment/config.xml`, change:

```xml
        <field
            name="enable_votes"
            type="radio"
            layout="joomla.form.field.radio.switcher"
            default="1"
            label="COM_LCOMMENT_CONFIG_ENABLE_VOTES_LABEL"
        >
            <option value="0">JNO</option>
            <option value="1">JYES</option>
        </field>
    </fieldset>
```

to:

```xml
        <field
            name="enable_votes"
            type="radio"
            layout="joomla.form.field.radio.switcher"
            default="1"
            label="COM_LCOMMENT_CONFIG_ENABLE_VOTES_LABEL"
        >
            <option value="0">JNO</option>
            <option value="1">JYES</option>
        </field>
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

- [ ] **Step 2: Add the config label and notification-content language keys**

Append to `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_CONFIG_ENABLE_REPLY_NOTIFICATIONS_LABEL="Enable reply notifications"
```

Append to `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_CONFIG_ENABLE_REPLY_NOTIFICATIONS_LABEL="Ativar notificações de resposta"
```

Append to `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_NOTIFICATION_SUBJECT="Someone replied to your comment on %s"
COM_LCOMMENT_NOTIFICATION_INTRO="%s replied to your comment:"
```

Append to `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_NOTIFICATION_SUBJECT="Alguém respondeu ao seu comentário em %s"
COM_LCOMMENT_NOTIFICATION_INTRO="%s respondeu ao seu comentário:"
```

- [ ] **Step 3: Create `ReplyNotifier`**

Create `com_lcomment/administrator/components/com_lcomment/src/Service/ReplyNotifier.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;

/**
 * Enqueues a reply notification — never sends anything itself. Sending
 * is NotificationQueueProcessor's job alone.
 *
 * Joomla APIs used here were verified against real joomla-cms 5.4-dev
 * source before this plan was written: Factory::getLanguage() is
 * deprecated in Joomla 6 (use Factory::getApplication()->getLanguage()
 * instead); $app->get('language', 'en-GB') is the real site default
 * language tag (SiteApplication). See the plan's Global Constraints.
 */
final class ReplyNotifier
{
    private const EXCERPT_LENGTH = 200;

    public static function notifyIfNeeded(int $commentId): void
    {
        $params = ComponentHelper::getParams('com_lcomment');

        if (!(bool) $params->get('enable_reply_notifications', 1)) {
            return;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $reply = self::findComment($db, $commentId);

        if ($reply === null || (int) $reply->parent_id === 0) {
            return;
        }

        $parent = self::findComment($db, (int) $reply->parent_id);

        if ($parent === null) {
            return;
        }

        $parentAuthorUserId = $parent->user_id !== null ? (int) $parent->user_id : null;
        $replyAuthorUserId = $reply->user_id !== null ? (int) $reply->user_id : null;

        $shouldNotify = ReplyNotificationPolicy::shouldNotify(
            (int) $reply->state,
            (int) $reply->parent_id,
            $parentAuthorUserId,
            $replyAuthorUserId,
            self::alreadyQueued($db, $commentId)
        );

        if (!$shouldNotify) {
            return;
        }

        $recipient = self::findUser($db, $parentAuthorUserId);

        if ($recipient === null || $recipient['email'] === '') {
            return;
        }

        // Runs from both the site and the admin application — the admin
        // backend's own active language is not necessarily the site's
        // default, so the language used to compose the email text must
        // be forced explicitly rather than left to whatever is already
        // loaded. Factory::getLanguage() is deprecated in Joomla 6.
        $app = Factory::getApplication();
        $siteLanguageTag = $app->get('language', 'en-GB');
        $app->getLanguage()->load('com_lcomment', \JPATH_SITE, $siteLanguageTag, true);

        $replyAuthorName = $reply->guest_name !== null && $reply->guest_name !== ''
            ? (string) $reply->guest_name
            : (self::findUser($db, $replyAuthorUserId)['name'] ?? '');

        $commentText = (string) $reply->comment_text;
        $excerpt = mb_substr($commentText, 0, self::EXCERPT_LENGTH);

        if (mb_strlen($commentText) > self::EXCERPT_LENGTH) {
            $excerpt .= '...';
        }

        $url = (string) ($reply->item_url ?? '');
        $siteName = (string) Factory::getApplication()->get('sitename', '');

        $subject = Text::sprintf('COM_LCOMMENT_NOTIFICATION_SUBJECT', $siteName);
        $body = Text::sprintf('COM_LCOMMENT_NOTIFICATION_INTRO', $replyAuthorName)
            . "\n\n" . $excerpt . "\n\n" . $url;

        self::enqueue($db, $commentId, (int) $recipient['id'], $subject, $body, $url);
    }

    private static function findComment(DatabaseInterface $db, int $commentId): ?object
    {
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__lcomment_comments'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $commentId, ParameterType::INTEGER);

        $db->setQuery($query);

        return $db->loadObject() ?: null;
    }

    private static function alreadyQueued(DatabaseInterface $db, int $commentId): bool
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__lcomment_notifications'))
            ->where($db->quoteName('comment_id') . ' = :commentId')
            ->bind(':commentId', $commentId, ParameterType::INTEGER);

        $db->setQuery($query);

        return (int) $db->loadResult() > 0;
    }

    /**
     * @return array{id: int, name: string, email: string}|null
     */
    private static function findUser(DatabaseInterface $db, ?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('name'), $db->quoteName('email')])
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $userId, ParameterType::INTEGER);

        $db->setQuery($query);

        $row = $db->loadAssoc();

        return $row !== null
            ? ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'email' => (string) $row['email']]
            : null;
    }

    private static function enqueue(
        DatabaseInterface $db,
        int $commentId,
        int $userId,
        string $subject,
        string $body,
        string $url
    ): void {
        $created = Factory::getDate()->toSql();

        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__lcomment_notifications'))
            ->columns([
                $db->quoteName('comment_id'),
                $db->quoteName('user_id'),
                $db->quoteName('subject'),
                $db->quoteName('body'),
                $db->quoteName('url'),
                $db->quoteName('created'),
            ])
            ->values(':commentId, :userId, :subject, :body, :url, :created')
            ->bind(':commentId', $commentId, ParameterType::INTEGER)
            ->bind(':userId', $userId, ParameterType::INTEGER)
            ->bind(':subject', $subject, ParameterType::STRING)
            ->bind(':body', $body, ParameterType::STRING)
            ->bind(':url', $url, ParameterType::STRING)
            ->bind(':created', $created, ParameterType::STRING);

        $db->setQuery($query);

        try {
            $db->execute();
        } catch (ExecutionFailureException $exception) {
            // Same race-safety already fixed for reactions/votes after
            // Fase 2c's final review, applied here from day one: only
            // swallow the failure if a row for this comment now exists
            // (confirms it was the UNIQUE KEY doing its job), otherwise
            // this is a real failure and must not be hidden.
            if (!self::alreadyQueued($db, $commentId)) {
                throw $exception;
            }
        }
    }
}
```

- [ ] **Step 4: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/administrator/components/com_lcomment/src/Service/ReplyNotifier.php && vendor/bin/phpunit`
Expected: `No syntax errors detected...` followed by PASS on the full suite.

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/ReplyNotifier.php com_lcomment/administrator/components/com_lcomment/config.xml com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini
git commit -m "feat(lcomment): add ReplyNotifier enqueue service and enable_reply_notifications toggle"
```

---

### Task 4: `NotificationQueueProcessor` (send service)

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Service/NotificationQueueProcessor.php`

**Interfaces:**
- Consumes: the `#__lcomment_notifications` table (Task 1).
- Produces: `Lcsilva\Component\Lcomment\Administrator\Service\NotificationQueueProcessor::process(int $limit = 50): int`
  (returns how many rows were attempted this call). Consumed by Task 5
  (admin toolbar button) and Task 8 (scheduled task routine).
- No PHPUnit coverage possible (framework code). `Factory::getMailer()`/
  `Mail` API verified against real source before this plan was written
  — see the file-level comment below and Global Constraints.

- [ ] **Step 1: Create `NotificationQueueProcessor`**

Create `com_lcomment/administrator/components/com_lcomment/src/Service/NotificationQueueProcessor.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * The only code in this component that actually sends mail.
 *
 * Mail::Send() (verified against real joomla-cms 5.4-dev source,
 * libraries/src/Mail/Mail.php, before this plan was written) returns
 * bool — true on success, false on some failure paths (e.g. the
 * auto-TLS retry also failing) — but can ALSO throw
 * MailDisabledException or a phpmailerException on other paths (mail
 * disabled in Global Configuration, the mail() function unavailable).
 * Both must be treated as "this attempt failed," never let one crash
 * the caller (an admin page, or a scheduled task run).
 */
final class NotificationQueueProcessor
{
    private const MAX_ATTEMPTS = 3;

    public static function process(int $limit = 50): int
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'),
                $db->quoteName('user_id'),
                $db->quoteName('subject'),
                $db->quoteName('body'),
            ])
            ->from($db->quoteName('#__lcomment_notifications'))
            ->where($db->quoteName('sent_at') . ' IS NULL')
            ->where($db->quoteName('attempts') . ' < :maxAttempts')
            ->order($db->quoteName('created') . ' ASC')
            ->setLimit($limit)
            ->bind(':maxAttempts', $maxAttempts = self::MAX_ATTEMPTS, ParameterType::INTEGER);

        $db->setQuery($query);

        $rows = $db->loadObjectList();

        foreach ($rows as $row) {
            self::attemptSend($db, $row);
        }

        return \count($rows);
    }

    private static function attemptSend(DatabaseInterface $db, object $row): void
    {
        $email = self::emailFor($db, (int) $row->user_id);

        if ($email === null) {
            self::recordFailure($db, (int) $row->id);

            return;
        }

        $sent = false;

        try {
            $mailer = Factory::getMailer();
            $mailer->addRecipient($email);
            $mailer->setSubject((string) $row->subject);
            $mailer->setBody((string) $row->body);
            $mailer->isHtml(false);

            $sent = $mailer->Send() === true;
        } catch (\Throwable $exception) {
            $sent = false;
        }

        if ($sent) {
            self::recordSuccess($db, (int) $row->id);

            return;
        }

        self::recordFailure($db, (int) $row->id);
    }

    private static function emailFor(DatabaseInterface $db, int $userId): ?string
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('email'))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $userId, ParameterType::INTEGER);

        $db->setQuery($query);

        $email = $db->loadResult();

        return $email !== null && $email !== '' ? (string) $email : null;
    }

    private static function recordSuccess(DatabaseInterface $db, int $id): void
    {
        $sentAt = Factory::getDate()->toSql();

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__lcomment_notifications'))
            ->set($db->quoteName('sent_at') . ' = :sentAt')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':sentAt', $sentAt, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER);

        $db->setQuery($query);
        $db->execute();
    }

    private static function recordFailure(DatabaseInterface $db, int $id): void
    {
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__lcomment_notifications'))
            ->set($db->quoteName('attempts') . ' = ' . $db->quoteName('attempts') . ' + 1')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $db->setQuery($query);
        $db->execute();
    }
}
```

- [ ] **Step 2: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/administrator/components/com_lcomment/src/Service/NotificationQueueProcessor.php && vendor/bin/phpunit`
Expected: `No syntax errors detected...` followed by PASS on the full suite.

- [ ] **Step 3: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/NotificationQueueProcessor.php
git commit -m "feat(lcomment): add NotificationQueueProcessor send service"
```

---

### Task 5: Admin "Notifications" view

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Model/NotificationsModel.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Controller/NotificationsController.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/View/Notifications/HtmlView.php`
- Create: `com_lcomment/administrator/components/com_lcomment/tmpl/notifications/default.php`
- Modify: `com_lcomment/com_lcomment.xml`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.sys.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.sys.ini`

**Interfaces:**
- Consumes: `NotificationQueueProcessor::process()` (Task 4).
- Produces: task `notifications.process` (toolbar button), reachable at
  `index.php?option=com_lcomment&view=notifications` via a new admin
  submenu entry. No later task consumes this directly — it's the leaf
  manual-trigger path, verified by the spec's manual acceptance
  criterion 2.
- No PHPUnit coverage possible (framework/view code, same precedent as
  the existing `Comments`/`Contexts` admin views).

- [ ] **Step 1: Add the submenu entry**

In `com_lcomment/com_lcomment.xml`, change:

```xml
        <submenu>
            <menu view="contexts">COM_LCOMMENT_MENU_CONTEXTS</menu>
            <menu view="comments">COM_LCOMMENT_MENU_COMMENTS</menu>
        </submenu>
```

to:

```xml
        <submenu>
            <menu view="contexts">COM_LCOMMENT_MENU_CONTEXTS</menu>
            <menu view="comments">COM_LCOMMENT_MENU_COMMENTS</menu>
            <menu view="notifications">COM_LCOMMENT_MENU_NOTIFICATIONS</menu>
        </submenu>
```

- [ ] **Step 2: Add the language keys**

Append to `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.sys.ini`:

```ini
COM_LCOMMENT_MENU_NOTIFICATIONS="Notifications"
```

Append to `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.sys.ini`:

```ini
COM_LCOMMENT_MENU_NOTIFICATIONS="Notificações"
```

Append to `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_MENU_NOTIFICATIONS="Notifications"
COM_LCOMMENT_NOTIFICATIONS_TITLE="LComment: Notifications"
COM_LCOMMENT_NOTIFICATIONS_RECIPIENT_LABEL="Recipient"
COM_LCOMMENT_NOTIFICATIONS_SUBJECT_LABEL="Subject"
COM_LCOMMENT_NOTIFICATIONS_ATTEMPTS_LABEL="Attempts"
COM_LCOMMENT_NOTIFICATIONS_CREATED_LABEL="Created"
COM_LCOMMENT_NOTIFICATIONS_SENT_LABEL="Sent"
COM_LCOMMENT_NOTIFICATIONS_PENDING="Pending"
COM_LCOMMENT_NOTIFICATIONS_PROCESS_BUTTON="Send notifications"
COM_LCOMMENT_NOTIFICATIONS_PROCESSED_MESSAGE="%d notification(s) processed."
```

Append to `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_MENU_NOTIFICATIONS="Notificações"
COM_LCOMMENT_NOTIFICATIONS_TITLE="LComment: Notificações"
COM_LCOMMENT_NOTIFICATIONS_RECIPIENT_LABEL="Destinatário"
COM_LCOMMENT_NOTIFICATIONS_SUBJECT_LABEL="Assunto"
COM_LCOMMENT_NOTIFICATIONS_ATTEMPTS_LABEL="Tentativas"
COM_LCOMMENT_NOTIFICATIONS_CREATED_LABEL="Criado"
COM_LCOMMENT_NOTIFICATIONS_SENT_LABEL="Enviado"
COM_LCOMMENT_NOTIFICATIONS_PENDING="Pendente"
COM_LCOMMENT_NOTIFICATIONS_PROCESS_BUTTON="Enviar notificações"
COM_LCOMMENT_NOTIFICATIONS_PROCESSED_MESSAGE="%d notificação(ões) processada(s)."
```

- [ ] **Step 3: Create `NotificationsModel`**

Create `com_lcomment/administrator/components/com_lcomment/src/Model/NotificationsModel.php`, mirroring `CommentsModel`'s structure exactly:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;

final class NotificationsModel extends ListModel
{
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'subject', 'attempts', 'created', 'sent_at'];
        }

        parent::__construct($config);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('n.*')
            ->select($db->quoteName('u.name', 'recipient_name'))
            ->select($db->quoteName('u.email', 'recipient_email'))
            ->from($db->quoteName('#__lcomment_notifications', 'n'))
            ->join(
                'LEFT',
                $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('n.user_id')
            );

        $ordering = $this->state->get('list.ordering', 'n.created');
        $direction = $this->state->get('list.direction', 'DESC');
        $query->order($db->escape($ordering) . ' ' . $db->escape($direction));

        return $query;
    }
}
```

- [ ] **Step 4: Create `NotificationsController`**

Create `com_lcomment/administrator/components/com_lcomment/src/Controller/NotificationsController.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Session\Session;
use Lcsilva\Component\Lcomment\Administrator\Service\NotificationQueueProcessor;

final class NotificationsController extends AdminController
{
    protected $text_prefix = 'COM_LCOMMENT_NOTIFICATIONS';

    public function getModel($name = 'Notification', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    public function process(): void
    {
        Session::checkToken('request') or die(Text::_('JINVALID_TOKEN'));

        $processed = NotificationQueueProcessor::process();

        $app = Factory::getApplication();
        $app->enqueueMessage(Text::sprintf('COM_LCOMMENT_NOTIFICATIONS_PROCESSED_MESSAGE', $processed));
        $app->redirect('index.php?option=com_lcomment&view=notifications');
    }
}
```

**Note:** `getModel()`'s default `$name` is `'Notification'` (singular) —
this is Joomla's `ListModel` naming convention for a list view: the
model class is `NotificationsModel` (file/class name, plural, matching
the view name), but `getModel()` is conventionally called with the
singular form as `$name` because Joomla's `MVCFactory` appends an `s`
internally when resolving a `ListModel` — same exact pattern already
used by `CommentsController::getModel('Comment', ...)` resolving to
`CommentsModel` in this very codebase.

- [ ] **Step 5: Create the view and template**

Create `com_lcomment/administrator/components/com_lcomment/src/View/Notifications/HtmlView.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\View\Notifications;

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

        ToolbarHelper::title(Text::_('COM_LCOMMENT_NOTIFICATIONS_TITLE'));
        ToolbarHelper::custom('notifications.process', 'envelope', '', 'COM_LCOMMENT_NOTIFICATIONS_PROCESS_BUTTON', false);
        ToolbarHelper::deleteList('', 'notifications.delete');
        ToolbarHelper::preferences('com_lcomment');

        parent::display($tpl);
    }
}
```

Create `com_lcomment/administrator/components/com_lcomment/tmpl/notifications/default.php`, mirroring `tmpl/comments/default.php`'s structure:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Notifications\HtmlView $this */

$listOrder = $this->state->get('list.ordering', 'n.created');
$listDirn = $this->state->get('list.direction', 'DESC');
?>
<form action="<?php echo htmlspecialchars(\Joomla\CMS\Uri\Uri::getInstance()->toString()); ?>" method="post" name="adminForm" id="adminForm">
    <table class="table">
        <thead>
            <tr>
                <th><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_NOTIFICATIONS_RECIPIENT_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_NOTIFICATIONS_SUBJECT_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_NOTIFICATIONS_ATTEMPTS_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_NOTIFICATIONS_CREATED_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_NOTIFICATIONS_SENT_LABEL'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->items as $i => $item) : ?>
            <tr>
                <td><?php echo HTMLHelper::_('grid.id', $i, $item->id); ?></td>
                <td><?php echo htmlspecialchars((string) ($item->recipient_name ?: $item->recipient_email)); ?></td>
                <td><?php echo htmlspecialchars((string) $item->subject); ?></td>
                <td><?php echo (int) $item->attempts; ?></td>
                <td><?php echo htmlspecialchars((string) $item->created); ?></td>
                <td>
                    <?php if ($item->sent_at) : ?>
                        <?php echo htmlspecialchars((string) $item->sent_at); ?>
                    <?php else : ?>
                        <?php echo Text::_('COM_LCOMMENT_NOTIFICATIONS_PENDING'); ?>
                    <?php endif; ?>
                </td>
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

- [ ] **Step 6: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/administrator/components/com_lcomment/src/Model/NotificationsModel.php && php -l com_lcomment/administrator/components/com_lcomment/src/Controller/NotificationsController.php && php -l com_lcomment/administrator/components/com_lcomment/src/View/Notifications/HtmlView.php && php -l com_lcomment/administrator/components/com_lcomment/tmpl/notifications/default.php && vendor/bin/phpunit`
Expected: four `No syntax errors detected...` lines, then PASS on the full suite.

- [ ] **Step 7: Commit**

```bash
git add com_lcomment/com_lcomment.xml com_lcomment/administrator/components/com_lcomment/language com_lcomment/administrator/components/com_lcomment/src/Model/NotificationsModel.php com_lcomment/administrator/components/com_lcomment/src/Controller/NotificationsController.php com_lcomment/administrator/components/com_lcomment/src/View/Notifications/HtmlView.php com_lcomment/administrator/components/com_lcomment/tmpl/notifications/default.php
git commit -m "feat(lcomment): add admin Notifications list view with manual process button"
```

---

### Task 6: Capture `item_url` and enqueue from `CommentController::save()`

**Files:**
- Modify: `com_lcomment/components/com_lcomment/src/Controller/CommentController.php`

**Interfaces:**
- Consumes: `ReplyNotifier::notifyIfNeeded()` (Task 3).
- Produces: `#__lcomment_comments.item_url` is populated for every new
  comment (not just replies — simplest to always capture it, since any
  comment could later become a reply's parent). No later task consumes
  this directly.
- No PHPUnit coverage possible (framework code, same precedent as the
  rest of this controller).

- [ ] **Step 1: Capture and store `item_url`, call `ReplyNotifier`**

In `com_lcomment/components/com_lcomment/src/Controller/CommentController.php`, add the import — change:

```php
use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionPolicy;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionRequest;
```

to:

```php
use Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotifier;
use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionPolicy;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionRequest;
```

Then set the column — change:

```php
        $table->extension = $extension;
        $table->view = $view;
        $table->item_id = $itemId;
        $table->parent_id = $parentId;
        $table->comment_text = $policyResult->normalizedText;
```

to:

```php
        $table->extension = $extension;
        $table->view = $view;
        $table->item_id = $itemId;
        $table->parent_id = $parentId;
        $table->item_url = $returnUrl;
        $table->comment_text = $policyResult->normalizedText;
```

Then enqueue right after the row is stored — change:

```php
        if (!$table->check() || !$table->store()) {
            $app->setUserState($stateKey, [
                'text' => $text,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
                'parent_id' => $parentId,
            ]);
            $app->enqueueMessage($table->getError(), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $app->enqueueMessage(
            Text::_($policyResult->initialState === 1 ? 'COM_LCOMMENT_SAVE_SUCCESS_PUBLISHED' : 'COM_LCOMMENT_SAVE_SUCCESS_PENDING')
        );
```

to:

```php
        if (!$table->check() || !$table->store()) {
            $app->setUserState($stateKey, [
                'text' => $text,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
                'parent_id' => $parentId,
            ]);
            $app->enqueueMessage($table->getError(), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        if ($policyResult->initialState === 1) {
            ReplyNotifier::notifyIfNeeded((int) $table->id);
        }

        $app->enqueueMessage(
            Text::_($policyResult->initialState === 1 ? 'COM_LCOMMENT_SAVE_SUCCESS_PUBLISHED' : 'COM_LCOMMENT_SAVE_SUCCESS_PENDING')
        );
```

- [ ] **Step 2: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/components/com_lcomment/src/Controller/CommentController.php && vendor/bin/phpunit`
Expected: `No syntax errors detected...` followed by PASS on the full suite.

- [ ] **Step 3: Commit**

```bash
git add com_lcomment/components/com_lcomment/src/Controller/CommentController.php
git commit -m "feat(lcomment): capture item_url and enqueue reply notification on comment save"
```

---

### Task 7: Enqueue from the admin publish action

**Files:**
- Modify: `com_lcomment/administrator/components/com_lcomment/src/Model/CommentModel.php`

**Interfaces:**
- Consumes: `ReplyNotifier::notifyIfNeeded()` (Task 3).
- Produces: nothing new — this is the second (and last) trigger point.
- No PHPUnit coverage possible (framework code).

- [ ] **Step 1: Override `publish()`**

In `com_lcomment/administrator/components/com_lcomment/src/Model/CommentModel.php`, change:

```php
use Joomla\CMS\MVC\Model\AdminModel;

final class CommentModel extends AdminModel
{
    public function getTable($type = 'Comment', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function getForm($data = [], $loadData = true)
    {
        // Comments are moderated (publish/unpublish/trash), never created
        // or edited through a form in this phase.
        return false;
    }
}
```

to:

```php
use Joomla\CMS\MVC\Model\AdminModel;
use Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotifier;

final class CommentModel extends AdminModel
{
    public function getTable($type = 'Comment', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function getForm($data = [], $loadData = true)
    {
        // Comments are moderated (publish/unpublish/trash), never created
        // or edited through a form in this phase.
        return false;
    }

    public function publish(&$pks, $value = 1)
    {
        $result = parent::publish($pks, $value);

        // Verified against real joomla-cms 5.4-dev source
        // (AdminModel::publish()): $pks is pruned BY REFERENCE to only
        // the ids that actually changed to $value during this call —
        // Joomla already filters out "already published" ids before we
        // ever see them here, so re-publishing an already-published
        // comment never reaches notifyIfNeeded() for it.
        if ($result && (int) $value === 1) {
            foreach ((array) $pks as $pk) {
                ReplyNotifier::notifyIfNeeded((int) $pk);
            }
        }

        return $result;
    }
}
```

- [ ] **Step 2: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/administrator/components/com_lcomment/src/Model/CommentModel.php && vendor/bin/phpunit`
Expected: `No syntax errors detected...` followed by PASS on the full suite.

- [ ] **Step 3: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Model/CommentModel.php
git commit -m "feat(lcomment): enqueue reply notification when a pending comment is published"
```

- [ ] **Step 4: Manual check to perform later, during live Joomla verification (not automatable here)**

Set the admin backend's own language (User menu → the logged-in user's
own language preference, or Global Configuration's Administrator
Default Language) to something **different** from the site's front-end
default language. Publish a pending reply from the admin, then check
the Notifications list / the actual received email — the subject and
body must be in the **site's** default language, not the admin
session's language. This is the one behavior in this task that no
syntax check or test run can confirm.

---

### Task 8: Scheduled-task plugin and packaging

**Files:**
- Create: `plg_task_lcomment/lcomment.xml`
- Create: `plg_task_lcomment/index.html`
- Create: `plg_task_lcomment/services/provider.php`
- Create: `plg_task_lcomment/src/Extension/Lcomment.php`
- Create: `plg_task_lcomment/language/en-GB/en-GB.plg_task_lcomment.sys.ini`
- Create: `plg_task_lcomment/language/pt-PT/pt-PT.plg_task_lcomment.sys.ini`
- Modify: `packages/pkg_lcomment.xml`
- Modify: `packages/script.php`
- Modify: `build.sh`

**Interfaces:**
- Consumes: `NotificationQueueProcessor::process()` (Task 4).
- Produces: a third installable extension in the package, registering
  routine `lcomment.process_notifications` with Joomla's `com_scheduler`.
  No later task consumes this.
- No PHPUnit coverage possible (framework/plugin code). Every API used
  here was verified against the real native `plg_task_sessiongc` plugin
  source before this plan was written — see the file-level comment
  below and Global Constraints.

- [ ] **Step 1: Create the plugin manifest**

Create `plg_task_lcomment/lcomment.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<extension type="plugin" group="task" method="upgrade">
    <name>plg_task_lcomment</name>
    <creationDate>2026-10-09</creationDate>
    <author>leocostadeveloper</author>
    <license>GNU General Public License version 2 or later</license>
    <version>0.1.0</version>
    <description>PLG_TASK_LCOMMENT_XML_DESCRIPTION</description>
    <namespace path="src">Lcsilva\Plugin\Task\Lcomment</namespace>
    <files>
        <folder plugin="lcomment">services</folder>
        <folder>src</folder>
        <filename>index.html</filename>
    </files>
    <languages folder="language">
        <language tag="en-GB">en-GB/en-GB.plg_task_lcomment.sys.ini</language>
        <language tag="pt-PT">pt-PT/pt-PT.plg_task_lcomment.sys.ini</language>
    </languages>
</extension>
```

- [ ] **Step 2: Create the index.html, provider, and language files**

Create `plg_task_lcomment/index.html`:

```html
<!DOCTYPE html><title></title>
```

Create `plg_task_lcomment/services/provider.php`:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Lcsilva\Plugin\Task\Lcomment\Extension\Lcomment;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);
                $plugin = new Lcomment(
                    $dispatcher,
                    (array) PluginHelper::getPlugin('task', 'lcomment')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
```

Create `plg_task_lcomment/language/en-GB/en-GB.plg_task_lcomment.sys.ini`:

```ini
PLG_TASK_LCOMMENT="Task - LComment"
PLG_TASK_LCOMMENT_XML_DESCRIPTION="Processes LComment's pending reply-notification queue."
PLG_TASK_LCOMMENT_PROCESS_NOTIFICATIONS="LComment: Process reply-notification queue"
```

Create `plg_task_lcomment/language/pt-PT/pt-PT.plg_task_lcomment.sys.ini`:

```ini
PLG_TASK_LCOMMENT="Tarefa - LComment"
PLG_TASK_LCOMMENT_XML_DESCRIPTION="Processa a fila pendente de notificações de resposta do LComment."
PLG_TASK_LCOMMENT_PROCESS_NOTIFICATIONS="LComment: Processar fila de notificações de resposta"
```

- [ ] **Step 3: Create the plugin class**

Create `plg_task_lcomment/src/Extension/Lcomment.php`. This mirrors the
real native `plg_task_sessiongc` plugin's structure exactly — verified
against its source (`plugins/task/sessiongc/src/Extension/SessionGC.php`,
`joomla-cms` 5.4-dev) before this plan was written:
`advertiseRoutines`/`standardRoutineHandler`/`enhanceTaskItemForm` are
all provided ready-made by `TaskPluginTrait`; the `form` key in
`TASKS_MAP` is optional and omitted here since this routine takes no
configurable parameters:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Plugin\Task\Lcomment\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;
use Lcsilva\Component\Lcomment\Administrator\Service\NotificationQueueProcessor;

final class Lcomment extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    private const TASKS_MAP = [
        'lcomment.process_notifications' => [
            'langConstPrefix' => 'PLG_TASK_LCOMMENT_PROCESS_NOTIFICATIONS',
            'method' => 'processNotifications',
        ],
    ];

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList' => 'advertiseRoutines',
            'onExecuteTask' => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    private function processNotifications(ExecuteTaskEvent $event): int
    {
        $processed = NotificationQueueProcessor::process();

        $this->logTask(\sprintf('Processed %d notification(s)', $processed));

        return Status::OK;
    }
}
```

- [ ] **Step 4: Package the new plugin**

In `packages/pkg_lcomment.xml`, change:

```xml
    <files folder="constituents">
        <file type="component" id="com_lcomment">com_lcomment.zip</file>
        <file type="plugin" id="lcomment" group="content">plg_content_lcomment.zip</file>
        <file type="plugin" id="lcomment" group="system">plg_system_lcomment.zip</file>
    </files>
```

to:

```xml
    <files folder="constituents">
        <file type="component" id="com_lcomment">com_lcomment.zip</file>
        <file type="plugin" id="lcomment" group="content">plg_content_lcomment.zip</file>
        <file type="plugin" id="lcomment" group="system">plg_system_lcomment.zip</file>
        <file type="plugin" id="lcomment" group="task">plg_task_lcomment.zip</file>
    </files>
```

- [ ] **Step 5: Auto-enable the new plugin on install**

In `packages/script.php`, change:

```php
            ->where(
                $db->quoteName('folder') . ' IN (' . $db->quote('content') . ', ' . $db->quote('system') . ')'
            );
```

to:

```php
            ->where(
                $db->quoteName('folder') . ' IN ('
                    . $db->quote('content') . ', ' . $db->quote('system') . ', ' . $db->quote('task')
                . ')'
            );
```

Also update the comment right above it — change:

```php
        // The two LComment plugins ship installed-but-disabled by default
        // (standard Joomla behaviour for non-editor plugins). The spec
        // requires them enabled out of the box, so flip them on here.
```

to:

```php
        // The three LComment plugins ship installed-but-disabled by
        // default (standard Joomla behaviour for non-editor plugins).
        // The spec requires them enabled out of the box, so flip them
        // on here.
```

- [ ] **Step 6: Build the new plugin's zip**

In `build.sh`, change:

```bash
( cd "$ROOT/com_lcomment" && zip -r -q "$DIST/com_lcomment.zip" . -x '.*' )
( cd "$ROOT/plg_content_lcomment" && zip -r -q "$DIST/plg_content_lcomment.zip" . -x '.*' )
( cd "$ROOT/plg_system_lcomment" && zip -r -q "$DIST/plg_system_lcomment.zip" . -x '.*' )

# Also kept at dist/ root (not just dist/constituents/) so each extension
# can still be installed individually, same as the package's own copies.
cp "$DIST/com_lcomment.zip" "$DIST/constituents/com_lcomment.zip"
cp "$DIST/plg_content_lcomment.zip" "$DIST/constituents/plg_content_lcomment.zip"
cp "$DIST/plg_system_lcomment.zip" "$DIST/constituents/plg_system_lcomment.zip"
```

to:

```bash
( cd "$ROOT/com_lcomment" && zip -r -q "$DIST/com_lcomment.zip" . -x '.*' )
( cd "$ROOT/plg_content_lcomment" && zip -r -q "$DIST/plg_content_lcomment.zip" . -x '.*' )
( cd "$ROOT/plg_system_lcomment" && zip -r -q "$DIST/plg_system_lcomment.zip" . -x '.*' )
( cd "$ROOT/plg_task_lcomment" && zip -r -q "$DIST/plg_task_lcomment.zip" . -x '.*' )

# Also kept at dist/ root (not just dist/constituents/) so each extension
# can still be installed individually, same as the package's own copies.
cp "$DIST/com_lcomment.zip" "$DIST/constituents/com_lcomment.zip"
cp "$DIST/plg_content_lcomment.zip" "$DIST/constituents/plg_content_lcomment.zip"
cp "$DIST/plg_system_lcomment.zip" "$DIST/constituents/plg_system_lcomment.zip"
cp "$DIST/plg_task_lcomment.zip" "$DIST/constituents/plg_task_lcomment.zip"
```

- [ ] **Step 7: Syntax-check and run the full test suite**

Run: `php -l plg_task_lcomment/src/Extension/Lcomment.php && php -l plg_task_lcomment/services/provider.php && vendor/bin/phpunit`
Expected: two `No syntax errors detected...` lines, then PASS on the full suite.

- [ ] **Step 8: Build the package and verify the new zip exists**

Run: `./build.sh && unzip -l dist/pkg_lcomment.zip | grep plg_task_lcomment`
Expected: `Built .../dist/pkg_lcomment.zip` followed by a line listing
`constituents/plg_task_lcomment.zip` inside the package.

- [ ] **Step 9: Commit**

```bash
git add plg_task_lcomment packages/pkg_lcomment.xml packages/script.php build.sh
git commit -m "feat(lcomment): add plg_task_lcomment scheduled-task plugin for the notification queue"
```

---

## Manual verification (real Joomla 6.1.4 site)

After installing the rebuilt `dist/pkg_lcomment.zip` (an **upgrade** —
Joomla runs `0.5.0.sql` automatically; confirm
`#__lcomment_notifications` exists and `#__lcomment_comments` has the
new `item_url` column afterward), install the new `plg_task_lcomment`
plugin too if it wasn't bundled into the same package install (it is,
via `pkg_lcomment.zip` — just confirm it shows up enabled in the Plugin
Manager under the Task group), then run through the spec's 10
acceptance criteria in order (see the spec's own "Critério de aceite"
section) plus the Task 7 Step 4 language check above.

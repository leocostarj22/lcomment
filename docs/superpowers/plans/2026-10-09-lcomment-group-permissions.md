# LComment — Fase 3a: ACL Granular por Grupo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single global `allow_guests` toggle with a per-Joomla-group permission matrix (post new, post replies, autopublish, edit own, delete own), enforced on comment submission, and ship the actual edit/delete-own-comment feature it gates.

**Architecture:** A pure-PHP `GroupPermissionResolver` resolves a user's effective permissions (OR across their authorised groups) from rows already loaded from a new `#__lcomment_group_permissions` table. `SubmissionPolicy` (already existing) takes three new fields instead of the retired `guestsAllowed`, unifying guest and registered-user permission checks into one. Two new site controller actions (`edit`/`delete`) reuse the same "does this need moderation?" formula as a fresh submission. A soft-deleted comment (`state = -2`, the same column admin trash already uses) with surviving replies is now fetched by the site query and rendered as a placeholder, closing a Fase 2b gap along the way. A new single-page admin view lets an administrator edit the matrix.

**Tech Stack:** PHP 8.1+, Joomla 6.1.4 MVC, PHPUnit 10.5 for pure-PHP domain classes (`GroupPermissionResolver`, `SubmissionPolicy`), manual verification for framework-glue code (same established split as every prior phase).

**Spec:** `docs/superpowers/specs/2026-10-09-lcomment-group-permissions-design.md`

## Global Constraints

- Target: Joomla 6.1.4 real environment (live site and/or the local
  Podman/MariaDB/`joomla:6.1.4-apache` environment — see memory
  `reference-local-joomla-test-env`), PHP 8.1+.
- The permission matrix is global (one matrix for the whole site), never
  per-Context.
- Resolution is OR across a user's authorised groups
  (`User::getAuthorisedGroups()`, verified against real
  `joomla-cms` 6.1-dev source — works uniformly for guests and
  registered users, no special-casing needed: for a guest, it already
  resolves to the configured `com_users.guest_usergroup` (default 1 =
  Público) and its ancestors).
- A group with `autopublish` always skips the Context's moderation
  toggle, for both new submissions and edits of the user's own comment.
- Editing/deleting one's own comment is registered-users-only, never
  guests — regardless of `can_edit_own`/`can_delete_own`.
- No time limit on editing/deleting one's own comment (YAGNI).
- Editing an already-published comment, in a moderated Context, by a
  user without `autopublish`, sends it back to pending (`state = 0`).
- Deleting one's own comment sets `state = -2` — the exact same column
  value admin trash already uses, not a new state.
- `allow_guests` is retired entirely: removed from `config.xml`, its
  last stored value is copied into the Público/Visitante group's row
  during the `0.6.0` migration, and no code reads it afterward.
- A group with no row in `#__lcomment_group_permissions` (e.g. a Joomla
  group created after LComment was installed) gets every permission as
  `false` — deny by default.
- Package version bumps `0.5.0` → `0.6.0`.
- Verified against real `joomla-cms` 6.1-dev source before this plan was
  written (cited again at point of use below):
  `Joomla\CMS\User\User::getAuthorisedGroups()`
  (`libraries/src/User/User.php`) and
  `Joomla\CMS\Access\Access::getGroupsByUser()`
  (`libraries/src/Access/Access.php`, confirms guest handling);
  `DatabaseDriver::loadAssocList($key)`
  (`joomla-framework/database`, confirms indexing a result set by a
  column's value); `InstallerAdapter::setupScriptfile()`
  (`libraries/src/Installer/InstallerAdapter.php`, confirms a
  component's `<scriptfile>` is resolved relative to the extracted
  package root — i.e. `com_lcomment/script.php`, a sibling of
  `com_lcomment.xml`, not nested under `administrator/`).

## Review Focus

- A Joomla group created **after** LComment is installed (no row in
  `#__lcomment_group_permissions`) must be unable to post, reply,
  autopublish, edit, or delete — never silently inherit a different
  group's permissions or crash resolving an empty set. Pinned by a
  `GroupPermissionResolverTest` case (Task 2) and the spec's own
  "deny by default" constraint; the full-stack behavior (a real new
  group actually being blocked) is only exercised by manual testing,
  not by any task's automated test.
- A visitor (guest) must never be able to edit or delete a comment
  (their own "identity" by IP+session matches nothing here — the check
  is strictly `$user->id > 0`), even if the Público group's
  `can_edit_own`/`can_delete_own` were ever mistakenly set to `true` in
  the admin UI. Pinned in Task 5's manual verification note — this is a
  second, redundant safety check beyond "the Público group's seeded
  defaults are false", since an admin could still flip that checkbox.
- Deleting a comment that has **no** replies must behave exactly as
  before this phase (simply absent from the page) — only a trashed
  comment with at least one **non-trashed** descendant gets the new
  placeholder treatment. A trashed comment whose only replies are
  *themselves* trashed must also stay fully hidden, not surface a lone
  placeholder with no visible children under it. Pinned by a
  `CommentModelGetItemsForTest`-equivalent manual check in Task 4 (this
  method has no existing PHPUnit coverage — framework/DB code — so this
  is a Task 4 manual-verification item, not an automated test).
- Editing one's own comment must re-validate against the **current**
  min/max length settings and the **current** Context's moderation
  state — never the values that applied when the comment was first
  posted (e.g. an admin shortening `max_length` after the fact must
  retroactively apply to an edit attempt). Pinned by Task 5's edit
  implementation reusing `CommentValidator::validate()` and a fresh
  Context lookup, never cached values from the original row.
- The admin Permissions view saving a row for a group that has **since
  been deleted** in Joomla (stale `group_id` submitted from a stale
  form) must not crash the save nor silently resurrect a phantom
  permission row that no real user can ever match — `REPLACE INTO`
  with that dead id is harmless (nothing ever matches it in
  `getAuthorisedGroups()`), but Task 7's save step must not assume the
  posted group ids are still valid without at least filtering them
  against a fresh `#__usergroups` read, since the admin's browser tab
  could have been open across an unrelated group deletion.

---

### Task 1: Schema, migration, install script, and `allow_guests` retirement

**Files:**
- Modify: `com_lcomment/com_lcomment.xml`
- Modify: `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`
- Modify: `com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql`
- Create: `com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.6.0.sql`
- Create: `com_lcomment/script.php`
- Modify: `com_lcomment/administrator/components/com_lcomment/config.xml`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.sys.ini` (no change needed — see Step 7, verifying this)
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`
- Modify: `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`

**Interfaces:**
- Consumes: nothing.
- Produces: the `#__lcomment_group_permissions` table (`group_id` PK,
  `can_post`, `can_reply`, `autopublish`, `can_edit_own`,
  `can_delete_own`, all `TINYINT`), seeded on install/update — consumed
  by Task 4 (`CommentModel::getGroupPermissions()`) and Task 7 (admin
  Permissions view).

- [ ] **Step 1: Bump the package version**

In `com_lcomment/com_lcomment.xml`, change:

```xml
    <version>0.5.0</version>
```

to:

```xml
    <version>0.6.0</version>
```

- [ ] **Step 2: Declare the install script and add the new table to the fresh-install SQL**

In `com_lcomment/com_lcomment.xml`, change:

```xml
    <element>com_lcomment</element>
    <namespace path="src">Lcsilva\Component\Lcomment</namespace>

    <files folder="components/com_lcomment">
```

to:

```xml
    <element>com_lcomment</element>
    <namespace path="src">Lcsilva\Component\Lcomment</namespace>
    <scriptfile>script.php</scriptfile>

    <files folder="components/com_lcomment">
```

In `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`, append at the end of the file:

```sql

CREATE TABLE IF NOT EXISTS `#__lcomment_group_permissions` (
    `group_id` INT UNSIGNED NOT NULL,
    `can_post` TINYINT NOT NULL DEFAULT 0,
    `can_reply` TINYINT NOT NULL DEFAULT 0,
    `autopublish` TINYINT NOT NULL DEFAULT 0,
    `can_edit_own` TINYINT NOT NULL DEFAULT 0,
    `can_delete_own` TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`group_id`)
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
```

- [ ] **Step 4: Create the upgrade migration**

Create `com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.6.0.sql`:

```sql
CREATE TABLE IF NOT EXISTS `#__lcomment_group_permissions` (
    `group_id` INT UNSIGNED NOT NULL,
    `can_post` TINYINT NOT NULL DEFAULT 0,
    `can_reply` TINYINT NOT NULL DEFAULT 0,
    `autopublish` TINYINT NOT NULL DEFAULT 0,
    `can_edit_own` TINYINT NOT NULL DEFAULT 0,
    `can_delete_own` TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 5: Write the install script that seeds the matrix**

Create `com_lcomment/script.php`. Verified against real `joomla-cms`
6.1-dev source before writing: `InstallerScriptInterface`'s
`install(InstallerAdapter $adapter): bool` and
`update(InstallerAdapter $adapter): bool` run **after** the SQL files
above have already created the table — safe to `INSERT` immediately.
`Joomla\Registry\Registry` is already used elsewhere in this project to
decode a JSON `params` column (e.g. `AdminModel::getItem()`'s own
behavior, documented in this project's memory) — same pattern reused
here to read the component's stored `allow_guests` value directly from
`#__extensions`, since the field is about to be removed from
`config.xml` but its last-saved value still lives in that JSON blob
regardless:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Registry\Registry;

return new class () implements InstallerScriptInterface {
    private const REGISTERED_GROUP_ID = 2;

    public function install(InstallerAdapter $adapter): bool
    {
        $this->seedGroupPermissions($this->currentAllowGuestsValue());

        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        $this->seedGroupPermissions($this->currentAllowGuestsValue());

        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    private function currentAllowGuestsValue(): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_lcomment'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'));

        $db->setQuery($query);

        $paramsJson = $db->loadResult();

        if ($paramsJson === null) {
            return true;
        }

        $params = new Registry($paramsJson);

        return (bool) $params->get('allow_guests', true);
    }

    private function seedGroupPermissions(bool $allowGuestsForPublic): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $guestGroupId = (int) ComponentHelper::getParams('com_users')->get('guest_usergroup', 1);

        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__usergroups'));

        $db->setQuery($query);

        $groupIds = array_map('intval', $db->loadColumn());

        foreach ($groupIds as $groupId) {
            if ($groupId === $guestGroupId) {
                $row = [
                    'can_post' => $allowGuestsForPublic,
                    'can_reply' => $allowGuestsForPublic,
                    'autopublish' => false,
                    'can_edit_own' => false,
                    'can_delete_own' => false,
                ];
            } elseif ($groupId === self::REGISTERED_GROUP_ID) {
                $row = [
                    'can_post' => true,
                    'can_reply' => true,
                    'autopublish' => false,
                    'can_edit_own' => true,
                    'can_delete_own' => true,
                ];
            } else {
                $row = [
                    'can_post' => true,
                    'can_reply' => true,
                    'autopublish' => true,
                    'can_edit_own' => true,
                    'can_delete_own' => true,
                ];
            }

            $insertQuery = $db->getQuery(true)
                ->insert($db->quoteName('#__lcomment_group_permissions'))
                ->columns([
                    $db->quoteName('group_id'),
                    $db->quoteName('can_post'),
                    $db->quoteName('can_reply'),
                    $db->quoteName('autopublish'),
                    $db->quoteName('can_edit_own'),
                    $db->quoteName('can_delete_own'),
                ])
                ->values(implode(', ', [
                    $groupId,
                    (int) $row['can_post'],
                    (int) $row['can_reply'],
                    (int) $row['autopublish'],
                    (int) $row['can_edit_own'],
                    (int) $row['can_delete_own'],
                ]));

            $db->setQuery($insertQuery);

            try {
                $db->execute();
            } catch (ExecutionFailureException $exception) {
                // PRIMARY KEY violation: this group already has a row,
                // either from a previous run of this same migration or
                // because the admin already configured it by hand —
                // never overwrite an existing, possibly admin-edited row.
            }
        }
    }
};
```

- [ ] **Step 6: Remove `allow_guests` from the Options form**

In `com_lcomment/administrator/components/com_lcomment/config.xml`, change:

```xml
        <field
            name="allow_guests"
            type="radio"
            layout="joomla.form.field.radio.switcher"
            default="1"
            label="COM_LCOMMENT_CONFIG_ALLOW_GUESTS_LABEL"
        >
            <option value="0">JNO</option>
            <option value="1">JYES</option>
        </field>
        <field
            name="enable_reactions"
```

to:

```xml
        <field
            name="enable_reactions"
```

- [ ] **Step 7: Remove the now-unused language keys, add the new error key**

In `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`, remove the line
`COM_LCOMMENT_CONFIG_ALLOW_GUESTS_LABEL="Allow guest comments"` and the line
`COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED="Guest comments are not allowed on this site."`.

In `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`, remove the line
`COM_LCOMMENT_CONFIG_ALLOW_GUESTS_LABEL="Permitir comentários de visitantes"` and the line
`COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED="Comentários de visitantes não são permitidos neste site."`.

In `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`, change:

```ini
COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED="Guest comments are not allowed on this site."
```

to:

```ini
COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED="You are not allowed to post here."
```

In `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`, change:

```ini
COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED="Comentários de visitantes não são permitidos neste site."
```

to:

```ini
COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED="Não tem permissão para publicar aqui."
```

Confirm no other `.sys.ini` file references either key (it doesn't —
only the two `.ini` pairs above ever defined it):

Run: `grep -rn "COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED\|COM_LCOMMENT_CONFIG_ALLOW_GUESTS_LABEL" com_lcomment/`
Expected: no output.

- [ ] **Step 8: Verify the version bump, the schema diff, and run the full test suite**

Run: `grep -n '<version>' com_lcomment/com_lcomment.xml`
Expected: `8:    <version>0.6.0</version>`

Run: `diff <(sed -n '/lcomment_group_permissions/,/utf8mb4;/p' com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql) <(sed -n '/lcomment_group_permissions/,/utf8mb4;/p' com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.6.0.sql)`
Expected: no output.

Run: `php -l com_lcomment/script.php && vendor/bin/phpunit`
Expected: `No syntax errors detected...` followed by PASS (this task touches no PHP file the suite exercises — expect the same count as before this task).

- [ ] **Step 9: Commit**

```bash
git add com_lcomment/com_lcomment.xml com_lcomment/administrator/components/com_lcomment/sql com_lcomment/script.php com_lcomment/administrator/components/com_lcomment/config.xml com_lcomment/administrator/components/com_lcomment/language com_lcomment/components/com_lcomment/language
git commit -m "feat(lcomment): add lcomment_group_permissions table, seed script, retire allow_guests"
```

---

### Task 2: `GroupPermissionResolver` domain class

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Service/GroupPermissionResolver.php`
- Test: `tests/Service/GroupPermissionResolverTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `Lcsilva\Component\Lcomment\Administrator\Service\GroupPermissionResolver::resolve(array $rowsByGroupId, array $authorisedGroupIds): array{can_post: bool, can_reply: bool, autopublish: bool, can_edit_own: bool, can_delete_own: bool}`.
  Consumed by Task 4 (`CommentController::save()`) and Task 5
  (`CommentController::edit()`/`delete()`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Service/GroupPermissionResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\GroupPermissionResolver;
use PHPUnit\Framework\TestCase;

final class GroupPermissionResolverTest extends TestCase
{
    private function row(bool $canPost, bool $canReply, bool $autopublish, bool $canEditOwn, bool $canDeleteOwn): array
    {
        return [
            'can_post' => $canPost,
            'can_reply' => $canReply,
            'autopublish' => $autopublish,
            'can_edit_own' => $canEditOwn,
            'can_delete_own' => $canDeleteOwn,
        ];
    }

    public function testDeniesEverythingWhenNoGroupHasARow(): void
    {
        $result = GroupPermissionResolver::resolve([], [99]);

        self::assertSame(
            ['can_post' => false, 'can_reply' => false, 'autopublish' => false, 'can_edit_own' => false, 'can_delete_own' => false],
            $result
        );
    }

    public function testDeniesEverythingWhenUserHasNoAuthorisedGroups(): void
    {
        $result = GroupPermissionResolver::resolve([1 => $this->row(true, true, true, true, true)], []);

        self::assertFalse($result['can_post']);
    }

    public function testGrantsPermissionFromTheMatchingGroupRow(): void
    {
        $result = GroupPermissionResolver::resolve(
            [2 => $this->row(true, true, false, true, true)],
            [2]
        );

        self::assertTrue($result['can_post']);
        self::assertTrue($result['can_reply']);
        self::assertFalse($result['autopublish']);
        self::assertTrue($result['can_edit_own']);
        self::assertTrue($result['can_delete_own']);
    }

    public function testIgnoresAnAuthorisedGroupIdWithNoConfiguredRow(): void
    {
        $result = GroupPermissionResolver::resolve(
            [2 => $this->row(true, true, true, true, true)],
            [999, 2]
        );

        self::assertTrue($result['can_post']);
    }

    public function testOrsPermissionsAcrossMultipleAuthorisedGroups(): void
    {
        $result = GroupPermissionResolver::resolve(
            [
                1 => $this->row(false, false, false, false, false),
                6 => $this->row(true, false, false, false, false),
            ],
            [1, 6]
        );

        self::assertTrue($result['can_post']);
        self::assertFalse($result['can_reply']);
    }

    public function testEachPermissionFlagIsIndependentlyOred(): void
    {
        $result = GroupPermissionResolver::resolve(
            [
                1 => $this->row(true, false, false, false, false),
                2 => $this->row(false, true, false, false, false),
                3 => $this->row(false, false, true, false, false),
                4 => $this->row(false, false, false, true, false),
                5 => $this->row(false, false, false, false, true),
            ],
            [1, 2, 3, 4, 5]
        );

        self::assertSame(
            ['can_post' => true, 'can_reply' => true, 'autopublish' => true, 'can_edit_own' => true, 'can_delete_own' => true],
            $result
        );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Service/GroupPermissionResolverTest.php`
Expected: FAIL — `Class "Lcsilva\Component\Lcomment\Administrator\Service\GroupPermissionResolver" not found`.

- [ ] **Step 3: Implement `GroupPermissionResolver`**

Create `com_lcomment/administrator/components/com_lcomment/src/Service/GroupPermissionResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class GroupPermissionResolver
{
    private const FLAGS = ['can_post', 'can_reply', 'autopublish', 'can_edit_own', 'can_delete_own'];

    /**
     * @param array<int, array{can_post: bool, can_reply: bool, autopublish: bool, can_edit_own: bool, can_delete_own: bool}> $rowsByGroupId
     * @param int[] $authorisedGroupIds
     * @return array{can_post: bool, can_reply: bool, autopublish: bool, can_edit_own: bool, can_delete_own: bool}
     */
    public static function resolve(array $rowsByGroupId, array $authorisedGroupIds): array
    {
        $result = array_fill_keys(self::FLAGS, false);

        foreach ($authorisedGroupIds as $groupId) {
            if (!isset($rowsByGroupId[$groupId])) {
                continue;
            }

            foreach (self::FLAGS as $flag) {
                $result[$flag] = $result[$flag] || (bool) $rowsByGroupId[$groupId][$flag];
            }
        }

        return $result;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Service/GroupPermissionResolverTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Run the full suite and commit**

Run: `vendor/bin/phpunit`
Expected: PASS, full suite.

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/GroupPermissionResolver.php tests/Service/GroupPermissionResolverTest.php
git commit -m "feat(lcomment): add GroupPermissionResolver domain class"
```

---

### Task 3: `SubmissionRequest`/`SubmissionPolicy` — retire `guestsAllowed`, add group permissions and autopublish

**Files:**
- Modify: `com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionRequest.php`
- Modify: `com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionPolicy.php`
- Modify: `tests/Service/SubmissionPolicyTest.php`

**Interfaces:**
- Consumes: nothing new (the `canPost`/`canReply`/`autopublish` values
  are computed by the caller — Task 4 — via `GroupPermissionResolver`;
  this task only changes the shape `SubmissionRequest` accepts).
- Produces: `SubmissionRequest`'s new constructor signature (below) and
  `SubmissionPolicy::evaluate()`'s new initial-state formula. Consumed
  by Task 4 (`CommentController::save()`) and Task 5
  (`CommentController::edit()`, which builds a request the same way).

- [ ] **Step 1: Write the failing tests**

Replace the full content of `tests/Service/SubmissionPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionPolicy;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionRequest;
use PHPUnit\Framework\TestCase;

final class SubmissionPolicyTest extends TestCase
{
    private function request(array $overrides = []): SubmissionRequest
    {
        return new SubmissionRequest(
            contextActive: $overrides['contextActive'] ?? true,
            contextModeration: $overrides['contextModeration'] ?? true,
            userId: $overrides['userId'] ?? null,
            text: $overrides['text'] ?? 'A valid comment body.',
            minLength: $overrides['minLength'] ?? 3,
            maxLength: $overrides['maxLength'] ?? 1000,
            guestName: $overrides['guestName'] ?? 'A Guest',
            guestEmail: $overrides['guestEmail'] ?? 'guest@example.com',
            itemIncluded: $overrides['itemIncluded'] ?? true,
            parentValid: $overrides['parentValid'] ?? true,
            isReply: $overrides['isReply'] ?? false,
            canPost: $overrides['canPost'] ?? true,
            canReply: $overrides['canReply'] ?? true,
            autopublish: $overrides['autopublish'] ?? false,
        );
    }

    public function testRejectsInactiveContext(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['contextActive' => false]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_CONTEXT_INACTIVE'], $result->errors);
    }

    public function testRejectsNewCommentWhenGroupLacksCanPost(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'isReply' => false,
            'canPost' => false,
            'canReply' => true,
        ]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED'], $result->errors);
    }

    public function testRejectsReplyWhenGroupLacksCanReply(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'isReply' => true,
            'canPost' => true,
            'canReply' => false,
        ]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED'], $result->errors);
    }

    public function testAllowsNewCommentWhenGroupHasCanPost(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['isReply' => false, 'canPost' => true]));

        self::assertTrue($result->accepted);
    }

    public function testAllowsReplyWhenGroupHasCanReplyEvenWithoutCanPost(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'isReply' => true,
            'canPost' => false,
            'canReply' => true,
        ]));

        self::assertTrue($result->accepted);
    }

    public function testRejectsRegisteredUserWhenGroupLacksCanPost(): void
    {
        // The permission check is unified — a registered user is governed
        // by their own group's can_post exactly like a guest is governed
        // by the Público group's, with no special-casing by identity.
        $result = SubmissionPolicy::evaluate($this->request([
            'userId' => 42,
            'isReply' => false,
            'canPost' => false,
        ]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED'], $result->errors);
    }

    public function testRejectsInvalidText(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['text' => '']));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_TEXT_REQUIRED'], $result->errors);
    }

    public function testModerationEnabledWithoutAutopublishYieldsPendingState(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['contextModeration' => true, 'autopublish' => false]));

        self::assertTrue($result->accepted);
        self::assertSame(0, $result->initialState);
    }

    public function testModerationDisabledYieldsPublishedState(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['contextModeration' => false]));

        self::assertTrue($result->accepted);
        self::assertSame(1, $result->initialState);
    }

    public function testAutopublishYieldsPublishedStateEvenWithModerationEnabled(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['contextModeration' => true, 'autopublish' => true]));

        self::assertTrue($result->accepted);
        self::assertSame(1, $result->initialState);
    }

    public function testContextCheckTakesPriorityOverTextErrors(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'contextActive' => false,
            'text' => '',
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_CONTEXT_INACTIVE'], $result->errors);
    }

    public function testAcceptedResultStoresTrimmedText(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['text' => "  Hello there.  \n"]));

        self::assertTrue($result->accepted);
        self::assertSame('Hello there.', $result->normalizedText);
    }

    public function testTrailingWhitespacePaddingDoesNotBypassMaximumLength(): void
    {
        $padded = str_repeat('a', 100) . str_repeat(' ', 100000);

        $result = SubmissionPolicy::evaluate($this->request(['text' => $padded, 'maxLength' => 100]));

        self::assertTrue($result->accepted);
        self::assertSame(100, \strlen($result->normalizedText));
    }

    public function testRejectsEmptyGuestName(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['userId' => null, 'guestName' => '']));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GUEST_NAME_REQUIRED'], $result->errors);
    }

    public function testRejectsWhitespaceOnlyGuestName(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['userId' => null, 'guestName' => "   \t"]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GUEST_NAME_REQUIRED'], $result->errors);
    }

    public function testRejectsGuestNameLongerThanColumnWidth(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['userId' => null, 'guestName' => str_repeat('a', 151)]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GUEST_NAME_TOO_LONG'], $result->errors);
    }

    public function testRejectsInvalidGuestEmail(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['userId' => null, 'guestEmail' => 'not-an-email']));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GUEST_EMAIL_INVALID'], $result->errors);
    }

    public function testRejectsGuestEmailLongerThanColumnWidth(): void
    {
        $longLocalPart = str_repeat('a', 250);

        $result = SubmissionPolicy::evaluate($this->request(['userId' => null, 'guestEmail' => $longLocalPart . '@example.com']));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GUEST_EMAIL_TOO_LONG'], $result->errors);
    }

    public function testLoggedInUserIsNotRequiredToProvideGuestNameOrEmail(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'userId' => 42,
            'guestName' => '',
            'guestEmail' => '',
        ]));

        self::assertTrue($result->accepted);
    }

    public function testGroupCheckTakesPriorityOverGuestDetailChecks(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'userId' => null,
            'canPost' => false,
            'guestName' => '',
            'text' => '',
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED'], $result->errors);
    }

    public function testRejectsItemExcludedByScope(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['itemIncluded' => false]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_ITEM_EXCLUDED'], $result->errors);
    }

    public function testAllowsItemIncludedByScope(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['itemIncluded' => true]));

        self::assertTrue($result->accepted);
    }

    public function testContextCheckTakesPriorityOverItemExcluded(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'contextActive' => false,
            'itemIncluded' => false,
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_CONTEXT_INACTIVE'], $result->errors);
    }

    public function testItemExcludedTakesPriorityOverGroupCheck(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'itemIncluded' => false,
            'canPost' => false,
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_ITEM_EXCLUDED'], $result->errors);
    }

    public function testRejectsInvalidParent(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['parentValid' => false]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_PARENT'], $result->errors);
    }

    public function testAllowsValidParent(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['parentValid' => true]));

        self::assertTrue($result->accepted);
    }

    public function testItemExcludedTakesPriorityOverInvalidParent(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'itemIncluded' => false,
            'parentValid' => false,
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_ITEM_EXCLUDED'], $result->errors);
    }

    public function testInvalidParentTakesPriorityOverGroupCheck(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'parentValid' => false,
            'canPost' => false,
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_PARENT'], $result->errors);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Service/SubmissionPolicyTest.php`
Expected: FAIL — `Unknown named parameter $isReply` (or similar constructor-mismatch error), since `SubmissionRequest`/`SubmissionPolicy` haven't changed yet.

- [ ] **Step 3: Update `SubmissionRequest`**

Replace the full content of `com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionRequest.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class SubmissionRequest
{
    public function __construct(
        public readonly bool $contextActive,
        public readonly bool $contextModeration,
        public readonly ?int $userId,
        public readonly string $text,
        public readonly int $minLength,
        public readonly int $maxLength,
        public readonly string $guestName = '',
        public readonly string $guestEmail = '',
        public readonly bool $itemIncluded = true,
        public readonly bool $parentValid = true,
        public readonly bool $isReply = false,
        public readonly bool $canPost = false,
        public readonly bool $canReply = false,
        public readonly bool $autopublish = false,
    ) {
    }
}
```

- [ ] **Step 4: Update `SubmissionPolicy`**

In `com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionPolicy.php`, change:

```php
        if ($request->userId === null && !$request->guestsAllowed) {
            return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED']);
        }
```

to:

```php
        $permitted = $request->isReply ? $request->canReply : $request->canPost;

        if (!$permitted) {
            return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED']);
        }
```

And change:

```php
        return new SubmissionResult(
            true,
            [],
            $request->contextModeration ? 0 : 1,
            trim($request->text)
        );
```

to:

```php
        return new SubmissionResult(
            true,
            [],
            ($request->contextModeration && !$request->autopublish) ? 0 : 1,
            trim($request->text)
        );
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Service/SubmissionPolicyTest.php`
Expected: PASS (27 tests).

- [ ] **Step 6: Run the full suite and commit**

Run: `vendor/bin/phpunit`
Expected: PASS, full suite.

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionRequest.php com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionPolicy.php tests/Service/SubmissionPolicyTest.php
git commit -m "feat(lcomment): replace guestsAllowed with group-based can_post/can_reply/autopublish"
```

---

### Task 4: Site `CommentModel` + `CommentController::save()` wiring

**Files:**
- Modify: `com_lcomment/components/com_lcomment/src/Model/CommentModel.php`
- Modify: `com_lcomment/components/com_lcomment/src/Controller/CommentController.php`

**Interfaces:**
- Consumes: `GroupPermissionResolver::resolve()` (Task 2), the new
  `SubmissionRequest` shape (Task 3).
- Produces: `CommentModel::getGroupPermissions(): array<int, array{can_post: bool, can_reply: bool, autopublish: bool, can_edit_own: bool, can_delete_own: bool}>`
  (indexed by `group_id`) and the trashed-with-replies extension to
  `getItemsFor()`. Both consumed by Task 5 (`edit()`/`delete()` reuse
  `getGroupPermissions()`) and Task 6 (the content plugin calls
  `getGroupPermissions()` once per page to compute the current
  visitor's `userPermissions` for the layout).
- No PHPUnit coverage possible (framework/DB code, same established
  split as the rest of this component's Models/Controllers).

- [ ] **Step 1: Add `getGroupPermissions()` to the site `CommentModel`**

In `com_lcomment/components/com_lcomment/src/Model/CommentModel.php`, add this method (anywhere among the other public methods — e.g. right after `getAuthorNames()`):

```php
    /**
     * @return array<int, array{can_post: bool, can_reply: bool, autopublish: bool, can_edit_own: bool, can_delete_own: bool}>
     */
    public function getGroupPermissions(): array
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__lcomment_group_permissions'));

        $db->setQuery($query);

        $rows = $db->loadAssocList('group_id');

        $result = [];

        foreach ($rows as $groupId => $row) {
            $result[(int) $groupId] = [
                'can_post' => (bool) $row['can_post'],
                'can_reply' => (bool) $row['can_reply'],
                'autopublish' => (bool) $row['autopublish'],
                'can_edit_own' => (bool) $row['can_edit_own'],
                'can_delete_own' => (bool) $row['can_delete_own'],
            ];
        }

        return $result;
    }
```

`DatabaseDriver::loadAssocList($key)` (verified against real
`joomla-framework/database` source) indexes the returned array by the
named column's value — exactly `group_id => row` here, no manual
re-indexing needed.

- [ ] **Step 2: Extend `getItemsFor()` to also fetch a trashed comment that still has a surviving reply**

In `com_lcomment/components/com_lcomment/src/Model/CommentModel.php`, replace the full body of `getItemsFor()`:

```php
    public function getItemsFor(string $extension, string $view, int $itemId): array
    {
        $db = $this->getDatabase();
        $user = \Joomla\CMS\Factory::getApplication()->getIdentity();

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__lcomment_comments'))
            ->where($db->quoteName('extension') . ' = :extension')
            ->where($db->quoteName('view') . ' = :view')
            ->where($db->quoteName('item_id') . ' = :itemId')
            ->bind(':extension', $extension, ParameterType::STRING)
            ->bind(':view', $view, ParameterType::STRING)
            ->bind(':itemId', $itemId, ParameterType::INTEGER)
            ->order($db->quoteName('created') . ' ASC');

        if ($user && $user->id > 0) {
            $query->extendWhere(
                'AND',
                [
                    $db->quoteName('state') . ' = 1',
                    '(' . $db->quoteName('state') . ' = 0 AND ' . $db->quoteName('user_id') . ' = :userId)',
                    '(' . $db->quoteName('state') . ' = -2 AND ' . self::trashedHasSurvivingReplySql($db) . ')',
                ],
                'OR'
            )->bind(':userId', $user->id, ParameterType::INTEGER);
        } else {
            $query->extendWhere(
                'AND',
                [
                    $db->quoteName('state') . ' = 1',
                    '(' . $db->quoteName('state') . ' = -2 AND ' . self::trashedHasSurvivingReplySql($db) . ')',
                ],
                'OR'
            );
        }

        $db->setQuery($query);

        return $db->loadObjectList() ?: [];
    }
```

Then add this private helper (anywhere in the class):

```php
    private static function trashedHasSurvivingReplySql(\Joomla\Database\DatabaseInterface $db): string
    {
        return 'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__lcomment_comments', 'r')
            . ' WHERE ' . $db->quoteName('r.parent_id') . ' = ' . $db->quoteName('#__lcomment_comments.id')
            . ' AND ' . $db->quoteName('r.state') . ' != -2)';
    }
```

**Important:** the main query's `FROM` clause has no alias
(`#__lcomment_comments`, not `#__lcomment_comments AS c`) — the
unaliased table name must be used on the outer side of the `EXISTS`
correlation (`#__lcomment_comments.id`) to match. Do not introduce an
alias on the outer query as part of this step; that would be a larger,
unrelated change to every other `where()`/`select()` call in this
method.

- [ ] **Step 3: Wire group permissions into `CommentController::save()`**

In `com_lcomment/components/com_lcomment/src/Controller/CommentController.php`, add the import — change:

```php
use Joomla\CMS\Uri\Uri;
use Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotifier;
```

to:

```php
use Joomla\CMS\Uri\Uri;
use Lcsilva\Component\Lcomment\Administrator\Service\GroupPermissionResolver;
use Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotifier;
```

Then change:

```php
        $user = $app->getIdentity();
        $params = ComponentHelper::getParams('com_lcomment');

        $policyResult = SubmissionPolicy::evaluate(new SubmissionRequest(
            contextActive: $context !== null,
            contextModeration: $context !== null && (bool) $context->moderation,
            guestsAllowed: (bool) $params->get('allow_guests', 1),
            userId: $user && $user->id > 0 ? (int) $user->id : null,
            text: $text,
            minLength: (int) $params->get('min_length', 3),
            maxLength: (int) $params->get('max_length', 2000),
            guestName: $guestName,
            guestEmail: $guestEmail,
            itemIncluded: $itemIncluded,
            parentValid: $parentValid,
        ));
```

to:

```php
        $user = $app->getIdentity();
        $params = ComponentHelper::getParams('com_lcomment');

        $permissions = GroupPermissionResolver::resolve(
            $model->getGroupPermissions(),
            $user->getAuthorisedGroups()
        );

        $policyResult = SubmissionPolicy::evaluate(new SubmissionRequest(
            contextActive: $context !== null,
            contextModeration: $context !== null && (bool) $context->moderation,
            userId: $user && $user->id > 0 ? (int) $user->id : null,
            text: $text,
            minLength: (int) $params->get('min_length', 3),
            maxLength: (int) $params->get('max_length', 2000),
            guestName: $guestName,
            guestEmail: $guestEmail,
            itemIncluded: $itemIncluded,
            parentValid: $parentValid,
            isReply: $parentId !== 0,
            canPost: $permissions['can_post'],
            canReply: $permissions['can_reply'],
            autopublish: $permissions['autopublish'],
        ));
```

`$user->getAuthorisedGroups()` works for both a registered identity and
a guest one (`$user->id === 0`) — verified against real `joomla-cms`
6.1-dev source (`User::getAuthorisedGroups()` →
`Access::getGroupsByUser($this->id)`, which for `id === 0` resolves
from the configured guest group and its ancestors). No `$user ? ... :
...` branching needed here, unlike the `userId:` field just below it
(which still needs the null-for-guest distinction for a different
reason — recording who authored the comment).

- [ ] **Step 4: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/components/com_lcomment/src/Model/CommentModel.php && php -l com_lcomment/components/com_lcomment/src/Controller/CommentController.php && vendor/bin/phpunit`
Expected: two `No syntax errors detected...` lines, then PASS on the full suite.

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/components/com_lcomment/src/Model/CommentModel.php com_lcomment/components/com_lcomment/src/Controller/CommentController.php
git commit -m "feat(lcomment): wire group permissions into comment submission, fetch trashed-with-replies"
```

- [ ] **Step 6: Manual check to perform later, during live Joomla verification (not automatable here)**

Create a comment, reply to it, then **trash the reply too** (via the
admin Comments list), so the parent has only trashed descendants. Load
the page as the parent's own author (or any logged-in user) and confirm
the parent is **not** shown at all — not even as a placeholder. This is
the Review Focus case the `EXISTS` subquery's `r.state != -2` condition
exists to satisfy, and it cannot be exercised by any of this project's
automated tests (no PHPUnit harness touches live DB queries).

---

### Task 5: Edit and delete one's own comment

**Files:**
- Modify: `com_lcomment/components/com_lcomment/src/Controller/CommentController.php`
- Modify: `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`

**Interfaces:**
- Consumes: `GroupPermissionResolver::resolve()` (Task 2),
  `CommentModel::getGroupPermissions()` (Task 4),
  `SubmissionPolicy`/`SubmissionRequest`'s moderation-formula logic
  (Task 3, reused directly rather than re-exported, since `edit()`
  doesn't go through the full `SubmissionPolicy::evaluate()` — it
  already knows the comment is the caller's own and skips the
  guest/context/scope checks that only apply to a brand-new comment).
- Produces: `CommentController::edit(): bool` and
  `CommentController::delete(): bool`, reachable via
  `task=comment.edit` / `task=comment.delete`. Consumed by Task 6 (the
  layout's new "Editar"/"Eliminar" forms submit to these).
- No PHPUnit coverage possible (framework/DB code).

- [ ] **Step 1: Add `edit()` and `delete()`**

In `com_lcomment/components/com_lcomment/src/Controller/CommentController.php`, add the import — change:

```php
use Lcsilva\Component\Lcomment\Administrator\Service\GroupPermissionResolver;
use Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotifier;
use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionPolicy;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionRequest;
use Lcsilva\Component\Lcomment\Administrator\Table\CommentTable;
```

to:

```php
use Lcsilva\Component\Lcomment\Administrator\Service\CommentValidator;
use Lcsilva\Component\Lcomment\Administrator\Service\GroupPermissionResolver;
use Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotifier;
use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionPolicy;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionRequest;
use Lcsilva\Component\Lcomment\Administrator\Table\CommentTable;
use Joomla\Database\DatabaseInterface;
```

Then add both new methods at the end of the class, right before the
final closing `}`:

```php
    public function edit(): bool
    {
        Session::checkToken('post') or die(Text::_('JINVALID_TOKEN'));

        $app = Factory::getApplication();
        $input = $app->getInput();
        $returnUrl = base64_decode($input->getBase64('return', ''));
        $user = $app->getIdentity();

        if ($user === null || $user->id === 0) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_NOT_OWNER'), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $commentId = $input->getInt('id', 0);

        /** @var CommentTable $table */
        $table = new CommentTable(Factory::getContainer()->get(DatabaseInterface::class));

        if (!$table->load($commentId) || (int) $table->user_id !== (int) $user->id) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_NOT_OWNER'), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        /** @var \Lcsilva\Component\Lcomment\Site\Model\CommentModel $model */
        $model = $this->getModel('Comment', 'Site');

        $permissions = GroupPermissionResolver::resolve($model->getGroupPermissions(), $user->getAuthorisedGroups());

        if (!$permissions['can_edit_own']) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED'), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $text = (string) $input->get('comment_text', '', 'RAW');
        $params = ComponentHelper::getParams('com_lcomment');
        $errors = CommentValidator::validate($text, (int) $params->get('min_length', 3), (int) $params->get('max_length', 2000));

        if ($errors !== []) {
            foreach ($errors as $error) {
                $app->enqueueMessage(Text::_($error), 'error');
            }

            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $context = $model->getContext((string) $table->extension, (string) $table->view);
        $contextModeration = $context !== null && (bool) $context->moderation;

        $table->comment_text = trim($text);
        $table->state = ($contextModeration && !$permissions['autopublish']) ? 0 : 1;
        $table->modified = Factory::getDate()->toSql();

        if (!$table->check() || !$table->store()) {
            $app->enqueueMessage($table->getError(), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $app->enqueueMessage(
            Text::_($table->state === 1 ? 'COM_LCOMMENT_SAVE_SUCCESS_PUBLISHED' : 'COM_LCOMMENT_SAVE_SUCCESS_PENDING')
        );
        $app->redirect($returnUrl ?: 'index.php');

        return true;
    }

    public function delete(): bool
    {
        Session::checkToken('post') or die(Text::_('JINVALID_TOKEN'));

        $app = Factory::getApplication();
        $input = $app->getInput();
        $returnUrl = base64_decode($input->getBase64('return', ''));
        $user = $app->getIdentity();

        if ($user === null || $user->id === 0) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_NOT_OWNER'), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $commentId = $input->getInt('id', 0);

        /** @var CommentTable $table */
        $table = new CommentTable(Factory::getContainer()->get(DatabaseInterface::class));

        if (!$table->load($commentId) || (int) $table->user_id !== (int) $user->id) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_NOT_OWNER'), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        /** @var \Lcsilva\Component\Lcomment\Site\Model\CommentModel $model */
        $model = $this->getModel('Comment', 'Site');

        $permissions = GroupPermissionResolver::resolve($model->getGroupPermissions(), $user->getAuthorisedGroups());

        if (!$permissions['can_delete_own']) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED'), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $table->state = -2;

        if (!$table->store()) {
            $app->enqueueMessage($table->getError(), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $app->enqueueMessage(Text::_('COM_LCOMMENT_DELETE_SUCCESS'));
        $app->redirect($returnUrl ?: 'index.php');

        return true;
    }
```

- [ ] **Step 2: Add the new language keys**

Append to `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_ERROR_NOT_OWNER="You cannot edit or delete this comment."
COM_LCOMMENT_DELETE_SUCCESS="Your comment was deleted."
COM_LCOMMENT_DELETED_PLACEHOLDER="Comment removed."
COM_LCOMMENT_EDIT_LABEL="Edit"
COM_LCOMMENT_DELETE_LABEL="Delete"
COM_LCOMMENT_DELETE_CONFIRM="Are you sure you want to delete this comment?"
```

Append to `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_ERROR_NOT_OWNER="Não pode editar ou eliminar este comentário."
COM_LCOMMENT_DELETE_SUCCESS="O seu comentário foi eliminado."
COM_LCOMMENT_DELETED_PLACEHOLDER="Comentário removido."
COM_LCOMMENT_EDIT_LABEL="Editar"
COM_LCOMMENT_DELETE_LABEL="Eliminar"
COM_LCOMMENT_DELETE_CONFIRM="Tem a certeza que quer eliminar este comentário?"
```

- [ ] **Step 3: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/components/com_lcomment/src/Controller/CommentController.php && vendor/bin/phpunit`
Expected: `No syntax errors detected...` followed by PASS on the full suite.

- [ ] **Step 4: Commit**

```bash
git add com_lcomment/components/com_lcomment/src/Controller/CommentController.php com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini
git commit -m "feat(lcomment): add edit/delete-own-comment site actions"
```

- [ ] **Step 5: Manual check to perform later, during live Joomla verification (not automatable here)**

Confirm a visitor (not logged in) has no way to reach `task=comment.edit`/`comment.delete` with any effect — `$user->id === 0` rejects before anything else runs, regardless of what the Público group's `can_edit_own`/`can_delete_own` happen to be set to in the admin UI. This is the Review Focus item about guests never editing/deleting even if that checkbox were mistakenly enabled.

---

### Task 6: Layout — editar/eliminar buttons, deleted-comment placeholder

**Files:**
- Modify: `com_lcomment/components/com_lcomment/layouts/comment.php`
- Modify: `plg_content_lcomment/src/Extension/Lcomment.php`

**Interfaces:**
- Consumes: `CommentModel::getGroupPermissions()` (Task 4),
  `GroupPermissionResolver::resolve()` (Task 2).
- Produces: nothing consumed by a later task — this is the UI leaf.
- No PHPUnit coverage possible (layout/plugin code).

- [ ] **Step 1: Compute and pass the current visitor's permissions from the content plugin**

In `plg_content_lcomment/src/Extension/Lcomment.php`, add the import — change:

```php
use Lcsilva\Component\Lcomment\Administrator\Service\ContextResolver;
use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;
```

to:

```php
use Lcsilva\Component\Lcomment\Administrator\Service\ContextResolver;
use Lcsilva\Component\Lcomment\Administrator\Service\GroupPermissionResolver;
use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;
```

Then change:

```php
        $authorNames = $model->getAuthorNames(array_map(static fn ($comment) => $comment->user_id, $items));
        $returnUrl = Uri::getInstance()->toString();
```

to:

```php
        $authorNames = $model->getAuthorNames(array_map(static fn ($comment) => $comment->user_id, $items));
        $returnUrl = Uri::getInstance()->toString();

        $currentUser = $app->getIdentity();
        $userPermissions = GroupPermissionResolver::resolve(
            $model->getGroupPermissions(),
            $currentUser->getAuthorisedGroups()
        );
```

And change:

```php
                'authorNames' => $authorNames,
                'returnUrl' => $returnUrl,
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
            ],
            \JPATH_ROOT . '/components/com_lcomment/layouts'
        );
```

`$currentUserId` is passed separately from `$userPermissions` because
the layout needs **both** facts independently: whether this viewer is
registered at all (`currentUserId !== null`) and, only if so, whether
their resolved group permissions allow editing/deleting — a guest with
(mistakenly) `can_edit_own = true` on the Público group must still
never see the button, matching `CommentController::edit()`/`delete()`'s
own `$user->id === 0` guard from Task 5.

- [ ] **Step 2: Render the deleted-comment placeholder, and the Editar/Eliminar buttons**

In `com_lcomment/components/com_lcomment/layouts/comment.php`, add two
new render closures right after `$renderVotes` and before `$renderNode`
— change:

```php
$renderNode = function (array $node, int $depth) use (&$renderNode, $renderForm, $renderReactions, $renderVotes, $prefillParentId, $prefillText, $authorNames, $reactionsEnabled, $votesEnabled): void {
```

to:

```php
$renderEditForm = function (int $commentId, string $currentText, string $returnUrlLocal): void {
    ?>
    <details class="lcomment-edit">
        <summary><?php echo Text::_('COM_LCOMMENT_EDIT_LABEL'); ?></summary>
        <form method="post" action="<?php echo Route::_('index.php?option=com_lcomment&task=comment.edit'); ?>" class="lcomment-form">
            <input type="hidden" name="id" value="<?php echo $commentId; ?>">
            <input type="hidden" name="return" value="<?php echo base64_encode($returnUrlLocal); ?>">
            <div class="mb-2">
                <textarea id="lcomment-edit-text-<?php echo $commentId; ?>" name="comment_text" class="form-control" required><?php echo htmlspecialchars($currentText); ?></textarea>
            </div>
            <?php echo HTMLHelper::_('form.token'); ?>
            <button type="submit" class="btn btn-secondary">
                <?php echo Text::_('COM_LCOMMENT_FORM_SUBMIT_LABEL'); ?>
            </button>
        </form>
    </details>
    <?php
};

$renderDeleteButton = function (int $commentId, string $returnUrlLocal): void {
    ?>
    <form method="post" action="<?php echo Route::_('index.php?option=com_lcomment&task=comment.delete'); ?>" class="lcomment-form" onsubmit="return confirm('<?php echo htmlspecialchars(Text::_('COM_LCOMMENT_DELETE_CONFIRM'), ENT_QUOTES); ?>');">
        <input type="hidden" name="id" value="<?php echo $commentId; ?>">
        <input type="hidden" name="return" value="<?php echo base64_encode($returnUrlLocal); ?>">
        <?php echo HTMLHelper::_('form.token'); ?>
        <button type="submit" class="btn btn-sm btn-outline-danger">
            <?php echo Text::_('COM_LCOMMENT_DELETE_LABEL'); ?>
        </button>
    </form>
    <?php
};

$renderNode = function (array $node, int $depth) use (&$renderNode, $renderForm, $renderReactions, $renderVotes, $renderEditForm, $renderDeleteButton, $prefillParentId, $prefillText, $authorNames, $reactionsEnabled, $votesEnabled, $userPermissions, $currentUserId, $returnUrl): void {
```

Then, inside `$renderNode`'s body, change the whole function (the
deleted-comment branch must short-circuit before any of the normal
reaction/vote/reply/edit/delete UI, since a removed comment accepts no
further interaction):

```php
    $comment = $node['comment'];
    $commentId = (int) $comment->id;
    $depthClass = 'lcomment-depth-' . min($depth, 5);
    $isReplyOpen = $prefillParentId === $commentId;
    $authorName = $comment->guest_name ?: ($authorNames[(int) $comment->user_id] ?? ('#' . (int) $comment->user_id));
    ?>
    <li class="lcomment-item <?php echo $depthClass; ?>" data-id="<?php echo $commentId; ?>">
        <?php if ((int) $comment->state === -2) : ?>
            <p class="lcomment-removed"><em><?php echo Text::_('COM_LCOMMENT_DELETED_PLACEHOLDER'); ?></em></p>
        <?php else : ?>
            <strong>
                <?php echo htmlspecialchars($authorName); ?>
            </strong>
            <?php if ((int) $comment->state === 0) : ?>
                <span class="badge bg-warning"><?php echo Text::_('COM_LCOMMENT_LIST_PENDING_BADGE'); ?></span>
            <?php endif; ?>
            <p><?php echo htmlspecialchars((string) $comment->comment_text); ?></p>

            <?php if ($reactionsEnabled) : ?>
                <?php $renderReactions($commentId); ?>
            <?php endif; ?>
            <?php if ($votesEnabled) : ?>
                <?php $renderVotes($commentId); ?>
            <?php endif; ?>

            <?php if ($currentUserId !== null && (int) $comment->user_id === $currentUserId) : ?>
                <?php if ($userPermissions['can_edit_own']) : ?>
                    <?php $renderEditForm($commentId, (string) $comment->comment_text, $returnUrl); ?>
                <?php endif; ?>
                <?php if ($userPermissions['can_delete_own']) : ?>
                    <?php $renderDeleteButton($commentId, $returnUrl); ?>
                <?php endif; ?>
            <?php endif; ?>

            <details class="lcomment-reply"<?php echo $isReplyOpen ? ' open' : ''; ?>>
                <summary><?php echo Text::_('COM_LCOMMENT_REPLY_LABEL'); ?></summary>
                <?php $renderForm($commentId, (string) $commentId, $isReplyOpen ? $prefillText : ''); ?>
            </details>
        <?php endif; ?>

        <?php if (!empty($node['replies'])) : ?>
            <ul class="lcomment-list list-unstyled">
                <?php foreach ($node['replies'] as $child) : ?>
                    <?php $renderNode($child, $depth + 1); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </li>
    <?php
```

(The closing `};` after this block, and everything below it in the
file — the top-level `<div class="lcomment-block">` markup and the
final `$renderForm(0, 'top', ...)` call — stay exactly as they are.)

- [ ] **Step 3: Syntax-check**

Run: `php -l com_lcomment/components/com_lcomment/layouts/comment.php && php -l plg_content_lcomment/src/Extension/Lcomment.php && vendor/bin/phpunit`
Expected: two `No syntax errors detected...` lines, then PASS on the full suite.

- [ ] **Step 4: Commit**

```bash
git add com_lcomment/components/com_lcomment/layouts/comment.php plg_content_lcomment/src/Extension/Lcomment.php
git commit -m "feat(lcomment): render edit/delete-own-comment UI and the deleted-comment placeholder"
```

---

### Task 7: Admin "Permissões" view, final packaging

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Model/PermissionsModel.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Controller/PermissionsController.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/View/Permissions/HtmlView.php`
- Create: `com_lcomment/administrator/components/com_lcomment/tmpl/permissions/default.php`
- Modify: `com_lcomment/com_lcomment.xml`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.sys.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.sys.ini`

**Interfaces:**
- Consumes: nothing from an earlier task directly (reads/writes
  `#__lcomment_group_permissions` — Task 1 — on its own).
- Produces: the admin screen at
  `index.php?option=com_lcomment&view=permissions`. No later task
  consumes this.
- No PHPUnit coverage possible (framework/view code, same precedent as
  the existing `Comments`/`Contexts`/`Notifications` admin views).

- [ ] **Step 1: Add the submenu entry and language keys**

In `com_lcomment/com_lcomment.xml`, change:

```xml
            <menu view="notifications">COM_LCOMMENT_MENU_NOTIFICATIONS</menu>
        </submenu>
```

to:

```xml
            <menu view="notifications">COM_LCOMMENT_MENU_NOTIFICATIONS</menu>
            <menu view="permissions">COM_LCOMMENT_MENU_PERMISSIONS</menu>
        </submenu>
```

Append to `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.sys.ini`:

```ini
COM_LCOMMENT_MENU_PERMISSIONS="Permissions"
```

Append to `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.sys.ini`:

```ini
COM_LCOMMENT_MENU_PERMISSIONS="Permissões"
```

Append to `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_MENU_PERMISSIONS="Permissions"
COM_LCOMMENT_PERMISSIONS_TITLE="LComment: Permissions"
COM_LCOMMENT_PERMISSIONS_GROUP_LABEL="Group"
COM_LCOMMENT_PERMISSIONS_CAN_POST_LABEL="Post new comments"
COM_LCOMMENT_PERMISSIONS_CAN_REPLY_LABEL="Post replies"
COM_LCOMMENT_PERMISSIONS_AUTOPUBLISH_LABEL="Autopublish"
COM_LCOMMENT_PERMISSIONS_CAN_EDIT_OWN_LABEL="Edit own comments"
COM_LCOMMENT_PERMISSIONS_CAN_DELETE_OWN_LABEL="Delete own comments"
COM_LCOMMENT_PERMISSIONS_SAVED_MESSAGE="Permissions saved."
```

Append to `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_MENU_PERMISSIONS="Permissões"
COM_LCOMMENT_PERMISSIONS_TITLE="LComment: Permissões"
COM_LCOMMENT_PERMISSIONS_GROUP_LABEL="Grupo"
COM_LCOMMENT_PERMISSIONS_CAN_POST_LABEL="Publicar novos comentários"
COM_LCOMMENT_PERMISSIONS_CAN_REPLY_LABEL="Publicar respostas"
COM_LCOMMENT_PERMISSIONS_AUTOPUBLISH_LABEL="Publicação automática"
COM_LCOMMENT_PERMISSIONS_CAN_EDIT_OWN_LABEL="Editar o próprio comentário"
COM_LCOMMENT_PERMISSIONS_CAN_DELETE_OWN_LABEL="Eliminar o próprio comentário"
COM_LCOMMENT_PERMISSIONS_SAVED_MESSAGE="Permissões guardadas."
```

- [ ] **Step 2: Create `PermissionsModel`**

Create `com_lcomment/administrator/components/com_lcomment/src/Model/PermissionsModel.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;

final class PermissionsModel extends BaseDatabaseModel
{
    /**
     * @return array<int, array{group_id: int, title: string, level: int, can_post: bool, can_reply: bool, autopublish: bool, can_edit_own: bool, can_delete_own: bool}>
     */
    public function getGroups(): array
    {
        $db = $this->getDatabase();

        // #__usergroups has no `level` column (verified against the real
        // joomla-cms installation SQL — only id/parent_id/lft/rgt/title
        // exist); depth is the standard nested-set calculation, the
        // number of ancestor rows whose range strictly contains this
        // row's.
        $levelSql = '(SELECT COUNT(*) FROM ' . $db->quoteName('#__usergroups', 'anc')
            . ' WHERE ' . $db->quoteName('anc.lft') . ' < ' . $db->quoteName('g.lft')
            . ' AND ' . $db->quoteName('anc.rgt') . ' > ' . $db->quoteName('g.rgt') . ')';

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('g.id', 'group_id'),
                $db->quoteName('g.title'),
                $levelSql . ' AS ' . $db->quoteName('level'),
                $db->quoteName('p.can_post'),
                $db->quoteName('p.can_reply'),
                $db->quoteName('p.autopublish'),
                $db->quoteName('p.can_edit_own'),
                $db->quoteName('p.can_delete_own'),
            ])
            ->from($db->quoteName('#__usergroups', 'g'))
            ->join(
                'LEFT',
                $db->quoteName('#__lcomment_group_permissions', 'p') . ' ON ' . $db->quoteName('p.group_id') . ' = ' . $db->quoteName('g.id')
            )
            ->order($db->quoteName('g.lft') . ' ASC');

        $db->setQuery($query);

        $result = [];

        foreach ($db->loadAssocList() as $row) {
            $result[] = [
                'group_id' => (int) $row['group_id'],
                'title' => (string) $row['title'],
                'level' => (int) $row['level'],
                'can_post' => (bool) $row['can_post'],
                'can_reply' => (bool) $row['can_reply'],
                'autopublish' => (bool) $row['autopublish'],
                'can_edit_own' => (bool) $row['can_edit_own'],
                'can_delete_own' => (bool) $row['can_delete_own'],
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array{can_post?: string, can_reply?: string, autopublish?: string, can_edit_own?: string, can_delete_own?: string}> $rows
     *        Indexed by group_id, as posted by the form (checkbox values are '1' or absent).
     */
    public function save(array $rows): void
    {
        $db = $this->getDatabase();

        // Never trust a stale form's group ids blindly — a group could
        // have been deleted in Joomla between this page loading and the
        // admin clicking Save. Filtering against a fresh read costs one
        // query and avoids writing permission rows for ids that no
        // longer exist (harmless either way, since nothing can ever
        // authorise as a deleted group, but there is no reason to keep
        // dead rows around either).
        $existingQuery = $db->getQuery(true)->select($db->quoteName('id'))->from($db->quoteName('#__usergroups'));
        $db->setQuery($existingQuery);
        $validGroupIds = array_flip(array_map('intval', $db->loadColumn()));

        foreach ($rows as $groupId => $flags) {
            $groupId = (int) $groupId;

            if (!isset($validGroupIds[$groupId])) {
                continue;
            }

            $canPost = isset($flags['can_post']) ? 1 : 0;
            $canReply = isset($flags['can_reply']) ? 1 : 0;
            $autopublish = isset($flags['autopublish']) ? 1 : 0;
            $canEditOwn = isset($flags['can_edit_own']) ? 1 : 0;
            $canDeleteOwn = isset($flags['can_delete_own']) ? 1 : 0;

            $deleteQuery = $db->getQuery(true)
                ->delete($db->quoteName('#__lcomment_group_permissions'))
                ->where($db->quoteName('group_id') . ' = :groupId')
                ->bind(':groupId', $groupId, ParameterType::INTEGER);

            $db->setQuery($deleteQuery);
            $db->execute();

            $insertQuery = $db->getQuery(true)
                ->insert($db->quoteName('#__lcomment_group_permissions'))
                ->columns([
                    $db->quoteName('group_id'),
                    $db->quoteName('can_post'),
                    $db->quoteName('can_reply'),
                    $db->quoteName('autopublish'),
                    $db->quoteName('can_edit_own'),
                    $db->quoteName('can_delete_own'),
                ])
                ->values(implode(', ', [$groupId, $canPost, $canReply, $autopublish, $canEditOwn, $canDeleteOwn]));

            $db->setQuery($insertQuery);

            try {
                $db->execute();
            } catch (ExecutionFailureException $exception) {
                // Should not happen (the row was just deleted above in
                // the same request), but never let a single group's
                // write failure abort the rest of the form's save.
            }
        }
    }
}
```

A delete-then-insert per row (rather than a MySQL-specific
`REPLACE INTO`/`ON DUPLICATE KEY UPDATE`) matches the plain
insert()/delete() QueryBuilder style already used by every other query
in this component — this project only ever ships `sql/*.mysql.sql`
files (no PostgreSQL support exists or is implied elsewhere in this
codebase), so the choice here is about staying consistent with the
rest of the component's style, not about database portability.

- [ ] **Step 3: Create `PermissionsController`**

Create `com_lcomment/administrator/components/com_lcomment/src/Controller/PermissionsController.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;

final class PermissionsController extends BaseController
{
    public function save(): void
    {
        Session::checkToken('post') or die(Text::_('JINVALID_TOKEN'));

        $app = Factory::getApplication();
        $rows = (array) $app->getInput()->get('groups', [], 'array');

        /** @var \Lcsilva\Component\Lcomment\Administrator\Model\PermissionsModel $model */
        $model = $this->getModel('Permissions');
        $model->save($rows);

        $app->enqueueMessage(Text::_('COM_LCOMMENT_PERMISSIONS_SAVED_MESSAGE'));
        $app->redirect('index.php?option=com_lcomment&view=permissions');
    }
}
```

- [ ] **Step 4: Create the view and template**

Create `com_lcomment/administrator/components/com_lcomment/src/View/Permissions/HtmlView.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\View\Permissions;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

final class HtmlView extends BaseHtmlView
{
    public $groups;

    public function display($tpl = null)
    {
        $this->groups = $this->get('Groups');

        ToolbarHelper::title(Text::_('COM_LCOMMENT_PERMISSIONS_TITLE'));
        ToolbarHelper::custom('permissions.save', 'save', '', 'JTOOLBAR_SAVE', false);
        ToolbarHelper::preferences('com_lcomment');

        parent::display($tpl);
    }
}
```

Create `com_lcomment/administrator/components/com_lcomment/tmpl/permissions/default.php`:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Permissions\HtmlView $this */

$flags = [
    'can_post' => 'COM_LCOMMENT_PERMISSIONS_CAN_POST_LABEL',
    'can_reply' => 'COM_LCOMMENT_PERMISSIONS_CAN_REPLY_LABEL',
    'autopublish' => 'COM_LCOMMENT_PERMISSIONS_AUTOPUBLISH_LABEL',
    'can_edit_own' => 'COM_LCOMMENT_PERMISSIONS_CAN_EDIT_OWN_LABEL',
    'can_delete_own' => 'COM_LCOMMENT_PERMISSIONS_CAN_DELETE_OWN_LABEL',
];
?>
<form action="<?php echo htmlspecialchars(\Joomla\CMS\Uri\Uri::getInstance()->toString()); ?>" method="post" name="adminForm" id="adminForm">
    <table class="table">
        <thead>
            <tr>
                <th><?php echo Text::_('COM_LCOMMENT_PERMISSIONS_GROUP_LABEL'); ?></th>
                <?php foreach ($flags as $label) : ?>
                    <th><?php echo Text::_($label); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->groups as $group) : ?>
            <tr>
                <td><?php echo str_repeat('— ', max(0, $group['level'] - 1)) . htmlspecialchars($group['title']); ?></td>
                <?php foreach (array_keys($flags) as $flagKey) : ?>
                    <td>
                        <input type="checkbox"
                            name="groups[<?php echo $group['group_id']; ?>][<?php echo $flagKey; ?>]"
                            value="1"
                            <?php echo $group[$flagKey] ? 'checked' : ''; ?>
                        >
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
```

- [ ] **Step 5: Syntax-check and run the full test suite**

Run: `php -l com_lcomment/administrator/components/com_lcomment/src/Model/PermissionsModel.php && php -l com_lcomment/administrator/components/com_lcomment/src/Controller/PermissionsController.php && php -l com_lcomment/administrator/components/com_lcomment/src/View/Permissions/HtmlView.php && php -l com_lcomment/administrator/components/com_lcomment/tmpl/permissions/default.php && vendor/bin/phpunit`
Expected: four `No syntax errors detected...` lines, then PASS on the full suite.

- [ ] **Step 6: Commit**

```bash
git add com_lcomment/com_lcomment.xml com_lcomment/administrator/components/com_lcomment/language com_lcomment/administrator/components/com_lcomment/src/Model/PermissionsModel.php com_lcomment/administrator/components/com_lcomment/src/Controller/PermissionsController.php com_lcomment/administrator/components/com_lcomment/src/View/Permissions/HtmlView.php com_lcomment/administrator/components/com_lcomment/tmpl/permissions/default.php
git commit -m "feat(lcomment): add admin Permissions view for the group ACL matrix"
```

- [ ] **Step 7: Rebuild the package**

Run: `./build.sh`
Expected: `Built .../dist/pkg_lcomment.zip` — no `build.sh` changes were
needed for this task (no new plugin was added; the new `script.php` and
admin view are already inside the `com_lcomment/` folder the existing
zip command already archives recursively).

- [ ] **Step 8: Manual check to perform later, during live Joomla verification (not automatable here)**

Delete a custom Joomla group that has a row in the Permissions screen
while that screen is NOT open, then open the Permissions screen fresh
(confirming the deleted group's row is simply absent, not an error),
and separately confirm that saving the form normally (no stale state)
still updates every group correctly. This exercises the Review Focus
item about `PermissionsModel::save()` filtering stale group ids.

---

## Manual verification (real Joomla 6.1.4 site)

After installing the rebuilt `dist/pkg_lcomment.zip` (an **upgrade** —
confirm `#__lcomment_group_permissions` exists afterward, with one row
per existing group, and that `allow_guests` no longer appears under
Components → LComment → Options), run through the spec's 9 acceptance
criteria in order (see the spec's own "Critério de aceite" section),
plus the three manual-check notes left in Tasks 4, 5, and 7 above.

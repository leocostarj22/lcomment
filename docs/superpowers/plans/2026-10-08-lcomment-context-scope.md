# LComment Fase 2a — Escopo Granular de Contexto — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a Context restrict comments to specific `com_content` categories and/or specific items (any extension), instead of always applying to every item of an `extension + view` pair, with the decision enforced both at render time and at submission time.

**Architecture:** A new pure PHP `ScopeEvaluator` class (no Joomla dependency, PHPUnit-testable) holds every scope decision rule. The admin Context form gains a `scope_mode` field and a repeatable `scope_rules` subform, serialized to the existing `#__lcomment_contexts.params` JSON column. The content plugin and the site `CommentController` both call `ScopeEvaluator` independently — the plugin to decide whether to render the comment block, the controller to decide whether to accept a submission — so a forged request can't bypass scoping just because the block was never shown.

**Tech Stack:** Same as Phase 1 — PHP 8.1+, Joomla 5/6 CMS API, MySQL, PHPUnit 10.

**Spec:** `docs/superpowers/specs/2026-10-08-lcomment-context-scope-design.md` (and `docs/superpowers/specs/2026-10-07-lcomment-foundation-design.md` for the Phase 1 baseline this builds on)

## Global Constraints

- Real target environment is **Joomla 6.1.4** (confirmed via live testing of Phase 1), not 5.x as the original Phase 1 spec assumed — PHP 8.1+.
- Verify any Joomla framework behavior against the real `joomla-cms` source (`raw.githubusercontent.com/joomla/joomla-cms/5.4-dev/...`) before assuming a method signature or form attribute exists — several Phase 1 bugs came from trusting summarized tutorial pages instead.
- No new database table — reuses `#__lcomment_contexts.params` (existing JSON TEXT column) plus one new `scope_mode` column.
- All scope-decision logic lives in `Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator`, a framework-free class with no `_JEXEC` guard (same pattern as `ContextResolver`/`CommentValidator`/`SubmissionPolicy`), unit-tested with PHPUnit.
- Every scope decision applied at render time (the plugin) must be re-applied server-side (`CommentController::save()`) — never trust that a hidden block means the request can't happen.
- Confirmed against real Joomla source: a `subform` field's `formsource` attribute resolves relative to `JPATH_ROOT`, **not** relative to the component's `forms/` folder. Use the full path `administrator/components/com_lcomment/forms/context_scope_rule.xml`.
- Already-approved decision: category rules are dynamic (apply to future items in that category too), never a frozen list of ids resolved at save time.
- `scope_mode = 'include'` with an empty rule list excludes everything — this is deliberate, not a bug, and the admin UI must say so.
- The subform's `rule_type` field always offers both "Category" and "Specific item" options regardless of the parent Context's extension (a `showon` condition reaching into the parent form's own fields from inside a subform row is unconfirmed and not relied on) — `category`-type rules on a non-`com_content` context are rejected at save time by `ContextTable::check()` with a clear error instead.

## Review Focus

- `scope_mode = 'include'` with zero rules must exclude every item, not silently fall back to including everything.
- Corrupted/invalid JSON in `params` must not crash the page render or the comment-save flow — rule decoding must degrade to an empty rule list.
- A `category`-type rule must never match when `categoryId` is `null` (non-`com_content` contexts) — never let `null` compare equal to `0`.
- A forged POST to `comment.save` naming an item excluded by scope rules must be rejected server-side even though the comment block was never rendered for it.
- A `category`-type rule saved against a Context whose extension isn't `com_content` must be rejected at save time with a clear error, not silently accepted or silently ignored later.

---

### Task 1: Database migration — `scope_mode` column

**Files:**
- Modify: `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`
- Create: `com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.2.0.sql`
- Modify: `com_lcomment/com_lcomment.xml`
- Modify: `tests/Sql/SchemaTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the `scope_mode` column on `#__lcomment_contexts`, which Task 4 (`ContextTable`), Task 5 (`ContextModel`), Task 7 (plugin) and Task 8 (site controller) all read/write.

- [ ] **Step 1: Write the failing test**

Add to `tests/Sql/SchemaTest.php` (inside the existing `SchemaTest` class, alongside `testInstallSqlCreatesContextsTableWithExpectedColumns`):

```php
    public function testInstallSqlContextsTableHasScopeModeColumn(): void
    {
        $sql = file_get_contents(self::SQL_DIR . '/install.mysql.sql');

        self::assertMatchesRegularExpression(
            '/`scope_mode`\s+VARCHAR\(20\)\s+NOT NULL\s+DEFAULT \'all\'/',
            $sql,
            'Expected `scope_mode` column with default \'all\' in #__lcomment_contexts'
        );
    }

    public function testUpdateScriptAddsScopeModeColumn(): void
    {
        $sql = file_get_contents(self::SQL_DIR . '/updates/mysql/0.2.0.sql');

        self::assertStringContainsString('ALTER TABLE `#__lcomment_contexts`', $sql);
        self::assertStringContainsString('ADD COLUMN `scope_mode`', $sql);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Sql/SchemaTest.php`
Expected: FAIL — `testInstallSqlContextsTableHasScopeModeColumn` fails because the
column doesn't exist yet; `testUpdateScriptAddsScopeModeColumn` errors because
`sql/updates/mysql/0.2.0.sql` doesn't exist (`file_get_contents()` returns `false`).

- [ ] **Step 3: Add the column to the install script**

In `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`,
change the `#__lcomment_contexts` table definition from:

```sql
CREATE TABLE IF NOT EXISTS `#__lcomment_contexts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `extension` VARCHAR(100) NOT NULL,
    `view` VARCHAR(100) NOT NULL,
    `published` TINYINT NOT NULL DEFAULT 1,
    `moderation` TINYINT NOT NULL DEFAULT 1,
    `params` TEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_extension_view` (`extension`, `view`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

to:

```sql
CREATE TABLE IF NOT EXISTS `#__lcomment_contexts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `extension` VARCHAR(100) NOT NULL,
    `view` VARCHAR(100) NOT NULL,
    `published` TINYINT NOT NULL DEFAULT 1,
    `moderation` TINYINT NOT NULL DEFAULT 1,
    `scope_mode` VARCHAR(20) NOT NULL DEFAULT 'all',
    `params` TEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_extension_view` (`extension`, `view`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

(leave the `#__lcomment_comments` table definition in the same file untouched)

- [ ] **Step 4: Write the update script**

`com_lcomment/administrator/components/com_lcomment/sql/updates/mysql/0.2.0.sql`:

```sql
ALTER TABLE `#__lcomment_contexts`
    ADD COLUMN `scope_mode` VARCHAR(20) NOT NULL DEFAULT 'all' AFTER `moderation`;
```

- [ ] **Step 5: Wire the update schema path and bump the version**

In `com_lcomment/com_lcomment.xml`, change `<version>0.1.0</version>` to
`<version>0.2.0</version>`, and add an `<update>` block as a sibling of
`<install>`/`<uninstall>` (after `<uninstall>...</uninstall>`, before the
closing `</extension>`):

```xml
    <update>
        <schemas>
            <schemapath type="mysql">sql/updates/mysql</schemapath>
        </schemas>
    </update>
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Sql/SchemaTest.php`
Expected: OK (5 tests: the 3 existing ones plus the 2 new ones).

Run: `xmllint --noout com_lcomment/com_lcomment.xml`
Expected: no output, exit code 0.

- [ ] **Step 7: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/sql com_lcomment/com_lcomment.xml tests/Sql/SchemaTest.php
git commit -m "feat(lcomment): add scope_mode column and 0.2.0 update script"
```

---

### Task 2: `ScopeEvaluator` domain class (TDD)

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Service/ScopeEvaluator.php`
- Test: `tests/Service/ScopeEvaluatorTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `ScopeEvaluator::decodeRules(?string $paramsJson): array` — returns a list of `['type' => 'category'|'item', 'value' => int]`, or `[]` on missing/invalid JSON or a missing `scope_rules` key. Consumed by Task 7 (plugin) and Task 8 (site controller).
  - `ScopeEvaluator::isItemIncluded(string $scopeMode, array $rules, int $itemId, ?int $categoryId): bool`. Consumed by Task 3 (`SubmissionPolicy`), Task 7, Task 8.
  - `ScopeEvaluator::validateRulesForExtension(string $extension, array $rules): bool` — `false` means at least one `category`-type rule exists on a non-`com_content` extension (invalid). Consumed by Task 4 (`ContextTable`).

- [ ] **Step 1: Write the failing tests**

`tests/Service/ScopeEvaluatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;
use PHPUnit\Framework\TestCase;

final class ScopeEvaluatorTest extends TestCase
{
    // --- decodeRules() ---

    public function testDecodeRulesReturnsEmptyArrayForNull(): void
    {
        self::assertSame([], ScopeEvaluator::decodeRules(null));
    }

    public function testDecodeRulesReturnsEmptyArrayForEmptyString(): void
    {
        self::assertSame([], ScopeEvaluator::decodeRules(''));
    }

    public function testDecodeRulesReturnsEmptyArrayForInvalidJson(): void
    {
        self::assertSame([], ScopeEvaluator::decodeRules('{not valid json'));
    }

    public function testDecodeRulesReturnsEmptyArrayWhenScopeRulesKeyMissing(): void
    {
        self::assertSame([], ScopeEvaluator::decodeRules('{"other_key":1}'));
    }

    public function testDecodeRulesReturnsTheRuleList(): void
    {
        $json = '{"scope_rules":[{"type":"category","value":12},{"type":"item","value":345}]}';

        self::assertSame(
            [
                ['type' => 'category', 'value' => 12],
                ['type' => 'item', 'value' => 345],
            ],
            ScopeEvaluator::decodeRules($json)
        );
    }

    // --- isItemIncluded() ---

    public function testAllModeAlwaysIncludesRegardlessOfRules(): void
    {
        self::assertTrue(ScopeEvaluator::isItemIncluded('all', [], 1, null));
        self::assertTrue(ScopeEvaluator::isItemIncluded('all', [['type' => 'item', 'value' => 999]], 1, null));
    }

    public function testUnknownModeFailsOpenToIncludeEverything(): void
    {
        self::assertTrue(ScopeEvaluator::isItemIncluded('bogus-corrupted-mode', [], 1, null));
        self::assertTrue(ScopeEvaluator::isItemIncluded('bogus-corrupted-mode', [['type' => 'item', 'value' => 1]], 1, null));
    }

    public function testExcludeModeIncludesWhenNoRuleMatches(): void
    {
        $rules = [['type' => 'item', 'value' => 999]];

        self::assertTrue(ScopeEvaluator::isItemIncluded('exclude', $rules, 1, null));
    }

    public function testExcludeModeExcludesWhenItemRuleMatches(): void
    {
        $rules = [['type' => 'item', 'value' => 1]];

        self::assertFalse(ScopeEvaluator::isItemIncluded('exclude', $rules, 1, null));
    }

    public function testExcludeModeExcludesWhenCategoryRuleMatches(): void
    {
        $rules = [['type' => 'category', 'value' => 12]];

        self::assertFalse(ScopeEvaluator::isItemIncluded('exclude', $rules, 1, 12));
    }

    public function testCategoryRuleNeverMatchesWhenCategoryIdIsNull(): void
    {
        $rules = [['type' => 'category', 'value' => 12]];

        self::assertTrue(ScopeEvaluator::isItemIncluded('exclude', $rules, 1, null));
    }

    public function testIncludeModeExcludesEverythingWhenRuleListIsEmpty(): void
    {
        self::assertFalse(ScopeEvaluator::isItemIncluded('include', [], 1, null));
    }

    public function testIncludeModeIncludesOnlyMatchingItem(): void
    {
        $rules = [['type' => 'item', 'value' => 1]];

        self::assertTrue(ScopeEvaluator::isItemIncluded('include', $rules, 1, null));
        self::assertFalse(ScopeEvaluator::isItemIncluded('include', $rules, 2, null));
    }

    public function testIncludeModeIncludesOnlyMatchingCategory(): void
    {
        $rules = [['type' => 'category', 'value' => 12]];

        self::assertTrue(ScopeEvaluator::isItemIncluded('include', $rules, 1, 12));
        self::assertFalse(ScopeEvaluator::isItemIncluded('include', $rules, 1, 99));
    }

    // --- validateRulesForExtension() ---

    public function testValidatesTrueWhenNoCategoryRulesPresent(): void
    {
        $rules = [['type' => 'item', 'value' => 1]];

        self::assertTrue(ScopeEvaluator::validateRulesForExtension('com_k2', $rules));
    }

    public function testValidatesTrueForCategoryRuleOnComContent(): void
    {
        $rules = [['type' => 'category', 'value' => 12]];

        self::assertTrue(ScopeEvaluator::validateRulesForExtension('com_content', $rules));
    }

    public function testValidatesFalseForCategoryRuleOnOtherExtension(): void
    {
        $rules = [['type' => 'category', 'value' => 12]];

        self::assertFalse(ScopeEvaluator::validateRulesForExtension('com_k2', $rules));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Service/ScopeEvaluatorTest.php`
Expected: FAIL — class `ScopeEvaluator` not found.

- [ ] **Step 3: Implement**

`com_lcomment/administrator/components/com_lcomment/src/Service/ScopeEvaluator.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class ScopeEvaluator
{
    /**
     * @return array<int, array{type: string, value: int}>
     */
    public static function decodeRules(?string $paramsJson): array
    {
        if ($paramsJson === null || $paramsJson === '') {
            return [];
        }

        $decoded = json_decode($paramsJson, true);

        if (!\is_array($decoded) || !isset($decoded['scope_rules']) || !\is_array($decoded['scope_rules'])) {
            return [];
        }

        $rules = [];

        foreach ($decoded['scope_rules'] as $rule) {
            if (!\is_array($rule) || !isset($rule['type'], $rule['value'])) {
                continue;
            }

            $rules[] = ['type' => (string) $rule['type'], 'value' => (int) $rule['value']];
        }

        return $rules;
    }

    /**
     * @param array<int, array{type: string, value: int}> $rules
     */
    public static function isItemIncluded(string $scopeMode, array $rules, int $itemId, ?int $categoryId): bool
    {
        if ($scopeMode === 'exclude') {
            return !self::anyRuleMatches($rules, $itemId, $categoryId);
        }

        if ($scopeMode === 'include') {
            return self::anyRuleMatches($rules, $itemId, $categoryId);
        }

        // 'all', or any unrecognised/corrupted mode: fail open to Phase 1's
        // unrestricted behaviour rather than silently hiding comments.
        return true;
    }

    /**
     * @param array<int, array{type: string, value: int}> $rules
     */
    public static function validateRulesForExtension(string $extension, array $rules): bool
    {
        if ($extension === 'com_content') {
            return true;
        }

        foreach ($rules as $rule) {
            if ($rule['type'] === 'category') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array{type: string, value: int}> $rules
     */
    private static function anyRuleMatches(array $rules, int $itemId, ?int $categoryId): bool
    {
        foreach ($rules as $rule) {
            if ($rule['type'] === 'item' && $rule['value'] === $itemId) {
                return true;
            }

            if ($rule['type'] === 'category' && $categoryId !== null && $rule['value'] === $categoryId) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Service/ScopeEvaluatorTest.php`
Expected: OK (17 tests).

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/ScopeEvaluator.php tests/Service/ScopeEvaluatorTest.php
git commit -m "feat(lcomment): add ScopeEvaluator domain class"
```

---

### Task 3: `SubmissionPolicy` — item-excluded check (TDD)

**Files:**
- Modify: `com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionRequest.php`
- Modify: `com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionPolicy.php`
- Modify: `tests/Service/SubmissionPolicyTest.php`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`
- Modify: `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`

**Interfaces:**
- Consumes: nothing new from this plan (the caller computes `itemIncluded` using `ScopeEvaluator::isItemIncluded()` from Task 2 — `SubmissionPolicy` itself stays framework-free and just reads the boolean it's given).
- Produces: `SubmissionRequest` gains a new named constructor parameter `itemIncluded: bool` (default `true`, so every Phase 1 call site that doesn't know about scoping keeps working unchanged). Consumed by Task 8 (site controller).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Service/SubmissionPolicyTest.php`: first, update the `request()` helper method to accept the new field —

```php
    private function request(array $overrides = []): SubmissionRequest
    {
        return new SubmissionRequest(
            contextActive: $overrides['contextActive'] ?? true,
            contextModeration: $overrides['contextModeration'] ?? true,
            guestsAllowed: $overrides['guestsAllowed'] ?? true,
            userId: $overrides['userId'] ?? null,
            text: $overrides['text'] ?? 'A valid comment body.',
            minLength: $overrides['minLength'] ?? 3,
            maxLength: $overrides['maxLength'] ?? 1000,
            guestName: $overrides['guestName'] ?? 'A Guest',
            guestEmail: $overrides['guestEmail'] ?? 'guest@example.com',
            itemIncluded: $overrides['itemIncluded'] ?? true,
        );
    }
```

then add these test methods to the `SubmissionPolicyTest` class:

```php
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

    public function testItemExcludedTakesPriorityOverGuestChecks(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'itemIncluded' => false,
            'guestsAllowed' => false,
            'userId' => null,
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_ITEM_EXCLUDED'], $result->errors);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Service/SubmissionPolicyTest.php`
Expected: FAIL — `Unknown named parameter $itemIncluded` (the constructor doesn't accept it yet).

- [ ] **Step 3: Add the field to `SubmissionRequest`**

In `com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionRequest.php`, add
`itemIncluded` as the last constructor parameter with a default of `true`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class SubmissionRequest
{
    public function __construct(
        public readonly bool $contextActive,
        public readonly bool $contextModeration,
        public readonly bool $guestsAllowed,
        public readonly ?int $userId,
        public readonly string $text,
        public readonly int $minLength,
        public readonly int $maxLength,
        public readonly string $guestName = '',
        public readonly string $guestEmail = '',
        public readonly bool $itemIncluded = true,
    ) {
    }
}
```

- [ ] **Step 4: Add the check to `SubmissionPolicy`**

In `com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionPolicy.php`,
add the new check immediately after the `contextActive` check and before the
`guestsAllowed` check:

```php
        if (!$request->contextActive) {
            return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_CONTEXT_INACTIVE']);
        }

        if (!$request->itemIncluded) {
            return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_ITEM_EXCLUDED']);
        }

        if ($request->userId === null && !$request->guestsAllowed) {
```

(only those three lines change — everything else in the file stays as it is)

- [ ] **Step 5: Add the language keys**

Add this line to all four of
`com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`,
`com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`,
`com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`,
`com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`
(near the other `COM_LCOMMENT_ERROR_*` keys):

English (both en-GB files):
```ini
COM_LCOMMENT_ERROR_ITEM_EXCLUDED="Comments are not enabled on this item."
```

Portuguese (both pt-PT files):
```ini
COM_LCOMMENT_ERROR_ITEM_EXCLUDED="Os comentários não estão habilitados neste item."
```

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Service/SubmissionPolicyTest.php`
Expected: OK (21 tests — 17 from Phase 1 plus the 4 new ones).

- [ ] **Step 7: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: OK, all tests pass.

- [ ] **Step 8: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionRequest.php \
        com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionPolicy.php \
        tests/Service/SubmissionPolicyTest.php \
        com_lcomment/administrator/components/com_lcomment/language \
        com_lcomment/components/com_lcomment/language
git commit -m "feat(lcomment): add item-excluded check to SubmissionPolicy"
```

---

### Task 4: `ContextTable` — reject category rules on non-`com_content` contexts

**Files:**
- Modify: `com_lcomment/administrator/components/com_lcomment/src/Table/ContextTable.php`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`

**Interfaces:**
- Consumes: `ScopeEvaluator::decodeRules()`/`validateRulesForExtension()` (Task 2).
- Produces: nothing new for later tasks — this is the last line of defence validating whatever Task 5's form/model produced before it reaches the database.

`ContextTable` extends `Joomla\CMS\Table\Table`, which only runs inside a
live Joomla instance — no PHPUnit test here, same as every Table class in
this project; the logic it calls (`ScopeEvaluator::validateRulesForExtension`)
is already covered by Task 2's tests.

- [ ] **Step 1: Add a `check()` method**

`com_lcomment/administrator/components/com_lcomment/src/Table/ContextTable.php`
currently reads:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;

final class ContextTable extends Table
{
    public function __construct(DatabaseInterface $db)
    {
        parent::__construct('#__lcomment_contexts', 'id', $db);
    }
}
```

Replace it with:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;

final class ContextTable extends Table
{
    public function __construct(DatabaseInterface $db)
    {
        parent::__construct('#__lcomment_contexts', 'id', $db);
    }

    public function check(): bool
    {
        $rules = ScopeEvaluator::decodeRules((string) ($this->params ?? ''));

        if (!ScopeEvaluator::validateRulesForExtension((string) $this->extension, $rules)) {
            $this->setError('COM_LCOMMENT_ERROR_CATEGORY_RULE_REQUIRES_COM_CONTENT');

            return false;
        }

        return true;
    }
}
```

- [ ] **Step 2: Add the language key**

Add to `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:
```ini
COM_LCOMMENT_ERROR_CATEGORY_RULE_REQUIRES_COM_CONTENT="Category-based rules are only available when Extension is com_content."
```

Add to `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:
```ini
COM_LCOMMENT_ERROR_CATEGORY_RULE_REQUIRES_COM_CONTENT="Regras por categoria só estão disponíveis quando a Extensão é com_content."
```

- [ ] **Step 3: Lint**

Run: `php -l com_lcomment/administrator/components/com_lcomment/src/Table/ContextTable.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: OK, all tests still pass (this task adds no new PHPUnit tests).

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Table/ContextTable.php com_lcomment/administrator/components/com_lcomment/language
git commit -m "feat(lcomment): reject category scope rules on non-com_content contexts"
```

---

### Task 5: Admin Context form — `scope_mode` + `scope_rules`

**Files:**
- Modify: `com_lcomment/administrator/components/com_lcomment/forms/context.xml`
- Create: `com_lcomment/administrator/components/com_lcomment/forms/context_scope_rule.xml`
- Modify: `com_lcomment/administrator/components/com_lcomment/src/Model/ContextModel.php`
- Modify: `com_lcomment/administrator/components/com_lcomment/tmpl/context/edit.php`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`

**Interfaces:**
- Consumes: nothing new.
- Produces: the admin UI that writes `scope_mode` and JSON-encoded `scope_rules` into `#__lcomment_contexts`, which Task 7 and Task 8 read back via `CommentModel::getContext()` (already selects `*`, no change needed there).

No PHPUnit test — this is MVC/form framework glue with no path to a live
Joomla instance in this sandbox; verified by lint and the manual checklist
in Task 9.

- [ ] **Step 1: Add `scope_mode` and `scope_rules` to the Context form**

In `com_lcomment/administrator/components/com_lcomment/forms/context.xml`, add
two new fields inside the existing `<fieldset name="context">`, right after
the `moderation` field and before the `published` field:

```xml
        <field
            name="scope_mode"
            type="list"
            default="all"
            label="COM_LCOMMENT_CONTEXT_SCOPE_MODE_LABEL"
        >
            <option value="all">COM_LCOMMENT_CONTEXT_SCOPE_MODE_ALL</option>
            <option value="exclude">COM_LCOMMENT_CONTEXT_SCOPE_MODE_EXCLUDE</option>
            <option value="include">COM_LCOMMENT_CONTEXT_SCOPE_MODE_INCLUDE</option>
        </field>
        <field
            name="scope_rules"
            type="subform"
            multiple="true"
            buttons="add,remove,move"
            layout="joomla.form.field.subform.repeatable-table"
            formsource="administrator/components/com_lcomment/forms/context_scope_rule.xml"
            label="COM_LCOMMENT_CONTEXT_SCOPE_RULES_LABEL"
            description="COM_LCOMMENT_CONTEXT_SCOPE_RULES_DESC"
            showon="scope_mode:exclude,include"
        />
```

- [ ] **Step 2: Write the subform row definition**

`com_lcomment/administrator/components/com_lcomment/forms/context_scope_rule.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<form>
    <fieldset name="rule">
        <field
            name="rule_type"
            type="list"
            default="item"
            label="COM_LCOMMENT_SCOPE_RULE_TYPE_LABEL"
        >
            <option value="category">COM_LCOMMENT_SCOPE_RULE_TYPE_CATEGORY</option>
            <option value="item">COM_LCOMMENT_SCOPE_RULE_TYPE_ITEM</option>
        </field>
        <field
            name="rule_value"
            type="number"
            default="0"
            label="COM_LCOMMENT_SCOPE_RULE_VALUE_LABEL"
            description="COM_LCOMMENT_SCOPE_RULE_VALUE_DESC"
            required="true"
        />
    </fieldset>
</form>
```

- [ ] **Step 3: Wire encode/decode in `ContextModel`**

`com_lcomment/administrator/components/com_lcomment/src/Model/ContextModel.php`
currently reads:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\AdminModel;

final class ContextModel extends AdminModel
{
    public function getTable($type = 'Context', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_lcomment.context',
            'context',
            ['control' => 'jform', 'load_data' => $loadData]
        );

        return $form instanceof Form ? $form : null;
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_lcomment.edit.context.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }
}
```

Replace it with:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\AdminModel;
use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;

final class ContextModel extends AdminModel
{
    public function getTable($type = 'Context', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_lcomment.context',
            'context',
            ['control' => 'jform', 'load_data' => $loadData]
        );

        return $form instanceof Form ? $form : null;
    }

    public function save($data)
    {
        $rows = \is_array($data['scope_rules'] ?? null) ? $data['scope_rules'] : [];
        unset($data['scope_rules']);

        $rules = [];

        foreach ($rows as $row) {
            if (!\is_array($row) || !isset($row['rule_type'], $row['rule_value'])) {
                continue;
            }

            $rules[] = ['type' => (string) $row['rule_type'], 'value' => (int) $row['rule_value']];
        }

        $data['params'] = json_encode(['scope_rules' => $rules]);

        return parent::save($data);
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_lcomment.edit.context.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        if (\is_object($data)) {
            $rules = ScopeEvaluator::decodeRules((string) ($data->params ?? ''));
            $data->scope_rules = array_map(
                static fn (array $rule): array => ['rule_type' => $rule['type'], 'rule_value' => $rule['value']],
                $rules
            );
        }

        return $data;
    }
}
```

- [ ] **Step 4: Add an inline warning for empty "Apenas" rule lists**

In `com_lcomment/administrator/components/com_lcomment/tmpl/context/edit.php`,
the form currently loops `$this->form->getFieldset()` and renders each field
with `$field->renderField()`. Add a warning message right after the opening
`<form ...>` tag and before that loop:

```php
    <?php if ((string) ($this->item->scope_mode ?? 'all') === 'include' && empty($this->item->scope_rules)) : ?>
        <div class="alert alert-warning">
            <?php echo Text::_('COM_LCOMMENT_CONTEXT_SCOPE_INCLUDE_EMPTY_WARNING'); ?>
        </div>
    <?php endif; ?>
```

- [ ] **Step 5: Add the language keys**

Add to `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:
```ini
COM_LCOMMENT_CONTEXT_SCOPE_MODE_LABEL="Scope"
COM_LCOMMENT_CONTEXT_SCOPE_MODE_ALL="All items"
COM_LCOMMENT_CONTEXT_SCOPE_MODE_EXCLUDE="All items except..."
COM_LCOMMENT_CONTEXT_SCOPE_MODE_INCLUDE="Only these items..."
COM_LCOMMENT_CONTEXT_SCOPE_RULES_LABEL="Scope rules"
COM_LCOMMENT_CONTEXT_SCOPE_RULES_DESC="Each rule is a category (com_content only) or a specific item id. An item matches the scope if it matches any rule."
COM_LCOMMENT_SCOPE_RULE_TYPE_LABEL="Rule type"
COM_LCOMMENT_SCOPE_RULE_TYPE_CATEGORY="Category (com_content only)"
COM_LCOMMENT_SCOPE_RULE_TYPE_ITEM="Specific item"
COM_LCOMMENT_SCOPE_RULE_VALUE_LABEL="Value"
COM_LCOMMENT_SCOPE_RULE_VALUE_DESC="The category id, or the item id, depending on the rule type above."
COM_LCOMMENT_CONTEXT_SCOPE_INCLUDE_EMPTY_WARNING="Scope is set to \"Only these items\" but no rule has been added yet — comments are disabled everywhere for this context until you add at least one rule."
```

Add to `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:
```ini
COM_LCOMMENT_CONTEXT_SCOPE_MODE_LABEL="Escopo"
COM_LCOMMENT_CONTEXT_SCOPE_MODE_ALL="Todos os itens"
COM_LCOMMENT_CONTEXT_SCOPE_MODE_EXCLUDE="Todos os itens, exceto..."
COM_LCOMMENT_CONTEXT_SCOPE_MODE_INCLUDE="Apenas estes itens..."
COM_LCOMMENT_CONTEXT_SCOPE_RULES_LABEL="Regras de escopo"
COM_LCOMMENT_CONTEXT_SCOPE_RULES_DESC="Cada regra é uma categoria (só com_content) ou um id de item específico. Um item casa com o escopo se casar com qualquer regra."
COM_LCOMMENT_SCOPE_RULE_TYPE_LABEL="Tipo de regra"
COM_LCOMMENT_SCOPE_RULE_TYPE_CATEGORY="Categoria (só com_content)"
COM_LCOMMENT_SCOPE_RULE_TYPE_ITEM="Item específico"
COM_LCOMMENT_SCOPE_RULE_VALUE_LABEL="Valor"
COM_LCOMMENT_SCOPE_RULE_VALUE_DESC="O id da categoria, ou o id do item, dependendo do tipo de regra acima."
COM_LCOMMENT_CONTEXT_SCOPE_INCLUDE_EMPTY_WARNING="O escopo está definido como \"Apenas estes itens\", mas nenhuma regra foi adicionada ainda — os comentários ficam desativados em todo o contexto até que pelo menos uma regra seja adicionada."
```

- [ ] **Step 6: Lint**

Run:
```bash
php -l com_lcomment/administrator/components/com_lcomment/src/Model/ContextModel.php
php -l com_lcomment/administrator/components/com_lcomment/tmpl/context/edit.php
xmllint --noout com_lcomment/administrator/components/com_lcomment/forms/context.xml
xmllint --noout com_lcomment/administrator/components/com_lcomment/forms/context_scope_rule.xml
```
Expected: no syntax errors, no xmllint output.

- [ ] **Step 7: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: OK, all tests still pass (no PHPUnit tests added in this task).

- [ ] **Step 8: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/forms \
        com_lcomment/administrator/components/com_lcomment/src/Model/ContextModel.php \
        com_lcomment/administrator/components/com_lcomment/tmpl/context/edit.php \
        com_lcomment/administrator/components/com_lcomment/language
git commit -m "feat(lcomment): add scope_mode/scope_rules fields to the admin Context form"
```

---

### Task 6: Site `CommentModel::getCategoryId()`

**Files:**
- Modify: `com_lcomment/components/com_lcomment/src/Model/CommentModel.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `CommentModel::getCategoryId(string $extension, int $itemId): ?int`, returning the `com_content` article's `catid`, or `null` for any other extension (or if the item doesn't exist). Consumed by Task 8 (site controller — the plugin gets the category a different way, directly from the already-loaded `$item` object, see Task 7).

No PHPUnit test — `BaseDatabaseModel` subclasses need a live database
connection; verified by lint and the manual checklist in Task 9.

- [ ] **Step 1: Add the method**

In `com_lcomment/components/com_lcomment/src/Model/CommentModel.php`, add this
public method to the `CommentModel` class (after `getItemsFor()`, before the
closing `}` of the class):

```php
    public function getCategoryId(string $extension, int $itemId): ?int
    {
        if ($extension !== 'com_content') {
            return null;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('catid'))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = :itemId')
            ->bind(':itemId', $itemId, \Joomla\Database\ParameterType::INTEGER);

        $db->setQuery($query);

        $catid = $db->loadResult();

        return $catid !== null ? (int) $catid : null;
    }
```

- [ ] **Step 2: Lint**

Run: `php -l com_lcomment/components/com_lcomment/src/Model/CommentModel.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: OK, all tests still pass.

- [ ] **Step 4: Commit**

```bash
git add com_lcomment/components/com_lcomment/src/Model/CommentModel.php
git commit -m "feat(lcomment): add CommentModel::getCategoryId for com_content"
```

---

### Task 7: Wire scope checking into `plg_content_lcomment`

**Files:**
- Modify: `plg_content_lcomment/src/Extension/Lcomment.php`

**Interfaces:**
- Consumes: `ScopeEvaluator::decodeRules()`/`isItemIncluded()` (Task 2).
- Produces: nothing new for later tasks.

No PHPUnit test — this is the plugin's event handler, needs a live Joomla
dispatch; verified by lint and the manual checklist in Task 9.

- [ ] **Step 1: Add the scope check**

In `plg_content_lcomment/src/Extension/Lcomment.php`, add the import:

```php
use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;
```

(alongside the existing `use Lcsilva\Component\Lcomment\Administrator\Service\ContextResolver;`)

Then, right after the existing block:

```php
        $commentContext = $model->getContext($resolved['extension'], $resolved['view']);

        if ($commentContext === null) {
            return;
        }
```

add:

```php
        $categoryId = $resolved['extension'] === 'com_content' ? ($item->catid ?? null) : null;
        $categoryId = $categoryId !== null ? (int) $categoryId : null;

        $rules = ScopeEvaluator::decodeRules((string) ($commentContext->params ?? ''));
        $scopeMode = (string) ($commentContext->scope_mode ?? 'all');

        if (!ScopeEvaluator::isItemIncluded($scopeMode, $rules, (int) $item->id, $categoryId)) {
            return;
        }
```

(this goes before the existing `$extension = $resolved['extension'];` line —
the full method body keeps everything else unchanged)

- [ ] **Step 2: Lint**

Run: `php -l plg_content_lcomment/src/Extension/Lcomment.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: OK, all tests still pass.

- [ ] **Step 4: Commit**

```bash
git add plg_content_lcomment/src/Extension/Lcomment.php
git commit -m "feat(lcomment): enforce scope rules in plg_content_lcomment render"
```

---

### Task 8: Wire scope checking into `CommentController::save()`

**Files:**
- Modify: `com_lcomment/components/com_lcomment/src/Controller/CommentController.php`

**Interfaces:**
- Consumes: `ScopeEvaluator::decodeRules()`/`isItemIncluded()` (Task 2),
  `CommentModel::getCategoryId()` (Task 6), `SubmissionRequest`'s
  `itemIncluded` parameter (Task 3).
- Produces: nothing new for later tasks — this is the end of the chain for
  this sub-delivery.

No PHPUnit test — framework controller, needs a live Joomla dispatch;
verified by lint and the manual checklist in Task 9 (this is also where
Review Focus item 4, the forged-request check, gets its real coverage).

- [ ] **Step 1: Add the scope check**

In `com_lcomment/components/com_lcomment/src/Controller/CommentController.php`,
add the import:

```php
use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;
```

(alongside the existing `use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionPolicy;` import)

Then, right after the existing block:

```php
        /** @var \Lcsilva\Component\Lcomment\Site\Model\CommentModel $model */
        $model = $this->getModel('Comment', 'Site');
        $context = $model->getContext($extension, $view);
```

add:

```php
        $categoryId = $model->getCategoryId($extension, $itemId);
        $rules = $context !== null ? ScopeEvaluator::decodeRules((string) ($context->params ?? '')) : [];
        $scopeMode = $context !== null ? (string) ($context->scope_mode ?? 'all') : 'all';
        $itemIncluded = ScopeEvaluator::isItemIncluded($scopeMode, $rules, $itemId, $categoryId);
```

Then update the `SubmissionPolicy::evaluate(new SubmissionRequest(...))` call
to pass the new field — change:

```php
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
        ));
```

to:

```php
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
        ));
```

- [ ] **Step 2: Lint**

Run: `php -l com_lcomment/components/com_lcomment/src/Controller/CommentController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: OK, all tests still pass.

- [ ] **Step 4: Commit**

```bash
git add com_lcomment/components/com_lcomment/src/Controller/CommentController.php
git commit -m "feat(lcomment): enforce scope rules server-side in CommentController::save"
```

---

### Task 9: Rebuild and manual acceptance checklist

**Files:**
- None (uses `build.sh` from Phase 1, unchanged).

**Interfaces:**
- Consumes: every file touched in Tasks 1-8.
- Produces: an updated `dist/pkg_lcomment.zip` (and the individual
  `dist/com_lcomment.zip`/`dist/plg_content_lcomment.zip`/
  `dist/plg_system_lcomment.zip`) ready to test against the live Joomla
  6.1.4 site this project has been validated against.

- [ ] **Step 1: Run the full automated test suite one more time**

Run: `vendor/bin/phpunit`
Expected: OK, every test from Tasks 1-8 passes (36 tests: the 32 from
Phase 1 plus 4 new `SchemaTest`/`ScopeEvaluatorTest`/`SubmissionPolicyTest`
groups — see each task's own expected count).

- [ ] **Step 2: Rebuild the package**

Run: `./build.sh`
Expected: `Built dist/pkg_lcomment.zip` printed, and
`unzip -l dist/pkg_lcomment.zip` shows the `constituents/` folder with all
three sub-extension zips (same structure Phase 1 ended on).

- [ ] **Step 3: Manual acceptance checklist (requires the real Joomla 6.1.4 site)**

This cannot be automated in this repository. Reinstall `dist/com_lcomment.zip`
(the scope logic lives in the component and the already-installed plugins —
reinstalling just the component is enough unless Task 7's plugin file
changed, in which case reinstall `dist/plg_content_lcomment.zip` too) and
walk through the spec's own acceptance list:

1. Edit the `com_content`/`article` Context, set Scope to "Todos os itens,
   exceto...", add a rule of type Categoria pointing at a category that has
   at least one article. Save.
   Expect: saves without error.
2. Open an article in that category on the frontend.
   Expect: the comment block does NOT appear.
3. Open an article in a different category.
   Expect: the comment block appears normally.
4. Create a brand new article in the excluded category.
   Expect: the comment block does not appear on it either, without
   touching the Context again (confirms the category rule is dynamic).
5. Forge a POST to `index.php?option=com_lcomment&task=comment.save` with
   `item_id` set to an article from the excluded category (e.g. via
   browser dev tools replaying the form with a different hidden `item_id`
   value).
   Expect: rejected with "Comments are not enabled on this item." /
   "Os comentários não estão habilitados neste item.", even though the
   block was never rendered for that item.
6. Switch Scope to "Apenas estes itens...", remove all rules, save.
   Expect: the inline warning about the empty rule list appears on the
   edit screen; no article in `com_content`/`article` shows the comment
   block.
7. Add one rule of type "Item específico" naming a specific article's id.
   Expect: only that one article shows the comment block.
8. Edit the Context again, change Extension away from `com_content` (e.g.
   to `com_contact`) while a Categoria-type rule still exists in the
   rules list, and try to save.
   Expect: rejected with the category/com_content validation error from
   Task 4, not silently accepted.

- [ ] **Step 4: Commit (only if Step 3 required any follow-up fixes — otherwise nothing to commit)**

If the manual checklist passes with no code changes, there is nothing to
commit for this task. If it surfaces a bug, follow
`superpowers:systematic-debugging`, fix it, and commit the fix with a
message describing what the live test found — the same pattern used
throughout Phase 1's live-validation cycle.

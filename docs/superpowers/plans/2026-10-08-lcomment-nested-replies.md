# LComment — Fase 2b: Respostas Aninhadas Reais — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `parent_id` (already stored, always `0` today) actually drive a real reply tree — submission, server-side validation, and nested display — instead of a flat list.

**Architecture:** A new pure-PHP `CommentTreeBuilder` domain class turns the already-visibility-filtered flat comment list into a tree, excluding any comment whose ancestor chain is broken (cascading hide, never promoted to root). `SubmissionPolicy` gains a `parentValid` check with the same priority as the Fase 2a `itemIncluded` check. `CommentController::save()` validates a POSTed `parent_id` against the database (must exist, not be trashed, belong to the same extension/view/item). The site layout renders the tree recursively via two closures (anonymous, not named functions — the layout file can be `include`d more than once per request when a page shows multiple items, so a top-level named `function` would fatal on the second render) and adds a `<details>/<summary>` reply form per comment, with visual indent capped at depth 5 via a CSS class while the real DOM nesting (and ordering) stays uncapped.

**Tech Stack:** PHP 8.1+, Joomla 6.1.4 MVC (component `com_lcomment`, plugin `plg_content_lcomment`), PHPUnit 10.5 for the pure-PHP domain classes, no new JavaScript.

**Spec:** `docs/superpowers/specs/2026-10-08-lcomment-nested-replies-design.md`

## Global Constraints

- Target: Joomla 6.1.4 real environment, PHP 8.1+. Verify any Joomla framework
  class/method against real `joomla-cms` GitHub source before depending on it
  (see the `feedback_joomla_api_verification` lesson — this has already bitten
  this project several times).
- No database schema change. `parent_id` already exists
  (`INT UNSIGNED NOT NULL DEFAULT 0`).
- Nesting depth is unlimited in the data/ordering. Visual indentation is
  capped at 5 levels (deeper replies reuse the depth-5 CSS class).
- A hidden/trashed/cross-item ancestor hides the *entire* reply subtree,
  cascading through the whole ancestor chain — never promoted to top-level.
- Reply UI uses `<details>/<summary>`, no new JavaScript.
- `CommentTreeBuilder` is pure PHP (no `_JEXEC` guard, no Joomla dependency),
  testable via PHPUnit, same pattern as `ScopeEvaluator`/`SubmissionPolicy`.
- `CommentModel::parentBelongsToItem()` and the `CommentController::save()`
  wiring are Joomla framework code with no automated test harness available
  in this project (same precedent as `CommentModel::getCategoryId()` in Fase
  2a) — verified by manual acceptance criteria instead.

## Review Focus

- A reply-of-a-reply whose direct parent is visible but whose grandparent is
  hidden/trashed/cross-item must *also* be hidden — not just the immediate
  child. Pinned by a 3-level `CommentTreeBuilderTest` case (Task 1).
- A forged `parent_id` POST value pointing at a comment from a *different*
  article/extension/view must be rejected server-side, even though the
  rendered UI never offers that option. Pinned by a `SubmissionPolicyTest`
  case (Task 2) plus the spec's manual acceptance criterion 5.
- A reply targeting a parent that is itself still pending moderation (same
  item) must be **accepted**, not rejected as an invalid parent — only a
  trashed or cross-item parent is rejected. Pinned by the exact SQL in
  `parentBelongsToItem()` (Task 3) plus the spec's manual acceptance
  criterion 3; called out explicitly so a future edit doesn't "fix" it into
  requiring the parent to be published.
- Malformed or circular `parent_id` data must never cause infinite recursion
  or a stack overflow while building the tree. Pinned by a cycle-guard
  `CommentTreeBuilderTest` case (Task 1).
- A `parent_id` that is missing, blank, or non-numeric in the POST must
  default to top-level (`0`) and never block the submission. Guaranteed by
  Joomla's `$input->getInt('parent_id', 0)` filter; called out explicitly in
  Task 3 so nobody later turns a missing `parent_id` into a hard error.

---

### Task 1: `CommentTreeBuilder` domain class

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Service/CommentTreeBuilder.php`
- Test: `tests/Service/CommentTreeBuilderTest.php`

**Interfaces:**
- Consumes: nothing new (pure PHP, no dependency on other LComment classes).
- Produces: `Lcsilva\Component\Lcomment\Administrator\Service\CommentTreeBuilder::build(array $flatComments): array`, returning a list of root nodes, each `['comment' => <item from $flatComments>, 'replies' => <same shape, recursively>]`. Consumed by Task 4's layout. Accepts items that expose `->id`/`->parent_id` as `stdClass` (as `BaseDatabaseModel::loadObjectList()` returns them) — not arrays, since that's the only shape the real caller ever produces.

- [ ] **Step 1: Write the failing tests**

Create `tests/Service/CommentTreeBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\CommentTreeBuilder;
use PHPUnit\Framework\TestCase;

final class CommentTreeBuilderTest extends TestCase
{
    private function comment(int $id, int $parentId): object
    {
        return (object) ['id' => $id, 'parent_id' => $parentId];
    }

    public function testEmptyListReturnsEmptyTree(): void
    {
        self::assertSame([], CommentTreeBuilder::build([]));
    }

    public function testFlatCommentsWithNoParentsAreAllRootsInOriginalOrder(): void
    {
        $comments = [
            $this->comment(1, 0),
            $this->comment(2, 0),
            $this->comment(3, 0),
        ];

        $tree = CommentTreeBuilder::build($comments);

        self::assertSame([1, 2, 3], array_map(fn (array $node) => $node['comment']->id, $tree));
        self::assertSame([], $tree[0]['replies']);
    }

    public function testDirectReplyNestsUnderItsParent(): void
    {
        $comments = [
            $this->comment(1, 0),
            $this->comment(2, 1),
        ];

        $tree = CommentTreeBuilder::build($comments);

        self::assertCount(1, $tree);
        self::assertSame(1, $tree[0]['comment']->id);
        self::assertCount(1, $tree[0]['replies']);
        self::assertSame(2, $tree[0]['replies'][0]['comment']->id);
    }

    public function testReplyOfAReplyNestsAtTheCorrectThirdLevel(): void
    {
        $comments = [
            $this->comment(1, 0),
            $this->comment(2, 1),
            $this->comment(3, 2),
        ];

        $tree = CommentTreeBuilder::build($comments);

        self::assertSame(3, $tree[0]['replies'][0]['replies'][0]['comment']->id);
    }

    public function testReplyIsExcludedWhenItsDirectParentIsMissingFromTheSet(): void
    {
        // Parent 1 was filtered out before reaching the builder (e.g. trashed,
        // or belongs to another item) — reply 2 must not appear at all, and
        // must never be promoted to a root.
        $comments = [
            $this->comment(2, 1),
        ];

        self::assertSame([], CommentTreeBuilder::build($comments));
    }

    public function testCascadingHideExcludesAnEntireBrokenAncestorChain(): void
    {
        // 1 is present but its own parent (99) is not in the set, so 1 is
        // invalid. 2 replies to 1, 3 replies to 2. All three must vanish,
        // not just 1 — the direct parent (2) being present is not enough.
        $comments = [
            $this->comment(1, 99),
            $this->comment(2, 1),
            $this->comment(3, 2),
        ];

        self::assertSame([], CommentTreeBuilder::build($comments));
    }

    public function testReplyToANonexistentIdIsExcludedWithoutCrashing(): void
    {
        $comments = [
            $this->comment(1, 0),
            $this->comment(2, 54321),
        ];

        $tree = CommentTreeBuilder::build($comments);

        self::assertCount(1, $tree);
        self::assertSame([], $tree[0]['replies']);
    }

    public function testCircularParentReferenceDoesNotCauseInfiniteRecursion(): void
    {
        // Pathological/corrupted data only — parent_id is never edited after
        // creation in normal use, so this cannot occur naturally. Must still
        // terminate and simply exclude both.
        $comments = [
            $this->comment(1, 2),
            $this->comment(2, 1),
        ];

        self::assertSame([], CommentTreeBuilder::build($comments));
    }

    public function testSiblingRepliesUnderTheSameParentPreserveChronologicalOrder(): void
    {
        $comments = [
            $this->comment(1, 0),
            $this->comment(2, 1),
            $this->comment(3, 1),
        ];

        $tree = CommentTreeBuilder::build($comments);

        self::assertSame([2, 3], array_map(fn (array $node) => $node['comment']->id, $tree[0]['replies']));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Service/CommentTreeBuilderTest.php`
Expected: FAIL — `Class "Lcsilva\Component\Lcomment\Administrator\Service\CommentTreeBuilder" not found`.

- [ ] **Step 3: Implement `CommentTreeBuilder`**

Create `com_lcomment/administrator/components/com_lcomment/src/Service/CommentTreeBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class CommentTreeBuilder
{
    /**
     * @param array<int, object> $flatComments Already filtered by visibility
     *   (e.g. CommentModel::getItemsFor()'s state rule), ordered oldest-first.
     *   This method only decides *structural* visibility: whether a
     *   comment's whole ancestor chain is present in the given set.
     *
     * @return array<int, array{comment: object, replies: array}>
     */
    public static function build(array $flatComments): array
    {
        $byId = [];

        foreach ($flatComments as $comment) {
            $byId[(int) $comment->id] = $comment;
        }

        $nodes = [];

        foreach ($byId as $id => $comment) {
            $nodes[$id] = (object) ['comment' => $comment, 'replies' => []];
        }

        $validity = [];
        $visiting = [];

        // Memoized so each id's validity is computed once; $visiting guards
        // against a circular parent_id chain looping forever (shouldn't
        // happen in normal use since parent_id is immutable after creation,
        // but corrupted data must never hang or overflow the call stack).
        $isValid = function (int $id) use (&$isValid, &$validity, &$visiting, $byId): bool {
            if (isset($validity[$id])) {
                return $validity[$id];
            }

            if (isset($visiting[$id]) || !isset($byId[$id])) {
                return $validity[$id] = false;
            }

            $parentId = (int) $byId[$id]->parent_id;

            if ($parentId === 0) {
                return $validity[$id] = true;
            }

            $visiting[$id] = true;
            $result = isset($byId[$parentId]) && $isValid($parentId);
            unset($visiting[$id]);

            return $validity[$id] = $result;
        };

        $roots = [];

        foreach ($nodes as $id => $node) {
            if (!$isValid($id)) {
                continue;
            }

            $parentId = (int) $node->comment->parent_id;

            if ($parentId === 0) {
                $roots[] = $node;
            } elseif (isset($nodes[$parentId])) {
                $nodes[$parentId]->replies[] = $node;
            }
        }

        return self::toArrayShape($roots);
    }

    /**
     * @param array<int, object> $nodes
     * @return array<int, array{comment: object, replies: array}>
     */
    private static function toArrayShape(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            $result[] = [
                'comment' => $node->comment,
                'replies' => self::toArrayShape($node->replies),
            ];
        }

        return $result;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Service/CommentTreeBuilderTest.php`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/CommentTreeBuilder.php tests/Service/CommentTreeBuilderTest.php
git commit -m "feat(lcomment): add CommentTreeBuilder for nested reply display"
```

---

### Task 2: `parentValid` check in `SubmissionPolicy`

**Files:**
- Modify: `com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionRequest.php`
- Modify: `com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionPolicy.php`
- Modify: `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`
- Test: `tests/Service/SubmissionPolicyTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `SubmissionRequest` gains `public readonly bool $parentValid = true` (named constructor arg, default `true` so every other existing call site keeps compiling unchanged). `SubmissionPolicy::evaluate()` returns `SubmissionResult(false, ['COM_LCOMMENT_ERROR_INVALID_PARENT'])` when `$request->parentValid === false`, checked immediately after the `itemIncluded` check and before the guest checks. Consumed by Task 3's `CommentController::save()`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Service/SubmissionPolicyTest.php` (inside the `request()` helper, add `parentValid` to the list of overridable fields, and add these test methods):

```php
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

    public function testInvalidParentTakesPriorityOverGuestChecks(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'parentValid' => false,
            'guestsAllowed' => false,
            'userId' => null,
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_PARENT'], $result->errors);
    }
```

And update the `request()` helper's constructor call to also pass `parentValid: $overrides['parentValid'] ?? true,`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Service/SubmissionPolicyTest.php`
Expected: FAIL — `Unknown named parameter $parentValid` (constructor doesn't accept it yet).

- [ ] **Step 3: Add `parentValid` to `SubmissionRequest`**

In `com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionRequest.php`, change:

```php
        public readonly bool $itemIncluded = true,
    ) {
```

to:

```php
        public readonly bool $itemIncluded = true,
        public readonly bool $parentValid = true,
    ) {
```

- [ ] **Step 4: Add the check to `SubmissionPolicy::evaluate()`**

In `com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionPolicy.php`, change:

```php
        if (!$request->itemIncluded) {
            return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_ITEM_EXCLUDED']);
        }

        if ($request->userId === null && !$request->guestsAllowed) {
```

to:

```php
        if (!$request->itemIncluded) {
            return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_ITEM_EXCLUDED']);
        }

        if (!$request->parentValid) {
            return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_INVALID_PARENT']);
        }

        if ($request->userId === null && !$request->guestsAllowed) {
```

- [ ] **Step 5: Add the new language key**

Append to `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_ERROR_INVALID_PARENT="You cannot reply to that comment."
```

Append to `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_ERROR_INVALID_PARENT="Não é possível responder a esse comentário."
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Service/SubmissionPolicyTest.php`
Expected: PASS (all tests, including the 4 new ones).

- [ ] **Step 7: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionRequest.php com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionPolicy.php com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini tests/Service/SubmissionPolicyTest.php
git commit -m "feat(lcomment): add parentValid check to SubmissionPolicy"
```

---

### Task 3: Validate and persist `parent_id` in `CommentController::save()`

**Files:**
- Modify: `com_lcomment/components/com_lcomment/src/Model/CommentModel.php`
- Modify: `com_lcomment/components/com_lcomment/src/Controller/CommentController.php`

**Interfaces:**
- Consumes: `SubmissionRequest`'s new `parentValid` field and `SubmissionPolicy`'s new error code from Task 2.
- Produces: `CommentModel::parentBelongsToItem(int $parentId, string $extension, string $view, int $itemId): bool`. No PHPUnit coverage possible (same precedent as `getCategoryId()` — real `BaseDatabaseModel`/query builder, no DB in the test harness); verified via the spec's manual acceptance criteria 3 and 5 instead.

- [ ] **Step 1: Add `CommentModel::parentBelongsToItem()`**

In `com_lcomment/components/com_lcomment/src/Model/CommentModel.php`, add this method after `getCategoryId()` (before the class's closing `}`):

```php
    public function parentBelongsToItem(int $parentId, string $extension, string $view, int $itemId): bool
    {
        $db = $this->getDatabase();

        // Only excludes trashed (-2). A pending (state = 0) parent on the
        // same item is a valid reply target — moderation state is not a
        // reason to reject the reply, only trash/cross-item is. See the
        // spec's "pending parent" acceptance criterion.
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__lcomment_comments'))
            ->where($db->quoteName('id') . ' = :parentId')
            ->where($db->quoteName('extension') . ' = :extension')
            ->where($db->quoteName('view') . ' = :view')
            ->where($db->quoteName('item_id') . ' = :itemId')
            ->where($db->quoteName('state') . ' != -2')
            ->bind(':parentId', $parentId, ParameterType::INTEGER)
            ->bind(':extension', $extension, ParameterType::STRING)
            ->bind(':view', $view, ParameterType::STRING)
            ->bind(':itemId', $itemId, ParameterType::INTEGER);

        $db->setQuery($query);

        return (int) $db->loadResult() > 0;
    }
```

- [ ] **Step 2: Read and validate `parent_id` in `CommentController::save()`**

In `com_lcomment/components/com_lcomment/src/Controller/CommentController.php`, change the input-reading block:

```php
        $extension = $input->getCmd('extension', '');
        $view = $input->getCmd('view', '');
        $itemId = $input->getInt('item_id', 0);
        $text = (string) $input->get('comment_text', '', 'RAW');
```

to:

```php
        $extension = $input->getCmd('extension', '');
        $view = $input->getCmd('view', '');
        $itemId = $input->getInt('item_id', 0);
        // getInt() already returns 0 for a missing/blank/non-numeric value —
        // an absent parent_id must default to a top-level comment, never
        // block the submission.
        $parentId = $input->getInt('parent_id', 0);
        $text = (string) $input->get('comment_text', '', 'RAW');
```

Then change:

```php
        $categoryId = $model->getCategoryId($extension, $itemId);
        $rules = $context !== null ? ScopeEvaluator::decodeRules((string) ($context->params ?? '')) : [];
        $scopeMode = $context !== null ? (string) ($context->scope_mode ?? 'all') : 'all';
        $itemIncluded = ScopeEvaluator::isItemIncluded($scopeMode, $rules, $itemId, $categoryId);
```

to:

```php
        $categoryId = $model->getCategoryId($extension, $itemId);
        $rules = $context !== null ? ScopeEvaluator::decodeRules((string) ($context->params ?? '')) : [];
        $scopeMode = $context !== null ? (string) ($context->scope_mode ?? 'all') : 'all';
        $itemIncluded = ScopeEvaluator::isItemIncluded($scopeMode, $rules, $itemId, $categoryId);
        $parentValid = $parentId === 0 || $model->parentBelongsToItem($parentId, $extension, $view, $itemId);
```

Then change the `SubmissionRequest` construction:

```php
            itemIncluded: $itemIncluded,
        ));
```

to:

```php
            itemIncluded: $itemIncluded,
            parentValid: $parentValid,
        ));
```

- [ ] **Step 3: Preserve `parent_id` in the rejected-submission state, and persist it on success**

Both occurrences of:

```php
            $app->setUserState($stateKey, [
                'text' => $text,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
            ]);
```

become:

```php
            $app->setUserState($stateKey, [
                'text' => $text,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
                'parent_id' => $parentId,
            ]);
```

And add `$table->parent_id = $parentId;` to the `CommentTable` assignment block:

```php
        $table->extension = $extension;
        $table->view = $view;
        $table->item_id = $itemId;
        $table->comment_text = $policyResult->normalizedText;
```

becomes:

```php
        $table->extension = $extension;
        $table->view = $view;
        $table->item_id = $itemId;
        $table->parent_id = $parentId;
        $table->comment_text = $policyResult->normalizedText;
```

- [ ] **Step 4: Run the full test suite to confirm nothing else broke**

Run: `vendor/bin/phpunit`
Expected: PASS — this task has no new automated tests of its own (framework code, see Global Constraints), but it must not break Task 1/2's suites.

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/components/com_lcomment/src/Model/CommentModel.php com_lcomment/components/com_lcomment/src/Controller/CommentController.php
git commit -m "feat(lcomment): validate and persist parent_id in CommentController::save"
```

---

### Task 4: Recursive reply tree in the site layout

**Files:**
- Modify: `com_lcomment/components/com_lcomment/layouts/comment.php`
- Modify: `com_lcomment/media/css/lcomment.css`
- Modify: `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Modify: `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`

**Interfaces:**
- Consumes: `CommentTreeBuilder::build(array $flatComments): array` from Task 1 (node shape `['comment' => object, 'replies' => array]`); the `parent_id` POST field name from Task 3.
- Produces: no new interface — this is the leaf of the chain (view layer). Verified by the spec's manual acceptance criteria 1, 2, 4, 6 (and 3/5 together with Task 3).

- [ ] **Step 1: Add the new language key**

Append to `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_REPLY_LABEL="Reply"
```

Append to `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_REPLY_LABEL="Responder"
```

- [ ] **Step 2: Rewrite the layout to render the tree recursively**

Replace the full contents of `com_lcomment/components/com_lcomment/layouts/comment.php` with:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Lcsilva\Component\Lcomment\Administrator\Service\CommentTreeBuilder;

/**
 * Expected keys in $displayData (array or object):
 * @var string $extension
 * @var string $view
 * @var int    $itemId
 * @var array  $items
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

$renderNode = function (array $node, int $depth) use (&$renderNode, $renderForm, $prefillParentId, $prefillText): void {
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

- [ ] **Step 3: Add the depth-indent and reply-disclosure CSS**

Append to `com_lcomment/media/css/lcomment.css`:

```css
.lcomment-reply {
    margin-top: 0.5rem;
}

.lcomment-reply > summary {
    cursor: pointer;
}

/*
 * Each reply level is a `.lcomment-list` nested inside its parent
 * `.lcomment-item`, so a fixed margin-left here compounds naturally with
 * the DOM nesting (depth 2 = 1.5rem, depth 3 = 3rem, ...). To cap the
 * *visual* indent at depth 5 without capping the real nesting, zero out
 * the margin on the child list of any item carrying `.lcomment-depth-5`
 * (every item at structural depth >= 5, since the PHP side clamps the
 * class with `min($depth, 5)`) — that stops indentation from growing
 * any further for every deeper level too.
 */
.lcomment-item > .lcomment-list {
    margin-left: 1.5rem;
}

.lcomment-depth-5 > .lcomment-list {
    margin-left: 0;
}
```

> **Note (post-final-review correction):** an earlier version of this step
> gave each `.lcomment-depth-N` class its own *absolute* `margin-left`
> (0/1.5/3/4.5/6rem). That compounds with the DOM nesting instead of
> replacing it — indentation kept growing past depth 5 instead of
> capping — and failed the spec's acceptance criterion 6. The CSS above
> is the corrected version; do not reintroduce per-depth absolute
> margins here.

- [ ] **Step 4: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS — this task adds no PHPUnit tests (view layer), but must not break Tasks 1–3's suites.

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/components/com_lcomment/layouts/comment.php com_lcomment/media/css/lcomment.css com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini
git commit -m "feat(lcomment): render comments as a nested reply tree"
```

---

## Manual verification (real Joomla 6.1.4 site)

After `./build.sh` and installing the rebuilt `dist/pkg_lcomment.zip`, run through the spec's 6 acceptance criteria in order:

1. Reply to a top-level comment → appears nested under it, not in the main flat list.
2. Reply to that reply (2nd level) → nests under the reply, not under the original comment.
3. With moderation on, submit a reply as a guest → pending badge shown; publishing it in the admin makes it appear in the correct nested position.
4. Unpublish/trash a comment that has published replies → the comment and all its (direct and indirect) replies disappear from the article.
5. Forge a POST to `comment.save` with a `parent_id` belonging to a *different* article → rejected with the invalid-parent error message.
6. Build a thread deeper than 5 levels → structure stays correct (each reply under its real parent) but visual indentation stops increasing past the 5th level.

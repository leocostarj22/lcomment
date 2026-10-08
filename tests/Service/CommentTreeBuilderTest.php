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

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

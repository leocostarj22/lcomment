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

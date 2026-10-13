<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class EditStatePolicy
{
    /**
     * Decides the new state for a comment being edited by its own
     * author. Editing may demote an already-published comment back to
     * pending (same rule a fresh submission uses), but must never
     * promote a comment that was not already published — whatever put
     * it at pending (initial moderation, an admin's own unpublish, or
     * reports crossing the auto-unpublish threshold) stays in effect
     * until an admin's own publish action changes it.
     */
    public static function nextState(bool $wasPublished, bool $contextModeration, bool $autopublish): int
    {
        if (!$wasPublished) {
            return 0;
        }

        return ($contextModeration && !$autopublish) ? 0 : 1;
    }
}

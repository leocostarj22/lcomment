<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\EditStatePolicy;
use PHPUnit\Framework\TestCase;

final class EditStatePolicyTest extends TestCase
{
    public function testStaysPendingWhenNotPreviouslyPublished(): void
    {
        self::assertSame(0, EditStatePolicy::nextState(false, true, false));
    }

    public function testStaysPendingWhenNotPreviouslyPublishedEvenWithoutModeration(): void
    {
        // This is the exploit Fase 3b's reports feature surfaced: a
        // comment knocked back to pending by reports (or by an admin's
        // own unpublish) must never be promoted straight back to
        // published just because the context happens to have no
        // moderation — only an admin's own publish action may do that.
        self::assertSame(0, EditStatePolicy::nextState(false, false, false));
    }

    public function testStaysPendingWhenNotPreviouslyPublishedEvenWithAutopublish(): void
    {
        self::assertSame(0, EditStatePolicy::nextState(false, true, true));
    }

    public function testDemotesPublishedCommentWhenModerationRequiresIt(): void
    {
        // Fase 3a's own already-tested behavior: editing an already-
        // published comment, in a moderated context, without
        // autopublish, sends it back to pending.
        self::assertSame(0, EditStatePolicy::nextState(true, true, false));
    }

    public function testKeepsPublishedWhenNoModerationNeeded(): void
    {
        self::assertSame(1, EditStatePolicy::nextState(true, false, false));
    }

    public function testKeepsPublishedWithAutopublishEvenWithModeration(): void
    {
        self::assertSame(1, EditStatePolicy::nextState(true, true, true));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\ReactionToggle;
use PHPUnit\Framework\TestCase;

final class ReactionToggleTest extends TestCase
{
    public function testNoExistingReactionInsertsTheRequestedType(): void
    {
        self::assertSame(
            ['action' => 'insert', 'type' => 'like'],
            ReactionToggle::decide(null, 'like')
        );
    }

    public function testClickingTheSameActiveReactionRemovesIt(): void
    {
        self::assertSame(
            ['action' => 'delete', 'type' => null],
            ReactionToggle::decide('like', 'like')
        );
    }

    public function testClickingADifferentReactionSwapsToTheNewType(): void
    {
        self::assertSame(
            ['action' => 'update', 'type' => 'love'],
            ReactionToggle::decide('like', 'love')
        );
    }

    public function testValidTypesListsExactlyTheSixFixedReactions(): void
    {
        self::assertSame(
            ['like', 'love', 'haha', 'wow', 'sad', 'angry'],
            ReactionToggle::VALID_TYPES
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\VoteToggle;
use PHPUnit\Framework\TestCase;

final class VoteToggleTest extends TestCase
{
    public function testNoExistingVoteInsertsTheRequestedType(): void
    {
        self::assertSame(
            ['action' => 'insert', 'type' => 'helpful'],
            VoteToggle::decide(null, 'helpful')
        );
    }

    public function testClickingTheSameActiveVoteRemovesIt(): void
    {
        self::assertSame(
            ['action' => 'delete', 'type' => null],
            VoteToggle::decide('helpful', 'helpful')
        );
    }

    public function testClickingTheOppositeVoteSwapsToTheNewType(): void
    {
        self::assertSame(
            ['action' => 'update', 'type' => 'unhelpful'],
            VoteToggle::decide('helpful', 'unhelpful')
        );
    }

    public function testValidTypesListsExactlyTheTwoFixedVotes(): void
    {
        self::assertSame(
            ['helpful', 'unhelpful'],
            VoteToggle::VALID_TYPES
        );
    }
}

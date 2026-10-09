<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotificationPolicy;
use PHPUnit\Framework\TestCase;

final class ReplyNotificationPolicyTest extends TestCase
{
    public function testDoesNotNotifyWhenReplyIsNotPublished(): void
    {
        self::assertFalse(ReplyNotificationPolicy::shouldNotify(0, 5, 10, 20, false));
    }

    public function testDoesNotNotifyWhenNotAReply(): void
    {
        self::assertFalse(ReplyNotificationPolicy::shouldNotify(1, 0, 10, 20, false));
    }

    public function testDoesNotNotifyWhenParentAuthorIsAGuest(): void
    {
        self::assertFalse(ReplyNotificationPolicy::shouldNotify(1, 5, null, 20, false));
    }

    public function testDoesNotNotifyOnSelfReply(): void
    {
        self::assertFalse(ReplyNotificationPolicy::shouldNotify(1, 5, 10, 10, false));
    }

    public function testDoesNotNotifyWhenAlreadyQueued(): void
    {
        self::assertFalse(ReplyNotificationPolicy::shouldNotify(1, 5, 10, 20, true));
    }

    public function testNotifiesWhenAllConditionsAreMet(): void
    {
        self::assertTrue(ReplyNotificationPolicy::shouldNotify(1, 5, 10, 20, false));
    }

    public function testNotifiesWhenTheReplyItselfIsFromAGuest(): void
    {
        self::assertTrue(ReplyNotificationPolicy::shouldNotify(1, 5, 10, null, false));
    }
}

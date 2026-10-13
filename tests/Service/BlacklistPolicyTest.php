<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\BlacklistPolicy;
use PHPUnit\Framework\TestCase;

final class BlacklistPolicyTest extends TestCase
{
    public function testRejectsUnknownType(): void
    {
        $errors = BlacklistPolicy::validateEntry('email', '1.2.3.4');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_TYPE'], $errors);
    }

    public function testRejectsEmptyValue(): void
    {
        $errors = BlacklistPolicy::validateEntry('ip', '   ');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'], $errors);
    }

    public function testRejectsMalformedIp(): void
    {
        $errors = BlacklistPolicy::validateEntry('ip', 'not-an-ip');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'], $errors);
    }

    public function testAcceptsValidIpv4(): void
    {
        self::assertSame([], BlacklistPolicy::validateEntry('ip', '203.0.113.42'));
    }

    public function testAcceptsValidIpv6(): void
    {
        self::assertSame([], BlacklistPolicy::validateEntry('ip', '2001:db8::1'));
    }

    public function testRejectsNonNumericUserValue(): void
    {
        $errors = BlacklistPolicy::validateEntry('user', 'abc');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'], $errors);
    }

    public function testRejectsZeroUserValue(): void
    {
        $errors = BlacklistPolicy::validateEntry('user', '0');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'], $errors);
    }

    public function testRejectsNegativeUserValue(): void
    {
        $errors = BlacklistPolicy::validateEntry('user', '-5');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'], $errors);
    }

    public function testAcceptsValidUserValue(): void
    {
        self::assertSame([], BlacklistPolicy::validateEntry('user', '42'));
    }

    public function testUnknownTypeTakesPriorityOverValueFormat(): void
    {
        $errors = BlacklistPolicy::validateEntry('email', '');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_TYPE'], $errors);
    }

    public function testIsBlockedWhenNeitherMatches(): void
    {
        self::assertFalse(BlacklistPolicy::isBlocked(false, false));
    }

    public function testIsBlockedWhenOnlyUserMatches(): void
    {
        self::assertTrue(BlacklistPolicy::isBlocked(true, false));
    }

    public function testIsBlockedWhenOnlyIpMatches(): void
    {
        self::assertTrue(BlacklistPolicy::isBlocked(false, true));
    }

    public function testIsBlockedWhenBothMatch(): void
    {
        self::assertTrue(BlacklistPolicy::isBlocked(true, true));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionPolicy;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionRequest;
use PHPUnit\Framework\TestCase;

final class SubmissionPolicyTest extends TestCase
{
    private function request(array $overrides = []): SubmissionRequest
    {
        return new SubmissionRequest(
            contextActive: $overrides['contextActive'] ?? true,
            contextModeration: $overrides['contextModeration'] ?? true,
            guestsAllowed: $overrides['guestsAllowed'] ?? true,
            userId: $overrides['userId'] ?? null,
            text: $overrides['text'] ?? 'A valid comment body.',
            minLength: $overrides['minLength'] ?? 3,
            maxLength: $overrides['maxLength'] ?? 1000,
        );
    }

    public function testRejectsInactiveContext(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['contextActive' => false]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_CONTEXT_INACTIVE'], $result->errors);
    }

    public function testRejectsGuestWhenGuestsNotAllowed(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'guestsAllowed' => false,
            'userId' => null,
        ]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED'], $result->errors);
    }

    public function testAllowsGuestWhenGuestsAllowed(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'guestsAllowed' => true,
            'userId' => null,
        ]));

        self::assertTrue($result->accepted);
    }

    public function testAllowsLoggedInUserEvenWhenGuestsNotAllowed(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'guestsAllowed' => false,
            'userId' => 42,
        ]));

        self::assertTrue($result->accepted);
    }

    public function testRejectsInvalidText(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['text' => '']));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_TEXT_REQUIRED'], $result->errors);
    }

    public function testModerationEnabledYieldsPendingState(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['contextModeration' => true]));

        self::assertTrue($result->accepted);
        self::assertSame(0, $result->initialState);
    }

    public function testModerationDisabledYieldsPublishedState(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['contextModeration' => false]));

        self::assertTrue($result->accepted);
        self::assertSame(1, $result->initialState);
    }

    public function testContextCheckTakesPriorityOverTextErrors(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'contextActive' => false,
            'text' => '',
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_CONTEXT_INACTIVE'], $result->errors);
    }
}

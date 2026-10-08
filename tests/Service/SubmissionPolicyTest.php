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
            guestName: $overrides['guestName'] ?? 'A Guest',
            guestEmail: $overrides['guestEmail'] ?? 'guest@example.com',
            itemIncluded: $overrides['itemIncluded'] ?? true,
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

    public function testAcceptedResultStoresTrimmedText(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['text' => "  Hello there.  \n"]));

        self::assertTrue($result->accepted);
        self::assertSame('Hello there.', $result->normalizedText);
    }

    public function testTrailingWhitespacePaddingDoesNotBypassMaximumLength(): void
    {
        // Validation measures the trimmed text, but the text actually stored
        // must also be the trimmed text, or padding lets oversized input through.
        $padded = str_repeat('a', 100) . str_repeat(' ', 100000);

        $result = SubmissionPolicy::evaluate($this->request(['text' => $padded, 'maxLength' => 100]));

        self::assertTrue($result->accepted);
        self::assertSame(100, \strlen($result->normalizedText));
    }

    public function testRejectsEmptyGuestName(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['userId' => null, 'guestName' => '']));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GUEST_NAME_REQUIRED'], $result->errors);
    }

    public function testRejectsWhitespaceOnlyGuestName(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['userId' => null, 'guestName' => "   \t"]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GUEST_NAME_REQUIRED'], $result->errors);
    }

    public function testRejectsGuestNameLongerThanColumnWidth(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['userId' => null, 'guestName' => str_repeat('a', 151)]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GUEST_NAME_TOO_LONG'], $result->errors);
    }

    public function testRejectsInvalidGuestEmail(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['userId' => null, 'guestEmail' => 'not-an-email']));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GUEST_EMAIL_INVALID'], $result->errors);
    }

    public function testRejectsGuestEmailLongerThanColumnWidth(): void
    {
        $longLocalPart = str_repeat('a', 250);

        $result = SubmissionPolicy::evaluate($this->request(['userId' => null, 'guestEmail' => $longLocalPart . '@example.com']));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GUEST_EMAIL_TOO_LONG'], $result->errors);
    }

    public function testLoggedInUserIsNotRequiredToProvideGuestNameOrEmail(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'userId' => 42,
            'guestName' => '',
            'guestEmail' => '',
        ]));

        self::assertTrue($result->accepted);
    }

    public function testGuestChecksTakePriorityOverTextErrors(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'userId' => null,
            'guestName' => '',
            'text' => '',
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_GUEST_NAME_REQUIRED'], $result->errors);
    }

    public function testRejectsItemExcludedByScope(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['itemIncluded' => false]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_ITEM_EXCLUDED'], $result->errors);
    }

    public function testAllowsItemIncludedByScope(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['itemIncluded' => true]));

        self::assertTrue($result->accepted);
    }

    public function testContextCheckTakesPriorityOverItemExcluded(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'contextActive' => false,
            'itemIncluded' => false,
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_CONTEXT_INACTIVE'], $result->errors);
    }

    public function testItemExcludedTakesPriorityOverGuestChecks(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'itemIncluded' => false,
            'guestsAllowed' => false,
            'userId' => null,
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_ITEM_EXCLUDED'], $result->errors);
    }
}

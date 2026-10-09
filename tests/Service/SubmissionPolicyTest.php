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
            userId: $overrides['userId'] ?? null,
            text: $overrides['text'] ?? 'A valid comment body.',
            minLength: $overrides['minLength'] ?? 3,
            maxLength: $overrides['maxLength'] ?? 1000,
            guestName: $overrides['guestName'] ?? 'A Guest',
            guestEmail: $overrides['guestEmail'] ?? 'guest@example.com',
            itemIncluded: $overrides['itemIncluded'] ?? true,
            parentValid: $overrides['parentValid'] ?? true,
            isReply: $overrides['isReply'] ?? false,
            canPost: $overrides['canPost'] ?? true,
            canReply: $overrides['canReply'] ?? true,
            autopublish: $overrides['autopublish'] ?? false,
        );
    }

    public function testRejectsInactiveContext(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['contextActive' => false]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_CONTEXT_INACTIVE'], $result->errors);
    }

    public function testRejectsNewCommentWhenGroupLacksCanPost(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'isReply' => false,
            'canPost' => false,
            'canReply' => true,
        ]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED'], $result->errors);
    }

    public function testRejectsReplyWhenGroupLacksCanReply(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'isReply' => true,
            'canPost' => true,
            'canReply' => false,
        ]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED'], $result->errors);
    }

    public function testAllowsNewCommentWhenGroupHasCanPost(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['isReply' => false, 'canPost' => true]));

        self::assertTrue($result->accepted);
    }

    public function testAllowsReplyWhenGroupHasCanReplyEvenWithoutCanPost(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'isReply' => true,
            'canPost' => false,
            'canReply' => true,
        ]));

        self::assertTrue($result->accepted);
    }

    public function testRejectsRegisteredUserWhenGroupLacksCanPost(): void
    {
        // The permission check is unified — a registered user is governed
        // by their own group's can_post exactly like a guest is governed
        // by the Público group's, with no special-casing by identity.
        $result = SubmissionPolicy::evaluate($this->request([
            'userId' => 42,
            'isReply' => false,
            'canPost' => false,
        ]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED'], $result->errors);
    }

    public function testRejectsInvalidText(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['text' => '']));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_TEXT_REQUIRED'], $result->errors);
    }

    public function testModerationEnabledWithoutAutopublishYieldsPendingState(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['contextModeration' => true, 'autopublish' => false]));

        self::assertTrue($result->accepted);
        self::assertSame(0, $result->initialState);
    }

    public function testModerationDisabledYieldsPublishedState(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['contextModeration' => false]));

        self::assertTrue($result->accepted);
        self::assertSame(1, $result->initialState);
    }

    public function testAutopublishYieldsPublishedStateEvenWithModerationEnabled(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['contextModeration' => true, 'autopublish' => true]));

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

    public function testGroupCheckTakesPriorityOverGuestDetailChecks(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'userId' => null,
            'canPost' => false,
            'guestName' => '',
            'text' => '',
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED'], $result->errors);
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

    public function testItemExcludedTakesPriorityOverGroupCheck(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'itemIncluded' => false,
            'canPost' => false,
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_ITEM_EXCLUDED'], $result->errors);
    }

    public function testRejectsInvalidParent(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['parentValid' => false]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_PARENT'], $result->errors);
    }

    public function testAllowsValidParent(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['parentValid' => true]));

        self::assertTrue($result->accepted);
    }

    public function testItemExcludedTakesPriorityOverInvalidParent(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'itemIncluded' => false,
            'parentValid' => false,
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_ITEM_EXCLUDED'], $result->errors);
    }

    public function testInvalidParentTakesPriorityOverGroupCheck(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'parentValid' => false,
            'canPost' => false,
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_PARENT'], $result->errors);
    }
}

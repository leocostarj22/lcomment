<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class SubmissionPolicy
{
    private const GUEST_NAME_MAX_LENGTH = 150;
    private const GUEST_EMAIL_MAX_LENGTH = 254;

    public static function evaluate(SubmissionRequest $request): SubmissionResult
    {
        if (!$request->contextActive) {
            return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_CONTEXT_INACTIVE']);
        }

        if (!$request->itemIncluded) {
            return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_ITEM_EXCLUDED']);
        }

        if ($request->userId === null && !$request->guestsAllowed) {
            return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED']);
        }

        if ($request->userId === null) {
            $guestErrors = self::validateGuestDetails($request->guestName, $request->guestEmail);

            if ($guestErrors !== []) {
                return new SubmissionResult(false, $guestErrors);
            }
        }

        $textErrors = CommentValidator::validate($request->text, $request->minLength, $request->maxLength);

        if ($textErrors !== []) {
            return new SubmissionResult(false, $textErrors);
        }

        return new SubmissionResult(
            true,
            [],
            $request->contextModeration ? 0 : 1,
            trim($request->text)
        );
    }

    /**
     * @return string[]
     */
    private static function validateGuestDetails(string $guestName, string $guestEmail): array
    {
        $trimmedName = trim($guestName);

        if ($trimmedName === '') {
            return ['COM_LCOMMENT_ERROR_GUEST_NAME_REQUIRED'];
        }

        if (\mb_strlen($trimmedName) > self::GUEST_NAME_MAX_LENGTH) {
            return ['COM_LCOMMENT_ERROR_GUEST_NAME_TOO_LONG'];
        }

        if (\strlen($guestEmail) > self::GUEST_EMAIL_MAX_LENGTH) {
            return ['COM_LCOMMENT_ERROR_GUEST_EMAIL_TOO_LONG'];
        }

        if (filter_var($guestEmail, FILTER_VALIDATE_EMAIL) === false) {
            return ['COM_LCOMMENT_ERROR_GUEST_EMAIL_INVALID'];
        }

        return [];
    }
}

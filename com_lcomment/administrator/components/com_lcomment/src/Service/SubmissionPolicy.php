<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class SubmissionRequest
{
    public function __construct(
        public readonly bool $contextActive,
        public readonly bool $contextModeration,
        public readonly bool $guestsAllowed,
        public readonly ?int $userId,
        public readonly string $text,
        public readonly int $minLength,
        public readonly int $maxLength,
    ) {
    }
}

final class SubmissionResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly array $errors,
        public readonly int $initialState = 0,
    ) {
    }
}

final class SubmissionPolicy
{
    public static function evaluate(SubmissionRequest $request): SubmissionResult
    {
        if (!$request->contextActive) {
            return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_CONTEXT_INACTIVE']);
        }

        if ($request->userId === null && !$request->guestsAllowed) {
            return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED']);
        }

        $textErrors = CommentValidator::validate($request->text, $request->minLength, $request->maxLength);

        if ($textErrors !== []) {
            return new SubmissionResult(false, $textErrors);
        }

        return new SubmissionResult(true, [], $request->contextModeration ? 0 : 1);
    }
}

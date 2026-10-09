<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class SubmissionRequest
{
    public function __construct(
        public readonly bool $contextActive,
        public readonly bool $contextModeration,
        public readonly ?int $userId,
        public readonly string $text,
        public readonly int $minLength,
        public readonly int $maxLength,
        public readonly string $guestName = '',
        public readonly string $guestEmail = '',
        public readonly bool $itemIncluded = true,
        public readonly bool $parentValid = true,
        public readonly bool $isReply = false,
        public readonly bool $canPost = false,
        public readonly bool $canReply = false,
        public readonly bool $autopublish = false,
    ) {
    }
}

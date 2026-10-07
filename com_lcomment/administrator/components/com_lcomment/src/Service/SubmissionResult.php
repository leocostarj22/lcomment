<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class SubmissionResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly array $errors,
        public readonly int $initialState = 0,
        public readonly string $normalizedText = '',
    ) {
    }
}

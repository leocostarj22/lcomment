<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class CommentValidator
{
    /**
     * @return string[] Language keys of validation errors; empty when valid.
     */
    public static function validate(string $text, int $minLength, int $maxLength): array
    {
        $trimmed = trim($text);

        if (\mb_strlen($trimmed) < max(1, $minLength)) {
            return ['COM_LCOMMENT_ERROR_TEXT_REQUIRED'];
        }

        if (\mb_strlen($trimmed) > $maxLength) {
            return ['COM_LCOMMENT_ERROR_TEXT_TOO_LONG'];
        }

        return [];
    }
}

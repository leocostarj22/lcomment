<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class BlacklistPolicy
{
    public const VALID_TYPES = ['ip', 'user'];

    /**
     * Validates a new blacklist entry before it is saved. Returns a
     * list of error keys (empty if valid).
     */
    public static function validateEntry(string $type, string $value): array
    {
        if (!\in_array($type, self::VALID_TYPES, true)) {
            return ['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_TYPE'];
        }

        $value = trim($value);

        if ($value === '') {
            return ['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'];
        }

        if ($type === 'ip' && filter_var($value, FILTER_VALIDATE_IP) === false) {
            return ['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'];
        }

        if ($type === 'user' && (!ctype_digit($value) || (int) $value <= 0)) {
            return ['COM_LCOMMENT_ERROR_INVALID_BLACKLIST_VALUE'];
        }

        return [];
    }

    /**
     * Decides whether an identity is blocked from the two facts a
     * caller already looked up (one row matched by user_id, one row
     * matched by IP). Kept as its own method, despite being a simple
     * OR, to document and pin the combining rule.
     */
    public static function isBlocked(bool $userMatch, bool $ipMatch): bool
    {
        return $userMatch || $ipMatch;
    }
}

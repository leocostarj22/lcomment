<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class ReportPolicy
{
    public const VALID_REASONS = ['spam', 'abusive', 'hate_speech', 'other'];

    /**
     * @return string[] Language keys of validation errors; empty when allowed.
     */
    public static function canReport(
        int $commentState,
        ?int $commentAuthorUserId,
        int $reporterUserId,
        bool $alreadyReported,
        string $reason
    ): array {
        if ($commentState !== 1) {
            return ['COM_LCOMMENT_ERROR_INVALID_REPORT_TARGET'];
        }

        if ($commentAuthorUserId !== null && $commentAuthorUserId === $reporterUserId) {
            return ['COM_LCOMMENT_ERROR_SELF_REPORT_NOT_ALLOWED'];
        }

        if ($alreadyReported) {
            return ['COM_LCOMMENT_ERROR_ALREADY_REPORTED'];
        }

        if (!\in_array($reason, self::VALID_REASONS, true)) {
            return ['COM_LCOMMENT_ERROR_INVALID_REPORT_REASON'];
        }

        return [];
    }

    public static function reachesAutoUnpublishThreshold(int $totalReportsAfterInsert, int $threshold): bool
    {
        return $totalReportsAfterInsert >= $threshold;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\ReportPolicy;
use PHPUnit\Framework\TestCase;

final class ReportPolicyTest extends TestCase
{
    public function testRejectsReportingAPendingComment(): void
    {
        $errors = ReportPolicy::canReport(0, 10, 20, false, 'spam');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_REPORT_TARGET'], $errors);
    }

    public function testRejectsReportingATrashedComment(): void
    {
        $errors = ReportPolicy::canReport(-2, 10, 20, false, 'spam');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_REPORT_TARGET'], $errors);
    }

    public function testRejectsSelfReport(): void
    {
        $errors = ReportPolicy::canReport(1, 20, 20, false, 'spam');

        self::assertSame(['COM_LCOMMENT_ERROR_SELF_REPORT_NOT_ALLOWED'], $errors);
    }

    public function testAllowsReportingAGuestAuthoredComment(): void
    {
        // A guest-authored comment has no user_id to ever match the
        // (always registered) reporter's id against.
        $errors = ReportPolicy::canReport(1, null, 20, false, 'spam');

        self::assertSame([], $errors);
    }

    public function testRejectsAlreadyReported(): void
    {
        $errors = ReportPolicy::canReport(1, 10, 20, true, 'spam');

        self::assertSame(['COM_LCOMMENT_ERROR_ALREADY_REPORTED'], $errors);
    }

    public function testRejectsInvalidReason(): void
    {
        $errors = ReportPolicy::canReport(1, 10, 20, false, 'not-a-real-reason');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_REPORT_REASON'], $errors);
    }

    public function testAllowsEachValidReason(): void
    {
        foreach (ReportPolicy::VALID_REASONS as $reason) {
            $errors = ReportPolicy::canReport(1, 10, 20, false, $reason);

            self::assertSame([], $errors, "Expected reason '{$reason}' to be accepted");
        }
    }

    public function testInvalidTargetTakesPriorityOverSelfReport(): void
    {
        $errors = ReportPolicy::canReport(0, 20, 20, false, 'spam');

        self::assertSame(['COM_LCOMMENT_ERROR_INVALID_REPORT_TARGET'], $errors);
    }

    public function testSelfReportTakesPriorityOverAlreadyReported(): void
    {
        $errors = ReportPolicy::canReport(1, 20, 20, true, 'spam');

        self::assertSame(['COM_LCOMMENT_ERROR_SELF_REPORT_NOT_ALLOWED'], $errors);
    }

    public function testAlreadyReportedTakesPriorityOverInvalidReason(): void
    {
        $errors = ReportPolicy::canReport(1, 10, 20, true, 'not-a-real-reason');

        self::assertSame(['COM_LCOMMENT_ERROR_ALREADY_REPORTED'], $errors);
    }

    public function testBelowThresholdDoesNotTrigger(): void
    {
        self::assertFalse(ReportPolicy::reachesAutoUnpublishThreshold(2, 3));
    }

    public function testAtThresholdTriggers(): void
    {
        self::assertTrue(ReportPolicy::reachesAutoUnpublishThreshold(3, 3));
    }

    public function testAboveThresholdTriggers(): void
    {
        self::assertTrue(ReportPolicy::reachesAutoUnpublishThreshold(4, 3));
    }
}

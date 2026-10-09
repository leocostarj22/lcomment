<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\GroupPermissionResolver;
use PHPUnit\Framework\TestCase;

final class GroupPermissionResolverTest extends TestCase
{
    private function row(bool $canPost, bool $canReply, bool $autopublish, bool $canEditOwn, bool $canDeleteOwn): array
    {
        return [
            'can_post' => $canPost,
            'can_reply' => $canReply,
            'autopublish' => $autopublish,
            'can_edit_own' => $canEditOwn,
            'can_delete_own' => $canDeleteOwn,
        ];
    }

    public function testDeniesEverythingWhenNoGroupHasARow(): void
    {
        $result = GroupPermissionResolver::resolve([], [99]);

        self::assertSame(
            ['can_post' => false, 'can_reply' => false, 'autopublish' => false, 'can_edit_own' => false, 'can_delete_own' => false],
            $result
        );
    }

    public function testDeniesEverythingWhenUserHasNoAuthorisedGroups(): void
    {
        $result = GroupPermissionResolver::resolve([1 => $this->row(true, true, true, true, true)], []);

        self::assertFalse($result['can_post']);
    }

    public function testGrantsPermissionFromTheMatchingGroupRow(): void
    {
        $result = GroupPermissionResolver::resolve(
            [2 => $this->row(true, true, false, true, true)],
            [2]
        );

        self::assertTrue($result['can_post']);
        self::assertTrue($result['can_reply']);
        self::assertFalse($result['autopublish']);
        self::assertTrue($result['can_edit_own']);
        self::assertTrue($result['can_delete_own']);
    }

    public function testIgnoresAnAuthorisedGroupIdWithNoConfiguredRow(): void
    {
        $result = GroupPermissionResolver::resolve(
            [2 => $this->row(true, true, true, true, true)],
            [999, 2]
        );

        self::assertTrue($result['can_post']);
    }

    public function testOrsPermissionsAcrossMultipleAuthorisedGroups(): void
    {
        $result = GroupPermissionResolver::resolve(
            [
                1 => $this->row(false, false, false, false, false),
                6 => $this->row(true, false, false, false, false),
            ],
            [1, 6]
        );

        self::assertTrue($result['can_post']);
        self::assertFalse($result['can_reply']);
    }

    public function testEachPermissionFlagIsIndependentlyOred(): void
    {
        $result = GroupPermissionResolver::resolve(
            [
                1 => $this->row(true, false, false, false, false),
                2 => $this->row(false, true, false, false, false),
                3 => $this->row(false, false, true, false, false),
                4 => $this->row(false, false, false, true, false),
                5 => $this->row(false, false, false, false, true),
            ],
            [1, 2, 3, 4, 5]
        );

        self::assertSame(
            ['can_post' => true, 'can_reply' => true, 'autopublish' => true, 'can_edit_own' => true, 'can_delete_own' => true],
            $result
        );
    }
}

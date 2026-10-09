<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class GroupPermissionResolver
{
    private const FLAGS = ['can_post', 'can_reply', 'autopublish', 'can_edit_own', 'can_delete_own'];

    /**
     * @param array<int, array{can_post: bool, can_reply: bool, autopublish: bool, can_edit_own: bool, can_delete_own: bool}> $rowsByGroupId
     * @param int[] $authorisedGroupIds
     * @return array{can_post: bool, can_reply: bool, autopublish: bool, can_edit_own: bool, can_delete_own: bool}
     */
    public static function resolve(array $rowsByGroupId, array $authorisedGroupIds): array
    {
        $result = array_fill_keys(self::FLAGS, false);

        foreach ($authorisedGroupIds as $groupId) {
            if (!isset($rowsByGroupId[$groupId])) {
                continue;
            }

            foreach (self::FLAGS as $flag) {
                $result[$flag] = $result[$flag] || (bool) $rowsByGroupId[$groupId][$flag];
            }
        }

        return $result;
    }
}

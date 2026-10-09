<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;

final class PermissionsModel extends BaseDatabaseModel
{
    /**
     * @return array<int, array{group_id: int, title: string, level: int, can_post: bool, can_reply: bool, autopublish: bool, can_edit_own: bool, can_delete_own: bool}>
     */
    public function getGroups(): array
    {
        $db = $this->getDatabase();

        // #__usergroups has no `level` column (verified against the real
        // joomla-cms installation SQL — only id/parent_id/lft/rgt/title
        // exist); depth is the standard nested-set calculation, the
        // number of ancestor rows whose range strictly contains this
        // row's.
        $levelSql = '(SELECT COUNT(*) FROM ' . $db->quoteName('#__usergroups', 'anc')
            . ' WHERE ' . $db->quoteName('anc.lft') . ' < ' . $db->quoteName('g.lft')
            . ' AND ' . $db->quoteName('anc.rgt') . ' > ' . $db->quoteName('g.rgt') . ')';

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('g.id', 'group_id'),
                $db->quoteName('g.title'),
                $levelSql . ' AS ' . $db->quoteName('level'),
                $db->quoteName('p.can_post'),
                $db->quoteName('p.can_reply'),
                $db->quoteName('p.autopublish'),
                $db->quoteName('p.can_edit_own'),
                $db->quoteName('p.can_delete_own'),
            ])
            ->from($db->quoteName('#__usergroups', 'g'))
            ->join(
                'LEFT',
                $db->quoteName('#__lcomment_group_permissions', 'p') . ' ON ' . $db->quoteName('p.group_id') . ' = ' . $db->quoteName('g.id')
            )
            ->order($db->quoteName('g.lft') . ' ASC');

        $db->setQuery($query);

        $result = [];

        foreach ($db->loadAssocList() as $row) {
            $result[] = [
                'group_id' => (int) $row['group_id'],
                'title' => (string) $row['title'],
                'level' => (int) $row['level'],
                'can_post' => (bool) $row['can_post'],
                'can_reply' => (bool) $row['can_reply'],
                'autopublish' => (bool) $row['autopublish'],
                'can_edit_own' => (bool) $row['can_edit_own'],
                'can_delete_own' => (bool) $row['can_delete_own'],
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array{can_post?: string, can_reply?: string, autopublish?: string, can_edit_own?: string, can_delete_own?: string}> $rows
     *        Indexed by group_id, as posted by the form (checkbox values are '1' or absent).
     */
    public function save(array $rows): void
    {
        $db = $this->getDatabase();

        // Never trust a stale form's group ids blindly — a group could
        // have been deleted in Joomla between this page loading and the
        // admin clicking Save. Filtering against a fresh read costs one
        // query and avoids writing permission rows for ids that no
        // longer exist (harmless either way, since nothing can ever
        // authorise as a deleted group, but there is no reason to keep
        // dead rows around either).
        $existingQuery = $db->getQuery(true)->select($db->quoteName('id'))->from($db->quoteName('#__usergroups'));
        $db->setQuery($existingQuery);
        $validGroupIds = array_flip(array_map('intval', $db->loadColumn()));

        foreach ($rows as $groupId => $flags) {
            $groupId = (int) $groupId;

            if (!isset($validGroupIds[$groupId])) {
                continue;
            }

            $canPost = isset($flags['can_post']) ? 1 : 0;
            $canReply = isset($flags['can_reply']) ? 1 : 0;
            $autopublish = isset($flags['autopublish']) ? 1 : 0;
            $canEditOwn = isset($flags['can_edit_own']) ? 1 : 0;
            $canDeleteOwn = isset($flags['can_delete_own']) ? 1 : 0;

            $deleteQuery = $db->getQuery(true)
                ->delete($db->quoteName('#__lcomment_group_permissions'))
                ->where($db->quoteName('group_id') . ' = :groupId')
                ->bind(':groupId', $groupId, ParameterType::INTEGER);

            $db->setQuery($deleteQuery);
            $db->execute();

            $insertQuery = $db->getQuery(true)
                ->insert($db->quoteName('#__lcomment_group_permissions'))
                ->columns([
                    $db->quoteName('group_id'),
                    $db->quoteName('can_post'),
                    $db->quoteName('can_reply'),
                    $db->quoteName('autopublish'),
                    $db->quoteName('can_edit_own'),
                    $db->quoteName('can_delete_own'),
                ])
                ->values(implode(', ', [$groupId, $canPost, $canReply, $autopublish, $canEditOwn, $canDeleteOwn]));

            $db->setQuery($insertQuery);

            try {
                $db->execute();
            } catch (ExecutionFailureException $exception) {
                // Should not happen (the row was just deleted above in
                // the same request), but never let a single group's
                // write failure abort the rest of the form's save.
            }
        }
    }
}

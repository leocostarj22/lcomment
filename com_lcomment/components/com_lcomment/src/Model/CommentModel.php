<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Site\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\ParameterType;

final class CommentModel extends BaseDatabaseModel
{
    public function getContext(string $extension, string $view): ?object
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__lcomment_contexts'))
            ->where($db->quoteName('extension') . ' = :extension')
            ->where($db->quoteName('view') . ' = :view')
            ->where($db->quoteName('published') . ' = 1')
            ->bind(':extension', $extension, ParameterType::STRING)
            ->bind(':view', $view, ParameterType::STRING);

        $db->setQuery($query);

        $result = $db->loadObject();

        return $result ?: null;
    }

    public function getItemsFor(string $extension, string $view, int $itemId): array
    {
        $db = $this->getDatabase();
        $user = \Joomla\CMS\Factory::getApplication()->getIdentity();
        $viewerId = ($user && $user->id > 0) ? (int) $user->id : null;

        // Fetch every state for this item — a trashed comment's own
        // visibility now depends on whether it has a visible descendant,
        // possibly several trashed levels down (e.g. a published reply
        // under a trashed reply under a trashed top-level comment), which
        // cannot be expressed as a fixed-depth SQL EXISTS. The final
        // filter below is still exactly as strict as before for every
        // directly-visible row; only trashed rows get the extra,
        // recursively-computed allowance.
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__lcomment_comments'))
            ->where($db->quoteName('extension') . ' = :extension')
            ->where($db->quoteName('view') . ' = :view')
            ->where($db->quoteName('item_id') . ' = :itemId')
            ->bind(':extension', $extension, ParameterType::STRING)
            ->bind(':view', $view, ParameterType::STRING)
            ->bind(':itemId', $itemId, ParameterType::INTEGER)
            ->order($db->quoteName('created') . ' ASC');

        $db->setQuery($query);

        $all = $db->loadObjectList() ?: [];

        $isDirectlyVisible = static function (object $row) use ($viewerId): bool {
            if ((int) $row->state === 1) {
                return true;
            }

            return (int) $row->state === 0 && $viewerId !== null && (int) $row->user_id === $viewerId;
        };

        $childrenOf = [];

        foreach ($all as $row) {
            $childrenOf[(int) $row->parent_id][] = $row;
        }

        $memo = [];

        $hasVisibleDescendant = function (int $id) use (&$hasVisibleDescendant, &$memo, $childrenOf, $isDirectlyVisible): bool {
            if (isset($memo[$id])) {
                return $memo[$id];
            }

            // Guard against a corrupted parent_id cycle looping forever —
            // mark this id as "not yet proven visible" before recursing,
            // same technique CommentTreeBuilder already uses.
            $memo[$id] = false;

            foreach ($childrenOf[$id] ?? [] as $child) {
                if ($isDirectlyVisible($child) || $hasVisibleDescendant((int) $child->id)) {
                    return $memo[$id] = true;
                }
            }

            return $memo[$id] = false;
        };

        $result = [];

        foreach ($all as $row) {
            if ($isDirectlyVisible($row)) {
                $result[] = $row;

                continue;
            }

            if ((int) $row->state === -2 && $hasVisibleDescendant((int) $row->id)) {
                // Never leak a removed comment's real content/author
                // through a custom template override of the layout —
                // the shipped layout already renders a fixed placeholder
                // for state -2 and never reads these fields, but an
                // override that forgets to check the state first must
                // not be able to recover them from the data it's handed.
                $row->comment_text = '';
                $row->guest_name = null;
                $row->guest_email = null;
                $row->user_id = null;
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * @return array<int, array{can_post: bool, can_reply: bool, autopublish: bool, can_edit_own: bool, can_delete_own: bool}>
     */
    public function getGroupPermissions(): array
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__lcomment_group_permissions'));

        $db->setQuery($query);

        $rows = $db->loadAssocList('group_id');

        $result = [];

        foreach ($rows as $groupId => $row) {
            $result[(int) $groupId] = [
                'can_post' => (bool) $row['can_post'],
                'can_reply' => (bool) $row['can_reply'],
                'autopublish' => (bool) $row['autopublish'],
                'can_edit_own' => (bool) $row['can_edit_own'],
                'can_delete_own' => (bool) $row['can_delete_own'],
            ];
        }

        return $result;
    }

    public function getCategoryId(string $extension, int $itemId): ?int
    {
        if ($extension !== 'com_content') {
            return null;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('catid'))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = :itemId')
            ->bind(':itemId', $itemId, ParameterType::INTEGER);

        $db->setQuery($query);

        $catid = $db->loadResult();

        return $catid !== null ? (int) $catid : null;
    }

    public function parentBelongsToItem(int $parentId, string $extension, string $view, int $itemId): bool
    {
        $db = $this->getDatabase();

        // Only excludes trashed (-2). A pending (state = 0) parent on the
        // same item is a valid reply target — moderation state is not a
        // reason to reject the reply, only trash/cross-item is. See the
        // spec's "pending parent" acceptance criterion.
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__lcomment_comments'))
            ->where($db->quoteName('id') . ' = :parentId')
            ->where($db->quoteName('extension') . ' = :extension')
            ->where($db->quoteName('view') . ' = :view')
            ->where($db->quoteName('item_id') . ' = :itemId')
            ->where($db->quoteName('state') . ' != -2')
            ->bind(':parentId', $parentId, ParameterType::INTEGER)
            ->bind(':extension', $extension, ParameterType::STRING)
            ->bind(':view', $view, ParameterType::STRING)
            ->bind(':itemId', $itemId, ParameterType::INTEGER);

        $db->setQuery($query);

        return (int) $db->loadResult() > 0;
    }

    public function getReactionsFor(array $commentIds): array
    {
        $result = [];

        foreach ($commentIds as $commentId) {
            $result[(int) $commentId] = ['counts' => [], 'mine' => null];
        }

        if ($commentIds === []) {
            return $result;
        }

        $db = $this->getDatabase();

        $countsQuery = $db->getQuery(true)
            ->select([
                $db->quoteName('comment_id'),
                $db->quoteName('reaction_type'),
                'COUNT(*) AS ' . $db->quoteName('total'),
            ])
            ->from($db->quoteName('#__lcomment_reactions'))
            ->whereIn($db->quoteName('comment_id'), $commentIds)
            ->group([$db->quoteName('comment_id'), $db->quoteName('reaction_type')]);

        $db->setQuery($countsQuery);

        foreach ($db->loadObjectList() as $row) {
            $result[(int) $row->comment_id]['counts'][(string) $row->reaction_type] = (int) $row->total;
        }

        $app = \Joomla\CMS\Factory::getApplication();
        $user = $app->getIdentity();

        $mineQuery = $db->getQuery(true)
            ->select([$db->quoteName('comment_id'), $db->quoteName('reaction_type')])
            ->from($db->quoteName('#__lcomment_reactions'))
            ->whereIn($db->quoteName('comment_id'), $commentIds);

        if ($user && $user->id > 0) {
            $mineQuery->where($db->quoteName('user_id') . ' = :userId')
                ->bind(':userId', $user->id, ParameterType::INTEGER);
        } else {
            $guestIp = $app->getInput()->server->getString('REMOTE_ADDR', '');
            $guestSessionId = $app->getSession()->getId();

            $mineQuery->where($db->quoteName('guest_ip') . ' = :guestIp')
                ->where($db->quoteName('guest_session_id') . ' = :guestSessionId')
                ->bind(':guestIp', $guestIp, ParameterType::STRING)
                ->bind(':guestSessionId', $guestSessionId, ParameterType::STRING);
        }

        $db->setQuery($mineQuery);

        foreach ($db->loadObjectList() as $row) {
            $result[(int) $row->comment_id]['mine'] = (string) $row->reaction_type;
        }

        return $result;
    }

    public function getVotesFor(array $commentIds): array
    {
        $result = [];

        foreach ($commentIds as $commentId) {
            $result[(int) $commentId] = ['counts' => [], 'mine' => null];
        }

        if ($commentIds === []) {
            return $result;
        }

        $db = $this->getDatabase();

        $countsQuery = $db->getQuery(true)
            ->select([
                $db->quoteName('comment_id'),
                $db->quoteName('vote_type'),
                'COUNT(*) AS ' . $db->quoteName('total'),
            ])
            ->from($db->quoteName('#__lcomment_votes'))
            ->whereIn($db->quoteName('comment_id'), $commentIds)
            ->group([$db->quoteName('comment_id'), $db->quoteName('vote_type')]);

        $db->setQuery($countsQuery);

        foreach ($db->loadObjectList() as $row) {
            $result[(int) $row->comment_id]['counts'][(string) $row->vote_type] = (int) $row->total;
        }

        $app = \Joomla\CMS\Factory::getApplication();
        $user = $app->getIdentity();

        $mineQuery = $db->getQuery(true)
            ->select([$db->quoteName('comment_id'), $db->quoteName('vote_type')])
            ->from($db->quoteName('#__lcomment_votes'))
            ->whereIn($db->quoteName('comment_id'), $commentIds);

        if ($user && $user->id > 0) {
            $mineQuery->where($db->quoteName('user_id') . ' = :userId')
                ->bind(':userId', $user->id, ParameterType::INTEGER);
        } else {
            $guestIp = $app->getInput()->server->getString('REMOTE_ADDR', '');
            $guestSessionId = $app->getSession()->getId();

            $mineQuery->where($db->quoteName('guest_ip') . ' = :guestIp')
                ->where($db->quoteName('guest_session_id') . ' = :guestSessionId')
                ->bind(':guestIp', $guestIp, ParameterType::STRING)
                ->bind(':guestSessionId', $guestSessionId, ParameterType::STRING);
        }

        $db->setQuery($mineQuery);

        foreach ($db->loadObjectList() as $row) {
            $result[(int) $row->comment_id]['mine'] = (string) $row->vote_type;
        }

        return $result;
    }

    /**
     * @param array<int, int|null> $userIds
     * @return array<int, string>
     */
    public function getAuthorNames(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(
            $userIds,
            static fn ($id) => $id !== null && (int) $id > 0
        )));

        if ($userIds === []) {
            return [];
        }

        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('name')])
            ->from($db->quoteName('#__users'))
            ->whereIn($db->quoteName('id'), array_map('intval', $userIds));

        $db->setQuery($query);

        $result = [];

        foreach ($db->loadObjectList() as $row) {
            $result[(int) $row->id] = (string) $row->name;
        }

        return $result;
    }
}

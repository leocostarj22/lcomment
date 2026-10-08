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

        if ($user && $user->id > 0) {
            $query->extendWhere(
                'AND',
                [
                    $db->quoteName('state') . ' = 1',
                    '(' . $db->quoteName('state') . ' = 0 AND ' . $db->quoteName('user_id') . ' = :userId)',
                ],
                'OR'
            )->bind(':userId', $user->id, ParameterType::INTEGER);
        } else {
            $query->where($db->quoteName('state') . ' = 1');
        }

        $db->setQuery($query);

        return $db->loadObjectList() ?: [];
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
}

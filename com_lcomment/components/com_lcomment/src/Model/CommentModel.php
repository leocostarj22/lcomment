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
}

<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

final class CommentsModel extends ListModel
{
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'extension', 'view', 'state', 'created'];
        }

        parent::__construct($config);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__lcomment_comments'));

        $search = (string) $this->getState('filter.search', '');

        if ($search !== '') {
            $likeSearch = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->where($db->quoteName('comment_text') . ' LIKE :search')
                ->bind(':search', $likeSearch, ParameterType::STRING);
        }

        $state = $this->getState('filter.state', '');

        if ($state !== '') {
            $query->where($db->quoteName('state') . ' = :state')
                ->bind(':state', $state, ParameterType::INTEGER);
        } else {
            // Hide trashed comments from the default view, matching Joomla convention.
            $query->where($db->quoteName('state') . ' != -2');
        }

        $ordering = $this->state->get('list.ordering', 'created');
        $direction = $this->state->get('list.direction', 'DESC');
        $query->order($db->escape($ordering) . ' ' . $db->escape($direction));

        return $query;
    }
}

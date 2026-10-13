<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

final class BlacklistsModel extends ListModel
{
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['a.id', 'a.type', 'a.value', 'a.created'];
        }

        parent::__construct($config);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('a.*')
            ->select($db->quoteName('u.name', 'created_by_name'))
            ->from($db->quoteName('#__lcomment_blacklist', 'a'))
            ->join(
                'LEFT',
                $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('a.created_by')
            );

        $search = (string) $this->getState('filter.search', '');

        if ($search !== '') {
            $likeSearch = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->where($db->quoteName('a.value') . ' LIKE :search')
                ->bind(':search', $likeSearch, ParameterType::STRING);
        }

        $ordering = $this->state->get('list.ordering', 'a.created');
        $direction = $this->state->get('list.direction', 'DESC');
        $query->order($db->escape($ordering) . ' ' . $db->escape($direction));

        return $query;
    }
}

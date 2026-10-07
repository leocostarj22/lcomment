<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

final class ContextsModel extends ListModel
{
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'extension', 'view', 'published'];
        }

        parent::__construct($config);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__lcomment_contexts'));

        $search = (string) $this->getState('filter.search', '');

        if ($search !== '') {
            $query->where(
                '(' . $db->quoteName('extension') . ' LIKE :search1 OR ' . $db->quoteName('view') . ' LIKE :search2)'
            )
                ->bind(':search1', $search, ParameterType::STRING)
                ->bind(':search2', $search, ParameterType::STRING);
        }

        $ordering = $this->state->get('list.ordering', 'extension');
        $direction = $this->state->get('list.direction', 'ASC');
        $query->order($db->escape($ordering) . ' ' . $db->escape($direction));

        return $query;
    }
}

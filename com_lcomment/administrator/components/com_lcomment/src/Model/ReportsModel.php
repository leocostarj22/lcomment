<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;

final class ReportsModel extends ListModel
{
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'reason', 'created'];
        }

        parent::__construct($config);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();

        // LEFT JOIN, not an inner join: a reported comment the author
        // later deleted themselves (Fase 3a, state = -2) must still
        // show up here so the admin can still see and eliminate the
        // report — losing it from this list just because the comment
        // is gone would make an existing report permanently
        // un-eliminable through the normal UI.
        $query = $db->getQuery(true)
            ->select('r.*')
            ->select($db->quoteName('u.name', 'reporter_name'))
            ->select($db->quoteName('c.comment_text'))
            ->select($db->quoteName('c.item_url'))
            ->select($db->quoteName('c.extension'))
            ->select($db->quoteName('c.view'))
            ->from($db->quoteName('#__lcomment_reports', 'r'))
            ->join(
                'LEFT',
                $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('r.user_id')
            )
            ->join(
                'LEFT',
                $db->quoteName('#__lcomment_comments', 'c') . ' ON ' . $db->quoteName('c.id') . ' = ' . $db->quoteName('r.comment_id')
            );

        $ordering = $this->state->get('list.ordering', 'r.created');
        $direction = $this->state->get('list.direction', 'DESC');
        $query->order($db->escape($ordering) . ' ' . $db->escape($direction));

        return $query;
    }
}

<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\AdminModel;

final class CommentModel extends AdminModel
{
    public function getTable($type = 'Comment', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function getForm($data = [], $loadData = true)
    {
        // Comments are moderated (publish/unpublish/trash), never created
        // or edited through a form in this phase.
        return false;
    }
}

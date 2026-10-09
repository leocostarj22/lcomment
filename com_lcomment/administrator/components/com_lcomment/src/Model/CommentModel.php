<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\AdminModel;
use Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotifier;

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

    public function publish(&$pks, $value = 1)
    {
        $result = parent::publish($pks, $value);

        // Verified against real joomla-cms 5.4-dev source
        // (AdminModel::publish()): $pks is pruned BY REFERENCE to only
        // the ids that actually changed to $value during this call —
        // Joomla already filters out "already published" ids before we
        // ever see them here, so re-publishing an already-published
        // comment never reaches notifyIfNeeded() for it.
        if ($result && (int) $value === 1) {
            foreach ((array) $pks as $pk) {
                ReplyNotifier::notifyIfNeeded((int) $pk);
            }
        }

        return $result;
    }
}

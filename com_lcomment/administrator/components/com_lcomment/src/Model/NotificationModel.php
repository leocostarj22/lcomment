<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\AdminModel;

final class NotificationModel extends AdminModel
{
    public function getTable($type = 'Notification', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function getForm($data = [], $loadData = true)
    {
        // Notifications are queue rows, never created or edited through a
        // form — only listed (NotificationsModel) and deleted
        // (AdminController::delete(), inherited by NotificationsController).
        return false;
    }
}

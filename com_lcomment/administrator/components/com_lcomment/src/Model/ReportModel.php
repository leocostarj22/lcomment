<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\AdminModel;

final class ReportModel extends AdminModel
{
    public function getTable($type = 'Report', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function getForm($data = [], $loadData = true)
    {
        // Reports are logged events, never created or edited through a
        // form — only listed (ReportsModel) and deleted
        // (AdminController::delete(), inherited by ReportsController).
        return false;
    }
}

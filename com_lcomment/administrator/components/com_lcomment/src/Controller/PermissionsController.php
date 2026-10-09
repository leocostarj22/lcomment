<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;

final class PermissionsController extends BaseController
{
    public function save(): void
    {
        Session::checkToken('post') or die(Text::_('JINVALID_TOKEN'));

        $app = Factory::getApplication();
        $rows = (array) $app->getInput()->get('groups', [], 'array');

        /** @var \Lcsilva\Component\Lcomment\Administrator\Model\PermissionsModel $model */
        $model = $this->getModel('Permissions');
        $model->save($rows);

        $app->enqueueMessage(Text::_('COM_LCOMMENT_PERMISSIONS_SAVED_MESSAGE'));
        $app->redirect('index.php?option=com_lcomment&view=permissions');
    }
}

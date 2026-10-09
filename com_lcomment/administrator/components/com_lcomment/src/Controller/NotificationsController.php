<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Session\Session;
use Lcsilva\Component\Lcomment\Administrator\Service\NotificationQueueProcessor;

final class NotificationsController extends AdminController
{
    protected $text_prefix = 'COM_LCOMMENT_NOTIFICATIONS';

    public function getModel($name = 'Notification', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    public function process(): void
    {
        Session::checkToken('request') or die(Text::_('JINVALID_TOKEN'));

        $processed = NotificationQueueProcessor::process();

        $app = Factory::getApplication();
        $app->enqueueMessage(Text::sprintf('COM_LCOMMENT_NOTIFICATIONS_PROCESSED_MESSAGE', $processed));
        $app->redirect('index.php?option=com_lcomment&view=notifications');
    }
}

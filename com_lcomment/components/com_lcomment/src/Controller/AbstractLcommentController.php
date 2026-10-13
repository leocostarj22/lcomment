<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Lcsilva\Component\Lcomment\Administrator\Service\BlacklistChecker;

abstract class AbstractLcommentController extends BaseController
{
    public function execute($task)
    {
        $app = Factory::getApplication();
        $input = $app->getInput();
        $user = $app->getIdentity();
        $ip = $input->server->getString('REMOTE_ADDR', '');
        $userId = ($user !== null && $user->id > 0) ? (int) $user->id : null;

        if (BlacklistChecker::isBlocked($userId, $ip)) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_BLACKLISTED'), 'error');

            $returnUrl = base64_decode($input->getBase64('return', ''));
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        return parent::execute($task);
    }
}

<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\AdminController;

final class BlacklistsController extends AdminController
{
    protected $text_prefix = 'COM_LCOMMENT_BLACKLISTS';

    public function getModel($name = 'Blacklist', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }
}

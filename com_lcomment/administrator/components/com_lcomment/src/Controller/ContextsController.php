<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\AdminController;

final class ContextsController extends AdminController
{
    protected $text_prefix = 'COM_LCOMMENT_CONTEXTS';

    public function getModel($name = 'Context', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }
}

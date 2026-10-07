<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\FormController;

final class ContextController extends FormController
{
    protected $text_prefix = 'COM_LCOMMENT_CONTEXT';
}

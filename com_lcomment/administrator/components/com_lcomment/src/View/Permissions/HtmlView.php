<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\View\Permissions;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

final class HtmlView extends BaseHtmlView
{
    public $groups;

    public function display($tpl = null)
    {
        $this->groups = $this->get('Groups');

        ToolbarHelper::title(Text::_('COM_LCOMMENT_PERMISSIONS_TITLE'));
        ToolbarHelper::custom('permissions.save', 'save', '', 'JTOOLBAR_SAVE', false);
        ToolbarHelper::preferences('com_lcomment');

        parent::display($tpl);
    }
}

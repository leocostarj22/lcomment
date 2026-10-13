<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\View\Blacklist;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

final class HtmlView extends BaseHtmlView
{
    public $item;
    public $form;

    public function display($tpl = null)
    {
        $this->item = $this->get('Item');
        $this->form = $this->get('Form');

        ToolbarHelper::title(Text::_('COM_LCOMMENT_BLACKLISTS_TITLE'));
        ToolbarHelper::apply('blacklist.apply');
        ToolbarHelper::save('blacklist.save');
        ToolbarHelper::cancel('blacklist.cancel');

        parent::display($tpl);
    }
}

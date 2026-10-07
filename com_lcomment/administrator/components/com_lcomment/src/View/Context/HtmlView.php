<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\View\Context;

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

        ToolbarHelper::title(Text::_('COM_LCOMMENT_CONTEXTS_TITLE'));
        ToolbarHelper::apply('context.apply');
        ToolbarHelper::save('context.save');
        ToolbarHelper::cancel('context.cancel');

        parent::display($tpl);
    }
}

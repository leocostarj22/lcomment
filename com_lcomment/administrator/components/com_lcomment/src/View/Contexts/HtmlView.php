<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\View\Contexts;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

final class HtmlView extends BaseHtmlView
{
    public $items;
    public $pagination;
    public $state;

    public function display($tpl = null)
    {
        $this->items = $this->get('Items');
        $this->pagination = $this->get('Pagination');
        $this->state = $this->get('State');

        ToolbarHelper::title(Text::_('COM_LCOMMENT_CONTEXTS_TITLE'));
        ToolbarHelper::addNew('context.add');
        ToolbarHelper::editList('context.edit');
        ToolbarHelper::publishList('contexts.publish');
        ToolbarHelper::unpublishList('contexts.unpublish');
        ToolbarHelper::deleteList('', 'contexts.delete');

        parent::display($tpl);
    }
}

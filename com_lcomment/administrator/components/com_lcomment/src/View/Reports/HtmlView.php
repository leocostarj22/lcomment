<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\View\Reports;

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

        ToolbarHelper::title(Text::_('COM_LCOMMENT_REPORTS_TITLE'));
        ToolbarHelper::deleteList('', 'reports.delete');
        ToolbarHelper::preferences('com_lcomment');

        parent::display($tpl);
    }
}

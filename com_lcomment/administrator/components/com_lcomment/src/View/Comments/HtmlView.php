<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\View\Comments;

\defined('_JEXEC') or die;

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

        ToolbarHelper::title('COM_LCOMMENT_COMMENTS_TITLE');
        ToolbarHelper::publishList('comments.publish');
        ToolbarHelper::unpublishList('comments.unpublish');
        ToolbarHelper::trash('comments.trash');

        parent::display($tpl);
    }
}

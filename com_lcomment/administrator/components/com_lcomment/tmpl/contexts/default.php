<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Contexts\HtmlView $this */

$listOrder = $this->state->get('list.ordering', 'extension');
$listDirn = $this->state->get('list.direction', 'ASC');
?>
<form action="<?php echo htmlspecialchars(\Joomla\CMS\Uri\Uri::getInstance()->toString()); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row mb-3">
        <div class="col-md-4">
            <input
                type="text"
                name="filter[search]"
                value="<?php echo htmlspecialchars((string) $this->state->get('filter.search', '')); ?>"
                class="form-control"
                placeholder="<?php echo Text::_('JSEARCH_FILTER'); ?>"
                onchange="this.form.submit()"
            >
        </div>
    </div>
    <table class="table">
        <thead>
            <tr>
                <td></td>
                <th><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_EXTENSION_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_VIEW_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_MODERATION_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_PUBLISHED_LABEL'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->items as $i => $item) : ?>
            <tr>
                <td></td>
                <td><?php echo HTMLHelper::_('grid.id', $i, $item->id); ?></td>
                <td>
                    <a href="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_lcomment&task=context.edit&id=' . (int) $item->id); ?>">
                        <?php echo htmlspecialchars($item->extension); ?>
                    </a>
                </td>
                <td><?php echo htmlspecialchars($item->view); ?></td>
                <td><?php echo $item->moderation ? Text::_('JYES') : Text::_('JNO'); ?></td>
                <td><?php echo $item->published ? Text::_('JPUBLISHED') : Text::_('JUNPUBLISHED'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php echo $this->pagination->getListFooter(); ?>
    <input type="hidden" name="task" value="">
    <input type="hidden" name="boxchecked" value="0">
    <input type="hidden" name="filter_order" value="<?php echo htmlspecialchars($listOrder); ?>">
    <input type="hidden" name="filter_order_Dir" value="<?php echo htmlspecialchars($listDirn); ?>">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>

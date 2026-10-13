<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Blacklists\HtmlView $this */

$listOrder = $this->state->get('list.ordering', 'a.created');
$listDirn = $this->state->get('list.direction', 'DESC');
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
                <th><?php echo Text::_('COM_LCOMMENT_BLACKLIST_TYPE_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_BLACKLIST_VALUE_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_BLACKLIST_REASON_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_BLACKLIST_CREATED_BY_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_BLACKLIST_CREATED_LABEL'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->items as $i => $item) : ?>
            <tr>
                <td></td>
                <td><?php echo HTMLHelper::_('grid.id', $i, $item->id); ?></td>
                <td>
                    <a href="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_lcomment&task=blacklist.edit&id=' . (int) $item->id); ?>">
                        <?php echo $item->type === 'ip' ? Text::_('COM_LCOMMENT_BLACKLIST_TYPE_IP') : Text::_('COM_LCOMMENT_BLACKLIST_TYPE_USER'); ?>
                    </a>
                </td>
                <td><?php echo htmlspecialchars((string) $item->value); ?></td>
                <td><?php echo htmlspecialchars(mb_strimwidth((string) $item->reason, 0, 80, '…')); ?></td>
                <td><?php echo htmlspecialchars((string) ($item->created_by_name ?: ('#' . (int) $item->created_by))); ?></td>
                <td><?php echo htmlspecialchars((string) $item->created); ?></td>
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

<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Comments\HtmlView $this */

$listOrder = $this->state->get('list.ordering', 'created');
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
        <div class="col-md-3">
            <select name="filter[state]" class="form-select" onchange="this.form.submit()">
                <option value=""><?php echo Text::_('JOPTION_SELECT_PUBLISHED'); ?></option>
                <option value="1" <?php echo (string) $this->state->get('filter.state', '') === '1' ? 'selected' : ''; ?>><?php echo Text::_('JPUBLISHED'); ?></option>
                <option value="0" <?php echo (string) $this->state->get('filter.state', '') === '0' ? 'selected' : ''; ?>><?php echo Text::_('JUNPUBLISHED'); ?></option>
                <option value="-2" <?php echo (string) $this->state->get('filter.state', '') === '-2' ? 'selected' : ''; ?>><?php echo Text::_('JTRASHED'); ?></option>
            </select>
        </div>
    </div>
    <table class="table">
        <thead>
            <tr>
                <td></td>
                <th><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_EXTENSION_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_VIEW_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_COMMENTS_AUTHOR_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_COMMENTS_TEXT_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_PUBLISHED_LABEL'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->items as $i => $item) : ?>
            <tr>
                <td></td>
                <td><?php echo HTMLHelper::_('grid.id', $i, $item->id); ?></td>
                <td><?php echo htmlspecialchars($item->extension); ?></td>
                <td><?php echo htmlspecialchars($item->view); ?></td>
                <td><?php echo htmlspecialchars((string) ($item->guest_name ?: ($item->author_name ?: ('#' . (int) $item->user_id)))); ?></td>
                <td><?php echo htmlspecialchars(mb_strimwidth((string) $item->comment_text, 0, 80, '…')); ?></td>
                <td>
                    <?php if ((int) $item->state === 1) : ?>
                        <?php echo Text::_('JPUBLISHED'); ?>
                    <?php elseif ((int) $item->state === -2) : ?>
                        <?php echo Text::_('JTRASHED'); ?>
                    <?php else : ?>
                        <?php echo Text::_('JUNPUBLISHED'); ?>
                    <?php endif; ?>
                </td>
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

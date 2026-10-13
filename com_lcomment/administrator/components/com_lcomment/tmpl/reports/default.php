<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Reports\HtmlView $this */

$listOrder = $this->state->get('list.ordering', 'r.created');
$listDirn = $this->state->get('list.direction', 'DESC');

$reasonLabels = [
    'spam' => 'COM_LCOMMENT_REPORT_REASON_SPAM',
    'abusive' => 'COM_LCOMMENT_REPORT_REASON_ABUSIVE',
    'hate_speech' => 'COM_LCOMMENT_REPORT_REASON_HATE_SPEECH',
    'other' => 'COM_LCOMMENT_REPORT_REASON_OTHER',
];
?>
<form action="<?php echo htmlspecialchars(\Joomla\CMS\Uri\Uri::getInstance()->toString()); ?>" method="post" name="adminForm" id="adminForm">
    <table class="table">
        <thead>
            <tr>
                <th><?php echo HTMLHelper::_('grid.checkall'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_REPORTS_PAGE_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_REPORTS_COMMENT_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_REPORTS_REPORTER_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_REPORTS_REASON_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_REPORTS_CREATED_LABEL'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->items as $i => $item) : ?>
            <tr>
                <td><?php echo HTMLHelper::_('grid.id', $i, $item->id); ?></td>
                <td>
                    <?php $pageLabel = htmlspecialchars((string) $item->extension . ' / ' . (string) $item->view); ?>
                    <?php if (!empty($item->item_url)) : ?>
                        <a href="<?php echo htmlspecialchars((string) $item->item_url); ?>" target="_blank" rel="noopener"><?php echo $pageLabel; ?></a>
                    <?php else : ?>
                        <?php echo $pageLabel; ?>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars(mb_strimwidth((string) $item->comment_text, 0, 80, '…')); ?></td>
                <td><?php echo htmlspecialchars((string) ($item->reporter_name ?: ('#' . (int) $item->user_id))); ?></td>
                <td><?php echo Text::_($reasonLabels[$item->reason] ?? $item->reason); ?></td>
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

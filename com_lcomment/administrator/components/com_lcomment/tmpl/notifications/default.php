<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Notifications\HtmlView $this */

$listOrder = $this->state->get('list.ordering', 'n.created');
$listDirn = $this->state->get('list.direction', 'DESC');
?>
<form action="<?php echo htmlspecialchars(\Joomla\CMS\Uri\Uri::getInstance()->toString()); ?>" method="post" name="adminForm" id="adminForm">
    <table class="table">
        <thead>
            <tr>
                <th><?php echo Text::_('COM_LCOMMENT_NOTIFICATIONS_RECIPIENT_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_NOTIFICATIONS_SUBJECT_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_NOTIFICATIONS_ATTEMPTS_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_NOTIFICATIONS_CREATED_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_NOTIFICATIONS_SENT_LABEL'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->items as $item) : ?>
            <tr>
                <td><?php echo htmlspecialchars((string) ($item->recipient_name ?: $item->recipient_email)); ?></td>
                <td><?php echo htmlspecialchars((string) $item->subject); ?></td>
                <td><?php echo (int) $item->attempts; ?></td>
                <td><?php echo htmlspecialchars((string) $item->created); ?></td>
                <td>
                    <?php if ($item->sent_at) : ?>
                        <?php echo htmlspecialchars((string) $item->sent_at); ?>
                    <?php else : ?>
                        <?php echo Text::_('COM_LCOMMENT_NOTIFICATIONS_PENDING'); ?>
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

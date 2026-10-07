<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Comments\HtmlView $this */
?>
<form action="<?php echo htmlspecialchars(\Joomla\CMS\Uri\Uri::getInstance()->toString()); ?>" method="post" name="adminForm" id="adminForm">
    <table class="table">
        <thead>
            <tr>
                <th></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_EXTENSION_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_VIEW_LABEL'); ?></th>
                <th>Author</th>
                <th>Comment</th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_PUBLISHED_LABEL'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->items as $i => $item) : ?>
            <tr>
                <td>
                    <input type="checkbox" name="cid[]" value="<?php echo (int) $item->id; ?>">
                </td>
                <td><?php echo htmlspecialchars($item->extension); ?></td>
                <td><?php echo htmlspecialchars($item->view); ?></td>
                <td><?php echo htmlspecialchars($item->guest_name ?: ('#' . (int) $item->user_id)); ?></td>
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
    <?php echo HTMLHelper::_('form.token'); ?>
</form>

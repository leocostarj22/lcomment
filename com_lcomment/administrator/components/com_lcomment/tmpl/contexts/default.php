<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Contexts\HtmlView $this */
?>
<form action="<?php echo htmlspecialchars(\Joomla\CMS\Uri\Uri::getInstance()->toString()); ?>" method="post" name="adminForm" id="adminForm">
    <table class="table">
        <thead>
            <tr>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_EXTENSION_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_VIEW_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_MODERATION_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_PUBLISHED_LABEL'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->items as $i => $item) : ?>
            <tr>
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
    <?php echo HTMLHelper::_('form.token'); ?>
</form>

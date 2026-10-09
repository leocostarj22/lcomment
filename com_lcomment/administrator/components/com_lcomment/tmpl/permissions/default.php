<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Permissions\HtmlView $this */

$flags = [
    'can_post' => 'COM_LCOMMENT_PERMISSIONS_CAN_POST_LABEL',
    'can_reply' => 'COM_LCOMMENT_PERMISSIONS_CAN_REPLY_LABEL',
    'autopublish' => 'COM_LCOMMENT_PERMISSIONS_AUTOPUBLISH_LABEL',
    'can_edit_own' => 'COM_LCOMMENT_PERMISSIONS_CAN_EDIT_OWN_LABEL',
    'can_delete_own' => 'COM_LCOMMENT_PERMISSIONS_CAN_DELETE_OWN_LABEL',
];
?>
<form action="<?php echo htmlspecialchars(\Joomla\CMS\Uri\Uri::getInstance()->toString()); ?>" method="post" name="adminForm" id="adminForm">
    <table class="table">
        <thead>
            <tr>
                <th><?php echo Text::_('COM_LCOMMENT_PERMISSIONS_GROUP_LABEL'); ?></th>
                <?php foreach ($flags as $label) : ?>
                    <th><?php echo Text::_($label); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->groups as $group) : ?>
            <tr>
                <td>
                    <input type="hidden" name="groups[<?php echo $group['group_id']; ?>][present]" value="1">
                    <?php echo str_repeat('— ', max(0, $group['level'] - 1)) . htmlspecialchars($group['title']); ?>
                </td>
                <?php foreach (array_keys($flags) as $flagKey) : ?>
                    <td>
                        <input type="checkbox"
                            name="groups[<?php echo $group['group_id']; ?>][<?php echo $flagKey; ?>]"
                            value="1"
                            <?php echo $group[$flagKey] ? 'checked' : ''; ?>
                        >
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>

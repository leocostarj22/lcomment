<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Context\HtmlView $this */
?>
<form action="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_lcomment&task=context.save'); ?>" method="post" name="adminForm" id="context-form">
    <?php foreach ($this->form->getFieldset() as $field) : ?>
        <div class="mb-3">
            <?php echo $field->renderLabel(); ?>
            <?php echo $field->renderField(); ?>
        </div>
    <?php endforeach; ?>
    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>

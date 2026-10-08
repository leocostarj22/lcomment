<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Context\HtmlView $this */

// The form-validate class below requires this script, or Joomla.submitbutton()
// throws on document.formvalidator being undefined and the Save/Apply/Close
// toolbar buttons silently do nothing.
Factory::getApplication()->getDocument()->getWebAssetManager()->useScript('form.validate');
?>
<form action="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_lcomment&layout=edit&id=' . (int) $this->item->id); ?>" method="post" name="adminForm" id="adminForm" class="form-validate">
    <?php if ((string) ($this->item->scope_mode ?? 'all') === 'include' && empty($this->item->scope_rules)) : ?>
        <div class="alert alert-warning">
            <?php echo Text::_('COM_LCOMMENT_CONTEXT_SCOPE_INCLUDE_EMPTY_WARNING'); ?>
        </div>
    <?php endif; ?>
    <?php foreach ($this->form->getFieldset() as $field) : ?>
        <div class="mb-3">
            <?php echo $field->renderField(); ?>
        </div>
    <?php endforeach; ?>
    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>

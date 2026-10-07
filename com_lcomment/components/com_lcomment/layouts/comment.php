<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/**
 * Expected keys in $displayData (array or object):
 * @var string $extension
 * @var string $view
 * @var int    $itemId
 * @var array  $items
 * @var string $returnUrl
 *
 * Overridable via templates/<template>/html/layouts/comment.php
 */
extract((array) $displayData);

$app = Factory::getApplication();
$user = $app->getIdentity();

// Read-once: repopulate the form after a rejected submission, then forget it.
$stateKey = 'com_lcomment.comment.state.' . $extension . '.' . $view . '.' . $itemId;
$previous = (array) $app->getUserState($stateKey, []);
$app->setUserState($stateKey, null);

$prefillText = $previous['text'] ?? '';
$prefillGuestName = $previous['guest_name'] ?? '';
$prefillGuestEmail = $previous['guest_email'] ?? '';
?>
<div class="lcomment-block">
    <ul class="lcomment-list list-unstyled">
        <?php if (empty($items)) : ?>
            <li><?php echo Text::_('COM_LCOMMENT_LIST_EMPTY'); ?></li>
        <?php endif; ?>
        <?php foreach ($items as $comment) : ?>
            <li class="lcomment-item" data-id="<?php echo (int) $comment->id; ?>">
                <strong>
                    <?php echo htmlspecialchars($comment->guest_name ?: ('#' . (int) $comment->user_id)); ?>
                </strong>
                <?php if ((int) $comment->state === 0) : ?>
                    <span class="badge bg-warning"><?php echo Text::_('COM_LCOMMENT_LIST_PENDING_BADGE'); ?></span>
                <?php endif; ?>
                <p><?php echo htmlspecialchars((string) $comment->comment_text); ?></p>
            </li>
        <?php endforeach; ?>
    </ul>

    <form method="post" action="<?php echo Route::_('index.php?option=com_lcomment&task=comment.save'); ?>" class="lcomment-form">
        <input type="hidden" name="extension" value="<?php echo htmlspecialchars($extension); ?>">
        <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
        <input type="hidden" name="item_id" value="<?php echo (int) $itemId; ?>">
        <input type="hidden" name="return" value="<?php echo base64_encode($returnUrl); ?>">

        <?php if (!$user || $user->id === 0) : ?>
            <div class="mb-2">
                <label for="lcomment-guest-name"><?php echo Text::_('COM_LCOMMENT_FORM_NAME_LABEL'); ?></label>
                <input type="text" id="lcomment-guest-name" name="guest_name" class="form-control" value="<?php echo htmlspecialchars($prefillGuestName); ?>">
            </div>
            <div class="mb-2">
                <label for="lcomment-guest-email"><?php echo Text::_('COM_LCOMMENT_FORM_EMAIL_LABEL'); ?></label>
                <input type="email" id="lcomment-guest-email" name="guest_email" class="form-control" value="<?php echo htmlspecialchars($prefillGuestEmail); ?>">
            </div>
        <?php endif; ?>

        <div class="mb-2">
            <label for="lcomment-comment-text"><?php echo Text::_('COM_LCOMMENT_FORM_TEXT_LABEL'); ?></label>
            <textarea id="lcomment-comment-text" name="comment_text" class="form-control" required><?php echo htmlspecialchars($prefillText); ?></textarea>
        </div>

        <?php echo HTMLHelper::_('form.token'); ?>
        <button type="submit" class="btn btn-primary">
            <?php echo Text::_('COM_LCOMMENT_FORM_SUBMIT_LABEL'); ?>
        </button>
    </form>
</div>

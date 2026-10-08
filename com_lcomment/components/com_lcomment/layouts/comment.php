<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Lcsilva\Component\Lcomment\Administrator\Service\CommentTreeBuilder;

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

$prefillParentId = (int) ($previous['parent_id'] ?? 0);
$prefillText = $previous['text'] ?? '';
$prefillGuestName = $previous['guest_name'] ?? '';
$prefillGuestEmail = $previous['guest_email'] ?? '';

$tree = CommentTreeBuilder::build($items);

// Anonymous closures, not named functions: this file is included via
// LayoutHelper::render() once per onContentAfterDisplay call, and a page
// listing several items (e.g. a blog/category view) triggers that event
// once per item — so the file can be included more than once in the same
// request. A top-level `function` declaration would fatal on the second
// inclusion.
$renderForm = function (int $parentId, string $idSuffix, string $textValue) use (
    $extension,
    $view,
    $itemId,
    $returnUrl,
    $user,
    $prefillGuestName,
    $prefillGuestEmail
): void {
    ?>
    <form method="post" action="<?php echo Route::_('index.php?option=com_lcomment&task=comment.save'); ?>" class="lcomment-form">
        <input type="hidden" name="extension" value="<?php echo htmlspecialchars($extension); ?>">
        <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
        <input type="hidden" name="item_id" value="<?php echo (int) $itemId; ?>">
        <input type="hidden" name="parent_id" value="<?php echo $parentId; ?>">
        <input type="hidden" name="return" value="<?php echo base64_encode($returnUrl); ?>">

        <?php if (!$user || $user->id === 0) : ?>
            <div class="mb-2">
                <label for="lcomment-guest-name-<?php echo $idSuffix; ?>"><?php echo Text::_('COM_LCOMMENT_FORM_NAME_LABEL'); ?></label>
                <input type="text" id="lcomment-guest-name-<?php echo $idSuffix; ?>" name="guest_name" class="form-control" value="<?php echo htmlspecialchars($prefillGuestName); ?>">
            </div>
            <div class="mb-2">
                <label for="lcomment-guest-email-<?php echo $idSuffix; ?>"><?php echo Text::_('COM_LCOMMENT_FORM_EMAIL_LABEL'); ?></label>
                <input type="email" id="lcomment-guest-email-<?php echo $idSuffix; ?>" name="guest_email" class="form-control" value="<?php echo htmlspecialchars($prefillGuestEmail); ?>">
            </div>
        <?php endif; ?>

        <div class="mb-2">
            <label for="lcomment-comment-text-<?php echo $idSuffix; ?>"><?php echo Text::_('COM_LCOMMENT_FORM_TEXT_LABEL'); ?></label>
            <textarea id="lcomment-comment-text-<?php echo $idSuffix; ?>" name="comment_text" class="form-control" required><?php echo htmlspecialchars($textValue); ?></textarea>
        </div>

        <?php echo HTMLHelper::_('form.token'); ?>
        <button type="submit" class="btn btn-primary">
            <?php echo Text::_('COM_LCOMMENT_FORM_SUBMIT_LABEL'); ?>
        </button>
    </form>
    <?php
};

$renderNode = function (array $node, int $depth) use (&$renderNode, $renderForm, $prefillParentId, $prefillText): void {
    $comment = $node['comment'];
    $commentId = (int) $comment->id;
    $depthClass = 'lcomment-depth-' . min($depth, 5);
    $isReplyOpen = $prefillParentId === $commentId;
    ?>
    <li class="lcomment-item <?php echo $depthClass; ?>" data-id="<?php echo $commentId; ?>">
        <strong>
            <?php echo htmlspecialchars($comment->guest_name ?: ('#' . (int) $comment->user_id)); ?>
        </strong>
        <?php if ((int) $comment->state === 0) : ?>
            <span class="badge bg-warning"><?php echo Text::_('COM_LCOMMENT_LIST_PENDING_BADGE'); ?></span>
        <?php endif; ?>
        <p><?php echo htmlspecialchars((string) $comment->comment_text); ?></p>

        <details class="lcomment-reply"<?php echo $isReplyOpen ? ' open' : ''; ?>>
            <summary><?php echo Text::_('COM_LCOMMENT_REPLY_LABEL'); ?></summary>
            <?php $renderForm($commentId, (string) $commentId, $isReplyOpen ? $prefillText : ''); ?>
        </details>

        <?php if (!empty($node['replies'])) : ?>
            <ul class="lcomment-list list-unstyled">
                <?php foreach ($node['replies'] as $child) : ?>
                    <?php $renderNode($child, $depth + 1); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </li>
    <?php
};
?>
<div class="lcomment-block">
    <ul class="lcomment-list list-unstyled">
        <?php if (empty($tree)) : ?>
            <li><?php echo Text::_('COM_LCOMMENT_LIST_EMPTY'); ?></li>
        <?php endif; ?>
        <?php foreach ($tree as $node) : ?>
            <?php $renderNode($node, 1); ?>
        <?php endforeach; ?>
    </ul>

    <?php $renderForm(0, 'top', $prefillParentId === 0 ? $prefillText : ''); ?>
</div>

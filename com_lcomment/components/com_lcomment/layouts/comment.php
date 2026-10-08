<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Lcsilva\Component\Lcomment\Administrator\Service\CommentTreeBuilder;
use Lcsilva\Component\Lcomment\Administrator\Service\ReactionToggle;
use Lcsilva\Component\Lcomment\Administrator\Service\VoteToggle;

/**
 * Expected keys in $displayData (array or object):
 * @var string $extension
 * @var string $view
 * @var int    $itemId
 * @var array  $items
 * @var array  $reactions
 * @var array  $votes
 * @var array  $authorNames
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

$reactionEmoji = [
    'like' => '👍',
    'love' => '❤️',
    'haha' => '😂',
    'wow' => '😮',
    'sad' => '😢',
    'angry' => '😡',
];

$voteLabel = [
    'helpful' => '👍 ' . Text::_('COM_LCOMMENT_VOTE_HELPFUL_LABEL'),
    'unhelpful' => '👎 ' . Text::_('COM_LCOMMENT_VOTE_UNHELPFUL_LABEL'),
];

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

$renderReactions = function (int $commentId) use ($returnUrl, $reactions, $reactionEmoji): void {
    $mine = $reactions[$commentId]['mine'] ?? null;
    $counts = $reactions[$commentId]['counts'] ?? [];
    ?>
    <div class="lcomment-reactions" data-comment-id="<?php echo $commentId; ?>">
        <?php foreach (ReactionToggle::VALID_TYPES as $type) : ?>
            <?php $isMine = $mine === $type; ?>
            <form method="post" action="<?php echo Route::_('index.php?option=com_lcomment&task=reaction.save'); ?>" class="lcomment-reaction-form<?php echo $isMine ? ' lcomment-reaction-active' : ''; ?>">
                <input type="hidden" name="comment_id" value="<?php echo $commentId; ?>">
                <input type="hidden" name="reaction_type" value="<?php echo $type; ?>">
                <input type="hidden" name="return" value="<?php echo base64_encode($returnUrl); ?>">
                <?php echo HTMLHelper::_('form.token'); ?>
                <button type="submit" class="lcomment-reaction-button" aria-pressed="<?php echo $isMine ? 'true' : 'false'; ?>">
                    <span class="lcomment-reaction-emoji"><?php echo $reactionEmoji[$type]; ?></span>
                    <?php if (($counts[$type] ?? 0) > 0) : ?>
                        <span class="lcomment-reaction-count"><?php echo (int) $counts[$type]; ?></span>
                    <?php endif; ?>
                </button>
            </form>
        <?php endforeach; ?>
    </div>
    <?php
};

$renderVotes = function (int $commentId) use ($returnUrl, $votes, $voteLabel): void {
    $mine = $votes[$commentId]['mine'] ?? null;
    $counts = $votes[$commentId]['counts'] ?? [];
    ?>
    <div class="lcomment-votes" data-comment-id="<?php echo $commentId; ?>">
        <?php foreach (VoteToggle::VALID_TYPES as $type) : ?>
            <?php $isMine = $mine === $type; ?>
            <form method="post" action="<?php echo Route::_('index.php?option=com_lcomment&task=vote.save'); ?>" class="lcomment-vote-form<?php echo $isMine ? ' lcomment-vote-active' : ''; ?>">
                <input type="hidden" name="comment_id" value="<?php echo $commentId; ?>">
                <input type="hidden" name="vote_type" value="<?php echo $type; ?>">
                <input type="hidden" name="return" value="<?php echo base64_encode($returnUrl); ?>">
                <?php echo HTMLHelper::_('form.token'); ?>
                <button type="submit" class="lcomment-vote-button" aria-pressed="<?php echo $isMine ? 'true' : 'false'; ?>">
                    <span class="lcomment-vote-label"><?php echo $voteLabel[$type]; ?></span>
                    <span class="lcomment-vote-count"><?php echo (int) ($counts[$type] ?? 0); ?></span>
                </button>
            </form>
        <?php endforeach; ?>
    </div>
    <?php
};

$renderNode = function (array $node, int $depth) use (&$renderNode, $renderForm, $renderReactions, $renderVotes, $prefillParentId, $prefillText, $authorNames): void {
    $comment = $node['comment'];
    $commentId = (int) $comment->id;
    $depthClass = 'lcomment-depth-' . min($depth, 5);
    $isReplyOpen = $prefillParentId === $commentId;
    $authorName = $comment->guest_name ?: ($authorNames[(int) $comment->user_id] ?? ('#' . (int) $comment->user_id));
    ?>
    <li class="lcomment-item <?php echo $depthClass; ?>" data-id="<?php echo $commentId; ?>">
        <strong>
            <?php echo htmlspecialchars($authorName); ?>
        </strong>
        <?php if ((int) $comment->state === 0) : ?>
            <span class="badge bg-warning"><?php echo Text::_('COM_LCOMMENT_LIST_PENDING_BADGE'); ?></span>
        <?php endif; ?>
        <p><?php echo htmlspecialchars((string) $comment->comment_text); ?></p>

        <?php $renderReactions($commentId); ?>
        <?php $renderVotes($commentId); ?>

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

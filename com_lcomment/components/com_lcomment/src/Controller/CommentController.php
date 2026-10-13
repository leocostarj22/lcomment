<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Lcsilva\Component\Lcomment\Administrator\Service\CommentValidator;
use Lcsilva\Component\Lcomment\Administrator\Service\EditStatePolicy;
use Lcsilva\Component\Lcomment\Administrator\Service\GroupPermissionResolver;
use Lcsilva\Component\Lcomment\Administrator\Service\ReplyNotifier;
use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionPolicy;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionRequest;
use Lcsilva\Component\Lcomment\Administrator\Table\CommentTable;

final class CommentController extends BaseController
{
    public function save(): bool
    {
        Session::checkToken('post') or die(Text::_('JINVALID_TOKEN'));

        $app = Factory::getApplication();
        $input = $app->getInput();

        $extension = $input->getCmd('extension', '');
        $view = $input->getCmd('view', '');
        $itemId = $input->getInt('item_id', 0);
        // getInt() already returns 0 for a missing/blank/non-numeric value —
        // an absent parent_id must default to a top-level comment, never
        // block the submission.
        $parentId = $input->getInt('parent_id', 0);
        $text = (string) $input->get('comment_text', '', 'RAW');
        $guestName = $input->getString('guest_name', '');
        $guestEmail = $input->getString('guest_email', '');
        $returnUrl = base64_decode($input->getBase64('return', ''));

        $stateKey = 'com_lcomment.comment.state.' . $extension . '.' . $view . '.' . $itemId;

        /** @var \Lcsilva\Component\Lcomment\Site\Model\CommentModel $model */
        $model = $this->getModel('Comment', 'Site');
        $context = $model->getContext($extension, $view);

        $categoryId = $model->getCategoryId($extension, $itemId);
        $rules = $context !== null ? ScopeEvaluator::decodeRules((string) ($context->params ?? '')) : [];
        $scopeMode = $context !== null ? (string) ($context->scope_mode ?? 'all') : 'all';
        $itemIncluded = ScopeEvaluator::isItemIncluded($scopeMode, $rules, $itemId, $categoryId);
        $parentValid = $parentId === 0 || $model->parentBelongsToItem($parentId, $extension, $view, $itemId);

        $user = $app->getIdentity();
        $params = ComponentHelper::getParams('com_lcomment');

        $permissions = GroupPermissionResolver::resolve(
            $model->getGroupPermissions(),
            $user->getAuthorisedGroups()
        );

        $policyResult = SubmissionPolicy::evaluate(new SubmissionRequest(
            contextActive: $context !== null,
            contextModeration: $context !== null && (bool) $context->moderation,
            userId: $user && $user->id > 0 ? (int) $user->id : null,
            text: $text,
            minLength: (int) $params->get('min_length', 3),
            maxLength: (int) $params->get('max_length', 2000),
            guestName: $guestName,
            guestEmail: $guestEmail,
            itemIncluded: $itemIncluded,
            parentValid: $parentValid,
            isReply: $parentId !== 0,
            canPost: $permissions['can_post'],
            canReply: $permissions['can_reply'],
            autopublish: $permissions['autopublish'],
        ));

        if (!$policyResult->accepted) {
            $app->setUserState($stateKey, [
                'text' => $text,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
                'parent_id' => $parentId,
            ]);

            foreach ($policyResult->errors as $error) {
                $app->enqueueMessage(Text::_($error), 'error');
            }

            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        /** @var CommentTable $table */
        $table = new CommentTable(Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class));
        $table->extension = $extension;
        $table->view = $view;
        $table->item_id = $itemId;
        $table->parent_id = $parentId;
        // Only an internal URL within the DB column's length is kept for
        // later use in a reply-notification email (ReplyNotifier) — a
        // forged external return URL must never be relayed to a third
        // party from the site's own notification sender, and a URL over
        // 500 characters (e.g. one with marketing query params) must
        // never block the comment from being stored.
        $table->item_url = (Uri::isInternal($returnUrl) && \strlen($returnUrl) <= 500) ? $returnUrl : null;
        $table->comment_text = $policyResult->normalizedText;
        $table->state = $policyResult->initialState;
        $table->language = $app->getLanguage()->getTag();
        $table->ip = $input->server->getString('REMOTE_ADDR', '');

        if ($user && $user->id > 0) {
            $table->user_id = $user->id;
        } else {
            $table->guest_name = $guestName;
            $table->guest_email = $guestEmail;
        }

        if (!$table->check() || !$table->store()) {
            $app->setUserState($stateKey, [
                'text' => $text,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
                'parent_id' => $parentId,
            ]);
            $app->enqueueMessage($table->getError(), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        if ($policyResult->initialState === 1) {
            ReplyNotifier::notifyIfNeeded((int) $table->id);
        }

        $app->enqueueMessage(
            Text::_($policyResult->initialState === 1 ? 'COM_LCOMMENT_SAVE_SUCCESS_PUBLISHED' : 'COM_LCOMMENT_SAVE_SUCCESS_PENDING')
        );
        $app->redirect($returnUrl ?: 'index.php');

        return true;
    }

    public function edit(): bool
    {
        Session::checkToken('post') or die(Text::_('JINVALID_TOKEN'));

        $app = Factory::getApplication();
        $input = $app->getInput();
        $returnUrl = base64_decode($input->getBase64('return', ''));
        $user = $app->getIdentity();

        if ($user === null || $user->id === 0) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_NOT_OWNER'), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $commentId = $input->getInt('id', 0);

        /** @var CommentTable $table */
        $table = new CommentTable(Factory::getContainer()->get(DatabaseInterface::class));

        if (!$table->load($commentId) || (int) $table->user_id !== (int) $user->id) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_NOT_OWNER'), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        // A trashed comment is gone — editing it must never be the way
        // it comes back. Only the admin's own publish action (never this
        // site-side endpoint) can move a comment out of state -2.
        if ((int) $table->state === -2) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_NOT_OWNER'), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        /** @var \Lcsilva\Component\Lcomment\Site\Model\CommentModel $model */
        $model = $this->getModel('Comment', 'Site');

        $permissions = GroupPermissionResolver::resolve($model->getGroupPermissions(), $user->getAuthorisedGroups());

        if (!$permissions['can_edit_own']) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED'), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $text = (string) $input->get('comment_text', '', 'RAW');
        $params = ComponentHelper::getParams('com_lcomment');
        $errors = CommentValidator::validate($text, (int) $params->get('min_length', 3), (int) $params->get('max_length', 2000));

        if ($errors !== []) {
            foreach ($errors as $error) {
                $app->enqueueMessage(Text::_($error), 'error');
            }

            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $context = $model->getContext((string) $table->extension, (string) $table->view);
        $contextModeration = $context !== null && (bool) $context->moderation;
        $wasPublished = (int) $table->state === 1;

        $table->comment_text = trim($text);
        $table->state = EditStatePolicy::nextState($wasPublished, $contextModeration, $permissions['autopublish']);
        $table->modified = Factory::getDate()->toSql();

        if (!$table->check() || !$table->store()) {
            $app->enqueueMessage($table->getError(), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $app->enqueueMessage(
            Text::_($table->state === 1 ? 'COM_LCOMMENT_SAVE_SUCCESS_PUBLISHED' : 'COM_LCOMMENT_SAVE_SUCCESS_PENDING')
        );
        $app->redirect($returnUrl ?: 'index.php');

        return true;
    }

    public function delete(): bool
    {
        Session::checkToken('post') or die(Text::_('JINVALID_TOKEN'));

        $app = Factory::getApplication();
        $input = $app->getInput();
        $returnUrl = base64_decode($input->getBase64('return', ''));
        $user = $app->getIdentity();

        if ($user === null || $user->id === 0) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_NOT_OWNER'), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $commentId = $input->getInt('id', 0);

        /** @var CommentTable $table */
        $table = new CommentTable(Factory::getContainer()->get(DatabaseInterface::class));

        if (!$table->load($commentId) || (int) $table->user_id !== (int) $user->id) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_NOT_OWNER'), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        /** @var \Lcsilva\Component\Lcomment\Site\Model\CommentModel $model */
        $model = $this->getModel('Comment', 'Site');

        $permissions = GroupPermissionResolver::resolve($model->getGroupPermissions(), $user->getAuthorisedGroups());

        if (!$permissions['can_delete_own']) {
            $app->enqueueMessage(Text::_('COM_LCOMMENT_ERROR_GROUP_NOT_ALLOWED'), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $table->state = -2;

        if (!$table->store()) {
            $app->enqueueMessage($table->getError(), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $app->enqueueMessage(Text::_('COM_LCOMMENT_DELETE_SUCCESS'));
        $app->redirect($returnUrl ?: 'index.php');

        return true;
    }
}

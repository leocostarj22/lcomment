<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
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
        $text = $input->get('comment_text', '', 'RAW');
        $returnUrl = base64_decode($input->getBase64('return', ''));

        /** @var \Lcsilva\Component\Lcomment\Site\Model\CommentModel $model */
        $model = $this->getModel('Comment', 'Site');
        $context = $model->getContext($extension, $view);

        $user = $app->getIdentity();
        $params = ComponentHelper::getParams('com_lcomment');

        $policyResult = SubmissionPolicy::evaluate(new SubmissionRequest(
            contextActive: $context !== null,
            contextModeration: $context !== null && (bool) $context->moderation,
            guestsAllowed: (bool) $params->get('allow_guests', 1),
            userId: $user && $user->id > 0 ? (int) $user->id : null,
            text: (string) $text,
            minLength: (int) $params->get('min_length', 3),
            maxLength: (int) $params->get('max_length', 2000),
        ));

        if (!$policyResult->accepted) {
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
        $table->comment_text = $text;
        $table->state = $policyResult->initialState;
        $table->language = $app->getLanguage()->getTag();
        $table->ip = $input->server->getString('REMOTE_ADDR', '');

        if ($user && $user->id > 0) {
            $table->user_id = $user->id;
        } else {
            $table->guest_name = $input->getString('guest_name', '');
            $table->guest_email = $input->getString('guest_email', '');
        }

        if (!$table->check() || !$table->store()) {
            $app->enqueueMessage($table->getError(), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $app->enqueueMessage(
            Text::_($policyResult->initialState === 1 ? 'COM_LCOMMENT_SAVE_SUCCESS_PUBLISHED' : 'COM_LCOMMENT_SAVE_SUCCESS_PENDING')
        );
        $app->redirect($returnUrl ?: 'index.php');

        return true;
    }
}

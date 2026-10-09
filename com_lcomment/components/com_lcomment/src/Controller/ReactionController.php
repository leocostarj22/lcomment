<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;
use Lcsilva\Component\Lcomment\Administrator\Service\ReactionToggle;

final class ReactionController extends BaseController
{
    public function save(): bool
    {
        Session::checkToken('post') or die(Text::_('JINVALID_TOKEN'));

        $app = Factory::getApplication();
        $input = $app->getInput();
        $isAjax = $input->server->getString('HTTP_X_LCOMMENT_AJAX', '') !== '';
        $returnUrl = base64_decode($input->getBase64('return', ''));

        $params = ComponentHelper::getParams('com_lcomment');

        if (!(bool) $params->get('enable_reactions', 1)) {
            return $this->fail($app, $isAjax, $returnUrl, 'COM_LCOMMENT_ERROR_REACTIONS_DISABLED');
        }

        $commentId = $input->getInt('comment_id', 0);
        $reactionType = $input->getCmd('reaction_type', '');

        if (!\in_array($reactionType, ReactionToggle::VALID_TYPES, true)) {
            return $this->fail($app, $isAjax, $returnUrl, 'COM_LCOMMENT_ERROR_INVALID_REACTION_TYPE');
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        if (!$this->commentIsPublished($db, $commentId)) {
            return $this->fail($app, $isAjax, $returnUrl, 'COM_LCOMMENT_ERROR_INVALID_REACTION_TARGET');
        }

        $user = $app->getIdentity();
        $userId = $user && $user->id > 0 ? (int) $user->id : null;
        $guestIp = $userId === null ? $input->server->getString('REMOTE_ADDR', '') : '';
        $guestSessionId = $userId === null ? $app->getSession()->getId() : '';

        $existing = $this->findExisting($db, $commentId, $userId, $guestIp, $guestSessionId);
        $decision = ReactionToggle::decide($existing['type'] ?? null, $reactionType);

        $this->applyDecision($db, $decision, $existing['id'] ?? null, $commentId, $userId, $guestIp, $guestSessionId);

        if ($isAjax) {
            /** @var \Lcsilva\Component\Lcomment\Site\Model\CommentModel $model */
            $model = $this->getModel('Comment', 'Site');
            $reactions = $model->getReactionsFor([$commentId]);

            $this->respondJson($app, 200, $reactions[$commentId]);

            return true;
        }

        $app->redirect($returnUrl ?: 'index.php');

        return true;
    }

    private function fail($app, bool $isAjax, string $returnUrl, string $errorKey): bool
    {
        if ($isAjax) {
            $this->respondJson($app, 400, ['error' => $errorKey]);

            return false;
        }

        $app->enqueueMessage(Text::_($errorKey), 'error');
        $app->redirect($returnUrl ?: 'index.php');

        return false;
    }

    private function respondJson($app, int $status, array $payload): void
    {
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('status', $status, true);
        $app->sendHeaders();

        echo json_encode($payload);

        $app->close();
    }

    private function commentIsPublished(DatabaseInterface $db, int $commentId): bool
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__lcomment_comments'))
            ->where($db->quoteName('id') . ' = :commentId')
            ->where($db->quoteName('state') . ' = 1')
            ->bind(':commentId', $commentId, ParameterType::INTEGER);

        $db->setQuery($query);

        return (int) $db->loadResult() > 0;
    }

    /**
     * @return array{id: int, type: string}|array{}
     */
    private function findExisting(DatabaseInterface $db, int $commentId, ?int $userId, string $guestIp, string $guestSessionId): array
    {
        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('reaction_type')])
            ->from($db->quoteName('#__lcomment_reactions'))
            ->where($db->quoteName('comment_id') . ' = :commentId')
            ->bind(':commentId', $commentId, ParameterType::INTEGER);

        if ($userId !== null) {
            $query->where($db->quoteName('user_id') . ' = :userId')
                ->bind(':userId', $userId, ParameterType::INTEGER);
        } else {
            $query->where($db->quoteName('guest_ip') . ' = :guestIp')
                ->where($db->quoteName('guest_session_id') . ' = :guestSessionId')
                ->bind(':guestIp', $guestIp, ParameterType::STRING)
                ->bind(':guestSessionId', $guestSessionId, ParameterType::STRING);
        }

        $db->setQuery($query);

        $row = $db->loadAssoc();

        return $row !== null ? ['id' => (int) $row['id'], 'type' => (string) $row['reaction_type']] : [];
    }

    private function applyDecision(
        DatabaseInterface $db,
        array $decision,
        ?int $existingId,
        int $commentId,
        ?int $userId,
        string $guestIp,
        string $guestSessionId
    ): void {
        if ($decision['action'] === 'delete') {
            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__lcomment_reactions'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $existingId, ParameterType::INTEGER);

            $db->setQuery($query);
            $db->execute();

            return;
        }

        if ($decision['action'] === 'update') {
            $type = $decision['type'];

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__lcomment_reactions'))
                ->set($db->quoteName('reaction_type') . ' = :type')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':type', $type, ParameterType::STRING)
                ->bind(':id', $existingId, ParameterType::INTEGER);

            $db->setQuery($query);
            $db->execute();

            return;
        }

        $type = $decision['type'];
        $created = Factory::getDate()->toSql();
        $userIdType = $userId !== null ? ParameterType::INTEGER : ParameterType::NULL;
        $guestValueType = $userId === null ? ParameterType::STRING : ParameterType::NULL;
        $guestIpValue = $userId === null ? $guestIp : null;
        $guestSessionValue = $userId === null ? $guestSessionId : null;

        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__lcomment_reactions'))
            ->columns([
                $db->quoteName('comment_id'),
                $db->quoteName('user_id'),
                $db->quoteName('guest_ip'),
                $db->quoteName('guest_session_id'),
                $db->quoteName('reaction_type'),
                $db->quoteName('created'),
            ])
            ->values(':commentId, :userId, :guestIp, :guestSessionId, :type, :created')
            ->bind(':commentId', $commentId, ParameterType::INTEGER)
            ->bind(':userId', $userId, $userIdType)
            ->bind(':guestIp', $guestIpValue, $guestValueType)
            ->bind(':guestSessionId', $guestSessionValue, $guestValueType)
            ->bind(':type', $type, ParameterType::STRING)
            ->bind(':created', $created, ParameterType::STRING);

        $db->setQuery($query);

        try {
            $db->execute();
        } catch (ExecutionFailureException $exception) {
            // A concurrent request for the same identity on the same
            // comment (e.g. a rapid double-click, or two overlapping
            // requests from different tabs) can race this INSERT against
            // another one that wins first, tripping the unique keys on
            // (comment_id, user_id) and
            // (comment_id, guest_ip, guest_session_id) — those are what
            // actually guarantee "never two rows for the same identity".
            // If a row for this identity exists now, that race is exactly
            // what happened and there is nothing left to do; any other
            // failure (a real DB/connectivity problem) still has no
            // matching row, so it is rethrown rather than hidden.
            if ($this->findExisting($db, $commentId, $userId, $guestIp, $guestSessionId) === []) {
                throw $exception;
            }
        }
    }
}

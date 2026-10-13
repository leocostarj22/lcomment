<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;
use Lcsilva\Component\Lcomment\Administrator\Service\ReportPolicy;
use Lcsilva\Component\Lcomment\Administrator\Table\CommentTable;

final class ReportController extends AbstractLcommentController
{
    public function save(): bool
    {
        Session::checkToken('post') or die(Text::_('JINVALID_TOKEN'));

        $app = Factory::getApplication();
        $input = $app->getInput();
        $returnUrl = base64_decode($input->getBase64('return', ''));

        $params = ComponentHelper::getParams('com_lcomment');

        if (!(bool) $params->get('enable_reports', 1)) {
            return $this->fail($app, $returnUrl, 'COM_LCOMMENT_ERROR_REPORTS_DISABLED');
        }

        $user = $app->getIdentity();

        if ($user === null || $user->id === 0) {
            return $this->fail($app, $returnUrl, 'COM_LCOMMENT_ERROR_NOT_OWNER');
        }

        $commentId = $input->getInt('comment_id', 0);
        $reason = $input->getCmd('reason', '');
        $reporterId = (int) $user->id;

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $comment = $this->findCommentForReporting($db, $commentId);

        $commentState = $comment['state'] ?? -99;
        $commentAuthorUserId = $comment['user_id'] ?? null;
        $alreadyReported = $this->hasAlreadyReported($db, $commentId, $reporterId);

        $errors = ReportPolicy::canReport($commentState, $commentAuthorUserId, $reporterId, $alreadyReported, $reason);

        if ($errors !== []) {
            return $this->fail($app, $returnUrl, $errors[0]);
        }

        $this->insertReport($db, $commentId, $reporterId, $reason);

        // Never trust the stored param alone — a 0 or negative value
        // (however it got there: direct DB edit, a pre-existing row
        // saved before the form's min="1" was added, or just a browser
        // that doesn't enforce the HTML min hint) would make
        // reachesAutoUnpublishThreshold() true on the very first report
        // from anyone, defeating the whole feature's premise.
        $threshold = max(1, (int) $params->get('report_threshold', 3));
        $total = $this->countReports($db, $commentId);

        if (ReportPolicy::reachesAutoUnpublishThreshold($total, $threshold)) {
            $this->unpublishIfStillPublished($db, $commentId);
        }

        $app->enqueueMessage(Text::_('COM_LCOMMENT_REPORT_SUCCESS'));
        $app->redirect($returnUrl ?: 'index.php');

        return true;
    }

    private function fail($app, string $returnUrl, string $errorKey): bool
    {
        $app->enqueueMessage(Text::_($errorKey), 'error');
        $app->redirect($returnUrl ?: 'index.php');

        return false;
    }

    /**
     * @return array{state: int, user_id: ?int}|null
     */
    private function findCommentForReporting(DatabaseInterface $db, int $commentId): ?array
    {
        $query = $db->getQuery(true)
            ->select([$db->quoteName('state'), $db->quoteName('user_id')])
            ->from($db->quoteName('#__lcomment_comments'))
            ->where($db->quoteName('id') . ' = :commentId')
            ->bind(':commentId', $commentId, ParameterType::INTEGER);

        $db->setQuery($query);

        $row = $db->loadAssoc();

        if ($row === null) {
            return null;
        }

        return [
            'state' => (int) $row['state'],
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
        ];
    }

    private function hasAlreadyReported(DatabaseInterface $db, int $commentId, int $userId): bool
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__lcomment_reports'))
            ->where($db->quoteName('comment_id') . ' = :commentId')
            ->where($db->quoteName('user_id') . ' = :userId')
            ->bind(':commentId', $commentId, ParameterType::INTEGER)
            ->bind(':userId', $userId, ParameterType::INTEGER);

        $db->setQuery($query);

        return (int) $db->loadResult() > 0;
    }

    private function insertReport(DatabaseInterface $db, int $commentId, int $userId, string $reason): void
    {
        $created = Factory::getDate()->toSql();

        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__lcomment_reports'))
            ->columns([
                $db->quoteName('comment_id'),
                $db->quoteName('user_id'),
                $db->quoteName('reason'),
                $db->quoteName('created'),
            ])
            ->values(':commentId, :userId, :reason, :created')
            ->bind(':commentId', $commentId, ParameterType::INTEGER)
            ->bind(':userId', $userId, ParameterType::INTEGER)
            ->bind(':reason', $reason, ParameterType::STRING)
            ->bind(':created', $created, ParameterType::STRING);

        $db->setQuery($query);

        try {
            $db->execute();
        } catch (ExecutionFailureException $exception) {
            // Same race-safety as reactions/votes/notifications since
            // Fase 2c: only swallow the failure if a row for this
            // identity now exists (confirms the UNIQUE KEY caught a
            // concurrent duplicate), otherwise this is a real failure
            // and must not be hidden.
            if (!$this->hasAlreadyReported($db, $commentId, $userId)) {
                throw $exception;
            }
        }
    }

    private function countReports(DatabaseInterface $db, int $commentId): int
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__lcomment_reports'))
            ->where($db->quoteName('comment_id') . ' = :commentId')
            ->bind(':commentId', $commentId, ParameterType::INTEGER);

        $db->setQuery($query);

        return (int) $db->loadResult();
    }

    private function unpublishIfStillPublished(DatabaseInterface $db, int $commentId): void
    {
        $table = new CommentTable($db);

        if (!$table->load($commentId) || (int) $table->state !== 1) {
            return;
        }

        $table->state = 0;
        $table->store();
    }
}

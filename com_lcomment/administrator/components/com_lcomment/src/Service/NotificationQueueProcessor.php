<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * The only code in this component that actually sends mail.
 *
 * Mail::Send() (verified against real joomla-cms 5.4-dev source,
 * libraries/src/Mail/Mail.php, before this plan was written) returns
 * bool — true on success, false on some failure paths (e.g. the
 * auto-TLS retry also failing) — but can ALSO throw
 * MailDisabledException or a phpmailerException on other paths (mail
 * disabled in Global Configuration, the mail() function unavailable).
 * Both must be treated as "this attempt failed," never let one crash
 * the caller (an admin page, or a scheduled task run).
 */
final class NotificationQueueProcessor
{
    private const MAX_ATTEMPTS = 3;

    public static function process(int $limit = 50): int
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $maxAttempts = self::MAX_ATTEMPTS;

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'),
                $db->quoteName('user_id'),
                $db->quoteName('subject'),
                $db->quoteName('body'),
            ])
            ->from($db->quoteName('#__lcomment_notifications'))
            ->where($db->quoteName('sent_at') . ' IS NULL')
            ->where($db->quoteName('attempts') . ' < :maxAttempts')
            ->order($db->quoteName('created') . ' ASC')
            ->setLimit($limit)
            ->bind(':maxAttempts', $maxAttempts, ParameterType::INTEGER);

        $db->setQuery($query);

        $rows = $db->loadObjectList();

        foreach ($rows as $row) {
            self::attemptSend($db, $row);
        }

        return \count($rows);
    }

    private static function attemptSend(DatabaseInterface $db, object $row): void
    {
        $email = self::emailFor($db, (int) $row->user_id);

        if ($email === null) {
            self::recordFailure($db, (int) $row->id);

            return;
        }

        $sent = false;

        try {
            $mailer = Factory::getMailer();
            $mailer->addRecipient($email);
            $mailer->setSubject((string) $row->subject);
            $mailer->setBody((string) $row->body);
            $mailer->isHtml(false);

            $sent = $mailer->Send() === true;
        } catch (\Throwable $exception) {
            $sent = false;
        }

        if ($sent) {
            self::recordSuccess($db, (int) $row->id);

            return;
        }

        self::recordFailure($db, (int) $row->id);
    }

    private static function emailFor(DatabaseInterface $db, int $userId): ?string
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('email'))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $userId, ParameterType::INTEGER);

        $db->setQuery($query);

        $email = $db->loadResult();

        return $email !== null && $email !== '' ? (string) $email : null;
    }

    private static function recordSuccess(DatabaseInterface $db, int $id): void
    {
        $sentAt = Factory::getDate()->toSql();

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__lcomment_notifications'))
            ->set($db->quoteName('sent_at') . ' = :sentAt')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':sentAt', $sentAt, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER);

        $db->setQuery($query);
        $db->execute();
    }

    private static function recordFailure(DatabaseInterface $db, int $id): void
    {
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__lcomment_notifications'))
            ->set($db->quoteName('attempts') . ' = ' . $db->quoteName('attempts') . ' + 1')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $db->setQuery($query);
        $db->execute();
    }
}

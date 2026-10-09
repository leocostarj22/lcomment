<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;

/**
 * Enqueues a reply notification — never sends anything itself. Sending
 * is NotificationQueueProcessor's job alone.
 *
 * notifyIfNeeded() runs from both the site app (reply published
 * immediately) and the admin app (reply published later by a
 * moderator) — $app->get('language') is the ACTIVE application's own
 * language (the admin user's backend language preference when run
 * from the admin app), never the site's configured front-end default,
 * verified against real joomla-cms 6.1-dev source
 * (CMSApplication::initialiseApp(), AdministratorApplication). The
 * real site default is ComponentHelper::getParams('com_languages')->get('site'),
 * which SiteApplication::detectLanguage() itself falls back to. A
 * fresh, isolated Language instance is built from that tag — the
 * shared $app->getLanguage() singleton is never touched, since the
 * site .ini and admin .ini share several keys (e.g.
 * COM_LCOMMENT_SAVE_SUCCESS_PUBLISHED) and reloading it here would
 * change the flash message language for whatever request is already
 * running.
 */
final class ReplyNotifier
{
    private const EXCERPT_LENGTH = 200;

    public static function notifyIfNeeded(int $commentId): void
    {
        $params = ComponentHelper::getParams('com_lcomment');

        if (!(bool) $params->get('enable_reply_notifications', 1)) {
            return;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $reply = self::findComment($db, $commentId);

        if ($reply === null || (int) $reply->parent_id === 0) {
            return;
        }

        $parent = self::findComment($db, (int) $reply->parent_id);

        if ($parent === null) {
            return;
        }

        $parentAuthorUserId = $parent->user_id !== null ? (int) $parent->user_id : null;
        $replyAuthorUserId = $reply->user_id !== null ? (int) $reply->user_id : null;

        $shouldNotify = ReplyNotificationPolicy::shouldNotify(
            (int) $reply->state,
            (int) $reply->parent_id,
            $parentAuthorUserId,
            $replyAuthorUserId,
            self::alreadyQueued($db, $commentId)
        );

        if (!$shouldNotify) {
            return;
        }

        $recipient = self::findUser($db, $parentAuthorUserId);

        if ($recipient === null || $recipient['email'] === '') {
            return;
        }

        $siteLanguageTag = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        $language = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($siteLanguageTag);
        $language->load('com_lcomment', \JPATH_SITE, $siteLanguageTag, true);

        $replyAuthorName = $reply->guest_name !== null && $reply->guest_name !== ''
            ? (string) $reply->guest_name
            : (self::findUser($db, $replyAuthorUserId)['name'] ?? '');

        $commentText = (string) $reply->comment_text;
        $excerpt = mb_substr($commentText, 0, self::EXCERPT_LENGTH);

        if (mb_strlen($commentText) > self::EXCERPT_LENGTH) {
            $excerpt .= '...';
        }

        $url = (string) ($reply->item_url ?? '');
        $siteName = (string) Factory::getApplication()->get('sitename', '');

        $subject = \sprintf($language->_('COM_LCOMMENT_NOTIFICATION_SUBJECT'), $siteName);
        $body = \sprintf($language->_('COM_LCOMMENT_NOTIFICATION_INTRO'), $replyAuthorName)
            . "\n\n" . $excerpt . "\n\n" . $url;

        self::enqueue($db, $commentId, (int) $recipient['id'], $subject, $body, $url);
    }

    private static function findComment(DatabaseInterface $db, int $commentId): ?object
    {
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__lcomment_comments'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $commentId, ParameterType::INTEGER);

        $db->setQuery($query);

        return $db->loadObject() ?: null;
    }

    private static function alreadyQueued(DatabaseInterface $db, int $commentId): bool
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__lcomment_notifications'))
            ->where($db->quoteName('comment_id') . ' = :commentId')
            ->bind(':commentId', $commentId, ParameterType::INTEGER);

        $db->setQuery($query);

        return (int) $db->loadResult() > 0;
    }

    /**
     * @return array{id: int, name: string, email: string}|null
     */
    private static function findUser(DatabaseInterface $db, ?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('name'), $db->quoteName('email')])
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $userId, ParameterType::INTEGER);

        $db->setQuery($query);

        $row = $db->loadAssoc();

        return $row !== null
            ? ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'email' => (string) $row['email']]
            : null;
    }

    private static function enqueue(
        DatabaseInterface $db,
        int $commentId,
        int $userId,
        string $subject,
        string $body,
        string $url
    ): void {
        $created = Factory::getDate()->toSql();

        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__lcomment_notifications'))
            ->columns([
                $db->quoteName('comment_id'),
                $db->quoteName('user_id'),
                $db->quoteName('subject'),
                $db->quoteName('body'),
                $db->quoteName('url'),
                $db->quoteName('created'),
            ])
            ->values(':commentId, :userId, :subject, :body, :url, :created')
            ->bind(':commentId', $commentId, ParameterType::INTEGER)
            ->bind(':userId', $userId, ParameterType::INTEGER)
            ->bind(':subject', $subject, ParameterType::STRING)
            ->bind(':body', $body, ParameterType::STRING)
            ->bind(':url', $url, ParameterType::STRING)
            ->bind(':created', $created, ParameterType::STRING);

        $db->setQuery($query);

        try {
            $db->execute();
        } catch (ExecutionFailureException $exception) {
            // Same race-safety already fixed for reactions/votes after
            // Fase 2c's final review, applied here from day one: only
            // swallow the failure if a row for this comment now exists
            // (confirms it was the UNIQUE KEY doing its job), otherwise
            // this is a real failure and must not be hidden.
            if (!self::alreadyQueued($db, $commentId)) {
                throw $exception;
            }
        }
    }
}

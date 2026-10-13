<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

final class BlacklistChecker
{
    public static function isBlocked(?int $userId, string $ip): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('type'))
            ->from($db->quoteName('#__lcomment_blacklist'));

        $condition = '(' . $db->quoteName('type') . ' = ' . $db->quote('ip')
            . ' AND ' . $db->quoteName('value') . ' = :ip)';

        if ($userId !== null) {
            $condition .= ' OR (' . $db->quoteName('type') . ' = ' . $db->quote('user')
                . ' AND ' . $db->quoteName('value') . ' = :userId)';
        }

        $query->where($condition)
            ->bind(':ip', $ip, ParameterType::STRING);

        if ($userId !== null) {
            $userIdAsString = (string) $userId;
            $query->bind(':userId', $userIdAsString, ParameterType::STRING);
        }

        $types = (array) $db->setQuery($query)->loadColumn();

        return BlacklistPolicy::isBlocked(
            \in_array('user', $types, true),
            \in_array('ip', $types, true)
        );
    }
}

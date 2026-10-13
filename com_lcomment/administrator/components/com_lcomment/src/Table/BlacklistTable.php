<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Lcsilva\Component\Lcomment\Administrator\Service\BlacklistPolicy;

final class BlacklistTable extends Table
{
    public function __construct(DatabaseInterface $db)
    {
        parent::__construct('#__lcomment_blacklist', 'id', $db);
    }

    public function check(): bool
    {
        $errors = BlacklistPolicy::validateEntry((string) $this->type, (string) $this->value);

        if ($errors !== []) {
            // FormController::save() surfaces this via
            // Text::sprintf('JLIB_APPLICATION_ERROR_SAVE_FAILED', ...), which
            // does not translate its argument — resolve the key ourselves or
            // the admin sees the raw language key instead of a message.
            $this->setError(Text::_($errors[0]));

            return false;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName($this->_tbl))
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('value') . ' = :value')
            ->bind(':type', $this->type, ParameterType::STRING)
            ->bind(':value', $this->value, ParameterType::STRING);

        $currentId = (int) $this->id;

        if ($currentId > 0) {
            $query->where($db->quoteName('id') . ' != :id')
                ->bind(':id', $currentId, ParameterType::INTEGER);
        }

        $count = (int) $db->setQuery($query)->loadResult();

        if ($count > 0) {
            $this->setError(Text::_('COM_LCOMMENT_ERROR_DUPLICATE_BLACKLIST_ENTRY'));

            return false;
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;

final class ContextTable extends Table
{
    public function __construct(DatabaseInterface $db)
    {
        parent::__construct('#__lcomment_contexts', 'id', $db);
    }

    public function check(): bool
    {
        $rules = ScopeEvaluator::decodeRules($this->params ?? null);

        if (!ScopeEvaluator::validateRulesForExtension((string) $this->extension, $rules)) {
            // FormController::save() surfaces this via
            // Text::sprintf('JLIB_APPLICATION_ERROR_SAVE_FAILED', ...), which
            // does not translate its argument — resolve the key ourselves or
            // the admin sees the raw language key instead of a message.
            $this->setError(Text::_('COM_LCOMMENT_ERROR_CATEGORY_RULE_REQUIRES_COM_CONTENT'));

            return false;
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Table;

\defined('_JEXEC') or die;

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
        $rules = ScopeEvaluator::decodeRules((string) ($this->params ?? ''));

        if (!ScopeEvaluator::validateRulesForExtension((string) $this->extension, $rules)) {
            $this->setError('COM_LCOMMENT_ERROR_CATEGORY_RULE_REQUIRES_COM_CONTENT');

            return false;
        }

        return true;
    }
}

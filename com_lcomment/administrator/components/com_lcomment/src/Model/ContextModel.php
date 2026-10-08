<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\AdminModel;
use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;

final class ContextModel extends AdminModel
{
    public function getTable($type = 'Context', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_lcomment.context',
            'context',
            ['control' => 'jform', 'load_data' => $loadData]
        );

        return $form instanceof Form ? $form : null;
    }

    public function save($data)
    {
        $rows = \is_array($data['scope_rules'] ?? null) ? $data['scope_rules'] : [];
        unset($data['scope_rules']);

        $rules = [];

        foreach ($rows as $row) {
            if (!\is_array($row) || !isset($row['rule_type'], $row['rule_value'])) {
                continue;
            }

            $rules[] = ['type' => (string) $row['rule_type'], 'value' => (int) $row['rule_value']];
        }

        $data['params'] = json_encode(['scope_rules' => $rules]);

        return parent::save($data);
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_lcomment.edit.context.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        if (\is_object($data)) {
            $rules = ScopeEvaluator::decodeRules((string) ($data->params ?? ''));
            $data->scope_rules = array_map(
                static fn (array $rule): array => ['rule_type' => $rule['type'], 'rule_value' => $rule['value']],
                $rules
            );
        }

        return $data;
    }
}

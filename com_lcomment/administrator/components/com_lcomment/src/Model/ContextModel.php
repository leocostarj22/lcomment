<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\AdminModel;

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

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_lcomment.edit.context.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }
}

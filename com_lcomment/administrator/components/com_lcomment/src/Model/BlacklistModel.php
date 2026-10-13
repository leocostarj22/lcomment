<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\AdminModel;

final class BlacklistModel extends AdminModel
{
    public function getTable($type = 'Blacklist', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_lcomment.blacklist',
            'blacklist',
            ['control' => 'jform', 'load_data' => $loadData]
        );

        return $form instanceof Form ? $form : null;
    }

    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        $isNewEntry = empty($item->id);

        if ($isNewEntry) {
            $input = Factory::getApplication()->getInput();
            $prefillType = $input->getWord('bl_type', '');
            $prefillValue = $input->getString('bl_value', '');

            if ($prefillType !== '') {
                $item->type = $prefillType;
            }

            if ($prefillValue !== '') {
                $item->value = $prefillValue;
            }
        }

        return $item;
    }

    public function save($data)
    {
        if (empty($data['id'])) {
            $data['created_by'] = Factory::getApplication()->getIdentity()->id;
            $data['created'] = Factory::getDate()->toSql();
        }

        return parent::save($data);
    }
}

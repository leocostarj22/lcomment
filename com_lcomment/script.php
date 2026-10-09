<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Registry\Registry;

return new class () implements InstallerScriptInterface {
    private const REGISTERED_GROUP_ID = 2;

    public function install(InstallerAdapter $adapter): bool
    {
        $this->seedGroupPermissions($this->currentAllowGuestsValue());

        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        $this->seedGroupPermissions($this->currentAllowGuestsValue());

        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    private function currentAllowGuestsValue(): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_lcomment'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'));

        $db->setQuery($query);

        $paramsJson = $db->loadResult();

        if ($paramsJson === null) {
            return true;
        }

        $params = new Registry($paramsJson);

        return (bool) $params->get('allow_guests', true);
    }

    private function seedGroupPermissions(bool $allowGuestsForPublic): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $guestGroupId = (int) ComponentHelper::getParams('com_users')->get('guest_usergroup', 1);

        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__usergroups'));

        $db->setQuery($query);

        $groupIds = array_map('intval', $db->loadColumn());

        foreach ($groupIds as $groupId) {
            if ($groupId === $guestGroupId) {
                $row = [
                    'can_post' => $allowGuestsForPublic,
                    'can_reply' => $allowGuestsForPublic,
                    'autopublish' => false,
                    'can_edit_own' => false,
                    'can_delete_own' => false,
                ];
            } elseif ($groupId === self::REGISTERED_GROUP_ID) {
                $row = [
                    'can_post' => true,
                    'can_reply' => true,
                    'autopublish' => false,
                    'can_edit_own' => true,
                    'can_delete_own' => true,
                ];
            } else {
                $row = [
                    'can_post' => true,
                    'can_reply' => true,
                    'autopublish' => true,
                    'can_edit_own' => true,
                    'can_delete_own' => true,
                ];
            }

            $insertQuery = $db->getQuery(true)
                ->insert($db->quoteName('#__lcomment_group_permissions'))
                ->columns([
                    $db->quoteName('group_id'),
                    $db->quoteName('can_post'),
                    $db->quoteName('can_reply'),
                    $db->quoteName('autopublish'),
                    $db->quoteName('can_edit_own'),
                    $db->quoteName('can_delete_own'),
                ])
                ->values(implode(', ', [
                    $groupId,
                    (int) $row['can_post'],
                    (int) $row['can_reply'],
                    (int) $row['autopublish'],
                    (int) $row['can_edit_own'],
                    (int) $row['can_delete_own'],
                ]));

            $db->setQuery($insertQuery);

            try {
                $db->execute();
            } catch (ExecutionFailureException $exception) {
                // PRIMARY KEY violation: this group already has a row,
                // either from a previous run of this same migration or
                // because the admin already configured it by hand —
                // never overwrite an existing, possibly admin-edited row.
            }
        }
    }
};

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

        // Only ever seed once: at a fresh install, or at the single
        // upgrade that introduces this table. A group created after
        // that point must stay deny-by-default (no row at all) until an
        // admin configures it explicitly — re-seeding on every future
        // update (0.7.0, 0.8.0, ...) would instead silently grant it
        // every permission the "else" branch below gives, which is
        // exactly what the spec's deny-by-default guarantee forbids.
        $countQuery = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__lcomment_group_permissions'));

        $db->setQuery($countQuery);

        if ((int) $db->loadResult() > 0) {
            return;
        }

        // Joomla's own installer seeds this as "9" ("Guest"), a child of
        // the root "Public" group (id 1) — confirmed against real
        // joomla-cms 6.1-dev installation/sql/mysql/base.sql. "1" here
        // is only the fallback if the param is ever somehow missing, not
        // a claim that 1 is the real default.
        $guestGroupId = (int) ComponentHelper::getParams('com_users')->get('guest_usergroup', 9);

        $query = $db->getQuery(true)
            ->select([$db->quoteName('id'), $db->quoteName('parent_id')])
            ->from($db->quoteName('#__usergroups'));

        $db->setQuery($query);

        $groups = $db->loadAssocList();

        foreach ($groups as $group) {
            $groupId = (int) $group['id'];
            $parentId = (int) $group['parent_id'];

            if ($parentId === 0) {
                // The root group (normally "Public", id 1) is an
                // ancestor of every other group — User::getAuthorisedGroups()
                // (verified against real joomla-cms 6.1-dev source,
                // Access::getGroupsByUser()) includes ancestors, and
                // GroupPermissionResolver ORs across all of a user's
                // authorised groups. Granting the root group ANY
                // permission here would silently grant it to every
                // user and guest on the site, regardless of their own
                // group's own row. Always deny here; the real guest
                // identity is handled by the $guestGroupId branch below
                // (ordinarily a different, non-root group).
                $row = [
                    'can_post' => false,
                    'can_reply' => false,
                    'autopublish' => false,
                    'can_edit_own' => false,
                    'can_delete_own' => false,
                ];
            } elseif ($groupId === $guestGroupId) {
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

<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;

return new class () implements InstallerScriptInterface {
    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
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
        // The two LComment plugins ship installed-but-disabled by default
        // (standard Joomla behaviour for non-editor plugins). The spec
        // requires them enabled out of the box, so flip them on here.
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('lcomment'))
            ->where(
                $db->quoteName('folder') . ' IN (' . $db->quote('content') . ', ' . $db->quote('system') . ')'
            );

        $db->setQuery($query);
        $db->execute();

        return true;
    }
};

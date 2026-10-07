<?php

declare(strict_types=1);

namespace Lcsilva\Plugin\System\Lcomment\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

final class Lcomment extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [];
    }
}

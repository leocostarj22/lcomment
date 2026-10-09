<?php

declare(strict_types=1);

namespace Lcsilva\Plugin\Task\Lcomment\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;
use Lcsilva\Component\Lcomment\Administrator\Service\NotificationQueueProcessor;

final class Lcomment extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    private const TASKS_MAP = [
        'lcomment.process_notifications' => [
            'langConstPrefix' => 'PLG_TASK_LCOMMENT_PROCESS_NOTIFICATIONS',
            'method' => 'processNotifications',
        ],
    ];

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList' => 'advertiseRoutines',
            'onExecuteTask' => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    private function processNotifications(ExecuteTaskEvent $event): int
    {
        $processed = NotificationQueueProcessor::process();

        $this->logTask(\sprintf('Processed %d notification(s)', $processed));

        return Status::OK;
    }
}

<?php

declare(strict_types=1);

namespace Lcsilva\Plugin\Content\Lcomment\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\Content\AfterDisplayEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;
use Lcsilva\Component\Lcomment\Administrator\Service\ContextResolver;

final class Lcomment extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onContentAfterDisplay' => 'onContentAfterDisplay',
        ];
    }

    public function onContentAfterDisplay(AfterDisplayEvent $event): void
    {
        $context = (string) $event->getArgument('context', '');
        $item = $event->getArgument('item');

        $resolved = ContextResolver::resolve($context);

        if ($resolved === null || empty($item->id)) {
            return;
        }

        $app = Factory::getApplication();

        /** @var MVCFactoryInterface $factory */
        $factory = $app->bootComponent('com_lcomment')->getMVCFactory();

        /** @var \Lcsilva\Component\Lcomment\Site\Model\CommentModel $model */
        $model = $factory->createModel('Comment', 'Site');

        $commentContext = $model->getContext($resolved['extension'], $resolved['view']);

        if ($commentContext === null) {
            return;
        }

        $extension = $resolved['extension'];
        $view = $resolved['view'];
        $itemId = (int) $item->id;
        $items = $model->getItemsFor($extension, $view, $itemId);
        $returnUrl = Uri::getInstance()->toString();

        $app->getDocument()->getWebAssetManager()
            ->useStyle('com_lcomment.comments')
            ->useScript('com_lcomment.comments');

        ob_start();
        require \JPATH_ROOT . '/components/com_lcomment/tmpl/comment/default.php';
        $html = ob_get_clean();

        $event->setArgument('result', $event->getArgument('result', '') . $html);
    }
}

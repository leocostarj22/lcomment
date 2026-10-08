<?php

declare(strict_types=1);

namespace Lcsilva\Plugin\Content\Lcomment\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\Content\AfterDisplayEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;
use Lcsilva\Component\Lcomment\Administrator\Service\ContextResolver;
use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;

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

        $categoryId = $resolved['extension'] === 'com_content' ? ($item->catid ?? null) : null;
        $categoryId = $categoryId !== null ? (int) $categoryId : null;

        $rules = ScopeEvaluator::decodeRules((string) ($commentContext->params ?? ''));
        $scopeMode = (string) ($commentContext->scope_mode ?? 'all');

        if (!ScopeEvaluator::isItemIncluded($scopeMode, $rules, (int) $item->id, $categoryId)) {
            return;
        }

        $extension = $resolved['extension'];
        $view = $resolved['view'];
        $itemId = (int) $item->id;
        $items = $model->getItemsFor($extension, $view, $itemId);
        $returnUrl = Uri::getInstance()->toString();

        $app->getLanguage()->load('com_lcomment', \JPATH_SITE);

        $webAssetManager = $app->getDocument()->getWebAssetManager();
        // The active component here is whatever triggered onContentAfterDisplay
        // (e.g. com_content), not com_lcomment, so its asset registry file is
        // never auto-loaded — register it explicitly before using it.
        $webAssetManager->getRegistry()->addExtensionRegistryFile('com_lcomment');
        $webAssetManager->useStyle('com_lcomment.comments')
            ->useScript('com_lcomment.comments');

        $html = LayoutHelper::render(
            'comment',
            [
                'extension' => $extension,
                'view' => $view,
                'itemId' => $itemId,
                'items' => $items,
                'returnUrl' => $returnUrl,
            ],
            \JPATH_ROOT . '/components/com_lcomment/layouts'
        );

        $event->addResult($html);
    }
}

<?php

declare(strict_types=1);

namespace Lcsilva\Plugin\Content\Lcomment\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
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
        $commentIds = array_map(static fn ($comment) => (int) $comment->id, $items);

        $params = ComponentHelper::getParams('com_lcomment');
        $reactionsEnabled = (bool) $params->get('enable_reactions', 1);
        $votesEnabled = (bool) $params->get('enable_votes', 1);

        // Skip the query entirely when the feature is off, not just the
        // display — no point aggregating data nobody can see or act on.
        $reactions = $reactionsEnabled ? $model->getReactionsFor($commentIds) : [];
        $votes = $votesEnabled ? $model->getVotesFor($commentIds) : [];
        $authorNames = $model->getAuthorNames(array_map(static fn ($comment) => $comment->user_id, $items));
        $returnUrl = Uri::getInstance()->toString();

        $app->getLanguage()->load('com_lcomment', \JPATH_SITE);

        $webAssetManager = $app->getDocument()->getWebAssetManager();
        // The active component here is whatever triggered onContentAfterDisplay
        // (e.g. com_content), not com_lcomment, so its asset registry file is
        // never auto-loaded — register it explicitly before using it.
        $webAssetManager->getRegistry()->addExtensionRegistryFile('com_lcomment');
        $webAssetManager->useStyle('com_lcomment.comments')
            ->useScript('com_lcomment.comments');

        if ($reactionsEnabled) {
            $webAssetManager->useScript('com_lcomment.reactions');
        }

        if ($votesEnabled) {
            $webAssetManager->useScript('com_lcomment.votes');
        }

        $html = LayoutHelper::render(
            'comment',
            [
                'extension' => $extension,
                'view' => $view,
                'itemId' => $itemId,
                'items' => $items,
                'reactions' => $reactions,
                'votes' => $votes,
                'reactionsEnabled' => $reactionsEnabled,
                'votesEnabled' => $votesEnabled,
                'authorNames' => $authorNames,
                'returnUrl' => $returnUrl,
            ],
            \JPATH_ROOT . '/components/com_lcomment/layouts'
        );

        // Some third-party page/content renderers (e.g. a page builder's
        // "dynamic content" addon fetching the article body through its
        // own path) assemble the page without going through Joomla's
        // normal <head> composition, so the useStyle()/useScript() calls
        // above silently never reach the page even though this event
        // still fires and this HTML still gets inserted. Guard with a
        // static flag (once per request — this event can fire more than
        // once per page, e.g. a blog/category view) and fall back to
        // embedding <link>/<script> tags directly next to our own HTML,
        // so the comment UI is never silently unstyled/unscripted
        // regardless of how the surrounding page assembled its <head>.
        static $assetsInlined = false;

        if (!$assetsInlined) {
            $assetsInlined = true;
            $html = self::inlineAssetTags($reactionsEnabled, $votesEnabled) . $html;
        }

        $event->addResult($html);
    }

    private static function inlineAssetTags(bool $reactionsEnabled, bool $votesEnabled): string
    {
        $files = [
            'css/lcomment.css' => 'style',
            'js/lcomment.js' => 'script',
        ];

        if ($reactionsEnabled) {
            $files['js/lcomment-reactions.js'] = 'script';
        }

        if ($votesEnabled) {
            $files['js/lcomment-votes.js'] = 'script';
        }

        $base = Uri::root() . 'media/com_lcomment/';
        $tags = '';

        foreach ($files as $path => $type) {
            $file = \JPATH_ROOT . '/media/com_lcomment/' . $path;
            $version = is_file($file) ? (string) filemtime($file) : '1';
            $url = htmlspecialchars($base . $path . '?v=' . $version);

            $tags .= $type === 'style'
                ? '<link rel="stylesheet" href="' . $url . '">' . "\n"
                : '<script src="' . $url . '" defer></script>' . "\n";
        }

        return $tags;
    }
}

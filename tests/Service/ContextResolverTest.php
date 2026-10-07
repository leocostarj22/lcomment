<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\ContextResolver;
use PHPUnit\Framework\TestCase;

final class ContextResolverTest extends TestCase
{
    public function testResolvesSimpleTwoPartContext(): void
    {
        $result = ContextResolver::resolve('com_content.article');

        self::assertSame(['extension' => 'com_content', 'view' => 'article'], $result);
    }

    public function testResolvesContextWithDottedViewSuffix(): void
    {
        // Some extensions append extra segments, e.g. com_content.article.1
        $result = ContextResolver::resolve('com_content.article.1');

        self::assertSame(['extension' => 'com_content', 'view' => 'article'], $result);
    }

    /**
     * @dataProvider malformedContextProvider
     */
    public function testReturnsNullForMalformedContext(string $context): void
    {
        self::assertNull(ContextResolver::resolve($context));
    }

    public static function malformedContextProvider(): array
    {
        return [
            'empty string' => [''],
            'no dot' => ['comcontent'],
            'only a dot' => ['.'],
            'leading dot' => ['.article'],
        ];
    }
}

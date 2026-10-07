<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class ContextResolver
{
    /**
     * Parses a Joomla content-plugin context string (e.g. "com_content.article")
     * into its extension and view segments.
     *
     * @return array{extension: string, view: string}|null
     */
    public static function resolve(string $context): ?array
    {
        $parts = explode('.', $context);

        if (\count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return [
            'extension' => $parts[0],
            'view' => $parts[1],
        ];
    }
}

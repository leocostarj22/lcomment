<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class ReactionToggle
{
    /**
     * The fixed, non-configurable set of reaction types this sub-delivery
     * supports. This is the authoritative whitelist — callers (controller
     * validation, layout rendering) must check a requested type against
     * this list themselves; decide() below does not revalidate it.
     */
    public const VALID_TYPES = ['like', 'love', 'haha', 'wow', 'sad', 'angry'];

    /**
     * @return array{action: 'insert'|'update'|'delete', type: ?string}
     */
    public static function decide(?string $existingType, string $requestedType): array
    {
        if ($existingType === null) {
            return ['action' => 'insert', 'type' => $requestedType];
        }

        if ($existingType === $requestedType) {
            return ['action' => 'delete', 'type' => null];
        }

        return ['action' => 'update', 'type' => $requestedType];
    }
}

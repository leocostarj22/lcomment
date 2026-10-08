<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

final class ScopeEvaluator
{
    /**
     * Accepts either a JSON-encoded `params` string (as stored in the
     * database and as the site plugin/controller read it) or an
     * already-decoded array (as Joomla's AdminModel::getItem() hands back,
     * since it runs `params` through Registry::toArray() before the admin
     * edit form ever sees it).
     *
     * @return array<int, array{type: string, value: int}>
     */
    public static function decodeRules(string|array|null $params): array
    {
        if ($params === null || $params === '') {
            return [];
        }

        $decoded = \is_array($params) ? $params : json_decode($params, true);

        if (!\is_array($decoded) || !isset($decoded['scope_rules']) || !\is_array($decoded['scope_rules'])) {
            return [];
        }

        $rules = [];

        foreach ($decoded['scope_rules'] as $rule) {
            if (!\is_array($rule) || !isset($rule['type'], $rule['value'])) {
                continue;
            }

            $rules[] = ['type' => (string) $rule['type'], 'value' => (int) $rule['value']];
        }

        return $rules;
    }

    /**
     * @param array<int, array{type: string, value: int}> $rules
     */
    public static function isItemIncluded(string $scopeMode, array $rules, int $itemId, ?int $categoryId): bool
    {
        if ($scopeMode === 'exclude') {
            return !self::anyRuleMatches($rules, $itemId, $categoryId);
        }

        if ($scopeMode === 'include') {
            return self::anyRuleMatches($rules, $itemId, $categoryId);
        }

        // 'all', or any unrecognised/corrupted mode: fail open to Phase 1's
        // unrestricted behaviour rather than silently hiding comments.
        return true;
    }

    /**
     * @param array<int, array{type: string, value: int}> $rules
     */
    public static function validateRulesForExtension(string $extension, array $rules): bool
    {
        if ($extension === 'com_content') {
            return true;
        }

        foreach ($rules as $rule) {
            if ($rule['type'] === 'category') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array{type: string, value: int}> $rules
     */
    private static function anyRuleMatches(array $rules, int $itemId, ?int $categoryId): bool
    {
        foreach ($rules as $rule) {
            if ($rule['type'] === 'item' && $rule['value'] === $itemId) {
                return true;
            }

            if ($rule['type'] === 'category' && $categoryId !== null && $rule['value'] === $categoryId) {
                return true;
            }
        }

        return false;
    }
}

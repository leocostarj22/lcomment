<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\ScopeEvaluator;
use PHPUnit\Framework\TestCase;

final class ScopeEvaluatorTest extends TestCase
{
    // --- decodeRules() ---

    public function testDecodeRulesReturnsEmptyArrayForNull(): void
    {
        self::assertSame([], ScopeEvaluator::decodeRules(null));
    }

    public function testDecodeRulesReturnsEmptyArrayForEmptyString(): void
    {
        self::assertSame([], ScopeEvaluator::decodeRules(''));
    }

    public function testDecodeRulesReturnsEmptyArrayForInvalidJson(): void
    {
        self::assertSame([], ScopeEvaluator::decodeRules('{not valid json'));
    }

    public function testDecodeRulesReturnsEmptyArrayWhenScopeRulesKeyMissing(): void
    {
        self::assertSame([], ScopeEvaluator::decodeRules('{"other_key":1}'));
    }

    public function testDecodeRulesReturnsTheRuleList(): void
    {
        $json = '{"scope_rules":[{"type":"category","value":12},{"type":"item","value":345}]}';

        self::assertSame(
            [
                ['type' => 'category', 'value' => 12],
                ['type' => 'item', 'value' => 345],
            ],
            ScopeEvaluator::decodeRules($json)
        );
    }

    // --- isItemIncluded() ---

    public function testAllModeAlwaysIncludesRegardlessOfRules(): void
    {
        self::assertTrue(ScopeEvaluator::isItemIncluded('all', [], 1, null));
        self::assertTrue(ScopeEvaluator::isItemIncluded('all', [['type' => 'item', 'value' => 999]], 1, null));
    }

    public function testUnknownModeFailsOpenToIncludeEverything(): void
    {
        self::assertTrue(ScopeEvaluator::isItemIncluded('bogus-corrupted-mode', [], 1, null));
        self::assertTrue(ScopeEvaluator::isItemIncluded('bogus-corrupted-mode', [['type' => 'item', 'value' => 1]], 1, null));
    }

    public function testExcludeModeIncludesWhenNoRuleMatches(): void
    {
        $rules = [['type' => 'item', 'value' => 999]];

        self::assertTrue(ScopeEvaluator::isItemIncluded('exclude', $rules, 1, null));
    }

    public function testExcludeModeExcludesWhenItemRuleMatches(): void
    {
        $rules = [['type' => 'item', 'value' => 1]];

        self::assertFalse(ScopeEvaluator::isItemIncluded('exclude', $rules, 1, null));
    }

    public function testExcludeModeExcludesWhenCategoryRuleMatches(): void
    {
        $rules = [['type' => 'category', 'value' => 12]];

        self::assertFalse(ScopeEvaluator::isItemIncluded('exclude', $rules, 1, 12));
    }

    public function testCategoryRuleNeverMatchesWhenCategoryIdIsNull(): void
    {
        $rules = [['type' => 'category', 'value' => 12]];

        self::assertTrue(ScopeEvaluator::isItemIncluded('exclude', $rules, 1, null));
    }

    public function testIncludeModeExcludesEverythingWhenRuleListIsEmpty(): void
    {
        self::assertFalse(ScopeEvaluator::isItemIncluded('include', [], 1, null));
    }

    public function testIncludeModeIncludesOnlyMatchingItem(): void
    {
        $rules = [['type' => 'item', 'value' => 1]];

        self::assertTrue(ScopeEvaluator::isItemIncluded('include', $rules, 1, null));
        self::assertFalse(ScopeEvaluator::isItemIncluded('include', $rules, 2, null));
    }

    public function testIncludeModeIncludesOnlyMatchingCategory(): void
    {
        $rules = [['type' => 'category', 'value' => 12]];

        self::assertTrue(ScopeEvaluator::isItemIncluded('include', $rules, 1, 12));
        self::assertFalse(ScopeEvaluator::isItemIncluded('include', $rules, 1, 99));
    }

    // --- validateRulesForExtension() ---

    public function testValidatesTrueWhenNoCategoryRulesPresent(): void
    {
        $rules = [['type' => 'item', 'value' => 1]];

        self::assertTrue(ScopeEvaluator::validateRulesForExtension('com_k2', $rules));
    }

    public function testValidatesTrueForCategoryRuleOnComContent(): void
    {
        $rules = [['type' => 'category', 'value' => 12]];

        self::assertTrue(ScopeEvaluator::validateRulesForExtension('com_content', $rules));
    }

    public function testValidatesFalseForCategoryRuleOnOtherExtension(): void
    {
        $rules = [['type' => 'category', 'value' => 12]];

        self::assertFalse(ScopeEvaluator::validateRulesForExtension('com_k2', $rules));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Sql;

use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    private const SQL_DIR = __DIR__ . '/../../com_lcomment/administrator/components/com_lcomment/sql';

    public function testInstallSqlCreatesContextsTableWithExpectedColumns(): void
    {
        $sql = file_get_contents(self::SQL_DIR . '/install.mysql.sql');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `#__lcomment_contexts`', $sql);

        foreach (['id', 'extension', 'view', 'published', 'moderation', 'params'] as $column) {
            self::assertMatchesRegularExpression(
                '/`' . $column . '`/',
                $sql,
                "Expected column `{$column}` in #__lcomment_contexts"
            );
        }

        self::assertStringContainsString('UNIQUE', $sql);
    }

    public function testInstallSqlCreatesCommentsTableWithExpectedColumns(): void
    {
        $sql = file_get_contents(self::SQL_DIR . '/install.mysql.sql');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `#__lcomment_comments`', $sql);

        foreach ([
            'id', 'extension', 'view', 'item_id', 'parent_id', 'user_id',
            'guest_name', 'guest_email', 'comment_text', 'state',
            'language', 'ip', 'created', 'modified',
        ] as $column) {
            self::assertMatchesRegularExpression(
                '/`' . $column . '`/',
                $sql,
                "Expected column `{$column}` in #__lcomment_comments"
            );
        }
    }

    public function testUninstallSqlDropsBothTables(): void
    {
        $sql = file_get_contents(self::SQL_DIR . '/uninstall.mysql.sql');

        self::assertStringContainsString('DROP TABLE IF EXISTS `#__lcomment_contexts`', $sql);
        self::assertStringContainsString('DROP TABLE IF EXISTS `#__lcomment_comments`', $sql);
    }
}

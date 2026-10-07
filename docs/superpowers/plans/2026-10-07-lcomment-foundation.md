# LComment Foundation (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship an installable Joomla 5.x package (`pkg_lcomment`) that lets an admin enable comments for any extension/view registered in the backend, and lets visitors/registered users post and (optionally) be moderated on those comments, appearing automatically on the matching frontend pages via the standard `onContentAfterDisplay` content-plugin event.

**Architecture:** One component (`com_lcomment`, admin CRUD for contexts/comments + site submission flow) plus two plugins (`plg_content_lcomment` renders the comment block into matching pages; `plg_system_lcomment` is an empty extension point reserved for Phase 5's buffer-injection fallback). Business rules (context parsing, text validation, guest/moderation policy) live in small framework-free PHP classes so they can be unit-tested with PHPUnit; everything that must extend a Joomla base class (Table/Model/Controller/View) is thin glue verified by lint + a manual Joomla install checklist, since there is no Joomla runtime available in this repo/CI to execute against.

**Tech Stack:** PHP 8.1+, Joomla 5.x CMS API (`Joomla\CMS\*`, `Joomla\DI\*`, `Joomla\Event\*`), MySQL, PHPUnit 10 for the framework-free classes.

**Spec:** `docs/superpowers/specs/2026-10-07-lcomment-foundation-design.md`

## Global Constraints

- Target Joomla 5.x stable, PHP 8.1+; avoid APIs already deprecated in 5.x.
- PSR-4 namespace root `Lcsilva\Component\Lcomment`: Joomla auto-appends
  `\Site` for code under `components/com_lcomment/src` and `\Administrator`
  for code under `administrator/components/com_lcomment/src` — do not
  declare two separate `<namespace>` tags, declare it once at the manifest
  root with `path="src"` ([manual.joomla.org manifest](https://manual.joomla.org/docs/building-extensions/components/component-development-tutorial/step01-basic-component/)).
- Plugin namespaces: `Lcsilva\Plugin\Content\Lcomment`,
  `Lcsilva\Plugin\System\Lcomment`.
- Author on every manifest (`<author>`) is exactly `leocostadeveloper`. No
  other name/attribution anywhere in manifests, code comments, or commit
  messages for this project.
- Single installable package `pkg_lcomment` bundling `com_lcomment` +
  `plg_content_lcomment` + `plg_system_lcomment`.
- DB tables are exactly `#__lcomment_comments` and `#__lcomment_contexts`
  with the columns defined in the spec — do not add columns speculatively
  for later phases beyond the `params` JSON column already specified.
- Integration uses the standard Joomla content-plugin event
  `onContentAfterDisplay` via `SubscriberInterface` and the typed
  `Joomla\CMS\Event\Content\AfterDisplayEvent`, confirmed at
  [manual.joomla.org content plugin events](https://manual.joomla.org/docs/building-extensions/plugins/plugin-events/content/).
- Out of scope for every task below: reactions, ratings, real nested
  replies, subscriptions/notifications, BBCode, emoji, theming, blacklist,
  reporting, advanced anti-spam, multilingual filtering logic, migration,
  GDPR tooling, avatars, Web Services API, buffer-injection fallback. Do not
  add hooks, columns, or config fields "for later" beyond what's listed.

## Review Focus

- Empty or whitespace-only comment text must be rejected, not stored as a
  blank comment.
- Comment text longer than the configured maximum must be rejected with an
  error, never silently truncated.
- A guest comment submitted while "allow guest comments" is globally
  disabled must be rejected server-side even if the request is forged
  directly (POST without a session user), not merely hidden in the UI.
- A POST straight to `com_lcomment&task=comment.save` naming a context that
  is not registered or not published must be rejected server-side, not just
  omitted from the rendered page.
- A malformed `context` string reaching the content plugin (missing dot,
  extra dots, empty string) must not throw or break the page render; the
  plugin must no-op gracefully.

---

### Task 1: Manifests and directory skeleton

**Files:**
- Create: `com_lcomment/com_lcomment.xml`
- Create: `com_lcomment/components/com_lcomment/index.html`
- Create: `com_lcomment/administrator/components/com_lcomment/index.html`
- Create: `com_lcomment/media/com_lcomment/index.html`
- Create: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Create: `com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.sys.ini`
- Create: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`
- Create: `com_lcomment/administrator/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.sys.ini`
- Create: `com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`
- Create: `com_lcomment/components/com_lcomment/language/pt-PT/pt-PT.com_lcomment.ini`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: the installable skeleton every later task adds files under;
  the element name `com_lcomment`, the language key prefixes
  `COM_LCOMMENT_*`, and the admin menu view names `contexts` and `comments`
  that Task 7/8's controllers and language keys must match.

- [ ] **Step 1: Create the guard files**

Every real Joomla install folder gets a one-line guard file preventing
directory listing. Create each with this exact content:

```html
<!DOCTYPE html><title></title>
```

at the four paths listed above under "Create".

- [ ] **Step 2: Write the component manifest**

`com_lcomment/com_lcomment.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<extension type="component" version="5.0" method="upgrade">
    <name>com_lcomment</name>
    <creationDate>2026-10-07</creationDate>
    <author>leocostadeveloper</author>
    <copyright></copyright>
    <license>GNU General Public License version 2 or later</license>
    <version>0.1.0</version>
    <description>COM_LCOMMENT_XML_DESCRIPTION</description>
    <element>com_lcomment</element>
    <namespace path="src">Lcsilva\Component\Lcomment</namespace>

    <files folder="components/com_lcomment">
        <folder>src</folder>
        <folder>tmpl</folder>
        <filename>index.html</filename>
    </files>
    <languages folder="components/com_lcomment/language">
        <language tag="en-GB">en-GB/en-GB.com_lcomment.ini</language>
        <language tag="pt-PT">pt-PT/pt-PT.com_lcomment.ini</language>
    </languages>

    <administration>
        <menu>COM_LCOMMENT</menu>
        <submenu>
            <menu view="contexts">COM_LCOMMENT_MENU_CONTEXTS</menu>
            <menu view="comments">COM_LCOMMENT_MENU_COMMENTS</menu>
        </submenu>
        <files folder="administrator/components/com_lcomment">
            <folder>src</folder>
            <folder>tmpl</folder>
            <folder>forms</folder>
            <folder>services</folder>
            <folder>sql</folder>
            <filename>index.html</filename>
            <filename>config.xml</filename>
        </files>
        <languages folder="administrator/components/com_lcomment/language">
            <language tag="en-GB">en-GB/en-GB.com_lcomment.ini</language>
            <language tag="en-GB">en-GB/en-GB.com_lcomment.sys.ini</language>
            <language tag="pt-PT">pt-PT/pt-PT.com_lcomment.ini</language>
            <language tag="pt-PT">pt-PT/pt-PT.com_lcomment.sys.ini</language>
        </languages>
    </administration>

    <media destination="com_lcomment" folder="media">
        <folder>css</folder>
        <folder>js</folder>
        <filename>joomla.asset.json</filename>
        <filename>index.html</filename>
    </media>

    <install>
        <sql>
            <file driver="mysql" charset="utf8">sql/install.mysql.sql</file>
        </sql>
    </install>
    <uninstall>
        <sql>
            <file driver="mysql" charset="utf8">sql/uninstall.mysql.sql</file>
        </sql>
    </uninstall>
</extension>
```

- [ ] **Step 3: Write the sys language file (used by the extensions manager)**

`com_lcomment/administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.sys.ini`:

```ini
COM_LCOMMENT="LComment"
COM_LCOMMENT_XML_DESCRIPTION="Universal comment system for any Joomla extension and view."
```

`.../pt-PT/pt-PT.com_lcomment.sys.ini`:

```ini
COM_LCOMMENT="LComment"
COM_LCOMMENT_XML_DESCRIPTION="Sistema de comentários universal para qualquer extensão e visualização do Joomla."
```

- [ ] **Step 4: Write the admin UI language files**

`.../administrator/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_MENU_CONTEXTS="Contexts"
COM_LCOMMENT_MENU_COMMENTS="Comments"
COM_LCOMMENT_CONTEXTS_TITLE="LComment: Contexts"
COM_LCOMMENT_COMMENTS_TITLE="LComment: Comments"
COM_LCOMMENT_CONTEXT_EXTENSION_LABEL="Extension"
COM_LCOMMENT_CONTEXT_VIEW_LABEL="View"
COM_LCOMMENT_CONTEXT_MODERATION_LABEL="Moderate new comments"
COM_LCOMMENT_CONTEXT_PUBLISHED_LABEL="Status"
COM_LCOMMENT_CONFIG_MIN_LENGTH_LABEL="Minimum comment length"
COM_LCOMMENT_CONFIG_MAX_LENGTH_LABEL="Maximum comment length"
COM_LCOMMENT_CONFIG_ALLOW_GUESTS_LABEL="Allow guest comments"
COM_LCOMMENT_ERROR_TEXT_REQUIRED="Please enter a comment."
COM_LCOMMENT_ERROR_TEXT_TOO_LONG="Your comment is too long."
COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED="Guest comments are not allowed on this site."
COM_LCOMMENT_ERROR_CONTEXT_INACTIVE="Comments are not enabled here."
COM_LCOMMENT_SAVE_SUCCESS_PUBLISHED="Your comment was published."
COM_LCOMMENT_SAVE_SUCCESS_PENDING="Your comment was submitted and is awaiting moderation."
```

`.../pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_MENU_CONTEXTS="Contextos"
COM_LCOMMENT_MENU_COMMENTS="Comentários"
COM_LCOMMENT_CONTEXTS_TITLE="LComment: Contextos"
COM_LCOMMENT_COMMENTS_TITLE="LComment: Comentários"
COM_LCOMMENT_CONTEXT_EXTENSION_LABEL="Extensão"
COM_LCOMMENT_CONTEXT_VIEW_LABEL="Visualização"
COM_LCOMMENT_CONTEXT_MODERATION_LABEL="Moderar novos comentários"
COM_LCOMMENT_CONTEXT_PUBLISHED_LABEL="Estado"
COM_LCOMMENT_CONFIG_MIN_LENGTH_LABEL="Tamanho mínimo do comentário"
COM_LCOMMENT_CONFIG_MAX_LENGTH_LABEL="Tamanho máximo do comentário"
COM_LCOMMENT_CONFIG_ALLOW_GUESTS_LABEL="Permitir comentários de visitantes"
COM_LCOMMENT_ERROR_TEXT_REQUIRED="Por favor, escreva um comentário."
COM_LCOMMENT_ERROR_TEXT_TOO_LONG="O seu comentário é demasiado longo."
COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED="Comentários de visitantes não são permitidos neste site."
COM_LCOMMENT_ERROR_CONTEXT_INACTIVE="Os comentários não estão habilitados aqui."
COM_LCOMMENT_SAVE_SUCCESS_PUBLISHED="O seu comentário foi publicado."
COM_LCOMMENT_SAVE_SUCCESS_PENDING="O seu comentário foi enviado e aguarda moderação."
```

- [ ] **Step 5: Write the site-side language files**

`com_lcomment/components/com_lcomment/language/en-GB/en-GB.com_lcomment.ini`:

```ini
COM_LCOMMENT_FORM_TEXT_LABEL="Your comment"
COM_LCOMMENT_FORM_NAME_LABEL="Name"
COM_LCOMMENT_FORM_EMAIL_LABEL="Email"
COM_LCOMMENT_FORM_SUBMIT_LABEL="Post comment"
COM_LCOMMENT_LIST_EMPTY="No comments yet."
COM_LCOMMENT_LIST_PENDING_BADGE="Pending moderation"
```

`.../pt-PT/pt-PT.com_lcomment.ini`:

```ini
COM_LCOMMENT_FORM_TEXT_LABEL="O seu comentário"
COM_LCOMMENT_FORM_NAME_LABEL="Nome"
COM_LCOMMENT_FORM_EMAIL_LABEL="E-mail"
COM_LCOMMENT_FORM_SUBMIT_LABEL="Publicar comentário"
COM_LCOMMENT_LIST_EMPTY="Ainda não há comentários."
COM_LCOMMENT_LIST_PENDING_BADGE="Aguarda moderação"
```

- [ ] **Step 6: Verify every XML file is well-formed**

Run: `xmllint --noout com_lcomment/com_lcomment.xml`
Expected: no output, exit code 0.

- [ ] **Step 7: Commit**

```bash
git add com_lcomment
git commit -m "feat(lcomment): add component manifest and skeleton"
```

---

### Task 2: Database schema and structural tests

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/sql/install.mysql.sql`
- Create: `com_lcomment/administrator/components/com_lcomment/sql/uninstall.mysql.sql`
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Test: `tests/Sql/SchemaTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: table names `#__lcomment_contexts` and `#__lcomment_comments`
  and their exact column names, which `ContextTable`/`CommentTable` (Task 6)
  and every model/controller after it must match verbatim.

- [ ] **Step 1: Set up PHPUnit**

`composer.json`:

```json
{
    "name": "lcsilva/lcomment",
    "description": "LComment Joomla extension",
    "type": "project",
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload-dev": {
        "psr-4": {
            "Lcsilva\\Component\\Lcomment\\Administrator\\": "com_lcomment/administrator/components/com_lcomment/src/",
            "Tests\\": "tests/"
        }
    }
}
```

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Run: `composer install`
Expected: `vendor/` created, no errors.

- [ ] **Step 2: Write the failing structural test**

`tests/Sql/SchemaTest.php`:

```php
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
```

- [ ] **Step 3: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Sql/SchemaTest.php`
Expected: FAIL — `file_get_contents()` warning / files not found, since the
SQL files don't exist yet.

- [ ] **Step 4: Write the SQL files**

`.../sql/install.mysql.sql`:

```sql
CREATE TABLE IF NOT EXISTS `#__lcomment_contexts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `extension` VARCHAR(100) NOT NULL,
    `view` VARCHAR(100) NOT NULL,
    `published` TINYINT NOT NULL DEFAULT 1,
    `moderation` TINYINT NOT NULL DEFAULT 1,
    `params` TEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_extension_view` (`extension`, `view`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__lcomment_comments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `extension` VARCHAR(100) NOT NULL,
    `view` VARCHAR(100) NOT NULL,
    `item_id` INT UNSIGNED NOT NULL,
    `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `user_id` INT UNSIGNED NULL,
    `guest_name` VARCHAR(150) NULL,
    `guest_email` VARCHAR(254) NULL,
    `comment_text` TEXT NOT NULL,
    `state` TINYINT NOT NULL DEFAULT 0,
    `language` CHAR(7) NOT NULL DEFAULT '*',
    `ip` VARCHAR(45) NULL,
    `created` DATETIME NOT NULL,
    `modified` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_context_state` (`extension`, `view`, `item_id`, `state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`.../sql/uninstall.mysql.sql`:

```sql
DROP TABLE IF EXISTS `#__lcomment_comments`;
DROP TABLE IF EXISTS `#__lcomment_contexts`;
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Sql/SchemaTest.php`
Expected: OK (3 tests, 0 failures).

- [ ] **Step 6: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/sql composer.json phpunit.xml.dist tests/Sql
git commit -m "feat(lcomment): add database schema with structural tests"
```

---

### Task 3: `ContextResolver` domain class (TDD)

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Service/ContextResolver.php`
- Test: `tests/Service/ContextResolverTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Lcsilva\Component\Lcomment\Administrator\Service\ContextResolver::resolve(string $context): ?array`
  returning `['extension' => string, 'view' => string]` or `null` when the
  context string can't be parsed. Task 11 (content plugin) and Task 10
  (site controller, indirectly) depend on this exact signature.

- [ ] **Step 1: Write the failing tests**

`tests/Service/ContextResolverTest.php`:

```php
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
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Service/ContextResolverTest.php`
Expected: FAIL — class `ContextResolver` not found.

- [ ] **Step 3: Implement**

`com_lcomment/administrator/components/com_lcomment/src/Service/ContextResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

\defined('_JEXEC') or die;

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
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Service/ContextResolverTest.php`
Expected: OK (4 tests).

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/ContextResolver.php tests/Service/ContextResolverTest.php
git commit -m "feat(lcomment): add ContextResolver domain class"
```

---

### Task 4: `CommentValidator` domain class (TDD)

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Service/CommentValidator.php`
- Test: `tests/Service/CommentValidatorTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Lcsilva\Component\Lcomment\Administrator\Service\CommentValidator::validate(string $text, int $minLength, int $maxLength): array`
  returning a list of error-message language keys (empty array = valid).
  Task 5 (`SubmissionPolicy`) and Task 10 (site controller) call this.

- [ ] **Step 1: Write the failing tests**

`tests/Service/CommentValidatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\CommentValidator;
use PHPUnit\Framework\TestCase;

final class CommentValidatorTest extends TestCase
{
    public function testValidTextReturnsNoErrors(): void
    {
        $errors = CommentValidator::validate('A perfectly fine comment.', 3, 100);

        self::assertSame([], $errors);
    }

    public function testEmptyTextIsRejected(): void
    {
        $errors = CommentValidator::validate('', 3, 100);

        self::assertSame(['COM_LCOMMENT_ERROR_TEXT_REQUIRED'], $errors);
    }

    public function testWhitespaceOnlyTextIsRejected(): void
    {
        $errors = CommentValidator::validate("   \n\t  ", 3, 100);

        self::assertSame(['COM_LCOMMENT_ERROR_TEXT_REQUIRED'], $errors);
    }

    public function testTextBelowMinimumLengthIsRejected(): void
    {
        $errors = CommentValidator::validate('hi', 3, 100);

        self::assertSame(['COM_LCOMMENT_ERROR_TEXT_REQUIRED'], $errors);
    }

    public function testTextAboveMaximumLengthIsRejected(): void
    {
        $errors = CommentValidator::validate(str_repeat('a', 101), 3, 100);

        self::assertSame(['COM_LCOMMENT_ERROR_TEXT_TOO_LONG'], $errors);
    }

    public function testTextAtExactMaximumLengthIsAccepted(): void
    {
        $errors = CommentValidator::validate(str_repeat('a', 100), 3, 100);

        self::assertSame([], $errors);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Service/CommentValidatorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`com_lcomment/administrator/components/com_lcomment/src/Service/CommentValidator.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

\defined('_JEXEC') or die;

final class CommentValidator
{
    /**
     * @return string[] Language keys of validation errors; empty when valid.
     */
    public static function validate(string $text, int $minLength, int $maxLength): array
    {
        $trimmed = trim($text);

        if (\mb_strlen($trimmed) < max(1, $minLength)) {
            return ['COM_LCOMMENT_ERROR_TEXT_REQUIRED'];
        }

        if (\mb_strlen($trimmed) > $maxLength) {
            return ['COM_LCOMMENT_ERROR_TEXT_TOO_LONG'];
        }

        return [];
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Service/CommentValidatorTest.php`
Expected: OK (6 tests).

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/CommentValidator.php tests/Service/CommentValidatorTest.php
git commit -m "feat(lcomment): add CommentValidator domain class"
```

---

### Task 5: `SubmissionPolicy` domain class (TDD)

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionPolicy.php`
- Test: `tests/Service/SubmissionPolicyTest.php`

**Interfaces:**
- Consumes: `CommentValidator::validate()` (Task 4).
- Produces: `Lcsilva\Component\Lcomment\Administrator\Service\SubmissionPolicy::evaluate(SubmissionRequest $request): SubmissionResult`
  (both classes defined in this task, in the same file's namespace). Task 10
  (site controller) is the only consumer and must match these exact class
  and property names.

- [ ] **Step 1: Write the failing tests**

`tests/Service/SubmissionPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Service;

use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionPolicy;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionRequest;
use PHPUnit\Framework\TestCase;

final class SubmissionPolicyTest extends TestCase
{
    private function request(array $overrides = []): SubmissionRequest
    {
        return new SubmissionRequest(
            contextActive: $overrides['contextActive'] ?? true,
            contextModeration: $overrides['contextModeration'] ?? true,
            guestsAllowed: $overrides['guestsAllowed'] ?? true,
            userId: $overrides['userId'] ?? null,
            text: $overrides['text'] ?? 'A valid comment body.',
            minLength: $overrides['minLength'] ?? 3,
            maxLength: $overrides['maxLength'] ?? 1000,
        );
    }

    public function testRejectsInactiveContext(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['contextActive' => false]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_CONTEXT_INACTIVE'], $result->errors);
    }

    public function testRejectsGuestWhenGuestsNotAllowed(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'guestsAllowed' => false,
            'userId' => null,
        ]));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED'], $result->errors);
    }

    public function testAllowsGuestWhenGuestsAllowed(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'guestsAllowed' => true,
            'userId' => null,
        ]));

        self::assertTrue($result->accepted);
    }

    public function testAllowsLoggedInUserEvenWhenGuestsNotAllowed(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'guestsAllowed' => false,
            'userId' => 42,
        ]));

        self::assertTrue($result->accepted);
    }

    public function testRejectsInvalidText(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['text' => '']));

        self::assertFalse($result->accepted);
        self::assertSame(['COM_LCOMMENT_ERROR_TEXT_REQUIRED'], $result->errors);
    }

    public function testModerationEnabledYieldsPendingState(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['contextModeration' => true]));

        self::assertTrue($result->accepted);
        self::assertSame(0, $result->initialState);
    }

    public function testModerationDisabledYieldsPublishedState(): void
    {
        $result = SubmissionPolicy::evaluate($this->request(['contextModeration' => false]));

        self::assertTrue($result->accepted);
        self::assertSame(1, $result->initialState);
    }

    public function testContextCheckTakesPriorityOverTextErrors(): void
    {
        $result = SubmissionPolicy::evaluate($this->request([
            'contextActive' => false,
            'text' => '',
        ]));

        self::assertSame(['COM_LCOMMENT_ERROR_CONTEXT_INACTIVE'], $result->errors);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Service/SubmissionPolicyTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

`com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Service;

\defined('_JEXEC') or die;

final class SubmissionRequest
{
    public function __construct(
        public readonly bool $contextActive,
        public readonly bool $contextModeration,
        public readonly bool $guestsAllowed,
        public readonly ?int $userId,
        public readonly string $text,
        public readonly int $minLength,
        public readonly int $maxLength,
    ) {
    }
}

final class SubmissionResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly array $errors,
        public readonly int $initialState = 0,
    ) {
    }
}

final class SubmissionPolicy
{
    public static function evaluate(SubmissionRequest $request): SubmissionResult
    {
        if (!$request->contextActive) {
            return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_CONTEXT_INACTIVE']);
        }

        if ($request->userId === null && !$request->guestsAllowed) {
            return new SubmissionResult(false, ['COM_LCOMMENT_ERROR_GUESTS_NOT_ALLOWED']);
        }

        $textErrors = CommentValidator::validate($request->text, $request->minLength, $request->maxLength);

        if ($textErrors !== []) {
            return new SubmissionResult(false, $textErrors);
        }

        return new SubmissionResult(true, [], $request->contextModeration ? 0 : 1);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Service/SubmissionPolicyTest.php`
Expected: OK (8 tests).

- [ ] **Step 5: Run the full suite so far**

Run: `vendor/bin/phpunit`
Expected: OK, all tests from Tasks 2-5 pass.

- [ ] **Step 6: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Service/SubmissionPolicy.php tests/Service/SubmissionPolicyTest.php
git commit -m "feat(lcomment): add SubmissionPolicy domain class"
```

---

### Task 6: Table classes

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Table/ContextTable.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Table/CommentTable.php`

**Interfaces:**
- Consumes: `#__lcomment_contexts` / `#__lcomment_comments` schema (Task 2).
- Produces: `Lcsilva\Component\Lcomment\Administrator\Table\ContextTable` and
  `...\CommentTable`, each constructed with `(DatabaseInterface $db)`, used
  by every Model in Tasks 8-10.

These extend `Joomla\CMS\Table\Table`, which is only available inside a
running Joomla instance, so there is no PHPUnit test here — correctness is
verified by PHP lint now and by the manual checklist in Task 14.

- [ ] **Step 1: Implement `ContextTable`**

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;

final class ContextTable extends Table
{
    public function __construct(DatabaseInterface $db)
    {
        parent::__construct('#__lcomment_contexts', 'id', $db);
    }
}
```

- [ ] **Step 2: Implement `CommentTable`**

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;

final class CommentTable extends Table
{
    public function __construct(DatabaseInterface $db)
    {
        parent::__construct('#__lcomment_comments', 'id', $db);
    }

    public function check(): bool
    {
        if (trim((string) $this->comment_text) === '') {
            $this->setError('COM_LCOMMENT_ERROR_TEXT_REQUIRED');

            return false;
        }

        if (empty($this->created)) {
            $this->created = \Joomla\CMS\Factory::getDate()->toSql();
        }

        if (empty($this->language)) {
            $this->language = '*';
        }

        return true;
    }
}
```

- [ ] **Step 3: Lint**

Run: `php -l com_lcomment/administrator/components/com_lcomment/src/Table/ContextTable.php && php -l com_lcomment/administrator/components/com_lcomment/src/Table/CommentTable.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Table
git commit -m "feat(lcomment): add ContextTable and CommentTable"
```

---

### Task 7: Component service provider and global configuration

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/services/provider.php`
- Create: `com_lcomment/administrator/components/com_lcomment/config.xml`

**Interfaces:**
- Consumes: nothing new.
- Produces: the registered `MVCFactoryInterface`/`ComponentDispatcherFactoryInterface`
  every Controller/Model/View in Tasks 8-10 relies on implicitly (Joomla
  core wires this up when dispatching `option=com_lcomment`); the global
  config fields `min_length`, `max_length`, `allow_guests` (accessed via
  `Joomla\CMS\Component\ComponentHelper::getParams('com_lcomment')`) that
  Task 10's site controller reads.

- [ ] **Step 1: Write the service provider**

Verified against the official tutorial's exact boilerplate
([manual.joomla.org step01](https://manual.joomla.org/docs/building-extensions/components/component-development-tutorial/step01-basic-component/)):

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\Component;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory as ComponentDispatcherFactoryServiceProvider;
use Joomla\CMS\Extension\Service\Provider\MVCFactory as MVCFactoryServiceProvider;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new MVCFactoryServiceProvider('\\Lcsilva\\Component\\Lcomment'));
        $container->registerServiceProvider(new ComponentDispatcherFactoryServiceProvider('\\Lcsilva\\Component\\Lcomment'));

        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                return new Component($container->get(ComponentDispatcherFactoryInterface::class));
            }
        );
    }
};
```

- [ ] **Step 2: Write the global configuration form**

`config.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<config>
    <fieldset name="basic">
        <field
            name="min_length"
            type="number"
            default="3"
            label="COM_LCOMMENT_CONFIG_MIN_LENGTH_LABEL"
        />
        <field
            name="max_length"
            type="number"
            default="2000"
            label="COM_LCOMMENT_CONFIG_MAX_LENGTH_LABEL"
        />
        <field
            name="allow_guests"
            type="radio"
            layout="joomla.form.field.radio.switcher"
            default="1"
            label="COM_LCOMMENT_CONFIG_ALLOW_GUESTS_LABEL"
        >
            <option value="0">JNO</option>
            <option value="1">JYES</option>
        </field>
    </fieldset>
</config>
```

- [ ] **Step 3: Lint**

Run: `php -l com_lcomment/administrator/components/com_lcomment/services/provider.php && xmllint --noout com_lcomment/administrator/components/com_lcomment/config.xml`
Expected: both succeed with no errors.

- [ ] **Step 4: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/services com_lcomment/administrator/components/com_lcomment/config.xml
git commit -m "feat(lcomment): add component service provider and global config"
```

---

### Task 8: Admin — Contexts management (CRUD)

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Model/ContextsModel.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Model/ContextModel.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Controller/ContextsController.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Controller/ContextController.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/View/Contexts/HtmlView.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/View/Context/HtmlView.php`
- Create: `com_lcomment/administrator/components/com_lcomment/tmpl/contexts/default.php`
- Create: `com_lcomment/administrator/components/com_lcomment/tmpl/context/edit.php`
- Create: `com_lcomment/administrator/components/com_lcomment/forms/context.xml`

**Interfaces:**
- Consumes: `ContextTable` (Task 6).
- Produces: the `#__lcomment_contexts` admin CRUD screens. Task 10 (site
  controller) reads this same table directly via its own small query, so no
  PHP interface is produced for later tasks beyond the table itself.

Framework glue extending `AdminModel`/`ListModel`/`AdminController`/
`HtmlView` — no PHPUnit test (needs a live Joomla instance); verified by
lint now and the manual checklist in Task 14.

- [ ] **Step 1: List model**

`src/Model/ContextsModel.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

final class ContextsModel extends ListModel
{
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'extension', 'view', 'published'];
        }

        parent::__construct($config);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__lcomment_contexts'));

        $search = (string) $this->getState('filter.search', '');

        if ($search !== '') {
            $query->where(
                '(' . $db->quoteName('extension') . ' LIKE :search1 OR ' . $db->quoteName('view') . ' LIKE :search2)'
            )
                ->bind(':search1', $search, ParameterType::STRING)
                ->bind(':search2', $search, ParameterType::STRING);
        }

        $ordering = $this->state->get('list.ordering', 'extension');
        $direction = $this->state->get('list.direction', 'ASC');
        $query->order($db->escape($ordering) . ' ' . $db->escape($direction));

        return $query;
    }
}
```

- [ ] **Step 2: Item model**

`src/Model/ContextModel.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\AdminModel;

final class ContextModel extends AdminModel
{
    public function getTable($type = 'Context', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_lcomment.context',
            'context',
            ['control' => 'jform', 'load_data' => $loadData]
        );

        return $form instanceof Form ? $form : null;
    }

    protected function loadFormData()
    {
        $data = $this->getUserState('com_lcomment.edit.context.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }
}
```

- [ ] **Step 3: Controllers**

`src/Controller/ContextsController.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\AdminController;

final class ContextsController extends AdminController
{
    protected $text_prefix = 'COM_LCOMMENT_CONTEXTS';

    public function getModel($name = 'Context', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }
}
```

`src/Controller/ContextController.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\FormController;

final class ContextController extends FormController
{
    protected $text_prefix = 'COM_LCOMMENT_CONTEXT';
}
```

- [ ] **Step 4: Views**

`src/View/Contexts/HtmlView.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\View\Contexts;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

final class HtmlView extends BaseHtmlView
{
    public $items;
    public $pagination;
    public $state;

    public function display($tpl = null)
    {
        $this->items = $this->get('Items');
        $this->pagination = $this->get('Pagination');
        $this->state = $this->get('State');

        ToolbarHelper::title('COM_LCOMMENT_CONTEXTS_TITLE');
        ToolbarHelper::addNew('context.add');
        ToolbarHelper::editList('context.edit');
        ToolbarHelper::publishList('contexts.publish');
        ToolbarHelper::unpublishList('contexts.unpublish');
        ToolbarHelper::deleteList('', 'contexts.delete');

        parent::display($tpl);
    }
}
```

`src/View/Context/HtmlView.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\View\Context;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

final class HtmlView extends BaseHtmlView
{
    public $item;
    public $form;

    public function display($tpl = null)
    {
        $this->item = $this->get('Item');
        $this->form = $this->get('Form');

        ToolbarHelper::title('COM_LCOMMENT_CONTEXTS_TITLE');

        parent::display($tpl);
    }
}
```

- [ ] **Step 5: Templates**

`tmpl/contexts/default.php`:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Contexts\HtmlView $this */
?>
<form action="<?php echo htmlspecialchars(\Joomla\CMS\Uri\Uri::getInstance()->toString()); ?>" method="post" name="adminForm" id="adminForm">
    <table class="table">
        <thead>
            <tr>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_EXTENSION_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_VIEW_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_MODERATION_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_PUBLISHED_LABEL'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->items as $i => $item) : ?>
            <tr>
                <td>
                    <a href="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_lcomment&task=context.edit&id=' . (int) $item->id); ?>">
                        <?php echo htmlspecialchars($item->extension); ?>
                    </a>
                </td>
                <td><?php echo htmlspecialchars($item->view); ?></td>
                <td><?php echo $item->moderation ? Text::_('JYES') : Text::_('JNO'); ?></td>
                <td><?php echo $item->published ? Text::_('JPUBLISHED') : Text::_('JUNPUBLISHED'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php echo $this->pagination->getListFooter(); ?>
    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
```

`tmpl/context/edit.php`:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Context\HtmlView $this */
?>
<form action="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_lcomment&task=context.save'); ?>" method="post" name="adminForm" id="context-form">
    <?php foreach ($this->form->getFieldset() as $field) : ?>
        <div class="mb-3">
            <?php echo $field->renderLabel(); ?>
            <?php echo $field->renderField(); ?>
        </div>
    <?php endforeach; ?>
    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
```

- [ ] **Step 6: Form XML**

`forms/context.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<form>
    <fieldset name="context">
        <field
            name="id"
            type="number"
            default="0"
            label="JGLOBAL_FIELD_ID_LABEL"
            readonly="true"
            class="readonly"
        />
        <field
            name="extension"
            type="text"
            label="COM_LCOMMENT_CONTEXT_EXTENSION_LABEL"
            required="true"
            filter="cmd"
        />
        <field
            name="view"
            type="text"
            label="COM_LCOMMENT_CONTEXT_VIEW_LABEL"
            required="true"
            filter="cmd"
        />
        <field
            name="moderation"
            type="radio"
            layout="joomla.form.field.radio.switcher"
            default="1"
            label="COM_LCOMMENT_CONTEXT_MODERATION_LABEL"
        >
            <option value="0">JNO</option>
            <option value="1">JYES</option>
        </field>
        <field
            name="published"
            type="radio"
            layout="joomla.form.field.radio.switcher"
            default="1"
            label="COM_LCOMMENT_CONTEXT_PUBLISHED_LABEL"
        >
            <option value="0">JNO</option>
            <option value="1">JYES</option>
        </field>
    </fieldset>
</form>
```

- [ ] **Step 7: Lint every PHP file created in this task**

Run:
```bash
for f in com_lcomment/administrator/components/com_lcomment/src/Model/Context*.php \
         com_lcomment/administrator/components/com_lcomment/src/Controller/Context*.php \
         com_lcomment/administrator/components/com_lcomment/src/View/Context*/HtmlView.php \
         com_lcomment/administrator/components/com_lcomment/tmpl/context*/*.php; do
  php -l "$f"
done
xmllint --noout com_lcomment/administrator/components/com_lcomment/forms/context.xml
```
Expected: `No syntax errors detected` for every file, no xmllint output.

- [ ] **Step 8: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Model/Context*.php \
        com_lcomment/administrator/components/com_lcomment/src/Controller/Context*.php \
        com_lcomment/administrator/components/com_lcomment/src/View/Context* \
        com_lcomment/administrator/components/com_lcomment/tmpl/context* \
        com_lcomment/administrator/components/com_lcomment/forms/context.xml
git commit -m "feat(lcomment): add admin Contexts CRUD"
```

---

### Task 9: Admin — Comments moderation list

**Files:**
- Create: `com_lcomment/administrator/components/com_lcomment/src/Model/CommentsModel.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Model/CommentModel.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/Controller/CommentsController.php`
- Create: `com_lcomment/administrator/components/com_lcomment/src/View/Comments/HtmlView.php`
- Create: `com_lcomment/administrator/components/com_lcomment/tmpl/comments/default.php`

**Interfaces:**
- Consumes: `CommentTable` (Task 6).
- Produces: the comments moderation screen (publish/unpublish/trash via the
  inherited `AdminController` batch tasks). Nothing later depends on this
  beyond the table.

`AdminController`'s inherited `publish()`/`unpublish()`/`trash()` batch
tasks call `$this->getModel()` with no arguments, which resolves to the
*singular* model name below (an `AdminModel`, not the `ListModel`) — both
are required, not just the list model.

- [ ] **Step 1: Singular admin model (required by the publish/unpublish/trash batch tasks)**

`src/Model/CommentModel.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\AdminModel;

final class CommentModel extends AdminModel
{
    public function getTable($type = 'Comment', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function getForm($data = [], $loadData = true)
    {
        // Comments are moderated (publish/unpublish/trash), never created
        // or edited through a form in this phase.
        return false;
    }
}
```

- [ ] **Step 2: List model**

`src/Model/CommentsModel.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

final class CommentsModel extends ListModel
{
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'extension', 'view', 'state', 'created'];
        }

        parent::__construct($config);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__lcomment_comments'));

        $search = (string) $this->getState('filter.search', '');

        if ($search !== '') {
            $query->where($db->quoteName('comment_text') . ' LIKE :search')
                ->bind(':search', $search, ParameterType::STRING);
        }

        $state = $this->getState('filter.state', '');

        if ($state !== '') {
            $query->where($db->quoteName('state') . ' = :state')
                ->bind(':state', $state, ParameterType::INTEGER);
        }

        $ordering = $this->state->get('list.ordering', 'created');
        $direction = $this->state->get('list.direction', 'DESC');
        $query->order($db->escape($ordering) . ' ' . $db->escape($direction));

        return $query;
    }
}
```

- [ ] **Step 3: Controller**

`src/Controller/CommentsController.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\AdminController;

final class CommentsController extends AdminController
{
    protected $text_prefix = 'COM_LCOMMENT_COMMENTS';

    public function getModel($name = 'Comment', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }
}
```

- [ ] **Step 4: View**

`src/View/Comments/HtmlView.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Administrator\View\Comments;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

final class HtmlView extends BaseHtmlView
{
    public $items;
    public $pagination;
    public $state;

    public function display($tpl = null)
    {
        $this->items = $this->get('Items');
        $this->pagination = $this->get('Pagination');
        $this->state = $this->get('State');

        ToolbarHelper::title('COM_LCOMMENT_COMMENTS_TITLE');
        ToolbarHelper::publishList('comments.publish');
        ToolbarHelper::unpublishList('comments.unpublish');
        ToolbarHelper::trash('comments.trash');

        parent::display($tpl);
    }
}
```

- [ ] **Step 5: Template**

`tmpl/comments/default.php`:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Lcsilva\Component\Lcomment\Administrator\View\Comments\HtmlView $this */
?>
<form action="<?php echo htmlspecialchars(\Joomla\CMS\Uri\Uri::getInstance()->toString()); ?>" method="post" name="adminForm" id="adminForm">
    <table class="table">
        <thead>
            <tr>
                <th></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_EXTENSION_LABEL'); ?></th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_VIEW_LABEL'); ?></th>
                <th>Author</th>
                <th>Comment</th>
                <th><?php echo Text::_('COM_LCOMMENT_CONTEXT_PUBLISHED_LABEL'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($this->items as $i => $item) : ?>
            <tr>
                <td>
                    <input type="checkbox" name="cid[]" value="<?php echo (int) $item->id; ?>">
                </td>
                <td><?php echo htmlspecialchars($item->extension); ?></td>
                <td><?php echo htmlspecialchars($item->view); ?></td>
                <td><?php echo htmlspecialchars($item->guest_name ?: ('#' . (int) $item->user_id)); ?></td>
                <td><?php echo htmlspecialchars(mb_strimwidth((string) $item->comment_text, 0, 80, '…')); ?></td>
                <td>
                    <?php if ((int) $item->state === 1) : ?>
                        <?php echo Text::_('JPUBLISHED'); ?>
                    <?php elseif ((int) $item->state === -2) : ?>
                        <?php echo Text::_('JTRASHED'); ?>
                    <?php else : ?>
                        <?php echo Text::_('JUNPUBLISHED'); ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php echo $this->pagination->getListFooter(); ?>
    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
```

- [ ] **Step 6: Lint**

Run:
```bash
php -l com_lcomment/administrator/components/com_lcomment/src/Model/CommentsModel.php
php -l com_lcomment/administrator/components/com_lcomment/src/Model/CommentModel.php
php -l com_lcomment/administrator/components/com_lcomment/src/Controller/CommentsController.php
php -l com_lcomment/administrator/components/com_lcomment/src/View/Comments/HtmlView.php
php -l com_lcomment/administrator/components/com_lcomment/tmpl/comments/default.php
```
Expected: `No syntax errors detected` for every file.

- [ ] **Step 7: Commit**

```bash
git add com_lcomment/administrator/components/com_lcomment/src/Model/Comment*.php \
        com_lcomment/administrator/components/com_lcomment/src/Controller/CommentsController.php \
        com_lcomment/administrator/components/com_lcomment/src/View/Comments \
        com_lcomment/administrator/components/com_lcomment/tmpl/comments
git commit -m "feat(lcomment): add admin Comments moderation list"
```

---

### Task 10: Site — comment submission and listing

**Files:**
- Create: `com_lcomment/components/com_lcomment/services/provider.php`
- Create: `com_lcomment/components/com_lcomment/src/Model/CommentModel.php`
- Create: `com_lcomment/components/com_lcomment/src/Controller/CommentController.php`

**Interfaces:**
- Consumes: `ContextTable`/`CommentTable` (Task 6), `SubmissionPolicy` +
  `SubmissionRequest` (Task 5).
- Produces: `CommentModel::getItemsFor(string $extension, string $view, int $itemId): array`
  and `CommentModel::getContext(string $extension, string $view): ?object`,
  both consumed by the shared layout (Task 11) and the content plugin
  (Task 12).

- [ ] **Step 1: Site service provider**

Site components also need their own `ComponentInterface` registration so
Joomla can dispatch `option=com_lcomment` requests coming from the frontend
URL used by the comment form's `action`.

`com_lcomment/components/com_lcomment/services/provider.php`:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\Component;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory as ComponentDispatcherFactoryServiceProvider;
use Joomla\CMS\Extension\Service\Provider\MVCFactory as MVCFactoryServiceProvider;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new MVCFactoryServiceProvider('\\Lcsilva\\Component\\Lcomment'));
        $container->registerServiceProvider(new ComponentDispatcherFactoryServiceProvider('\\Lcsilva\\Component\\Lcomment'));

        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                return new Component($container->get(ComponentDispatcherFactoryInterface::class));
            }
        );
    }
};
```

Add this folder to the manifest: open `com_lcomment/com_lcomment.xml` and
add `<folder>services</folder>` inside the site `<files folder="components/com_lcomment">`
block (alongside the existing `src` and `tmpl` folders).

- [ ] **Step 2: Site model**

`src/Model/CommentModel.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Site\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\ParameterType;

final class CommentModel extends BaseDatabaseModel
{
    public function getContext(string $extension, string $view): ?object
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__lcomment_contexts'))
            ->where($db->quoteName('extension') . ' = :extension')
            ->where($db->quoteName('view') . ' = :view')
            ->where($db->quoteName('published') . ' = 1')
            ->bind(':extension', $extension, ParameterType::STRING)
            ->bind(':view', $view, ParameterType::STRING);

        $db->setQuery($query);

        $result = $db->loadObject();

        return $result ?: null;
    }

    public function getItemsFor(string $extension, string $view, int $itemId): array
    {
        $db = $this->getDatabase();
        $user = \Joomla\CMS\Factory::getApplication()->getIdentity();

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__lcomment_comments'))
            ->where($db->quoteName('extension') . ' = :extension')
            ->where($db->quoteName('view') . ' = :view')
            ->where($db->quoteName('item_id') . ' = :itemId')
            ->bind(':extension', $extension, ParameterType::STRING)
            ->bind(':view', $view, ParameterType::STRING)
            ->bind(':itemId', $itemId, ParameterType::INTEGER)
            ->order($db->quoteName('created') . ' ASC');

        if ($user && $user->id > 0) {
            $query->extendWhere(
                'AND',
                [
                    $db->quoteName('state') . ' = 1',
                    '(' . $db->quoteName('state') . ' = 0 AND ' . $db->quoteName('user_id') . ' = :userId)',
                ],
                'OR'
            )->bind(':userId', $user->id, ParameterType::INTEGER);
        } else {
            $query->where($db->quoteName('state') . ' = 1');
        }

        $db->setQuery($query);

        return $db->loadObjectList() ?: [];
    }
}
```

- [ ] **Step 3: Site controller**

`src/Controller/CommentController.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Component\Lcomment\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionPolicy;
use Lcsilva\Component\Lcomment\Administrator\Service\SubmissionRequest;
use Lcsilva\Component\Lcomment\Administrator\Table\CommentTable;

final class CommentController extends BaseController
{
    public function save(): bool
    {
        Session::checkToken('post') or die(Text::_('JINVALID_TOKEN'));

        $app = Factory::getApplication();
        $input = $app->getInput();

        $extension = $input->getCmd('extension', '');
        $view = $input->getCmd('view', '');
        $itemId = $input->getInt('item_id', 0);
        $text = $input->get('comment_text', '', 'RAW');
        $returnUrl = base64_decode($input->getBase64('return', ''));

        /** @var \Lcsilva\Component\Lcomment\Site\Model\CommentModel $model */
        $model = $this->getModel('Comment', 'Site');
        $context = $model->getContext($extension, $view);

        $user = $app->getIdentity();
        $params = ComponentHelper::getParams('com_lcomment');

        $policyResult = SubmissionPolicy::evaluate(new SubmissionRequest(
            contextActive: $context !== null,
            contextModeration: $context !== null && (bool) $context->moderation,
            guestsAllowed: (bool) $params->get('allow_guests', 1),
            userId: $user && $user->id > 0 ? (int) $user->id : null,
            text: (string) $text,
            minLength: (int) $params->get('min_length', 3),
            maxLength: (int) $params->get('max_length', 2000),
        ));

        if (!$policyResult->accepted) {
            foreach ($policyResult->errors as $error) {
                $app->enqueueMessage(Text::_($error), 'error');
            }

            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        /** @var CommentTable $table */
        $table = new CommentTable(Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class));
        $table->extension = $extension;
        $table->view = $view;
        $table->item_id = $itemId;
        $table->comment_text = $text;
        $table->state = $policyResult->initialState;
        $table->language = $app->getLanguage()->getTag();
        $table->ip = $input->server->getString('REMOTE_ADDR', '');

        if ($user && $user->id > 0) {
            $table->user_id = $user->id;
        } else {
            $table->guest_name = $input->getString('guest_name', '');
            $table->guest_email = $input->getString('guest_email', '');
        }

        if (!$table->check() || !$table->store()) {
            $app->enqueueMessage($table->getError(), 'error');
            $app->redirect($returnUrl ?: 'index.php');

            return false;
        }

        $app->enqueueMessage(
            Text::_($policyResult->initialState === 1 ? 'COM_LCOMMENT_SAVE_SUCCESS_PUBLISHED' : 'COM_LCOMMENT_SAVE_SUCCESS_PENDING')
        );
        $app->redirect($returnUrl ?: 'index.php');

        return true;
    }
}
```

- [ ] **Step 4: Lint**

Run:
```bash
php -l com_lcomment/components/com_lcomment/services/provider.php
php -l com_lcomment/components/com_lcomment/src/Model/CommentModel.php
php -l com_lcomment/components/com_lcomment/src/Controller/CommentController.php
```
Expected: `No syntax errors detected` for every file.

- [ ] **Step 5: Commit**

```bash
git add com_lcomment/components/com_lcomment/services com_lcomment/components/com_lcomment/src com_lcomment/com_lcomment.xml
git commit -m "feat(lcomment): add site comment submission and listing"
```

---

### Task 11: Shared comments layout

**Files:**
- Create: `com_lcomment/components/com_lcomment/tmpl/comment/default.php`

**Interfaces:**
- Consumes: `CommentModel::getItemsFor()` / `getContext()` (Task 10).
- Produces: rendered HTML string, consumed by `plg_content_lcomment`
  (Task 12) by `require`-ing this file with the expected local variables
  `$extension`, `$view`, `$itemId`, `$items`, `$returnUrl` in scope.

- [ ] **Step 1: Write the layout**

`tmpl/comment/default.php`:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * Expected variables in scope:
 * @var string $extension
 * @var string $view
 * @var int    $itemId
 * @var array  $items
 * @var string $returnUrl
 */

$user = Factory::getApplication()->getIdentity();
?>
<div class="lcomment-block">
    <ul class="lcomment-list list-unstyled">
        <?php if (empty($items)) : ?>
            <li><?php echo Text::_('COM_LCOMMENT_LIST_EMPTY'); ?></li>
        <?php endif; ?>
        <?php foreach ($items as $comment) : ?>
            <li class="lcomment-item" data-id="<?php echo (int) $comment->id; ?>">
                <strong>
                    <?php echo htmlspecialchars($comment->guest_name ?: ('#' . (int) $comment->user_id)); ?>
                </strong>
                <?php if ((int) $comment->state === 0) : ?>
                    <span class="badge bg-warning"><?php echo Text::_('COM_LCOMMENT_LIST_PENDING_BADGE'); ?></span>
                <?php endif; ?>
                <p><?php echo htmlspecialchars((string) $comment->comment_text); ?></p>
            </li>
        <?php endforeach; ?>
    </ul>

    <form method="post" action="<?php echo Route::_('index.php?option=com_lcomment&task=comment.save'); ?>" class="lcomment-form">
        <input type="hidden" name="extension" value="<?php echo htmlspecialchars($extension); ?>">
        <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
        <input type="hidden" name="item_id" value="<?php echo (int) $itemId; ?>">
        <input type="hidden" name="return" value="<?php echo base64_encode($returnUrl); ?>">

        <?php if (!$user || $user->id === 0) : ?>
            <div class="mb-2">
                <label><?php echo Text::_('COM_LCOMMENT_FORM_NAME_LABEL'); ?></label>
                <input type="text" name="guest_name" class="form-control">
            </div>
            <div class="mb-2">
                <label><?php echo Text::_('COM_LCOMMENT_FORM_EMAIL_LABEL'); ?></label>
                <input type="email" name="guest_email" class="form-control">
            </div>
        <?php endif; ?>

        <div class="mb-2">
            <label><?php echo Text::_('COM_LCOMMENT_FORM_TEXT_LABEL'); ?></label>
            <textarea name="comment_text" class="form-control" required></textarea>
        </div>

        <?php echo HTMLHelper::_('form.token'); ?>
        <button type="submit" class="btn btn-primary">
            <?php echo Text::_('COM_LCOMMENT_FORM_SUBMIT_LABEL'); ?>
        </button>
    </form>
</div>
```

- [ ] **Step 2: Lint**

Run: `php -l com_lcomment/components/com_lcomment/tmpl/comment/default.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add com_lcomment/components/com_lcomment/tmpl/comment
git commit -m "feat(lcomment): add shared comment list/form layout"
```

---

### Task 12: `plg_content_lcomment`

**Files:**
- Create: `plg_content_lcomment/lcomment.xml`
- Create: `plg_content_lcomment/services/provider.php`
- Create: `plg_content_lcomment/src/Extension/Lcomment.php`
- Create: `plg_content_lcomment/index.html`
- Create: `plg_content_lcomment/language/en-GB/en-GB.plg_content_lcomment.ini`
- Create: `plg_content_lcomment/language/en-GB/en-GB.plg_content_lcomment.sys.ini`
- Create: `plg_content_lcomment/language/pt-PT/pt-PT.plg_content_lcomment.ini`
- Create: `plg_content_lcomment/language/pt-PT/pt-PT.plg_content_lcomment.sys.ini`

**Interfaces:**
- Consumes: `ContextResolver::resolve()` (Task 3), `CommentModel` (Task 10),
  the shared layout (Task 11).
- Produces: the rendered comment block appended after article/content-item
  output on any page whose context is registered and published.

- [ ] **Step 1: Manifest**

`plg_content_lcomment/lcomment.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<extension type="plugin" group="content" method="upgrade">
    <name>plg_content_lcomment</name>
    <creationDate>2026-10-07</creationDate>
    <author>leocostadeveloper</author>
    <license>GNU General Public License version 2 or later</license>
    <version>0.1.0</version>
    <description>PLG_CONTENT_LCOMMENT_XML_DESCRIPTION</description>
    <namespace path="src">Lcsilva\Plugin\Content\Lcomment</namespace>
    <files>
        <folder>services</folder>
        <folder>src</folder>
        <filename>index.html</filename>
    </files>
    <languages folder="language">
        <language tag="en-GB">en-GB/en-GB.plg_content_lcomment.ini</language>
        <language tag="en-GB">en-GB/en-GB.plg_content_lcomment.sys.ini</language>
        <language tag="pt-PT">pt-PT/pt-PT.plg_content_lcomment.ini</language>
        <language tag="pt-PT">pt-PT/pt-PT.plg_content_lcomment.sys.ini</language>
    </languages>
</extension>
```

- [ ] **Step 2: Language files**

`language/en-GB/en-GB.plg_content_lcomment.sys.ini`:
```ini
PLG_CONTENT_LCOMMENT="Content - LComment"
PLG_CONTENT_LCOMMENT_XML_DESCRIPTION="Renders LComment's comment block after content items whose extension/view is registered in LComment."
```
`language/en-GB/en-GB.plg_content_lcomment.ini`: (empty header-only, no runtime strings yet — add a blank file)
```ini
```
`language/pt-PT/pt-PT.plg_content_lcomment.sys.ini`:
```ini
PLG_CONTENT_LCOMMENT="Conteúdo - LComment"
PLG_CONTENT_LCOMMENT_XML_DESCRIPTION="Exibe o bloco de comentários do LComment após itens de conteúdo cuja extensão/visualização esteja registada no LComment."
```
`language/pt-PT/pt-PT.plg_content_lcomment.ini`:
```ini
```

- [ ] **Step 3: Service provider**

`plg_content_lcomment/services/provider.php`:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Lcsilva\Plugin\Content\Lcomment\Extension\Lcomment;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);
                $plugin = new Lcomment(
                    $dispatcher,
                    (array) PluginHelper::getPlugin('content', 'lcomment')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
```

- [ ] **Step 4: Extension class**

`plg_content_lcomment/src/Extension/Lcomment.php`:

```php
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

        ob_start();
        require \JPATH_ROOT . '/components/com_lcomment/tmpl/comment/default.php';
        $html = ob_get_clean();

        $event->setArgument('result', $event->getArgument('result', '') . $html);
    }
}
```

- [ ] **Step 5: Guard file**

`plg_content_lcomment/index.html`:
```html
<!DOCTYPE html><title></title>
```

- [ ] **Step 6: Lint**

Run:
```bash
php -l plg_content_lcomment/services/provider.php
php -l plg_content_lcomment/src/Extension/Lcomment.php
xmllint --noout plg_content_lcomment/lcomment.xml
```
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add plg_content_lcomment
git commit -m "feat(lcomment): add plg_content_lcomment"
```

---

### Task 13: `plg_system_lcomment` and media assets

**Files:**
- Create: `plg_system_lcomment/lcomment.xml`
- Create: `plg_system_lcomment/services/provider.php`
- Create: `plg_system_lcomment/src/Extension/Lcomment.php`
- Create: `plg_system_lcomment/index.html`
- Create: `plg_system_lcomment/language/en-GB/en-GB.plg_system_lcomment.sys.ini`
- Create: `plg_system_lcomment/language/pt-PT/pt-PT.plg_system_lcomment.sys.ini`
- Create: `com_lcomment/media/com_lcomment/css/lcomment.css`
- Create: `com_lcomment/media/com_lcomment/js/lcomment.js`
- Create: `com_lcomment/media/com_lcomment/joomla.asset.json`

**Interfaces:**
- Consumes: nothing.
- Produces: the installed, enabled-by-default `plg_system_lcomment`
  extension point that Phase 5 attaches its buffer-injection fallback to;
  the `com_lcomment.comments` style/script asset names that Task 12's
  layout (via a follow-up edit) and any future layout reference by name.

This phase intentionally does not wire any event on the system plugin — it
exists only so Phase 5 can add behavior without a new install. The content
plugin loads the CSS/JS directly since it already knows when a block is
being rendered.

- [ ] **Step 1: Manifest**

`plg_system_lcomment/lcomment.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<extension type="plugin" group="system" method="upgrade">
    <name>plg_system_lcomment</name>
    <creationDate>2026-10-07</creationDate>
    <author>leocostadeveloper</author>
    <license>GNU General Public License version 2 or later</license>
    <version>0.1.0</version>
    <description>PLG_SYSTEM_LCOMMENT_XML_DESCRIPTION</description>
    <namespace path="src">Lcsilva\Plugin\System\Lcomment</namespace>
    <files>
        <folder>services</folder>
        <folder>src</folder>
        <filename>index.html</filename>
    </files>
    <languages folder="language">
        <language tag="en-GB">en-GB/en-GB.plg_system_lcomment.sys.ini</language>
        <language tag="pt-PT">pt-PT/pt-PT.plg_system_lcomment.sys.ini</language>
    </languages>
</extension>
```

- [ ] **Step 2: Language files**

`language/en-GB/en-GB.plg_system_lcomment.sys.ini`:
```ini
PLG_SYSTEM_LCOMMENT="System - LComment"
PLG_SYSTEM_LCOMMENT_XML_DESCRIPTION="Reserved extension point for LComment's future universal-injection fallback. No behavior in this release."
```
`language/pt-PT/pt-PT.plg_system_lcomment.sys.ini`:
```ini
PLG_SYSTEM_LCOMMENT="Sistema - LComment"
PLG_SYSTEM_LCOMMENT_XML_DESCRIPTION="Ponto de extensão reservado para o futuro fallback de injeção universal do LComment. Sem comportamento nesta versão."
```

- [ ] **Step 3: Service provider and extension class**

`plg_system_lcomment/services/provider.php`:

```php
<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Lcsilva\Plugin\System\Lcomment\Extension\Lcomment;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);
                $plugin = new Lcomment(
                    $dispatcher,
                    (array) PluginHelper::getPlugin('system', 'lcomment')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
```

`plg_system_lcomment/src/Extension/Lcomment.php`:

```php
<?php

declare(strict_types=1);

namespace Lcsilva\Plugin\System\Lcomment\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

final class Lcomment extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [];
    }
}
```

- [ ] **Step 4: Guard file**

`plg_system_lcomment/index.html`:
```html
<!DOCTYPE html><title></title>
```

- [ ] **Step 5: Media assets**

`com_lcomment/media/com_lcomment/css/lcomment.css`:
```css
.lcomment-block {
    margin-top: 1.5rem;
}

.lcomment-item {
    border-bottom: 1px solid #ddd;
    padding: 0.5rem 0;
}
```

`com_lcomment/media/com_lcomment/js/lcomment.js`:
```javascript
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.lcomment-form').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');

            if (button) {
                button.disabled = true;
            }
        });
    });
});
```

`com_lcomment/media/com_lcomment/joomla.asset.json`:
```json
{
    "$schema": "https://developer.joomla.org/schemas/json-schema/web_assets.json",
    "name": "com_lcomment",
    "version": "0.1.0",
    "assets": [
        {
            "name": "com_lcomment.comments",
            "type": "style",
            "uri": "css/lcomment.css"
        },
        {
            "name": "com_lcomment.comments",
            "type": "script",
            "uri": "js/lcomment.js",
            "attributes": {
                "defer": true
            }
        }
    ]
}
```

- [ ] **Step 6: Wire the asset into the content plugin's render**

Edit `plg_content_lcomment/src/Extension/Lcomment.php` (Task 12): inside
`onContentAfterDisplay`, immediately before `ob_start();`, add:

```php
$app->getDocument()->getWebAssetManager()
    ->useStyle('com_lcomment.comments')
    ->useScript('com_lcomment.comments');
```

- [ ] **Step 7: Lint**

Run:
```bash
php -l plg_system_lcomment/services/provider.php
php -l plg_system_lcomment/src/Extension/Lcomment.php
php -l plg_content_lcomment/src/Extension/Lcomment.php
xmllint --noout plg_system_lcomment/lcomment.xml
python3 -c "import json; json.load(open('com_lcomment/media/com_lcomment/joomla.asset.json'))"
```
Expected: no errors; the JSON parses.

- [ ] **Step 8: Commit**

```bash
git add plg_system_lcomment com_lcomment/media com_lcomment/components/com_lcomment  plg_content_lcomment/src/Extension/Lcomment.php
git commit -m "feat(lcomment): add plg_system_lcomment skeleton and media assets"
```

---

### Task 14: Package manifest, build script, and manual acceptance checklist

**Files:**
- Create: `packages/pkg_lcomment.xml`
- Create: `build.sh`

**Interfaces:**
- Consumes: every extension folder built in Tasks 1-13.
- Produces: `dist/pkg_lcomment.zip`, the final installable artifact.

- [ ] **Step 1: Package manifest**

`packages/pkg_lcomment.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<extension type="package" version="5.0" method="upgrade">
    <name>pkg_lcomment</name>
    <creationDate>2026-10-07</creationDate>
    <author>leocostadeveloper</author>
    <license>GNU General Public License version 2 or later</license>
    <version>0.1.0</version>
    <description>PKG_LCOMMENT_XML_DESCRIPTION</description>
    <packagename>lcomment</packagename>
    <files>
        <file type="component" id="com_lcomment">com_lcomment.zip</file>
        <file type="plugin" id="lcomment" group="content">plg_content_lcomment.zip</file>
        <file type="plugin" id="lcomment" group="system">plg_system_lcomment.zip</file>
    </files>
</extension>
```

- [ ] **Step 2: Build script**

`build.sh` (run from repo root):

```bash
#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST="$ROOT/dist"

rm -rf "$DIST"
mkdir -p "$DIST"

( cd "$ROOT/com_lcomment" && zip -r -q "$DIST/com_lcomment.zip" . -x '.*' )
( cd "$ROOT/plg_content_lcomment" && zip -r -q "$DIST/plg_content_lcomment.zip" . -x '.*' )
( cd "$ROOT/plg_system_lcomment" && zip -r -q "$DIST/plg_system_lcomment.zip" . -x '.*' )

cp "$ROOT/packages/pkg_lcomment.xml" "$DIST/pkg_lcomment.xml"
( cd "$DIST" && zip -q pkg_lcomment.zip pkg_lcomment.xml com_lcomment.zip plg_content_lcomment.zip plg_system_lcomment.zip )

echo "Built $DIST/pkg_lcomment.zip"
```

- [ ] **Step 3: Run the build and verify the artifact**

Run: `chmod +x build.sh && ./build.sh`
Expected: `Built dist/pkg_lcomment.zip` printed, and the file exists:
`test -f dist/pkg_lcomment.zip && echo OK`
Expected: `OK`.

- [ ] **Step 4: Run the full automated test suite one more time**

Run: `vendor/bin/phpunit`
Expected: OK, all tests across Tasks 2-5 still pass.

- [ ] **Step 5: Manual acceptance checklist (requires a real Joomla 5.x site)**

This cannot be automated in this repository; perform it against a disposable
Joomla 5.x install and record the result in the PR description:

1. Install `dist/pkg_lcomment.zip` via Extensions → Manage → Install.
   Expect: success message, no errors.
2. Go to Components → LComment → Contexts → New. Set Extension=`com_content`,
   View=`article`, Moderation=Yes, Status=Published. Save & Close.
   Expect: row appears in the Contexts list.
3. Visit any published article on the frontend while logged in.
   Expect: a comment form appears below the article body.
4. Submit a comment as the logged-in user.
   Expect: redirect back to the article with a "submitted and awaiting
   moderation" message; the comment does NOT appear in the list yet.
5. In Components → LComment → Comments, find the comment (state =
   Unpublished) and publish it.
   Expect: reloading the article now shows the comment text.
6. In Options for `com_lcomment`, set "Allow guest comments" = No. Log out
   and reload the article.
   Expect: no name/email fields are required to be hidden, but submitting a
   comment while logged out is rejected with the guests-not-allowed error
   message.
7. Set "Allow guest comments" = Yes again, and set the Context's
   Moderation = No. Submit a new comment while logged out, filling name and
   email.
   Expect: the comment appears immediately on reload, no admin action
   needed.
8. Unpublish the Context (Status = No) in the backend.
   Expect: the comment block disappears from the article entirely.

- [ ] **Step 6: Commit**

```bash
git add packages/pkg_lcomment.xml build.sh
git commit -m "feat(lcomment): add package manifest and build script"
```

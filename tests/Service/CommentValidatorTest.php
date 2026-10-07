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

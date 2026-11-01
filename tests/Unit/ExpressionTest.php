<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WatchTower15\Laravel\Config\Expression;

/** LB3: the comparison strings, with every operator against a string, an int, a float, a bool and null. */
final class ExpressionTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function comparisons(): iterable
    {
        // The subject: name 'eu', n 5, f 1.5, b true, z null.
        yield "string == 'eu'" => ["name == 'eu'", true];
        yield "string == \"us\"" => ['name == "us"', false];
        yield "string != 'us'" => ["name != 'us'", true];
        yield "string > 'ea'" => ["name > 'ea'", true];
        yield "string >= 'eu'" => ["name >= 'eu'", true];
        yield "string < 'eu'" => ["name < 'eu'", false];
        yield "string <= 'eu'" => ["name <= 'eu'", true];
        yield 'int == 5' => ['n == 5', true];
        yield 'int != 5' => ['n != 5', false];
        yield 'int > 4' => ['n > 4', true];
        yield 'int >= 6' => ['n >= 6', false];
        yield 'int < 6' => ['n < 6', true];
        yield 'int <= 4' => ['n <= 4', false];
        yield 'float > 1.4' => ['f > 1.4', true];
        yield 'float == 1.5' => ['f == 1.5', true];
        yield 'float <= 1.5' => ['f <= 1.5', true];
        yield 'int against a float' => ['n == 5.0', true];
        yield 'negative number' => ['n > -1', true];
        yield 'bool == true' => ['b == true', true];
        yield 'bool != true' => ['b != true', false];
        yield 'bool == false' => ['b == false', false];
        yield 'a string is not true' => ["name == true", false];
        yield 'null == null' => ['z == null', true];
        yield 'null != null' => ['z != null', false];
        yield 'a missing path is null' => ['missing == null', true];
        yield 'a string is not a number' => ["name == 5", false];
        yield 'a string != a number' => ["name != 5", true];
        yield 'a number is not a string' => ["n == '5'", false];
        yield 'no spaces' => ['n>=5', true];
        yield 'a nested path' => ['order.total > 9', true];
    }

    #[DataProvider('comparisons')]
    public function testEveryOperatorAgainstEveryLiteral(string $spec, bool $expected): void
    {
        $subject = ['name' => 'eu', 'n' => 5, 'f' => 1.5, 'b' => true, 'z' => null, 'order' => ['total' => 10]];

        self::assertSame($expected, Expression::parse($spec)->evaluate($subject));
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformed(): iterable
    {
        yield 'no literal' => ['n > '];
        yield 'two operators' => ['n >> 3'];
        yield 'a bare word literal' => ['n == five'];
        yield 'ordering against null' => ['n > null'];
        yield 'ordering against true' => ['n < true'];
        yield 'a space in a path' => ['order total'];
        yield 'empty' => [''];
        yield 'an unknown class in a list' => [[\Nope\NotAClass::class]];
        yield 'a list with a number first' => [[5]];
        yield 'a class that is not invokable' => [\stdClass::class . 'X\\Y'];
    }

    #[DataProvider('malformed')]
    public function testAMalformedSpecIsRefused(mixed $spec): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Expression::parse($spec);
    }

    public function testPathsAndLiterals(): void
    {
        self::assertSame(7, Expression::parse('amount')->evaluate(['amount' => 7]));
        self::assertSame(7, Expression::parse(7)->evaluate(null));
        self::assertTrue(Expression::parse(true)->evaluate(null));
        self::assertSame(1.5, Expression::parse(1.5)->evaluate(null));
        self::assertSame('x', Expression::parse('a.b')->evaluate((object) ['a' => (object) ['b' => 'x']]));
    }
}

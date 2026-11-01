<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Config;

use Illuminate\Container\Container;

/**
 * A `when` or `value` of a binding (D4): a dot path read with `data_get`, a literal, a comparison string
 * `<path> <op> <literal>` (`==`, `!=`, `>`, `>=`, `<`, `<=`; the literal a quoted string, a number, `true`, `false` or `null`;
 * equality strict for strings, booleans and null and numeric for numbers), an invokable class name, or
 * `[Class::class, ...constructor arguments]`.
 *
 * @internal
 */
final class Expression
{
    private const PATH = '/\A[A-Za-z0-9_*-]+(?:\.[A-Za-z0-9_*-]+)*\z/';

    private const COMPARISON = '/\A\s*([A-Za-z0-9_*-]+(?:\.[A-Za-z0-9_*-]+)*)\s*(==|!=|>=|<=|>|<)\s*(.+?)\s*\z/s';

    private const CLASS_NAME = '/\A\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+\z/';

    /**
     * @param list<mixed> $arguments
     */
    private function __construct(
        private readonly string $kind,
        private readonly string|int|float|bool $operand,
        private readonly string $operator = '',
        private readonly string|int|float|bool|null $literal = null,
        private readonly array $arguments = [],
    ) {
    }

    /**
     * @throws \InvalidArgumentException with a reason that names no value
     */
    public static function parse(mixed $spec): self
    {
        if (is_bool($spec) || is_int($spec) || is_float($spec)) {
            return new self('literal', $spec);
        }
        if (is_array($spec)) {
            return self::fromList($spec);
        }
        if (! is_string($spec) || trim($spec) === '') {
            throw new \InvalidArgumentException('is not a path, a literal, a comparison or an invokable class');
        }
        if (preg_match(self::COMPARISON, $spec, $m) === 1) {
            [$operator, $literal] = [$m[2], self::literal($m[3])];
            if (($literal === null || is_bool($literal)) && ! in_array($operator, ['==', '!='], true)) {
                throw new \InvalidArgumentException('comparison: only == and != fit true, false and null');
            }

            return new self('compare', $m[1], $operator, $literal);
        }
        if (preg_match(self::PATH, trim($spec)) === 1) {
            return new self('path', trim($spec));
        }
        if (preg_match(self::CLASS_NAME, trim($spec)) === 1) {
            return self::invokable(ltrim(trim($spec), '\\'), []);
        }

        throw new \InvalidArgumentException('is a malformed comparison or an unknown class');
    }

    public function evaluate(mixed $subject): mixed
    {
        switch ($this->kind) {
            case 'literal':
                return $this->operand;
            case 'path':
                return data_get($subject, (string) $this->operand);
            case 'compare':
                return self::compare(data_get($subject, (string) $this->operand), $this->operator, $this->literal);
            default:
                $callable = $this->build();

                return $callable($subject);
        }
    }

    /**
     * @param array<array-key, mixed> $list
     */
    private static function fromList(array $list): self
    {
        $list = array_values($list);
        $class = $list[0] ?? null;
        if (! is_string($class) || preg_match('/\A\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*\z/', $class) !== 1) {
            throw new \InvalidArgumentException('list: the first item must be an invokable class name');
        }

        return self::invokable(ltrim($class, '\\'), array_slice($list, 1));
    }

    /**
     * @param list<mixed> $arguments
     */
    private static function invokable(string $class, array $arguments): self
    {
        if (! class_exists($class)) {
            throw new \InvalidArgumentException('class not found');
        }
        if (! method_exists($class, '__invoke')) {
            throw new \InvalidArgumentException('class is not invokable');
        }

        return new self('invokable', $class, '', null, $arguments);
    }

    private static function literal(string $text): string|int|float|bool|null
    {
        $text = trim($text);
        if ($text === 'true') {
            return true;
        }
        if ($text === 'false') {
            return false;
        }
        if ($text === 'null') {
            return null;
        }
        if (preg_match('/\A(["\'])(.*)\1\z/s', $text, $m) === 1) {
            return $m[2];
        }
        if (preg_match('/\A-?\d+\z/', $text) === 1) {
            return (int) $text;
        }
        if (preg_match('/\A-?\d+\.\d+\z/', $text) === 1) {
            return (float) $text;
        }

        throw new \InvalidArgumentException('comparison: the literal is not a quoted string, a number, true, false or null');
    }

    private static function compare(mixed $actual, string $operator, string|int|float|bool|null $literal): bool
    {
        if (is_int($literal) || is_float($literal)) {
            if (! is_numeric($actual)) {
                return $operator === '!=';
            }
            $left = (float) $actual;
            $right = (float) $literal;

            return match ($operator) {
                '==' => $left === $right,
                '!=' => $left !== $right,
                '>' => $left > $right,
                '>=' => $left >= $right,
                '<' => $left < $right,
                default => $left <= $right,
            };
        }
        if (is_string($literal)) {
            if ($operator === '==') {
                return $actual === $literal;
            }
            if ($operator === '!=') {
                return $actual !== $literal;
            }
            if (! is_string($actual)) {
                return false;
            }
            $order = strcmp($actual, $literal);

            return match ($operator) {
                '>' => $order > 0,
                '>=' => $order >= 0,
                '<' => $order < 0,
                default => $order <= 0,
            };
        }

        return $operator === '==' ? $actual === $literal : $actual !== $literal;
    }

    private function build(): callable
    {
        /** @var class-string $class */
        $class = (string) $this->operand;
        $container = Container::getInstance();
        $parameters = [];
        if ($this->arguments !== []) {
            $constructor = (new \ReflectionClass($class))->getConstructor();
            $declared = $constructor === null ? [] : $constructor->getParameters();
            foreach ($this->arguments as $index => $argument) {
                if (isset($declared[$index])) {
                    $parameters[$declared[$index]->getName()] = $argument;
                }
            }
        }
        $object = $container->make($class, $parameters);

        if (! is_callable($object)) {
            throw new \LogicException('class is not invokable');
        }

        return $object;
    }
}

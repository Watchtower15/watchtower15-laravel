<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Support\Facades\DB;
use WatchTower15\Laravel\Tests\Fixtures\OrderRefunded;
use WatchTower15\Laravel\Tests\Fixtures\RegionGate;
use WatchTower15\Laravel\Tests\Fixtures\Widget;
use WatchTower15\Laravel\Tests\TestCase;

/** LB1, LB2, LB4, LB5: the event bindings on the `array` store. */
final class BindingTest extends TestCase
{
    protected function wt15Config(): array
    {
        $widget = 'eloquent.created: ' . Widget::class;

        return ['metrics' => [
            'orders.refunded' => ['type' => 'count', 'on' => OrderRefunded::class],
            'orders.eu' => ['type' => 'count', 'on' => OrderRefunded::class, 'when' => RegionGate::class],
            'orders.us' => ['type' => 'count', 'on' => OrderRefunded::class, 'when' => [RegionGate::class, 'us']],
            'orders.big' => ['type' => 'count', 'on' => OrderRefunded::class, 'when' => 'amount >= 10', 'value' => 'amount'],
            'widgets.committed' => ['type' => 'count', 'on' => $widget],
            'widgets.immediate' => ['type' => 'count', 'on' => $widget, 'after_commit' => false],
            'widgets.amount' => ['type' => 'count', 'on' => $widget, 'value' => 'amount'],
        ]];
    }

    public function testAClassEventBindingCountsEachEvent(): void
    {
        event(new OrderRefunded());
        event(new OrderRefunded());

        self::assertSame(2, $this->counted('orders.refunded'));
    }

    public function testAnEloquentBindingFollowsTheTransaction(): void
    {
        DB::transaction(static fn (): Widget => Widget::create(['amount' => 0]));
        self::assertSame(1, $this->counted('widgets.committed'));
        self::assertSame(1, $this->counted('widgets.immediate'));

        try {
            DB::transaction(static function (): void {
                Widget::create(['amount' => 0]);

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
            // Expected.
        }
        self::assertSame(1, $this->counted('widgets.committed'), 'a rolled-back transaction counts nothing');
        self::assertSame(2, $this->counted('widgets.immediate'), 'after_commit false counts at once');

        Widget::create(['amount' => 0]);
        self::assertSame(2, $this->counted('widgets.committed'), 'with no transaction the count is written at once');
        self::assertSame(3, $this->counted('widgets.immediate'));
    }

    public function testWhenAsAnInvokableClassAndAsAClassWithArguments(): void
    {
        event(new OrderRefunded(region: 'eu'));
        event(new OrderRefunded(region: 'us'));
        event(new OrderRefunded(region: 'us'));

        self::assertSame(1, $this->counted('orders.eu'));
        self::assertSame(2, $this->counted('orders.us'));
    }

    public function testValueIsReadFromTheSubjectAndWhenCompares(): void
    {
        Widget::create(['amount' => 5]);
        self::assertSame(5, $this->counted('widgets.amount'));

        event(new OrderRefunded(amount: 4));
        self::assertSame(0, $this->counted('orders.big'));
        event(new OrderRefunded(amount: 12));
        self::assertSame(12, $this->counted('orders.big'));
    }
}

<?php

declare(strict_types=1);

use Marko\Broadcasting\BroadcastableInterface;
use Marko\Broadcasting\BroadcasterInterface;
use Marko\Broadcasting\Channel;
use Marko\Broadcasting\PrivateChannel;
use Marko\Testing\Exceptions\AssertionFailedException;
use Marko\Testing\Fake\FakeBroadcaster;

function orderShippedBroadcastable(): BroadcastableInterface
{
    return new readonly class () implements BroadcastableInterface
    {
        public function channels(): array
        {
            return ['orders', new PrivateChannel('orders.7')];
        }

        public function event(): string
        {
            return 'order.shipped';
        }

        public function payload(): array
        {
            return ['id' => 7];
        }
    };
}

describe('FakeBroadcaster', function (): void {
    it('implements BroadcasterInterface', function (): void {
        expect(new FakeBroadcaster())->toBeInstanceOf(BroadcasterInterface::class);
    });

    it('records broadcasts with channel, event, data and id', function (): void {
        $broadcaster = new FakeBroadcaster();

        $broadcaster->broadcast('shows.42', 'seat.sold', ['seat' => 'A1'], 'evt-1');

        expect($broadcaster->broadcasts)->toHaveCount(1)
            ->and($broadcaster->broadcasts[0]['channel'])->toEqual(new Channel('shows.42'))
            ->and($broadcaster->broadcasts[0]['event'])->toBe('seat.sold')
            ->and($broadcaster->broadcasts[0]['data'])->toBe(['seat' => 'A1'])
            ->and($broadcaster->broadcasts[0]['id'])->toBe('evt-1');
    });

    it('records one broadcast per channel when dispatching a broadcastable', function (): void {
        $broadcaster = new FakeBroadcaster();

        $broadcaster->dispatch(orderShippedBroadcastable());

        expect($broadcaster->broadcasts)->toHaveCount(2)
            ->and($broadcaster->broadcasts[0]['channel']->name)->toBe('orders')
            ->and($broadcaster->broadcasts[1]['channel'])->toBeInstanceOf(PrivateChannel::class)
            ->and($broadcaster->broadcasts[1]['data'])->toBe(['id' => 7]);
    });

    it('passes assertBroadcast when the event was broadcast on the channel', function (): void {
        $broadcaster = new FakeBroadcaster();
        $broadcaster->dispatch(orderShippedBroadcastable());

        $broadcaster->assertBroadcast('orders', 'order.shipped');
        $broadcaster->assertBroadcast(
            new PrivateChannel('orders.7'),
            'order.shipped',
            fn (array $data): bool => $data['id'] === 7,
        );

        expect($broadcaster->broadcasts)->toHaveCount(2);
    });

    it('fails assertBroadcast when the callback rejects every match', function (): void {
        $broadcaster = new FakeBroadcaster();
        $broadcaster->broadcast('orders', 'order.shipped', ['id' => 7]);

        expect(
            fn () => $broadcaster->assertBroadcast(
                'orders',
                'order.shipped',
                fn (array $data): bool => $data['id'] === 8,
            ),
        )
            ->toThrow(AssertionFailedException::class);
    });

    it('fails assertBroadcast when nothing matches', function (): void {
        $broadcaster = new FakeBroadcaster();
        $broadcaster->broadcast('orders', 'order.shipped', []);

        expect(fn () => $broadcaster->assertBroadcast('orders', 'order.cancelled'))
            ->toThrow(AssertionFailedException::class, 'order.cancelled')
            ->and(fn () => $broadcaster->assertBroadcast(new PrivateChannel('orders'), 'order.shipped'))
            ->toThrow(AssertionFailedException::class);
    });

    it('passes assertNotBroadcast only when the event was not broadcast on the channel', function (): void {
        $broadcaster = new FakeBroadcaster();
        $broadcaster->broadcast('orders', 'order.shipped', []);

        $broadcaster->assertNotBroadcast('orders', 'order.cancelled');

        expect(fn () => $broadcaster->assertNotBroadcast('orders', 'order.shipped'))
            ->toThrow(AssertionFailedException::class);
    });

    it('checks the number of broadcasts with assertBroadcastCount', function (): void {
        $broadcaster = new FakeBroadcaster();
        $broadcaster->dispatch(orderShippedBroadcastable());

        $broadcaster->assertBroadcastCount(2);

        expect(fn () => $broadcaster->assertBroadcastCount(1))->toThrow(AssertionFailedException::class);
    });

    it('passes assertNothingBroadcast when empty and fails otherwise', function (): void {
        $broadcaster = new FakeBroadcaster();
        $broadcaster->assertNothingBroadcast();

        $broadcaster->broadcast('orders', 'order.shipped', []);

        expect(fn () => $broadcaster->assertNothingBroadcast())->toThrow(AssertionFailedException::class);
    });

    it('supports the toHaveBroadcast expectation', function (): void {
        $broadcaster = new FakeBroadcaster();
        $broadcaster->broadcast('orders', 'order.shipped', ['id' => 7]);

        expect($broadcaster)->toHaveBroadcast('orders', 'order.shipped')
            ->and($broadcaster)->toHaveBroadcast(
                'orders',
                'order.shipped',
                fn (array $data): bool => $data['id'] === 7,
            );
    });

    it('clears recorded broadcasts', function (): void {
        $broadcaster = new FakeBroadcaster();
        $broadcaster->broadcast('orders', 'order.shipped', []);

        $broadcaster->clear();

        expect($broadcaster->broadcasts)->toBeEmpty();
    });
});

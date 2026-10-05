<?php

declare(strict_types=1);

use Marko\Queue\Job;
use Marko\Testing\Fake\FakeQueue;
use PHPUnit\Framework\ExpectationFailedException;

it('passes toHavePushed when the job was pushed', function (): void {
    $queue = new FakeQueue();
    $job = new class () extends Job
    {
        public function handle(): void {}
    };

    $queue->push($job);

    expect($queue)->toHavePushed($job::class);
});

it('fails toHavePushed with an assertion failure when nothing was pushed', function (): void {
    $queue = new FakeQueue();
    $job = new class () extends Job
    {
        public function handle(): void {}
    };

    expect(fn () => expect($queue)->toHavePushed($job::class))
        ->toThrow(ExpectationFailedException::class, 'to be pushed to queue but it was not');
});

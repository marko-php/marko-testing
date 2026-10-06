<?php

declare(strict_types=1);

use Marko\Core\Command\ConfirmationPrompterInterface;
use Marko\Testing\Exceptions\AssertionFailedException;
use Marko\Testing\Fake\FakeConfirmationPrompter;

it('implements the core ConfirmationPrompterInterface', function (): void {
    expect(new FakeConfirmationPrompter())->toBeInstanceOf(ConfirmationPrompterInterface::class);
});

it('returns scripted answers in order', function (): void {
    $prompter = new FakeConfirmationPrompter(answers: [true, false]);

    expect($prompter->confirm('First?'))->toBeTrue()
        ->and($prompter->confirm('Second?', default: true))->toBeFalse();
});

it('records each question asked', function (): void {
    $prompter = new FakeConfirmationPrompter(answers: [true, false]);

    $prompter->confirm('First?');
    $prompter->confirm('Second?');

    expect($prompter->asked)->toBe(['First?', 'Second?']);
});

it('reports interactive unless told otherwise', function (): void {
    expect(new FakeConfirmationPrompter()->isInteractive())->toBeTrue()
        ->and(new FakeConfirmationPrompter(interactive: false)->isInteractive())->toBeFalse();
});

it('returns the default without consuming an answer when not interactive', function (): void {
    $prompter = new FakeConfirmationPrompter(answers: [false], interactive: false);

    expect($prompter->confirm('Install it?', default: true))->toBeTrue()
        ->and($prompter->confirm('Drop it?'))->toBeFalse()
        ->and($prompter->asked)->toBeEmpty();
});

it('throws loudly when asked more questions than answers were scripted', function (): void {
    $prompter = new FakeConfirmationPrompter(answers: [true]);
    $prompter->confirm('First?');

    expect(fn () => $prompter->confirm('Second?'))
        ->toThrow(AssertionFailedException::class, 'No scripted answer left for "Second?"');
});

it('asserts a question was asked and fails when it was not', function (): void {
    $prompter = new FakeConfirmationPrompter(answers: [true]);
    $prompter->confirm('Drop the table?');

    $prompter->assertAsked('Drop the table?');

    expect(fn () => $prompter->assertAsked('Truncate the table?'))
        ->toThrow(AssertionFailedException::class, 'Expected the question "Truncate the table?" to be asked');
});

it('asserts nothing was asked and fails when something was', function (): void {
    $prompter = new FakeConfirmationPrompter(answers: [true]);

    $prompter->assertNothingAsked();
    $prompter->confirm('Drop the table?');

    expect(fn () => $prompter->assertNothingAsked())
        ->toThrow(AssertionFailedException::class, 'Expected no questions to be asked, but 1 was asked');
});

<?php

declare(strict_types=1);

namespace Marko\Testing\Fake;

use Marko\Core\Command\ConfirmationPrompterInterface;
use Marko\Testing\Exceptions\AssertionFailedException;

/**
 * A confirmation prompter that answers from a script and records the questions it was asked.
 *
 * Like the real prompter, it asks nothing and returns the default when it is not interactive.
 */
class FakeConfirmationPrompter implements ConfirmationPrompterInterface
{
    /**
     * Questions asked so far, in order.
     *
     * @var list<string>
     */
    public private(set) array $asked = [];

    /**
     * @param list<bool> $answers Answers returned in order, one per question
     */
    public function __construct(
        private array $answers = [],
        private readonly bool $interactive = true,
    ) {}

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    /**
     * @throws AssertionFailedException When more questions are asked than answers were scripted
     */
    public function confirm(
        string $question,
        bool $default = false,
    ): bool {
        if (!$this->interactive) {
            return $default;
        }

        $this->asked[] = $question;

        if ($this->answers === []) {
            $count = count($this->asked);

            throw new AssertionFailedException(
                message: "No scripted answer left for \"$question\" (question $count).",
                context: 'While answering FakeConfirmationPrompter::confirm()',
                suggestion: 'Pass one answer per expected question: new FakeConfirmationPrompter(answers: [true, false]).',
            );
        }

        return array_shift($this->answers);
    }

    /**
     * @throws AssertionFailedException
     */
    public function assertAsked(
        string $question,
    ): void {
        if (in_array($question, $this->asked, true)) {
            return;
        }

        $asked = $this->asked === [] ? 'none' : '"' . implode('", "', $this->asked) . '"';

        throw new AssertionFailedException(
            message: "Expected the question \"$question\" to be asked, but it was not.",
            context: "Questions asked: $asked",
            suggestion: 'Check the exact question text, and that the prompter is interactive.',
        );
    }

    /**
     * @throws AssertionFailedException
     */
    public function assertNothingAsked(): void
    {
        if ($this->asked === []) {
            return;
        }

        $count = count($this->asked);
        $verb = $count === 1 ? 'was' : 'were';

        throw new AssertionFailedException(
            message: "Expected no questions to be asked, but $count $verb asked.",
            context: 'Questions asked: "' . implode('", "', $this->asked) . '"',
            suggestion: 'Make the code under test skip the prompt, or assert the question with assertAsked().',
        );
    }
}

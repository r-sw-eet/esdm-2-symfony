<?php

declare(strict_types=1);

namespace Esdm\Generator\Lint;

/**
 * Outcome of an `esdm lint` run: the findings, split by severity.
 */
final readonly class LintResult
{
    /** @var list<LintFinding> */
    public array $findings;

    /** @param list<LintFinding> $findings */
    public function __construct(array $findings)
    {
        $this->findings = $findings;
    }

    /** @return list<LintFinding> */
    public function errors(): array
    {
        return array_values(array_filter($this->findings, static fn (LintFinding $f) => $f->isError()));
    }

    /** @return list<LintFinding> */
    public function warnings(): array
    {
        return array_values(array_filter($this->findings, static fn (LintFinding $f) => !$f->isError()));
    }

    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    public function isClean(): bool
    {
        return $this->findings === [];
    }
}

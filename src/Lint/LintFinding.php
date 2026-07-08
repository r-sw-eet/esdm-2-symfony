<?php

declare(strict_types=1);

namespace Esdm\Generator\Lint;

/**
 * A single finding from `esdm lint --format json`.
 */
final readonly class LintFinding
{
    public function __construct(
        public string $ruleId,
        public string $severity,
        public string $message,
        public ?string $file,
        public ?int $line,
        public ?int $column,
    ) {
    }

    public static function fromArray(array $raw): self
    {
        $location = (array) ($raw['location'] ?? []);

        return new self(
            (string) ($raw['ruleId'] ?? 'unknown'),
            (string) ($raw['severity'] ?? 'error'),
            (string) ($raw['message'] ?? ''),
            isset($location['file']) ? (string) $location['file'] : null,
            isset($location['line']) ? (int) $location['line'] : null,
            isset($location['column']) ? (int) $location['column'] : null,
        );
    }

    public function isError(): bool
    {
        return $this->severity === 'error';
    }

    public function location(): string
    {
        if ($this->file === null) {
            return '';
        }

        return $this->line === null
            ? $this->file
            : sprintf('%s:%d:%d', $this->file, $this->line, $this->column ?? 0);
    }
}

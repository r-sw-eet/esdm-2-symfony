<?php

declare(strict_types=1);

namespace Esdm\Generator\Lint;

/**
 * Validates an ESDM model against the canonical schema by shelling out to the
 * upstream `esdm lint` CLI. The generator's own parser is intentionally lax;
 * this is the gate that keeps an invalid model from reaching code generation.
 */
final class EsdmLinter
{
    private string|false|null $resolved = null;

    public function __construct(private readonly ?string $binary = null)
    {
    }

    public function isAvailable(): bool
    {
        return $this->binary() !== null;
    }

    /**
     * Resolved path to the `esdm` binary, or a hint of where it was looked for.
     */
    public function binaryHint(): string
    {
        return $this->binary() ?? 'esdm (not found on PATH, ESDM_BIN, or tools/esdm)';
    }

    public function lint(string $modelDir): LintResult
    {
        $bin = $this->binary();
        if ($bin === null) {
            throw new \RuntimeException(
                'esdm binary not found. Install it (https://www.esdm.io/getting-started/installing-esdm/), '
                . 'put it at tools/esdm, or set the ESDM_BIN environment variable.'
            );
        }

        [$stdout, $stderr, $exit] = $this->run([$bin, 'lint', '-d', $modelDir, '--format', 'json', '--color', 'never']);

        $decoded = json_decode(trim($stdout) === '' ? '[]' : $stdout, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf(
                'esdm lint did not return parseable JSON (exit %d): %s',
                $exit,
                trim($stderr) !== '' ? trim($stderr) : trim($stdout),
            ));
        }

        $findings = array_map(
            static fn (array $raw) => LintFinding::fromArray($raw),
            array_values(array_filter($decoded, 'is_array')),
        );

        return new LintResult($findings);
    }

    private function binary(): ?string
    {
        if ($this->resolved !== null) {
            return $this->resolved ?: null;
        }

        foreach ($this->candidates() as $candidate) {
            if ($candidate !== null && is_file($candidate) && is_executable($candidate)) {
                return $this->resolved = $candidate;
            }
        }

        $onPath = $this->findOnPath('esdm');
        $this->resolved = $onPath ?? false;

        return $onPath;
    }

    /** @return list<string|null> */
    private function candidates(): array
    {
        $env = getenv('ESDM_BIN');

        return [
            $this->binary,
            is_string($env) && $env !== '' ? $env : null,
            \dirname(__DIR__, 2) . '/tools/esdm',
        ];
    }

    private function findOnPath(string $name): ?string
    {
        $path = getenv('PATH') ?: '';
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '/') . '/' . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<string> $command
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function run(array $command): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start esdm: ' . implode(' ', $command));
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$stdout, $stderr, $exit];
    }
}

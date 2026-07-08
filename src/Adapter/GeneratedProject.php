<?php

declare(strict_types=1);

namespace Esdm\Generator\Adapter;

/** An in-memory tree of files an adapter wants written, keyed by relative path. */
final class GeneratedProject
{
    /** @var array<string, string> */
    private array $files = [];

    public function add(string $relativePath, string $contents): void
    {
        $this->files[ltrim($relativePath, '/')] = $contents;
    }

    /** @return array<string, string> */
    public function files(): array
    {
        return $this->files;
    }

    public function writeTo(string $directory): void
    {
        foreach ($this->files as $relativePath => $contents) {
            $this->put($directory, $relativePath, $contents);
        }
    }

    private function put(string $directory, string $relativePath, string $contents): void
    {
        $target = rtrim($directory, '/') . '/' . $relativePath;
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }
        file_put_contents($target, $contents);
    }
}

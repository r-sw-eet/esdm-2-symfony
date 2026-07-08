<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads ESDM YAML files (one or many documents per file, separated by `---`)
 * from a directory tree into raw associative arrays. symfony/yaml has no
 * multi-document parser, so documents are split by hand.
 */
final class DocumentLoader
{
    /** @return list<array<string, mixed>> */
    public function loadDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException(sprintf('Model directory "%s" does not exist.', $directory));
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.(esdm\.ya?ml|ya?ml)$/', $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $documents = [];
        foreach ($files as $file) {
            foreach ($this->splitDocuments((string) file_get_contents($file)) as $raw) {
                $parsed = Yaml::parse($raw);
                if (is_array($parsed) && $parsed !== []) {
                    $documents[] = $parsed;
                }
            }
        }

        return $documents;
    }

    /** @return list<string> */
    private function splitDocuments(string $content): array
    {
        $parts = preg_split('/^---\s*$/m', $content) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }
}

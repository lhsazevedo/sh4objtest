<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

/**
 * Resolves debug-line source-file paths (Wine/wibo paths like
 * "Z:\app\src\016108.c") back to host files via a suite-supplied prefix map,
 * then scans them for coverage-exclusion tags.
 *
 * Tags are matched as plain substrings, not parsed as comments, so the same
 * markers work in both C (//, /* *\/) and SH assembler (;) sources:
 *
 *   coverage:disable / coverage:enable   exclude a block, inclusive
 *   coverage:ignore-next-line            exclude the following line only
 */
class SourceExclusions
{
    private const string DISABLE = 'coverage:disable';
    private const string ENABLE = 'coverage:enable';
    private const string IGNORE_NEXT = 'coverage:ignore-next-line';

    /** @var array<string, array<int,true>> resolved host path => ignored line set */
    private array $cache = [];

    /** @param array<string,string> $sourcePaths Wine prefix => host dir, relative to $suiteDir */
    public function __construct(
        private readonly array $sourcePaths,
        private readonly string $suiteDir,
    ) {
    }

    /** @return array<int,true> excluded line numbers as a set */
    public function ignoredLinesFor(?string $winPath): array
    {
        if ($winPath === null) {
            return [];
        }

        $hostPath = $this->resolve($winPath);
        if ($hostPath === null) {
            return [];
        }

        return $this->cache[$hostPath] ??= $this->scan($hostPath);
    }

    private function resolve(string $winPath): ?string
    {
        $normalized = str_replace('\\', '/', $winPath);

        foreach ($this->sourcePaths as $prefix => $dir) {
            $normalizedPrefix = str_replace('\\', '/', $prefix);
            if (!str_starts_with($normalized, $normalizedPrefix)) {
                continue;
            }

            $relative = ltrim(substr($normalized, strlen($normalizedPrefix)), '/');
            $hostPath = realpath("{$this->suiteDir}/{$dir}/{$relative}");

            return $hostPath !== false ? $hostPath : null;
        }

        return null;
    }

    /** @return array<int,true> */
    private function scan(string $hostPath): array
    {
        $lines = file($hostPath);
        if ($lines === false) {
            return [];
        }

        $ignored = [];
        $disabled = false;

        foreach ($lines as $i => $line) {
            $lineNumber = $i + 1;

            if (str_contains($line, self::DISABLE)) {
                $disabled = true;
            }

            if ($disabled) {
                $ignored[$lineNumber] = true;
            }

            if (str_contains($line, self::ENABLE)) {
                $disabled = false;
            }

            if (str_contains($line, self::IGNORE_NEXT)) {
                $ignored[$lineNumber + 1] = true;
            }
        }

        return $ignored;
    }
}

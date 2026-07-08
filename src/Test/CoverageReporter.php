<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\ObjectParser;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds and renders the coverage report shared by sequential and parallel
 * runs alike, once the caller has a fully merged set of ObjectResults.
 */
class CoverageReporter
{
    /**
     * @param array<string, ObjectResult> $objectResults
     * @return array<string, array{
     *     files: array<array{name: string, path: ?string, covered: int, total: int, percentage: float, uncoveredRanges: string[]}>,
     *     variables: array{name: string, touched: bool}[]
     * }>
     */
    public static function collect(array $objectResults): array
    {
        $coverageData = [];

        foreach ($objectResults as $objectPath => $objResult) {
            $parsedObject = ObjectParser::parse($objectPath);
            // Link so sections get their runtime linkedAddress
            Runner::linkObject($parsedObject);
            $report = $objResult->getReport($parsedObject);

            $files = [];
            foreach ($report as $fileNumber => $fileData) {
                sort($fileData['uncoveredLines']);
                $path = $parsedObject->unit->sourceFiles[$fileNumber] ?? null;

                $files[] = [
                    'name' => $path !== null ? basename($path) : "file $fileNumber",
                    'path' => $path,
                    'covered' => $fileData['covered'],
                    'total' => $fileData['total'],
                    'percentage' => $fileData['covered'] / $fileData['total'] * 100,
                    'uncoveredRanges' => self::computeLineRanges($fileData['uncoveredLines'], $fileData['coveredLines']),
                ];
            }

            $coverageData[$objectPath] = [
                'files' => $files,
                'variables' => $objResult->getSymbolReport($parsedObject),
            ];
        }

        return $coverageData;
    }

    /** @param array<string, array{files: array<array<string, mixed>>, variables: array{name: string, touched: bool}[]}> $coverageData */
    public static function render(OutputInterface $output, array $coverageData, bool $coverageFull): void
    {
        $output->writeln('');
        $output->writeln('<info>Coverage:</info>');

        // Width the percentage column against every file so it right-aligns
        // across all objects, not just within one.
        $names = array_merge(...array_map(
            fn ($data) => array_map(fn ($f) => $f['name'], $data['files']),
            array_values($coverageData),
        ));
        $nameWidth = $names === [] ? 0 : max(array_map('strlen', $names));

        foreach ($coverageData as $objectPath => $data) {
            $output->writeln("  <comment>{$objectPath}</comment>");

            if ($data['files'] === []) {
                $output->writeln('    (no debug line info)');
                continue;
            }

            foreach ($data['files'] as $file) {
                $pct = sprintf('%6.2f%%', $file['percentage']);
                $color = $file['percentage'] >= 100 ? 'green' : ($file['percentage'] >= 50 ? 'yellow' : 'red');
                $ranges = $coverageFull
                    ? implode(', ', $file['uncoveredRanges'])
                    : self::truncateRanges($file['uncoveredRanges'], 4);

                $output->writeln(sprintf(
                    '    %-' . $nameWidth . "s  <fg=%s>%s</>  %s",
                    $file['name'],
                    $color,
                    $pct,
                    $ranges,
                ));
            }

            $variables = $data['variables'];
            if ($variables !== []) {
                $untouched = array_values(array_filter($variables, fn ($s) => !$s['touched']));

                $output->writeln(sprintf(
                    '    %d/%d variables accessed',
                    count($variables) - count($untouched),
                    count($variables),
                ));

                if ($coverageFull && $untouched !== []) {
                    $names = array_map(fn ($s) => $s['name'], $untouched);
                    $output->writeln('      <fg=red>unaccessed:</> ' . implode(', ', $names));
                }
            }
        }
    }

    /**
     * Render uncovered ranges, keeping only the first few so the line stays
     * tidy; the full list lives in the JSON report.
     *
     * @param string[] $ranges
     */
    private static function truncateRanges(array $ranges, int $max): string
    {
        if ($ranges === []) {
            return '';
        }

        if (count($ranges) <= $max) {
            return implode(', ', $ranges);
        }

        $shown = array_slice($ranges, 0, $max);
        return implode(', ', $shown) . sprintf(' (+%d more)', count($ranges) - $max);
    }

    /**
     * Collapse line numbers into ranges, bridging gaps that contain no covered
     * line so e.g. 5-8 and 10-15 collapse to 5-15 when line 9 has no debug
     * record (blank line, brace, declaration).
     *
     * @param int[] $lines        uncovered line numbers, sorted ascending
     * @param int[] $coveredLines covered line numbers
     * @return string[]
     */
    private static function computeLineRanges(array $lines, array $coveredLines = []): array
    {
        $covered = array_flip($coveredLines);

        $ranges = [];
        $start = $end = null;

        foreach ($lines as $line) {
            if ($start === null) {
                $start = $end = $line;
            } elseif (self::gapIsEmpty($end, $line, $covered)) {
                $end = $line;
            } else {
                $ranges[] = $start === $end ? (string)$start : "{$start}-{$end}";
                $start = $end = $line;
            }
        }

        if ($start !== null) {
            $ranges[] = $start === $end ? (string)$start : "{$start}-{$end}";
        }

        return $ranges;
    }

    /** @param array<int,int> $covered covered line numbers as a set */
    private static function gapIsEmpty(int $from, int $to, array $covered): bool
    {
        for ($line = $from + 1; $line < $to; $line++) {
            if (isset($covered[$line])) {
                return false;
            }
        }

        return true;
    }
}

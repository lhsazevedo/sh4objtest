<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\ObjectParser;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\SectionHeader;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds and renders the coverage report shared by sequential and parallel
 * runs alike, once the caller has a fully merged set of ObjectResults.
 */
class CoverageReporter
{
    /**
     * Per-section coverage. Sections are the natural split between code and
     * data: assembler-built objects emit debug-line records for data
     * directives too, so a single blended percentage can be dragged down by
     * a large, mostly-unread data table even when the code is well covered.
     *
     * @param array<string, ObjectResult> $objectResults
     * @return array<string, array{
     *     sections: array<array{name: string, contents: string, sourceFile: ?string, covered: int, total: int, percentage: float, uncoveredLines: int[], coveredLines: int[], uncoveredRanges: string[]}>,
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

            $sections = [];
            foreach ($report as $sectionNumber => $sectionData) {
                sort($sectionData['uncoveredLines']);
                $section = $parsedObject->unit->sections[$sectionNumber];
                $path = $parsedObject->unit->sourceFiles[$sectionData['fileNumber']] ?? null;

                $sections[] = [
                    'name' => $section->name,
                    'contents' => SectionHeader::contentsLabel($section->contents),
                    'sourceFile' => $path,
                    'covered' => $sectionData['covered'],
                    'total' => $sectionData['total'],
                    'percentage' => $sectionData['covered'] / $sectionData['total'] * 100,
                    'uncoveredLines' => $sectionData['uncoveredLines'],
                    'coveredLines' => $sectionData['coveredLines'],
                    'uncoveredRanges' => self::computeLineRanges($sectionData['uncoveredLines'], $sectionData['coveredLines']),
                ];
            }

            $coverageData[$objectPath] = [
                'sections' => $sections,
                'variables' => $objResult->getSymbolReport($parsedObject),
            ];
        }

        return $coverageData;
    }

    /** @param array<string, array{sections: array<array<string, mixed>>, variables: array{name: string, touched: bool}[]}> $coverageData */
    public static function render(OutputInterface $output, array $coverageData, bool $coverageFull): void
    {
        $output->writeln('');
        $output->writeln('<info>Coverage:</info>');

        // Width the percentage column against every bucket label so it
        // right-aligns across all objects, not just within one.
        $labels = array_unique(array_merge(...array_map(
            fn ($data) => array_map(fn ($s) => $s['contents'], $data['sections']),
            array_values($coverageData),
        )));
        $labelWidth = $labels === [] ? 0 : max(array_map('strlen', $labels));

        foreach ($coverageData as $objectPath => $data) {
            $output->writeln("  <comment>{$objectPath}</comment>");

            if ($data['sections'] === []) {
                $output->writeln('    (no debug line info)');
                continue;
            }

            $sourceFile = $data['sections'][0]['sourceFile'] ?? null;
            if ($sourceFile !== null) {
                $output->writeln("    {$sourceFile}");
            }

            foreach (self::bucketSections($data['sections']) as $label => $bucket) {
                $pct = sprintf('%6.2f%%', $bucket['percentage']);
                $color = $bucket['percentage'] >= 100 ? 'green' : ($bucket['percentage'] >= 50 ? 'yellow' : 'red');
                $ranges = $coverageFull
                    ? implode(', ', $bucket['uncoveredRanges'])
                    : self::truncateRanges($bucket['uncoveredRanges'], 4);

                $output->writeln(sprintf(
                    '      %-' . $labelWidth . "s  <fg=%s>%s</>  %s",
                    $label,
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
     * Groups per-section coverage into per-contents buckets (code/data/…) for
     * the CLI summary, in the order each contents label first appears.
     *
     * @param array<array{contents: string, covered: int, total: int, uncoveredLines: int[], coveredLines: int[]}> $sections
     * @return array<string, array{covered: int, total: int, percentage: float, uncoveredRanges: string[]}>
     */
    private static function bucketSections(array $sections): array
    {
        /** @var array<string, array{covered: int, total: int, uncoveredLines: int[], coveredLines: int[]}> $raw */
        $raw = [];

        foreach ($sections as $section) {
            $label = $section['contents'];
            if (!isset($raw[$label])) {
                $raw[$label] = ['covered' => 0, 'total' => 0, 'uncoveredLines' => [], 'coveredLines' => []];
            }

            $raw[$label]['covered'] += $section['covered'];
            $raw[$label]['total'] += $section['total'];
            array_push($raw[$label]['uncoveredLines'], ...$section['uncoveredLines']);
            array_push($raw[$label]['coveredLines'], ...$section['coveredLines']);
        }

        $buckets = [];
        foreach ($raw as $label => $bucket) {
            sort($bucket['uncoveredLines']);
            $buckets[$label] = [
                'covered' => $bucket['covered'],
                'total' => $bucket['total'],
                'percentage' => $bucket['covered'] / $bucket['total'] * 100,
                'uncoveredRanges' => self::computeLineRanges($bucket['uncoveredLines'], $bucket['coveredLines']),
            ];
        }

        return $buckets;
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

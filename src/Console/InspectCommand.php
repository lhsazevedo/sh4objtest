<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Console;

use Lhsazevedo\Sh4ObjTest\ObjectParser;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\SectionHeader;
use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'inspect',
    description: 'Inspect the parsed contents of a SH4 object file',
)]
class InspectCommand extends Command
{
    public function configure(): void
    {
        $this->addArgument('object', InputArgument::REQUIRED, 'The object file to inspect')
            ->addOption('hex', 'x', InputOption::VALUE_NONE, 'Dump raw section bytes as hex')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: "pretty" or "json"', 'pretty')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write --format=json output to this file instead of stdout');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $objectFile = $input->getArgument('object');

        if (!file_exists($objectFile)) {
            $output->writeln("<error>File not found: $objectFile</error>");
            return Command::FAILURE;
        }

        $format = $input->getOption('format');
        if (!in_array($format, ['pretty', 'json'], true)) {
            $output->writeln("<error>Invalid --format \"{$format}\", expected \"pretty\" or \"json\".</error>");
            return Command::INVALID;
        }

        $parsed = ObjectParser::parse($objectFile);
        $unit = $parsed->unit;

        if ($format === 'json') {
            $json = json_encode($this->buildJsonDocument($parsed), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

            $outputPath = $input->getOption('output');
            if ($outputPath !== null) {
                file_put_contents($outputPath, $json);
                $output->writeln("<info>Wrote results to {$outputPath}</info>");
            } else {
                $output->write($json);
            }

            return Command::SUCCESS;
        }

        $output->writeln("<info>Unit:</info> {$unit->unitName}");
        $output->writeln("  Tool:     {$unit->toolName}");
        $output->writeln("  Date:     {$unit->toolDate}");
        $output->writeln("  Sections: {$unit->nSections}");
        $output->writeln("  ExtRefs:  {$unit->nExtRefs}");
        $output->writeln("  ExtDefs:  {$unit->nExtDefs}");
        $output->writeln('');

        foreach ($unit->sections as $i => $section) {
            $contentsLabel = SectionHeader::contentsLabel($section->contents);
            $rwx = ($section->read  ? 'r' : '-')
                 . ($section->write ? 'w' : '-')
                 . ($section->exec  ? 'x' : '-');

            $output->writeln(sprintf(
                '<info>Section %d:</info> %s  [%s %s]  addr=0x%08x  len=0x%x  align=0x%x',
                $i,
                $section->name,
                $contentsLabel,
                $rwx,
                $section->address,
                $section->length,
                $section->alignment,
            ));

            if ($section->exports) {
                $output->writeln('  <comment>Exports:</comment>');
                foreach ($section->exports as $export) {
                    $output->writeln(sprintf(
                        '    %-40s  section=%d  offset=0x%x',
                        $export->name,
                        $export->section,
                        $export->offset,
                    ));
                }
            }

            if ($section->externalRelocations) {
                $output->writeln('  <comment>External relocations (imported symbols):</comment>');
                foreach ($section->externalRelocations as $rel) {
                    $output->writeln(sprintf(
                        '    addr=0x%08x  %-40s  addend=%s  width=%d',
                        $rel->address,
                        $rel->name,
                        self::formatAddend($rel->addend),
                        $rel->fieldWidth,
                    ));
                }
            }

            if ($section->internalRelocations) {
                $output->writeln('  <comment>Internal relocations (section-relative):</comment>');
                foreach ($section->internalRelocations as $rel) {
                    // Explicit addend (RELA) is shown as a value; an implicit
                    // addend (REL) lives in the section data at the patched site.
                    $addend = $rel->addend === null
                        ? 'in-place'
                        : self::formatAddend($rel->addend);
                    $output->writeln(sprintf(
                        '    addr=0x%08x  -> section %d  addend=%s',
                        $rel->address,
                        $rel->sectionIndex,
                        $addend,
                    ));
                }
            }

            if ($input->getOption('hex') && $section->objectDataEntries) {
                $output->writeln('  <comment>Object data:</comment>');
                $raw = $section->assembleObjectData();
                $this->dumpHex($output, $raw);
            }

            $output->writeln('');
        }

        if ($unit->debugLines) {
            $output->writeln('<info>Debug lines:</info>');
            $output->writeln(sprintf('  %-6s %-6s %-8s %-10s %-10s %s', 'file', 'line', 'section', 'from', 'to', 'callCount'));
            foreach ($unit->debugLines as $dl) {
                $output->writeln(sprintf(
                    '  %-6d %-6d %-8d 0x%08x 0x%08x %d',
                    $dl->fileNumber,
                    $dl->lineNumber,
                    $dl->sectionNumber,
                    $dl->fromAddress,
                    $dl->toAddress,
                    $dl->callCount,
                ));
            }
            $output->writeln('');
        }

        if ($unit->sourceFiles) {
            $output->writeln('<info>Source files:</info>');
            foreach ($unit->sourceFiles as $i => $path) {
                $output->writeln(sprintf('  %-3d %s%s', $i, $path, $i === 0 ? '  <comment>(main)</comment>' : ''));
            }
            $output->writeln('');
        }

        if ($unit->debugSymbols) {
            // Only symbols defined in the main file are interesting here;
            // everything from #included headers is noise for our purposes.
            $mainSymbols = array_filter($unit->debugSymbols, fn($sym) => $sym->fileNumber === 0);
            $hidden = count($unit->debugSymbols) - count($mainSymbols);

            $output->writeln('<info>Debug symbols (main file):</info>');
            $output->writeln(sprintf('  %-12s %-32s %-8s %-8s %s', 'type', 'name', 'section', 'address', 'storage'));
            foreach ($mainSymbols as $sym) {
                $typeName = $sym->type !== null ? $sym->type->name : "raw({$sym->rawType})";
                $storage = $sym->register
                    ?? $sym->externalName
                    ?? ($sym->ainfo !== null ? "ainfo {$sym->ainfo}" : '');
                $output->writeln(sprintf(
                    '  %-12s %-32s %-8s %-8s %s',
                    $typeName,
                    $sym->name,
                    $sym->section !== null ? (string) $sym->section : '-',
                    $sym->address !== null ? sprintf('0x%x', $sym->address) : '-',
                    $storage,
                ));
            }
            if ($hidden > 0) {
                $output->writeln(sprintf('  <comment>(%d symbols from included files hidden)</comment>', $hidden));
            }
            $output->writeln('');
        }

        if ($parsed->skippedChunkTypes) {
            $output->writeln('<info>Skipped chunks (unhandled types):</info>');
            foreach ($parsed->skippedChunkTypes as $rawType => $count) {
                $output->writeln(sprintf('  0x%02x  x%d', $rawType, $count));
            }
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function buildJsonDocument(ParsedObject $parsed): array
    {
        $unit = $parsed->unit;

        return [
            'unit' => [
                'unitName' => $unit->unitName,
                'toolName' => $unit->toolName,
                'toolDate' => $unit->toolDate,
                'nSections' => $unit->nSections,
                'nExtRefs' => $unit->nExtRefs,
                'nExtDefs' => $unit->nExtDefs,
            ],
            'sections' => array_map(function (int $i, SectionHeader $section) {
                $raw = $section->assembleObjectData();

                return [
                    'index' => $i,
                    'name' => $section->name,
                    'contents' => SectionHeader::contentsLabel($section->contents),
                    'read' => (bool) $section->read,
                    'write' => (bool) $section->write,
                    'exec' => (bool) $section->exec,
                    'address' => $section->address,
                    'length' => $section->length,
                    'alignment' => $section->alignment,
                    'objectDataHex' => bin2hex($raw),
                    'exports' => array_map(fn($e) => [
                        'name' => $e->name,
                        'section' => $e->section,
                        'offset' => $e->offset,
                    ], $section->exports),
                    'externalRelocations' => array_map(fn($r) => [
                        'address' => $r->address,
                        'name' => $r->name,
                        'addend' => $r->addend,
                        'fieldWidth' => $r->fieldWidth,
                    ], $section->externalRelocations),
                    'internalRelocations' => array_map(fn($r) => [
                        'address' => $r->address,
                        'sectionIndex' => $r->sectionIndex,
                        'addend' => $r->addend, // null => implicit/in-place
                    ], $section->internalRelocations),
                ];
            }, array_keys($unit->sections), $unit->sections),
            'debugLines' => array_map(fn($dl) => [
                'fileNumber' => $dl->fileNumber,
                'lineNumber' => $dl->lineNumber,
                'sectionNumber' => $dl->sectionNumber,
                'fromAddress' => $dl->fromAddress,
                'toAddress' => $dl->toAddress,
                'callCount' => $dl->callCount,
                'callSites' => $dl->callSites,
            ], $unit->debugLines),
            'sourceFiles' => $unit->sourceFiles, // index 0 = main file, rest = #included
            'debugSymbols' => array_map(fn($sym) => [
                'type' => $sym->type?->name,
                'rawType' => $sym->rawType,
                'name' => $sym->name,
                'nesting' => $sym->nesting,
                'section' => $sym->section,
                'address' => $sym->address,
                'register' => $sym->register,
                'externalName' => $sym->externalName,
                'constant' => $sym->constant,
                'ainfo' => $sym->ainfo,
                'fileNumber' => $sym->fileNumber,
                'lineNumber' => $sym->lineNumber,
            ], $unit->debugSymbols), // unfiltered — includes symbols from #included files
            'skippedChunkTypes' => $parsed->skippedChunkTypes,
        ];
    }

    /** Format a signed addend as e.g. "0x4" or "-0x4". */
    private static function formatAddend(int $addend): string
    {
        return $addend < 0
            ? sprintf('-0x%x', -$addend)
            : sprintf('0x%x', $addend);
    }

    private function dumpHex(OutputInterface $output, string $data): void
    {
        $bytes = str_split(bin2hex($data), 2);
        $rows  = array_chunk($bytes, 16);

        foreach ($rows as $i => $row) {
            $ascii = array_map(
                fn(string $h) => ctype_print(hex2bin($h)) ? chr(hexdec($h)) : '.',
                $row,
            );
            $output->writeln(sprintf(
                '    0x%04x: %-47s  |%s|',
                $i * 16,
                implode(' ', $row),
                implode('', $ascii),
            ));
        }
    }
}

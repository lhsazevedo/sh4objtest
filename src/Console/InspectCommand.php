<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Console;

use Lhsazevedo\Sh4ObjTest\ObjectParser;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\SectionHeader;
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
    private const CONTENTS_NAMES = [
        SectionHeader::CONTENTS_CODE    => 'code',
        SectionHeader::CONTENTS_DATA    => 'data',
        SectionHeader::CONTENTS_STACK   => 'stack',
        SectionHeader::CONTENTS_DUMMY   => 'dummy',
        SectionHeader::CONTENTS_SPECIAL => 'special',
    ];

    public function configure(): void
    {
        $this->addArgument('object', InputArgument::REQUIRED, 'The object file to inspect')
            ->addOption('hex', 'x', InputOption::VALUE_NONE, 'Dump raw section bytes as hex');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $objectFile = $input->getArgument('object');

        if (!file_exists($objectFile)) {
            $output->writeln("<error>File not found: $objectFile</error>");
            return Command::FAILURE;
        }

        $parsed = ObjectParser::parse($objectFile);
        $unit = $parsed->unit;

        $output->writeln("<info>Unit:</info> {$unit->unitName}");
        $output->writeln("  Tool:     {$unit->toolName}");
        $output->writeln("  Date:     {$unit->toolDate}");
        $output->writeln("  Sections: {$unit->nSections}");
        $output->writeln("  ExtRefs:  {$unit->nExtRefs}");
        $output->writeln("  ExtDefs:  {$unit->nExtDefs}");
        $output->writeln('');

        foreach ($unit->sections as $i => $section) {
            $contentsLabel = self::CONTENTS_NAMES[$section->contents] ?? "unknown({$section->contents})";
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

            if ($section->relocations) {
                $output->writeln('  <comment>Relocations (external):</comment>');
                foreach ($section->relocations as $rel) {
                    $output->writeln(sprintf(
                        '    addr=0x%08x  %-40s  offset=0x%x',
                        $rel->address,
                        $rel->name,
                        $rel->offset,
                    ));
                }
            }

            if ($section->localRelocationsShort) {
                $output->writeln('  <comment>Local relocations (short):</comment>');
                foreach ($section->localRelocationsShort as $rel) {
                    $output->writeln(sprintf(
                        '    addr=0x%08x  -> section %d',
                        $rel->address,
                        $rel->sectionIndex,
                    ));
                }
            }

            if ($section->localRelocationsLong) {
                $output->writeln('  <comment>Local relocations (long):</comment>');
                foreach ($section->localRelocationsLong as $rel) {
                    $output->writeln(sprintf(
                        '    addr=0x%08x  -> section %d  target=0x%08x',
                        $rel->address,
                        $rel->sectionIndex,
                        $rel->target,
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

<?php

namespace App\Command;

use App\Rag\DocIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:rag:index', 'Indexe les fichiers markdown de docs/ dans Qdrant (remplace l\'index existant)')]
class RagIndexCommand
{
    public function __construct(
        private DocIndex $index,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        // One embedding per section, on CPU: show progress rather than a silent minute.
        $count = $this->index->index(fn (string $title) => $io->writeln(' - '.$title));
        $io->success(\sprintf('%d sections indexées.', $count));

        return Command::SUCCESS;
    }
}

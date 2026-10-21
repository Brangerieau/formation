<?php

namespace App\Command;

use App\Rag\DocIndex;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:rag:search', 'Affiche les sections de docs/ les plus proches d\'une question')]
class RagSearchCommand
{
    public function __construct(
        private DocIndex $index,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('La question')] string $query,
        #[Option('Nombre de sections')] int $limit = 5,
    ): int {
        foreach ($this->index->search($query, $limit) as $result) {
            $io->section(\sprintf('%.3f  %s (%s)', $result['score'], $result['title'], $result['source']));
            $io->writeln(mb_strimwidth($result['content'], 0, 300, '…'));
        }

        return Command::SUCCESS;
    }
}

<?php

namespace App\Console;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'ai:store:indexes',
    description: 'Set up indexes in the database',
)]
final  class SetupIndexesCommand extends Command
{
    public function __construct(private DocumentManager $documentManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->documentManager->getSchemaManager()->ensureIndexes();
        $this->documentManager->getSchemaManager()->createSearchIndexes();

        return 0;
    }
}

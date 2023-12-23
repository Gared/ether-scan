<?php
declare(strict_types=1);

namespace Gared\EtherScan\Console;

use Gared\EtherScan\Service\ScannerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ether:scan',
    description: 'Scan etherpad instance'
)]
class ScanCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'Url to etherpad instance');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputHelper = new ScanCommandOutputHelper(new SymfonyStyle($input, $output));

        $scanner = new ScannerService($input->getArgument('url'));
        $scanner->scan($outputHelper);

        return self::SUCCESS;
    }
}
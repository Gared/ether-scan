<?php
declare(strict_types=1);

namespace Gared\EtherScan\Console;

use Gared\EtherScan\Service\FileHashLookupService;
use Gared\EtherScan\Service\StaticFileClient;
use GuzzleHttp\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ether:generate-file-hashes',
    description: 'Generate file hashes of given instance'
)]
class GenerateFileHashesCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'Url to etherpad instance');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = $input->getArgument('url');

        $fileHashLookup = new FileHashLookupService();
        $staticFileClient = new StaticFileClient(new Client());

        $files = FileHashLookupService::getFileNames();

        $tableRows = [];

        foreach  ($files as $file) {
            $fileHash = $staticFileClient->getFileHash($url, $file);
            $versionRange = $fileHashLookup->getEtherpadVersionRange($file, $fileHash);
            $tableRows[] = [$file, $fileHash, $versionRange];
        }

        $symfonyStyle = new SymfonyStyle($input, $output);
        $symfonyStyle->table(['File', 'Hash', 'Version'], $tableRows);

        return self::SUCCESS;
    }
}

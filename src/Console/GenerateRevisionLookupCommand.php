<?php
declare(strict_types=1);

namespace Gared\EtherScan\Console;

use Gared\EtherScan\Api\GithubApi;
use Gared\EtherScan\Service\RevisionLookupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'ether:generate-revision-lookup',
    description: 'Generate revision lookup'
)]
class GenerateRevisionLookupCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $github = new GithubApi();
        $tagData = $github->getTags();

        if ($tagData === null) {
            $output->writeln('Failed to fetch tags');
            return self::FAILURE;
        }

        $saveData = [];

        foreach ($tagData as $tag) {
            $shortHash = substr($tag['commit']['sha'], 0, 7);
            $tagName = $tag['name'];
            if ($tagName[0] === 'v') {
                $tagName = substr($tagName, 1);
            }
            $saveData[$shortHash] = $tagName;
        }

        $revisionLookup = new RevisionLookupService();
        $revisionLookup->save($saveData);

        $output->writeln('Saved ' . count($saveData) . ' revisions');

        return self::SUCCESS;
    }
}

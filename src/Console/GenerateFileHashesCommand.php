<?php
declare(strict_types=1);

namespace Gared\EtherScan\Console;

use Gared\EtherScan\Api\GithubApi;
use Gared\EtherScan\Service\FileHashLookupService;
use Gared\EtherScan\Service\RevisionLookupService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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
    protected function configure()
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'Url to etherpad instance');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = $input->getArgument('url');

        $fileHashLookup = new FileHashLookupService();

        $attributePoolHash = $this->getFileHash($url, 'static/js/AttributePool.js');
        $attributesHash = $this->getFileHash($url, 'static/js/attributes.js');
        $padEditbarHash = $this->getFileHash($url, 'static/js/pad_editbar.js');
        $padHash = $this->getFileHash($url, 'static/js/pad.js');
        $padUtilsHash = $this->getFileHash($url, 'static/js/pad_utils.js');

        $symfonyStyle = new SymfonyStyle($input, $output);
        $symfonyStyle->table(['File', 'Hash', 'Version'], [
            ['static/js/AttributePool.js', $attributePoolHash, $fileHashLookup->getEtherpadVersionRange('static/js/AttributePool.js', $attributePoolHash)],
            ['static/js/attributes.js', $attributesHash, $fileHashLookup->getEtherpadVersionRange('static/js/attributes.js', $attributesHash)],
            ['static/js/pad_editbar.js', $padEditbarHash, $fileHashLookup->getEtherpadVersionRange('static/js/pad_editbar.js', $padEditbarHash)],
            ['static/js/pad.js', $padHash, $fileHashLookup->getEtherpadVersionRange('static/js/pad.js', $padHash)],
            ['static/js/pad_utils.js', $padUtilsHash, $fileHashLookup->getEtherpadVersionRange('static/js/pad_utils.js', $padUtilsHash)],
        ]);

        return self::SUCCESS;
    }

    private function getFileHash(string $url, string $path): ?string
    {
        try {
            $client = new Client([
                'base_uri' => $url,
                'timeout' => 5.0,
            ]);
            $response = $client->get($path, [
                'headers' => ['Accept-Encoding' => 'gzip'],
            ]);

            $body = (string)$response->getBody();
            return hash('md5', $body);
        } catch (GuzzleException $e) {
            var_dump($e->getMessage());
        }

        return null;
    }
}
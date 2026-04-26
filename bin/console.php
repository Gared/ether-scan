#!/usr/bin/env php
<?php
declare(strict_types=1);

use Gared\EtherScan\Console\CheckFileHashesCommand;
use Gared\EtherScan\Console\GenerateFileHashesCommand;
use Gared\EtherScan\Console\GenerateRevisionLookupCommand;
use Gared\EtherScan\Console\ScanCommand;
use Gared\EtherScan\Console\GenerateFileHashesAllVersionsCommand;
use Symfony\Component\Console\Application;

require __DIR__ . '/../vendor/autoload.php';

$application = new Application();
$application->add(new ScanCommand());
$application->add(new GenerateRevisionLookupCommand());
$application->add(new GenerateFileHashesCommand());
$application->add(new CheckFileHashesCommand());
$application->add(new GenerateFileHashesAllVersionsCommand());
$application->run();

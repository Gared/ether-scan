# Etherscan (an etherpad scanner)

Use this tool to scan your [etherpad](https://etherpad.org) instance to get some insights.

## Requirements

You need PHP 8.1 or higher to run this tool.

## Usage

### Clone

Clone this repository and install dependencies
```bash
composer install
```

Next run this command to scan your instance
```bash
bin/console.php ether:scan http://localhost:9001
```

### Composer

You can also install this tool with composer
```bash
composer require gared/ether-scan
```
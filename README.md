# Etherscan (an etherpad scanner)

Use this tool to scan your [etherpad](https://etherpad.org) instance to get some insights and test if there is any misconfiguration.

What is this tool doing?
* Check the "Server" header to see if the revision of etherpad is returned
* Check the API version (pad.example.com/api)
* Check the etherpad version
* Check if the pads are publicly accessible
* Check if websocket is working
* Check if the server is healthy (pad.example.com/health)
* Check if the admin area is accessible with default credentials (pad.example.com/admin)
* Check if any (frontend) plugins are installed
* Check if the server is running since a long time (pad.example.com/stats)

## Try it out

You can try this tool out on https://scanner.etherpad.org/instances which is using this library.

## Requirements

You need PHP 8.3 or higher to run this tool.

## Usage

### Docker

Directly download and run this docker image to scan your instance
```bash
docker run --rm gared/ether-scan:main bin/console.php ether:scan http://localhost:9001
```

### Clone

Clone this repository and install dependencies
```bash
composer install
```

Next run this command to scan your instance
```bash
bin/console.php ether:scan http://localhost:9001
```

![Scan Output](docs/scan-output.svg)

### Composer

You can also install this tool with composer
```bash
composer require gared/ether-scan
```

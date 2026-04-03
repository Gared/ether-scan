# Etherscan (an etherpad scanner)

Use this tool to scan your [etherpad](https://etherpad.org) instance to get some insights and test if there is any misconfiguration.

What is this tool doing?
* Check the "Server" header to see if the revision of etherpad is returned
* Check the API version (pad.example.com/api)
* Check the etherpad version
* Check if the pads are publicly accessible
* Check if the server is healthy (pad.example.com/health)
* Check if the admin area is accessible with default credentials (pad.example.com/admin)
* Check if any (frontend) plugins are installed
* Check if the server is running since a long time (pad.example.com/stats)

## Try it out

You can try this tool out on the https://scanner.etherpad.org which is using this library.

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

```console
Starting scan of api: http://localhost:9001/
============================================

 [INFO] No revision in server header

 [INFO] api version: 1.3.0

Starting scan of a pad...
=========================

 [INFO] Package version: 2.6.1

 [INFO] No plugins found

 [OK] Pads are publicly accessible

 [OK] Websocket is supported

 [OK] Server is healthy

 [INFO] Version is 2.6.1

 [INFO] Server running since: 2026-01-26T21:26:22+00:00

Starting scan of admin area...
==============================

 [OK] Admin area is not accessible with admin / admin

 [OK] Admin area is not accessible with admin / changeme1

 [OK] Admin area is not accessible with user / changeme1
```

### Composer

You can also install this tool with composer
```bash
composer require gared/ether-scan
```

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

```console
Starting scan of api...
=======================

 [INFO] No revision in server header                                                                                    

 [INFO] api version: 1.3.0                                                                                              

Starting scan of a pad...
=========================

 [INFO] Package version: 1.9.7                                                                                          

 [OK] Pads are publicly accessible                                                                                      

 [OK] Server is healthy                                                                                                 

 [INFO] Version is 1.9.7                                                                                                

 [INFO] Server running since: 2024-03-08T21:38:56+00:00                                                                 

Starting scan of admin area...
==============================

 [OK] Admin area is not accessible with admin / admin                                                                   

 [OK] Admin area is not accessible with admin / changeme1                                                               

 [OK] Admin area is not accessible with user / changeme1                                                                

Starting scan of plugins...
===========================

 [INFO] No plugins found                                                                                                
```

### Composer

You can also install this tool with composer
```bash
composer require gared/ether-scan
```
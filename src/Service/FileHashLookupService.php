<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service;

use Gared\EtherScan\Model\VersionRange;

class FileHashLookupService
{
    private const FILE_HASH_VERSIONS = [
        'static/js/AttributePool.js' => [
            '4edf12f374e005bfa7d0fc6681caa67f' => [null, '1.8.0'],
            '64ac4ec21f716d36d37a4b1a9aa0debe' => ['1.8.3', '1.8.4'],
            '3cd97554c5b0ada9c2970553b1cb0895' => ['1.8.5', '1.8.6'],
            '863cf873612192af5f96a2fdd86edf82' => ['1.8.7', '1.8.7'],
            '3b016b68f81bf91d03f57b7ab560e78d' => ['1.8.8', '1.8.14'],
            '7bfcb69a6bee4bdd500d5a5faae61db8' => ['1.8.15', '1.8.15'],
            '1ab82b2042cc551cf0205166f36fa625' => ['1.8.16', '1.8.18'],
            '27a349df6d68dcc316c63f396b481927' => ['1.9.0', '2.1.0'],
            '24478719e9641726d55b26e97466708b' => ['2.2.0', null],
        ],
        'static/js/attributes.js' => [
            null => [null, '1.8.18'],
            '3b78aa8c55200e09fe709178721c0e30' => ['1.9.0', '2.1.0'],
            '9dbc0d1414a1f4696d66c5b89c3e9abc' => ['2.2.0', null],
        ],
        'static/js/pad_editbar.js' => [
            '34a86fe81588f76b8def068331a11936' => [null, '1.8.0'],
            'c6c1b52cda50e102e645c03f7c8be411' => ['1.8.3', '1.8.3'],
            'f32cc6c8d2f83217531b7d87124dde89' => ['1.8.4', '1.8.4'],
            '6b94182a1d9ee7a2b804f67068fc926f' => ['1.8.5', '1.8.5'],
            'f75bd5a0e98756643ac37f7d619a795b' => ['1.8.6', '1.8.6'],
            '123796e620a3eca47d57346a5186ea3f' => ['1.8.7', '1.8.9'],
            'c8b681fbc13584006f5ec40ef1fc5fd2' => ['1.8.10', '1.8.14'],
            '9f3f1343f7585299bd0dc4e1dcbddff9' => ['1.8.14', '1.9.1'],
            '4f7669997ae0cbb5d9fc502b79cb2b50' => ['1.9.2', '1.9.2'],
            'd9d3f04a6b532773d02f463e2df34306' => ['1.9.3', '2.1.0'],
            '33c2045f954bce58d7f5ac17aa1d7f04' => ['2.2.0', null],
        ],
        'static/js/pad.js' => [
            'c0d22189c3497e2da29607f5cb6f47b1' => [null, '1.8.0'],
            '1904f2b800ef8ffcfef636e39409bfe9' => ['1.8.3', '1.8.3'],
            'f917bf95a0593b8cf6c316caf782a547' => ['1.8.4', '1.8.4'],
            'a723ea05d351a4684d1649370a177d75' => ['1.8.5', '1.8.5'],
            'b5b12aa4c4f5bb18732603205d426df4' => ['1.8.6', '1.8.6'],
            '162ea8ae452fedc68342a88bb0733b50' => ['1.8.7', '1.8.7'],
            'db97938a124442df74a8eeb78a3aea8c' => ['1.8.8', '1.8.9'],
            '340213c84571e261b7d4663367594bf6' => ['1.8.10', '1.8.12'],
            '44d1204499568a291876a24b6f338cc4' => ['1.8.13', '1.8.13'],
            '45ef5d43ac1a11d0ed4116f12c2a2546' => ['1.8.13', '1.8.13'],
            'bab4ae460659a2ec18d9e0376d76bcb9' => ['1.8.14', '1.8.14'],
            'dce60717b0ef09120279b66073944b29' => ['1.8.15', '1.8.18'],
            '4a9a47f791c40d794e7293d90ac571bf' => ['1.9.0', '1.9.1'],
            'a0625d1d18451d7ac8c0cca439f00a08' => ['1.9.2', '1.9.7'],
            'c643215708e10eea297d27e9b2f764f0' => ['2.0.0', '2.0.2'],
            'b022b626a88d09c7b9f0ab27b34eaa82' => ['2.0.3', '2.1.0'],
            '36389b0667fc8987d13abe61243f917b' => ['2.2.0', null],
        ],
        'static/js/pad_utils.js' => [
            'a7072962ca5031754c382373fc6fceb9' => [null, '1.8.0'],
            'da1ffbda8e0cf83820559e829b259de9' => ['1.8.3', '1.8.3'],
            'd7ef66be49dd94a1a10562fe83e1f2de' => ['1.8.4', '1.8.4'],
            '0a3c87bd3bd94c0cdcb634f089a7caa0' => ['1.8.5', '1.8.6'],
            '78ae97c31f5a713f34541d388792aaae' => ['1.8.7', '1.8.13'],
            '9647a4bd4fe93c2d931e4a13e67ce9f1' => ['1.8.14', '1.8.14'],
            'ba36ceb4b40845de8545334caca02163' => ['1.8.15', '1.8.18'],
            '3e44cb62ef2a60779e8a3684f8f0a905' => ['1.9.0', '1.9.0'],
            'fc1965c84113e78fb5b29b68c8fc84f8' => ['1.9.1', '1.9.1'],
            'e1d8c5fc1e4fcfe28b527828543a4729' => ['1.9.2', '2.1.0'],
            '96fd880e3e348fe4b45170b7c750a0b1' => ['2.2.0', null],
        ],
    ];

    public function getEtherpadVersionRange(string $fileName, ?string $hash): ?VersionRange
    {
        $versionRange = self::FILE_HASH_VERSIONS[$fileName][$hash] ?? null;
        if ($versionRange !== null) {
            return new VersionRange(
                $versionRange[0],
                $versionRange[1],
            );
        }

        return null;
    }
}
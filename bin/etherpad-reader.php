#!/usr/bin/env php
<?php

/**
 * Etherpad Pad Reader - Proof of Concept
 *
 * Connects to an Etherpad pad and listens for changes in an endless loop,
 * printing each edit (insertions and deletions) to stdout as it arrives.
 *
 * Usage:
 *   php etherpad-reader.php <etherpad-url> <pad-id>
 *
 * Example:
 *   php etherpad-reader.php https://etherpad.example.com my-pad
 *   php etherpad-reader.php https://demo.etherpad.org test-pad-123
 *
 * Press Ctrl+C to stop.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ElephantIO\Client as ElephantClient;
use Gared\EtherScan\Changeset\Changeset;
use Gared\EtherScan\Changeset\StringIterator;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\RequestOptions;

// ---------------------------------------------------------------------------
// CLI argument parsing
// ---------------------------------------------------------------------------

if ($argc < 3) {
    fwrite(STDERR, "Usage: php etherpad-reader.php <etherpad-url> <pad-id>\n");
    fwrite(STDERR, "Example: php etherpad-reader.php https://etherpad.example.com my-pad\n");
    exit(1);
}

$baseUrl = rtrim($argv[1], '/') . '/';
$padId   = $argv[2];

echo "=== Etherpad Pad Reader (Proof of Concept) ===\n";
echo "Server : {$baseUrl}\n";
echo "Pad    : {$padId}\n";
echo "Press Ctrl+C to stop.\n\n";

// ---------------------------------------------------------------------------
// Step 1: Visit the pad URL to obtain a session cookie
// ---------------------------------------------------------------------------

$cookies = new CookieJar();
$httpClient = new HttpClient([
    'timeout' => 10.0,
    'connect_timeout' => 5.0,
    RequestOptions::HEADERS => [
        'User-Agent' => 'EtherpadPadReader/1.0',
    ],
    'verify' => false,
]);

echo "[1/3] Fetching pad page to obtain session cookie...\n";
try {
    $httpClient->get($baseUrl . 'p/' . $padId, ['cookies' => $cookies]);
} catch (\Throwable $e) {
    // Some pads may return non-200 but still set a cookie; continue
    echo "      (note: HTTP request returned: " . $e->getMessage() . ")\n";
}

$cookieString = '';
foreach ($cookies as $cookie) {
    $cookieString .= $cookie->getName() . '=' . $cookie->getValue() . ';';
}
echo "      Cookies: " . ($cookieString !== '' ? $cookieString : '(none)') . "\n\n";

// ---------------------------------------------------------------------------
// Step 2: Connect via Socket.IO and send CLIENT_READY
// ---------------------------------------------------------------------------

echo "[2/3] Connecting via Socket.IO...\n";

$token = 't.' . bin2hex(random_bytes(16));

$socketClient = new ElephantClient(
    ElephantClient::engine(ElephantClient::CLIENT_4X, $baseUrl . 'socket.io/', [
        'persistent' => false,
        'context' => [
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ],
        'headers' => [
            'Cookie' => $cookieString,
        ],
    ])
);

$socketClient->connect();
$socketClient->of('/');

$socketClient->emit('message', [
    'component'       => 'pad',
    'type'            => 'CLIENT_READY',
    'padId'           => $padId,
    'sessionID'       => 'null',
    'token'           => $token,
    'password'        => null,
    'protocolVersion' => 2,
]);

echo "      Connected and CLIENT_READY sent.\n\n";

// ---------------------------------------------------------------------------
// Step 3: Receive CLIENT_VARS to get initial pad state
// ---------------------------------------------------------------------------

echo "[3/3] Waiting for CLIENT_VARS...\n";

$currentText = null;
$currentRev  = null;

while ($result = $socketClient->wait('message', 10)) {
    if (!is_array($result->data)) {
        continue;
    }

    $msg = $result->data;

    if (isset($msg['data']['type']) && $msg['data']['type'] === 'CUSTOM') {
        continue;
    }

    $accessStatus = $msg['accessStatus'] ?? null;
    if ($accessStatus === 'deny') {
        fwrite(STDERR, "ERROR: Pad access denied. The pad may require authentication.\n");
        $socketClient->disconnect();
        exit(1);
    }

    $type = $msg['type'] ?? null;
    if ($type !== 'CLIENT_VARS') {
        continue;
    }

    $clientVars       = $msg['data'];
    $collabClientVars = $clientVars['collab_client_vars'] ?? null;

    if ($collabClientVars === null) {
        fwrite(STDERR, "ERROR: Unexpected CLIENT_VARS structure (missing collab_client_vars).\n");
        $socketClient->disconnect();
        exit(1);
    }

    $currentRev  = (int) $collabClientVars['rev'];
    $currentText = $collabClientVars['initialAttributedText']['text'];

    echo "      Initial revision : {$currentRev}\n";
    echo "      Initial text     : " . json_encode($currentText) . "\n\n";
    break;
}

if ($currentText === null || $currentRev === null) {
    fwrite(STDERR, "ERROR: Did not receive CLIENT_VARS within timeout.\n");
    $socketClient->disconnect();
    exit(1);
}

// ---------------------------------------------------------------------------
// Endless loop: listen for NEW_CHANGES and print diffs
// ---------------------------------------------------------------------------

echo "Listening for changes (Ctrl+C to stop)...\n";
echo str_repeat('-', 60) . "\n";

// Handle Ctrl+C (SIGINT) and SIGTERM gracefully
$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, static function () use (&$running): void {
        echo "\nStopping...\n";
        $running = false;
    });
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
}

while ($running) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
    $result = $socketClient->wait('message', 30);

    if ($result === null) {
        // No message within the poll window — send a keepalive and continue
        echo "[" . date('H:i:s') . "] (no changes, still listening...)\n";
        continue;
    }

    if (!is_array($result->data)) {
        continue;
    }

    $msg = $result->data;

    // Only handle COLLABROOM messages
    if (($msg['type'] ?? null) !== 'COLLABROOM') {
        continue;
    }

    $data     = $msg['data'] ?? [];
    $dataType = $data['type'] ?? null;

    if ($dataType !== 'NEW_CHANGES') {
        continue;
    }

    $newRev    = (int) ($data['newRev'] ?? ($currentRev + 1));
    $changeset = $data['changeset'] ?? '';
    $author    = $data['author'] ?? 'unknown';

    if ($changeset === '') {
        continue;
    }

    // Apply the changeset to the current text to get the new text
    $newText = Changeset::applyToText($changeset, $currentText);

    // Parse the ops to extract what was inserted and what was deleted
    $unpacked  = Changeset::unpack($changeset);
    $strIter   = new StringIterator($currentText);
    $charBank  = $unpacked['charBank'];
    $bankPos   = 0;

    $insertions = [];
    $deletions  = [];

    foreach (Changeset::deserializeOps($unpacked['ops']) as $op) {
        switch ($op->opcode) {
            case '=':
                $strIter->skip($op->chars);
                break;
            case '-':
                $deletions[] = $strIter->take($op->chars);
                break;
            case '+':
                $insertions[] = mb_substr($charBank, $bankPos, $op->chars, 'UTF-8');
                $bankPos += $op->chars;
                break;
        }
    }

    // Print the change summary
    echo sprintf(
        "[%s] rev %d → %d (author: %s)\n",
        date('H:i:s'),
        $currentRev,
        $newRev,
        $author !== '' ? $author : 'unknown'
    );

    foreach ($deletions as $deleted) {
        $display = str_replace("\n", "↵", $deleted);
        echo "  - \033[31m{$display}\033[0m\n";
    }
    foreach ($insertions as $inserted) {
        $display = str_replace("\n", "↵", $inserted);
        echo "  + \033[32m{$display}\033[0m\n";
    }

    if ($insertions === [] && $deletions === []) {
        echo "  (attribute-only change)\n";
    }

    echo "  New text: " . json_encode($newText) . "\n";
    echo str_repeat('-', 60) . "\n";

    // Advance state
    $currentText = $newText;
    $currentRev  = $newRev;
}

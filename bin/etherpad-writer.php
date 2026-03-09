#!/usr/bin/env php
<?php

/**
 * Etherpad Pad Writer - Proof of Concept
 *
 * Demonstrates using the Changeset library to connect to an Etherpad instance
 * and insert "Hello world" in the middle of a pad's existing text.
 *
 * Usage:
 *   php etherpad-writer.php <etherpad-url> <pad-id>
 *
 * Example:
 *   php etherpad-writer.php https://etherpad.example.com my-pad
 *   php etherpad-writer.php https://demo.etherpad.org test-pad-123
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ElephantIO\Client as ElephantClient;
use ElephantIO\Engine\SocketIO;
use Gared\EtherScan\Changeset\AttributePool;
use Gared\EtherScan\Changeset\Changeset;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\RequestOptions;

// ---------------------------------------------------------------------------
// CLI argument parsing
// ---------------------------------------------------------------------------

if ($argc < 3) {
    fwrite(STDERR, "Usage: php etherpad-writer.php <etherpad-url> <pad-id>\n");
    fwrite(STDERR, "Example: php etherpad-writer.php https://etherpad.example.com my-pad\n");
    exit(1);
}

$baseUrl = rtrim($argv[1], '/') . '/';
$padId   = $argv[2];
$insertText = 'Hello world';

echo "=== Etherpad Pad Writer (Proof of Concept) ===\n";
echo "Server : {$baseUrl}\n";
echo "Pad    : {$padId}\n";
echo "Insert : \"{$insertText}\" at the middle of the current text\n\n";

// ---------------------------------------------------------------------------
// Step 1: Visit the pad URL to obtain a session cookie
// ---------------------------------------------------------------------------

$cookies = new CookieJar();
$httpClient = new HttpClient([
    'timeout' => 10.0,
    'connect_timeout' => 5.0,
    RequestOptions::HEADERS => [
        'User-Agent' => 'EtherpadPadWriter/1.0',
    ],
    'verify' => false,
]);

echo "[1/4] Fetching pad page to obtain session cookie...\n";
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

echo "[2/4] Connecting via Socket.IO...\n";

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
// Step 3: Wait for CLIENT_VARS to get the current pad state
// ---------------------------------------------------------------------------

echo "[3/4] Waiting for CLIENT_VARS...\n";

$currentText = null;
$currentRev  = null;
$serverApool = null;

while ($result = $socketClient->wait('message', 5)) {
    if (!is_array($result->data)) {
        continue;
    }

    $msg = $result->data;

    // Skip COLLABROOM/CUSTOM messages (e.g. plugin broadcasts)
    if (isset($msg['data']['type']) && $msg['data']['type'] === 'CUSTOM') {
        continue;
    }

    // The CLIENT_VARS message carries access status
    $accessStatus = $msg['accessStatus'] ?? null;
    if ($accessStatus === 'deny') {
        fwrite(STDERR, "ERROR: Pad access denied. The pad may require authentication.\n");
        $socketClient->disconnect();
        exit(1);
    }

    // CLIENT_VARS message: {type:'CLIENT_VARS', data: clientVars}
    $type = $msg['type'] ?? null;
    if ($type !== 'CLIENT_VARS') {
        // Not CLIENT_VARS yet, keep waiting
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
    $serverApool = $collabClientVars['apool']; // {numToAttrib: {...}, nextNum: N}

    echo "      Current revision : {$currentRev}\n";
    echo "      Current text     : " . json_encode($currentText) . "\n";
    break;
}

if ($currentText === null || $currentRev === null || $serverApool === null) {
    fwrite(STDERR, "ERROR: Did not receive CLIENT_VARS within timeout.\n");
    $socketClient->disconnect();
    exit(1);
}

// ---------------------------------------------------------------------------
// Step 4: Build a changeset that inserts text in the middle, then send it
// ---------------------------------------------------------------------------

echo "\n[4/4] Building and sending changeset...\n";

// Etherpad pads always end with "\n". The text we receive will look like "some text\n".
// We want to insert "Hello world" in the middle of the textual content
// (i.e., halfway through the characters, not counting the final newline).

$textLength = strlen($currentText); // includes trailing "\n"

// Find the insertion point: middle of the text (before the trailing newline)
$contentLength    = max(0, $textLength - 1); // exclude trailing "\n"
$insertionPoint   = (int) ($contentLength / 2);

echo "      Text length      : {$textLength} chars\n";
echo "      Insertion point  : position {$insertionPoint}\n";

// Reconstruct the server's attribute pool so we can pass it to moveOpsToNewPool if needed.
// For a simple plain-text insertion with no attributes, we use an empty local pool.
$localPool = new AttributePool();

// Build the changeset: keep the first half, insert the new text, keep the rest.
$changeset = Changeset::makeSplice($currentText, $insertionPoint, 0, $insertText);

echo "      Changeset        : {$changeset}\n";

// Verify the changeset applies correctly (sanity check)
$resultText = Changeset::applyToText($changeset, $currentText);
echo "      Resulting text   : " . json_encode($resultText) . "\n";

// Send the USER_CHANGES message wrapped in COLLABROOM
$socketClient->emit('message', [
    'type'      => 'COLLABROOM',
    'component' => 'pad',
    'data'      => [
        'type'      => 'USER_CHANGES',
        'baseRev'   => $currentRev,
        'changeset' => $changeset,
        'apool'     => $localPool->toJsonable(),
    ],
]);

echo "      USER_CHANGES sent.\n";

// Wait for ACCEPT_COMMIT confirmation
$accepted = false;
while ($result = $socketClient->wait('message', 5)) {
    if (!is_array($result->data)) {
        continue;
    }
    $msg  = $result->data;
    $type = $msg['data']['type'] ?? null;

    if ($type === 'ACCEPT_COMMIT') {
        $newRev = $msg['data']['newRev'] ?? '?';
        echo "      ACCEPT_COMMIT received! New revision: {$newRev}\n";
        $accepted = true;
        break;
    }

    // Handle disconnect/error response
    if (isset($msg['disconnect'])) {
        fwrite(STDERR, "ERROR: Server rejected changeset: " . $msg['disconnect'] . "\n");
        $socketClient->disconnect();
        exit(1);
    }
}

$socketClient->disconnect();

if ($accepted) {
    echo "\n✓ Success! \"{$insertText}\" was inserted at position {$insertionPoint} in pad '{$padId}'.\n";
} else {
    fwrite(STDERR, "\nWARNING: Changeset was sent but no ACCEPT_COMMIT was received within timeout.\n");
    fwrite(STDERR, "The change may have been applied; check the pad manually.\n");
    exit(2);
}

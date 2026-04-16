<?php

/**
 * Retry updating failed CRM items from update_progress.json.
 * Reads the errors array, retries each one, and updates the progress file.
 */

$webhookBase    = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
$entityTypeId   = 1038;
$newAssignedById = 28; // Sarah Zeidan

$progressFile = 'update_progress.json';
$maxRetries   = 3;     // Max attempts per item
$retryDelay   = 2;     // Seconds between retries

// --- Load progress ---

if (!file_exists($progressFile)) {
    die("Error: $progressFile not found.\n");
}

$progress = json_decode(file_get_contents($progressFile), true);
$errors   = $progress['errors'] ?? [];

if (empty($errors)) {
    echo "No failed items to retry. All good!\n";
    exit;
}

echo "Found " . count($errors) . " failed items to retry.\n\n";

$stillFailing = [];
$retrySuccess = 0;

foreach ($errors as $i => $err) {
    $itemId    = $err['id'];
    $itemTitle = $err['title'];

    echo "[" . ($i + 1) . "/" . count($errors) . "] Retrying: $itemTitle (ID: $itemId)...\n";

    $success = false;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        echo "  Attempt $attempt/$maxRetries... ";

        $payload = [
            'entityTypeId' => $entityTypeId,
            'id'           => $itemId,
            'fields'       => [
                'assignedById' => $newAssignedById,
            ],
        ];

        $result = sendRequest("$webhookBase/crm.item.update", $payload);

        if ($result['success']) {
            echo "SUCCESS\n";
            $success = true;
            $retrySuccess++;
            break;
        } else {
            echo "FAILED: " . $result['error'] . "\n";
            if ($attempt < $maxRetries) {
                echo "  Waiting {$retryDelay}s before next attempt...\n";
                sleep($retryDelay);
            }
        }
    }

    if (!$success) {
        $stillFailing[] = $err;
    }

    // Delay between items
    usleep(500000);
}

// --- Update progress file ---

$progress['updated'] += $retrySuccess;
$progress['errors']   = $stillFailing;

file_put_contents($progressFile, json_encode($progress, JSON_PRETTY_PRINT));

// --- Summary ---

echo "\n=== RETRY COMPLETE ===\n";
echo "Retried:        " . count($errors) . "\n";
echo "Now succeeded:  $retrySuccess\n";
echo "Still failing:  " . count($stillFailing) . "\n";

if (!empty($stillFailing)) {
    echo "\n--- STILL FAILING ---\n";
    foreach ($stillFailing as $err) {
        echo "  ID {$err['id']} ({$err['title']}): {$err['error']}\n";
    }
}

// --- HELPER ---

function sendRequest($url, $data = []) {
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => "Curl Error: $curlError"];
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['result'])) {
        return ['success' => true, 'data' => $decoded];
    } else {
        $errorMsg = $decoded['error_description'] ?? $decoded['error'] ?? "HTTP Status $httpCode";
        return ['success' => false, 'error' => $errorMsg];
    }
}

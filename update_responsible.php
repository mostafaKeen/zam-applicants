<?php

/**
 * Update all CRM items (entityTypeId 1038) to set the responsible person
 * to Sarah Zeidan (User ID 28).
 *
 * This script:
 *  1. Fetches ALL existing items from Bitrix via crm.item.list (with pagination)
 *  2. Updates each item's assignedById to 28 using crm.item.update
 */

$webhookBase  = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
$entityTypeId = 1038;
$newAssignedById = 28; // Sarah Zeidan (Sarah@zamprime.com)

$progressFile = 'update_progress.json';

// --- STEP 1: Fetch all existing items ---

echo "=== STEP 1: Fetching all existing items (entityTypeId: $entityTypeId) ===\n";

$allItems = [];
$start = 0;

do {
    $params = [
        'entityTypeId' => $entityTypeId,
        'select' => ['id', 'title', 'assignedById', 'xmlId', 'categoryId'],
        'start' => $start,
    ];

    $result = sendRequest("$webhookBase/crm.item.list", $params);

    if (!$result['success']) {
        die("ERROR fetching items: " . $result['error'] . "\n");
    }

    $items = $result['data']['result']['items'] ?? [];
    $total = $result['data']['total'] ?? 0;
    $allItems = array_merge($allItems, $items);

    echo "  Fetched " . count($allItems) . " / $total items...\n";

    $start = $result['data']['next'] ?? null;
    usleep(500000);

} while ($start !== null);

$totalItems = count($allItems);
echo "\nTotal items found: $totalItems\n\n";

if ($totalItems === 0) {
    echo "No items found. Nothing to update.\n";
    exit;
}

// --- STEP 2: Update each item's assignedById ---

echo "=== STEP 2: Updating assignedById to $newAssignedById (Sarah Zeidan) ===\n\n";

$progress = ['last_index' => -1, 'updated' => 0, 'skipped' => 0, 'errors' => []];
if (file_exists($progressFile)) {
    $progress = json_decode(file_get_contents($progressFile), true) ?: $progress;
}

$startIndex = $progress['last_index'] + 1;

if ($startIndex >= $totalItems) {
    echo "All items have already been processed.\n";
    echo "Updated: {$progress['updated']}, Skipped: {$progress['skipped']}, Errors: " . count($progress['errors']) . "\n";
    exit;
}

for ($i = $startIndex; $i < $totalItems; $i++) {
    $item = $allItems[$i];
    $itemId    = $item['id'];
    $itemTitle = $item['title'] ?? 'Untitled';
    $currentAssigned = $item['assignedById'] ?? 'Unknown';

    // Skip if already assigned to Sarah
    if ((int)$currentAssigned === $newAssignedById) {
        echo "[$i/$totalItems] SKIP (already assigned): $itemTitle (ID: $itemId)\n";
        $progress['skipped']++;
        $progress['last_index'] = $i;
        file_put_contents($progressFile, json_encode($progress, JSON_PRETTY_PRINT));
        continue;
    }

    echo "[$i/$totalItems] Updating: $itemTitle (ID: $itemId, current: $currentAssigned -> $newAssignedById)... ";

    $updatePayload = [
        'entityTypeId' => $entityTypeId,
        'id' => $itemId,
        'fields' => [
            'assignedById' => $newAssignedById,
        ],
    ];

    $updateResult = sendRequest("$webhookBase/crm.item.update", $updatePayload);

    if ($updateResult['success']) {
        echo "SUCCESS\n";
        $progress['updated']++;
    } else {
        echo "FAILED: " . $updateResult['error'] . "\n";
        $progress['errors'][] = [
            'index' => $i,
            'id'    => $itemId,
            'title' => $itemTitle,
            'error' => $updateResult['error'],
        ];
    }

    $progress['last_index'] = $i;
    file_put_contents($progressFile, json_encode($progress, JSON_PRETTY_PRINT));

    // Rate-limit protection
    usleep(500000);
}

// --- SUMMARY ---

echo "\n=== UPDATE COMPLETE ===\n";
echo "Total items:  $totalItems\n";
echo "Updated:      {$progress['updated']}\n";
echo "Skipped:      {$progress['skipped']}\n";
echo "Errors:       " . count($progress['errors']) . "\n";

if (!empty($progress['errors'])) {
    echo "\n--- ERRORS ---\n";
    foreach ($progress['errors'] as $err) {
        echo "  ID {$err['id']} ({$err['title']}): {$err['error']}\n";
    }
}

// --- HELPER FUNCTION ---

function sendRequest($url, $data = []) {
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
    ]);

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

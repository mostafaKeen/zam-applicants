<?php

/**
 * CONFIGURATION
 * Provide your Bitrix24 webhook URL here.
 */
$webhookUrl = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o/'; // Base URL
$entityTypeId = 1038;

echo "Starting deletion of items in Entity Type ID: $entityTypeId\n";

$hasMore = true;
$start = 0;
$totalDeleted = 0;

while ($hasMore) {
    // 1. Fetch a batch of items
    $listPayload = [
        'entityTypeId' => $entityTypeId,
        'select' => ['id'], // We only need the ID to delete
        'start' => $start
    ];

    $listResult = sendRequest($webhookUrl . 'crm.item.list', $listPayload);

    if (!$listResult['success']) {
         die("Failed to fetch items: " . $listResult['error'] . "\n");
    }

    $items = $listResult['data']['result']['items'] ?? [];
    
    if (empty($items)) {
        echo "No more items found. Finished.\n";
        break;
    }

    echo "Found " . count($items) . " items. Deleting...\n";

    // 2. Delete each item in the batch
    foreach ($items as $item) {
        $itemId = $item['id'];
        $deletePayload = [
            'entityTypeId' => $entityTypeId,
            'id' => $itemId
        ];

        $deleteResult = sendRequest($webhookUrl . 'crm.item.delete', $deletePayload);

        if ($deleteResult['success']) {
            echo "Deleted item ID: $itemId\n";
            $totalDeleted++;
        } else {
            echo "Failed to delete item ID: $itemId - Error: " . $deleteResult['error'] . "\n";
        }
        
        // Small delay to prevent hitting rate limits
        usleep(100000); // 0.1 seconds
    }

    // Check if there are more
    if (isset($listResult['data']['next'])) {
        // Bitrix list behavior when deleting: the "start" pointer might not need to advance
        // if we just deleted the first 50 items. The next 50 become the new first 50.
        // However, to be safe against items that failed to delete, we don't increment $start 
        // if we are deleting everything, we just keep fetching from start=0 until empty.
        $start = 0; 
        echo "Fetching next batch...\n";
    } else {
        $hasMore = false;
    }
}

echo "Deletion complete. Total items deleted: $totalDeleted\n";

// --- HELPER FUNCTIONS ---

function sendRequest($url, $data) {
    $ch = curl_init($url);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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

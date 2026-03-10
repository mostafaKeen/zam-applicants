<?php

/**
 * CONFIGURATION
 * Provide your Bitrix24 webhook URL here.
 */
$webhookUrl = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o/crm.item.add'; // Update if different
$entityTypeId = 1038;

$inputFile = 'mapped.json';
$progressFile = 'progress.json';

// --- INITIALIZATION ---

if (!file_exists($inputFile)) {
    die("Error: Source file '$inputFile' not found. Run map_data.php first.\n");
}

$data = json_decode(file_get_contents($inputFile), true);
if (!$data) {
    die("Error: Failed to decode input JSON.\n");
}

$progress = ['last_index' => -1, 'errors' => []];
if (file_exists($progressFile)) {
    $progress = json_decode(file_get_contents($progressFile), true) ?: $progress;
}

$totalRecords = count($data);
$startIndex = $progress['last_index'] + 1;

if ($startIndex >= $totalRecords) {
    echo "All records have already been processed.\n";
    exit;
}

echo "Starting upload from index $startIndex (Total: $totalRecords)...\n";

// --- UPLOAD LOOP ---

for ($i = $startIndex; $i < $totalRecords; $i++) {
    $record = $data[$i];
    
    echo "[$i/$totalRecords] Uploading: " . ($record['title'] ?? 'Unnamed Item') . "... ";
    
    $payload = [
        'entityTypeId' => $entityTypeId,
        'fields' => $record
    ];

    $result = sendRequest($webhookUrl, $payload);

    if ($result['success']) {
        echo "SUCCESS (ID: " . ($result['data']['result']['item']['id'] ?? 'Unknown') . ")\n";
        $progress['last_index'] = $i;
    } else {
        echo "FAILED: " . $result['error'] . "\n";
        $progress['errors'][] = [
            'index' => $i,
            'title' => $record['title'] ?? 'Unknown',
            'error' => $result['error']
        ];
    }

    // Save progress after each attempt
    file_put_contents($progressFile, json_encode($progress, JSON_PRETTY_PRINT));
    
    // Optional: Sleep to avoid rate limiting (e.g., 0.5 seconds)
    usleep(500000); 
}

echo "\nUpload process finished.\n";
echo "Parsed: " . ($progress['last_index'] + 1) . "/$totalRecords\n";
echo "Errors: " . count($progress['errors']) . "\n";

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

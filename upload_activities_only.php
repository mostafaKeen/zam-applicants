<?php

/**
 * Upload Activities ONLY to Bitrix24 Applicants (Ignores Comments)
 * 
 * Instructions:
 * 1. Ensure spa_export.json is in the same directory.
 * 2. Configure $webhookBase if different.
 * 3. Run: php upload_activities_only.php
 */

$webhookBase  = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
$entityTypeId = 1038; // Applicants (Recruitment)
$jsonFile     = 'spa_export.json';
$missingFile  = 'missing_activities.json';
$defaultResponsibleId = 28;

if (!file_exists($jsonFile)) {
    die("ERROR: $jsonFile not found.\n");
}

$data = json_decode(file_get_contents($jsonFile), true);
if (!$data || !is_array($data)) {
    die("ERROR: Invalid JSON format in $jsonFile.\n");
}

echo "=== STEP 1: Fetching all existing Applicants to build mapping ===\n";

$mapping = []; // xmlId => BitrixId
$start = 0;

do {
    $params = [
        'entityTypeId' => $entityTypeId,
        'select' => ['id', 'xmlId'],
        'start' => $start,
    ];

    $result = sendRequest("$webhookBase/crm.item.list", $params);

    if (!$result['success']) {
        die("ERROR fetching items: " . $result['error'] . "\n");
    }

    $items = $result['data']['result']['items'] ?? [];
    foreach ($items as $item) {
        if (!empty($item['xmlId'])) {
            $mapping[(string)$item['xmlId']] = $item['id'];
        }
    }

    echo "  Fetched mapping for " . count($mapping) . " items...\n";

    $start = $result['data']['next'] ?? null;
    usleep(200000);

} while ($start !== null);

echo "\nTotal mapped applicants discovered: " . count($mapping) . "\n\n";

echo "=== STEP 2: Processing ONLY Activities from JSON ===\n";

$missing = [];
$stats = [
    'matched' => 0,
    'skipped_comments' => 0,
    'missing' => 0,
    'activities_created' => 0,
    'errors' => 0
];

// Flatten the JSON
$allRecords = [];
foreach ($data as $block) {
    if (is_array($block)) {
        foreach ($block as $record) {
            $allRecords[] = $record;
        }
    } else {
        $allRecords[] = $block;
    }
}

$totalToProcess = count($allRecords);
echo "Total records in file: $totalToProcess\n\n";

foreach ($allRecords as $index => $record) {
    $type = $record['Type'] ?? 'Activity';
    
    // IGNORE COMMENTS
    if ($type !== 'Activity') {
        $stats['skipped_comments']++;
        continue;
    }

    $entityId = (string)($record['EntityId'] ?? $record['OwnerId'] ?? '');
    
    if (empty($entityId) || !isset($mapping[$entityId])) {
        $stats['missing']++;
        $missing[] = $record;
        echo "[$index/$totalToProcess] MISSING ID: $entityId\n";
        continue;
    }

    $bitrixId = $mapping[$entityId];
    $stats['matched']++;

    $description = cleanDescription($record['Description'] ?? '');
    $createdDate = formatBitrixDate($record['Created'] ?? '');

    // Add Activity with Provider Fix
    $payload = [
        'fields' => [
            'OWNER_TYPE_ID' => $entityTypeId,
            'OWNER_ID' => $bitrixId,
            'TYPE_ID' => 6,
            'PROVIDER_ID' => $record['ProviderId'] ?? 'CRM_TODO',
            'PROVIDER_TYPE_ID' => ($record['ProviderId'] ?? 'CRM_TODO') === 'CRM_TODO' ? 'TODO' : ($record['ProviderTypeId'] ?? ''),
            'SUBJECT' => 'Imported Activity',
            'DESCRIPTION' => $description,
            'COMPLETED' => ($record['Completed'] ?? 'N') === 'Y' ? 'Y' : 'N',
            'RESPONSIBLE_ID' => $defaultResponsibleId,
            'START_TIME' => $createdDate,
            'END_TIME' => $createdDate,
        ]
    ];

    $res = sendRequest("$webhookBase/crm.activity.add", $payload);
    
    if ($res['success']) {
        $stats['activities_created']++;
        echo "[$index/$totalToProcess] Activity Created for Applicant $bitrixId (xmlID: $entityId)\n";
    } else {
        $stats['errors']++;
        echo "[$index/$totalToProcess] FAILED Activity for $entityId: " . $res['error'] . "\n";
    }

    // Rate-limit safety
    usleep(500000);
}

// Write missing records if any
if (!empty($missing)) {
    file_put_contents($missingFile, json_encode($missing, JSON_PRETTY_PRINT));
}

echo "\n=== ACTIVITY IMPORT SUMMARY ===\n";
echo "Matched & Attempted:  {$stats['matched']}\n";
echo "Activities Created:   {$stats['activities_created']}\n";
echo "Comments Skipped:     {$stats['skipped_comments']}\n";
echo "Missing Mapping:      {$stats['missing']}\n";
echo "Errors:               {$stats['errors']}\n";
if ($stats['errors'] > 0) {
    echo "Check console output for detailed error messages.\n";
}
echo "================================\n";

// --- HELPER FUNCTIONS ---

function cleanDescription($text) {
    if (empty($text)) return '';
    $text = preg_replace('/\[\/?p\]/i', '', $text);
    $text = preg_replace('/\[\/?P\]/i', '', $text);
    $text = preg_replace('/\[URL=[^\]]*\](.*?)\[\/URL\]/i', '$1', $text);
    return trim($text);
}

function formatBitrixDate($dateStr) {
    if (empty($dateStr)) return date('c');
    $ts = strtotime($dateStr);
    return date('c', $ts);
}

function sendRequest($url, $data = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => "Curl Error: $curlError"];
    }

    $decoded = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && (isset($decoded['result']) || array_key_exists('result', $decoded))) {
        return ['success' => true, 'data' => $decoded];
    } else {
        $errorMsg = $decoded['error_description'] ?? $decoded['error'] ?? "HTTP Status $httpCode";
        return ['success' => false, 'error' => $errorMsg];
    }
}

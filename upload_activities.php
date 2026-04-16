<?php

/**
 * Upload Activities and Comments to Bitrix24 Applicants
 * 
 * Instructions:
 * 1. Ensure spa_export.json is in the same directory.
 * 2. Configure $webhookBase if different.
 * 3. Run: php upload_activities.php
 */

$webhookBase  = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
$entityTypeId = 1038; // Applicants (Recruitment)
$jsonFile     = 'spa_export.json';
$missingFile  = 'missing.json';
$progressFile = 'upload_progress.json';
$defaultResponsibleId = 28;

// Set this to true to only log mapping and missing IDs without uploading anything
$dryRun = false; 

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
    usleep(200000); // Small delay to prevent rate limiting

} while ($start !== null);

echo "\nTotal mapped applicants discovered: " . count($mapping) . "\n\n";

echo "=== STEP 2: Processing JSON data ===\n";

$missing = [];
$stats = [
    'matched' => 0,
    'missing' => 0,
    'activities_created' => 0,
    'comments_created' => 0,
    'errors' => 0
];

// Flatten the JSON if it's nested (it was observed as an array of arrays in spa_export.json)
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
echo "Total records to process: $totalToProcess\n\n";

foreach ($allRecords as $index => $record) {
    $entityId = (string)($record['EntityId'] ?? $record['OwnerId'] ?? '');
    
    if (empty($entityId) || !isset($mapping[$entityId])) {
        $stats['missing']++;
        $missing[] = $record;
        echo "[$index/$totalToProcess] MISSING ID: $entityId\n";
        continue;
    }

    $bitrixId = $mapping[$entityId];
    $stats['matched']++;

    if ($dryRun) {
        echo "[$index/$totalToProcess] MATCHED: $entityId -> Bitrix ID $bitrixId (Dry Run)\n";
        continue;
    }

    $type = $record['Type'] ?? 'Activity';
    $description = cleanDescription($record['Description'] ?? '');
    $createdDate = formatBitrixDate($record['Created'] ?? '');

    if ($type === 'Activity') {
        // Add Activity (TypeId 6 is Task/Todo)
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
            echo "[$index/$totalToProcess] Activity Created for Applicant $bitrixId\n";
        } else {
            $stats['errors']++;
            echo "[$index/$totalToProcess] FAILED Activity: " . $res['error'] . "\n";
        }
    } else { 
        // Add Comment (Timeline)
        $payload = [
            'entityTypeId' => $entityTypeId,
            'entityId' => $bitrixId,
            'fields' => [
                'DESCRIPTION' => $description
            ]
        ];
        // Note: crm.timeline.item.add is preferred for SPAs
        $res = sendRequest("$webhookBase/crm.timeline.item.add", $payload);
        if ($res['success']) {
            $stats['comments_created']++;
            echo "[$index/$totalToProcess] Comment Added for Applicant $bitrixId\n";
        } else {
            // Fallback try crm.timeline.comment.add if item.add fails
            $res = sendRequest("$webhookBase/crm.timeline.comment.add", [
                'fields' => [
                    'ENTITY_ID' => $bitrixId,
                    'ENTITY_TYPE' => 'DYNAMIC_'.$entityTypeId,
                    'COMMENT' => $description
                ]
            ]);
            if ($res['success']) {
                $stats['comments_created']++;
                echo "[$index/$totalToProcess] Comment Added (Fallback) for Applicant $bitrixId\n";
            } else {
                $stats['errors']++;
                echo "[$index/$totalToProcess] FAILED Comment: " . $res['error'] . "\n";
            }
        }
    }

    // Rate-limit safety
    usleep(500000);
}

// Write missing IDs
file_put_contents($missingFile, json_encode($missing, JSON_PRETTY_PRINT));

echo "\n=== IMPORT SUMMARY ===\n";
echo "Total Records:    $totalToProcess\n";
echo "Matched:          {$stats['matched']}\n";
echo "Missing:          {$stats['missing']} (Saved to $missingFile)\n";
echo "Activities:       {$stats['activities_created']}\n";
echo "Comments:         {$stats['comments_created']}\n";
echo "Errors:           {$stats['errors']}\n";
echo "======================\n";

// --- HELPER FUNCTIONS ---

function cleanDescription($text) {
    if (empty($text)) return '';
    // Remove [p] and [/p] (case-insensitive)
    $text = preg_replace('/\[\/?p\]/i', '', $text);
    // Remove [P] and [/P]
    $text = preg_replace('/\[\/?P\]/i', '', $text);
    // Remove [URL=...]...[/URL]
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

<?php
/**
 * Bitrix24 Activity & Comment Retry Script
 * 
 * Logic:
 * 1. Load failed records from migration_missing.json.
 * 2. Only attempt to upload those that failed due to API Errors OR Missing Leads.
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

$webhookUrl = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
$missingSourceFile = 'migration_missing.json';
$mappingFile = 'entity_to_name_date.json';
$newMissingFile = 'migration_retry_missing.json';
$promaxDateField = 'UF_CRM_1773428473';

echo "--- Starting Lead Activity Retry ---\n";

// 1. Load Data
if (!file_exists($missingSourceFile)) die("Error: Missing records file $missingSourceFile not found.\n");
$failedRecords = json_decode(file_get_contents($missingSourceFile), true);
echo "Loaded " . count($failedRecords) . " failed records to retry.\n";

if (!file_exists($mappingFile)) die("Error: Mapping file $mappingFile not found.\n");
$entityMap = json_decode(file_get_contents($mappingFile), true);

// 2. Build Bitrix24 Lead Lookup Map (needed to re-match)
echo "Fetching leads from Bitrix24 to rebuild lookup...\n";
$bitrixLookup = [];
$start = 0;
while (true) {
    $res = sendB24Request($webhookUrl . "/crm.lead.list", [
        'select' => ['ID', 'TITLE', $promaxDateField],
        'start' => $start
    ]);
    if (empty($res['result'])) break;
    foreach ($res['result'] as $lead) {
        $name = strtolower(trim($lead['TITLE']));
        $date = trim($lead[$promaxDateField]);
        if ($name && $date) {
            $day = substr($date, 0, 10);
            $key = $name . "|" . $day;
            $bitrixLookup[$key] = $lead['ID'];
        }
    }
    if (!isset($res['next'])) break;
    $start = $res['next'];
    echo "  Fetched " . count($bitrixLookup) . " unique name|day keys...\n";
}

// 3. Process Failed Records
$stats = ['total' => 0, 'success' => 0, 'error' => 0, 'still_no_lead' => 0];
$newMissing = [];

foreach ($failedRecords as $record) {
    $stats['total']++;
    
    // Some records in missing.json might be from the "No matching lead found" group
    // and don't have the original fields nested at the same level.
    // Let's normalize the record.
    $originalRecord = $record;
    $eid = '';
    
    if (isset($record['EntityId'])) {
        $eid = (string)$record['EntityId'];
    } elseif (isset($record['OwnerId'])) {
        $eid = (string)$record['OwnerId'];
    }

    if (!$eid || $eid === "0") {
       // This record is probably missing entity information
       $newMissing[] = array_merge($record, ['RetryReason' => 'Invalid EntityId']);
       $stats['error']++;
       continue;
    }

    // Lookup lead
    if (!isset($entityMap[$eid])) {
        $newMissing[] = array_merge($record, ['RetryReason' => 'EntityId still not in mapping']);
        $stats['error']++;
        continue;
    }
    
    $ref = $entityMap[$eid];
    $lookupKey = strtolower(trim($ref['name'])) . "|" . substr(trim($ref['created']), 0, 10);
    
    if (!isset($bitrixLookup[$lookupKey])) {
        $stats['still_no_lead']++;
        $newMissing[] = array_merge($record, ['RetryReason' => 'Still no matching lead found in Bitrix24']);
        continue;
    }
    
    $bitrixLeadId = $bitrixLookup[$lookupKey];

    // --- Rate Limiting: 0.5s delay ---
    usleep(500000);

    // Prepare content
    $description = cleanDescription($record['Description'] ?? '');
    $created = formatB24Date($record['Created'] ?? '');

    // Upload
    $type = $record['Type'] ?? '';
    // If Type is missing, we check if it was originally a comment or activity
    // But migration_missing.json usually keeps the 'Type' field.
    
    $uploadRes = null;
    if ($type === 'Comment') {
        $uploadRes = sendB24Request($webhookUrl . "/crm.timeline.comment.add", [
            'fields' => [
                'ENTITY_ID' => $bitrixLeadId,
                'ENTITY_TYPE' => 'lead',
                'COMMENT' => $description,
                'CREATED' => $created
            ]
        ]);
    } else {
        // Default to Activity
        $uploadRes = sendB24Request($webhookUrl . "/crm.activity.add", [
            'fields' => [
                'OWNER_ID' => $bitrixLeadId,
                'OWNER_TYPE_ID' => 1,
                'TYPE_ID' => 2,
                'SUBJECT' => 'Legacy Activity (Retry)',
                'START_TIME' => $created,
                'END_TIME' => $created,
                'COMPLETED' => (($record['Completed'] ?? 'Y') === 'Y' ? 'Y' : 'N'),
                'DESCRIPTION' => $description,
                'PROVIDER_ID' => 'CRM_TODO',
                'PROVIDER_TYPE_ID' => 'TODO',
                'COMMUNICATIONS' => [['ENTITY_ID' => $bitrixLeadId, 'ENTITY_TYPE_ID' => 'LEAD']]
            ]
        ]);
    }

    if (isset($uploadRes['result'])) {
        $stats['success']++;
    } else {
        $stats['error']++;
        $newMissing[] = array_merge($record, ['RetryReason' => 'Bitrix24 API Error: ' . json_encode($uploadRes)]);
    }
}

file_put_contents($newMissingFile, json_encode($newMissing, JSON_PRETTY_PRINT));

echo "\n--- Retry Summary ---\n";
echo "Total retries:    " . $stats['total'] . "\n";
echo "Sucessful:        " . $stats['success'] . "\n";
echo "Still No Lead:    " . $stats['still_no_lead'] . "\n";
echo "Errors/Invalid:   " . $stats['error'] . "\n";
echo "Remaining issues saved to $newMissingFile\n";

// --- Helper Functions ---
function sendB24Request($url, $params) {
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params)); $response = curl_exec($ch); return json_decode($response, true);
}
function cleanDescription($text) {
    if (!$text) return ""; $text = str_replace(['[p]', '[/p]', '[TABLE]', '[/TABLE]', '[TR]', '[/TR]', '[TD]', '[/TD]'], ["", "\n", "", "", "", "", " ", "\n"], $text); $text = preg_replace('/\[URL=.*?\](.*?)\[\/URL\]/i', '$1', $text); $text = html_entity_decode($text); return trim($text);
}
function formatB24Date($dateStr) {
    if (!$dateStr) return date('c'); $ts = strtotime($dateStr); return $ts ? date('c', $ts) : date('c');
}

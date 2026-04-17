<?php
/**
 * Bitrix24 Lead Activity Final Retry Script
 * 
 * Logic:
 * 1. Load failures from migration_retry_missing.json.
 * 2. Fetch leads from Bitrix24 *with their phone numbers*.
 * 3. Match and upload using lead phone numbers in COMMUNICATIONS.
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

$webhookUrl = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
$sourceFile = 'migration_retry_missing.json';
$mappingFile = 'entity_to_name_date.json';
$finalMissingFile = 'migration_final_missing.json';
$promaxDateField = 'UF_CRM_1773428473';

echo "--- Starting FINAL Lead Activity Retry ---\n";

// 1. Load Data
if (!file_exists($sourceFile)) die("Error: Source file $sourceFile not found.\n");
$failedRecords = json_decode(file_get_contents($sourceFile), true);

if (!file_exists($mappingFile)) die("Error: Mapping file $mappingFile not found.\n");
$entityMap = json_decode(file_get_contents($mappingFile), true);

// 2. Build Bitrix24 Lead Lookup Map (with Phones)
echo "Fetching leads from Bitrix24 (including Phone numbers)...\n";
$bitrixLookup = [];
$start = 0;
while (true) {
    $res = sendB24Request($webhookUrl . "/crm.lead.list", [
        'select' => ['ID', 'TITLE', $promaxDateField, 'PHONE'],
        'start' => $start
    ]);
    
    if (empty($res['result'])) break;
    
    foreach ($res['result'] as $lead) {
        $name = strtolower(trim($lead['TITLE']));
        $date = trim($lead[$promaxDateField]);
        
        // Match phone
        $phone = "";
        if (!empty($lead['PHONE']) && is_array($lead['PHONE'])) {
            $phone = $lead['PHONE'][0]['VALUE'];
        }

        if ($name && $date) {
            $day = substr($date, 0, 10);
            $key = $name . "|" . $day;
            $bitrixLookup[$key] = [
                'ID' => $lead['ID'],
                'PHONE' => $phone
            ];
        }
    }
    
    if (!isset($res['next'])) break;
    $start = $res['next'];
    echo "  Fetched " . count($bitrixLookup) . " unique name|day keys...\n";
}

// 3. Process
$stats = ['total' => 0, 'success' => 0, 'error' => 0, 'ignored' => 0, 'no_lead' => 0];
$finalMissing = [];

foreach ($failedRecords as $record) {
    $stats['total']++;
    
    // Normalize ID
    $eid = (string)($record['EntityId'] ?? $record['OwnerId'] ?? '0');
    
    // USER REQUEST: if entity id = 0 ignore it
    if ($eid === "0") {
        $stats['ignored']++;
        continue;
    }

    if (!isset($entityMap[$eid])) {
        $stats['error']++;
        $finalMissing[] = array_merge($record, ['FinalReason' => 'Still no mapping for entity ' . $eid]);
        continue;
    }

    $ref = $entityMap[$eid];
    $lookupKey = strtolower(trim($ref['name'])) . "|" . substr(trim($ref['created']), 0, 10);

    if (!isset($bitrixLookup[$lookupKey])) {
        $stats['no_lead']++;
        $finalMissing[] = array_merge($record, ['FinalReason' => 'Lead still missing in Bitrix24: ' . $lookupKey]);
        continue;
    }

    $leadInfo = $bitrixLookup[$lookupKey];
    $bitrixLeadId = $leadInfo['ID'];
    $leadPhone = $leadInfo['PHONE'];

    // --- Rate Limiting: 0.5s delay ---
    usleep(500000);

    $description = cleanDescription($record['Description'] ?? '');
    $created = formatB24Date($record['Created'] ?? '');
    $type = $record['Type'] ?? 'Activity';

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
        // Activity (Task/Todo)
        $fields = [
            'OWNER_ID' => $bitrixLeadId,
            'OWNER_TYPE_ID' => 1,
            'TYPE_ID' => 2, // Task
            'SUBJECT' => 'Legacy Activity (Final Retry)',
            'START_TIME' => $created,
            'END_TIME' => $created,
            'COMPLETED' => (($record['Completed'] ?? 'Y') === 'Y' ? 'Y' : 'N'),
            'DESCRIPTION' => $description,
            'PROVIDER_ID' => 'CRM_TODO',
            'PROVIDER_TYPE_ID' => 'TODO'
        ];

        // ADD COMMUNICATIONS if phone is available
        if ($leadPhone) {
            $fields['COMMUNICATIONS'] = [
                [
                    'VALUE' => $leadPhone,
                    'ENTITY_ID' => $bitrixLeadId,
                    'ENTITY_TYPE_ID' => 'LEAD'
                ]
            ];
        } else {
            // Fallback for Bitrix24 validation: dummy phone or skip comms if purely TO-DO
            // But let's try WITHOUT communications first if no phone, or use Lead ID
            $fields['COMMUNICATIONS'] = [
                [
                    'ENTITY_ID' => $bitrixLeadId,
                    'ENTITY_TYPE_ID' => 'LEAD'
                ]
            ];
        }

        $uploadRes = sendB24Request($webhookUrl . "/crm.activity.add", [
            'fields' => $fields
        ]);
    }

    if (isset($uploadRes['result'])) {
        $stats['success']++;
    } else {
        $stats['error']++;
        $finalMissing[] = array_merge($record, ['FinalReason' => 'Bitrix24 API Error: ' . json_encode($uploadRes)]);
    }
}

file_put_contents($finalMissingFile, json_encode($finalMissing, JSON_PRETTY_PRINT));

echo "\n--- Final Retry Summary ---\n";
echo "Total Records:    " . $stats['total'] . "\n";
echo "Ignored (EID=0): " . $stats['ignored'] . "\n";
echo "Successful:       " . $stats['success'] . "\n";
echo "Missing Leads:    " . $stats['no_lead'] . "\n";
echo "Api Errors:       " . $stats['error'] . "\n";
echo "Details saved to $finalMissingFile\n";

// --- Helpers ---
function sendB24Request($url, $params) {
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params)); $response = curl_exec($ch); return json_decode($response, true);
}
function cleanDescription($text) {
    if (!$text) return ""; $text = str_replace(['[p]', '[/p]', '[TABLE]', '[/TABLE]', '[TR]', '[/TR]', '[TD]', '[/TD]'], ["", "\n", "", "", "", "", " ", "\n"], $text); $text = preg_replace('/\[URL=.*?\](.*?)\[\/URL\]/i', '$1', $text); $text = html_entity_decode($text); return trim($text);
}
function formatB24Date($dateStr) {
    if (!$dateStr) return date('c'); $ts = strtotime($dateStr); return $ts ? date('c', $ts) : date('c');
}

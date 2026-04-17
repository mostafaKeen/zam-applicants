<?php
/**
 * Bitrix24 Activity & Comment Migration Script for Leads
 * 
 * Logic:
 * 1. Match legacy EntityId to (Name + Original Created Date) using Excel-exported JSON.
 * 2. Match (Name + Original Created Date) to Bitrix24 Lead ID using 'Created Date On Promax' field.
 * 3. Upload Activities and Comments to the matched Lead.
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

$webhookUrl = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
$activityFile = 'bitrix_activities_comments.json';
$mappingFile = 'entity_to_name_date.json';
$missingFile = 'migration_missing.json';
$promaxDateField = 'UF_CRM_1773428473';

echo "--- Starting Lead Activity Migration ---\n";

// 1. Load Mappings
if (!file_exists($mappingFile)) die("Error: Mapping file $mappingFile not found. Run the python script first.\n");
$entityMap = json_decode(file_get_contents($mappingFile), true);
echo "Loaded " . count($entityMap) . " entity-to-name/date mappings.\n";

if (!file_exists($activityFile)) die("Error: Activity file $activityFile not found.\n");
$activityBlocks = json_decode(file_get_contents($activityFile), true);

// 2. Build Bitrix24 Lead Lookup Map
echo "Fetching leads from Bitrix24 to build internal lookup (be patient)...\n";
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
            // Extract YYYY-MM-DD
            $day = substr($date, 0, 10);
            $key = $name . "|" . $day;
            // If multiple leads match name+day, we store them as an array or pick the first
            $bitrixLookup[$key] = $lead['ID'];
        }
    }
    
    if (!isset($res['next'])) break;
    $start = $res['next'];
    echo "  Fetched " . count($bitrixLookup) . " unique name|day keys...\n";
}
echo "Built internal lookup for " . count($bitrixLookup) . " unique name|day keys.\n";

// 3. Process Activities
$stats = ['total' => 0, 'matched' => 0, 'missing_entity' => 0, 'missing_lead' => 0, 'success' => 0, 'error' => 0];
$missingRecords = [];

foreach ($activityBlocks as $block) {
    if (!is_array($block)) $block = [$block];
    
    foreach ($block as $record) {
        $stats['total']++;
        
        // Find identifier
        $eid = ($record['Type'] === 'Activity') ? ($record['OwnerId'] ?? 0) : ($record['EntityId'] ?? 0);
        $eid = (string)$eid;
        
        if (!isset($entityMap[$eid])) {
            $stats['missing_entity']++;
            continue;
        }
        
        $ref = $entityMap[$eid];
        $name = strtolower(trim($ref['name']));
        $day = substr(trim($ref['created']), 0, 10);
        $lookupKey = $name . "|" . $day;
        
        if (!isset($bitrixLookup[$lookupKey])) {
            $stats['missing_lead']++;
            $missingRecords[] = [
                'EntityId' => $eid,
                'Name' => $ref['name'],
                'Date' => $ref['created'],
                'LookupKey' => $lookupKey,
                'Reason' => 'No matching Lead found in Bitrix24 for this name and day'
            ];
            continue;
        }
        
        $bitrixLeadId = $bitrixLookup[$lookupKey];
        $stats['matched']++;
        
        // Prepare content
        $description = cleanDescription($record['Description']);
        $created = formatB24Date($record['Created']);

        // Upload
        $uploadRes = null;
        if ($record['Type'] === 'Comment') {
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
            $uploadRes = sendB24Request($webhookUrl . "/crm.activity.add", [
                'fields' => [
                    'OWNER_ID' => $bitrixLeadId,
                    'OWNER_TYPE_ID' => 1, // Lead
                    'TYPE_ID' => 2, // Task
                    'SUBJECT' => 'Legacy Activity',
                    'START_TIME' => $created,
                    'END_TIME' => $created,
                    'COMPLETED' => ($record['Completed'] === 'Y' ? 'Y' : 'N'),
                    'DESCRIPTION' => $description,
                    'PROVIDER_ID' => 'CRM_TODO',
                    'PROVIDER_TYPE_ID' => 'TODO'
                ]
            ]);
        }

        if (isset($uploadRes['result'])) {
            $stats['success']++;
        } else {
            $stats['error']++;
            $missingRecords[] = array_merge($record, ['Reason' => 'Bitrix24 API Error: ' . json_encode($uploadRes)]);
        }
    }
}

// 4. Save results
file_put_contents($missingFile, json_encode($missingRecords, JSON_PRETTY_PRINT));

echo "\n--- Migration Summary ---\n";
echo "Total Records:    " . $stats['total'] . "\n";
echo "Matched Leads:    " . $stats['matched'] . "\n";
echo "Missing mapping:  " . $stats['missing_entity'] . "\n";
echo "Missing Bitrix:   " . $stats['missing_lead'] . "\n";
echo "Succesfully Sent: " . $stats['success'] . "\n";
echo "API Errors:       " . $stats['error'] . "\n";
echo "Missing details saved to $missingFile\n";

// --- Helper Functions ---

function sendB24Request($url, $params) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $response = curl_exec($ch);
    return json_decode($response, true);
}

function cleanDescription($text) {
    if (!$text) return "";
    $text = str_replace(['[p]', '[/p]', '[TABLE]', '[/TABLE]', '[TR]', '[/TR]', '[TD]', '[/TD]'], ["", "\n", "", "", "", "", " ", "\n"], $text);
    $text = preg_replace('/\[URL=.*?\](.*?)\[\/URL\]/i', '$1', $text);
    $text = html_entity_decode($text);
    return trim($text);
}

function formatB24Date($dateStr) {
    if (!$dateStr) return date('c');
    $ts = strtotime($dateStr);
    return $ts ? date('c', $ts) : date('c');
}

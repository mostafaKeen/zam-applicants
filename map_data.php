<?php

$inputFile = 'applicants.json';
$outputFile = 'mapped.json';

if (!file_exists($inputFile)) {
    die("Error: Source file '$inputFile' not found. Run extract_data.php first.\n");
}

$raw_data = json_decode(file_get_contents($inputFile), true);
if (!$raw_data) {
    die("Error: Failed to decode input JSON.\n");
}

echo "Mapping " . count($raw_data) . " records...\n";

// Map based on the provided Bitrix24 field list
$mapping = [
    'Name' => 'title',
    'Responsible person' => 'assignedById',
    'Stage' => 'stageId',
    'Phone' => 'ufCrm8_1772192069412',
    'E-mail' => 'ufCrm8_1772192889128',
    'Address' => 'ufCrm8_1772192916887',
    'Job Title' => 'ufCrm8_1772192939863',
    'Nationality' => 'ufCrm8_1772192959631',
    'Joining Date' => 'ufCrm8_1772193001136',
    'Date of Birth' => 'ufCrm8_1772193012616',
    'Preferred Name' => 'ufCrm8_1772193028487',
    'Area of Residency' => 'ufCrm8_1772193037231',
    'Call Date' => 'ufCrm8_1772193431725',
    'Call Decision' => 'ufCrm8_1772193488423',
    'Call Comments' => 'ufCrm8_1772193544349',
    'One To One Meeting Date' => 'ufCrm8_1772193559381',
    'One To One Meeting Decision' => 'ufCrm8_1772193601285',
    'One To One Meeting Comments' => 'ufCrm8_1772193623117',
    'Offer Status' => 'ufCrm8_1772193733501',
    'Gender' => 'ufCrm8_1772194127805',
    'Current Title with RE/MAX' => 'ufCrm8_1772194260020',
    'Team Status' => 'ufCrm8_1772194295467',
    'ID' => 'xmlId' // External ID
];

// Optional: Value mapping for ID-based fields (Enumerations)
// For now, we'll keep the values as strings. 
// Bitrix24 usually accepts labels for some fields but IDs for enumerations.

$stagesFile = 'stages_data.json';

if (!file_exists($stagesFile)) {
    die("Error: Stages data file '$stagesFile' not found.\n");
}

$stagesData = json_decode(file_get_contents($stagesFile), true);
if (!$stagesData || !isset($stagesData['result'])) {
    die("Error: Failed to decode stages JSON or invalid format.\n");
}

// Build stage mapping dictionary: Name => STATUS_ID (only for Entity 1038)
$stageDict = [];
foreach ($stagesData['result'] as $status) {
    if ($status['ENTITY_ID'] === 'DYNAMIC_1038_STAGE_14') {
        // Use lowercase to make matching case-insensitive
        $stageDict[strtolower(trim($status['NAME']))] = $status['STATUS_ID'];
        
        // Also add NAME_INIT as a fallback if it's different and not empty
        if (!empty($status['NAME_INIT']) && $status['NAME_INIT'] !== $status['NAME']) {
            $stageDict[strtolower(trim($status['NAME_INIT']))] = $status['STATUS_ID'];
        }
    }
}

// Special cases if the names in Excel don't match Bitrix exactly
$stageDict['1st interview'] = 'DT1038_14:UC_Z3P09M'; // 1st Interview
$stageDict['2nd interview'] = 'DT1038_14:UC_QXH80O'; // 2nd Interview
$stageDict['offer'] = 'DT1038_14:UC_QW0BFS';
$stageDict['joining'] = 'DT1038_14:UC_W15C0C';
// Feel free to add more manual mappings if data anomalies are found

$mapped_data = array_map(function($item) use ($mapping, $stageDict) {
    $mapped_item = [];
    foreach ($mapping as $excelKey => $bxKey) {
        if (isset($item[$excelKey])) {
            $val = $item[$excelKey];
            
            // Map the stage name to the Bitrix24 STATUS_ID
            if ($bxKey === 'stageId') {
                 $stageNameKey = strtolower(trim((string)$val));
                 if (isset($stageDict[$stageNameKey])) {
                     $val = $stageDict[$stageNameKey];
                 } else {
                     // Default to "New" stage if the name isn't found
                     // Assuming 'Start' or 'New' is the default
                     $val = 'DT1038_14:NEW'; 
                 }
            }
            
            $mapped_item[$bxKey] = $val;
        } else {
            $mapped_item[$bxKey] = null;
        }
    }
    
    // Explicitly set the categoryId to 14 for Entity 1038
    $mapped_item['categoryId'] = 14;
    
    return $mapped_item;
}, $raw_data);

echo "Saving to $outputFile...\n";
if (file_put_contents($outputFile, json_encode($mapped_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
    echo "Success! Mapped data saved to $outputFile\n";
} else {
    echo "Error: Failed to save mapped data.\n";
}

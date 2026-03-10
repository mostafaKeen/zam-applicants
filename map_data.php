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

$mapped_data = array_map(function($item) use ($mapping) {
    $mapped_item = [];
    foreach ($mapping as $excelKey => $bxKey) {
        if (isset($item[$excelKey])) {
            $mapped_item[$bxKey] = $item[$excelKey];
        } else {
            $mapped_item[$bxKey] = null;
        }
    }
    return $mapped_item;
}, $raw_data);

echo "Saving to $outputFile...\n";
if (file_put_contents($outputFile, json_encode($mapped_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
    echo "Success! Mapped data saved to $outputFile\n";
} else {
    echo "Error: Failed to save mapped data.\n";
}

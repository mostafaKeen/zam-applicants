<?php

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$inputFile = 'Zam Applicants.xlsx';
$outputFile = 'applicants.json';

if (!file_exists($inputFile)) {
    die("Error: Source file '$inputFile' not found.\n");
}

try {
    echo "Loading Excel file: $inputFile...\n";
    $spreadsheet = IOFactory::load($inputFile);
    $worksheet = $spreadsheet->getActiveSheet();
    
    // Get all rows as an array
    $rows = $worksheet->toArray();
    
    if (empty($rows)) {
        die("Error: The worksheet is empty.\n");
    }

    // Assume the first row is headers
    $headers = array_shift($rows);
    $data = [];

    foreach ($rows as $row) {
        $rowData = [];
        foreach ($headers as $index => $header) {
            // Map header names to values, handling empty cells
            $rowData[$header] = $row[$index] ?? null;
        }
        $data[] = $rowData;
    }

    echo "Converting to JSON...\n";
    $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if (file_put_contents($outputFile, $jsonContent)) {
        echo "Success! Data extracted to $outputFile\n";
    } else {
        echo "Error: Failed to write to $outputFile\n";
    }

} catch (Exception $e) {
    echo "Error processing file: " . $e->getMessage() . "\n";
}

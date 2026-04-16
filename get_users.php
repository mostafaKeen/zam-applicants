<?php

/**
 * Get Users from Bitrix24 using the user.get method.
 * Handles pagination to retrieve ALL users.
 * Results are saved to users.json.
 */

$webhookBase = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
$outputFile  = 'users.json';

// --- Optional filters (uncomment/modify as needed) ---
$filters = [
    // 'ACTIVE' => true,              // Only active users
    // 'UF_DEPARTMENT' => 1,          // Users in a specific department
    // 'USER_TYPE' => 'employee',     // Only employees (not extranet, etc.)
];

// --- FETCH ALL USERS WITH PAGINATION ---

$allUsers = [];
$start = 0;
$batchSize = 50; // Bitrix default page size

echo "Fetching users from Bitrix24...\n";

do {
    $params = array_merge($filters, [
        'start' => $start,
    ]);

    $result = sendRequest("$webhookBase/user.get", $params);

    if (!$result['success']) {
        echo "ERROR: " . $result['error'] . "\n";
        break;
    }

    $users = $result['data']['result'] ?? [];
    $total = $result['data']['total'] ?? 0;
    $allUsers = array_merge($allUsers, $users);

    echo "  Fetched " . count($allUsers) . " / $total users...\n";

    // Check if there are more pages
    $start = $result['data']['next'] ?? null;

    // Rate-limit protection
    usleep(500000);

} while ($start !== null);

// --- SAVE RESULTS ---

echo "\nTotal users retrieved: " . count($allUsers) . "\n";

file_put_contents($outputFile, json_encode($allUsers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Users saved to $outputFile\n";

// --- Print summary table ---
echo "\n--- USER LIST ---\n";
echo str_pad("ID", 8) . str_pad("Name", 30) . str_pad("Email", 35) . "Active\n";
echo str_repeat("-", 80) . "\n";

foreach ($allUsers as $user) {
    $id     = $user['ID'] ?? '-';
    $name   = trim(($user['NAME'] ?? '') . ' ' . ($user['LAST_NAME'] ?? ''));
    $email  = $user['EMAIL'] ?? '-';
    $active = ($user['ACTIVE'] ?? false) ? 'Yes' : 'No';

    echo str_pad($id, 8) . str_pad($name, 30) . str_pad($email, 35) . $active . "\n";
}

// --- HELPER FUNCTION ---

function sendRequest($url, $data = []) {
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

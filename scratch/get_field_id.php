<?php
$webhookBase = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';

function sendRequest($url, $params = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $response = curl_exec($ch);
    return json_decode($response, true);
}

echo "Fetching lead fields to find 'Created Date On Promax'...\n";
$res = sendRequest("$webhookBase/crm.lead.fields");

if (isset($res['result'])) {
    foreach ($res['result'] as $fieldId => $field) {
        if (stripos($field['editFormLabel'] ?? '', 'Promax') !== false || stripos($field['listLabel'] ?? '', 'Promax') !== false) {
            echo "Field ID: $fieldId | Label: " . ($field['editFormLabel'] ?? $field['listLabel'] ?? 'N/A') . "\n";
        }
    }
} else {
    echo "Error fetching fields: " . json_encode($res);
}

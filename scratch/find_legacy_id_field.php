<?php
$webhookBase = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
$idToSearch = '32582';

function sendRequest($url, $params) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $response = curl_exec($ch);
    return json_decode($response, true);
}

echo "Searching for EntityID $idToSearch across potential fields...\n";

// 1. Search by XML_ID
$res = sendRequest("$webhookBase/crm.lead.list", [
    'filter' => ['XML_ID' => $idToSearch],
    'select' => ['ID', 'TITLE', 'XML_ID']
]);
if (!empty($res['result'])) {
    echo "Found in XML_ID: " . json_encode($res['result'][0]) . "\n";
} else {
    echo "Not found in XML_ID.\n";
}

// 2. Search by custom fields (UF_*)
// We'll fetch one lead to see all UF fields and their values
$res = sendRequest("$webhookBase/crm.lead.list", [
    'order' => ['ID' => 'DESC'],
    'select' => ['*', 'UF_*'],
    'limit' => 5
]);

file_put_contents('recent_leads_full.json', json_encode($res['result'] ?? [], JSON_PRETTY_PRINT));
echo "Recent leads saved to recent_leads_full.json for inspection.\n";

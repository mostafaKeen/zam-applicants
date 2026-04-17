<?php
$webhookBase = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';

function sendRequest($url, $params) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $response = curl_exec($ch);
    return json_decode($response, true);
}

$name = "Kayraysie Bohgihtihnih";
echo "Searching for lead: $name\n";

$res = sendRequest("$webhookBase/crm.lead.list", [
    'filter' => ['TITLE' => $name], // Title often stores full name or lead name
    'select' => ['ID', 'TITLE', 'DATE_CREATE', 'NAME', 'LAST_NAME']
]);

print_r($res['result']);

// Also search by NAME/LAST_NAME just in case
$res = sendRequest("$webhookBase/crm.lead.list", [
    'filter' => ['NAME' => 'Kayraysie', 'LAST_NAME' => 'Bohgihtihnih'],
    'select' => ['ID', 'TITLE', 'DATE_CREATE', 'NAME', 'LAST_NAME']
]);

print_r($res['result']);

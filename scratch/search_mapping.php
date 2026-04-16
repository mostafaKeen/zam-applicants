<?php
$webhookBase = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
$idToSearch = 6269; 
$entityTypeId = 1038;

$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);

// Try searching by xmlId
echo "Searching by xmlId...\n";
$params = [
    'entityTypeId' => $entityTypeId,
    'filter' => ['xmlId' => (string)$idToSearch]
];
curl_setopt($ch, CURLOPT_URL, "$webhookBase/crm.item.list");
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
$res = json_decode(curl_exec($ch), true);
print_r($res['result']['items'] ?? "Not found in xmlId\n");

// Try searching by ufCrm8_1772195349103 (ID field)
echo "Searching by ufCrm8_1772195349103...\n";
$params = [
    'entityTypeId' => $entityTypeId,
    'filter' => ['ufCrm8_1772195349103' => $idToSearch]
];
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
$res = json_decode(curl_exec($ch), true);
print_r($res['result']['items'] ?? "Not found in ufCrm8_1772195349103\n");

// Try searching by title just in case
echo "Searching by title...\n";
$params = [
    'entityTypeId' => $entityTypeId,
    'filter' => ['%title' => (string)$idToSearch]
];
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
$res = json_decode(curl_exec($ch), true);
print_r($res['result']['items'] ?? "Not found in title\n");

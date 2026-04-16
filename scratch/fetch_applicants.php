<?php
$webhookBase = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
$ch = curl_init();
$params = [
    'entityTypeId' => 1038,
    'select' => ['*', 'UF_*'],
    'order' => ['ID' => 'DESC'],
    'limit' => 5
];
curl_setopt($ch, CURLOPT_URL, "$webhookBase/crm.item.list");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
$response = curl_exec($ch);
file_put_contents('recent_applicants.json', json_encode(json_decode($response, true)['result']['items'] ?? null, JSON_PRETTY_PRINT));
echo "Done";

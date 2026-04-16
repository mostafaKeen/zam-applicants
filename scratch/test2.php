<?php
$webhookBase = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$webhookBase/crm.type.list");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$response = curl_exec($ch);
file_put_contents('types.json', json_encode(json_decode($response, true)['result'] ?? null, JSON_PRETTY_PRINT));
echo "Done";

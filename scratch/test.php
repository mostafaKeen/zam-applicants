<?php
$webhookBase = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$webhookBase/crm.item.fields?entityTypeId=1044");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$response = curl_exec($ch);
file_put_contents('fields.json', json_encode(json_decode($response, true)['result']['fields'] ?? null, JSON_PRETTY_PRINT));
echo "Done";

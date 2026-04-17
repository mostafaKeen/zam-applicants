<?php
$webhookBase = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
function sendRequest($url, $params) {
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params)); $response = curl_exec($ch); return json_decode($response, true);
}
$res = sendRequest("$webhookBase/crm.lead.get", ['id' => 15350]);
echo "Lead 15350 Data:\n";
echo "XML_ID: [" . ($res['result']['XML_ID'] ?? 'NULL') . "]\n";
echo "UF_CRM_XML_ID: [" . ($res['result']['UF_CRM_XML_ID'] ?? 'NULL') . "]\n";
echo "--- Full Lead Result --- \n";
print_r($res['result']);

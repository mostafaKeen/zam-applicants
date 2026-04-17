<?php
$webhookBase = 'https://zamprime.bitrix24.com/rest/16/bfumzpzx61oc5s6o';
$promaxDateField = 'UF_CRM_1773428473';

function sendRequest($url, $params) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $response = curl_exec($ch);
    return json_decode($response, true);
}

$name = "Kayraysie Bohgihtihnih";
echo "Debugging match for: $name\n";

$res = sendRequest("$webhookBase/crm.lead.list", [
    'filter' => ['TITLE' => $name],
    'select' => ['ID', 'TITLE', $promaxDateField]
]);

if (!empty($res['result'])) {
    foreach ($res['result'] as $lead) {
        echo "BITRIX LEAD FOUND:\n";
        echo "  ID: " . $lead['ID'] . "\n";
        echo "  TITLE: [" . $lead['TITLE'] . "]\n";
        echo "  DATE: [" . $lead[$promaxDateField] . "]\n";
        echo "  KEY: [" . trim($lead['TITLE']) . "|" . trim($lead[$promaxDateField]) . "]\n";
    }
} else {
    echo "Lead not found in Bitrix24.\n";
}

// Also check Excel mapping for this lead
$eid = "32582";
$mapping = json_decode(file_get_contents('entity_to_name_date.json'), true);
if (isset($mapping[$eid])) {
    $ref = $mapping[$eid];
    echo "EXCEL MAPPING FOUND:\n";
    echo "  NAME: [" . $ref['name'] . "]\n";
    echo "  DATE: [" . $ref['created'] . "]\n";
    echo "  KEY: [" . $ref['name'] . "|" . $ref['created'] . "]\n";
}

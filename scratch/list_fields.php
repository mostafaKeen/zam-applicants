<?php
$fields = json_decode(file_get_contents('fields_1038.json'), true);
foreach ($fields as $k => $v) {
    if (strpos($k, 'ufCrm') !== false) {
        echo str_pad($k, 25) . ": " . $v['title'] . "\n";
    }
}

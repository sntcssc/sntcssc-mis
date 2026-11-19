<?php

include 'Crypto.php';
$workingKey = '';		// Working Key should be provided here.
$encResponse = $_POST['encResp'];			// This is the response sent by the CCAvenue Server
$rcvdString = decrypt($encResponse, $workingKey);	// Crypto Decryption used as per the specified working key.
$order_status = '';
$decryptValues = explode('&', $rcvdString);
$dataSize = count($decryptValues);
echo '<center>';
for ($i = 0; $i < $dataSize; $i++) {
    $information = explode('=', $decryptValues[$i]);
    if ($i == 3) {
        $order_status = $information[1];
    }
}
echo '<br>Decrypted Response<br><br>';
echo '<table cellspacing=4 cellpadding=4>';
for ($i = 0; $i < $dataSize; $i++) {
    $information = explode('=', $decryptValues[$i]);
    echo '<tr><td>'.$information[0].'</td><td>'.$information[1].'</td></tr>';
}
echo '</table><br>';
echo '</center>';

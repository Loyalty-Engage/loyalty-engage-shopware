<?php

// Script to update the order delivery state using curl to directly call the Shopware API

// Get the order ID and delivery ID from the command line arguments
$orderId = isset($argv[1]) ? $argv[1] : '';
$deliveryId = isset($argv[2]) ? $argv[2] : '';
$newState = 'done'; // The new state to set (use 'done' instead of 'shipped')

if (empty($orderId) || empty($deliveryId)) {
    echo "Usage: php update-order-state.php <order-id> <delivery-id>\n";
    exit(1);
}

// Log the attempt
$logFile = '/var/www/html/var/log/dev.log';
$logMessage = '[' . date('Y-m-d H:i:s') . '] app.INFO: Attempting to update order delivery state: ' . json_encode([
    'orderId' => $orderId,
    'deliveryId' => $deliveryId,
    'newState' => $newState
]) . PHP_EOL;
file_put_contents($logFile, $logMessage, FILE_APPEND);

// Get an access token
$accessToken = getAccessToken();

// Use curl to directly call the Shopware API
$url = "http://localhost/api/_action/order_delivery/{$deliveryId}/state/{$newState}";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Bearer ' . $accessToken
]);

// Execute the request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Output the result
echo "HTTP Code: {$httpCode}\n";
echo "Response: {$response}\n";
echo "Error: {$error}\n";

// Log the result
$logMessage = '[' . date('Y-m-d H:i:s') . '] app.INFO: Order delivery state update result: ' . json_encode([
    'orderId' => $orderId,
    'deliveryId' => $deliveryId,
    'newState' => $newState,
    'httpCode' => $httpCode,
    'response' => $response,
    'error' => $error
]) . PHP_EOL;
file_put_contents($logFile, $logMessage, FILE_APPEND);

/**
 * Get an access token from the Shopware API
 */
function getAccessToken() {
    $url = 'http://localhost/api/oauth/token';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'password',
        'client_id' => 'administration',
        'scopes' => 'write',
        'username' => 'admin',
        'password' => 'shopware'
    ]));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "Error getting access token: " . $response . "\n";
        exit(1);
    }

    $data = json_decode($response, true);
    return $data['access_token'];
}

<?php
/**
 * WHMCS RapidGateway Callback / Webhook Handler
 *
 * Implements official WHMCS Callback specifications:
 * @see https://developers.whmcs.com/payment-gateways/callbacks/
 * @see https://github.com/WHMCS/sample-gateway-module/blob/master/modules/gateways/callback/gatewaymodule.php
 *
 * Steps performed:
 * 1. Require WHMCS libraries (init.php, gatewayfunctions.php, invoicefunctions.php)
 * 2. Fetch Gateway parameters & verify module active
 * 3. Validate callback authenticity (HMAC-SHA256 & 5-minute replay window)
 * 4. Validate Invoice ID via checkCbInvoiceID()
 * 5. Check Transaction ID via checkCbTransID()
 * 6. Log Transaction via logTransaction()
 * 7. Apply Payment to invoice via addInvoicePayment()
 *
 * @copyright Copyright (c) Zedgehost / RapidGateway
 * @license MIT
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    http_response_code(400);
    die("Module Not Activated");
}

// Retrieve raw request body and RapidGateway security headers
$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAPIDGATEWAY_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_RAPIDGATEWAY_TIMESTAMP'] ?? '';
$eventType = $_SERVER['HTTP_X_RAPIDGATEWAY_EVENT'] ?? '';

if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

$webhookSecret = trim($gatewayParams['webhookSecret']);
$success = true;
$transactionStatus = 'Success';

/**
 * 1. Validate callback authenticity
 *
 * Recipe: HMAC_SHA256( secret, timestamp + "." + raw_request_body ) // hex, UPPERCASE
 * Must be within 5-minute window (|now - timestamp| <= 300s)
 */
if (!empty($webhookSecret)) {
    $now = time();
    $requestTime = is_numeric($timestamp) ? (int)$timestamp : strtotime($timestamp);

    if (empty($signature) || empty($timestamp)) {
        $transactionStatus = 'Signature or Timestamp Header Missing';
        $success = false;
    } elseif ($requestTime && abs($now - $requestTime) > 300) {
        $transactionStatus = 'Timestamp Expired (Replay Protection)';
        $success = false;
    } else {
        $expectedSignature = strtoupper(hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret));
        if (!hash_equals($expectedSignature, strtoupper($signature))) {
            $transactionStatus = 'HMAC Signature Verification Failed';
            $success = false;
        }
    }

    if (!$success) {
        logTransaction($gatewayParams['name'], [
            'status'   => $transactionStatus,
            'received' => $signature,
            'body'     => $rawBody,
        ], $transactionStatus);

        http_response_code(401);
        echo json_encode(['error' => $transactionStatus]);
        exit;
    }
}

// 2. Parse Webhook JSON Data
$payload = json_decode($rawBody, true);
if (!$payload || !is_array($payload)) {
    logTransaction($gatewayParams['name'], ['raw' => $rawBody], 'Malformed JSON');
    http_response_code(400);
    echo json_encode(['error' => 'Malformed JSON']);
    exit;
}

$eventId        = $payload['eventId'] ?? '';
$eventType      = $payload['eventType'] ?? '';
$gatewayTxnRef  = $payload['gatewayTxnRef'] ?? '';
$basketId       = $payload['merchantTransactionId'] ?? '';
$status         = strtoupper($payload['status'] ?? '');
$paymentAmount  = (float)($payload['amount'] ?? 0);
$paymentFee     = 0.00;

// Extract numeric invoice id from BASKET_ID (Format: INV<id>-<time>)
$rawInvoiceId = 0;
if (preg_match('/^INV(\d+)/i', $basketId, $matches)) {
    $rawInvoiceId = (int)$matches[1];
} elseif (is_numeric($basketId)) {
    $rawInvoiceId = (int)$basketId;
}

/**
 * 3. Validate Callback Invoice ID
 * Checks invoice ID is a valid invoice number. Performs a die upon encountering an invalid Invoice ID.
 */
$invoiceId = checkCbInvoiceID($rawInvoiceId, $gatewayParams['name']);

// Handle transaction outcomes
if ($eventType === 'transaction.completed' && $status === 'SUCCESS') {
    // Unique transaction reference to store in WHMCS
    $transactionId = !empty($gatewayTxnRef) ? $gatewayTxnRef : $basketId;

    /**
     * 4. Check Callback Transaction ID
     * Prevents duplicate payments for the same transaction reference.
     */
    checkCbTransID($transactionId);

    /**
     * 5. Log Transaction in WHMCS Gateway Log
     */
    logTransaction($gatewayParams['name'], $payload, 'Successful Payment');

    /**
     * 6. Add Payment to Invoice
     */
    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $paymentAmount,
        $paymentFee,
        $gatewayModuleName
    );

    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Invoice payment recorded']);
    exit;
} elseif ($eventType === 'transaction.failed' || $status === 'FAILED') {
    logTransaction($gatewayParams['name'], $payload, 'Payment Failed');
    http_response_code(200);
    echo json_encode(['status' => 'acknowledged', 'message' => 'Payment failure logged']);
    exit;
} elseif (strpos($eventType, 'refund.') === 0) {
    logTransaction($gatewayParams['name'], $payload, 'Refund Webhook Event: ' . $eventType);
    http_response_code(200);
    echo json_encode(['status' => 'acknowledged']);
    exit;
}

// Log any unhandled event types
logTransaction($gatewayParams['name'], $payload, 'Unhandled Event: ' . $eventType);
http_response_code(200);
echo json_encode(['status' => 'ignored']);
exit;

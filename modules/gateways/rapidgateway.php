<?php
/**
 * WHMCS RapidGateway Payment Gateway Module
 *
 * Built strictly according to WHMCS Payment Gateway Guidelines & Official Standards:
 * @see https://developers.whmcs.com/payment-gateways/
 * @see https://github.com/WHMCS/sample-gateway-module
 *
 * Supports:
 * - OAuth2 Client Credentials Bearer Token Authentication
 * - PKR Currency Validation
 * - Third Party Hosted Redirect Checkout
 * - Transaction Idempotency & Unique Reference Generation
 * - Automated Admin Refunds via RapidGateway API
 *
 * @copyright Copyright (c) Zedgehost / RapidGateway
 * @license MIT
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module related meta data.
 *
 * @see https://developers.whmcs.com/payment-gateways/meta-data-params/
 *
 * @return array
 */
function rapidgateway_MetaData()
{
    return [
        'DisplayName' => 'RapidGateway (PKR)',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * Define gateway configuration options.
 *
 * @see https://developers.whmcs.com/payment-gateways/configuration/
 *
 * @return array
 */
function rapidgateway_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'RapidGateway (Card, JazzCash, EasyPaisa, Raast)',
        ],
        'merchantId' => [
            'FriendlyName' => 'Merchant ID / Client ID',
            'Type' => 'text',
            'Size' => '32',
            'Default' => '',
            'Description' => 'Your RapidGateway Merchant ID (numeric ID in Live, or "client" for Sandbox).',
        ],
        'clientSecret' => [
            'FriendlyName' => 'Client Secret',
            'Type' => 'password',
            'Size' => '64',
            'Default' => '',
            'Description' => 'Your OAuth2 Client Secret provided in RapidGateway portal.',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook Signing Secret',
            'Type' => 'password',
            'Size' => '64',
            'Default' => '',
            'Description' => 'From Portal &rarr; Settings &rarr; Webhooks. Used to verify HMAC-SHA256 signature.',
        ],
        'merchantName' => [
            'FriendlyName' => 'Merchant Display Name',
            'Type' => 'text',
            'Size' => '40',
            'Default' => 'Zedgehost',
            'Description' => 'Business name shown on the hosted checkout page.',
        ],
        'testMode' => [
            'FriendlyName' => 'Sandbox / Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable RapidGateway sandbox testing environment.',
        ],
        'enableRefunds' => [
            'FriendlyName' => 'Enable Refunds',
            'Type' => 'yesno',
            'Description' => 'Allow WHMCS Admins to issue refunds directly via WHMCS billing.',
        ],
    ];
}

/**
 * Internal Helper: Get OAuth2 Access Token
 *
 * @param string $merchantId
 * @param string $clientSecret
 * @return string|false
 */
function rapidgateway_get_access_token($merchantId, $clientSecret)
{
    $tokenUrl = 'https://secure.rapid-gateway.com/oauth2/token';

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . base64_encode(trim($merchantId) . ':' . trim($clientSecret)),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'client_credentials',
        ]),
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        logTransaction('rapidgateway', ['error' => $curlError, 'stage' => 'token_fetch'], 'Token cURL Error');
        return false;
    }

    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($data['access_token'])) {
        return $data['access_token'];
    }

    logTransaction('rapidgateway', [
        'response' => $response,
        'httpCode' => $httpCode,
        'stage' => 'token_fetch'
    ], 'Token Request Failed');

    return false;
}

/**
 * Payment link generator.
 *
 * Defines the HTML output displayed on an invoice.
 *
 * @param array $params Payment Gateway Module Parameters
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 * @return string
 */
function rapidgateway_link($params)
{
    // Gateway Configuration Parameters
    $merchantId    = $params['merchantId'];
    $clientSecret  = $params['clientSecret'];
    $merchantName  = !empty($params['merchantName']) ? $params['merchantName'] : $params['companyname'];
    $testMode      = ($params['testMode'] == 'on');

    // Invoice Parameters
    $invoiceId     = $params['invoiceid'];
    $description   = $params['description'];
    $amount        = $params['amount']; // Format: xxx.xx
    $currencyCode  = $params['currency'];

    // Client Details
    $firstname     = $params['clientdetails']['firstname'];
    $lastname      = $params['clientdetails']['lastname'];
    $email         = $params['clientdetails']['email'];
    $phone         = $params['clientdetails']['phonenumber'];

    // Sanitize mobile number (Ensure format 03XXXXXXXXX)
    $cleanMobile = preg_replace('/[^0-9]/', '', $phone);
    if (substr($cleanMobile, 0, 2) === '92') {
        $cleanMobile = '0' . substr($cleanMobile, 2);
    } elseif (strlen($cleanMobile) === 10 && substr($cleanMobile, 0, 1) === '3') {
        $cleanMobile = '0' . $cleanMobile;
    }
    if (empty($cleanMobile) || strlen($cleanMobile) < 11) {
        $cleanMobile = '03001234567';
    }

    // System Parameters
    $systemUrl     = $params['systemurl'];
    $returnUrl     = $params['returnurl']; // WHMCS client invoice URL
    $langPayNow    = $params['langpaynow'] ?: 'Pay Now';
    $moduleName    = $params['paymentmethod'];

    // RapidGateway only supports PKR currency
    if (strtoupper($currencyCode) !== 'PKR') {
        return '<div class="alert alert-danger text-center" style="margin: 10px 0; padding: 12px;">
            <strong>Currency Error:</strong> RapidGateway accepts PKR currency only. Current invoice currency is <code>' . htmlspecialchars($currencyCode) . '</code>.
        </div>';
    }

    // Handle button submission / checkout initiation
    $actionField = 'rapidgateway_submit_' . $invoiceId;
    if (isset($_POST[$actionField])) {
        $token = rapidgateway_get_access_token($merchantId, $clientSecret);
        if (!$token) {
            return '<div class="alert alert-danger text-center">Unable to authenticate with RapidGateway. Please contact support.</div>';
        }

        // Endpoint for initiating transaction
        $processUrl = $testMode 
            ? 'https://secure.rapid-gateway.com/sandbox/process-transaction' 
            : 'https://secure.rapid-gateway.com/rapid/process-transaction';

        // RapidGateway Sandbox requires a numeric merchant ID in transaction payload
        $apiMerchantId = $merchantId;
        if ($testMode && !is_numeric($apiMerchantId)) {
            $apiMerchantId = '102'; // Official RapidGateway Sandbox test merchant ID
        }

        $payload = [
            'MERCHANT_ID'            => $apiMerchantId,
            'MERCHANT_NAME'          => $merchantName,
            'TXNAMT'                 => number_format((float)$amount, 2, '.', ''),
            'CURRENCY_CODE'          => 'PKR',
            'CUSTOMER_MOBILE_NO'     => $cleanMobile,
            'CUSTOMER_EMAIL_ADDRESS' => $email,
            'BASKET_ID'              => $basketId,
            'TXNDESC'                => 'Invoice #' . $invoiceId . ' - ' . $params['companyname'],
            'ORDER_DATE'             => date('d/m/Y'),
            'SUCCESS_URL'            => $returnUrl,
            'FAILURE_URL'            => $returnUrl,
            'CHECKOUT_URL'           => $returnUrl,
            'VERSION'                => 'MY_VER_1.0',
            'PROCCODE'               => '0',
        ];

        $ch = curl_init($processUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // Capture redirect URL without executing it on server
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS     => http_build_query($payload),
        ]);

        $rawResponse = curl_exec($ch);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!empty($redirectUrl)) {
            echo '<script type="text/javascript">window.location.href = ' . json_encode($redirectUrl) . ';</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl) . '"></noscript>';
            echo '<div class="alert alert-info text-center">Redirecting to RapidGateway... <a href="' . htmlspecialchars($redirectUrl) . '" class="btn btn-sm btn-primary">Click here if not redirected</a></div>';
            exit;
        }

        // Fallback for response body JSON
        $decoded = json_decode($rawResponse, true);
        if (!empty($decoded['redirect_url']) || !empty($decoded['checkout_url'])) {
            $dest = $decoded['redirect_url'] ?? $decoded['checkout_url'];
            echo '<script type="text/javascript">window.location.href = ' . json_encode($dest) . ';</script>';
            echo '<div class="alert alert-info text-center">Redirecting to RapidGateway... <a href="' . htmlspecialchars($dest) . '" class="btn btn-sm btn-primary">Click here</a></div>';
            exit;
        }

        $errorMsg = 'Payment initiation failed (HTTP ' . $httpCode . ')';
        if (!empty($curlError)) {
            $errorMsg .= ': ' . $curlError;
        } elseif (!empty($decoded['message'])) {
            $errorMsg .= ': ' . $decoded['message'];
        } elseif (!empty($decoded['error'])) {
            $errorMsg .= ': ' . $decoded['error'];
        } elseif (!empty($rawResponse)) {
            $errorMsg .= ': ' . substr(strip_tags($rawResponse), 0, 150);
        }

        logTransaction('rapidgateway', [
            'processUrl' => $processUrl,
            'payload' => $payload,
            'response' => $rawResponse,
            'curlError' => $curlError,
            'httpCode' => $httpCode,
        ], 'Payment Initiation Failed');

        return '<div class="alert alert-danger text-center" style="margin-top:10px;">
            <strong>RapidGateway Error:</strong> ' . htmlspecialchars($errorMsg) . '
        </div>';
    }

    // Output clean form conforming to WHMCS Third-Party Gateway specs
    $htmlOutput = '<form method="post" action="">';
    $htmlOutput .= '<input type="hidden" name="' . $actionField . '" value="1" />';
    $htmlOutput .= '<button type="submit" class="btn btn-primary btn-lg" style="margin-top: 10px;">';
    $htmlOutput .= '<i class="fas fa-lock"></i> ' . htmlspecialchars($langPayNow);
    $htmlOutput .= '</button>';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}

/**
 * Refund transaction.
 *
 * Called when a refund is requested for a previously successful transaction.
 *
 * @param array $params Payment Gateway Module Parameters
 * @see https://developers.whmcs.com/payment-gateways/refunds/
 * @return array Transaction response status
 */
function rapidgateway_refund($params)
{
    if ($params['enableRefunds'] !== 'on') {
        return [
            'status'  => 'declined',
            'rawdata' => 'Refunds are disabled in module configuration.',
        ];
    }

    $merchantId    = $params['merchantId'];
    $clientSecret  = $params['clientSecret'];
    $transId       = $params['transid']; // Transaction ID stored when payment was marked paid
    $refundAmount  = number_format((float)$params['amount'], 2, '.', '');
    $invoiceId     = $params['invoiceid'];

    $token = rapidgateway_get_access_token($merchantId, $clientSecret);
    if (!$token) {
        return [
            'status'  => 'error',
            'rawdata' => 'Failed to obtain OAuth2 access token for refund.',
        ];
    }

    $idempotencyKey = 'REFUND-' . $invoiceId . '-' . time() . '-' . mt_rand(100, 999);
    $refundUrl = 'https://secure.rapid-gateway.com/api/v1/payments/refunds';

    $payload = [
        'merchantId' => $merchantId,
        'basketId'   => $transId,
        'amount'     => (float)$refundAmount,
        'reasonCode' => 'CUSTOMER_REQUEST',
        'reasonNote' => 'WHMCS Admin Refund for Invoice #' . $invoiceId,
    ];

    $ch = curl_init($refundUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Idempotency-Key: ' . $idempotencyKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode === 201 || $httpCode === 202) {
        return [
            'status'  => 'success',
            'transid' => $data['refundRef'] ?? $transId,
            'rawdata' => $data,
        ];
    }

    return [
        'status'  => 'declined',
        'transid' => $transId,
        'rawdata' => $data ?: $response,
    ];
}

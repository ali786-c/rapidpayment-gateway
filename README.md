# RapidGateway Payment Module for WHMCS

Complete integration of **RapidGateway (PKR / OAuth2)** for WHMCS.

## Features Included
1. **OAuth2 Bearer Token Integration**: Fetches dynamic tokens via HTTP Basic Auth.
2. **Hosted Checkout Process**: Redirects customers to RapidGateway secure payment checkout.
3. **PKR Currency Validation**: Prevents payment attempts in unsupported currencies.
4. **Idempotency & Invoice Correlation**: Uses unique `INV<id>-<timestamp>` keys in `BASKET_ID` to prevent duplicate transaction errors.
5. **Signed Webhook / Callback Handler**:
   - `HMAC_SHA256( secret, timestamp + "." + raw_request_body )` verification.
   - 5-minute replay window validation (`|now - timestamp| <= 300s`).
   - Idempotency & Duplicate transaction check (`checkCbTransID`).
   - Auto marks WHMCS Invoices as **PAID** (`addInvoicePayment`).
   - Detailed gateway activity logging (`logTransaction`).
6. **Refunds Support**: Allows WHMCS admins to trigger full or partial refunds directly from the invoice screen via RapidGateway Refund API.

---

## Installation & Setup

### Step 1: Upload Files to WHMCS
Copy the folder `modules/` into your WHMCS root directory:
```
whmcs/
└── modules/
    └── gateways/
        ├── rapidgateway.php
        └── callback/
            └── rapidgateway.php
```

### Step 2: Configure in WHMCS Admin
1. Go to **Setup &rarr; Payments &rarr; Payment Gateways** (or **Configuration &rarr; System Settings &rarr; Payment Gateways** in WHMCS 8.x).
2. Click the **All Payment Gateways** tab.
3. Find **RapidGateway (PKR)** and click to activate it.
4. Fill in the following credentials:
   - **Show on Order Form**: Checked.
   - **Display Name**: `RapidGateway (Credit/Debit Card, EasyPaisa, JazzCash, Raast)`.
   - **Merchant ID / Client ID**: Your numeric Merchant ID (e.g. `102` or `client` for Sandbox).
   - **Client Secret**: Your OAuth2 Client Secret.
   - **Webhook Signing Secret**: Secret copied from RapidGateway Portal (*Settings &rarr; Webhooks*).
   - **Merchant Display Name**: E.g. `Zedgehost`.
   - **Sandbox / Test Mode**: Checked during testing, uncheck for live production.
   - **Enable Refunds**: Optional (allows refunds via admin).
5. Click **Save Changes**.

---

### Step 3: Register Webhook in RapidGateway Dashboard
1. Log in to your [RapidGateway Merchant Portal](https://secure.rapid-gateway.com).
2. Navigate to **Dashboard &rarr; Settings &rarr; Webhooks**.
3. Set your Webhook URL to:
   ```
   https://yourdomain.com/modules/gateways/callback/rapidgateway.php
   ```
4. Copy the **Signing Secret** and paste it into the **Webhook Signing Secret** field in your WHMCS Gateway configuration.
5. Save settings in both portals.

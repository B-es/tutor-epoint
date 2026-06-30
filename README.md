# TE Pay

**Author:** B_es
**GitHub:** [https://github.com/B-es](https://github.com/B-es)
**Plugin Repository:** [https://github.com/B-es/tutor-epoint](https://github.com/B-es/tutor-epoint)

TE Pay integrates Epoint.az with Tutor LMS. This plugin enables one-time course payments through the Epoint payment gateway.

## Features

- One-time payments for course purchases
- AZN currency support
- Signature-based request verification (SHA1 + Base64)
- Webhook (callback) processing with signature validation
- Automatic student enrollment after successful payment
- Enrollment confirmation email sent to the student
- Idempotent webhook handling (prevents duplicate processing)
- Sandbox and Live environment support
- Internationalization (i18n) support

## Minimum Requirements

- WordPress 5.3 or higher
- PHP 7.4 or higher
- Tutor LMS (Free version)
- Epoint.az merchant account

## Installation

1. Upload the plugin folder to `/wp-content/plugins`
2. Activate the plugin through WordPress admin
3. Ensure Tutor LMS is activated
4. Configure settings in Tutor LMS > Settings > Payments

## Configuration

### Step 1: Get Epoint Credentials

1. Register as a merchant at [Epoint.az](https://epoint.az)
2. Provide the following information to Epoint:
   - Your website URL
   - Success redirect URL (`success_redirect_url`)
   - Error redirect URL (`error_redirect_url`)
   - Callback URL (`result_url`)
3. Receive your access keys:
   - `public_key` — merchant identifier (e.g., `i000000001`)
   - `private_key` — secret key for API signatures

### Step 2: Configure Plugin

1. Go to **Tutor LMS > Settings > Payments**
2. Find **Epoint** in the payment gateways list
3. Enable and configure:
   - **Public Key**: Enter your Epoint Public Key
   - **Private Key**: Enter your Epoint Private Key
   - **Result URL**: Copy this URL and add it to your Epoint merchant panel

### Step 3: Configure Epoint Merchant Panel

1. Login to your Epoint merchant panel
2. Add the Result URL from step 2
3. Save settings

## How It Works

### Payment Flow

```
Student clicks "Purchase"
    ↓
Plugin prepares payment data (order_id, amount, currency, description)
    ↓
Plugin generates signature: base64(sha1(private_key + data + private_key))
    ↓
POST request to https://epoint.az/api/1/request
    ↓
Student redirected to bank payment page
    ↓
Student enters card details and confirms payment
    ↓
Student redirected to success_redirect_url or error_redirect_url
    ↓
Epoint sends POST callback to result_url (webhook)
    ↓
Plugin verifies signature
    ↓
Plugin updates order status (paid / failed)
    ↓
Student gets access to course
    ↓
Student receives enrollment confirmation email
```

### Security Features

1. **Signature Verification**: Every request and callback is verified using `base64(sha1(private_key + data + private_key))`
2. **Idempotency**: Orders are marked as processed to prevent duplicate webhook handling
3. **HTTPS Communication**: All API calls use HTTPS

### Enrollment Email

After successful payment the plugin automatically sends a confirmation email to the student's billing email address. The email contains the course name and order number. Sending is idempotent — the email is sent only once per order.

## Testing

### Test Transaction Flow

1. Create a test course in your LMS
2. Set a price for the course
3. Add the course to cart and proceed to checkout
4. Select Epoint as payment method
5. Complete payment on the Epoint payment page
6. Verify order status in Tutor LMS and check that the student is enrolled

## Supported Currency

- AZN (Azerbaijani Manat) — the only currency supported by Epoint

## API Integration Details

### Payment Initiation (Checkout)
- **Endpoint**: `https://epoint.az/api/1/request`
- **Method**: POST
- **Parameters**: `data` (base64-encoded JSON), `signature`
- **Response**: `{ status, redirect_url }`

### Payment Status Check
- **Endpoint**: `https://epoint.az/api/1/get-status`
- **Method**: POST
- **Parameters**: `data`, `signature`
- **Response**: `{ status, transaction, code, message, ... }`

### IPN Callback (Webhook)
- Epoint sends POST to `result_url` with `data` and `signature`
- Plugin verifies signature and decodes `data`
- Order is updated and student is enrolled

### Signature Formula

```
data      = base64_encode(json_string)
signature = base64_encode(sha1(private_key + data + private_key, true))
```

## Payment Statuses

| Epoint Status | Plugin Status |
|---------------|--------------|
| `success`     | `paid`       |
| `VALID`       | `paid`       |
| `VALIDATED`   | `paid`       |
| `new`         | `pending`    |
| `PENDING`     | `pending`    |
| `returned`    | `refunded`   |
| `error`       | `failed`     |
| `FAILED`      | `failed`     |
| `CANCELLED`   | `cancelled`  |

## Troubleshooting

### Payment Not Processing

1. **Check Credentials**: Ensure Public Key and Private Key are correct
2. **Result URL**: Verify Result URL is correctly configured in the Epoint panel
3. **SSL Certificate**: Ensure your site has a valid SSL certificate

### Transaction Validation Failed

1. Check if Result URL is accessible (not blocked by firewall)
2. Enable debug logging in WordPress (`WP_DEBUG`)
3. Check error logs — the plugin writes detailed step-by-step entries

### Order Status Not Updating

1. Verify webhook (Result URL) is configured correctly
2. Check that signature verification is passing (see error log)
3. Check webhook response in server logs

### Student Not Receiving Enrollment Email

1. Confirm the student has a `billing_email` in the Tutor LMS customer record
2. Check `_enrollment_email_sent` post meta — if set to `yes` the email was already sent
3. Verify WordPress is able to send mail (`wp_mail`)

## Known Limitations

1. **No Subscription Support**: Epoint does not provide native recurring payment functionality
2. **Refunds**: Manual refund processing through the Epoint merchant panel is required
3. **Language**: Payment interface language defaults to Russian (`ru`)

## Changelog

### Version 1.0.7
- **Feature**: Automatic enrollment confirmation email to student after payment
- **Feature**: Idempotent email sending (`_enrollment_email_sent` meta flag)
- **Fix**: Corrected checkout endpoint to `/api/1/request`
- **Fix**: Corrected redirect URL field names (`success_redirect_url`, `error_redirect_url`)
- **Improvement**: Detailed step-by-step logging throughout payment and enrollment flow
- **Improvement**: Code cleanup and optimization

### Version 1.0.6
- **Feature**: Added complete internationalization (i18n) support
- **Improvement**: Updated plugin constants and code structure

### Version 1.0.5
- Minor fixes and improvements

### Version 1.0.4
- Minor fixes and improvements

### Version 1.0.3
- **Improvement**: Replaced cURL with WordPress HTTP API
- **Improvement**: Enhanced error handling and JSON validation

### Version 1.0.2
- **Security**: Fixed fatal errors in IPN handling
- **Security**: Improved validation for webhook requests

### Version 1.0.1
- **Fix**: Corrected payment amount sending
- **Fix**: Updated to use correct Tutor LMS field names

### Version 1.0.0
- Initial release
- One-time payment support
- Webhook integration
- Transaction validation

## Support

For issues related to:
- **Plugin functionality**: Create issue on [GitHub](https://github.com/B-es/tutor-epoint)
- **Epoint API**: Contact Epoint support via [epoint.az](https://epoint.az)
- **Tutor LMS**: Contact Themeum support

## License

This plugin is licensed under GPLv2 or later.

## Credits

- Developed for Tutor LMS
- Epoint.az API integration
- Based on Tutor LMS Payment Gateway framework

## Additional Resources

- [Epoint.az Website](https://epoint.az)
- [Tutor LMS Documentation](https://docs.themeum.com/tutor-lms/)

## Setup

This extension replaces the uk.co.circleinteractive.payment.bitcoin.

#### Create an account
Go to https://test.bitpay.com and create an account. Verify account.

#### Create an API token
Go to https://test.bitpay.com/dashboard/merchant/api-tokens and create a new API token. Make a note of the pairing key.

#### Enable extension
1. Enable the bitpay extension in CiviCRM.
1. Create a new payment processor of type "Bitpay".
    1. Enter a "Private Key decryption password" of your choice.
    1. Enter any value for "API Key" - we don't use this.
    1. Don't enter anything for "Pairing token".
    1. Make a note of the payment processor ID (and the test ID).

#### Pair with Bitpay API
1. Run CiviCRM API Bitpay.createkeys with parameters:
    * payment_processor_id={id}
2. Run CiviCRM API Bitpay.pair with parameters:
    * payment_processor_id={id}
    * pairingkey={pairing key you created at bitpay earlier}


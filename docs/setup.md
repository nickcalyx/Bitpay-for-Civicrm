## Setup

*This extension replaces the uk.co.circleinteractive.payment.bitcoin extension. Uninstall that extension before installing this one.*

1. Enable the bitpay extension in CiviCRM.
1. Go to the CiviCRM system status page and ensure that there are no missing requirements for the bitpay extension:
![requirements](/docs/images/bitpay_missingrequirements.png)

#### Create an account
If you don't already have a bitpay account:

##### Live: https://bitpay.com  
1. Create an account.
1. Verify the account.

##### Test: https://test.bitpay.com
1. Create an account.
1. Verify the account.

#### Create an API token
You need to login to your account at bitpay to create an API token for use with the CiviCRM extension.

##### Live: https://bitpay.com/dashboard/merchant/api-tokens
##### Test: https://test.bitpay.com/dashboard/merchant/api-tokens

1. Follow the instructions there to create a new API token.
    * Make sure "Require Authentication" is enabled.
1. Make a note of the pairing key.

#### Enable extension

1. Create a new payment processor of type "Bitpay".
    1. Enter a "Private Key decryption password" of your choice.
    1. Enter any value for "API Key" - we don't use this.
    1. Don't enter anything for "Pairing token".
    1. Make a note of the payment processor ID (and the test ID) (see info here for how to find this: https://docs.civicrm.org/sysadmin/en/latest/setup/payment-processors/recurring/ - you can see the ID of the live processor by hovering over the edit link for that processor)

#### Pair with Bitpay API
1. Run CiviCRM API Bitpay.createkeys with parameters:
    * payment_processor_id={id}
2. Run CiviCRM API Bitpay.pair with parameters:
    * payment_processor_id={id}
    * pairingkey={pairing key you created at bitpay earlier}

## Troubleshooting
If you have trouble installing after uninstalling uk.co.circleinteractive.payment.bitcoin you can try running:

API `bitpay.checkinstall`

This will check that the new extension has everything it needs to function.


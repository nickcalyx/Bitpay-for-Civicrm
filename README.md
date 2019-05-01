# bitpay

Accept payments using bitpay (https://bitpay.com/) through CiviCRM.

The extension is licensed under [AGPL-3.0](LICENSE.txt).

![Screenshot](/docs/images/bitpay_invoicewaiting.png)

## Requirements

* PHP v7.0+
* CiviCRM 5.10

## Installation

Download and install the extension in the standard way.

Follow instructions here to configure pairing with bitpay: [docs/setup.md](/docs/setup.md)


## Known Issues
### Event Registration does not work
* We don't have a contribution available until payment has been processed: https://github.com/civicrm/civicrm-core/pull/13763
* Cannot easily insert Bitpay billing block because there is no crmRegion for event workflow: https://github.com/civicrm/civicrm-core/pull/13762

### No support for (drupal) webform_civicrm
* Webform_civicrm does not load the "thankyou" page so we would need to add a separate method to load the Bitpay invoice for webform_civicrm.


## Potential future development
* Show amount in bitcoin on the contribution pages?
* Record when invoice status changes from pending->paid->confirmed->completed? (We mark completed when it gets confirmed).
* Record amount paid in BTC on contribution record?

# bitpay

Accept payments using bitpay (https://bitpay.com/) through CiviCRM

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v7.0+
* CiviCRM 5.8

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Usage


## Known Issues

Not finished yet!

TODO:
* Do we need to collect billing address? (We are at the moment).
* Add a "pay now" button to the thankyou page in case the invoice is closed, but hide it once the invoice has been paid (see https://bitpay.com/docs/display-invoice).
* Implement IPN handler (currently all contributions remain in pending status as they are only confirmed some time later).

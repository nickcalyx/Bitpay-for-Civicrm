<script src="https://bitpay.com/bitpay.js"></script>

<div class="crm-section crm-bitpay-block">
  <div class="crm-bitpay" id="bitpay-trxnid" style="display: none">{$bitpayTrxnId}</div>
  <a id="bitpay-payment-link" href="javascript:void(0)" onclick="bitpay.showInvoice('{$bitpayTrxnId}')">
    <img id="bitpay-payment-button" src="https://www.bitpay.com/cdn/en_US/bp-btn-pay.svg" alt="Pay with BitPay" style="padding: 10px"/>
  </a>
</div>

{literal}
  <script type="text/javascript">
    CRM.$(function($) {
      CRM.$('.crm-bitpay-block').appendTo('div.crm-group.amount_display-group div.display-block');

      {/literal}{if $bitpayTestMode}{literal}
        bitpay.enableTestMode();
      {/literal}{/if}{literal}
      bitpay.showInvoice(CRM.$('#bitpay-trxnid').text());
    });
  </script>
{/literal}

<div class="crm-section crm-bitpay-block" style="display: none">
  <div class="crm-bitpay" id="bitpay-trxnid">{$bitpayTrxnId}</div>
</div>

{literal}
  <script type="text/javascript">
    CRM.$(function($) {
      {/literal}{if $bitpayTestMode}{literal}
        bitpay.enableTestMode();
      {/literal}{/if}{literal}
      bitpay.showInvoice(CRM.$('#bitpay-trxnid').text());
    });
  </script>
{/literal}

[{if !isset($size) }]
    [{assign var="size" value="20x1"}]
[{/if}]

[{assign var="currency" value=$oView->getActCurrency()}]
[{assign var="currencyname" value=$currency->name}]
[{assign var="paypal_sdk_url" value="https://www.paypal.com/sdk/js?client-id="|cat:$oViewConf->getPayPalClientId()|cat:"&currency=$currencyname"|cat:"&components=buttons,messages"}]

<script type="application/javascript">

    var PayPalMessage = function () {
        var windowWidth = window.innerWidth || document.documentElement.clientWidth || document.body.clientWidth;
        var bannerLayout = windowWidth <= 400 ? 'text' : 'flex';

        // Create installment banner holder
        var oldNode = document.querySelector('#paypal-installment-banner-container');
        var newNode = document.createElement('div');
        newNode.setAttribute('id', 'paypal-installment-banner-container');
        newNode.setAttribute('width', '100%');

        var newNodeInner = document.createElement('div');
        newNodeInner.setAttribute('id', 'paypal-installment-banner-container-content');
        newNodeInner.setAttribute('data-pp-message', '');
        newNodeInner.setAttribute('data-pp-style-layout', bannerLayout);
        newNodeInner.setAttribute('data-pp-style-color', "[{$oViewConf->getPayPalBannersColorScheme()}]");
        newNodeInner.setAttribute('data-pp-countryCode', "[{$oViewConf->getActLanguageAbbr()|upper}]");
        newNodeInner.setAttribute('data-pp-style-logo-type', 'inline');
        newNodeInner.setAttribute('data-pp-style-ratio', '[{$size}]');
        newNodeInner.setAttribute('data-pp-style-text-size', '16');
        newNodeInner.setAttribute('data-pp-amount', [{$amount}]);
        newNode.insertBefore(newNodeInner, newNode.nextSibling);

        var referenceNode = document.querySelector('[{$selector}]');
        if (referenceNode) {
            if (oldNode) {
                referenceNode.parentNode.replaceChild(newNode, oldNode);
            } else {
                referenceNode.parentNode.insertBefore(newNode, referenceNode.nextSibling);
            }
            if (typeof paypal === 'undefined') {
                var scripttagElement = document.createElement('script');
                scripttagElement.src = "[{$paypal_sdk_url}]";
                scripttagElement.onload = "resolve";
                referenceNode.appendChild(scripttagElement);
            }
        } else {
            console.warn('Installment banners was not added due to missing element `[{$selector}]`');
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', PayPalMessage);
    } else {
        PayPalMessage();
    }
</script>
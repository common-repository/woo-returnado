=== Woocommerce Returnado ===

Plugin Name: Woocommerce Returnado
Author: Wetail
Author URI: http://wetail.se
Tested up to: 4.9.8
Version: 0.4.7.34
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

WC Rest API extension for Returnado system and widget interface providing order returning functionality. Includes bypassing loggin on purchasing for non-guest mode.

== Description ==

Returnado is your complete returns handling system that gives your WooCommerce store a top-of-the-line returns system, completely automated and digitalized. It provides your customers with an intuitive interface to return, request exchange, create a complaint or get help with their return.

The platform is optimized to create re-conversion, meaning enabling the customer to use their return value to buy new items in your store, or request a store-credit instead of a refund.

The system uses native WooCommerce functionality, your other plugins working with native WooCommerce functionality should work as before. Approve and execute refunds, exchanges and complaints with the click of a button and all your administration is done!

In short, happier customers, saves you money and generates more revenue!

== Technical description ==

Returnado is your complete returns handling system that enriches your WooCommerce store with several powerful features designed to increase your profitability. It does so by supplying your customers with a better more intuitive returns experience, automates your return-flow and generates new sales by optimizing towards re-conversion.

Returnado provides, amongst others:

1. A complete returns handling system – Fully integrated to WooCommerce
2. Store-credit capabilities
3. The ability for customers to order exchanges
4. “1-click returns handling” of exchanges, refunds, store-credits and complaints
5. Automatic refunds to all major payment gateways (e.g. PayPal, Klarna, Stripe)
6. A mini “My Orders” page where your customers will get an overview over their ordered items.
7. Multi-currency support
8. Multi-language support

To be compatible with as many WooCommerce merchants as possible we’ve followed standard WooCommerce praxis and as far as possible, interact with WooCommerce native functionality.

= How Returnado interacts with WooCommerce =

When you first install and activate the plugin, we start syncing all sale orders made after plugin has been activated. Returnado collects basic order-, product-, and basic customer info to be able to.

1. Allow customers to “log in” to Returnado by entering email address only
2. Show orders and items customer has ordered from merchant
3. Offer recommendations, exchanges and replacement products

Only sales orders made after plugin has been activated will be return-able in Returnado.

Return information, including refund- and exchange information is sent from Returnado to WooCommerce when return has been approved by the merchant. No refund, or exchange order is final until you as a merchant has approved the return.

= Typical flow =

1. Customer wants to return product.
2. They enter your store at STORENAME.com/RETURNS and enters their email in embedded Returnado iframe
3. Customer gets access to their order history, and selects if they want to cancel purchase, order an exchange, request refund, or create a complaint.
4. If customer selects to exchange product, a preliminary order is created in WooCommerce, to reserve stock.
5. “Return registered” confirmation is emailed to customer.
6. Return appears in Returnado Admin, merchant approves return and exchange.
7. Order in WooCommerce is turned in to final order, and “Return handled” confirmation is sent to customer through email.

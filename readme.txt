=== AiBill Maker ===
Contributors: aibillmaker
Tags: invoice, ai invoice, billing, gst invoice, small business
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create invoices inside WordPress admin from a prompt using the WordPress AI Client.

== Description ==

AiBill Maker is an admin-only invoice maker for small business owners. The site owner can type a prompt with customer name, items, quantity, rate, GST/tax, and due date. AiBill creates a saved invoice record that can be viewed, edited, printed, or downloaded as PDF.

AiBill Maker uses the WordPress AI Client available in WordPress 7.0 or later. Provider selection, credentials, and model routing are managed by WordPress through Settings > Connectors. The plugin does not store AI provider API keys and does not call provider APIs directly.

= Main features =

* Admin-only invoice maker. No public page is required.
* Prompt popup inside WordPress admin.
* Saved invoice list.
* View invoice.
* Edit invoice fields and line items.
* Print invoice from a clean standalone print view.
* Download invoice as PDF.
* Business profile and default GST/tax settings.
* Uses the built-in WordPress AI Client for natural-language invoice prompts when a connector is available.
* Includes local parsing for prompts with a clear item and amount, so basic invoice creation can work without an AI provider.
* Does not store AI provider API keys in plugin settings.

= External services =

AiBill Maker does not call external AI providers directly. For natural-language prompts, the plugin asks the WordPress AI Client to generate invoice JSON. WordPress then routes the request to the AI provider configured by the site administrator in Settings > Connectors.

When AI is used, the invoice prompt text and invoice-generation instruction may be sent by WordPress to the site administrator's configured AI provider. No AI request is made by AiBill Maker unless the administrator uses the invoice generation tool and WordPress AI Client has a text-generation provider available.

AiBill Maker does not create an AiBill account, does not connect to AiBill.app, and does not send invoice data to our own server.

== Installation ==

1. Upload the `aibill-maker` folder to the `/wp-content/plugins/` directory, or install the plugin ZIP from the WordPress admin plugin uploader.
2. Activate the plugin through the Plugins screen in WordPress.
3. In WordPress admin, configure an AI provider in Settings > Connectors if you want natural-language AI prompts.
4. Open AiBill Maker > Settings and add your business profile and default tax settings.
5. Open AiBill Maker > Invoices and click Create Invoice.

== Frequently Asked Questions ==

= Do I need WordPress 7.0? =

Yes. AiBill Maker uses the WordPress AI Client and requires WordPress 7.0 or later.

= Do I need to create a frontend page? =

No. AiBill Maker is admin-first. Small business owners can create invoices directly inside WordPress admin.

= Does the Download PDF button need a browser print dialog? =

No. Download PDF creates a PDF file directly from WordPress and downloads it.

= Does it work without AI? =

Prompts with a clear item and amount can be parsed locally without an AI provider. For messy natural-language prompts, configure an AI provider in WordPress Settings > Connectors.

= Does this plugin store my AI API key? =

No. AI credentials are managed by WordPress Connectors. AiBill Maker does not store provider API keys in its own settings.

== Screenshots ==

1. Admin invoice list with Create Invoice button.
2. Prompt popup for creating invoices.
3. Invoice view with separate Print and Download PDF actions.
4. Settings page with business profile and WordPress AI Client status.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release of AiBill Maker.

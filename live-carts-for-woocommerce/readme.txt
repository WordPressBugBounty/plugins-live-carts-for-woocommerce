=== Live Carts for WooCommerce: Track Active, Abandoned, and Converted Carts & Wishlists in Real Time! ===
Contributors: berrypress
Tags: woocommerce, cart, basket, track, wishlist
Requires at least: 6.5.0
Tested up to: 7.1
Requires PHP: 7.0
WC requires at least: 7.0
WC tested up to: 11.2
Stable tag: 2.1.1
License: GNU General Public License version 3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Track active, abandoned, and converted WooCommerce carts and Shopper Lists (Wishlist, Save for Later) in real time from the WordPress admin dashboard.

== Description ==

The Live Carts plugin by BerryPress extends WooCommerce by adding real-time tracking of all customer shopping carts within your WordPress admin. It provides a complete list of customer carts, including their status (active, abandoned, or converted) and total value. Clicking a cart ID reveals more details, including all products and quantities in the cart.

Optionally, the plugin can display a cart ID on the frontend, visible to the customer. The admin interface supports searching for carts based on this ID. This allows store support to look up a customer cart if the customer provides their cart ID.

The plugin also calculates some basic cart statistics at Analytics > Carts, with date filtering based on the cart creation date.

### Shopper Lists

Version 1.3.0 adds admin visibility into WooCommerce **Shopper Lists** — the built-in **Wishlist** and **Save for Later** features in WooCommerce 10.9. When you enable those features in WooCommerce, Live Carts tracks each logged-in customer's lists alongside active carts. Open the **Shopper Lists** tab in the Live Carts admin to browse lists by customer, list type, value, and last seen. Shopper Lists in WooCommerce core are currently experimental and require Gutenberg Blocks.

**Requirements:** WooCommerce **10.9 or higher**, with at least one Shopper List feature enabled under **WooCommerce → Settings → Advanced → Features** (Wishlist and/or Save for Later). This feature only **monitors** lists that WooCommerce core stores when its Shopper List features are turned on.

=== Features ===

* List real-time and past carts
* Shopper Lists tracking - Monitor WooCommerce's built-in Wishlist and Save for Later lists in the admin (requires WooCommerce's Shopper List features)
* Filter cart list by status (Active/Converted/Abandoned)
* See the name of the logged-in user (if any) associated with each cart
* View visitor IP addresses in the cart list (respects privacy settings)
* See cart updates instantly as items are added, removed, or quantities change
* See the most recent URL that each cart was loaded on
* View cart products with thumbnails, product attributes, quantities, and total cart value — along with any applied coupon codes
* View cart revision history – See all changes to cart contents over time (added, removed, quantity changes)
* Search for carts by unique cart ID
* Bulk delete carts directly from the cart list
* Optionally display the cart ID on the frontend to easily match to a cart record in the backend
* Use the `[berrypress_live_cart_id]` shortcode to display the visitor's cart ID anywhere on your site - ideal for Contact Us or Support pages, so customers can easily share their cart ID with your support team
* Optionally disable collection of IP addresses and/or last visited URLs in this plugin's cart database
* Track visitor email addresses entered at checkout for guest cart recovery
* Recovery Emails — send automated abandoned cart recovery emails with configurable templates and send timing, including one-click cart recovery links
* Automatically archive older cart records
* Get cart conversion rate, cart abandonment rate, and average cart value metrics for a specified period of time (under Analytics > Carts)
* Status & Diagnostics page – Quickly verify your environment with health checks for WordPress, PHP, WooCommerce, database tables, migration status, and cron schedule
* Built-in debug logging with configurable log levels, in-admin log viewer, and downloadable log files
* Cart data collected by this plugin is stored in custom database tables to avoid cluttering default WordPress tables
* REST API and signed webhooks for integrating carts and Shopper Lists with other systems
* Optional DocsBot integration: when you enable it, the current cart ID is passed to your private DocsBot chatbot (docsbot.ai, a third-party service) through the DocsBot plugin, so the bot can answer questions about the visitor's cart
* Built-in extensibility via filter and action hooks

### Get Pro Features

Upgrade to **Live Carts Pro** [here](https://berrypress.com/live-carts/) and unlock powerful tools to manage and recover customer carts more effectively:

**Core Pro Features:**

- 🛒 **Edit cart contents in the backend** – Easily adjust products, quantities, or remove items directly from the admin.
- 🧾 **Convert a cart to an order with one click** – Turn any cart into a pending order instantly.
- 🎟️ **Discount coupons in recovery emails** – Set a percentage discount per recovery email; a unique, single-use WooCommerce coupon is generated for each cart and added with the [coupon] shortcode.
- 🔍 **Advanced cart filtering** – Filter carts by product, customer, IP address, user type, coupon, last seen date, or min/max cart value.
- 📋 **Product meta display** – View custom product metadata and additional product fields directly in the cart details view.

**Additional Pro Settings:**

- **Role Exclusions** - Do not track carts for specific user roles (e.g., administrators, shop managers)
- **Custom IP Header** - Specify HTTP header for IP detection (for Cloudflare, proxies: X-Forwarded-For, CF-Connecting-IP, etc.)
- **Cart Revision Controls** - Turn cart change history off, or limit how many carts keep it, to save database space
- **Product Weight Display** - View product weight in cart details for shipping calculations
- **Custom Abandoned Timing** - Define how many hours of inactivity mark a cart as abandoned (default: 2 hours)
- **Custom Archiving** - Change how many days of inactivity before carts are archived (default: 30 days)

Take full control of your WooCommerce carts with [BerryPress Live Carts Pro](https://berrypress.com/live-carts/).


### Documentation

Explore the full [Live Carts documentation](https://berrypress.com/docs/live-carts-for-woocommerce/).

## You may also like these plugins

[BerryPress](https://berrypress.com/) has built a range of powerful plugins for WooCommerce and WordPress. Check out some of our favorites below — and don’t forget to leave a ⭐⭐⭐⭐⭐ review to help others in the community discover them!

- [Ninjalytics](https://berrypress.com/product/woocommerce/ninjalytics/) — Report, analyze, and visualize your store's data to gain actionable insights for growth, support operations, and satisfy customers!
- [Export Order Items for WooCommerce](https://wordpress.org/plugins/export-order-items-for-woocommerce/) — Export detailed order data for every sale in your WooCommerce store. Simplify fulfillment, create accounting reports in just a few clicks, and download CSV files for easy sharing and compatibility.
- [Inline Image Upload for BBPress](https://wordpress.org/plugins/image-upload-for-bbpress/) — Add a TinyMCE WYSIWYG editor to BBPress forums and enable inline image uploads, so users can easily attach and display images within their posts.
- [PhotoBerry Studio](https://wordpress.org/plugins/photoberry-studio/) — Create beautiful, customizable product photo galleries directly in your WordPress dashboard.
- [Automatic Product Categories](https://berrypress.com/product/woocommerce/automatic-product-categories/) — Available as a free version and Pro. Automatically assign products to categories based on custom rules, saving you time and keeping your store organized.
- [Customer Address Change Notification for WooCommerce](https://wordpress.org/plugins/customer-address-change-notification-for-woocommerce/) — Get instant notifications when customers update their shipping or billing address, helping you stay on top of changes and prevent delivery issues.

To view BerryPress's premium WordPress plugins and themes, visit our [WordPress products catalog page](https://berrypress.com/shop/).

Enjoy!

== Frequently Asked Questions ==

= How can I report security bugs? =

You can report security bugs through the Patchstack Vulnerability Disclosure Program. The Patchstack team helps validate, triage and handle any security vulnerabilities. [Report a security vulnerability.]( https://patchstack.com/database/vdp/e6961347-47ef-4d8d-baf3-76d442d91d69 )

= Is Live Carts a wishlist plugin? =

No. Live Carts does not provide wishlist functionality on your storefront and is not a replacement for dedicated wishlist plugins. Shopper Lists tracking reads data from WooCommerce 10.9+'s built-in, experimental Wishlist and Save for Later features when you enable them in WooCommerce settings. [Read more here](https://berrypress.com/tutorials/whats-new-in-woocommerce-10-9-visual-attributes-native-wishlists/).

= What do I need for Shopper Lists tracking? =

WooCommerce 10.9 or higher, Live Carts 1.3.0 or higher, and at least one of WooCommerce's Shopper List features (Wishlist or Save for Later) enabled under WooCommerce → Settings → Advanced → Features. Only logged-in customers are tracked, matching WooCommerce's behavior for these lists.


== Installation ==

This plugin can be installed under Plugins > Add New Plugin, either by searching for the plugin title or by downloading the plugin zip file and uploading it via the Upload Plugin feature.

== Changelog ==

= 2.1.1 2026-11-05 =
- Added: Settings -> Integrations tab with an optional DocsBot integration. When enabled, the current cart ID is added to your private DocsBot widget's configuration, so it is logged in conversation history and can be used with the Live Carts skill. Requires the DocsBot plugin.
- Improved: Clearer keyboard focus indicators in the table pagination and the BerryPress sidebar.
- Fixed: REST API responses for carts containing a product variation failed. A variation's attributes now show its selected values plus the parent product's other visible attributes.
- Fixed: Tooltips now also remove `javascript:` and `data:` URLs written with capital letters or embedded whitespace.

= 2.1.0 2026-10-15 =
- Added: REST API (`live-carts/v1`) for reading carts and shopper lists, including item contents, revision history, recovery URLs and email addresses. See the [REST API documentation](https://berrypress.com/docs/live-carts-for-woocommerce/api/rest-api/).
- Added: Webhooks — subscribe one or more URLs to cart/list events (created, seen, items added/removed/updated, converted, abandoned). Each delivery is signed with HMAC-SHA256 (`X-Live-Carts-Signature`). Add, enable/disable, regenerate the secret of, and delete webhooks from Settings -> API.
- Added: Settings -> API tab to enable the REST API and webhooks separately.
- Fixed: possible fatal error when loading a cart whose stored items have no item key.
- Fixed: changes to cart item meta were not always detected in the cart revision history.

= 2.0.0 2026-10-03 =
- New: Recovery Emails — send automated abandoned cart recovery emails directly from the plugin (BerryPress → Live Carts → Recovery Emails). Configure email templates, subjects, and send timing. No Pro license required.
- New: Visitor email tracking — capture the email address entered at checkout and associate it with the cart record, enabling recovery emails for guest visitors.
- New: Settings redesigned with a tabbed layout (Tracking, Lifecycle, Display, Email) for easier navigation.
- New: Email tab — configure a custom sender name and email address for recovery emails.
- New: Cart email source setting — when a logged-in user's account email and a checkout email are both available, choose which one takes priority.
- New: "Date Created" column in the carts and shopper lists tables.
- New: Item counts shown in status filter tabs (All, Trash, etc.).
- New: Filters button in the cart list (Pro).
- Improved: Admin UI — tooltips on settings fields, Pro badge on Pro-only fields, improved tab navigation.
- Fixed: Recovery email address resolution now correctly falls back to the user's account email when no checkout email has been captured.

See full [changelog](https://plugins.svn.wordpress.org/live-carts-for-woocommerce/trunk/changelog.txt).
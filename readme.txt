=== Plugin Settings Finder ===
Contributors: marceltannich
Tags: admin bar, settings, plugins, navigation, developer
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An admin bar dropdown that takes you straight to the settings pages of every active plugin, wherever the plugin decided to put them.

== Description ==

You activate a plugin and then go hunting for its settings. It might have its own top-level menu item, or sit under Tools, Settings, Users (for profile features) or Media. A WooCommerce extension might use the WooCommerce menu, Products, or a new tab in the WooCommerce settings tab bar. Some only have a hidden welcome page.

Plugin Settings Finder adds a **Plugin Settings Finder** dropdown to the admin bar. It lists every active plugin with direct links to its admin screens and the breadcrumb of where each one lives ("Settings › Example", "WooCommerce › Settings › Payments › Example Pay"). Plugins without any admin screen say so: "No settings page found", followed by what they add instead: blocks, shortcodes, widgets, hints from their readme ("Readme mentions: blocks, works without configuration") and the readme's short description.

= How pages are found =

No guessing from plugin names. Every page is attributed by asking PHP where the code behind it lives:

* Admin menu and submenu pages: the file of the page callback.
* Hidden pages (setup wizards, welcome screens) registered without a visible parent.
* Post type and taxonomy screens: the plugin file that registered them.
* WooCommerce settings tabs and sections, payment gateways, shipping methods, integrations and emails: the class file of the tab, section or gateway.
* The links a plugin adds to its row on the Plugins screen.
* As a last resort, the plugin's own PHP files are read for the slugs it passes to `add_*_page()`, matched against pages that are really registered.

= Using it =

* Sort A to Z (default), Z to A, or with recently activated plugins first; the choice is saved per user. Each plugin is its own segment, separated by a line.
* Type in the filter field to narrow the list by plugin name or page.
* Clicking a plugin name opens the page that looks most like its settings.
* When Plugin Check (PCP) is active, every plugin gets a highlighted "Check with PCP" link that opens PCP with that plugin preselected. PCP's own "Check this plugin" row link is not counted as a settings page.
* Elementor, WooCommerce and Contact Form 7 are hidden by default because they are installed almost everywhere. Change the list of plugin folder names with the `psf_hidden_plugins` filter.
* Only users who can activate plugins see the dropdown (filter: `psf_capability`).

The list is rebuilt on admin screens whenever the set of active plugins changes, every ten minutes, or when you click **Rescan now**.

Nothing leaves your site.

== Installation ==

As a normal plugin: upload the `plugin-settings-finder` folder to `wp-content/plugins/` and activate it.

As a must-use plugin: copy the `plugin-settings-finder` folder into `wp-content/mu-plugins/` and copy `extras/mu-loader.php` next to it (WordPress only loads PHP files at the top level of `mu-plugins`).

== Changelog ==

= 1.4.0 =
* "No settings page found" now explains what the plugin adds instead: blocks (block.json, register_block_type), shortcodes, widgets, readme hints and the readme short description.

= 1.3.0 =
* Z to A sort option.
* Line between plugins, so each plugin and its PCP link form one segment.
* PCP link renamed to "Check with PCP".

= 1.2.0 =
* Highlighted Plugin Check (PCP) shortcut per plugin.
* PCP's row link no longer hides "No settings page found".

= 1.1.0 =
* Sort switch: A to Z (default) or recently activated first, saved per user.
* Elementor, WooCommerce and Contact Form 7 hidden by default (`psf_hidden_plugins` filter).
* Must-use plugin loader in `extras/`.

= 1.0.0 =
* First release.

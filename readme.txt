=== Extend WP ===
Contributors: brut3forc3, gnnpls
Tags: custom fields, meta boxes, options pages, custom tables, developer
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.13.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Developer toolkit: fields, meta boxes, options pages, custom content tables with REST, WP-CLI and Abilities, and an activity log.

== Description ==

Extend WP gives developers one way to describe fields and content, and builds the admin screens, storage and APIs from that description.

**Fields and screens**

* Fields for posts, terms, users and options pages: text, number, select, checkboxes, radio, image, gallery, map, repeater, sections, tabs and modal fields.
* Meta boxes, term fields, user fields and options pages from a single field array.
* Field groups, post types and taxonomies can also be created from the admin.

**Custom content tables**

* Content types stored in their own database tables (`_main` and `_data`, like posts and post meta), created and versioned from a schema.
* Lists built on WordPress DataViews, with search, filters, sorting and paging.
* The same create, read, update and delete actions over the REST API, WP-CLI and the WordPress Abilities API.

**Tools**

* Activity log with retention, a log viewer and WP-CLI commands.
* REST API Health page: every route of a plugin, with a Swagger UI to try them.
* Options export and import between sites.
* Dynamic asset loader: scripts and styles load only on pages that contain their elements.

== External services ==

**Google Maps (map fields)**
Map fields load the Google Maps JavaScript API only when you add a Google Maps API key in Extend WP → Developer Settings. The visitor's browser loads the map from Google.
Terms: https://cloud.google.com/maps-platform/terms - Privacy: https://policies.google.com/privacy

No other data leaves your site.

== Installation ==

1. Upload the `extend-wp` folder to `/wp-content/plugins/`, or install it from Plugins → Add New.
2. Activate Extend WP. Developers register fields with the `awm_add_meta_boxes_filter`, `awm_add_options_boxes_filter` and `awm_register_content_db` filters; see HOOKS.md in the plugin.

== Frequently Asked Questions ==

= Do I need to write code? =

Field groups, post types and taxonomies can be built from the admin. Custom content tables and the REST / WP-CLI / Abilities surfaces are meant for developers.

== Changelog ==

= 1.13.0 =
* First release on WordPress.org.

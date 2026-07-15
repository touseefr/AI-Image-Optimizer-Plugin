=== AI Image Optimizer ===
Contributors: yourname
Tags: images, compression, webp, alt text, seo, performance
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically compress images, generate AI-powered alt text, and create WebP versions — for better SEO and faster page loads.

== Description ==

AI Image Optimizer runs automatically every time you upload an image to WordPress:

1. **Compresses** the original file and every generated thumbnail size, reducing file size with minimal visible quality loss.
2. **Generates WebP versions** of each size for browsers that support it, served automatically on the front end.
3. **Writes AI-generated alt text** for images that don't have any yet, using OpenAI's vision models — improving accessibility and SEO without extra manual work.

You can also run all three steps on your **existing** Media Library from the Media → Image Optimizer settings page.

= Requirements =

* An OpenAI API key (for the alt text feature only — compression and WebP work with no API key).
* PHP with either the Imagick or GD extension (WordPress requires one of these already).

== Installation ==

1. Upload the `ai-image-optimizer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Media → Image Optimizer to add your OpenAI API key and adjust settings.

== Frequently Asked Questions ==

= Does this work without an API key? =
Compression and WebP generation work with no API key at all. Only AI alt text generation requires an OpenAI API key.

= Will it overwrite alt text I already wrote? =
No. It only fills in alt text for images where the field is empty, unless you click "Regenerate Alt Text with AI" manually.

= Does it modify my original images destructively? =
Compression re-saves the original file at the configured quality level. Keep backups if you want to preserve pristine originals. WebP generation creates new, separate .webp files alongside the originals.

== Changelog ==

= 1.0.0 =
* Initial release.

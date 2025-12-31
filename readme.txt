=== Landing A/B Test ===
Contributors: ababir, rsiddiqe
Tags: ab testing, landing page, split test, conversion
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html


A simple sticky A/B testing plugin for WordPress landing pages. Assign visitors to one of two variants and keep them there until the campaign is stopped.

== Description ==

Landing A/B Test lets you run split tests on any landing page in WordPress. You select an original page and two variant pages. When the campaign is active, visitors to the original page are randomly assigned to one variant and will continue to see that same variant on future visits. When the campaign is stopped, all visitors see the original page again.

== Installation ==

1. Download the ZIP file of the plugin from the GitHub repository.
2. In your WordPress admin dashboard, go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP file and click **Install Now**.
4. After installation, click **Activate Plugin**.

== Usage ==

1. After activation, you will see a new menu item called **Landing A/B Test** in the WordPress admin sidebar.
2. Open the plugin settings page.
3. Select:
   - **Original Page**: the landing page you want to test (e.g., `/sale` or `Home`).
   - **Variant A Page**: the first version (e.g., `/sale/v1`).
   - **Variant B Page**: the second version (e.g., `/sale/v2`).
4. Save your settings.
5. Start the campaign by clicking **Start Campaign**.
   - Visitors to the original page will be randomly assigned to Variant A or Variant B.
   - Each visitor will continue to see the same variant on future visits (sticky assignment via cookies).
6. To stop the campaign, click **Stop Campaign**.
   - Visitors will then see the original page again.
   - Assignment cookies are cleared.

== Notes ==

- Only published pages can be selected as original or variants.
- Original, Variant A, and Variant B must be three distinct pages.
- Redirects use HTTP 302 status codes to avoid permanent SEO changes.
- Cookies last for 30 days by default.

== Future Features ==

- **Test the variants with geo‑location (country):**  
  Assign different countries to either variants from multiple selections.

- **See views as per geo‑location (country):**  
  Track and display variant performance broken down by visitor country.

- **Conversion tracking dashboard:**  
  Record conversions by UTM for each and show A vs. B performance.

- **Multiple campaigns support:**  
  Run A/B tests on more than one original page at the same time.

- **Bot/crawler detection:**  
  Skip redirects for search engine bots to avoid skewing analytics.

- **Integration with analytics tools:**  
  Connect with Google Analytics and Clarity for deeper reporting.

== Changelog ==

= 1.0.0 =
* Initial release with sticky A/B testing functionality.

== License ==

This plugin is licensed under the GPLv2 or later.

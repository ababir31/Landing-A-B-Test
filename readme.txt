=== Landing A/B Test ===
Contributors: ababir, rsiddiqe
Tags: ab testing, landing page, split test, conversion, geo location
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html


A simple sticky A/B testing plugin for WordPress landing pages. Assign visitors to one of two variants and keep them there until the campaign is stopped. Includes optional geo‑location redirection by country using the free MaxMind GeoLite2 database.

== Description ==

Landing A/B Test lets you run split tests on any landing page in WordPress. You select an original page and two variant pages. When the campaign is active, visitors to the original page are either:

- **Randomly assigned** to one variant and will continue to see that same variant on future visits (sticky assignment via cookies).
- **Redirected by country** using the free MaxMind GeoLite2 database. You can assign multiple countries to Variant A and Variant B. Visitors from those countries will be redirected accordingly. If a visitor’s country is not listed, they see the original page.

When the campaign is stopped, all visitors see the original page again.

== Installation ==

1. Download the ZIP file of the plugin from the GitHub repository.
2. In your WordPress admin dashboard, go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP file and click **Install Now**.
4. After installation, click **Activate Plugin**.

== GeoLite2 Database Setup ==

To enable geo‑location redirection, you must install the free MaxMind GeoLite2 Country database:

1. **Sign up at MaxMind**:  
   - Create a free account at [MaxMind](https://www.maxmind.com/en/geolite2/signup).
   - After logging in, go to the **GeoLite2 Download Portal**.

2. **Download the database**:  
   - Choose **GeoLite2 Country** in MMDB format.  
   - You will receive a `.tar.gz` or `.zip` file containing `GeoLite2-Country.mmdb`.

3. **Place the database file**:  
   - Extract the archive.  
   - Copy `GeoLite2-Country.mmdb` into your plugin’s `/vendor/` directory:  
     ```
     wp-content/plugins/landing-ab-test/vendor/GeoLite2-Country.mmdb
     ```

4. **Install the PHP library**:  
   - In the plugin folder, run:  
     ```
     composer require geoip2/geoip2
     ```
   - This installs the official MaxMind PHP API into `/vendor/`.

5. **Verify setup**:  
   - After activation, select **Geo Location** as the redirection method in the plugin settings.  
   - Assign countries to Variant A and Variant B.  
   - Visitors from those countries will be redirected accordingly.

== Testing Geo Location Locally ==

When running WordPress locally (e.g., WordPress Studio, LocalWP, XAMPP), your server only sees `127.0.0.1` or `::1` as the visitor IP. To test geo‑location:

1. **Override localhost IP in code**:  
   - The plugin includes a helper that replaces `127.0.0.1` with a test IP.  
   - Example IPs:  
     - `8.8.8.8` → United States  
     - `81.2.69.142` → United Kingdom  
     - `49.36.0.1` → India  
     - `210.138.184.1` → Japan  
   - Change the override IP to simulate different countries.

2. **Use a VPN**:  
   - Connect through a VPN with an exit node in another country.  
   - Your local WordPress will then see the VPN’s IP.

3. **Use MaxMind test MMDB files**:  
   - MaxMind provides dummy databases with predictable IP mappings.  
   - Replace the GeoLite2 file with a test MMDB to simulate countries.

== Notes ==

- Only published pages can be selected as original or variants.
- Original, Variant A, and Variant B must be three distinct pages.
- Redirects use HTTP 302 status codes to avoid permanent SEO changes.
- Cookies last for 30 days by default.
- GeoLite2 database must be present for geo‑location redirection to work.

== Changelog ==

= 1.1.0 =
* Added geo‑location redirection by country using GeoLite2.
* Added helper for local testing with override IPs.

= 1.0.0 =
* Initial release with sticky A/B testing functionality.

== License ==

This plugin is licensed under the GPLv2 or later.

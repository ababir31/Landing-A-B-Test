<?php
/**
 * Plugin Name: Landing A/B Test
 * Description: A/B testing tool for a single landing page: Random sticky assignment or Geo Location (country-based) redirection.
 * Version: 1.1.0
 * Author: Abir, Rasel
 * Author URI: https://profiles.wordpress.org/ababir/
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

// Composer autoload for MaxMind
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}
use GeoIp2\Database\Reader;

class Landing_AB_Test_Plugin {

/**
 * Get the client IP address, with override for local development.
 *
 * @return string IPv4/IPv6 address
 * Remove commenting before testing.
 */
    
private function get_client_ip() {
    // Common server headers that may contain the client IP
    $keys = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_REAL_IP',        // nginx
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR',
    ];

    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = $_SERVER[$k];
            // X-Forwarded-For can contain a list of IPs
            if ($k === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $ip);
                $ip = trim($parts[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                // Local development testing override - commented out for live site
                // if (in_array($ip, ['127.0.0.1', '::1'], true)) {
                //     return '127.0.0.1'; // change this to test different countries
                // }
                return $ip;
            }
        }
    }

    // Fallback to loopback when nothing else works
    return '127.0.0.1';
}


    const OPTION_GROUP   = 'landing_ab_test_options_group';
    const OPTION_NAME    = 'landing_ab_test_options';
    const COOKIE_PREFIX  = 'landing_ab_assign_';
    const NONCE_ACTION   = 'landing_ab_test_nonce_action';
    const PAGE_SLUG      = 'landing-ab-test-settings';

    public function __construct() {
        // Admin menu (top-level)
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_landing_ab_test_save', [$this, 'handle_save_settings']);
        add_action('admin_post_landing_ab_test_toggle', [$this, 'handle_toggle_campaign']);

        // Frontend interception
        add_action('template_redirect', [$this, 'maybe_redirect_to_variant'], 1);

        // Optional cleanup
        add_action('wp_logout', [$this, 'clear_assignment_cookie_if_set']);

        // Optional admin styles
        add_action('admin_enqueue_scripts', function($hook) {
            if ($hook === 'toplevel_page_' . self::PAGE_SLUG) {
                wp_enqueue_style('landing-ab-test-admin', plugins_url('assets/css/admin.css', __FILE__), [], '1.0.0');
            }
        });
    }

    /**
     * Add a top-level menu page in the WordPress admin.
     */
    public function add_settings_page() {
        add_menu_page(
            'Landing A/B Test',
            'Landing A/B Test',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_settings_page'],
            'dashicons-randomize',
            25
        );
    }

    /**
     * Register options with defaults and sanitization.
     */
    public function register_settings() {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'sanitize_callback' => [$this, 'sanitize_options'],
            'default' => [
                'original_page_id'     => 0,
                'variant_a_page_id'    => 0,
                'variant_b_page_id'    => 0,
                'campaign_active'      => 0,
                'redirection_method'   => 'random', // 'random' or 'geo'
                'variant_a_countries'  => [],
                'variant_b_countries'  => [],
            ],
        ]);
    }

    /**
     * Sanitize and validate settings.
     */
    public function sanitize_options($input) {
        $output = [
            'original_page_id'     => isset($input['original_page_id']) ? intval($input['original_page_id']) : 0,
            'variant_a_page_id'    => isset($input['variant_a_page_id']) ? intval($input['variant_a_page_id']) : 0,
            'variant_b_page_id'    => isset($input['variant_b_page_id']) ? intval($input['variant_b_page_id']) : 0,
            'campaign_active'      => isset($input['campaign_active']) ? intval($input['campaign_active']) : 0,
            'redirection_method'   => isset($input['redirection_method']) ? sanitize_text_field($input['redirection_method']) : 'random',
            'variant_a_countries'  => isset($input['variant_a_countries']) && is_array($input['variant_a_countries']) ? array_map('sanitize_text_field', $input['variant_a_countries']) : [],
            'variant_b_countries'  => isset($input['variant_b_countries']) && is_array($input['variant_b_countries']) ? array_map('sanitize_text_field', $input['variant_b_countries']) : [],
        ];

        if ($output['original_page_id'] == -1) {
            $output['original_page_id'] = 0;
        }

        if (!in_array($output['redirection_method'], ['random', 'geo'], true)) {
            $output['redirection_method'] = 'random';
        }

        foreach (['variant_a_page_id', 'variant_b_page_id'] as $key) {
            if ($output[$key] > 0) {
                $post = get_post($output[$key]);
                if (!$post || $post->post_type !== 'page' || $post->post_status !== 'publish') {
                    $output[$key] = 0;
                }
            }
        }

        if ($output['campaign_active']) {
            $ids = [$output['original_page_id'], $output['variant_a_page_id'], $output['variant_b_page_id']];
            $valid    = ($ids[0] >= 0 && $ids[1] > 0 && $ids[2] > 0);
            $distinct = (count(array_unique($ids)) === 3);
            if (!$valid || !$distinct) {
                $output['campaign_active'] = 0;
            }
        }

        $iso = array_keys($this->country_list());
        $output['variant_a_countries'] = array_values(array_intersect($output['variant_a_countries'], $iso));
        $output['variant_b_countries'] = array_values(array_intersect($output['variant_b_countries'], $iso));

        return $output;
    }

    /**
     * Save settings handler.
     */
    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        check_admin_referer(self::NONCE_ACTION);

        $input = [
            'original_page_id'     => isset($_POST['original_page_id']) ? intval($_POST['original_page_id']) : 0,
            'variant_a_page_id'    => isset($_POST['variant_a_page_id']) ? intval($_POST['variant_a_page_id']) : 0,
            'variant_b_page_id'    => isset($_POST['variant_b_page_id']) ? intval($_POST['variant_b_page_id']) : 0,
            'campaign_active'      => isset($_POST['campaign_active']) ? intval($_POST['campaign_active']) : 0,
            'redirection_method'   => isset($_POST['redirection_method']) ? sanitize_text_field($_POST['redirection_method']) : 'random',
            'variant_a_countries'  => isset($_POST['variant_a_countries']) ? (array) $_POST['variant_a_countries'] : [],
            'variant_b_countries'  => isset($_POST['variant_b_countries']) ? (array) $_POST['variant_b_countries'] : [],
        ];

        $sanitized = $this->sanitize_options($input);
        update_option(self::OPTION_NAME, $sanitized);

        if (empty($sanitized['campaign_active'])) {
            $this->delete_assignment_cookie($sanitized['original_page_id']);
        }

        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * Start/Stop campaign handler.
     */
    public function handle_toggle_campaign() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        check_admin_referer(self::NONCE_ACTION);

        $options = $this->get_options();
        $toggle = isset($_POST['toggle']) ? sanitize_text_field($_POST['toggle']) : '';

        if ($toggle === 'start') {
            $options['campaign_active'] = 1;
            $options = $this->sanitize_options($options);
            update_option(self::OPTION_NAME, $options);
        } elseif ($toggle === 'stop') {
            $options['campaign_active'] = 0;
            update_option(self::OPTION_NAME, $options);
            $this->delete_assignment_cookie($options['original_page_id']);
        }

        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'toggled' => $toggle], admin_url('admin.php')));
        exit;
    }

    /**
     * Render admin settings page.
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $options   = $this->get_options();
        $pages     = $this->get_published_pages();
        $countries = $this->country_list();
        ?>
        <div class="wrap">
            <h1>Landing A/B Test</h1>
            <p>Choose your original page and two variants. Select a redirection method: Random or Geo Location (country-based).</p>

            <?php if (isset($_GET['updated'])): ?>
                <div class="updated notice"><p>Settings saved.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['toggled'])): ?>
                <div class="updated notice"><p>Campaign <?php echo esc_html($_GET['toggled']); ?>ed.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="landing_ab_test_save" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="original_page_id">Original page</label></th>
                        <td>
                            <select id="original_page_id" name="original_page_id" required>
                                <option value="-1">— Select —</option>
                                <option value="0" <?php selected($options['original_page_id'], 0); ?>>Home Page (<?php echo esc_url(home_url('/')); ?>)</option>
                                <?php foreach ($pages as $p): ?>
                                    <option value="<?php echo esc_attr($p->ID); ?>" <?php selected($options['original_page_id'], $p->ID); ?>>
                                        <?php echo esc_html($p->post_title . ' (' . get_permalink($p) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">This is the original landing page (e.g., /sale).</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="variant_a_page_id">Variant A page</label></th>
                        <td>
                            <select id="variant_a_page_id" name="variant_a_page_id" required>
                                <option value="0">— Select —</option>
                                <?php foreach ($pages as $p): ?>
                                    <option value="<?php echo esc_attr($p->ID); ?>" <?php selected($options['variant_a_page_id'], $p->ID); ?>>
                                        <?php echo esc_html($p->post_title . ' (' . get_permalink($p) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Variant A (e.g., /sale/v1).</p>

                            <label for="variant_a_countries"><strong>Countries for Variant A</strong></label><br />
                            <select id="variant_a_countries" name="variant_a_countries[]" multiple size="8" style="min-width: 320px;">
                                <?php foreach ($countries as $code => $name): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php echo in_array($code, $options['variant_a_countries'], true) ? 'selected' : ''; ?>>
                                        <?php echo esc_html($name . ' (' . $code . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Geo Location: visitors from these countries go to Variant A.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="variant_b_page_id">Variant B page</label></th>
                        <td>
                            <select id="variant_b_page_id" name="variant_b_page_id" required>
                                <option value="0">— Select —</option>
                                <?php foreach ($pages as $p): ?>
                                    <option value="<?php echo esc_attr($p->ID); ?>" <?php selected($options['variant_b_page_id'], $p->ID); ?>>
                                        <?php echo esc_html($p->post_title . ' (' . get_permalink($p) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Variant B (e.g., /sale/v2).</p>

                            <label for="variant_b_countries"><strong>Countries for Variant B</strong></label><br />
                            <select id="variant_b_countries" name="variant_b_countries[]" multiple size="8" style="min-width: 320px;">
                                <?php foreach ($countries as $code => $name): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php echo in_array($code, $options['variant_b_countries'], true) ? 'selected' : ''; ?>>
                                        <?php echo esc_html($name . ' (' . $code . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Geo Location: visitors from these countries go to Variant B.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="redirection_method">Redirection method</label></th>
                        <td>
                            <select id="redirection_method" name="redirection_method">
                                <option value="random" <?php selected($options['redirection_method'], 'random'); ?>>Random</option>
                                <option value="geo" <?php selected($options['redirection_method'], 'geo'); ?>>Geo Location (Country)</option>
                            </select>
                            <p class="description">
                                Random: 50/50 sticky assignment via cookies. Geo Location: redirect based on visitor country using GeoLite2.
                                If a country is not listed in either variant, the original page is shown.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Campaign status</th>
                        <td>
                            <label>
                                <input type="checkbox" name="campaign_active" value="1" <?php checked(1, $options['campaign_active']); ?> />
                                Active (redirects apply based on the selected method)
                            </label>
                            <p class="description">Requires all three pages to be selected and distinct.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save settings'); ?>
            </form>

            <hr />

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 1rem;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="landing_ab_test_toggle" />
                <?php if ($options['campaign_active']): ?>
                    <input type="hidden" name="toggle" value="stop" />
                    <?php submit_button('Stop campaign', 'delete'); ?>
                <?php else: ?>
                    <input type="hidden" name="toggle" value="start" />
                    <?php submit_button('Start campaign', 'primary'); ?>
                <?php endif; ?>
            </form>

            <h2>Current configuration</h2>
            <ul>
                <li><strong>Original:</strong> <?php echo $this->label_for_page($options['original_page_id']); ?></li>
                <li><strong>Variant A:</strong> <?php echo $this->label_for_page($options['variant_a_page_id']); ?></li>
                <li><strong>Variant B:</strong> <?php echo $this->label_for_page($options['variant_b_page_id']); ?></li>
                <li><strong>Method:</strong> <?php echo esc_html(ucfirst($options['redirection_method'])); ?></li>
                <li><strong>Countries A:</strong> <?php echo esc_html(implode(', ', $this->format_country_codes($options['variant_a_countries'])) ?: '—'); ?></li>
                <li><strong>Countries B:</strong> <?php echo esc_html(implode(', ', $this->format_country_codes($options['variant_b_countries'])) ?: '—'); ?></li>
                <li><strong>Status:</strong> <?php echo $options['campaign_active'] ? 'Active' : 'Inactive'; ?></li>
            </ul>
        </div>
        <?php
    }

    /**
     * Frontend interception: random or geo redirection.
     */
    public function maybe_redirect_to_variant() {
        if (is_admin()) {
            return;
        }

        $options = $this->get_options();
        if (empty($options['campaign_active'])) {
            return;
        }

        $original_id = intval($options['original_page_id']);
        $variant_a   = intval($options['variant_a_page_id']);
        $variant_b   = intval($options['variant_b_page_id']);
        if ($original_id < 0 || $variant_a <= 0 || $variant_b <= 0) {
            return;
        }

        if (!is_page($original_id) && !($original_id == 0 && is_front_page())) {
            return;
        }

        // Prevent redirect loop: do not redirect if already on a variant page
        if (is_page($variant_a) || is_page($variant_b)) {
            return;
        }

        $method = $options['redirection_method'];

        if ($method === 'random') {
            $cookie_name = $this->cookie_name($original_id);
            $assignment  = isset($_COOKIE[$cookie_name]) ? sanitize_text_field($_COOKIE[$cookie_name]) : '';

            if ($assignment !== 'a' && $assignment !== 'b') {
                $assignment = (wp_rand(0, 1) === 0) ? 'a' : 'b';
                $this->set_assignment_cookie($original_id, $assignment);
            }

            $target_id  = ($assignment === 'a') ? $variant_a : $variant_b;
            $target_url = get_permalink($target_id);
            if ($target_url) {
                wp_redirect($target_url, 302);
                exit;
            }

        } elseif ($method === 'geo') {
            // Optional: cache country in a short-lived cookie to reduce lookups
            $country_cookie = 'landing_ab_country_' . $original_id;
            $countryCode = isset($_COOKIE[$country_cookie]) ? sanitize_text_field($_COOKIE[$country_cookie]) : '';

            if (!$countryCode) {
                $mmdb = __DIR__ . '/vendor/GeoLite2-Country.mmdb';
                if (!file_exists($mmdb)) {
                    // If DB missing, serve original
                    return;
                }
                try {
                    if (!class_exists(Reader::class)) {
                        // autoload or Reader not available – serve original
                        return;
                    }
                    $reader = new Reader($mmdb);
                    $record = $reader->country($this->get_client_ip());
                    $countryCode = $record->country->isoCode; // e.g., "US"
                    $this->set_simple_cookie($country_cookie, $countryCode, 60 * 60 * 6); // 6 hours cache
                } catch (\Exception $e) {
                    return; // serve original on failure
                }
            }

            if (in_array($countryCode, $options['variant_a_countries'], true)) {
                $target_id = $variant_a;
            } elseif (in_array($countryCode, $options['variant_b_countries'], true)) {
                $target_id = $variant_b;
            } else {
                $target_id = 0; // fallback to original
            }

            if ($target_id) {
                $target_url = get_permalink($target_id);
                if ($target_url) {
                    wp_redirect($target_url, 302);
                    exit;
                }
            }
        }
    }

    /**
     * Cookie helpers.
     */
    private function cookie_name($original_page_id) {
        return self::COOKIE_PREFIX . intval($original_page_id);
    }

    private function set_assignment_cookie($original_page_id, $assignment) {
        $name    = $this->cookie_name($original_page_id);
        $expires = time() + 60 * 60 * 24 * 30; // 30 days
        $secure   = is_ssl();
        $httponly = true;
        $samesite = 'Lax';

        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, $assignment, [
                'expires'  => $expires,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
                'secure'   => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite,
            ]);
        } else {
            setcookie($name, $assignment, $expires, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', $secure, $httponly);
        }
        $_COOKIE[$name] = $assignment;
    }

    private function set_simple_cookie($name, $value, $ttlSeconds) {
        $expires = time() + intval($ttlSeconds);
        $secure   = is_ssl();
        $httponly = true;
        $samesite = 'Lax';

        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, $value, [
                'expires'  => $expires,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
                'secure'   => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite,
            ]);
        } else {
            setcookie($name, $value, $expires, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', $secure, $httponly);
        }
        $_COOKIE[$name] = $value;
    }

    private function delete_assignment_cookie($original_page_id) {
        $name    = $this->cookie_name($original_page_id);
        $secure   = is_ssl();
        $httponly = true;
        $samesite = 'Lax';

        if (isset($_COOKIE[$name])) {
            if (PHP_VERSION_ID >= 70300) {
                setcookie($name, '', [
                    'expires'  => time() - 3600,
                    'path'     => COOKIEPATH ? COOKIEPATH : '/',
                    'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
                    'secure'   => $secure,
                    'httponly' => $httponly,
                    'samesite' => $samesite,
                ]);
            } else {
                setcookie($name, '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', $secure, $httponly);
            }
            unset($_COOKIE[$name]);
        }
    }

    public function clear_assignment_cookie_if_set() {
        $options = $this->get_options();
        if (!empty($options['original_page_id'])) {
            $this->delete_assignment_cookie($options['original_page_id']);
        }
    }

    /**
     * Data helpers.
     */
    private function get_published_pages() {
        $args = [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];
        $q = new WP_Query($args);
        return $q->posts;
    }

    private function get_options() {
        $defaults = [
            'original_page_id'     => 0,
            'variant_a_page_id'    => 0,
            'variant_b_page_id'    => 0,
            'campaign_active'      => 0,
            'redirection_method'   => 'random',
            'variant_a_countries'  => [],
            'variant_b_countries'  => [],
        ];
        $opt = get_option(self::OPTION_NAME, $defaults);
        return wp_parse_args($opt, $defaults);
    }

    private function label_for_page($id) {
        $id = intval($id);
        if ($id < 0) return '—';
        if ($id == 0) {
            return 'Home Page (' . home_url('/') . ')';
        }
        $url = get_permalink($id);
        $title = get_the_title($id);
        if (!$url || !$title) return '—';
        return esc_html($title) . ' (' . esc_url($url) . ')';
    }

    private function country_list() {
        return [
            'US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom',
            'DE' => 'Germany', 'FR' => 'France', 'ES' => 'Spain', 'IT' => 'Italy',
            'NL' => 'Netherlands', 'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark',
            'FI' => 'Finland', 'IE' => 'Ireland', 'PT' => 'Portugal', 'PL' => 'Poland',
            'CZ' => 'Czechia', 'AT' => 'Austria', 'CH' => 'Switzerland', 'BE' => 'Belgium',
            'RU' => 'Russia', 'UA' => 'Ukraine', 'RO' => 'Romania', 'HU' => 'Hungary',
            'BG' => 'Bulgaria', 'GR' => 'Greece', 'TR' => 'Turkey',
            'IN' => 'India', 'BD' => 'Bangladesh', 'PK' => 'Pakistan', 'LK' => 'Sri Lanka',
            'NP' => 'Nepal', 'CN' => 'China', 'JP' => 'Japan', 'KR' => 'South Korea',
            'SG' => 'Singapore', 'MY' => 'Malaysia', 'TH' => 'Thailand', 'VN' => 'Vietnam',
            'ID' => 'Indonesia', 'PH' => 'Philippines', 'AU' => 'Australia', 'NZ' => 'New Zealand',
            'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar', 'KW' => 'Kuwait',
            'BH' => 'Bahrain', 'OM' => 'Oman', 'EG' => 'Egypt', 'ZA' => 'South Africa',
            'NG' => 'Nigeria', 'KE' => 'Kenya', 'MA' => 'Morocco', 'TN' => 'Tunisia', 'DZ' => 'Algeria',
            'MX' => 'Mexico', 'BR' => 'Brazil', 'AR' => 'Argentina', 'CL' => 'Chile',
            'CO' => 'Colombia', 'PE' => 'Peru', 'UY' => 'Uruguay',
        ];
    }

    private function format_country_codes($codes) {
        $list = $this->country_list();
        $out = [];
        foreach ((array) $codes as $code) {
            if (isset($list[$code])) {
                $out[] = $list[$code] . ' (' . $code . ')';
            } else {
                $out[] = $code;
            }
        }
        return $out;
    }
}

new Landing_AB_Test_Plugin();



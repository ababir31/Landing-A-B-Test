<?php
/**
 * Plugin Name: Landing A/B Test
 * Description: A/B testing tool for a single landing page: assign visitors to v1/v2 and keep them there until you stop the campaign.
 * Version: 1.0.0
 * Author: Abir, Rasel
 * Author URI: https://profiles.wordpress.org/ababir/, https://profiles.wordpress.org/rsiddiqe/
 * License: GPLv2 or later
 */



if (!defined('ABSPATH')) {
    exit;
}

class Landing_AB_Test_Plugin {

    const OPTION_GROUP   = 'landing_ab_test_options_group';
    const OPTION_NAME    = 'landing_ab_test_options';
    const COOKIE_PREFIX  = 'landing_ab_assign_';
    const NONCE_ACTION   = 'landing_ab_test_nonce_action';
    const PAGE_SLUG      = 'landing-ab-test-settings';

    public function __construct() {
        // Admin
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_landing_ab_test_toggle', [$this, 'handle_toggle_campaign']);
        add_action('admin_post_landing_ab_test_save', [$this, 'handle_save_settings']);

        // Frontend interception
        add_action('template_redirect', [$this, 'maybe_redirect_to_variant'], 1);

        // Cleanup cookies on logout (nice to have)
        add_action('wp_logout', [$this, 'clear_assignment_cookie_if_set']);
    }

/**
 * Add a top-level menu page in the WordPress admin.
 */
public function add_settings_page() {
    add_menu_page(
        'Landing A/B Test',                // Page title (shown in <title>)
        'Landing A/B Test',                // Menu title (shown in sidebar)
        'manage_options',                  // Capability required
        self::PAGE_SLUG,                   // Menu slug
        [$this, 'render_settings_page'],   // Callback to render content
        'dashicons-randomize',             // Dashicon (choose any from WP icon list)
        25                                 // Position (25 puts it right after Comments)
    );
}


    /**
     * Register options.
     */
    public function register_settings() {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'sanitize_callback' => [$this, 'sanitize_options'],
            'default' => [
                'original_page_id' => 0,
                'variant_a_page_id' => 0,
                'variant_b_page_id' => 0,
                'campaign_active'   => 0,
            ],
        ]);
    }

    /**
     * Sanitize and validate settings.
     */
    public function sanitize_options($input) {
        $output = [
            'original_page_id' => isset($input['original_page_id']) ? $input['original_page_id'] : 0,
            'variant_a_page_id' => isset($input['variant_a_page_id']) ? $input['variant_a_page_id'] : 0,
            'variant_b_page_id' => isset($input['variant_b_page_id']) ? $input['variant_b_page_id'] : 0,
            'campaign_active'   => isset($input['campaign_active']) ? intval($input['campaign_active']) : 0,
        ];

        // Basic validation: pages must be published and distinct
        foreach (['original_page_id', 'variant_a_page_id', 'variant_b_page_id'] as $key) {
            if ($output[$key] !== 'home' && $output[$key] > 0) {
                $post = get_post($output[$key]);
                if (!$post || $post->post_type !== 'page' || $post->post_status !== 'publish') {
                    $output[$key] = 0;
                }
            } elseif ($output[$key] !== 'home') {
                $output[$key] = intval($output[$key]);
            }
        }

        if ($output['campaign_active']) {
            // Ensure all three selected and distinct
            $ids = [$output['original_page_id'], $output['variant_a_page_id'], $output['variant_b_page_id']];
            $valid = ($ids[0] && $ids[1] && $ids[2]);
            $distinct = (count(array_unique($ids)) === 3);
            if (!$valid || !$distinct) {
                // If invalid while trying to activate, force deactivate
                $output['campaign_active'] = 0;
            }
        }

        return $output;
    }

    /**
     * Handle saving settings (dropdown selections).
     */
    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        check_admin_referer(self::NONCE_ACTION);

        $input = [
            'original_page_id' => isset($_POST['original_page_id']) ? $_POST['original_page_id'] : 0,
            'variant_a_page_id' => isset($_POST['variant_a_page_id']) ? $_POST['variant_a_page_id'] : 0,
            'variant_b_page_id' => isset($_POST['variant_b_page_id']) ? $_POST['variant_b_page_id'] : 0,
            'campaign_active'   => isset($_POST['campaign_active']) ? intval($_POST['campaign_active']) : 0,
        ];

        $sanitized = $this->sanitize_options($input);
        update_option(self::OPTION_NAME, $sanitized);

        // If campaign got deactivated, clear cookie (optional)
        if (empty($sanitized['campaign_active'])) {
            $this->delete_assignment_cookie($sanitized['original_page_id']);
        }

        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'updated' => '1'], admin_url('options-general.php')));
        exit;
    }

    /**
     * Handle start/stop via button (explicit toggle).
     */
    public function handle_toggle_campaign() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        check_admin_referer(self::NONCE_ACTION);

        $options = $this->get_options();

        $toggle = isset($_POST['toggle']) ? sanitize_text_field($_POST['toggle']) : '';
        if ($toggle === 'start') {
            // Attempt to activate
            $options['campaign_active'] = 1;
            $options = $this->sanitize_options($options);
            update_option(self::OPTION_NAME, $options);
        } elseif ($toggle === 'stop') {
            // Deactivate and clear cookie
            $options['campaign_active'] = 0;
            update_option(self::OPTION_NAME, $options);
            $this->delete_assignment_cookie($options['original_page_id']);
        }

        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'toggled' => $toggle], admin_url('options-general.php')));
        exit;
    }

    /**
     * Render admin settings page.
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $options = $this->get_options();
        $pages   = $this->get_available_pages();

        ?>
        <div class="wrap">
            <h1>Landing A/B Test</h1>
            <p>Assign visitors to two variant pages from your original landing page. Assignment is sticky via cookies until you stop the campaign.</p>

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
                                <option value="">— Select —</option>
                                <option value="home" <?php selected($options['original_page_id'], 'home'); ?>>Home Page (/)</option>
                                <?php foreach ($pages as $p): ?>
                                    <option value="<?php echo esc_attr($p->ID); ?>" <?php selected($options['original_page_id'], $p->ID); ?>>
                                        <?php echo esc_html($p->post_title . ' (' . get_permalink($p) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">This is the original landing page URL (e.g., example.com/sale or /).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="variant_a_page_id">Variant A page</label></th>
                        <td>
                            <select id="variant_a_page_id" name="variant_a_page_id" required>
                                <option value="">— Select —</option>
                                <option value="home" <?php selected($options['variant_a_page_id'], 'home'); ?>>Home Page (/)</option>
                                <?php foreach ($pages as $p): ?>
                                    <option value="<?php echo esc_attr($p->ID); ?>" <?php selected($options['variant_a_page_id'], $p->ID); ?>>
                                        <?php echo esc_html($p->post_title . ' (' . get_permalink($p) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Your v1 page (e.g., example.com/sale/v1 or /).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="variant_b_page_id">Variant B page</label></th>
                        <td>
                            <select id="variant_b_page_id" name="variant_b_page_id" required>
                                <option value="">— Select —</option>
                                <option value="home" <?php selected($options['variant_b_page_id'], 'home'); ?>>Home Page (/)</option>
                                <?php foreach ($pages as $p): ?>
                                    <option value="<?php echo esc_attr($p->ID); ?>" <?php selected($options['variant_b_page_id'], $p->ID); ?>>
                                        <?php echo esc_html($p->post_title . ' (' . get_permalink($p) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Your v2 page (e.g., example.com/sale/v2 or /).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Campaign status</th>
                        <td>
                            <label>
                                <input type="checkbox" name="campaign_active" value="1" <?php checked(1, $options['campaign_active']); ?> />
                                Active (when checked, visitors to the original page will be redirected to a variant)
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
                <li><strong>Status:</strong> <?php echo $options['campaign_active'] ? 'Active' : 'Inactive'; ?></li>
            </ul>
        </div>
        <?php
    }

    /**
     * Intercept requests to the original page and redirect to assigned variant.
     */
    public function maybe_redirect_to_variant() {
        if (is_admin()) {
            return;
        }

        $options = $this->get_options();
        if (empty($options['campaign_active'])) {
            return;
        }

        $original_id = $options['original_page_id'];
        $variant_a   = $options['variant_a_page_id'];
        $variant_b   = $options['variant_b_page_id'];
        if (!$original_id || !$variant_a || !$variant_b) {
            return;
        }

        // Only act when the request is for the original page
        if ($original_id === 'home') {
            if (!is_front_page()) {
                return;
            }
        } else {
            if (!is_page($original_id)) {
                return;
            }
        }

        // Avoid redirect loops if someone visits variant directly
        $current_id = get_queried_object_id();
        if ($original_id !== 'home' && $current_id !== intval($original_id)) {
            return;
        }

        $cookie_name = $this->cookie_name($original_id);
        $assignment  = isset($_COOKIE[$cookie_name]) ? sanitize_text_field($_COOKIE[$cookie_name]) : '';

        if ($assignment !== 'a' && $assignment !== 'b') {
            // Randomly assign A or B on first hit
            $assignment = (wp_rand(0, 1) === 0) ? 'a' : 'b';
            $this->set_assignment_cookie($original_id, $assignment);
        }

        $target_id = ($assignment === 'a') ? $variant_a : $variant_b;
        if ($target_id === 'home') {
            $target_url = home_url('/');
        } else {
            $target_url = get_permalink($target_id);
        }

        // Only redirect if target URL is valid
        if ($target_url) {
            // Use 302 to keep analytics sane; could be 307 to preserve method
            wp_redirect($target_url, 302);
            exit;
        }
    }

    /**
     * Create a unique cookie name per original page.
     */
    private function cookie_name($original_page_id) {
        if ($original_page_id === 'home') {
            return self::COOKIE_PREFIX . 'home';
        }
        return self::COOKIE_PREFIX . intval($original_page_id);
    }

    /**
     * Set assignment cookie (default 30 days).
     */
    private function set_assignment_cookie($original_page_id, $assignment) {
        $name    = $this->cookie_name($original_page_id);
        $expires = time() + 60 * 60 * 24 * 30; // 30 days

        // Respect site cookie params
        $secure   = is_ssl();
        $httponly = true;
        $samesite = 'Lax';

        // PHP < 7.3 fallback vs array options
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
            // Best-effort legacy
            setcookie($name, $assignment, $expires, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', $secure, $httponly);
        }

        // Also set in $_COOKIE for immediate use
        $_COOKIE[$name] = $assignment;
    }

    /**
     * Delete assignment cookie.
     */
    private function delete_assignment_cookie($original_page_id) {
        $name    = $this->cookie_name($original_page_id);
        $secure  = is_ssl();
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

    /**
     * Optional cleanup on logout.
     */
    public function clear_assignment_cookie_if_set() {
        $options = $this->get_options();
        if (!empty($options['original_page_id'])) {
            $this->delete_assignment_cookie($options['original_page_id']);
        }
    }

    /**
     * Helper: get all published pages plus home page.
     */
    private function get_available_pages() {
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

    /**
     * Helper: get options with defaults.
     */
    private function get_options() {
        $defaults = [
            'original_page_id' => 0,
            'variant_a_page_id' => 0,
            'variant_b_page_id' => 0,
            'campaign_active'   => 0,
        ];
        $opt = get_option(self::OPTION_NAME, $defaults);
        return wp_parse_args($opt, $defaults);
    }

    /**
     * Helper: label for page ID.
     */
    private function label_for_page($id) {
        if ($id === 'home') {
            return 'Home Page (' . esc_url(home_url('/')) . ')';
        }
        $id = intval($id);
        if ($id <= 0) return '—';
        $url = get_permalink($id);
        $title = get_the_title($id);
        if (!$url || !$title) return '—';
        return esc_html($title) . ' (' . esc_url($url) . ')';
    }
}

new Landing_AB_Test_Plugin();

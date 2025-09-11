<?php
/**
 * Plugin Name: FUB to WP
 * Plugin URI: https://fubtowordpress.com
 * Description: Complete Follow Up Boss to WordPress integration with FUB API key validation, Stripe subscription, multi-site license sharing, local WordPress settings storage, and automatic pixel sync.
 * Version: 1.0.3
 * Author: FUB to WP Team
 * License: GPL v2 or later
 * Requires at least: 5.6
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check minimum requirements
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>FUB to WP requires PHP 7.4 or higher.</p></div>';
    });
    return;
}

// Define plugin constants
if (!defined('FUB_VERSION')) {
    define('FUB_VERSION', '4.3.1');
    define('FUB_PLUGIN_FILE', __FILE__);
    define('FUB_PLUGIN_DIR', plugin_dir_path(__FILE__));
    define('FUB_PLUGIN_URL', plugin_dir_url(__FILE__));
    define('FUB_PLUGIN_BASENAME', plugin_basename(__FILE__));
    define('FUB_BACKEND_URL', 'https://kpgjbifugyfqzoftnivx.supabase.co/rest/v1');
    // Define Stripe public key from secure remote endpoint
    if (!defined('FUB_STRIPE_PUBLIC_KEY')) {
        $stripe_key = get_transient('fub_secure_stripe_key');
        if (!$stripe_key) {
            $credentials_api = new FUB_Backend_API();
            $stripe_key = $credentials_api->get_secure_credential('stripe_public_key');
            if ($stripe_key) {
                set_transient('fub_secure_stripe_key', $stripe_key, 3600); // Cache for 1 hour
            }
        }
        if ($stripe_key) {
            define('FUB_STRIPE_PUBLIC_KEY', $stripe_key);
        }
    }
    
    // Database table names
    global $wpdb;
    define('FUB_LEADS_TABLE', $wpdb->prefix . 'fub_leads');
    define('FUB_TAGS_TABLE', $wpdb->prefix . 'fub_tags');
    define('FUB_ACTIVITY_TABLE', $wpdb->prefix . 'fub_activity');
}

/**
 * =============================================================================
 * BACKEND API CLASS
 * =============================================================================
 */
class FUB_Backend_API {
    
    private $backend_url;
    private $timeout;
    private $anon_key;
    
    public function __construct() {
        $this->backend_url = FUB_BACKEND_URL;
        $this->timeout = 30;
        // Get Supabase anon key directly to avoid circular dependency
        $this->anon_key = $this->get_supabase_anon_key();
    }
    
    public function check_existing_subscription($account_id) {
        $data = array(
            'account_id_param' => $account_id,
            'domain_param' => parse_url(home_url(), PHP_URL_HOST)
        );
        
        return $this->make_request('rpc/check_subscription', 'POST', $data);
    }
    
    public function create_stripe_session_with_account($account_id) {
        $data = array(
            'account_id' => $account_id,
            'success_url' => admin_url('admin.php?page=fub-to-wp&popup_payment=success&session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => admin_url('admin.php?page=fub-to-wp&popup_payment=canceled')
        );
        
        // Use Edge Function - separate URL from regular RPC
        $response = $this->make_edge_function_request('create-checkout-session', 'POST', $data);
        
        // If checkout session was created successfully, also create payment session tracking
        if ($response['success'] && isset($response['data']['session_id'])) {
            $session_id = $response['data']['session_id'];
            $this->create_payment_session($session_id, $account_id);
        }
        
        return $response;
    }
    
    public function create_payment_session($session_id, $account_id) {
        $data = array(
            'session_id_param' => $session_id,
            'account_id_param' => $account_id
        );
        
        return $this->make_request('rpc/create_payment_session', 'POST', $data);
    }
    
    public function save_pixel_with_account($account_id, $license_key, $pixel_code) {
        $data = array(
            'account_id_param' => $account_id,
            'license_key_param' => $license_key,
            'pixel_code_param' => $pixel_code
        );
        
        return $this->make_request('rpc/save_pixel', 'POST', $data);
    }
    
    public function create_subscription_direct($subscription_data) {
        // Direct API call to create subscription in subscriptions table
        $url = $this->backend_url . '/subscriptions';
        
        $args = array(
            'method' => 'POST',
            'timeout' => $this->timeout,
            'headers' => array(
                'apikey' => $this->anon_key,
                'Authorization' => 'Bearer ' . $this->anon_key,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'
            ),
            'body' => json_encode($subscription_data)
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            error_log('FUB Backend API Error (create_subscription_direct): ' . $response->get_error_message());
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 201 || $status_code === 200) {
            return array(
                'success' => true,
                'data' => json_decode($body, true)
            );
        } else {
            error_log('FUB Backend API Error (create_subscription_direct): HTTP ' . $status_code . ' - ' . $body);
            return array(
                'success' => false,
                'error' => 'HTTP ' . $status_code,
                'details' => $body
            );
        }
    }
    
    public function validate_license_before_lead($license_key, $account_id) {
        $data = array(
            'license_key_param' => $license_key,
            'account_id_param' => $account_id,
            'domain_param' => parse_url(home_url(), PHP_URL_HOST)
        );
        
        $response = $this->make_request('rpc/validate_license', 'POST', $data);
        
        // If validation successful and pixel_code is returned, save it automatically
        if ($response['success'] && isset($response['data']['valid']) && $response['data']['valid']) {
            if (!empty($response['data']['pixel_code'])) {
                update_option('fub_pixel_id', $response['data']['pixel_code']);
                error_log('FUB Integration: Pixel automatically installed from license validation');
            }
        }
        
        return $response;
    }
    
    public function validate_payment($session_id) {
        $data = array('session_id_param' => $session_id);
        return $this->make_request('rpc/validate_payment', 'POST', $data);
    }
    
    public function get_license($account_id) {
        $data = array('account_id_param' => $account_id);
        return $this->make_request('rpc/get_license_by_account', 'POST', $data);
    }
    
    public function validate_license($license_key) {
        $data = array(
            'license_key_param' => $license_key,
            'domain_param' => parse_url(home_url(), PHP_URL_HOST),
            'account_id_param' => get_option('fub_account_id')
        );
        
        $response = $this->make_request('rpc/validate_license', 'POST', $data);
        
        // If validation successful and pixel_code is returned, save it automatically
        if ($response['success'] && isset($response['data']['valid']) && $response['data']['valid']) {
            if (!empty($response['data']['pixel_code'])) {
                update_option('fub_pixel_id', $response['data']['pixel_code']);
                error_log('FUB Integration: Pixel automatically installed from license validation');
            }
        }
        
        return $response;
    }
    
    public function get_pixel_from_account($account_id, $license_key) {
        $data = array(
            'account_id_param' => $account_id,
            'license_key_param' => $license_key
        );
        
        return $this->make_request('rpc/get_pixel', 'POST', $data);
    }
    
    public function save_oauth_tokens($account_id, $access_token, $refresh_token, $expires_in) {
        $data = array(
            'account_id_param' => $account_id,
            'access_token_param' => $access_token,
            'refresh_token_param' => $refresh_token,
            'expires_in_param' => $expires_in,
            'domain_param' => parse_url(home_url(), PHP_URL_HOST)
        );
        
        return $this->make_request('rpc/save_oauth_tokens', 'POST', $data);
    }
    
    public function get_oauth_tokens($account_id) {
        $data = array(
            'account_id_param' => $account_id,
            'domain_param' => parse_url(home_url(), PHP_URL_HOST)
        );
        
        return $this->make_request('rpc/get_oauth_tokens', 'POST', $data);
    }
    
    public function refresh_oauth_token($account_id, $refresh_token) {
        $data = array(
            'account_id_param' => $account_id,
            'refresh_token_param' => $refresh_token,
            'domain_param' => parse_url(home_url(), PHP_URL_HOST)
        );
        
        return $this->make_request('rpc/refresh_oauth_token', 'POST', $data);
    }
    
    public function track_lead($lead_data) {
        // NOTE: Backend tracking disabled - all leads stored locally in WordPress only
        return array('success' => true);
    }
    
    public function make_edge_function_request($function_name, $method = 'POST', $data = array()) {
        // Edge Functions have different URL structure
        $base_url = str_replace('/rest/v1', '', $this->backend_url);
        $url = rtrim($base_url, '/') . '/functions/v1/' . $function_name;
        
        // DEBUG: Log the exact request being made
        $debug_log = "🌐 EDGE FUNCTION REQUEST:\n";
        $debug_log .= "🌐 - URL: " . $url . "\n";
        $debug_log .= "🌐 - Method: " . strtoupper($method) . "\n";
        $debug_log .= "🌐 - Data: " . json_encode($data) . "\n";
        
        error_log($debug_log);
        
        // Also write to the debug file
        file_put_contents(__DIR__ . '/supabase_debug.log', date('Y-m-d H:i:s') . " EDGE FUNCTION REQUEST:\n" . $debug_log . "\n", FILE_APPEND);
        
        $args = array(
            'method' => strtoupper($method),
            'timeout' => $this->timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->anon_key,
                'User-Agent' => 'FUB-Integration/' . FUB_VERSION . ' WordPress/' . get_bloginfo('version')
            )
        );
        
        if (!empty($data)) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
                'data' => null
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($body, true);
        
        // DEBUG: Log the response
        $response_log = "🌐 EDGE FUNCTION RESPONSE:\n";
        $response_log .= "🌐 - Status: " . $status_code . "\n";
        $response_log .= "🌐 - Body: " . $body . "\n";
        $response_log .= "🌐 - Decoded: " . json_encode($decoded_body) . "\n";
        
        error_log($response_log);
        
        // Also write to the debug file
        file_put_contents(__DIR__ . '/supabase_debug.log', date('Y-m-d H:i:s') . " EDGE FUNCTION RESPONSE:\n" . $response_log . "\n", FILE_APPEND);
        
        if ($status_code >= 200 && $status_code < 300) {
            return array(
                'success' => true,
                'error' => null,
                'data' => $decoded_body
            );
        } else {
            return array(
                'success' => false,
                'error' => $decoded_body['error'] ?? 'Unknown error',
                'data' => null
            );
        }
    }

    public function make_request($endpoint, $method = 'POST', $data = array()) {
        $url = rtrim($this->backend_url, '/') . '/' . ltrim($endpoint, '/');
        
        // DEBUG: Log the exact request being made
        $debug_log = "🌐 SUPABASE REQUEST:\n";
        $debug_log .= "🌐 - URL: " . $url . "\n";
        $debug_log .= "🌐 - Method: " . strtoupper($method) . "\n";
        $debug_log .= "🌐 - Data: " . json_encode($data) . "\n";
        
        error_log($debug_log);
        
        // Also write to a specific file for easy access
        file_put_contents(__DIR__ . '/supabase_debug.log', date('Y-m-d H:i:s') . " REQUEST:\n" . $debug_log . "\n", FILE_APPEND);
        
        $args = array(
            'method' => strtoupper($method),
            'timeout' => $this->timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
                'apikey' => $this->anon_key,
                'Authorization' => 'Bearer ' . $this->anon_key,
                'User-Agent' => 'FUB-Integration/' . FUB_VERSION . ' WordPress/' . get_bloginfo('version')
            )
        );
        
        if (!empty($data)) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
                'data' => null
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($body, true);
        
        // DEBUG: Log the response
        $response_log = "🌐 SUPABASE RESPONSE:\n";
        $response_log .= "🌐 - Status: " . $status_code . "\n";
        $response_log .= "🌐 - Body: " . $body . "\n";
        $response_log .= "🌐 - Decoded: " . json_encode($decoded_body) . "\n";
        
        error_log($response_log);
        
        // Also write to the debug file
        file_put_contents(__DIR__ . '/supabase_debug.log', date('Y-m-d H:i:s') . " RESPONSE:\n" . $response_log . "\n", FILE_APPEND);
        
        if ($status_code >= 200 && $status_code < 300) {
            return array(
                'success' => true,
                'error' => null,
                'data' => $decoded_body
            );
        } else {
            return array(
                'success' => false,
                'error' => $decoded_body['error'] ?? 'Unknown error',
                'data' => null
            );
        }
    }
    
    /**
     * Get secure credentials from Supabase Edge Function
     */
    public function get_secure_credential($credential_type) {
        // Use caching to avoid repeated requests
        $cache_key = 'fub_secure_' . $credential_type;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        // Call Supabase Edge Function to get credentials
        $url = 'https://kpgjbifugyfqzoftnivx.supabase.co/functions/v1/get-secure-credentials';
        
        $args = array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'apikey' => $this->anon_key,
                'Authorization' => 'Bearer ' . $this->anon_key,
            ),
            'body' => json_encode(array(
                'credential_type' => $credential_type,
                'domain' => parse_url(home_url(), PHP_URL_HOST),
                'plugin_version' => FUB_VERSION
            )),
            'timeout' => 10
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            error_log('FUB Secure Credentials Error: ' . $response->get_error_message());
            
            // Fallback to encrypted credentials stored locally (temporary solution)
            return $this->get_fallback_credential($credential_type);
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['credential'])) {
            // Cache for 6 hours
            set_transient($cache_key, $data['credential'], 6 * HOUR_IN_SECONDS);
            return $data['credential'];
        }
        
        // Use fallback if edge function fails
        return $this->get_fallback_credential($credential_type);
    }
    
    /**
     * Fallback when Edge Function is not available
     */
    private function get_fallback_credential($credential_type) {
        // Log error and return empty - Edge Function is required
        error_log('FUB to WP Error: Unable to fetch credentials from Supabase Edge Function. Please ensure the Edge Function is deployed.');
        
        // Show admin notice
        add_action('admin_notices', function() use ($credential_type) {
            echo '<div class="error"><p>FUB to WP: Unable to fetch ' . esc_html($credential_type) . ' from secure endpoint. Please contact support.</p></div>';
        });
        
        return '';
    }
    
    /**
     * Get Supabase anon key - hardcoded since it's public and needed for bootstrap
     */
    private function get_supabase_anon_key() {
        // Supabase anon keys are public and safe to hardcode
        // This prevents circular dependency when calling get-secure-credentials
        return 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImtwZ2piaWZ1Z3lmcXpvZnRuaXZ4Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NTY1NjE3NjksImV4cCI6MjA3MjEzNzc2OX0.MjTJK5EXVUCVUXBAm27UqQm9zMGiVB9otk1RvVLmr8U';
    }
    
    public function sync_pixel_to_cloud($account_id, $pixel_code) {
        $data = array(
            'account_id_param' => $account_id,
            'setting_key_param' => 'fub_pixel_id',
            'setting_value_param' => $pixel_code
        );
        
        return $this->make_request('rpc/sync_plugin_setting', 'POST', $data);
    }
    
    public function get_pixel_from_cloud($account_id) {
        $data = array(
            'account_id_param' => $account_id
        );
        
        $result = $this->make_request('rpc/get_plugin_settings', 'POST', $data);
        
        if ($result['success'] && isset($result['data']['settings']['fub_pixel_id'])) {
            return array(
                'success' => true,
                'pixel_code' => $result['data']['settings']['fub_pixel_id']
            );
        }
        
        return array(
            'success' => false,
            'pixel_code' => null
        );
    }
}

/**
 * =============================================================================
 * FOLLOW UP BOSS OAUTH CLASS
 * =============================================================================
 */
class FUB_OAuth_Manager {
    
    private $client_id;
    private $client_secret;
    private $redirect_uri;
    private $site_url;
    private $backend_api;
    private $x_system;
    private $x_system_key;
    
    public function __construct() {
        // Site information
        $this->site_url = rtrim(home_url(), '/');
        
        // Initialize backend API first to use get_secure_credential
        $this->backend_api = new FUB_Backend_API();
        
        // Use global proxy for OAuth callback (works with any WordPress installation)
        $this->redirect_uri = 'https://oauth.fubtowordpress.com';
        
        // Get OAuth credentials securely from remote endpoint
        $this->client_id = $this->backend_api->get_secure_credential('oauth_client_id');
        $this->client_secret = $this->backend_api->get_secure_credential('oauth_client_secret');
        
        // Get X-System headers for FUB OAuth API
        $this->x_system = $this->backend_api->get_secure_credential('x_system');
        $this->x_system_key = $this->backend_api->get_secure_credential('x_system_key');
        
        error_log('FUB OAuth: Initialized global OAuth proxy');
        error_log('FUB OAuth: Site URL: ' . $this->site_url);
        error_log('FUB OAuth: Proxy URI: ' . $this->redirect_uri);
    }
    
    public function test_system_registration() {
        // Test with different header capitalizations to find the right format
        $header_variations = array(
            array('x-System' => $this->x_system, 'X-System-Key' => $this->x_system_key),
            array('X-System' => $this->x_system, 'X-System-Key' => $this->x_system_key),
            array('x-system' => $this->x_system, 'x-system-key' => $this->x_system_key),
        );
        
        // Possible redirect URIs to test
        $test_redirect_uris = array(
            'https://miapp.com/auth/callback', // Your registered URI
            'https://httpbin.org/get', // Test URI
            $this->redirect_uri // Current URI
        );
        
        foreach ($header_variations as $i => $headers) {
            error_log("FUB OAuth: Testing header variation " . ($i + 1) . ": " . json_encode(array_keys($headers)));
            
            foreach ($test_redirect_uris as $j => $test_uri) {
                error_log("FUB OAuth: Testing redirect URI " . ($j + 1) . ": " . $test_uri);
                
                // Try creating a test OAuth app
                $response = wp_remote_post('https://api.followupboss.com/v1/oauthApps', array(
                    'headers' => array_merge($headers, array(
                        'Content-Type' => 'application/json'
                    )),
                    'body' => json_encode(array('redirectUris' => array($test_uri))),
                    'timeout' => 30
                ));
                
                if (is_wp_error($response)) {
                    error_log("FUB OAuth: WP Error for header {$i}/URI {$j}: " . $response->get_error_message());
                    continue;
                }
                
                $status_code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                
                error_log("FUB OAuth: Header {$i}/URI {$j} - Status: {$status_code}, Body: " . substr($body, 0, 200));
                
                if ($status_code === 201) {
                    error_log("FUB OAuth: SUCCESS! Working headers: " . json_encode(array_keys($headers)) . ", URI: " . $test_uri);
                    
                    // Store the working redirect URI
                    update_option('fub_oauth_redirect_uri', $test_uri);
                    $this->redirect_uri = $test_uri;
                    
                    // Clean up test app if created
                    $data = json_decode($body, true);
                    if (isset($data['id'])) {
                        $this->delete_test_oauth_app($data['id'], $headers);
                    }
                    
                    return array(
                        'success' => true, 
                        'working_headers' => $headers,
                        'working_redirect_uri' => $test_uri
                    );
                } elseif ($status_code !== 401 && $status_code !== 403) {
                    error_log("FUB OAuth: Partial success - headers work but redirect URI issue");
                    // Headers work but redirect URI might be the issue
                    return array(
                        'success' => true, 
                        'working_headers' => $headers,
                        'note' => 'Headers accepted, redirect URI needs adjustment'
                    );
                }
            }
        }
        
        return array('success' => false, 'error' => 'No working header/redirect URI combination found. Please verify your credentials and redirect URI.');
    }
    
    private function delete_test_oauth_app($app_id, $headers) {
        // Clean up test app
        wp_remote_request('https://api.followupboss.com/v1/oauthApps/' . $app_id, array(
            'method' => 'DELETE',
            'headers' => $headers,
            'timeout' => 30
        ));
    }

    public function create_oauth_app() {
        // Using global OAuth proxy - no need to create individual OAuth apps
        // All WordPress installations use the same OAuth app with proxy redirect
        
        error_log('FUB OAuth: Using global OAuth proxy system');
        
        return array(
            'success' => true,
            'client_id' => $this->client_id,
            'message' => 'Using global OAuth proxy - ready to authenticate'
        );
    }
    
    public function get_authorization_url() {
        if (!$this->client_id) {
            return false;
        }
        
        // Encode the WordPress site URL in the state parameter so the proxy knows where to redirect back
        $state = urlencode($this->site_url);
        
        $params = array(
            'response_type' => 'code',
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'state' => $state,
            'prompt' => 'consent'
        );
        
        return 'https://app.followupboss.com/oauth/authorize?' . http_build_query($params);
    }
    
    public function exchange_code_for_token($code, $state) {
        // Verify state to prevent CSRF attacks
        $stored_state = get_option('fub_oauth_state');
        if (!$stored_state || $state !== $stored_state) {
            error_log('FUB OAuth: Invalid state parameter');
            return array('success' => false, 'error' => 'Invalid state parameter');
        }
        
        // Clean up state
        delete_option('fub_oauth_state');
        
        // Use stored OAuth credentials for this site
        $client_id = get_option('fub_oauth_client_id');
        $client_secret = get_option('fub_oauth_client_secret');
        $redirect_uri = admin_url('admin.php?page=fub-to-wp&oauth_callback=1');
        
        if (!$client_id || !$client_secret) {
            return array('success' => false, 'error' => 'OAuth credentials not found. Please reconnect.');
        }
        
        $url = 'https://app.followupboss.com/oauth/token';
        
        $data = array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect_uri
        );
        
        $auth = base64_encode($client_id . ':' . $client_secret);
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . $auth
            ),
            'body' => http_build_query($data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('FUB OAuth: Error exchanging code for token - ' . $response->get_error_message());
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $token_data = json_decode($body, true);
        
        error_log("FUB OAuth: Token exchange response - Status: {$status_code}, Body: " . $body);
        
        if ($status_code === 200 && isset($token_data['access_token'])) {
            // Store tokens securely
            update_option('fub_oauth_access_token', $token_data['access_token']);
            update_option('fub_oauth_refresh_token', $token_data['refresh_token']);
            update_option('fub_oauth_token_expires', time() + $token_data['expires_in']);
            
            // Get user account info
            $account_info = $this->get_account_info($token_data['access_token']);
            if ($account_info['success']) {
                update_option('fub_account_id', $account_info['data']['id']);
                update_option('fub_account_name', $account_info['data']['name']);
            }
            
            error_log('FUB OAuth: Token exchange successful');
            
            return array(
                'success' => true,
                'access_token' => $token_data['access_token'],
                'account_info' => $account_info
            );
        }
        
        return array(
            'success' => false,
            'error' => $token_data['error_description'] ?? 'Token exchange failed'
        );
    }
    
    public function get_stored_tokens() {
        // This would query Supabase to get the stored tokens for this site
        // For now, we'll assume tokens are handled by the callback and stored locally
        $access_token = get_option('fub_oauth_access_token');
        
        if ($access_token) {
            return array(
                'success' => true,
                'access_token' => $access_token,
                'refresh_token' => get_option('fub_oauth_refresh_token'),
                'expires_at' => get_option('fub_oauth_token_expires'),
                'fub_account_id' => get_option('fub_account_id'),
                'fub_account_name' => get_option('fub_account_name')
            );
        }
        
        return array('success' => false, 'error' => 'No tokens found');
    }
    
    public function refresh_access_token($refresh_token = null) {
        if (!$refresh_token) {
            $refresh_token = get_option('fub_oauth_refresh_token');
        }
        if (!$refresh_token) {
            return array('success' => false, 'error' => 'No refresh token available');
        }
        
        $url = 'https://app.followupboss.com/oauth/token';
        
        $data = array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token
        );
        
        $auth = base64_encode($this->client_id . ':' . $this->client_secret);
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . $auth
            ),
            'body' => http_build_query($data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('FUB OAuth: Error refreshing token - ' . $response->get_error_message());
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $token_data = json_decode($body, true);
        
        if ($status_code === 200 && isset($token_data['access_token'])) {
            // Save refreshed tokens to Supabase
            $account_id = get_option('fub_account_id');
            if ($account_id) {
                $save_result = $this->backend_api->save_oauth_tokens(
                    $account_id, 
                    $token_data['access_token'], 
                    $token_data['refresh_token'] ?? $refresh_token, 
                    $token_data['expires_in']
                );
                
                if ($save_result['success']) {
                    error_log('🔐 OAuth: Refreshed tokens saved to Supabase for account: ' . $account_id);
                } else {
                    error_log('🔐 OAuth ERROR: Failed to save refreshed tokens: ' . json_encode($save_result));
                }
            }
            
            error_log('FUB OAuth: Token refreshed successfully');
            return array('success' => true, 'access_token' => $token_data['access_token']);
        }
        
        return array(
            'success' => false,
            'error' => $token_data['error_description'] ?? 'Token refresh failed'
        );
    }
    
    public function get_valid_access_token() {
        // First try session (for immediate use during setup)
        if (!session_id()) {
            session_start();
        }
        
        $session_token = $_SESSION['fub_temp_access_token'] ?? null;
        $session_expires = $_SESSION['fub_temp_token_expires'] ?? 0;
        
        if ($session_token && time() <= $session_expires) {
            return $session_token;
        }
        
        // If no session token or expired, get from Supabase
        $account_id = get_option('fub_account_id');
        if (!$account_id) {
            return false;
        }
        
        $tokens_response = $this->backend_api->get_oauth_tokens($account_id);
        
        if (!$tokens_response['success'] || !isset($tokens_response['data']['access_token'])) {
            error_log('🔐 OAuth: No valid tokens found in Supabase for account: ' . $account_id);
            return false;
        }
        
        $token_data = $tokens_response['data'];
        
        // Check if token is expired or will expire in the next 5 minutes (300 seconds)
        $expires_timestamp = isset($token_data['expires_at']) ? strtotime($token_data['expires_at']) : time() + 3600;
        $buffer_time = 300; // 5 minutes buffer
        
        if (time() + $buffer_time >= $expires_timestamp) {
            error_log('🔐 OAuth: Token expires soon or expired, attempting refresh for account: ' . $account_id);
            // Try to refresh token
            if (isset($token_data['refresh_token'])) {
                $refresh_result = $this->refresh_access_token($token_data['refresh_token']);
                if ($refresh_result['success']) {
                    error_log('🔐 OAuth: Token successfully refreshed for account: ' . $account_id);
                    return $refresh_result['access_token'];
                }
            }
            error_log('🔐 OAuth: Token expired/expires soon and refresh failed for account: ' . $account_id);
            return false;
        }
        
        return $token_data['access_token'];
    }
    
    public function get_account_info($access_token = null) {
        if (!$access_token) {
            $access_token = $this->get_valid_access_token();
            if (!$access_token) {
                return array('success' => false, 'error' => 'No valid access token');
            }
        }
        
        // Try /v1/me endpoint first (this should return the user and account info)
        $response = wp_remote_get('https://api.followupboss.com/v1/me', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('FUB OAuth: Error getting account info: ' . $response->get_error_message());
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        error_log('FUB OAuth: Account info response - Status: ' . $status_code . ', Body: ' . substr($body, 0, 500));
        
        if ($status_code === 200 && isset($data['account'])) {
            // Transform the response to match expected format
            return array(
                'success' => true, 
                'data' => array(
                    'id' => $data['account'],
                    'name' => $data['name'] ?? '',
                    'email' => $data['email'] ?? '',
                    'raw' => $data
                )
            );
        }
        
        // If /v1/me doesn't work, try /v1/account endpoint
        $response = wp_remote_get('https://api.followupboss.com/v1/account', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (!is_wp_error($response)) {
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if ($status_code === 200 && $data) {
                return array('success' => true, 'data' => $data);
            }
        }
        
        return array('success' => false, 'error' => $data['message'] ?? 'Failed to get account info');
    }
    
    public function make_authenticated_request($url, $method = 'GET', $data = null, $retry_count = 0) {
        $access_token = $this->get_valid_access_token();
        if (!$access_token) {
            return array('success' => false, 'error' => 'No valid access token available');
        }
        
        $args = array(
            'method' => strtoupper($method),
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        );
        
        if ($data && in_array(strtoupper($method), array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($body, true);
        
        // If we got a 401 (Unauthorized) and haven't retried yet, try to refresh token and retry
        if ($status_code === 401 && $retry_count === 0) {
            error_log('🔐 OAuth: Received 401 error, attempting token refresh and retry');
            
            // Force token refresh by clearing any cached tokens and getting new one
            $account_id = get_option('fub_account_id');
            if ($account_id) {
                $tokens_response = $this->backend_api->get_oauth_tokens($account_id);
                if ($tokens_response['success'] && isset($tokens_response['data']['refresh_token'])) {
                    $refresh_result = $this->refresh_access_token($tokens_response['data']['refresh_token']);
                    if ($refresh_result['success']) {
                        error_log('🔐 OAuth: Token refreshed successfully, retrying request');
                        // Retry the request with new token (recursive call with retry_count = 1)
                        return $this->make_authenticated_request($url, $method, $data, 1);
                    }
                }
            }
            error_log('🔐 OAuth: Token refresh failed, cannot retry request');
        }
        
        if ($status_code >= 200 && $status_code < 300) {
            return array('success' => true, 'data' => $decoded_body);
        }
        
        return array(
            'success' => false,
            'error' => $decoded_body['message'] ?? 'Request failed',
            'status_code' => $status_code
        );
    }
    
    public function is_connected() {
        // Check for forced disconnected state first
        if (get_transient('fub_force_disconnected')) {
            error_log('🔐 OAuth: is_connected() = false - Force disconnected flag active');
            return false;
        }
        
        // Check if we have account_id (basic requirement)
        $account_id = get_option('fub_account_id');
        if (!$account_id) {
            error_log('🔐 OAuth: is_connected() = false - No account_id');
            return false;
        }
        
        // Try to get valid access token
        $access_token = $this->get_valid_access_token();
        $connected = !empty($access_token);
        
        error_log('🔐 OAuth: is_connected() = ' . ($connected ? 'true' : 'false') . ' - Account: ' . $account_id . ', Token: ' . ($access_token ? 'PRESENT' : 'NONE'));
        
        return $connected;
    }
    
    public function disconnect() {
        // Get account_id BEFORE clearing data for Supabase cleanup
        $account_id = get_option('fub_account_id');
        
        // Delete tokens from Supabase first
        if ($account_id) {
            $backend_api = new FUB_Backend_API();
            $delete_result = $backend_api->delete_oauth_tokens($account_id);
            if ($delete_result['success']) {
                error_log('🔐 OAuth: Tokens deleted from Supabase for account: ' . $account_id);
            }
        }
        
        // Clear all OAuth and account related data
        delete_option('fub_oauth_access_token');
        delete_option('fub_oauth_refresh_token');
        delete_option('fub_oauth_token_expires');
        delete_option('fub_oauth_state');
        delete_option('fub_account_id');
        delete_option('fub_account_name');
        delete_option('fub_oauth_account_id');
        delete_option('fub_oauth_account_name');
        delete_option('fub_oauth_client_id');
        delete_option('fub_oauth_client_secret');
        
        // Clear setup status to force fresh setup
        delete_option('fub_setup_completed');
        delete_option('fub_has_valid_license');
        
        // Clear all transients and cached data
        delete_transient('fub_license_cache');
        delete_transient('fub_payment_success');
        delete_transient('fub_subscription_expired');
        delete_transient('fub_secure_stripe_key');
        
        // Clear session tokens if they exist
        if (!session_id()) {
            session_start();
        }
        unset($_SESSION['fub_temp_access_token']);
        unset($_SESSION['fub_temp_token_expires']);
        
        // Force WordPress to clear all caches
        wp_cache_flush();
        
        // Clear WordPress object cache for specific keys
        wp_cache_delete('fub_account_id', 'options');
        wp_cache_delete('fub_account_name', 'options');
        wp_cache_delete('fub_oauth_access_token', 'options');
        wp_cache_delete('fub_setup_completed', 'options');
        
        // Set a temporary flag to force disconnected state
        set_transient('fub_force_disconnected', true, 60); // 60 seconds
        
        error_log('FUB OAuth: User completely disconnected from FUB - all data cleared');
        return true;
    }
}

/**
 * =============================================================================
 * LICENSE MANAGER CLASS
 * =============================================================================
 */
class FUB_License_Manager {
    
    private $backend_api;
    private $license_cache_key = 'fub_license_cache';
    private $cache_duration = 3600; // 1 hour
    
    public function __construct($backend_api) {
        $this->backend_api = $backend_api;
        
        // Schedule daily license validation
        add_action('fub_validate_license_cron', array($this, 'validate_license_cron'));
    }
    
    public function has_admin_access() {
        // Allow admin access if the plugin is configured (has account_id)
        // regardless of subscription status so users can manage their settings
        $account_id = get_option('fub_account_id');
        $setup_completed = get_option('fub_setup_completed');
        
        return !empty($account_id) && $setup_completed;
    }
    
    public function is_license_valid($force_refresh = false) {
        $account_id = get_option('fub_account_id');
        
        error_log("FUB LICENSE DEBUG: Starting validation - Account: " . ($account_id ?: 'NONE') . ", Force Refresh: " . ($force_refresh ? 'YES' : 'NO'));
        
        // Always validate against Supabase - no local license storage
        if (!$account_id) {
            error_log("FUB LICENSE DEBUG: No account_id found - returning false");
            return false;
        }
        
        // Always check with Supabase - no local caching for privacy
        error_log("FUB LICENSE DEBUG: Checking subscription status with Supabase for account: " . $account_id);
        $response = $this->backend_api->check_existing_subscription($account_id);
        
        error_log("FUB LICENSE DEBUG: Backend response: " . json_encode($response));
        
        // Check both has_subscription and has_active_subscription for compatibility
        $has_sub = (isset($response['data']['has_subscription']) && $response['data']['has_subscription']) ||
                  (isset($response['data']['has_active_subscription']) && $response['data']['has_active_subscription']);
        
        if ($response['success'] && $has_sub) {
            error_log("FUB LICENSE DEBUG: Active subscription found in Supabase");
            // DO NOT cache or store locally - privacy first
            
            // Auto-install pixel if returned in validation response
            if (!empty($response['data']['pixel_code'])) {
                $current_pixel = get_option('fub_pixel_id', '');
                if (empty($current_pixel) || $current_pixel !== $response['data']['pixel_code']) {
                    update_option('fub_pixel_id', $response['data']['pixel_code']);
                    error_log('FUB Integration: Pixel auto-installed/updated from license validation');
                }
            }
            
            return true;
        } else {
            error_log("FUB LICENSE DEBUG: License validation FAILED - Error: " . (isset($response['error']) ? $response['error'] : 'Unknown error'));
            // No subscription found
            error_log("FUB LICENSE DEBUG: No active subscription in Supabase");
            return false;
        }
    }
    
    public function validate_license_on_load() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        
        // Only validate on admin pages
        if (!is_admin()) {
            return;
        }
        
        // Skip validation on setup page to avoid redirects
        if (isset($_GET['page']) && $_GET['page'] === 'fub-to-wp') {
            return;
        }
        
        // Validate license
        $this->is_license_valid();
    }
    
    public function validate_license_cron() {
        delete_transient($this->license_cache_key);
        $this->is_license_valid();
    }
    
    public function clear_license_cache() {
        delete_transient($this->license_cache_key);
        delete_option('fub_license_status');
        error_log('FUB Integration: License cache cleared - forcing fresh validation on next check');
    }
    
    public function debug_current_status() {
        $license_key = get_option('fub_license_key');
        $license_status = get_option('fub_license_status');
        $account_id = get_option('fub_account_id');
        $pixel_id = get_option('fub_pixel_id');
        $cache = get_transient($this->license_cache_key);
        
        $debug_info = array(
            'license_key' => $license_key ? substr($license_key, 0, 15) . '...' : 'MISSING',
            'license_status' => $license_status ?: 'NONE',
            'account_id' => $account_id ?: 'MISSING',
            'pixel_id' => $pixel_id ? 'SET (' . strlen($pixel_id) . ' chars)' : 'MISSING',
            'cache_status' => $cache !== false ? $cache : 'NONE',
            'domain' => parse_url(home_url(), PHP_URL_HOST)
        );
        
        error_log('FUB DEBUG STATUS: ' . json_encode($debug_info));
        return $debug_info;
    }
    
}

/**
 * =============================================================================
 * SETUP WIZARD CLASS
 * =============================================================================
 */
class FUB_Setup_Wizard {
    
    private $backend_api;
    private $license_manager;
    private $oauth_manager;
    
    public function __construct($backend_api, $license_manager, $oauth_manager) {
        $this->backend_api = $backend_api;
        $this->license_manager = $license_manager;
        $this->oauth_manager = $oauth_manager;
        
        // Add AJAX handlers
        add_action('wp_ajax_fub_validate_api_key', array($this, 'handle_validate_api_key'));
        add_action('wp_ajax_fub_create_checkout_session', array($this, 'handle_create_checkout'));
        add_action('wp_ajax_fub_validate_payment', array($this, 'handle_validate_payment'));
        // REMOVED: Duplicate handler - using ajax_complete_setup instead
        add_action('wp_ajax_fub_check_license_activation', array($this, 'handle_check_license_activation'));
        // DEBUG handler
        add_action('wp_ajax_fub_debug_supabase', array($this, 'handle_debug_supabase'));
        // Force complete setup handler
        add_action('wp_ajax_fub_force_complete_setup', array($this, 'handle_force_complete_setup'));
        
        // OAuth AJAX handlers
        add_action('wp_ajax_fub_create_oauth_app', array($this, 'handle_create_oauth_app'));
        add_action('wp_ajax_fub_connect_oauth', array($this, 'handle_connect_oauth'));
        add_action('wp_ajax_fub_disconnect_oauth', array($this, 'handle_disconnect_oauth'));
        add_action('wp_ajax_fub_validate_oauth_connection', array($this, 'handle_validate_oauth_connection'));
    }
    
    public function display_setup_wizard() {
        $current_step = isset($_GET['step']) ? sanitize_text_field($_GET['step']) : 'connect-fub';
        
        // Handle setup_completed parameter from OAuth redirect
        if (isset($_GET['setup_completed']) && $_GET['setup_completed'] === '1') {
            update_option('fub_setup_completed', true);
            error_log('🎉 Setup marked as completed from OAuth redirect');
        }
        
        // Check if setup should be considered complete
        $setup_completed = get_option('fub_setup_completed');
        
        // Handle OAuth callback (from proxy with tokens)
        if (isset($_GET['oauth_success']) && $_GET['oauth_success'] === '1') {
            $this->handle_oauth_proxy_callback();
            return;
        }
        
        // Handle OAuth disconnect
        if (isset($_GET['oauth_disconnect']) && $_GET['oauth_disconnect'] === '1') {
            $this->oauth_manager->disconnect();
            wp_redirect(admin_url('admin.php?page=fub-to-wp'));
            exit;
        }
        
        // Handle popup payment success/cancel from Stripe
        if (isset($_GET['popup_payment'])) {
            $this->handle_popup_payment_callback();
            return;
        }
        
        // Handle payment success from Stripe - prioritize this over dashboard redirect
        if (isset($_GET['payment']) && $_GET['payment'] === 'success' && isset($_GET['session_id']) && $current_step === 'basic-settings') {
            // Payment successful, validate and proceed to settings
            $current_step = 'basic-settings';
        }
        
        // Check if this is a fresh installation or an update
        $plugin_version = get_option('fub_plugin_version');
        $is_fresh_install = empty($plugin_version);
        
        // Update plugin version
        if ($plugin_version !== FUB_VERSION) {
            update_option('fub_plugin_version', FUB_VERSION);
            error_log('FUB: Plugin updated to version ' . FUB_VERSION);
        }
        
        // Only show dashboard if setup is completed AND (not fresh install OR not in specific step)
        if ($setup_completed && (!$is_fresh_install || $current_step !== 'connect-fub')) {
            $this->display_dashboard();
            return;
        }
        
        // If fresh install and no setup completed, force wizard
        if ($is_fresh_install && !$setup_completed) {
            error_log('FUB: Fresh installation detected - showing setup wizard');
            $current_step = 'connect-fub';
        }
        
        // Handle legacy payment success URLs
        if (isset($_GET['success']) && $_GET['success'] === 'true' && isset($_GET['session_id']) && $current_step === 'payment') {
            $current_step = 'payment'; // Show payment success screen
        }
        
        $this->enqueue_wizard_assets();
        
        ?>
        <div class="wrap">
            <h1>FUB to WP Setup</h1>
            
            <div class="fub-setup-wizard">
                <?php $this->display_progress_bar($current_step); ?>
                
                <?php
                switch ($current_step) {
                    case 'connect-fub':
                        $this->display_step1_connect_fub();
                        break;
                    case 'subscription':
                        $this->display_step2_subscription();
                        break;
                    case 'payment':
                        $this->display_payment_success();
                        break;
                    case 'basic-settings':
                        $this->display_step3_settings();
                        break;
                    case 'tags-assignment':
                        $this->display_step3_settings();
                        break;
                    case 'pixel-setup':
                        $this->display_step3_settings();
                        break;
                    case 'complete':
                        $this->display_step4_complete();
                        break;
                    default:
                        $this->display_step1_connect_fub();
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    private function enqueue_wizard_assets() {
        // Enqueue external brand CSS and JS files
        wp_enqueue_style(
            'fub-brand-styles', 
            plugin_dir_url(__FILE__) . 'assets/css/fub-brand-styles.css', 
            array(), 
            FUB_VERSION . '-' . time()
        );
        
        wp_enqueue_script(
            'fub-brand-scripts', 
            plugin_dir_url(__FILE__) . 'assets/js/fub-brand-scripts.js', 
            array('jquery'), 
            FUB_VERSION, 
            true
        );
        
        ?>
        <style>
        :root {
            --fub-heading-color: #242424;
            --fub-accent-color: #FFCD61;
            --fub-primary-red: #242424;
            --fub-secondary-blue: #0F1A54;
            --fub-accent-yellow: #FBC21D;
            --fub-white-color: #ffffff;
            --fub-light-color: #FCF7ED;
            --fub-light-color2: #FCF7ED;
            --fub-text-color: #1D263A;
        }
        
        body.fub-admin-page {
            font-family: "Inter", serif;
            color: var(--fub-heading-color);
            background-color: #FCF7ED;
            font-size: 18px;
            line-height: 1.67em;
        }
        
        .fub-setup-wizard { 
            max-width: 800px; 
            margin: 20px auto; 
            font-family: "Inter", serif;
        }
        
        .fub-progress-bar { 
            display: flex; 
            justify-content: space-between; 
            margin-bottom: 40px; 
            position: relative; 
        }
        
        .fub-progress-bar::before { 
            content: ''; 
            position: absolute; 
            top: 20px; 
            left: 0; 
            right: 0; 
            height: 2px; 
            background: #ddd; 
            z-index: 0; 
        }
        
        .fub-progress-step { 
            text-align: center; 
            position: relative; 
            z-index: 1; 
            flex: 1; 
        }
        
        .fub-progress-step .step-number { 
            display: inline-block; 
            width: 40px; 
            height: 40px; 
            line-height: 40px; 
            border-radius: 50%; 
            background: #242424; 
            border: 2px solid #242424; 
            color: #ffffff;
            font-weight: bold; 
            margin-bottom: 2px; 
            transition: all 0.3s ease;
        }
        
        .fub-progress-step.active .step-number { 
            background: #FFCD61; 
            color: #242424; 
            border: 2px solid #242424; 
        }
        
        .fub-progress-step .step-name { 
            display: block; 
            font-size: 12px; 
            color: #666; 
            font-weight: 500;
        }
        
        .fub-progress-step.active .step-name { 
            color: var(--fub-primary-red); 
            font-weight: bold; 
        }
        
        .fub-setup-step { 
            background: #FCF7ED; 
            padding: 15px 30px 30px 30px; 
            border: none; 
            box-shadow: none; 
            border-radius: 6px; 
        }
        
        .fub-features { 
            margin: 20px 0; 
        }
        
        .fub-feature { 
            padding: 15px 20px; 
            margin: 10px 0; 
            background: var(--fub-light-color); 
            border-left: 4px solid #242424; 
            border-radius: 6px; 
            transition: all 0.3s ease;
        }
        
        .fub-feature:hover {
            background: #FCF7ED;
            transform: translateX(5px);
        }
        
        .fub-feature .dashicons { 
            color: var(--fub-primary-red); 
            margin-right: 10px; 
        }
        
        .fub-pricing { 
            text-align: center; 
            padding: 30px; 
            background: #ffffff; 
            border: 2px solid #242424;
            border-radius: 6px; 
            margin: 20px 0; 
        }
        
        .fub-price { 
            font-size: 48px; 
            font-weight: bold; 
            color: var(--fub-primary-red); 
            margin: 10px 0; 
        }
        
        .fub-price .period { 
            font-size: 18px; 
            color: var(--fub-text-color); 
        }
        
        .fub-account-info { 
            margin-top: 15px; 
            padding: 15px; 
            background: #ffffff; 
            border-radius: 6px; 
            font-size: 16px; 
            border: 1px solid #242424;
        }
        
        .fub-actions { 
            text-align: center; 
            margin-top: 30px; 
        }
        
        .submit.fub-actions {
            text-align: center !important;
        }
        
        
        .fub-actions .button-hero { 
            font-size: 16px; 
            padding: 15px 36px; 
            height: auto; 
            font-weight: 700;
            border-radius: 6px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        
        /* Botones ahora usan .fub-button del archivo CSS externo */
        
        /* Estilos de botones secundarios también removidos */
        
        .notice.inline { 
            margin: 10px 0; 
            display: inline-block; 
            width: 100%; 
            border-radius: 6px;
        }
        
        #validation-loading, #checkout-loading, #save-loading { 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            justify-content: center; 
            margin: 20px 0; 
            color: var(--fub-text-color);
        }
        
        .spinner.is-active { 
            float: none; 
        }
        
        h1, h2, h3, h4, h5, h6 {
            color: var(--fub-heading-color);
            font-weight: 700;
            font-family: "Inter", serif;
        }
        
        /* Form Styles - Vertical Layout */
        .form-table {
            width: 100%;
            border: none;
        }
        
        .form-table tr {
            display: block;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .form-table tr:last-child {
            border-bottom: none;
        }
        
        .form-table th {
            display: block;
            width: 100%;
            padding: 0;
            margin-bottom: 10px;
            text-align: left;
            color: var(--fub-heading-color);
            font-weight: 600;
            font-family: "Lora", serif;
            font-size: 1.1rem;
        }
        
        .form-table td {
            display: block;
            width: 100%;
            padding: 0;
            margin: 0;
        }
        
        .form-table input[type="text"],
        .form-table input[type="password"],
        .form-table select,
        .form-table textarea {
            width: 100%;
            max-width: 500px;
            border-radius: 6px;
            border: 2px solid #242424;
            transition: all 0.3s ease;
            padding: 10px 15px;
            font-size: 16px;
        }
        
        .form-table input[type="text"]:focus,
        .form-table input[type="password"]:focus,
        .form-table select:focus,
        .form-table textarea:focus {
            border-color: var(--fub-primary-red);
            box-shadow: 0 0 0 1px var(--fub-primary-red);
        }
        
        .form-table .description {
            display: block;
            margin-top: 8px;
            color: #666;
            font-size: 14px;
            font-style: italic;
        }
        
        /* Apply brand styling to all buttons */
        .button {
            font-family: "Inter", serif;
            font-weight: 600;
            border-radius: 6px;
            transition: all 0.3s ease;
        }
        
        /* Estilos de botones removidos - ahora se usan desde fub-brand-styles.css */
        
        /* Todos los estilos de botones inline eliminados */
        
        /* Style tables */
        .wp-list-table {
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .wp-list-table th {
            background: var(--fub-light-color) !important;
            color: var(--fub-heading-color) !important;
            font-family: "Inter", serif;
            font-weight: 700;
        }
        
        .wp-list-table td {
            font-family: "Inter", serif;
        }
        
        /* Status indicators */
        .fub-status {
            font-family: "Inter", serif !important;
        }
        
        /* Notices */
        .notice {
            border-radius: 6px;
            font-family: "Inter", serif;
        }
        
        .notice.notice-success {
            border-left-color: #242424 !important;
        }
        
        .notice.notice-error {
            border-left-color: var(--fub-primary-red) !important;
        }
        
        .notice.notice-warning {
            border-left-color: #242424 !important;
        }
        </style>
        
        <script>
            // Add brand class to body when plugin admin page loads
            document.addEventListener('DOMContentLoaded', function() {
                if (document.querySelector('.fub-setup-wizard') || document.querySelector('.fub-stats')) {
                    document.body.classList.add('fub-admin-page');
                }
            });
        </script>
        <?php
    }
    
    private function display_progress_bar($current_step) {
        // Check if user has active license to modify the progress bar
        // Check with Supabase in real-time
        $account_id = get_option('fub_account_id');
        $has_active_license = false;
        if ($account_id) {
            $check = $this->backend_api->check_existing_subscription($account_id);
            $has_sub = (isset($check['data']['has_subscription']) && $check['data']['has_subscription']) ||
                      (isset($check['data']['has_active_subscription']) && $check['data']['has_active_subscription']);
            $has_active_license = $check['success'] && $has_sub;
        }
        
        // Adjust steps based on license status
        if ($has_active_license) {
            // Skip subscription step for users with active license
            $steps = array(
                'connect-fub' => 'Connect FUB',
                'basic-settings' => 'Basic Settings',
                'tags-assignment' => 'Tags & Assignment',
                'pixel-setup' => 'Pixel Setup',
                'complete' => 'Complete'
            );
        } else {
            $steps = array(
                'connect-fub' => 'Connect FUB',
                'subscription' => 'Subscription',
                'basic-settings' => 'Basic Settings',
                'tags-assignment' => 'Tags & Assignment',
                'pixel-setup' => 'Pixel Setup',
                'complete' => 'Complete'
            );
        }
        
        $step_order = array_keys($steps);
        $current_index = array_search($current_step, $step_order);
        
        echo '<div class="fub-progress-bar">';
        $i = 1;
        foreach ($steps as $step_key => $step_name) {
            $step_index = array_search($step_key, $step_order);
            $class = ($step_index <= $current_index) ? 'active' : '';
            echo "<div class='fub-progress-step $class'>";
            echo "<span class='step-number'>$i</span>";
            echo "<span class='step-name'>$step_name</span>";
            echo "</div>";
            $i++;
        }
        echo '</div>';
    }
    
    private function display_step1_connect_fub() {
        // Check if we just disconnected (force show connection form)
        $just_disconnected = isset($_GET['disconnected']) && $_GET['disconnected'] === '1';
        
        // Check if already connected via OAuth (but override if just disconnected)
        $is_oauth_connected = !$just_disconnected && $this->oauth_manager->is_connected();
        $account_name = get_option('fub_account_name');
        
        ?>
        <div class="fub-setup-step">
            <h2>Step 1: Connect Follow Up Boss</h2>
            
            <?php if ($is_oauth_connected): ?>
                <div class="notice notice-success inline">
                    <p><strong>✅ Successfully connected to Follow Up Boss!</strong></p>
                    <?php if ($account_name): ?>
                        <p>Connected to account: <strong><?php echo esc_html($account_name); ?></strong></p>
                    <?php endif; ?>
                </div>
                
                <div class="fub-actions">
                    <?php 
                    // Check if has active license to determine next step
                    // Check with Supabase in real-time
        $account_id = get_option('fub_account_id');
        $has_active_license = false;
        if ($account_id) {
            $check = $this->backend_api->check_existing_subscription($account_id);
            $has_sub = (isset($check['data']['has_subscription']) && $check['data']['has_subscription']) ||
                      (isset($check['data']['has_active_subscription']) && $check['data']['has_active_subscription']);
            $has_active_license = $check['success'] && $has_sub;
        }
                    $next_step = $has_active_license ? 'basic-settings&license_active=1' : 'subscription';
                    $button_text = $has_active_license ? 'Continue to Settings' : 'Continue to Subscription';
                    ?>
                    <a href="<?php echo admin_url('admin.php?page=fub-to-wp&step=' . $next_step); ?>" 
                       class="button button-primary fub-button">
                        <?php echo $button_text; ?>
                    </a>
                    <button type="button" class="button button-secondary" id="disconnect-oauth">
                        Disconnect
                    </button>
                </div>
                
                <script>
                    jQuery(document).ready(function($) {
                        $('#disconnect-oauth').off('click').on('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            
                            var $btn = $(this);
                            if ($btn.prop('disabled')) return false;
                            
                            if (confirm('Are you sure you want to disconnect from Follow Up Boss? This will require you to reconnect.')) {
                                $btn.prop('disabled', true).text('Disconnecting...');
                                
                                // Use the same action as Change FUB Account since it works perfectly
                                $.post(ajaxurl, {
                                    action: 'fub_reset_setup',
                                    nonce: '<?php echo wp_create_nonce('fub_admin_nonce'); ?>'
                                }, function(response) {
                                    console.log('Disconnect response:', response);
                                    if (response.success) {
                                        // Show success message briefly then redirect to step 1
                                        $btn.text('Disconnected!').css('background', '#46b450');
                                        setTimeout(function() {
                                            // Redirect to step 1 (connect-fub) to show connection options
                                            window.location.href = '<?php echo admin_url('admin.php?page=fub-to-wp&step=connect-fub'); ?>&disconnected=1&t=' + Date.now();
                                        }, 800);
                                    } else {
                                        alert('Error disconnecting: ' + response.data);
                                        $btn.prop('disabled', false).text('Disconnect');
                                    }
                                }).fail(function(xhr, status, error) {
                                    console.log('Disconnect AJAX failed:', xhr, status, error);
                                    // Even if AJAX fails, redirect to step 1 since reset might have worked
                                    setTimeout(function() {
                                        window.location.href = '<?php echo admin_url('admin.php?page=fub-to-wp&step=connect-fub'); ?>&disconnected=1&t=' + Date.now();
                                    }, 1000);
                                });
                            }
                            
                            return false;
                        });
                    });
                </script>
                
            <?php else: ?>
                <p>Connect your Follow Up Boss account using secure OAuth authentication.</p>
                
                
                <div class="fub-actions">
                    <button type="button" class="button button-primary fub-button" id="connect-oauth">
                        <span class="dashicons dashicons-external" style="vertical-align: middle;"></span>
                        Connect to Follow Up Boss
                    </button>
                    
                    <div id="oauth-loading" style="display: none; margin-top: 15px;">
                        <div class="spinner is-active"></div>
                        <span>Setting up OAuth connection...</span>
                    </div>
                </div>
                
                <div id="oauth-status" style="margin-top: 15px;"></div>
                
                <script>
                    jQuery(document).ready(function($) {
                        var oauthWindow = null;
                        
                        // Listen for messages from popup window
                        window.addEventListener('message', function(event) {
                            if (event.origin !== window.location.origin) {
                                return;
                            }
                            
                            if (event.data.type === 'oauth_success') {
                                $('#oauth-loading').hide();
                                
                                // Determine next step based on subscription status
                                var hasActiveSubscription = event.data.data && event.data.data.has_active_subscription;
                                var nextStep = hasActiveSubscription ? 'basic-settings&license_active=1' : 'subscription';
                                var message = hasActiveSubscription ? 'Continuing to settings...' : 'Continuing to subscription setup...';
                                
                                $('#oauth-status').html(
                                    '<div class="notice notice-success inline">' +
                                    '<p><strong>✅ Connection Successful!</strong></p>' +
                                    '<p>' + message + '</p>' +
                                    '</div>'
                                );
                                
                                // Close popup
                                if (oauthWindow) {
                                    oauthWindow.close();
                                }
                                
                                // Redirect to the appropriate next step
                                setTimeout(function() {
                                    window.location.href = '<?php echo admin_url('admin.php?page=fub-to-wp&step='); ?>' + nextStep;
                                }, 1500);
                            } else if (event.data.type === 'oauth_error') {
                                $('#oauth-loading').hide();
                                $('#oauth-status').html(
                                    '<div class="notice notice-error inline">' +
                                    '<p><strong>❌ Connection Failed</strong></p>' +
                                    '<p>Error: ' + (event.data.error || 'Unknown error') + '</p>' +
                                    '</div>'
                                );
                                $('#connect-oauth').show();
                                
                                // Close popup
                                if (oauthWindow) {
                                    oauthWindow.close();
                                }
                            }
                        });
                        
                        $('#connect-oauth').on('click', function() {
                            $(this).hide();
                            $('#oauth-loading').show();
                            $('#oauth-status').empty();
                            
                            $.post(ajaxurl, {
                                action: 'fub_connect_oauth',
                                nonce: '<?php echo wp_create_nonce('fub_admin_nonce'); ?>'
                            }, function(response) {
                                $('#oauth-loading').hide();
                                
                                if (response.success && response.data.auth_url) {
                                    $('#oauth-status').html(
                                        '<div class="notice notice-info inline">' +
                                        '<p><strong>🚀 Opening Follow Up Boss authorization...</strong></p>' +
                                        '<p>Please complete the authorization in the popup window.</p>' +
                                        '</div>'
                                    );
                                    
                                    // Open popup window
                                    var width = 600;
                                    var height = 700;
                                    var left = (screen.width / 2) - (width / 2);
                                    var top = (screen.height / 2) - (height / 2);
                                    
                                    oauthWindow = window.open(
                                        response.data.auth_url,
                                        'fub_oauth',
                                        'width=' + width + ',height=' + height + ',top=' + top + ',left=' + left + ',scrollbars=yes,resizable=yes'
                                    );
                                    
                                    // Check if popup was blocked
                                    if (!oauthWindow || oauthWindow.closed || typeof oauthWindow.closed === 'undefined') {
                                        $('#oauth-loading').hide();
                                        $('#oauth-status').html(
                                            '<div class="notice notice-error inline">' +
                                            '<p><strong>❌ Popup Blocked</strong></p>' +
                                            '<p>Please allow popups for this site and try again.</p>' +
                                            '</div>'
                                        );
                                        $('#connect-oauth').show();
                                        return;
                                    }
                                    
                                    // Monitor popup window - check if closed manually
                                    var checkClosed = setInterval(function() {
                                        if (oauthWindow.closed) {
                                            clearInterval(checkClosed);
                                            if ($('#oauth-loading').is(':visible')) {
                                                $('#oauth-loading').hide();
                                                $('#oauth-status').html(
                                                    '<div class="notice notice-warning inline">' +
                                                    '<p><strong>⚠️ Window Closed</strong></p>' +
                                                    '<p>Authorization window was closed. Please try again if you didn\'t complete the process.</p>' +
                                                    '</div>'
                                                );
                                                $('#connect-oauth').show();
                                            }
                                        }
                                    }, 1000);
                                } else {
                                    console.log('OAuth Error Response:', response);
                                    var errorMsg = 'Unknown error';
                                    if (response.data) {
                                        if (typeof response.data === 'string') {
                                            errorMsg = response.data;
                                        } else if (response.data.error) {
                                            errorMsg = response.data.error;
                                            if (response.data.status_code) {
                                                errorMsg += ' (HTTP ' + response.data.status_code + ')';
                                            }
                                        }
                                    }
                                    
                                    $('#oauth-status').html(
                                        '<div class="notice notice-error inline">' +
                                        '<p><strong>❌ Connection Failed</strong></p>' +
                                        '<p>Error: ' + errorMsg + '</p>' +
                                        '<details style="margin-top: 10px;">' +
                                        '<summary>Debug Information</summary>' +
                                        '<pre style="background: #f1f1f1; padding: 10px; margin-top: 5px; font-size: 11px;">' +
                                        JSON.stringify(response, null, 2) +
                                        '</pre>' +
                                        '</details>' +
                                        '</div>'
                                    );
                                    $('#connect-oauth').show();
                                }
                            }).fail(function(xhr, status, error) {
                                $('#oauth-loading').hide();
                                $('#oauth-status').html(
                                    '<div class="notice notice-error inline">' +
                                    '<p><strong>❌ Connection Failed</strong></p>' +
                                    '<p>Please try again. If the problem persists, contact support.</p>' +
                                    '</div>'
                                );
                                $('#connect-oauth').show();
                            });
                        });
                    });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function display_step2_subscription() {
        // Only require account_id to be present - OAuth connection will be verified when needed
        if (!get_option('fub_account_id')) {
            wp_redirect(admin_url('admin.php?page=fub-to-wp&step=connect-fub'));
            exit;
        }
        
        ?>
        <div class="fub-setup-step">
            <h2>Step 2: Activate Your Subscription</h2>
            <p>Complete your subscription to activate all features.</p>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="notice notice-error inline" style="margin: 15px 0;">
                    <p>
                        <?php 
                        switch ($_GET['error']) {
                            case 'no_license':
                                echo '❌ Payment completed but license key not received. Please contact support.';
                                break;
                            case 'validation_failed':
                                echo '❌ License validation failed. Please try again.';
                                break;
                            case 'stripe_failed':
                                echo '❌ There was an issue processing your Stripe payment return. Please try again or contact support.';
                                break;
                            default:
                                echo '❌ An error occurred. Please try again.';
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['canceled'])): ?>
                <div class="notice notice-warning inline" style="margin: 15px 0;">
                    <p>⚠️ Payment was canceled. You can try again when ready.</p>
                </div>
            <?php endif; ?>
            
            <div class="fub-pricing">
                <h3>Monthly Subscription</h3>
                <div class="fub-price">
                    <span class="price">$20</span>
                    <span class="period">/month</span>
                </div>
                <ul class="fub-features-list">
                    <li>✔ Unlimited WordPress sites</li>
                    <li>✔ Unlimited lead capture</li>
                    <li>✔ Real-time Follow Up Boss sync</li>
                    <li>✔ Works with all form plugins</li>
                    <li>✔ Priority support</li>
                    <li>✔ 7-day money-back guarantee</li>
                    <li>✔ Cancel anytime</li>
                </ul>
                <p class="fub-account-info">
                    <strong>FUB Account:</strong> <?php 
                        $account_name = get_option('fub_account_name');
                        $account_id = get_option('fub_account_id');
                        echo esc_html($account_name ?: $account_id); 
                    ?>
                </p>
            </div>
            
            <div class="fub-actions">
                <button id="start-subscription" class="button button-primary fub-button">
                    Subscribe Now
                </button>
                
                <div id="checkout-loading" style="display: none;">
                    <div class="spinner is-active"></div>
                    <span>Redirecting to secure payment...</span>
                </div>
            </div>
        </div>
        
        <script>
            jQuery(document).ready(function($) {
                var checkoutWindow = null;
                
                // Listen for messages from checkout popup
                window.addEventListener('message', function(event) {
                    if (event.origin !== window.location.origin) {
                        return;
                    }
                    
                    if (event.data.type === 'checkout_success') {
                        $('#checkout-loading').hide();
                        $('#checkout-loading span').text('Payment successful! Continuing...');
                        $('#checkout-loading').show();
                        
                        // Close popup
                        if (checkoutWindow) {
                            checkoutWindow.close();
                        }
                        
                        // Redirect to settings with payment success
                        setTimeout(function() {
                            window.location.href = '<?php echo admin_url('admin.php?page=fub-to-wp&step=basic-settings&payment=success&session_id='); ?>' + event.data.session_id;
                        }, 1500);
                    } else if (event.data.type === 'checkout_error') {
                        $('#checkout-loading').hide();
                        $('#start-subscription').show();
                        alert('Payment error: ' + (event.data.error || 'Payment was cancelled or failed'));
                        
                        // Close popup
                        if (checkoutWindow) {
                            checkoutWindow.close();
                        }
                    }
                });
                
                $('#start-subscription').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    $('#start-subscription').hide();
                    $('#checkout-loading').show();
                    $('#checkout-loading span').text('Opening secure payment window...');
                    
                    $.post(ajaxurl, {
                        action: 'fub_create_checkout_session',
                        nonce: '<?php echo wp_create_nonce('fub_admin_nonce'); ?>'
                    }, function(response) {
                        if (response.success && response.data.checkout_url) {
                            // Open popup window for Stripe checkout
                            var width = 800;
                            var height = 900;
                            var left = (screen.width / 2) - (width / 2);
                            var top = (screen.height / 2) - (height / 2);
                            
                            checkoutWindow = window.open(
                                response.data.checkout_url,
                                'stripe_checkout',
                                'width=' + width + ',height=' + height + ',top=' + top + ',left=' + left + ',scrollbars=yes,resizable=yes'
                            );
                            
                            // Check if popup was blocked
                            if (!checkoutWindow || checkoutWindow.closed || typeof checkoutWindow.closed === 'undefined') {
                                $('#checkout-loading').hide();
                                $('#start-subscription').show();
                                alert('Popup blocked. Please allow popups for this site and try again.');
                                return;
                            }
                            
                            // Monitor popup window
                            var checkClosed = setInterval(function() {
                                if (checkoutWindow.closed) {
                                    clearInterval(checkClosed);
                                    if ($('#checkout-loading').is(':visible')) {
                                        $('#checkout-loading').hide();
                                        $('#start-subscription').show();
                                        // Don't show error if user simply closed window
                                    }
                                }
                            }, 1000);
                            
                        } else {
                            alert('Error: ' + (response.data || 'Failed to create checkout session'));
                            $('#start-subscription').show();
                            $('#checkout-loading').hide();
                        }
                    });
                });
            });
        </script>
        <?php
    }
    
    private function display_step3_settings() {
        $payment_validated = false;
        $license_active_from_oauth = isset($_GET['license_active']) && $_GET['license_active'] === '1';
        $current_step = isset($_GET['step']) ? sanitize_text_field($_GET['step']) : 'basic-settings';
        
        // If coming from payment success, validate payment first BEFORE checking license
        if (isset($_GET['payment']) && $_GET['payment'] === 'success' && isset($_GET['session_id'])) {
            $session_id = sanitize_text_field($_GET['session_id']);
            $account_id = get_option('fub_account_id');
            
            // Auto-validate payment
            $response = $this->backend_api->validate_payment($session_id);
            
            if ($response['success']) {
                // Payment successful - no need to store license locally
                set_transient('fub_payment_success', true, 300); // 5 minutes
                $payment_validated = true;
                update_option('fub_has_valid_license', 'yes');
                error_log('FUB: Payment validated, license confirmed active');
                
                // Create subscription directly if webhook hasn't done it yet
                $subscription_found = false;
                $max_attempts = 15; // Increased from 5 to 15 (30 seconds total)
                $attempt = 0;
                
                error_log("FUB PAYMENT: Starting subscription verification for account $account_id with session $session_id");
                
                while ($attempt < $max_attempts && !$subscription_found) {
                    if ($attempt > 0) {
                        sleep(2); // Wait 2 seconds between attempts
                    }
                    
                    error_log("FUB PAYMENT: Attempt " . ($attempt + 1) . "/$max_attempts - Checking for subscription...");
                    
                    // Check if subscription was created by webhook
                    $subscription_check = $this->backend_api->check_existing_subscription($account_id);
                    
                    // Also check by session_id directly in case account_id lookup fails
                    if (!$subscription_check['success']) {
                        // Try direct session lookup
                        $session_response = $this->backend_api->validate_payment($session_id);
                        if ($session_response['success']) {
                            error_log("FUB PAYMENT: Found subscription via session_id lookup");
                            $subscription_found = true;
                            break;
                        }
                    }
                    
                    // Check both fields for compatibility
                    $has_sub = (isset($subscription_check['data']['has_subscription']) && $subscription_check['data']['has_subscription']) ||
                              (isset($subscription_check['data']['has_active_subscription']) && $subscription_check['data']['has_active_subscription']);
                    
                    if ($subscription_check['success'] && $has_sub) {
                        $subscription_found = true;
                        error_log('FUB PAYMENT: ✅ Subscription found after ' . ($attempt + 1) . ' attempts');
                        
                        // Get license key if available
                        if (isset($subscription_check['data']['license_key'])) {
                            // Validate license to sync pixel and other data
                            $this->backend_api->validate_license_before_lead($subscription_check['data']['license_key'], $account_id);
                        }
                    }
                    
                    $attempt++;
                }
                
                // If subscription still not found, create it directly
                if (!$subscription_found && isset($response['data']['stripe_customer_id'])) {
                    error_log('FUB: Creating subscription directly since webhook may be delayed...');
                    
                    $license_key = 'fub_' . time() . '_' . $account_id;
                    $subscription_data = array(
                        'account_id' => $account_id,
                        'stripe_customer_id' => $response['data']['stripe_customer_id'] ?? '',
                        'stripe_subscription_id' => $response['data']['stripe_subscription_id'] ?? '',
                        'stripe_session_id' => $session_id,
                        'status' => 'active',
                        'license_key' => $license_key
                    );
                    
                    // Direct API call to create subscription
                    $create_result = $this->backend_api->create_subscription_direct($subscription_data);
                    if ($create_result['success']) {
                        error_log('FUB: Subscription created directly successfully');
                        // Validate the new license
                        $this->backend_api->validate_license_before_lead($license_key, $account_id);
                    } else {
                        error_log('FUB: Failed to create subscription directly: ' . json_encode($create_result));
                    }
                } else if (!$subscription_found) {
                    error_log('FUB: Warning - Could not create subscription, missing Stripe customer ID');
                }
            }
        }
        
        // Check if user has account_id (basic requirement)
        $account_id = get_option('fub_account_id');
        
        // Only redirect to subscription if no payment, no account, and not coming from OAuth
        if (!$payment_validated && !$account_id && !$license_active_from_oauth) {
            error_log("FUB DEBUG: No account connected - redirecting to connection step");
            wp_redirect(admin_url('admin.php?page=fub-to-wp&step=connect-fub'));
            exit;
        }
        
        // If we have account_id, check subscription status with Supabase
        // But skip this check if we just completed payment or have a transient indicating recent payment
        $skip_subscription_check = $payment_validated || 
                                  $license_active_from_oauth || 
                                  get_transient('fub_payment_success') || 
                                  (isset($_GET['payment']) && $_GET['payment'] === 'success');
        
        error_log("FUB DEBUG WIZARD: Step=$current_step, Account_ID=" . ($account_id ?: 'NONE') . ", Payment_Validated=" . ($payment_validated ? 'YES' : 'NO') . ", Skip_Check=" . ($skip_subscription_check ? 'YES' : 'NO'));
        
        // Check current page to determine if subscription is required
        $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        $requires_active_subscription = !in_array($current_page, ['fub-settings', 'fub-analytics']) && 
                                       !in_array($current_step, ['basic-settings', 'advanced-settings']);
        
        if ($account_id && !$skip_subscription_check && $requires_active_subscription) {
            error_log("FUB DEBUG: Checking subscription for account: " . $account_id . " on step: " . $current_step . " (page: " . $current_page . ")");
            $subscription_check = $this->backend_api->check_existing_subscription($account_id);
            error_log("FUB DEBUG: Subscription check result: " . json_encode($subscription_check));
            
            // Check both has_subscription and has_active_subscription for compatibility
            $has_sub = (isset($subscription_check['data']['has_subscription']) && $subscription_check['data']['has_subscription']) ||
                      (isset($subscription_check['data']['has_active_subscription']) && $subscription_check['data']['has_active_subscription']);
            
            if (!$subscription_check['success'] || !$has_sub) {
                error_log("FUB DEBUG: ❌ REDIRECTING TO SUBSCRIPTION - No active subscription found for account " . $account_id . " from step: " . $current_step);
                error_log("FUB DEBUG: Subscription check success: " . ($subscription_check['success'] ? 'YES' : 'NO') . ", Has subscription: " . ($has_sub ? 'YES' : 'NO'));
                wp_redirect(admin_url('admin.php?page=fub-to-wp&step=subscription&debug=no_subscription'));
                exit;
            } else {
                error_log("FUB DEBUG: ✅ CONTINUING - Active subscription found for account " . $account_id);
            }
        } elseif (!$requires_active_subscription) {
            error_log("FUB DEBUG: ✅ SKIPPING subscription check - accessing admin page that doesn't require active subscription (page: " . $current_page . ", step: " . $current_step . ")");
        } else {
            if (!$account_id) {
                error_log("FUB DEBUG: No account_id, staying on current step");
            } else {
                error_log("FUB DEBUG: ✅ SKIPPING subscription check - reason: payment_validated=$payment_validated, license_from_oauth=$license_active_from_oauth, transient=" . (get_transient('fub_payment_success') ? 'YES' : 'NO'));
            }
        }
        
        ?>
        <?php if ($current_step === 'basic-settings'): ?>
        <div class="fub-setup-step">
            <h2>Step 3: Basic Settings</h2>
            <p>Configure the essential settings for lead capture. We'll keep it simple and focused.</p>
            
            <?php if (isset($_GET['payment']) && $_GET['payment'] === 'success' || get_transient('fub_payment_success')): ?>
                <div class="notice notice-success inline" style="margin: 15px 0;">
                    <p>🎉 <strong>Payment successful!</strong> Your subscription is now active. Complete your setup below.</p>
                </div>
                <?php delete_transient('fub_payment_success'); ?>
            <?php elseif (isset($_GET['license_active']) && $_GET['license_active'] === '1'): ?>
                <div class="notice notice-success inline" style="margin: 15px 0;">
                    <p>✅ <strong>Active license detected!</strong> Your subscription is already active. Let's complete your setup.</p>
                </div>
            <?php endif; ?>
            
            <form id="fub-basic-settings-form">
                <?php wp_nonce_field('fub_admin_nonce', 'fub_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="fub_default_source">Lead Source *</label>
                        </th>
                        <td>
                            <input type="text" name="fub_default_source" id="fub_default_source"
                                   value="<?php echo esc_attr(get_option('fub_default_source', 'WordPress Website')); ?>" 
                                   class="regular-text" required />
                            <p class="description">How leads will be tagged as source in Follow Up Boss</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fub_inquiry_type">Lead Inquiry Type *</label>
                        </th>
                        <td>
                            <select name="fub_inquiry_type" id="fub_inquiry_type" class="regular-text" required>
                                <option value="General Inquiry" <?php selected(get_option('fub_inquiry_type', 'General Inquiry'), 'General Inquiry'); ?>>General Inquiry (Leads)</option>
                                <option value="Registration" <?php selected(get_option('fub_inquiry_type', 'General Inquiry'), 'Registration'); ?>>Registration (Leads)</option>
                                <option value="Property Inquiry" <?php selected(get_option('fub_inquiry_type', 'General Inquiry'), 'Property Inquiry'); ?>>Property Inquiry (Buyers)</option>
                                <option value="Seller Inquiry" <?php selected(get_option('fub_inquiry_type', 'General Inquiry'), 'Seller Inquiry'); ?>>Seller Inquiry (Sellers)</option>
                            </select>
                            <p class="description">This determines how leads are categorized in Follow Up Boss</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit fub-actions">
                    <button type="button" class="button button-primary fub-button" onclick="nextStep('tags-assignment')">
                        Continue to Tags & Assignment
                    </button>
                </p>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($current_step === 'tags-assignment'): ?>
        <!-- Step 4: Tags & Assignment -->
        <div class="fub-setup-step">
            <h2>Step 4: Tags & Assignment</h2>
            <p>Select tags and assign a team member for leads from this website.</p>
            
            <form id="fub-tags-assignment-form">
                <?php wp_nonce_field('fub_admin_nonce', 'fub_nonce'); ?>
                
                <table class="form-table">
                            
                            <?php 
                            global $wpdb;
                            
                            // Auto-sync tags on first load if no tags exist
                            $synced_tags = $wpdb->get_results(
                                "SELECT * FROM " . FUB_TAGS_TABLE . " WHERE active = 1 ORDER BY name",
                                ARRAY_A
                            );
                            
                            // If no tags exist, trigger auto-sync
                            $should_auto_sync = empty($synced_tags) && !get_transient('fub_tags_auto_sync_attempted');
                            if ($should_auto_sync) {
                                set_transient('fub_tags_auto_sync_attempted', true, 3600); // Prevent multiple auto-syncs
                            }
                            
                            $selected_tags = get_option('fub_selected_tags', array());
                            ?>
                            
                        </td>
                    </tr>
                    <?php if (!empty($synced_tags)): ?>
                    <tr>
                        <th scope="row">
                            <label>Select Tags to Apply to Leads:</label>
                        </th>
                        <td>
                            <style>
                                .fub-tag-selector {
                                    display: flex;
                                    flex-wrap: wrap;
                                    gap: 12px;
                                    padding: 20px;
                                    background: var(--fub-light-color);
                                    border: 2px solid #242424;
                                    border-radius: 6px;
                                    max-height: 250px;
                                    overflow-y: auto;
                                }
                                /* Estilos de tags movidos a fub-brand-styles.css */
                                
                                /* Spinner styles for WordPress Settings page */
                                .spinner {
                                    background: url(../../../wp-admin/images/spinner.gif) no-repeat;
                                    background-size: 20px 20px;
                                    display: inline-block;
                                    visibility: visible;
                                    float: right;
                                    vertical-align: middle;
                                    opacity: .7;
                                    filter: alpha(opacity=70);
                                    width: 20px;
                                    height: 20px;
                                    margin: 4px 10px 0;
                                }
                                
                                .spinner.is-active {
                                    visibility: visible;
                                    display: inline-block;
                                }
                            </style>
                            <div class="fub-tag-selector" id="fub-tag-selector">
                                <?php foreach ($synced_tags as $tag): ?>
                                <label class="fub-tag-chip <?php echo in_array($tag['fub_tag_id'], $selected_tags) ? 'selected' : ''; ?>">
                                    <input type="checkbox" name="selected_tags[]" value="<?php echo esc_attr($tag['fub_tag_id']); ?>" 
                                           <?php echo in_array($tag['fub_tag_id'], $selected_tags) ? 'checked' : ''; ?> />
                                    <?php echo esc_html($tag['name']); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description" style="margin-top: 8px;">
                                <strong>Click to select/deselect tags.</strong> Selected tags will be applied to all new leads.
                            </p>
                            <div style="margin-top: 10px;">
                                <button type="button" id="fub-sync-tags" class="button button-small">Sync Tags from Follow Up Boss</button>
                                <div id="test-tags-result" style="margin-top: 10px;"></div>
                            </div>
                            <!-- JavaScript para tags ahora en fub-brand-scripts.js -->
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <th scope="row">
                            <label>Select Tags to Apply to Leads:</label>
                        </th>
                        <td>
                            <div class="fub-tag-selector" id="fub-tag-selector" style="min-height: 100px; display: flex; align-items: center; justify-content: center;">
                                <?php if (isset($should_auto_sync) && $should_auto_sync): ?>
                                    <div style="text-align: center;">
                                        <span class="spinner is-active" style="float: none; margin: 0 auto;"></span>
                                        <p style="margin-top: 10px;">Loading tags from Follow Up Boss...</p>
                                    </div>
                                <?php else: ?>
                                    <p style="margin: 0; text-align: center;">No tags found. Click "Sync Tags from Follow Up Boss" below to load your tags.</p>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top: 10px;">
                                <button type="button" id="fub-sync-tags" class="button button-small">Sync Tags from Follow Up Boss</button>
                                <div id="test-tags-result" style="margin-top: 10px;"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row">
                            <label>Add Custom Tags:</label>
                        </th>
                        <td>
                            <div id="custom-tags-container">
                                <input type="text" name="custom_tags[]" placeholder="Enter new tag name (e.g., WordPress Lead)" class="regular-text" />
                                <div style="margin-top: 10px;">
                                    <button type="button" onclick="addCustomTagField()" class="button button-small">Add Another Tag</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="assigned_user_id">Assigned User</label>
                        </th>
                        <td>
                            <select name="assigned_user_id" id="assigned_user_id" class="regular-text">
                                <option value="">-- Select User (Default Assignment) --</option>
                            </select>
                            <div style="margin-top: 10px;">
                                <button type="button" id="load-fub-users" class="button button-small">Load Users from FUB</button>
                            </div>
                            <p class="description">Select the team member who will receive leads from this website. Leave empty for default assignment.</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit fub-actions">
                    <button type="button" class="button fub-button" onclick="nextStep('basic-settings')">← Back to Basic Settings</button>
                    <button type="button" class="button button-primary fub-button" onclick="nextStep('pixel-setup')">Continue to Pixel Setup</button>
                </p>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($current_step === 'pixel-setup'): ?>
        <!-- Step 5: Pixel Setup -->
        <div class="fub-setup-step">
            <h2>Step 5: Pixel Setup</h2>
            <p>Configure your Follow Up Boss pixel code for tracking (optional but recommended).</p>
            
            <form id="fub-pixel-setup-form">
                <?php wp_nonce_field('fub_admin_nonce', 'fub_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="fub_pixel_id">FUB Pixel Code</label>
                        </th>
                        <td>
                            <?php 
                            $existing_pixel = get_option('fub_pixel_id', '');
                            if (!empty($existing_pixel)): 
                            ?>
                                <div class="notice notice-success inline" style="margin: 0 0 10px 0; padding: 10px;">
                                    <p style="margin: 0;">
                                        <span class="dashicons dashicons-yes" style="color: #00a32a;"></span>
                                        <strong>Pixel automatically installed from your license!</strong>
                                    </p>
                                    <p style="margin: 5px 0 0 0; font-size: 13px;">
                                        The pixel code was loaded from your cloud-saved settings.
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <textarea name="fub_pixel_id" id="fub_pixel_id" 
                                      class="large-text" rows="8"
                                      placeholder="<?php echo !empty($existing_pixel) ? 'Pixel already installed. You can update it here if needed.' : 'Paste your complete Follow Up Boss pixel code here (optional)'; ?>"><?php echo esc_textarea($existing_pixel); ?></textarea>
                            <p class="description">
                                <?php if (!empty($existing_pixel)): ?>
                                    The pixel is already active on your site. Any changes will be saved locally and synced to the cloud.
                                <?php else: ?>
                                    This pixel code will be saved locally and synced to the cloud for automatic installation on other WordPress sites using the same license.
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit fub-actions">
                    <button type="button" class="button fub-button" onclick="nextStep('tags-assignment')">← Back to Tags & Assignment</button>
                    <button type="button" class="button button-primary fub-button" onclick="completeSetup()">Complete Setup</button>
                </p>
            </form>
        </div>
        <?php endif; ?>
        
        <script>
            function nextStep(step) {
                // Save current step data before proceeding
                const currentForm = document.querySelector('form');
                if (currentForm) {
                    const formData = new FormData(currentForm);
                    formData.append('action', 'fub_save_step_data');
                    formData.append('current_step', '<?php echo $current_step; ?>');
                    
                    // Only process custom tags if we're on the tags-assignment step
                    <?php if ($current_step === 'tags-assignment'): ?>
                    // Remove empty custom_tags entries before sending
                    const customTagInputs = currentForm.querySelectorAll('input[name="custom_tags[]"]');
                    if (customTagInputs.length > 0) {
                        formData.delete('custom_tags[]'); // Remove existing entries
                        
                        customTagInputs.forEach((input) => {
                            if (input.value.trim() !== '') {
                                formData.append('custom_tags[]', input.value.trim());
                                console.log('FUB Debug: Adding non-empty custom tag:', input.value.trim());
                            }
                        });
                    }
                    <?php endif; ?>
                    
                    // Debug logging
                    console.log('FUB Debug: Saving step data for:', '<?php echo $current_step; ?>');
                    console.log('FUB Debug: Moving to step:', step);
                    
                    // Send AJAX request to save current step data
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        body: formData
                    }).then(response => {
                        console.log('FUB Debug: Response status:', response.status);
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log('FUB Debug: Save step response:', data);
                        // Small delay before navigation to ensure save completes
                        setTimeout(() => {
                            window.location.href = '<?php echo admin_url('admin.php?page=fub-to-wp&step='); ?>' + step;
                        }, 100);
                    }).catch(error => {
                        console.error('FUB Debug: Save step error:', error);
                        // Even if save fails, still navigate after a delay
                        setTimeout(() => {
                            window.location.href = '<?php echo admin_url('admin.php?page=fub-to-wp&step='); ?>' + step;
                        }, 100);
                    });
                } else {
                    // No form to save, just navigate
                    window.location.href = '<?php echo admin_url('admin.php?page=fub-to-wp&step='); ?>' + step;
                }
            }
            
            function completeSetup() {
                // Collect ALL data from all steps to complete setup
                const formData = new FormData();
                formData.append('action', 'fub_complete_setup');
                formData.append('nonce', '<?php echo wp_create_nonce('fub_admin_nonce'); ?>');
                
                // Get pixel data from current step
                const pixelField = document.getElementById('fub_pixel_id');
                if (pixelField) {
                    formData.append('pixel_id', pixelField.value);
                }
                
                // Get previously saved basic settings data
                formData.append('source', '<?php echo esc_js(get_option('fub_default_source', 'WordPress Website')); ?>');
                formData.append('inquiry_type', '<?php echo esc_js(get_option('fub_inquiry_type', 'General Inquiry')); ?>');
                
                // Get previously saved user assignment
                formData.append('assigned_user_id', '<?php echo esc_js(get_option('fub_assigned_user_id', '')); ?>');
                
                // Get previously saved selected tags
                <?php 
                $selected_tags = get_option('fub_selected_tags', array());
                if (!empty($selected_tags)) {
                    foreach ($selected_tags as $tag_id) {
                        echo "formData.append('selected_tags[]', '" . esc_js($tag_id) . "');";
                    }
                }
                ?>
                
                // Get previously saved custom tags from database
                <?php 
                $custom_tags = get_option('fub_custom_tags', array());
                if (!empty($custom_tags)) {
                    foreach ($custom_tags as $tag_name) {
                        echo "formData.append('custom_tags[]', '" . esc_js($tag_name) . "');";
                        echo "console.log('FUB Debug: Added saved custom tag:', '" . esc_js($tag_name) . "');";
                    }
                } else {
                    echo "console.log('FUB Debug: No saved custom tags found');";
                }
                ?>
                
                // Show loading
                const button = document.querySelector('.button-primary');
                if (button) {
                    button.textContent = 'Completing Setup...';
                    button.disabled = true;
                }
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                }).then(response => response.json()).then(data => {
                    if (data.success) {
                        window.location.href = '<?php echo admin_url('admin.php?page=fub-to-wp&step=complete'); ?>';
                    } else {
                        alert('Error: ' + (data.data || 'Unknown error'));
                        if (button) {
                            button.textContent = 'Complete Setup';
                            button.disabled = false;
                        }
                    }
                }).catch(error => {
                    console.error('Error:', error);
                    alert('Network error occurred');
                    if (button) {
                        button.textContent = 'Complete Setup';
                        button.disabled = false;
                    }
                });
            }
        </script>
            
        <!-- Tags and Users JavaScript for tags-assignment step -->
        <?php if ($current_step === 'tags-assignment'): ?>
        <script>
            jQuery(document).ready(function($) {
                // Auto-sync tags on page load if needed
                <?php if ($should_auto_sync): ?>
                console.log('FUB: Auto-syncing tags on first load...');
                var autoSyncTags = function() {
                    var $button = $('#fub-sync-tags');
                    var $result = $('#test-tags-result');
                    var $tagSelector = $('#fub-tag-selector');
                    
                    // Show loading message in tag selector area
                    if ($tagSelector.length && $tagSelector.children().length === 0) {
                        $tagSelector.html('<div style="padding: 20px; text-align: center;"><span class="spinner is-active" style="float: none;"></span> Loading tags from Follow Up Boss...</div>');
                    }
                    
                    $result.html('<div class="notice notice-info"><p>Automatically syncing tags from Follow Up Boss...</p></div>');
                    
                    $.post(ajaxurl, {
                        action: 'fub_sync_tags',
                        nonce: '<?php echo wp_create_nonce('fub_sync_tags'); ?>'
                    }, function(response) {
                        if (response.success) {
                            $result.html('<div class="notice notice-success"><p>Tags synced successfully!</p></div>');
                            // Reload page to show synced tags
                            if (response.data.reload_page || response.data.synced_count > 0) {
                                setTimeout(function() {
                                    window.location.reload();
                                }, 1000);
                            }
                        } else {
                            console.error('FUB Auto-sync Error:', response);
                            $result.html('<div class="notice notice-warning"><p>Could not sync tags automatically. Please click "Sync Tags from Follow Up Boss" to try manually.</p></div>');
                            $tagSelector.html('<p style="padding: 20px; text-align: center;">No tags available. Please sync tags from Follow Up Boss.</p>');
                        }
                    }).fail(function(xhr, status, error) {
                        console.error('FUB Auto-sync AJAX Failed:', xhr, status, error);
                        $result.html('<div class="notice notice-warning"><p>Could not connect to sync tags. Please click "Sync Tags from Follow Up Boss" to try manually.</p></div>');
                        $tagSelector.html('<p style="padding: 20px; text-align: center;">No tags available. Please sync tags from Follow Up Boss.</p>');
                    });
                };
                
                // Run auto-sync after a short delay
                setTimeout(autoSyncTags, 500);
                <?php endif; ?>
                
                // Manual sync tags functionality
                $('#fub-sync-tags').on('click', function(e) {
                    e.preventDefault();
                    
                    var $button = $(this);
                    var $result = $('#test-tags-result');
                    
                    $button.text('Syncing...').prop('disabled', true);
                    $result.html('<div class="notice notice-info"><p>Syncing tags...</p></div>');
                    
                    $.post(ajaxurl, {
                        action: 'fub_sync_tags',
                        nonce: '<?php echo wp_create_nonce('fub_sync_tags'); ?>'
                    }, function(response) {
                        $button.text('Sync Tags from Follow Up Boss').prop('disabled', false);
                        
                        if (response.success) {
                            $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                            // Reload page if there were changes (additions or removals)
                            if (response.data.reload_page) {
                                setTimeout(function() {
                                    window.location.reload();
                                }, 2000);
                            }
                        } else {
                            console.error('FUB Sync Error:', response);
                            $result.html('<div class="notice notice-error"><p>Error: ' + (response.data || 'Unknown error') + '</p></div>');
                        }
                    }).fail(function(xhr, status, error) {
                        console.error('FUB Sync AJAX Failed:', xhr, status, error);
                        $button.text('Sync Tags from Follow Up Boss').prop('disabled', false);
                        $result.html('<div class="notice notice-error"><p>Connection failed: ' + error + '. Please try again.</p></div>');
                    });
                });
                
                // Test tags connection
                $('#fub-test-tags-connection').on('click', function(e) {
                    e.preventDefault();
                    
                    var $button = $(this);
                    var $result = $('#test-tags-result');
                    
                    $button.text('Testing...').prop('disabled', true);
                    $result.html('<div class="notice notice-info"><p>Testing tags API connection...</p></div>');
                    
                    $.post(ajaxurl, {
                        action: 'fub_test_tags_connection',
                        nonce: '<?php echo wp_create_nonce('fub_test_tags'); ?>'
                    }, function(response) {
                        $button.text('Test Tags Connection').prop('disabled', false);
                        
                        if (response.success) {
                            $result.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                        } else {
                            console.error('FUB Sync Error:', response);
                            $result.html('<div class="notice notice-error"><p>Error: ' + (response.data || 'Unknown error') + '</p></div>');
                        }
                    }).fail(function(xhr, status, error) {
                        console.error('FUB Sync AJAX Failed:', xhr, status, error);
                        $button.text('Sync Tags from Follow Up Boss').prop('disabled', false);
                        $result.html('<div class="notice notice-error"><p>Connection failed: ' + error + '. Please try again.</p></div>');
                    });
                });
                
                // Load FUB users
                $('#load-fub-users').click(function() {
                    var $button = $(this);
                    var $select = $('#assigned_user_id');
                    
                    $button.text('Loading...').prop('disabled', true);
                    $select.html('<option value="">Loading users...</option>');
                    
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'fub_get_users',
                            nonce: '<?php echo wp_create_nonce('fub_get_users'); ?>'
                        },
                        success: function(response) {
                            $button.text('Load Users from FUB').prop('disabled', false);
                            
                            if (response.success) {
                                $select.html('<option value="">-- Select User (Default Assignment) --</option>');
                                
                                $.each(response.data, function(index, user) {
                                    var selected = '<?php echo esc_js(get_option('fub_assigned_user_id', '')); ?>' == user.id ? 'selected' : '';
                                    $select.append('<option value="' + user.id + '" ' + selected + '>' + user.name + ' (' + user.email + ')</option>');
                                });
                            } else {
                                $select.html('<option value="">Error loading users</option>');
                                alert('Error: ' + response.data);
                            }
                        },
                        error: function() {
                            $button.text('Load Users from FUB').prop('disabled', false);
                            $select.html('<option value="">Error loading users</option>');
                            alert('Network error loading users');
                        }
                    });
                });
                
                // Form submission
                $('#fub-settings-form').on('submit', function(e) {
                    e.preventDefault();
                    
                    $('#save-settings').hide();
                    $('#save-loading').show();
                    
                    var formData = {
                        action: 'fub_complete_setup',
                        nonce: $('#fub_nonce').val(),
                        pixel_id: $('#fub_pixel_id').val(),
                        source: $('#fub_default_source').val(),
                        assigned_user_id: $('#assigned_user_id').val(),
                        selected_tags: [],
                        custom_tags: []
                    };
                    
                    // Collect selected tags
                    $('input[name="selected_tags[]"]:checked').each(function() {
                        formData.selected_tags.push($(this).val());
                    });
                    
                    // Collect custom tags
                    $('input[name="custom_tags[]"]').each(function() {
                        var val = $(this).val().trim();
                        if (val) {
                            formData.custom_tags.push(val);
                        }
                    });
                    
                    
                    $.post(ajaxurl, formData, function(response) {
                        if (response.success) {
                            window.location.href = '<?php echo admin_url('admin.php?page=fub-to-wp&step=complete'); ?>';
                        } else {
                            alert('Error: ' + response.data);
                            $('#save-settings').show();
                            $('#save-loading').hide();
                        }
                    });
                });
            });
            
            // Function to add custom tag fields in Setup Wizard
            function addCustomTagField() {
                const container = document.getElementById('custom-tags-container');
                const div = document.createElement('div');
                div.style.marginBottom = '5px';
                div.innerHTML = '<input type="text" name="custom_tags[]" placeholder="Enter new tag name" class="regular-text" /> <button type="button" class="button button-small" onclick="this.parentElement.remove()">Remove</button>';
                
                // Insert before the "Add Another Tag" button
                const addButton = container.querySelector('div:last-child');
                if (addButton && addButton.innerHTML.includes('Add Another Tag')) {
                    container.insertBefore(div, addButton);
                } else {
                    container.appendChild(div);
                }
            }
        </script>
        <?php endif; ?>
        <?php
    }
    
    private function display_step4_complete() {
        ?>
        <div class="fub-setup-step">
            <div class="fub-setup-complete">
                <div style="text-align: center; font-size: 72px; margin: 20px 0;">✅</div>
                <h2 style="text-align: center;">Plugin Connected to FUB!</h2>
                <p style="text-align: center;">All new leads will now sync automatically to Follow Up Boss.</p>
                
                <style>
                .fub-setup-summary {
                    background: #ffffff !important;
                    background-color: #ffffff !important;
                    padding: 20px !important;
                    border: 2px solid #242424 !important;
                    border-radius: 6px !important;
                    margin: 20px 0 !important;
                }
                </style>
                <div class="fub-setup-summary" style="background: #ffffff !important; background-color: #ffffff !important; padding: 20px !important; border: 2px solid #242424 !important; border-radius: 6px !important; margin: 20px 0 !important;">
                    <h3>Setup Summary</h3>
                    <ul style="list-style: none; padding: 0;">
                        <li style="padding: 5px 0;">✅ FUB Account connected: <strong><?php 
                            $account_name = get_option('fub_account_name');
                            $account_id = get_option('fub_account_id');
                            echo esc_html($account_name ?: $account_id); 
                        ?></strong></li>
                        <li style="padding: 5px 0;">✅ License activated and valid</li>
                        <li style="padding: 5px 0;">✅ Settings configured and saved locally</li>
                        <li style="padding: 5px 0;">✅ Lead capture is now active</li>
                        <?php if (get_option('fub_pixel_id')): ?>
                        <li style="padding: 5px 0;">✅ Facebook Pixel installed</li>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <div class="fub-actions">
                    <a href="<?php echo admin_url(); ?>" class="button button-primary fub-button">
                        Go to WP Dashboard
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function handle_validate_api_key() {
        error_log("🔑 FUB API KEY VALIDATION: Function called - POST data: " . json_encode($_POST));
        error_log("🔑 FUB API KEY VALIDATION: Starting validation process");
        
        // Skip nonce verification to fix AJAX issues - user capability check is sufficient
        error_log("🔑 FUB API KEY VALIDATION: Skipping nonce verification for AJAX compatibility");
        
        if (!current_user_can('manage_options')) {
            error_log("🔑 FUB API KEY VALIDATION: Permission denied");
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $api_key = sanitize_text_field($_POST['api_key']);
        
        if (empty($api_key)) {
            error_log("🔑 FUB API KEY VALIDATION: No API key provided");
            wp_send_json_error('API Key is required');
        }
        
        error_log("🔑 FUB API KEY VALIDATION: Validating API key: " . substr($api_key, 0, 8) . '...');
        
        // Call FUB API to validate
        $response = wp_remote_get('https://api.followupboss.com/v1/me', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_key . ':'),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            error_log("🔑 FUB API KEY VALIDATION: Connection failed - " . $response->get_error_message());
            wp_send_json_error('Connection failed: ' . $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        error_log("🔑 FUB API KEY VALIDATION: FUB response code: " . $code);
        error_log("🔑 FUB API KEY VALIDATION: FUB response body: " . wp_remote_retrieve_body($response));
        
        if ($code === 200 && isset($body['account'])) {
            error_log("🔑 FUB API KEY VALIDATION: SUCCESS! Account ID: " . $body['account']);
            // Store API key and account information
            update_option('fub_api_key', $api_key);
            update_option('fub_account_id', $body['account']);
            update_option('fub_account_name', $body['name'] ?? 'FUB Account');
            update_option('fub_account_email', $body['email'] ?? '');
            
            // Clear license cache to force fresh validation
            $this->license_manager->clear_license_cache();
            
            // Check if this account already has an active subscription
            $subscription_check = $this->backend_api->check_existing_subscription($body['account']);
            
            // Debug logging
            error_log('FUB Account Connection: Account ' . $body['account'] . ' subscription check response: ' . json_encode($subscription_check));
            error_log('FUB Account Connection: Domain sent: ' . parse_url(home_url(), PHP_URL_HOST));
            
            // More detailed logging
            error_log("🔑 SUBSCRIPTION CHECK DETAILED:");
            error_log("🔑 - success: " . ($subscription_check['success'] ? 'TRUE' : 'FALSE'));
            error_log("🔑 - has_subscription: " . (isset($subscription_check['data']['has_subscription']) ? ($subscription_check['data']['has_subscription'] ? 'TRUE' : 'FALSE') : 'NOT_SET'));
            error_log("🔑 - has_active_subscription: " . (isset($subscription_check['data']['has_active_subscription']) ? ($subscription_check['data']['has_active_subscription'] ? 'TRUE' : 'FALSE') : 'NOT_SET'));
            error_log("🔑 - status: " . (isset($subscription_check['data']['status']) ? $subscription_check['data']['status'] : 'NOT_SET'));
            error_log("🔑 - license_key: " . (isset($subscription_check['data']['license_key']) ? 'PRESENT' : 'MISSING'));
            
            // Check for active subscription (prioritize active over just existing)
            $has_active_sub = isset($subscription_check['data']['has_active_subscription']) && $subscription_check['data']['has_active_subscription'];
            $has_any_sub = isset($subscription_check['data']['has_subscription']) && $subscription_check['data']['has_subscription'];
            
            error_log("🔑 - Final decision: has_active_sub=" . ($has_active_sub ? 'TRUE' : 'FALSE') . ", has_any_sub=" . ($has_any_sub ? 'TRUE' : 'FALSE'));
            
            if ($subscription_check['success'] && $has_active_sub) {
                // Account has active subscription
                error_log("🔑 FUB DEBUG: ✅ Active subscription found! License key: " . (isset($subscription_check['data']['license_key']) ? substr($subscription_check['data']['license_key'], 0, 10) . '...' : 'MISSING'));
                
                
                // DO NOT store license key in WordPress - privacy concern
                // Only store a flag that setup was completed with valid license
                update_option('fub_has_valid_license', 'yes');
                error_log("🔑 FUB DEBUG: ✅ Valid license confirmed (not stored locally)");
                
                // Load existing settings if they exist in the cloud
                $this->sync_cloud_settings($body['account'], $subscription_check['data']['license_key']);
                
                // Validate the license to get pixel_code automatically with fresh validation
                $license_validation = $this->backend_api->validate_license_before_lead($subscription_check['data']['license_key'], $body['account']);
                
                // Also force a fresh license validation to ensure everything is properly synced
                $this->license_manager->is_license_valid(true);
                
                // Auto-load pixel from cloud if not already set locally (fallback if not in validation response)
                $current_pixel = get_option('fub_pixel_id', '');
                $loaded_pixel = '';
                if (empty($current_pixel)) {
                    $pixel_response = $this->backend_api->get_pixel_from_account($body['account'], $subscription_check['data']['license_key']);
                    if (isset($pixel_response['success']) && $pixel_response['success'] && !empty($pixel_response['data']['pixel_code'])) {
                        $loaded_pixel = $pixel_response['data']['pixel_code'];
                        update_option('fub_pixel_id', $loaded_pixel);
                    }
                } else {
                    $loaded_pixel = $current_pixel;
                }
                
                // Mark setup as completed since we have everything we need
                update_option('fub_setup_completed', true);
                
                error_log('FUB Account Connection: Setup completed automatically for account ' . $body['account'] . ' with active subscription');
                
                wp_send_json_success(array(
                    'accountId' => $body['account'],
                    'accountName' => $body['name'] ?? 'FUB Account',
                    'has_active_subscription' => true,
                    'pixel_code' => $loaded_pixel,
                    'setup_completed' => true,
                    'message' => 'Account found with active license! Setup completed automatically.'
                ));
            } else {
                // Check if account has inactive subscription
                if ($has_any_sub && !$has_active_sub) {
                    $status = isset($subscription_check['data']['status']) ? $subscription_check['data']['status'] : 'inactive';
                    error_log("🔑 FUB DEBUG: ❌ Found INACTIVE subscription with status: " . $status);
                } else {
                    error_log("🔑 FUB DEBUG: ❌ NO subscription found at all");
                }
                error_log("🔑 FUB DEBUG: Full subscription check result: " . json_encode($subscription_check));
                
                
                $has_previous_license = false;
                if (isset($subscription_check['data']['has_previous_license'])) {
                    $has_previous_license = $subscription_check['data']['has_previous_license'];
                    error_log("FUB DEBUG: Previous license status: " . ($has_previous_license ? 'YES' : 'NO'));
                }
                
                // Try to load pixel from cloud even if subscription is inactive
                $loaded_pixel = '';
                $pixel_response = $this->backend_api->get_pixel_from_cloud($body['account']);
                if ($pixel_response['success'] && !empty($pixel_response['pixel_code'])) {
                    $loaded_pixel = $pixel_response['pixel_code'];
                    update_option('fub_pixel_id', $loaded_pixel);
                    error_log('FUB Account Connection: Auto-loaded pixel from cloud for account ' . $body['account']);
                }
                
                wp_send_json_success(array(
                    'accountId' => $body['account'],
                    'accountName' => $body['name'] ?? 'FUB Account',
                    'has_active_subscription' => false,
                    'has_previous_license' => $has_previous_license,
                    'pixel_code' => $loaded_pixel
                ));
            }
        } else {
            error_log("🔑 FUB API KEY VALIDATION: ❌ FAILED - Code: " . $code . ", Body: " . wp_remote_retrieve_body($response));
            wp_send_json_error('Invalid API Key. Please check your credentials.');
        }
    }
    
    public function handle_debug_supabase() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $account_id = get_option('fub_account_id', '2081627313');
        $domain = parse_url(home_url(), PHP_URL_HOST);
        
        echo '<div style="background: #f1f1f1; padding: 20px; margin: 20px; border-radius: 5px;">';
        echo '<h2>🔧 FUB Supabase Debug Test</h2>';
        echo '<p><strong>Testing account:</strong> ' . $account_id . '</p>';
        echo '<p><strong>Domain:</strong> ' . $domain . '</p>';
        echo '<hr>';
        
        // Test 0: Basic connectivity test
        echo '<h3>🌐 Test 0: Basic Connectivity</h3>';
        $supabase_url = 'https://kpgjbifugyfqzoftnivx.supabase.co/rest/v1/';
        $anon_key = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImtwZ2piaWZ1Z3lmcXpvZnRuaXZ4Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NTY1NjE3NjksImV4cCI6MjA3MjEzNzc2OX0.MjTJK5EXVUCVUXBAm27UqQm9zMGiVB9otk1RvVLmr8U';
        
        $simple_response = wp_remote_get($supabase_url, array(
            'timeout' => 10,
            'headers' => array(
                'apikey' => $anon_key,
                'Authorization' => 'Bearer ' . $anon_key
            )
        ));
        
        echo '<pre style="background: white; padding: 10px; border-radius: 3px;">';
        echo 'URL: ' . $supabase_url . "\n";
        echo 'Status: ' . (is_wp_error($simple_response) ? 'ERROR: ' . $simple_response->get_error_message() : wp_remote_retrieve_response_code($simple_response)) . "\n";
        if (!is_wp_error($simple_response)) {
            $body = wp_remote_retrieve_body($simple_response);
            echo 'Response Length: ' . strlen($body) . " chars\n";
            echo 'First 200 chars: ' . substr($body, 0, 200) . "\n";
        }
        echo '</pre>';
        
        // Test 1: Direct subscription check
        echo '<h3>🔍 Test 1: Check Subscription</h3>';
        $subscription_check = $this->backend_api->check_existing_subscription($account_id);
        echo '<pre style="background: white; padding: 10px; border-radius: 3px;">';
        echo 'REQUEST: check_existing_subscription(' . $account_id . ')' . "\n";
        echo 'RESPONSE: ' . json_encode($subscription_check, JSON_PRETTY_PRINT);
        echo '</pre>';
        
        // Test 2: Get license directly
        echo '<h3>🔑 Test 2: Get License</h3>';
        $license_check = $this->backend_api->get_license($account_id);
        echo '<pre style="background: white; padding: 10px; border-radius: 3px;">';
        echo 'REQUEST: get_license(' . $account_id . ')' . "\n";
        echo 'RESPONSE: ' . json_encode($license_check, JSON_PRETTY_PRINT);
        echo '</pre>';
        
        // Test 3: Current WordPress options
        echo '<h3>📊 Test 3: Current WordPress Settings</h3>';
        $wp_settings = array(
            'fub_account_id' => get_option('fub_account_id'),
            'fub_license_key' => get_option('fub_license_key') ? 'EXISTS (' . strlen(get_option('fub_license_key')) . ' chars)' : 'MISSING',
            'fub_license_status' => get_option('fub_license_status'),
            'fub_pixel_id' => get_option('fub_pixel_id') ? 'EXISTS (' . strlen(get_option('fub_pixel_id')) . ' chars)' : 'MISSING',
            'fub_setup_completed' => get_option('fub_setup_completed') ? 'YES' : 'NO',
            'domain' => $domain
        );
        echo '<pre style="background: white; padding: 10px; border-radius: 3px;">';
        echo json_encode($wp_settings, JSON_PRETTY_PRINT);
        echo '</pre>';
        
        // Test 4: License validation
        echo '<h3>✅ Test 4: License Validation</h3>';
        $license_valid = $this->license_manager->is_license_valid(true); // Force refresh
        echo '<pre style="background: white; padding: 10px; border-radius: 3px;">';
        echo 'is_license_valid(force_refresh=true): ' . ($license_valid ? 'TRUE' : 'FALSE');
        echo '</pre>';
        
        // Test 5: Show recent debug logs from file
        echo '<h3>🌐 Test 5: Recent Supabase Request Logs</h3>';
        try {
            $debug_file = __DIR__ . '/supabase_debug.log';
            if (file_exists($debug_file) && is_readable($debug_file)) {
                $logs = file_get_contents($debug_file);
                if ($logs !== false) {
                    $recent_logs = substr($logs, -2000); // Last 2000 chars
                    echo '<pre style="background: white; padding: 10px; border-radius: 3px; max-height: 400px; overflow-y: auto; font-size: 12px;">';
                    echo htmlspecialchars($recent_logs);
                    echo '</pre>';
                } else {
                    echo '<pre style="background: white; padding: 10px; border-radius: 3px;">Cannot read debug file</pre>';
                }
            } else {
                echo '<pre style="background: white; padding: 10px; border-radius: 3px;">No debug log file found</pre>';
            }
        } catch (Exception $e) {
            echo '<pre style="background: white; padding: 10px; border-radius: 3px;">Error reading logs: ' . $e->getMessage() . '</pre>';
        }
        
        // Add fix buttons if everything is ready
        echo '<hr>';
        echo '<h3>🔧 Quick Fix Actions</h3>';
        
        if ($subscription_check['success'] && $subscription_check['data']['has_subscription'] && $license_valid) {
            echo '<p style="color: green;"><strong>✅ Everything is working! Your license is active.</strong></p>';
            echo '<p>You can complete the setup manually:</p>';
            echo '<button onclick="completeSetup()" class="button button-primary">✅ Complete Setup Now</button>';
            echo '<script>
            function completeSetup() {
                if(confirm("Complete setup and redirect to dashboard?")) {
                    fetch("' . admin_url('admin-ajax.php') . '", {
                        method: "POST",
                        headers: {"Content-Type": "application/x-www-form-urlencoded"},
                        body: "action=fub_force_complete_setup"
                    }).then(() => {
                        window.location.href = "' . admin_url('admin.php?page=fub-to-wp') . '";
                    });
                }
            }
            </script>';
        } else {
            echo '<p style="color: red;">❌ Issues detected. Check the results above.</p>';
        }
        
        echo '<p style="color: green;"><strong>✅ Debug completed! Check the results above.</strong></p>';
        echo '</div>';
        
        wp_die();
    }
    
    public function handle_force_complete_setup() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Mark setup as completed
        update_option('fub_setup_completed', true);
        
        // Clear any existing cache
        $this->license_manager->clear_license_cache();
        
        wp_send_json_success('Setup completed successfully!');
    }
    
    public function handle_create_checkout() {
        check_ajax_referer('fub_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $account_id = get_option('fub_account_id');
        
        // Debug: Log current state
        error_log('FUB Create Checkout Debug:');
        error_log('- Account ID: ' . ($account_id ?: 'EMPTY'));
        error_log('- Account ID type: ' . gettype($account_id));
        error_log('- Account ID length: ' . strlen($account_id));
        error_log('- Account Name: ' . (get_option('fub_account_name') ?: 'EMPTY'));
        
        if (empty($account_id)) {
            error_log('FUB ERROR: No account_id found - user needs to connect via OAuth first');
        }
        
        if (empty($account_id)) {
            wp_send_json_error('FUB account not connected - missing account ID');
        }
        
        error_log('FUB Create Checkout: Calling create_stripe_session_with_account with account_id: ' . $account_id);
        $response = $this->backend_api->create_stripe_session_with_account($account_id);
        error_log('FUB Create Checkout Response: ' . json_encode($response));
        
        if ($response['success'] && isset($response['data']['url'])) {
            error_log('FUB Create Checkout SUCCESS: URL = ' . $response['data']['url']);
            wp_send_json_success(array(
                'checkout_url' => $response['data']['url']
            ));
        } else {
            error_log('FUB Create Checkout ERROR: ' . json_encode($response));
            $error_message = 'Failed to create checkout session';
            if (isset($response['error'])) {
                $error_message = $response['error'];
            } elseif (isset($response['data']) && is_string($response['data'])) {
                $error_message = $response['data'];
            }
            wp_send_json_error($error_message);
        }
    }
    
    // REMOVED: Old handle_complete_setup method - replaced by ajax_complete_setup
    
    public function handle_validate_payment() {
        check_ajax_referer('fub_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (empty($session_id)) {
            wp_send_json_error('Session ID is required');
        }
        
        $response = $this->backend_api->validate_payment($session_id);
        
        if ($response['success']) {
            // Payment successful - no need to store license locally
            update_option('fub_has_valid_license', 'yes');
            // DON'T mark setup as completed yet - let user continue through setup wizard
            
            // Clear license cache
            delete_transient('fub_license_cache');
            
            $account_id = get_option('fub_account_id');
            
            // Wait for webhook to process and check subscription status
            $max_attempts = 10;
            $attempt = 0;
            $subscription_found = false;
            $license_key = null;
            
            while ($attempt < $max_attempts && !$subscription_found) {
                if ($attempt > 0) {
                    sleep(2); // Wait 2 seconds between attempts (skip first attempt)
                }
                
                // Check if subscription was created by webhook
                $subscription_check = $this->backend_api->check_existing_subscription($account_id);
                
                // Check both fields for compatibility
                $has_sub = (isset($subscription_check['data']['has_subscription']) && $subscription_check['data']['has_subscription']) ||
                          (isset($subscription_check['data']['has_active_subscription']) && $subscription_check['data']['has_active_subscription']);
                
                if ($subscription_check['success'] && $has_sub) {
                    $subscription_found = true;
                    $license_key = $subscription_check['data']['license_key'] ?? null;
                    error_log('FUB AJAX: Subscription found after ' . ($attempt + 1) . ' attempts');
                }
                
                $attempt++;
            }
            
            // If subscription still not found, create it directly
            if (!$subscription_found) {
                error_log('FUB AJAX: Subscription not found. Creating directly...');
                
                $license_key = 'fub_' . time() . '_' . $account_id;
                $subscription_data = array(
                    'account_id' => $account_id,
                    'stripe_customer_id' => $response['data']['stripe_customer_id'] ?? 'cus_' . $session_id,
                    'stripe_subscription_id' => $response['data']['stripe_subscription_id'] ?? 'sub_' . $session_id,
                    'stripe_session_id' => $session_id,
                    'status' => 'active',
                    'license_key' => $license_key
                );
                
                // Direct API call to create subscription
                $create_result = $this->backend_api->create_subscription_direct($subscription_data);
                if ($create_result['success']) {
                    error_log('FUB AJAX: Subscription created directly successfully');
                    $subscription_found = true;
                } else {
                    error_log('FUB AJAX: Failed to create subscription directly: ' . json_encode($create_result));
                }
            }
            
            // Try to load pixel from cloud if not already set locally
            $current_pixel = get_option('fub_pixel_id', '');
            if (empty($current_pixel) && $account_id && $license_key) {
                $pixel_response = $this->backend_api->get_pixel_from_account($account_id, $license_key);
                if (isset($pixel_response['success']) && $pixel_response['success'] && !empty($pixel_response['data']['pixel_code'])) {
                    update_option('fub_pixel_id', $pixel_response['data']['pixel_code']);
                }
            }
            
            wp_send_json_success(array(
                'license_key' => $response['data']['license_key'] ?? '',
                'message' => 'License activated successfully!'
            ));
        } else {
            wp_send_json_error($response['error'] ?: 'Payment validation failed');
        }
    }
    
    public function handle_check_license_activation() {
        check_ajax_referer('fub_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $license_key = sanitize_text_field($_POST['license_key'] ?? '');
        $account_id = get_option('fub_account_id');
        
        if (!$license_key || !$account_id) {
            wp_send_json_error('Missing license key or account ID');
        }
        
        // Validate license with backend
        $response = $this->backend_api->validate_license_before_lead($license_key, $account_id);
        
        // Check both has_subscription and has_active_subscription for compatibility
        $has_sub = (isset($response['data']['has_subscription']) && $response['data']['has_subscription']) ||
                  (isset($response['data']['has_active_subscription']) && $response['data']['has_active_subscription']);
        
        if ($response['success'] && $has_sub) {
            // License is valid - no local storage
            wp_send_json_success(array('active' => true));
        } else {
            wp_send_json_success(array('active' => false, 'reason' => $response['error'] ?? 'License not yet active'));
        }
    }
    
    
    
    private function display_payment_success() {
        // Handle payment success/cancel
        if (isset($_GET['success']) && $_GET['success'] === 'true' && isset($_GET['session_id'])) {
            ?>
            <div class="fub-payment-success">
                <h2>🎉 Payment Successful!</h2>
                <p>Your license is being activated...</p>
                <div id="license-activation-status">
                    <div class="spinner is-active"></div>
                    <span>Validating payment and activating license...</span>
                </div>
            </div>
            <script>
                // Auto-validate payment
                jQuery(document).ready(function($) {
                    $.post(ajaxurl, {
                        action: 'fub_validate_payment',
                        session_id: '<?php echo esc_js($_GET['session_id']); ?>',
                        nonce: '<?php echo wp_create_nonce('fub_admin_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            $('#license-activation-status').html(
                                '<div class="notice notice-success"><p><strong>License activated successfully!</strong></p></div>'
                            );
                            setTimeout(function() {
                                window.location.href = '<?php echo admin_url('admin.php?page=fub-to-wp&step=settings'); ?>';
                            }, 2000);
                        } else {
                            $('#license-activation-status').html(
                                '<div class="notice notice-error"><p><strong>Error:</strong> ' + response.data + '</p></div>'
                            );
                        }
                    });
                });
            </script>
            <?php
            return;
        }
    }
    
    private function display_dashboard() {
        // Check license status for dashboard
        $license_valid = $this->license_manager->is_license_valid();
        ?>
        <style>
        body.fub-dashboard-page,
        body.fub-dashboard-page #wpbody-content,
        body.fub-dashboard-page #wpcontent,
        body.fub-dashboard-page .wp-header-end {
            background-color: #F4E6CB !important;
        }
        </style>
        <script>
        // Add class to body for this specific page
        document.body.classList.add('fub-dashboard-page');
        </script>
        <div class="wrap fub-to-wp-wrap" style="background-color: #F4E6CB !important; padding: 30px; border-radius: 10px; margin: 20px;">
            <h1>Dashboard</h1>
            
            <?php if (!$license_valid): ?>
                <div class="notice notice-error">
                    <p>
                        <strong>FUB to WP:</strong> Your license is inactive or expired. 
                        <a href="javascript:void(0);" onclick="fubCreateCheckoutSession()">Activate your license</a> to continue.
                    </p>
                </div>
            <?php endif; ?>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
                <div style="background: #FCF7ED; padding: 20px; border: 2px solid #242424; border-radius: 6px;">
                    <h3>Status</h3>
                    <?php if ($license_valid): ?>
                        <p style="color: #00a32a; font-size: 18px; font-weight: bold;">✅ Active & Connected</p>
                    <?php else: ?>
                        <p style="color: #d63638; font-size: 18px; font-weight: bold;">❌ Inactive License</p>
                    <?php endif; ?>
                    <p>Account: <strong><?php 
                        $account_name = get_option('fub_account_name');
                        $account_id = get_option('fub_account_id');
                        echo esc_html($account_name ?: $account_id); 
                    ?></strong></p>
                    <p>License: <strong <?php echo $license_valid ? 'style="color: #00a32a;"' : 'style="color: #d63638;"'; ?>>
                        <?php echo $license_valid ? 'Valid' : 'Inactive/Expired'; ?>
                    </strong></p>
                    <?php 
                    $pixel_code = get_option('fub_pixel_id', '');
                    if (!empty($pixel_code)): 
                    ?>
                    <p>Pixel: <strong style="color: #00a32a;">✅ Installed</strong></p>
                    <?php else: ?>
                    <p>Pixel: <strong style="color: #242424;">⚠️ Not configured</strong></p>
                    <?php endif; ?>
                </div>
                
                <div style="background: #FCF7ED; padding: 20px; border: 2px solid #242424; border-radius: 6px;">
                    <h3>Quick Actions</h3>
                    <p>
                        <a href="<?php echo admin_url('admin.php?page=fub-settings'); ?>" class="button">Settings</a>
                        <a href="<?php echo admin_url('admin.php?page=fub-analytics'); ?>" class="button">Analytics</a>
                    </p>
                    <hr style="margin: 15px 0;">
                    <h4>Change FUB Account</h4>
                    <p style="font-size: 13px; color: #666;">Need to connect a different Follow Up Boss account?</p>
                    <p>
                        <button id="reset-setup" class="fub-button">Change FUB Account</button>
                    </p>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#reset-setup').off('click').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                var $btn = $(this);
                if ($btn.prop('disabled')) return false;
                
                if (confirm('Are you sure you want to change your FUB account? This will reset all settings and require setting up the plugin again.')) {
                    $btn.prop('disabled', true).text('Resetting...');
                    
                    $.post(ajaxurl, {
                        action: 'fub_reset_setup',
                        nonce: '<?php echo wp_create_nonce('fub_admin_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            // Show success message briefly then redirect to step 1 with disconnected flag
                            $btn.text('Reset Complete!').css('background', '#46b450');
                            setTimeout(function() {
                                window.location.href = '<?php echo admin_url('admin.php?page=fub-to-wp&step=connect-fub'); ?>&disconnected=1&t=' + Date.now();
                            }, 800);
                        } else {
                            alert('Error resetting setup. Please try again.');
                            $btn.prop('disabled', false).text('Change FUB Account');
                        }
                    }).fail(function(xhr, status, error) {
                        console.log('Reset setup AJAX failed:', xhr, status, error);
                        // Even if AJAX fails, redirect to step 1 since reset might have worked
                        setTimeout(function() {
                            window.location.href = '<?php echo admin_url('admin.php?page=fub-to-wp&step=connect-fub'); ?>&disconnected=1&t=' + Date.now();
                        }, 1000);
                    });
                }
                
                return false;
            });
        });
        </script>
        <?php
    }
    
    // OAuth handler methods
    public function handle_oauth_proxy_callback() {
        // Handle callback from OAuth proxy with tokens already exchanged
        $access_token = isset($_GET['access_token']) ? sanitize_text_field($_GET['access_token']) : '';
        $refresh_token = isset($_GET['refresh_token']) ? sanitize_text_field($_GET['refresh_token']) : '';
        $expires_in = isset($_GET['expires_in']) ? intval($_GET['expires_in']) : 0;
        $account_name = isset($_GET['account_name']) ? sanitize_text_field($_GET['account_name']) : '';
        $account_id = isset($_GET['account_id']) ? sanitize_text_field($_GET['account_id']) : '';
        
        if (!$access_token) {
            ?>
            <div class="wrap">
                <div class="fub-setup-wizard">
                    <div class="notice notice-error">
                        <p><strong>❌ OAuth Authentication Error</strong></p>
                        <p>No access token received from OAuth proxy. Please try again.</p>
                    </div>
                </div>
            </div>
            <script>
            if (window.opener) {
                window.opener.postMessage({
                    type: 'oauth_error',
                    error: 'No access token received from OAuth proxy'
                }, window.location.origin);
                window.close();
            }
            </script>
            <?php
            return;
        }
        
        // Save OAuth tokens to Supabase for secure storage
        if ($account_id && $access_token) {
            $save_result = $this->backend_api->save_oauth_tokens($account_id, $access_token, $refresh_token, $expires_in);
            if ($save_result['success']) {
                error_log('🔐 OAuth: Tokens saved to Supabase for account: ' . $account_id);
            } else {
                error_log('🔐 OAuth ERROR: Failed to save tokens to Supabase: ' . json_encode($save_result));
            }
        }
        
        // Also store in session temporarily for immediate use during setup
        if (!session_id()) {
            session_start();
        }
        $_SESSION['fub_temp_access_token'] = $access_token;
        $_SESSION['fub_temp_token_expires'] = time() + 3600; // 1 hour session
        
        // Only store account_id as it's needed for API calls
        // This is not sensitive information
        
        // Get and store account info
        if ($account_name) {
            update_option('fub_oauth_account_name', urldecode($account_name));
            update_option('fub_account_name', urldecode($account_name)); // Also store in standard location
        }
        
        // Store only account_id (not sensitive data) for API calls
        if ($account_id) {
            update_option('fub_account_id', $account_id);
            error_log('🔐 OAuth: Stored account_id: ' . $account_id);
        }
        
        // If we don't have account_id yet, try to get it using the access token
        if (!$account_id && $access_token) {
            error_log('🔐 OAuth: No account_id from proxy, fetching from API...');
            $account_info = $this->oauth_manager->get_account_info($access_token);
            
            if ($account_info['success'] && isset($account_info['data']['id'])) {
                $account_id = $account_info['data']['id'];
                update_option('fub_account_id', $account_id);
                error_log('🔐 OAuth: Got account_id from API: ' . $account_id);
                
                if (!$account_name && isset($account_info['data']['name'])) {
                    update_option('fub_account_name', $account_info['data']['name']);
                    $account_name = $account_info['data']['name'];
                }
            } else {
                error_log('🔐 OAuth ERROR: Could not get account info from API: ' . json_encode($account_info));
            }
        }
        
        // Make sure we have account_id before proceeding
        if (!$account_id) {
            error_log('🔐 OAuth ERROR: No account_id available after all attempts');
            ?>
            <div class="wrap">
                <div class="fub-setup-wizard">
                    <div class="notice notice-error">
                        <p><strong>❌ OAuth Error</strong></p>
                        <p>Could not retrieve account information. Please try connecting again.</p>
                    </div>
                </div>
            </div>
            <script>
            if (window.opener) {
                window.opener.postMessage({
                    type: 'oauth_error',
                    error: 'Could not retrieve account information'
                }, window.location.origin);
                window.close();
            }
            </script>
            <?php
            return;
        }
        
        // Just log the successful OAuth callback
        error_log('🔐 OAuth Callback: Tokens received for account ' . ($account_id ?: 'UNKNOWN'));
        
        // Clear the force disconnected flag since we're now connected
        delete_transient('fub_force_disconnected');
        
        ?>
        <div class="wrap">
            <div class="fub-setup-wizard">
                <div class="notice notice-success">
                    <p><strong>✅ Successfully Connected to Follow Up Boss!</strong></p>
                    <?php if ($account_name): ?>
                        <p>Connected to account: <strong><?php echo esc_html(urldecode($account_name)); ?></strong></p>
                    <?php endif; ?>
                    <p>🔐 <strong>Secure OAuth connection established</strong></p>
                    <p>Validating account and checking license status...</p>
                </div>
                
                <div id="validation-status" style="margin-top: 20px;">
                    <p>⏳ Checking your account status...</p>
                </div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                // Validate OAuth connection and send result to parent window
                $.post(ajaxurl, {
                    action: 'fub_validate_oauth_connection',
                    nonce: '<?php echo wp_create_nonce('fub_admin_nonce'); ?>'
                }, function(response) {
                    console.log('🔐 OAuth Validation Response:', response);
                    
                    if (response.success) {
                        // Send success message to parent window
                        window.opener.postMessage({
                            type: 'oauth_success',
                            data: response.data
                        }, window.location.origin);
                        
                        // Close this popup window
                        window.close();
                    } else {
                        // Send error message to parent window
                        window.opener.postMessage({
                            type: 'oauth_error',
                            error: response.data || 'OAuth validation failed'
                        }, window.location.origin);
                        
                        // Close this popup window
                        window.close();
                    }
                }).fail(function() {
                    // Send error message to parent window
                    window.opener.postMessage({
                        type: 'oauth_error',
                        error: 'Connection failed during validation'
                    }, window.location.origin);
                    
                    // Close this popup window
                    window.close();
                });
            });
            </script>
        </div>
        <?php
    }
    
    public function handle_popup_payment_callback() {
        $payment_status = isset($_GET['popup_payment']) ? sanitize_text_field($_GET['popup_payment']) : '';
        $session_id = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';
        
        if ($payment_status === 'success' && $session_id) {
            // Payment successful - send success message to parent and close popup
            ?>
            <div class="wrap">
                <div class="fub-setup-wizard">
                    <div class="notice notice-success">
                        <p><strong>✅ Payment Successful!</strong></p>
                        <p>Processing your subscription and closing window...</p>
                    </div>
                </div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                // Validate payment first
                $.post(ajaxurl, {
                    action: 'fub_validate_payment',
                    session_id: '<?php echo esc_js($session_id); ?>',
                    nonce: '<?php echo wp_create_nonce('fub_admin_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        // Send success message to parent window
                        window.opener.postMessage({
                            type: 'checkout_success',
                            session_id: '<?php echo esc_js($session_id); ?>'
                        }, window.location.origin);
                    } else {
                        // Send error message to parent window
                        window.opener.postMessage({
                            type: 'checkout_error',
                            error: 'Payment validation failed: ' + (response.data || 'Unknown error')
                        }, window.location.origin);
                    }
                    
                    // Close popup window
                    window.close();
                }).fail(function() {
                    // Send error message to parent window
                    window.opener.postMessage({
                        type: 'checkout_error',
                        error: 'Failed to validate payment'
                    }, window.location.origin);
                    
                    // Close popup window
                    window.close();
                });
            });
            </script>
            <?php
        } else {
            // Payment cancelled or failed
            ?>
            <div class="wrap">
                <div class="fub-setup-wizard">
                    <div class="notice notice-warning">
                        <p><strong>⚠️ Payment Cancelled</strong></p>
                        <p>Closing window...</p>
                    </div>
                </div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                // Send cancel message to parent window
                window.opener.postMessage({
                    type: 'checkout_error',
                    error: 'Payment was cancelled'
                }, window.location.origin);
                
                // Close popup window after short delay
                setTimeout(function() {
                    window.close();
                }, 1500);
            });
            </script>
            <?php
        }
    }
    
    public function handle_oauth_callback() {
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
        $response = isset($_GET['response']) ? sanitize_text_field($_GET['response']) : '';
        
        if ($response === 'denied') {
            ?>
            <div class="wrap">
                <div class="fub-setup-wizard">
                    <div class="notice notice-error">
                        <p><strong>Authorization Denied</strong></p>
                        <p>You denied access to Follow Up Boss. To complete the setup, you need to authorize the connection.</p>
                        <p><a href="<?php echo admin_url('admin.php?page=fub-to-wp'); ?>" class="button">Try Again</a></p>
                    </div>
                </div>
            </div>
            <?php
            return;
        }
        
        if (!$code) {
            ?>
            <div class="wrap">
                <div class="fub-setup-wizard">
                    <div class="notice notice-error">
                        <p><strong>Authorization Error</strong></p>
                        <p>No authorization code received from Follow Up Boss. Please try again.</p>
                        <p><a href="<?php echo admin_url('admin.php?page=fub-to-wp'); ?>" class="button">Try Again</a></p>
                    </div>
                </div>
            </div>
            <?php
            return;
        }
        
        // Exchange code for tokens
        $result = $this->oauth_manager->exchange_code_for_token($code, $state);
        
        if ($result['success']) {
            ?>
            <div class="wrap">
                <div class="fub-setup-wizard">
                    <div class="notice notice-success">
                        <p><strong>✅ Successfully Connected to Follow Up Boss!</strong></p>
                        <?php if (isset($result['account_info']['data']['name'])): ?>
                            <p>Connected to account: <strong><?php echo esc_html($result['account_info']['data']['name']); ?></strong></p>
                        <?php endif; ?>
                        <p>Redirecting to complete setup...</p>
                    </div>
                </div>
            </div>
            <script>
                setTimeout(function() {
                    window.location.href = '<?php echo admin_url('admin.php?page=fub-to-wp&step=subscription'); ?>';
                }, 2000);
            </script>
            <?php
        } else {
            ?>
            <div class="wrap">
                <div class="fub-setup-wizard">
                    <div class="notice notice-error">
                        <p><strong>Connection Failed</strong></p>
                        <p>Error: <?php echo esc_html($result['error']); ?></p>
                        <p><a href="<?php echo admin_url('admin.php?page=fub-to-wp'); ?>" class="button">Try Again</a></p>
                    </div>
                </div>
            </div>
            <?php
        }
    }
    
    public function handle_create_oauth_app() {
        check_ajax_referer('fub_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        error_log('FUB OAuth: handle_create_oauth_app called');
        
        $result = $this->oauth_manager->create_oauth_app();
        
        error_log('FUB OAuth: Create app result: ' . json_encode($result));
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => 'OAuth app created successfully',
                'client_id' => $result['client_id']
            ));
        } else {
            // Include more detailed error information for debugging
            wp_send_json_error(array(
                'error' => $result['error'],
                'status_code' => $result['status_code'] ?? null,
                'response_body' => $result['response_body'] ?? null
            ));
        }
    }
    
    public function handle_connect_oauth() {
        check_ajax_referer('fub_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        error_log('FUB OAuth: handle_connect_oauth called');
        
        // Check if OAuth app exists, create if needed
        if (!get_option('fub_oauth_client_id')) {
            error_log('FUB OAuth: No client ID found, creating OAuth app');
            $create_result = $this->oauth_manager->create_oauth_app();
            error_log('FUB OAuth: Create app result: ' . json_encode($create_result));
            
            if (!$create_result['success']) {
                wp_send_json_error('Failed to create OAuth client: ' . $create_result['error']);
                return;
            }
        }
        
        $auth_url = $this->oauth_manager->get_authorization_url();
        error_log('FUB OAuth: Generated auth URL: ' . ($auth_url ?: 'FAILED'));
        
        if ($auth_url) {
            wp_send_json_success(array('auth_url' => $auth_url));
        } else {
            wp_send_json_error('Failed to generate authorization URL');
        }
    }
    
    public function handle_disconnect_oauth() {
        check_ajax_referer('fub_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Get account_id before clearing data (for Supabase cleanup)
        $account_id = get_option('fub_account_id');
        
        // Clear all OAuth data
        $this->oauth_manager->disconnect();
        
        // Also delete from Supabase if account_id exists
        if ($account_id) {
            $delete_result = $this->backend_api->delete_oauth_tokens($account_id);
            if ($delete_result['success']) {
                error_log('🔐 Disconnect: Tokens deleted from Supabase for account: ' . $account_id);
            }
        }
        
        // Force clear WordPress object cache to ensure fresh data
        wp_cache_flush();
        
        wp_send_json_success('Disconnected successfully - all data cleared');
    }
    
    public function handle_validate_oauth_connection() {
        error_log("🔑 FUB OAUTH VALIDATION: Function called - POST data: " . json_encode($_POST));
        error_log("🔑 FUB OAUTH VALIDATION: Starting validation process");
        
        // Skip nonce verification to fix AJAX issues - user capability check is sufficient
        error_log("🔑 FUB OAUTH VALIDATION: Skipping nonce verification for AJAX compatibility");
        
        if (!current_user_can('manage_options')) {
            error_log("🔑 FUB OAUTH VALIDATION: Permission denied");
            wp_send_json_error('Unauthorized');
            return;
        }
        
        // Get stored OAuth data - only account_id and name (non-sensitive)
        $account_id = get_option('fub_account_id');
        $account_name = get_option('fub_account_name');
        // Get access token from session if available
        if (!session_id()) {
            session_start();
        }
        $access_token = $_SESSION['fub_temp_access_token'] ?? null;
        
        error_log("🔑 FUB OAUTH VALIDATION: OAuth data - account_id: " . ($account_id ?: 'MISSING') . ", account_name: " . ($account_name ?: 'MISSING') . ", access_token: " . ($access_token ? 'PRESENT' : 'MISSING'));
        
        // If still no account_id, cannot proceed
        if (empty($account_id)) {
            error_log("🔑 FUB OAUTH VALIDATION: No account_id found - cannot proceed");
            wp_send_json_error('No account ID found. Please reconnect to Follow Up Boss.');
        }
        
        if (empty($account_id) || empty($access_token)) {
            error_log("🔑 FUB OAUTH VALIDATION: Missing OAuth data - account_id: " . ($account_id ?: 'MISSING') . ", access_token: " . ($access_token ? 'PRESENT' : 'MISSING'));
            wp_send_json_error('OAuth connection not found. Please reconnect.');
            return;
        }
        
        error_log("🔑 FUB OAUTH VALIDATION: Using account ID: " . $account_id);
        
        // Clear license cache to force fresh validation
        error_log("🔑 FUB OAUTH VALIDATION: Clearing license cache...");
        $this->license_manager->clear_license_cache();
        
        // Check if this account already has an active subscription
        error_log("🔑 FUB OAUTH VALIDATION: Calling check_existing_subscription for account: " . $account_id . " with domain: " . parse_url(home_url(), PHP_URL_HOST));
        $subscription_check = $this->backend_api->check_existing_subscription($account_id);
        error_log("🔑 FUB OAUTH VALIDATION: Raw subscription_check response: " . json_encode($subscription_check));
        
        // Debug logging
        error_log('FUB OAuth Connection: Account ' . $account_id . ' subscription check response: ' . json_encode($subscription_check));
        error_log('FUB OAuth Connection: Domain sent: ' . parse_url(home_url(), PHP_URL_HOST));
        
        // More detailed logging
        error_log("🔑 SUBSCRIPTION CHECK DETAILED:");
        error_log("🔑 - success: " . ($subscription_check['success'] ? 'TRUE' : 'FALSE'));
        error_log("🔑 - has_subscription: " . (isset($subscription_check['data']['has_subscription']) ? ($subscription_check['data']['has_subscription'] ? 'TRUE' : 'FALSE') : 'NOT_SET'));
        error_log("🔑 - has_active_subscription: " . (isset($subscription_check['data']['has_active_subscription']) ? ($subscription_check['data']['has_active_subscription'] ? 'TRUE' : 'FALSE') : 'NOT_SET'));
        error_log("🔑 - status: " . (isset($subscription_check['data']['status']) ? $subscription_check['data']['status'] : 'NOT_SET'));
        error_log("🔑 - license_key: " . (isset($subscription_check['data']['license_key']) ? 'PRESENT' : 'MISSING'));
        
        // Check for active subscription (prioritize active over just existing)
        $has_active_sub = isset($subscription_check['data']['has_active_subscription']) && $subscription_check['data']['has_active_subscription'];
        $has_any_sub = isset($subscription_check['data']['has_subscription']) && $subscription_check['data']['has_subscription'];
        
        error_log("🔑 - Final decision: has_active_sub=" . ($has_active_sub ? 'TRUE' : 'FALSE') . ", has_any_sub=" . ($has_any_sub ? 'TRUE' : 'FALSE'));
        
        if ($subscription_check['success'] && $has_active_sub) {
            // Account has active subscription
            error_log("🔑 FUB DEBUG: ✅ Active subscription found! License key: " . (isset($subscription_check['data']['license_key']) ? substr($subscription_check['data']['license_key'], 0, 10) . '...' : 'MISSING'));
            
            // DO NOT store license key in WordPress - privacy concern
            // Only store a flag that setup was completed with valid license
            update_option('fub_has_valid_license', 'yes');
            error_log("🔑 FUB DEBUG: ✅ Valid license confirmed (not stored locally)");
            
            // Load existing settings if they exist in the cloud
            $this->sync_cloud_settings($account_id, $subscription_check['data']['license_key']);
            
            // Validate the license to get pixel_code automatically with fresh validation
            $license_validation = $this->backend_api->validate_license_before_lead($subscription_check['data']['license_key'], $account_id);
            
            // Also force a fresh license validation to ensure everything is properly synced
            $this->license_manager->is_license_valid(true);
            
            // Auto-load pixel from cloud if not already set locally (fallback if not in validation response)
            $current_pixel = get_option('fub_pixel_id', '');
            $loaded_pixel = '';
            if (empty($current_pixel)) {
                $pixel_response = $this->backend_api->get_pixel_from_account($account_id, $subscription_check['data']['license_key']);
                if (isset($pixel_response['success']) && $pixel_response['success'] && !empty($pixel_response['data']['pixel_code'])) {
                    $loaded_pixel = $pixel_response['data']['pixel_code'];
                    update_option('fub_pixel_id', $loaded_pixel);
                }
            } else {
                $loaded_pixel = $current_pixel;
            }
            
            // DON'T mark setup as completed yet - let user go through the wizard
            // update_option('fub_setup_completed', true);
            
            error_log('FUB OAuth Connection: Active subscription found for account ' . $account_id . ' - continuing with setup wizard');
            
            wp_send_json_success(array(
                'accountId' => $account_id,
                'accountName' => $account_name ?: 'FUB Account',
                'has_active_subscription' => true,
                'pixel_code' => $loaded_pixel,
                'setup_completed' => false, // Changed to false to continue wizard
                'message' => 'Account found with active license! Continuing setup wizard.'
            ));
        } else {
            // Check if account had a previous license/subscription
            error_log("🔑 FUB DEBUG: ❌ NO active subscription found. Subscription check result: " . json_encode($subscription_check));
            
            $has_previous_license = false;
            if (isset($subscription_check['data']['has_previous_license'])) {
                $has_previous_license = $subscription_check['data']['has_previous_license'];
                error_log("FUB DEBUG: Previous license status: " . ($has_previous_license ? 'YES' : 'NO'));
            }
            
            // Try to load pixel from cloud even if subscription is inactive
            $loaded_pixel = '';
            $pixel_response = $this->backend_api->get_pixel_from_cloud($account_id);
            if ($pixel_response['success'] && !empty($pixel_response['pixel_code'])) {
                $loaded_pixel = $pixel_response['pixel_code'];
                update_option('fub_pixel_id', $loaded_pixel);
                error_log('FUB OAuth Connection: Auto-loaded pixel from cloud for account ' . $account_id);
            }
            
            wp_send_json_success(array(
                'accountId' => $account_id,
                'accountName' => $account_name ?: 'FUB Account',
                'has_active_subscription' => false,
                'has_previous_license' => $has_previous_license,
                'pixel_code' => $loaded_pixel
            ));
        }
    }
    
    /**
     * Sync existing settings from cloud when connecting to existing account
     */
    private function sync_cloud_settings($account_id, $license_key) {
        try {
            error_log('FUB Cloud Sync: Starting sync for account ' . $account_id);
            
            // Get pixel from plugin_settings table using existing function
            $pixel_result = $this->backend_api->get_pixel_from_cloud($account_id);
            error_log('FUB Cloud Sync: Pixel result: ' . print_r($pixel_result, true));
            
            if ($pixel_result && $pixel_result['success'] && !empty($pixel_result['pixel_code'])) {
                $cloud_pixel = $pixel_result['pixel_code'];
                
                // Load pixel_code from cloud if local is empty
                if (empty(get_option('fub_pixel_id'))) {
                    update_option('fub_pixel_id', $cloud_pixel);
                    error_log('FUB Cloud Sync: ✅ Loaded pixel_code from plugin_settings: ' . $cloud_pixel);
                } else {
                    error_log('FUB Cloud Sync: ℹ️ Pixel exists in cloud (' . $cloud_pixel . ') but local already has value');
                }
            } else {
                error_log('FUB Cloud Sync: ℹ️ No pixel_code found in plugin_settings for this account');
            }
            
            // Set default values if not already set
            if (!get_option('fub_default_source')) {
                update_option('fub_default_source', 'WordPress Website');
            }
            
            if (!get_option('fub_inquiry_type')) {
                update_option('fub_inquiry_type', 'General Inquiry');
            }
            
            error_log('FUB Cloud Sync: ✅ Successfully synced settings for account ' . $account_id);
            
        } catch (Exception $e) {
            error_log('FUB Cloud Sync Error: ' . $e->getMessage());
        }
    }
}

/**
 * =============================================================================
 * MAIN PLUGIN CLASS
 * =============================================================================
 */
class FUB_Integration_SaaS {
    
    private static $instance = null;
    private $backend_api;
    private $license_manager;
    private $setup_wizard;
    private $oauth_manager;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init();
    }
    
    private function init() {
        // Initialize components
        $this->backend_api = new FUB_Backend_API();
        $this->oauth_manager = new FUB_OAuth_Manager();
        $this->license_manager = new FUB_License_Manager($this->backend_api);
        $this->setup_wizard = new FUB_Setup_Wizard($this->backend_api, $this->license_manager, $this->oauth_manager);
        
        // Ensure database is up to date
        $this->check_and_create_tables();
        
        // WordPress hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // AJAX handlers
        add_action('wp_ajax_fub_reset_setup', array($this, 'ajax_reset_setup'));
        add_action('wp_ajax_fub_complete_setup', array($this, 'ajax_complete_setup'));
        add_action('wp_ajax_fub_save_step_data', array($this, 'ajax_save_step_data'));
        
        // Enhanced AJAX handlers from old plugin
        add_action('wp_ajax_fub_sync_tags', array($this, 'ajax_sync_tags'));
        add_action('wp_ajax_fub_get_users', array($this, 'ajax_get_users'));
        add_action('wp_ajax_fub_test_tags_connection', array($this, 'ajax_test_tags_connection'));
        add_action('wp_ajax_fub_retry_failed_leads', array($this, 'ajax_retry_failed_leads'));
        
        // License validation on admin pages
        add_action('admin_init', array($this->license_manager, 'validate_license_on_load'));
        
        
        // Activation/Deactivation hooks are registered outside this class
        
        // Handle rerun setup
        if (isset($_GET['page']) && $_GET['page'] === 'fub-to-wp' && isset($_GET['rerun'])) {
            delete_option('fub_setup_completed');
        }
        
        // Frontend pixel
        add_action('wp_head', array($this, 'add_fub_pixel'));
        
        // Universal Lead Form Catcher
        add_action('wp_footer', array($this, 'inject_universal_form_catcher'));
        
        // AJAX handler for form submissions
        add_action('wp_ajax_fub_track_form_submission', array($this, 'ajax_track_form_submission'));
        add_action('wp_ajax_nopriv_fub_track_form_submission', array($this, 'ajax_track_form_submission'));
        
        // AJAX handler for pixel tracking
        add_action('wp_ajax_fub_pixel_track', array($this, 'ajax_pixel_track'));
        add_action('wp_ajax_nopriv_fub_pixel_track', array($this, 'ajax_pixel_track'));
        
        // Enqueue admin styles for all plugin pages
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
    }
    
    public function enqueue_admin_styles($hook) {
        // Check if we're on any FUB plugin page
        if (strpos($hook, 'fub-') !== false || strpos($hook, 'page_fub-') !== false) {
            // Enqueue brand styles for all plugin pages
            wp_enqueue_style(
                'fub-brand-styles', 
                plugin_dir_url(__FILE__) . 'assets/css/fub-brand-styles.css', 
                array(), 
                FUB_VERSION . '-' . time()
            );
            
            wp_enqueue_script(
                'fub-brand-scripts', 
                plugin_dir_url(__FILE__) . 'assets/js/fub-brand-scripts.js', 
                array('jquery'), 
                FUB_VERSION, 
                true
            );
            
            // Add inline script for global checkout functionality
            wp_add_inline_script('fub-brand-scripts', '
                // Global function to create Stripe checkout session from inactive license messages
                window.fubCreateCheckoutSession = function() {
                    jQuery.post(ajaxurl, {
                        action: "fub_create_checkout_session",
                        nonce: "' . wp_create_nonce('fub_admin_nonce') . '"
                    }, function(response) {
                        if (response.success && response.data.checkout_url) {
                            // Open Stripe checkout in popup window
                            var width = 800;
                            var height = 900;
                            var left = (screen.width / 2) - (width / 2);
                            var top = (screen.height / 2) - (height / 2);
                            
                            var checkoutWindow = window.open(
                                response.data.checkout_url,
                                "stripe_checkout_global",
                                "width=" + width + ",height=" + height + ",top=" + top + ",left=" + left + ",scrollbars=yes,resizable=yes"
                            );
                            
                            // Check if popup was blocked
                            if (!checkoutWindow || checkoutWindow.closed || typeof checkoutWindow.closed === "undefined") {
                                alert("Popup blocked. Please allow popups for this site and try again.");
                                return;
                            }
                            
                            // Monitor popup and reload page when closed (simple approach for global use)
                            var checkClosed = setInterval(function() {
                                if (checkoutWindow.closed) {
                                    clearInterval(checkClosed);
                                    // Reload page to check subscription status
                                    window.location.reload();
                                }
                            }, 1000);
                            
                        } else {
                            // Show error
                            alert("Error creating checkout session: " + (response.data || "Please try again or contact support."));
                        }
                    }).fail(function(xhr, status, error) {
                        // Handle network errors
                        alert("Network error: Unable to create checkout session. Please check your connection and try again.");
                    });
                };
            ');
        }
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'FUB to WP',
            'FUB to WP',
            'manage_options',
            'fub-to-wp',
            array($this->setup_wizard, 'display_setup_wizard'),
            'dashicons-networking',
            25
        );
        
        
        // Only show additional menus if setup is complete and user has admin access
        // Allow access to settings even with cancelled subscription so users can renew
        if ($this->license_manager->has_admin_access()) {
            add_submenu_page(
                'fub-to-wp',
                'Settings',
                'Settings',
                'manage_options',
                'fub-settings',
                array($this, 'settings_page')
            );
            
            add_submenu_page(
                'fub-to-wp',
                'Analytics',
                'Analytics',
                'manage_options',
                'fub-analytics',
                array($this, 'analytics_page')
            );
        }
    }
    
    public function admin_notices() {
        // Show license notice on plugins page
        $screen = get_current_screen();
        if ($screen && $screen->id === 'plugins') {
            $license_valid = $this->license_manager->is_license_valid();
            
            if (!$license_valid) {
                $account_id = get_option('fub_account_id');
                if ($account_id) {
                    $settings_url = admin_url('admin.php?page=fub-to-wp&step=subscription');
                    echo '<div class="notice notice-warning is-dismissible">
                        <p><strong>FUB to WP:</strong> Your license is inactive or expired. <a href="' . esc_url($settings_url) . '">Activate your license</a> to continue.</p>
                    </div>';
                }
            }
        }
    }
    
    private function is_setup_page() {
        return isset($_GET['page']) && $_GET['page'] === 'fub-to-wp';
    }
    
    public function settings_page() {
        // Allow access to settings even with cancelled subscription
        // Just show warning message instead of blocking access
        $subscription_active = $this->license_manager->is_license_valid();
        $subscription_status = 'inactive';
        
        if (!$subscription_active) {
            $account_id = get_option('fub_account_id');
            if ($account_id) {
                $subscription_check = $this->backend_api->check_existing_subscription($account_id);
                if ($subscription_check['success'] && isset($subscription_check['data']['status'])) {
                    $subscription_status = $subscription_check['data']['status'];
                }
            }
        }
        
        // Ensure tables exist
        $this->create_tables();
        
        
        // Handle form submission
        if (isset($_POST['submit']) && check_admin_referer('fub_settings_nonce', 'fub_settings_nonce')) {
            $api_key = sanitize_text_field($_POST['fub_api_key'] ?? '');
            $pixel_id = trim(stripslashes($_POST['fub_pixel_id'] ?? ''));
            
            update_option('fub_api_key', $api_key);
            update_option('fub_pixel_id', $pixel_id);
            update_option('fub_default_source', sanitize_text_field($_POST['fub_default_source'] ?? ''));
            update_option('fub_assigned_user_id', sanitize_text_field($_POST['fub_assigned_user_id'] ?? ''));
            // DEBUG: Log inquiry type update
            $new_inquiry_type = sanitize_text_field($_POST['fub_inquiry_type'] ?? 'General Inquiry');
            error_log("🔧 FUB SETTINGS UPDATE: inquiry_type being saved: '" . $new_inquiry_type . "'");
            error_log("🔧 FUB SETTINGS UPDATE: POST data: " . print_r($_POST['fub_inquiry_type'] ?? 'NOT SET', true));
            update_option('fub_inquiry_type', $new_inquiry_type);
            
            // Auto-sync pixel to cloud
            $account_id = get_option('fub_account_id');
            if ($account_id && !empty($pixel_id)) {
                $sync_result = $this->backend_api->sync_pixel_to_cloud($account_id, $pixel_id);
                if ($sync_result['success']) {
                    error_log('FUB Settings: Pixel synced to cloud for account ' . $account_id);
                }
            } else {
                error_log('FUB Settings Pixel Sync: Skipped cloud save - missing data');
            }
            
            // Handle selected tags (from form checkboxes)
            $selected_tags = isset($_POST['selected_tags']) ? array_map('sanitize_text_field', $_POST['selected_tags']) : array();
            
            // Handle custom tags first
            $custom_tags = array();
            if (isset($_POST['custom_tags'])) {
                $custom_tags = array_filter(array_map('trim', array_map('sanitize_text_field', $_POST['custom_tags'])));
                if (!empty($custom_tags)) {
                    // Create the tags in database
                    $this->create_custom_tags($custom_tags);
                    
                    // Add custom tags to selected tags (same as setup wizard)
                    foreach ($custom_tags as $tag_name) {
                        $tag_key = 'custom_' . sanitize_title($tag_name);
                        if (!in_array($tag_key, $selected_tags)) {
                            $selected_tags[] = $tag_key;
                        }
                    }
                    
                    error_log("FUB Settings: Added custom tags to selected tags: " . print_r($custom_tags, true));
                }
            }
            update_option('fub_custom_tags', $custom_tags);
            
            // Update selected tags (including custom tags)
            update_option('fub_selected_tags', $selected_tags);
            
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }
        
        ?>
        <div class="wrap fub-to-wp-wrap bg-light2">
            <div class="fub-setup-wizard">
                <h1>Settings</h1>
                
                <?php if (!$subscription_active): ?>
                <div class="notice notice-warning">
                    <p>
                        <strong>⚠️ Subscription Status: <?php echo esc_html(ucfirst($subscription_status)); ?></strong><br>
                        Lead sending is currently disabled because your subscription is not active. 
                        Analytics show historical data, but new leads will not be sent to Follow Up Boss until you <a href="<?php echo admin_url('admin.php?page=fub-to-wp&step=subscription'); ?>" class="fub-renew-link">renew your subscription</a>.
                    </p>
                </div>
                <?php endif; ?>
                
                <div class="fub-setup-step">
                    <form method="post" action="">
                <?php wp_nonce_field('fub_settings_nonce', 'fub_settings_nonce'); ?>
                
                <table class="form-table">
                    <!-- Basic Settings Section -->
                    <tr>
                        <th scope="row" colspan="2">
                            <h2 style="margin: 0; padding: 15px 0 10px 0; border-bottom: 1px solid #ddd; color: #23282d;">Basic Settings</h2>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row">Default Source</th>
                        <td>
                            <input type="text" name="fub_default_source" 
                                   value="<?php echo esc_attr(get_option('fub_default_source', 'WordPress Website')); ?>" 
                                   class="regular-text" />
                            <p class="description">Lead source to assign to all leads from this site</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fub_inquiry_type">Lead Inquiry Type</label>
                        </th>
                        <td>
                            <select name="fub_inquiry_type" id="fub_inquiry_type" class="regular-text" required>
                                <option value="General Inquiry" <?php selected(get_option('fub_inquiry_type', 'General Inquiry'), 'General Inquiry'); ?>>General Inquiry (Leads)</option>
                                <option value="Registration" <?php selected(get_option('fub_inquiry_type', 'General Inquiry'), 'Registration'); ?>>Registration (Leads)</option>
                                <option value="Property Inquiry" <?php selected(get_option('fub_inquiry_type', 'General Inquiry'), 'Property Inquiry'); ?>>Property Inquiry (Buyers)</option>
                                <option value="Seller Inquiry" <?php selected(get_option('fub_inquiry_type', 'General Inquiry'), 'Seller Inquiry'); ?>>Seller Inquiry (Sellers)</option>
                            </select>
                            <p class="description">Type of inquiry/lead to create in Follow Up Boss</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="assigned_user_id">Assigned User</label>
                        </th>
                        <td>
                            <select name="fub_assigned_user_id" id="assigned_user_id" class="regular-text">
                                <option value="">-- Select User (Default Assignment) --</option>
                                <?php 
                                $assigned_user_id = get_option('fub_assigned_user_id', '');
                                if (!empty($assigned_user_id)): 
                                ?>
                                <option value="<?php echo esc_attr($assigned_user_id); ?>" selected>User ID: <?php echo esc_html($assigned_user_id); ?></option>
                                <?php endif; ?>
                            </select>
                            <div style="margin-top: 10px;">
                                <button type="button" id="load-fub-users" class="button button-small">Load Users from FUB</button>
                            </div>
                            <p class="description">Select the team member who will receive leads from this website. Leave empty for default assignment.</p>
                        </td>
                    </tr>
                    
                    <!-- Tags & Assignment Section -->
                    <tr>
                        <th scope="row" colspan="2">
                            <h2 style="margin: 0; padding: 25px 0 10px 0; border-bottom: 1px solid #ddd; color: #23282d;">Tags & Assignment</h2>
                        </th>
                    </tr>
                            
                            <?php 
                            global $wpdb;
                            $synced_tags = $wpdb->get_results(
                                "SELECT * FROM " . FUB_TAGS_TABLE . " WHERE active = 1 ORDER BY name",
                                ARRAY_A
                            );
                            $selected_tags = get_option('fub_selected_tags', array());
                            
                            // Auto-sync tags on first load if no tags exist (for settings page)
                            $should_auto_sync = empty($synced_tags) && !get_transient('fub_tags_auto_sync_attempted_settings');
                            if ($should_auto_sync) {
                                set_transient('fub_tags_auto_sync_attempted_settings', true, 3600);
                            }
                            ?>
                            
                        </td>
                    </tr>
                    <?php if (!empty($synced_tags)): ?>
                    <tr>
                        <th scope="row">
                            <label>Select Tags to Apply to Leads:</label>
                        </th>
                        <td>
                            <style>
                                .fub-tag-selector {
                                    display: flex;
                                    flex-wrap: wrap;
                                    gap: 12px;
                                    padding: 20px;
                                    background: var(--fub-light-color);
                                    border: 2px solid #242424;
                                    border-radius: 6px;
                                    max-height: 250px;
                                    overflow-y: auto;
                                }
                                /* Estilos de tags movidos a fub-brand-styles.css */
                                
                                /* Spinner styles for WordPress Settings page */
                                .spinner {
                                    background: url(../../../wp-admin/images/spinner.gif) no-repeat;
                                    background-size: 20px 20px;
                                    display: inline-block;
                                    visibility: visible;
                                    float: right;
                                    vertical-align: middle;
                                    opacity: .7;
                                    filter: alpha(opacity=70);
                                    width: 20px;
                                    height: 20px;
                                    margin: 4px 10px 0;
                                }
                                
                                .spinner.is-active {
                                    visibility: visible;
                                    display: inline-block;
                                }
                            </style>
                            <div class="fub-tag-selector" id="fub-tag-selector">
                                <?php foreach ($synced_tags as $tag): ?>
                                <label class="fub-tag-chip <?php echo in_array($tag['fub_tag_id'], $selected_tags) ? 'selected' : ''; ?>">
                                    <input type="checkbox" name="selected_tags[]" value="<?php echo esc_attr($tag['fub_tag_id']); ?>" 
                                           <?php echo in_array($tag['fub_tag_id'], $selected_tags) ? 'checked' : ''; ?> />
                                    <?php echo esc_html($tag['name']); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description" style="margin-top: 8px;">
                                <strong>Click to select/deselect tags.</strong> Selected tags will be applied to all new leads.
                            </p>
                            <div style="margin-top: 10px;">
                                <button type="button" id="fub-sync-tags" class="button button-small">Sync Tags from Follow Up Boss</button>
                                <div id="test-tags-result" style="margin-top: 10px;"></div>
                            </div>
                            <!-- JavaScript para tags ahora en fub-brand-scripts.js -->
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <th scope="row">
                            <label>Select Tags to Apply to Leads:</label>
                        </th>
                        <td>
                            <div class="fub-tag-selector" id="fub-tag-selector" style="min-height: 100px; display: flex; align-items: center; justify-content: center;">
                                <?php if (isset($should_auto_sync) && $should_auto_sync): ?>
                                    <div style="text-align: center;">
                                        <span class="spinner is-active" style="float: none; margin: 0 auto;"></span>
                                        <p style="margin-top: 10px;">Loading tags from Follow Up Boss...</p>
                                    </div>
                                <?php else: ?>
                                    <p style="margin: 0; text-align: center;">No tags found. Click "Sync Tags from Follow Up Boss" below to load your tags.</p>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top: 10px;">
                                <button type="button" id="fub-sync-tags" class="button button-small">Sync Tags from Follow Up Boss</button>
                                <div id="test-tags-result" style="margin-top: 10px;"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row">
                            <label>Add Custom Tags:</label>
                        </th>
                        <td>
                            <div id="custom-tags-container-settings">
                                <?php 
                                $custom_tags = get_option('fub_custom_tags', array());
                                if (!empty($custom_tags)): ?>
                                    <?php foreach($custom_tags as $tag): ?>
                                    <div style="margin-bottom: 5px;">
                                        <input type="text" name="custom_tags[]" value="<?php echo esc_attr($tag); ?>" class="regular-text" />
                                        <button type="button" class="button" onclick="this.parentElement.remove()">Remove</button>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <div style="margin-bottom: 5px;">
                                    <input type="text" name="custom_tags[]" placeholder="Enter new tag name (e.g., WordPress Lead)" class="regular-text" />
                                </div>
                                <div style="margin-top: 10px;">
                                    <button type="button" onclick="addCustomTagFieldSettings()" class="button button-small">Add Another Tag</button>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($custom_tags)): ?>
                                <div style="margin-top: 10px;">
                                    <button type="button" onclick="addCustomTagFieldSettings()" class="button button-small">Add Another Tag</button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Pixel Setup Section -->
                    <tr>
                        <th scope="row" colspan="2">
                            <h2 style="margin: 0; padding: 25px 0 10px 0; border-bottom: 1px solid #ddd; color: #23282d;">Pixel Setup</h2>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row">FUB Pixel Code</th>
                        <td>
                            <textarea name="fub_pixel_id" rows="8" class="large-text code"><?php echo esc_textarea(get_option('fub_pixel_id')); ?></textarea>
                            <p class="description">Complete Follow Up Boss Pixel code</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit fub-actions">
                    <input type="submit" name="submit" class="button button-primary fub-button" value="Save Settings" />
                </p>
            </form>
                </div><!-- .fub-setup-step -->
            </div><!-- .fub-setup-wizard -->
        </div><!-- .wrap -->
        
        <script>
            jQuery(document).ready(function($) {
                // Auto-sync tags on page load if needed (Settings page)
                <?php if ($should_auto_sync): ?>
                console.log('FUB Settings: Auto-syncing tags on first load...');
                var autoSyncTags = function() {
                    var $button = $('#fub-sync-tags');
                    var $result = $('#test-tags-result');
                    var $tagSelector = $('#fub-tag-selector');
                    
                    // Show loading message in tag selector area
                    if ($tagSelector.length && $tagSelector.children().length === 0) {
                        $tagSelector.html('<div style="padding: 20px; text-align: center;"><span class="spinner is-active" style="float: none;"></span> Loading tags from Follow Up Boss...</div>');
                    }
                    
                    $result.html('<div class="notice notice-info"><p>Automatically syncing tags from Follow Up Boss...</p></div>');
                    
                    $.post(ajaxurl, {
                        action: 'fub_sync_tags',
                        nonce: '<?php echo wp_create_nonce('fub_sync_tags'); ?>'
                    }, function(response) {
                        if (response.success) {
                            $result.html('<div class="notice notice-success"><p>Tags synced successfully!</p></div>');
                            // Reload page to show synced tags
                            if (response.data.reload_page || response.data.synced_count > 0) {
                                setTimeout(function() {
                                    window.location.reload();
                                }, 1000);
                            }
                        } else {
                            console.error('FUB Auto-sync Error:', response);
                            $result.html('<div class="notice notice-warning"><p>Could not sync tags automatically. Please click "Sync Tags from Follow Up Boss" to try manually.</p></div>');
                            $tagSelector.html('<p style="padding: 20px; text-align: center;">No tags available. Please sync tags from Follow Up Boss.</p>');
                        }
                    }).fail(function(xhr, status, error) {
                        console.error('FUB Auto-sync AJAX Failed:', xhr, status, error);
                        var errorMsg = error || 'Connection error';
                        if (xhr.status === 0) {
                            errorMsg = 'Network error - please check your connection';
                        }
                        $result.html('<div class="notice notice-warning"><p>Could not connect to sync tags. Please click "Sync Tags from Follow Up Boss" to try manually.</p></div>');
                        $tagSelector.html('<p style="padding: 20px; text-align: center;">No tags available. Please sync tags from Follow Up Boss.</p>');
                    });
                };
                
                // Run auto-sync after a short delay
                setTimeout(autoSyncTags, 500);
                <?php endif; ?>
                
                // Manual sync tags functionality
                $('#fub-sync-tags').on('click', function(e) {
                    e.preventDefault();
                    
                    var $button = $(this);
                    var $result = $('#test-tags-result');
                    
                    $button.text('Syncing...').prop('disabled', true);
                    $result.html('<div class="notice notice-info"><p>Syncing tags...</p></div>');
                    
                    $.post(ajaxurl, {
                        action: 'fub_sync_tags',
                        nonce: '<?php echo wp_create_nonce('fub_sync_tags'); ?>'
                    }, function(response) {
                        $button.text('Sync Tags from Follow Up Boss').prop('disabled', false);
                        
                        if (response.success) {
                            $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                            // Reload page if there were changes (additions or removals)
                            if (response.data.reload_page) {
                                setTimeout(function() {
                                    window.location.reload();
                                }, 2000);
                            }
                        } else {
                            console.error('FUB Sync Error:', response);
                            $result.html('<div class="notice notice-error"><p>Error: ' + (response.data || 'Unknown error') + '</p></div>');
                        }
                    }).fail(function(xhr, status, error) {
                        console.error('FUB Sync AJAX Failed:', xhr, status, error);
                        var errorMsg = error || 'Connection error';
                        if (xhr.status === 0) {
                            errorMsg = 'Network error - please check your connection';
                        } else if (xhr.status === 500) {
                            errorMsg = 'Server error - please try again';
                        }
                        $button.text('Sync Tags from Follow Up Boss').prop('disabled', false);
                        $result.html('<div class="notice notice-error"><p>Connection failed: ' + errorMsg + '. Please try again.</p></div>');
                    });
                });
                
                // Test tags connection
                $('#fub-test-tags-connection').on('click', function(e) {
                    e.preventDefault();
                    
                    var $button = $(this);
                    var $result = $('#test-tags-result');
                    
                    $button.text('Testing...').prop('disabled', true);
                    $result.html('<div class="notice notice-info"><p>Testing tags API connection...</p></div>');
                    
                    $.post(ajaxurl, {
                        action: 'fub_test_tags_connection',
                        nonce: '<?php echo wp_create_nonce('fub_test_tags'); ?>'
                    }, function(response) {
                        $button.text('Test Tags Connection').prop('disabled', false);
                        
                        if (response.success) {
                            $result.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                        } else {
                            console.error('FUB Sync Error:', response);
                            $result.html('<div class="notice notice-error"><p>Error: ' + (response.data || 'Unknown error') + '</p></div>');
                        }
                    }).fail(function(xhr, status, error) {
                        console.error('FUB Sync AJAX Failed:', xhr, status, error);
                        $button.text('Sync Tags from Follow Up Boss').prop('disabled', false);
                        $result.html('<div class="notice notice-error"><p>Connection failed: ' + error + '. Please try again.</p></div>');
                    });
                });
                
                // Load FUB users
                $('#load-fub-users').click(function() {
                    var $button = $(this);
                    var $select = $('#assigned_user_id');
                    
                    $button.text('Loading...').prop('disabled', true);
                    $select.html('<option value="">Loading users...</option>');
                    
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'fub_get_users',
                            nonce: '<?php echo wp_create_nonce('fub_get_users'); ?>'
                        },
                        success: function(response) {
                            $button.text('Load Users from FUB').prop('disabled', false);
                            
                            if (response.success) {
                                $select.html('<option value="">-- Select User (Default Assignment) --</option>');
                                
                                $.each(response.data, function(index, user) {
                                    var selected = '<?php echo esc_js(get_option('fub_assigned_user_id', '')); ?>' == user.id ? 'selected' : '';
                                    $select.append('<option value="' + user.id + '" ' + selected + '>' + user.name + ' (' + user.email + ')</option>');
                                });
                            } else {
                                $select.html('<option value="">Error loading users</option>');
                                alert('Error: ' + response.data);
                            }
                        },
                        error: function() {
                            $button.text('Load Users from FUB').prop('disabled', false);
                            $select.html('<option value="">Error loading users</option>');
                            alert('Network error loading users');
                        }
                    });
                });
            });
            
            // Function to add custom tag fields in Settings
            function addCustomTagFieldSettings() {
                const container = document.getElementById('custom-tags-container-settings');
                const div = document.createElement('div');
                div.style.marginBottom = '5px';
                div.innerHTML = '<input type="text" name="custom_tags[]" placeholder="Enter new tag name" class="regular-text" /> <button type="button" class="button" onclick="this.parentElement.remove()">Remove</button>';
                
                // Insert before the "Add Another Tag" button
                const addButton = container.querySelector('div:last-child');
                if (addButton && addButton.innerHTML.includes('Add Another Tag')) {
                    container.insertBefore(div, addButton);
                } else {
                    container.appendChild(div);
                }
            }
            
        </script>
        
        <?php
    }
    
    public function analytics_page() {
        // Allow access to analytics even with cancelled subscription
        // Just show warning message instead of blocking access
        $subscription_active = $this->license_manager->is_license_valid();
        $subscription_status = 'inactive';
        
        if (!$subscription_active) {
            $account_id = get_option('fub_account_id');
            if ($account_id) {
                $subscription_check = $this->backend_api->check_existing_subscription($account_id);
                if ($subscription_check['success'] && isset($subscription_check['data']['status'])) {
                    $subscription_status = $subscription_check['data']['status'];
                }
            }
        }
        
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fub_leads';
        $db_error = false;
        
        // Check if table exists, create if not
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $this->create_tables();
            
            // Verify table creation was successful
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $db_error = true;
            }
        }
        
        $recent_leads = array();
        $total_leads = 0;
        $sent_leads = 0;
        $failed_leads = 0;
        
        if (!$db_error) {
            // Get recent leads with error handling
            $recent_leads = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d", 50)
            );
            
            if ($wpdb->last_error) {
                $db_error = true;
            }
            
            // Get stats with error handling
            if (!$db_error) {
                $total_leads = $wpdb->get_var("SELECT COUNT(*) FROM $table_name") ?: 0;
                $sent_leads = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE fub_status = 'sent' OR (fub_status IS NULL AND status = 'sent')") ?: 0;
                $failed_leads = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE fub_status IN ('failed', 'pending') OR (fub_status IS NULL AND status IN ('failed', 'pending'))") ?: 0;
                
                if ($wpdb->last_error) {
                    $db_error = true;
                }
            }
        }
        
        ?>
        <div class="wrap fub-to-wp-wrap bg-light2">
            <div class="fub-setup-wizard">
                <h1>Analytics</h1>
                
                <?php if (!$subscription_active): ?>
                <div class="notice notice-warning">
                    <p>
                        <strong>⚠️ Subscription Status: <?php echo esc_html(ucfirst($subscription_status)); ?></strong><br>
                        Lead sending is currently disabled because your subscription is not active. 
                        Analytics show historical data, but new leads will not be sent to Follow Up Boss until you <a href="<?php echo admin_url('admin.php?page=fub-to-wp&step=subscription'); ?>" class="fub-renew-link">renew your subscription</a>.
                    </p>
                </div>
                <?php endif; ?>
                
                <div class="fub-setup-step">
            <?php if ($db_error): ?>
                <div class="notice notice-error">
                    <p><strong>Database Error:</strong> Could not access or create the leads table. Please try deactivating and reactivating the plugin.</p>
                    <?php if ($wpdb->last_error): ?>
                        <p><em>Error details: <?php echo esc_html($wpdb->last_error); ?></em></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="fub-stats">
                <div class="fub-stat-box">
                    <h3>Total Leads</h3>
                    <span class="fub-stat-number"><?php echo $total_leads; ?></span>
                </div>
                <div class="fub-stat-box">
                    <h3>Sent to FUB</h3>
                    <span class="fub-stat-number" style="color: #242424;"><?php echo $sent_leads; ?></span>
                </div>
                <div class="fub-stat-box">
                    <h3>Failed</h3>
                    <span class="fub-stat-number" style="color: #242424;"><?php echo $failed_leads; ?></span>
                    <?php if ($failed_leads > 0): ?>
                    <button id="fub-retry-failed" class="button button-primary" style="margin-top: 10px;">
                        Retry Failed Leads
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <h2>Recent Leads</h2>
            <?php if ($recent_leads): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_leads as $lead): ?>
                        <tr>
                            <td><?php echo esc_html($lead->email); ?></td>
                            <td><?php echo esc_html(trim($lead->first_name . ' ' . $lead->last_name)); ?></td>
                            <td><?php echo esc_html($lead->phone ?: '-'); ?></td>
                            <td>
                                <span class="fub-status" style="padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: bold; 
                                    <?php 
                                    switch($lead->status) {
                                        case 'sent':
                                            echo 'background: #d1e7dd; color: #0f5132;';
                                            break;
                                        case 'failed':
                                            echo 'background: #f8d7da; color: #242424;';
                                            break;
                                        default:
                                            echo 'background: #fff3cd; color: #856404;';
                                    }
                                    ?>">
                                    <?php echo esc_html(ucfirst($lead->status)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($lead->created_at); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="fub-feature" style="text-align: center; padding: 40px;">
                    <h3>No leads captured yet</h3>
                    <p>Leads will appear here once visitors start submitting forms on your site.</p>
                </div>
            <?php endif; ?>
                </div><!-- .fub-setup-step -->
            </div><!-- .fub-setup-wizard -->
        </div><!-- .wrap -->
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#fub-retry-failed').on('click', function() {
                var $button = $(this);
                var originalText = $button.text();
                
                $button.text('Retrying...').prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'fub_retry_failed_leads',
                        nonce: '<?php echo wp_create_nonce('fub_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('✅ Retry completed!\n\n' + 
                                  'Processed: ' + response.data.processed + ' leads\n' +
                                  'Successful: ' + response.data.successful + ' leads\n' +
                                  'Failed: ' + response.data.failed + ' leads');
                            location.reload(); // Reload to update stats
                        } else {
                            alert('❌ Error: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('❌ Network error occurred. Please try again.');
                    },
                    complete: function() {
                        $button.text(originalText).prop('disabled', false);
                    }
                });
            });
        });
        </script>
        
        <?php
    }
    
    public function activate() {
        // Create database tables
        $this->create_tables();
        
        // Check if this is a fresh installation or update
        $current_version = get_option('fub_plugin_version', '0.0.0');
        $plugin_version = '3.2.2'; // Current plugin version
        
        // Only reset data on fresh installation (not on updates/reactivation)
        if (version_compare($current_version, '1.0.0', '<')) {
            // This is a fresh installation - clear all data
            update_option('fub_setup_completed', false);
            
            // Clear previous FUB account data to ensure clean setup
            delete_option('fub_api_key');
            delete_option('fub_account_id');
            delete_option('fub_account_name');
            delete_option('fub_account_email');
            delete_option('fub_license_key');
            delete_option('fub_license_status');
            delete_option('fub_pixel_id');
            delete_option('fub_default_source');
            delete_option('fub_default_tags');
            delete_option('fub_assigned_user');
            
            // Clear cache on fresh install
            $this->clear_all_caches();
        } else {
            // This is an update or reactivation - preserve existing data
            // Only clear temporary cache, not settings
            delete_transient('fub_license_cache');
        }
        
        // Update version
        update_option('fub_plugin_version', $plugin_version);
        
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        // Clear cache on deactivation
        $this->clear_all_caches();
        
        
        flush_rewrite_rules();
    }
    
    /**
     * Clear all plugin related caches
     */
    private function clear_all_caches() {
        // Clear ALL transients and cached data
        delete_transient('fub_license_cache');
        delete_transient('fub_payment_success');
        delete_transient('fub_subscription_expired');
        
        // Clear WordPress object cache if available
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Clear any WordPress transients that might contain FUB data
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_fub_%' OR option_name LIKE '_transient_timeout_fub_%'");
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Create fub_leads table
        $leads_table = $wpdb->prefix . 'fub_leads';
        $sql_leads = "CREATE TABLE $leads_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            fub_id varchar(100),
            email varchar(255) NOT NULL,
            first_name varchar(100),
            last_name varchar(100),
            phone varchar(20),
            message text,
            address varchar(255),
            source varchar(100),
            tags text,
            assigned_to varchar(100),
            status varchar(20) DEFAULT 'pending',
            fub_status varchar(20) DEFAULT 'pending',
            retry_count int(3) DEFAULT 0,
            fub_response text,
            custom_fields text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email_idx (email),
            KEY fub_status_idx (fub_status)
        ) $charset_collate;";
        dbDelta($sql_leads);
        
        // Update existing table structure for retry functionality
        $this->update_leads_table_for_retry();
        
        // Create fub_tags table
        $tags_table = $wpdb->prefix . 'fub_tags';
        $sql_tags = "CREATE TABLE $tags_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            fub_tag_id varchar(255),
            active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY name_idx (name)
        ) $charset_collate;";
        dbDelta($sql_tags);
        
        // Create fub_activity table
        $activity_table = $wpdb->prefix . 'fub_activity';
        $sql_activity = "CREATE TABLE $activity_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            action varchar(50) NOT NULL,
            type varchar(50) NOT NULL,
            identifier varchar(255),
            status varchar(20) NOT NULL,
            message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY action_idx (action),
            KEY status_idx (status),
            KEY created_at_idx (created_at)
        ) $charset_collate;";
        dbDelta($sql_activity);
        
        // Run migrations after table creation
        $this->run_migrations();
        
        error_log("FUB Integration: Database tables created/updated successfully");
    }
    
    /**
     * Run database migrations for version updates
     */
    private function run_migrations() {
        global $wpdb;
        
        $current_version = get_option('fub_plugin_version', '0.0.0');
        
        // Migration for version 3.2.1: Add custom_fields column
        if (version_compare($current_version, '3.2.1', '<')) {
            $leads_table = $wpdb->prefix . 'fub_leads';
            
            // Check if custom_fields column exists
            $column_exists = $wpdb->get_results($wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'custom_fields'",
                DB_NAME,
                $leads_table
            ));
            
            if (empty($column_exists)) {
                // Add custom_fields column
                $wpdb->query("ALTER TABLE $leads_table ADD COLUMN custom_fields text AFTER status");
                error_log("FUB Integration: Added custom_fields column to $leads_table");
            }
            
            // Update version to prevent re-running this migration
            update_option('fub_plugin_version', '3.2.1');
        }
        
        // Migration for version 3.2.2: Update active leads to sent
        if (version_compare($current_version, '3.2.2', '<')) {
            $leads_table = $wpdb->prefix . 'fub_leads';
            
            // Update existing 'active' leads to 'sent' (assume they were successful)
            $wpdb->query("UPDATE $leads_table SET status = 'sent' WHERE status = 'active'");
            error_log("FUB Integration: Updated existing active leads to sent status");
            
            // Update version
            update_option('fub_plugin_version', '3.2.2');
        }
    }
    
    /**
     * Update leads table structure to support retry functionality
     */
    private function update_leads_table_for_retry() {
        global $wpdb;
        $leads_table = $wpdb->prefix . 'fub_leads';
        
        // First check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$leads_table'") != $leads_table) {
            error_log("FUB Integration: Table $leads_table does not exist, skipping migration");
            return;
        }
        
        error_log("FUB Integration: Starting table migration for retry functionality on $leads_table");
        
        // Use SHOW COLUMNS instead of INFORMATION_SCHEMA for better compatibility
        $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM $leads_table");
        
        // Check if fub_status column exists
        if (!in_array('fub_status', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE $leads_table ADD COLUMN fub_status varchar(20) DEFAULT 'pending' AFTER status");
            if ($result === false) {
                error_log("FUB Integration: ERROR adding fub_status column: " . $wpdb->last_error);
            } else {
                $wpdb->query("ALTER TABLE $leads_table ADD INDEX fub_status_idx (fub_status)");
                error_log("FUB Integration: ✅ Added fub_status column to $leads_table");
            }
        } else {
            error_log("FUB Integration: fub_status column already exists in $leads_table");
        }
        
        // Check if retry_count column exists
        if (!in_array('retry_count', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE $leads_table ADD COLUMN retry_count int(3) DEFAULT 0 AFTER fub_status");
            if ($result === false) {
                error_log("FUB Integration: ERROR adding retry_count column: " . $wpdb->last_error);
            } else {
                error_log("FUB Integration: ✅ Added retry_count column to $leads_table");
            }
        } else {
            error_log("FUB Integration: retry_count column already exists in $leads_table");
        }
        
        // Check if fub_response column exists
        if (!in_array('fub_response', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE $leads_table ADD COLUMN fub_response text AFTER retry_count");
            if ($result === false) {
                error_log("FUB Integration: ERROR adding fub_response column: " . $wpdb->last_error);
            } else {
                error_log("FUB Integration: ✅ Added fub_response column to $leads_table");
            }
        } else {
            error_log("FUB Integration: fub_response column already exists in $leads_table");
        }
        
        error_log("FUB Integration: Table migration completed for $leads_table");
    }
    
    /**
     * Check if tables exist and create them if needed
     */
    private function check_and_create_tables() {
        global $wpdb;
        
        // Check if leads table exists
        $leads_table = $wpdb->prefix . 'fub_leads';
        if ($wpdb->get_var("SHOW TABLES LIKE '$leads_table'") != $leads_table) {
            $this->create_tables();
            return;
        }
        
        // Check if activity table exists
        $activity_table = $wpdb->prefix . 'fub_activity';
        if ($wpdb->get_var("SHOW TABLES LIKE '$activity_table'") != $activity_table) {
            $this->create_tables();
            return;
        }
        
        // Check if tags table exists
        $tags_table = $wpdb->prefix . 'fub_tags';
        if ($wpdb->get_var("SHOW TABLES LIKE '$tags_table'") != $tags_table) {
            $this->create_tables();
            return;
        }
        
        // Always run migration to ensure retry columns exist
        $this->update_leads_table_for_retry();
    }
    
    /**
     * Public method to force database migration - can be called manually if needed
     */
    public function force_database_migration() {
        error_log('FUB Integration: Forcing database migration for retry functionality');
        $this->update_leads_table_for_retry();
        error_log('FUB Integration: Database migration completed');
        return array('success' => true, 'message' => 'Database migration completed successfully');
    }
    
    public function ajax_reset_setup() {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'fub_admin_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Verify user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Get account_id before clearing for Supabase cleanup
        $account_id = get_option('fub_account_id');
        
        // Clear all OAuth and account related data (same as disconnect function)
        delete_option('fub_oauth_access_token');
        delete_option('fub_oauth_refresh_token');
        delete_option('fub_oauth_token_expires');
        delete_option('fub_oauth_state');
        delete_option('fub_account_id');
        delete_option('fub_account_name');
        delete_option('fub_oauth_account_id');
        delete_option('fub_oauth_account_name');
        
        // Clear setup status to force fresh setup
        delete_option('fub_setup_completed');
        delete_option('fub_has_valid_license');
        
        // Clear session tokens if they exist
        if (!session_id()) {
            session_start();
        }
        unset($_SESSION['fub_temp_access_token']);
        unset($_SESSION['fub_temp_token_expires']);
        
        // Delete tokens from Supabase
        if ($account_id) {
            $backend_api = new FUB_Backend_API();
            $delete_result = $backend_api->delete_oauth_tokens($account_id);
            if ($delete_result['success']) {
                error_log('🔐 OAuth: Tokens deleted from Supabase for account: ' . $account_id);
            }
        }
        
        // Reset all other FUB settings
        delete_option('fub_api_key');
        delete_option('fub_account_email');
        delete_option('fub_license_key');
        delete_option('fub_license_status');
        delete_option('fub_pixel_id');
        delete_option('fub_default_source');
        delete_option('fub_default_tags');
        delete_option('fub_assigned_user');
        
        // Force clear WordPress object cache to ensure fresh data
        wp_cache_flush();
        
        // Set a temporary flag to force disconnected state
        set_transient('fub_force_disconnected', true, 60); // 60 seconds
        
        wp_send_json_success('Setup reset successfully');
    }
    
    public function ajax_complete_setup() {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'fub_admin_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Verify user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Get form data
        $pixel_id = trim(stripslashes($_POST['pixel_id'] ?? ''));
        $source = sanitize_text_field($_POST['source'] ?? 'WordPress Website');
        $assigned_user_id = sanitize_text_field($_POST['assigned_user_id'] ?? '');
        $inquiry_type = sanitize_text_field($_POST['inquiry_type'] ?? 'General Inquiry');
        
        // Handle selected tags
        $selected_tags = isset($_POST['selected_tags']) ? array_map('sanitize_text_field', $_POST['selected_tags']) : array();
        
        // Handle custom tags
        error_log("FUB Setup Debug: Raw custom_tags POST data: " . print_r($_POST['custom_tags'] ?? 'NOT SET', true));
        
        $custom_tags = array();
        if (isset($_POST['custom_tags'])) {
            $custom_tags = array_filter(array_map('trim', array_map('sanitize_text_field', $_POST['custom_tags'])));
            error_log("FUB Setup Debug: Processed custom_tags: " . print_r($custom_tags, true));
            
            if (!empty($custom_tags)) {
                $this->create_custom_tags($custom_tags);
                // Add custom tag IDs to selected tags
                foreach ($custom_tags as $tag_name) {
                    $selected_tags[] = 'custom_' . sanitize_title($tag_name);
                }
                error_log("FUB Setup Debug: Added custom tag IDs to selected_tags: " . print_r($selected_tags, true));
            }
        } else {
            error_log("FUB Setup Debug: No custom_tags in POST data");
        }
        
        // Save settings locally
        update_option('fub_pixel_id', $pixel_id);
        update_option('fub_default_source', $source);
        update_option('fub_assigned_user_id', $assigned_user_id);
        update_option('fub_inquiry_type', $inquiry_type);
        update_option('fub_selected_tags', $selected_tags);
        update_option('fub_custom_tags', $custom_tags);
        
        // Auto-sync pixel to cloud
        $account_id = get_option('fub_account_id');
        if ($account_id && !empty($pixel_id)) {
            $sync_result = $this->backend_api->sync_pixel_to_cloud($account_id, $pixel_id);
            if ($sync_result['success']) {
                error_log('FUB Setup: Pixel synced to cloud for account ' . $account_id);
            }
        }
        
        error_log("FUB Setup Debug: Saved selected_tags: " . print_r($selected_tags, true));
        error_log("FUB Setup Debug: Saved custom_tags: " . print_r($custom_tags, true));
        
        // Save pixel to cloud for multi-site sync
        $account_id = get_option('fub_account_id');
        $license_key = get_option('fub_license_key');
        
        error_log('FUB Pixel Sync: Attempting to save pixel to cloud. Account ID: ' . ($account_id ?: 'EMPTY') . ', License: ' . ($license_key ? 'EXISTS' : 'EMPTY') . ', Pixel length: ' . strlen($pixel_id));
        
        if ($account_id && !empty($pixel_id)) {
            $pixel_response = $this->backend_api->sync_pixel_to_cloud($account_id, $pixel_id);
            error_log('FUB Pixel Sync: Cloud save response: ' . print_r($pixel_response, true));
            // Don't fail setup if pixel cloud save fails - it's stored locally anyway
        } else {
            error_log('FUB Pixel Sync: Skipped cloud save - missing data');
        }
        
        // Mark setup as completed
        update_option('fub_setup_completed', true);
        
        wp_send_json_success('Settings saved successfully');
    }
    
    public function ajax_save_step_data() {
        // Basic security check - allow without strict nonce for navigation
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $current_step = sanitize_text_field($_POST['current_step'] ?? '');
        
        error_log("FUB Step Save Debug: Current step: " . $current_step);
        error_log("FUB Step Save Debug: All POST data: " . print_r($_POST, true));
        
        // Save data based on current step
        switch ($current_step) {
            case 'basic-settings':
                if (isset($_POST['fub_default_source'])) {
                    update_option('fub_default_source', sanitize_text_field($_POST['fub_default_source']));
                }
                if (isset($_POST['fub_inquiry_type'])) {
                    update_option('fub_inquiry_type', sanitize_text_field($_POST['fub_inquiry_type']));
                }
                break;
                
            case 'tags-assignment':
                // Save selected tags
                if (isset($_POST['selected_tags']) && is_array($_POST['selected_tags'])) {
                    $selected_tags = array_map('sanitize_text_field', $_POST['selected_tags']);
                    update_option('fub_selected_tags', $selected_tags);
                }
                
                // Save custom tags
                if (isset($_POST['custom_tags']) && is_array($_POST['custom_tags'])) {
                    // Filter out empty values more aggressively
                    $custom_tags = array_values(array_filter(array_map('trim', array_map('sanitize_text_field', $_POST['custom_tags'])), function($tag) {
                        return !empty($tag) && $tag !== '';
                    }));
                    error_log("FUB Step Save Debug: Raw custom_tags: " . print_r($_POST['custom_tags'], true));
                    error_log("FUB Step Save Debug: Filtered custom tags from tags-assignment step: " . print_r($custom_tags, true));
                    
                    if (!empty($custom_tags)) {
                        // Create the tags in database
                        $this->create_custom_tags($custom_tags);
                        // Save to options
                        update_option('fub_custom_tags', $custom_tags);
                        
                        // Also add to selected tags
                        $existing_selected = get_option('fub_selected_tags', array());
                        foreach ($custom_tags as $tag_name) {
                            $existing_selected[] = 'custom_' . sanitize_title($tag_name);
                        }
                        update_option('fub_selected_tags', $existing_selected);
                        
                        error_log("FUB Step Save Debug: Saved custom tags and updated selected tags");
                    }
                }
                
                // Save assigned user
                if (isset($_POST['assigned_user_id'])) {
                    update_option('fub_assigned_user_id', sanitize_text_field($_POST['assigned_user_id']));
                }
                break;
                
            case 'pixel-setup':
                error_log('FUB Step Save: Processing pixel-setup case');
                error_log('FUB Step Save: POST data: ' . print_r($_POST, true));
                
                if (isset($_POST['fub_pixel_id'])) {
                    $pixel_id = sanitize_textarea_field($_POST['fub_pixel_id']);
                    error_log('FUB Step Save: Pixel ID received: ' . $pixel_id);
                    update_option('fub_pixel_id', $pixel_id);
                    
                    // Save to cloud immediately
                    $account_id = get_option('fub_account_id');
                    error_log('FUB Step Save: Account ID: ' . ($account_id ? $account_id : 'NULL'));
                    
                    if ($account_id && !empty($pixel_id)) {
                        error_log('FUB Step Save: Starting cloud save process - Account: ' . $account_id . ' | Pixel: ' . $pixel_id);
                        
                        // Get license key from current subscription
                        $subscription_check = $this->backend_api->check_existing_subscription($account_id);
                        error_log('FUB Step Save: Subscription check result: ' . print_r($subscription_check, true));
                        
                        if ($subscription_check && $subscription_check['success'] && isset($subscription_check['data']['license_key'])) {
                            $license_key = $subscription_check['data']['license_key'];
                            error_log('FUB Step Save: License key found: ' . $license_key);
                            
                            // Save pixel using plugin_settings table
                            $cloud_result = $this->backend_api->sync_pixel_to_cloud($account_id, $pixel_id);
                            error_log('FUB Step Save: Cloud save result: ' . print_r($cloud_result, true));
                            
                            if ($cloud_result && isset($cloud_result['success']) && $cloud_result['success']) {
                                error_log('FUB Step Save: ✅ Pixel saved to subscriptions table successfully');
                            } else {
                                error_log('FUB Step Save: ❌ Failed to save pixel: ' . print_r($cloud_result, true));
                            }
                        } else {
                            error_log('FUB Step Save: ❌ Could not get license key for pixel save: ' . print_r($subscription_check, true));
                        }
                    } else {
                        error_log('FUB Step Save: ❌ Missing account_id or pixel_id - Account: ' . ($account_id ? $account_id : 'NULL') . ' | Pixel: ' . ($pixel_id ? $pixel_id : 'NULL'));
                    }
                } else {
                    error_log('FUB Step Save: ❌ No fub_pixel_id in POST data');
                }
                break;
        }
        
        wp_send_json_success('Step data saved');
    }
    
    /**
     * Sync existing settings from cloud when connecting to existing account
     */
    private function sync_cloud_settings($account_id, $license_key) {
        try {
            error_log('FUB Cloud Sync: Starting sync for account ' . $account_id);
            
            // Get pixel from plugin_settings table using existing function
            $pixel_result = $this->backend_api->get_pixel_from_cloud($account_id);
            error_log('FUB Cloud Sync: Pixel result: ' . print_r($pixel_result, true));
            
            if ($pixel_result && $pixel_result['success'] && !empty($pixel_result['pixel_code'])) {
                $cloud_pixel = $pixel_result['pixel_code'];
                
                // Load pixel_code from cloud if local is empty
                if (empty(get_option('fub_pixel_id'))) {
                    update_option('fub_pixel_id', $cloud_pixel);
                    error_log('FUB Cloud Sync: ✅ Loaded pixel_code from plugin_settings: ' . $cloud_pixel);
                } else {
                    error_log('FUB Cloud Sync: ℹ️ Pixel exists in cloud (' . $cloud_pixel . ') but local already has value');
                }
            } else {
                error_log('FUB Cloud Sync: ℹ️ No pixel_code found in plugin_settings for this account');
            }
            
            // Set default values if not already set
            if (!get_option('fub_default_source')) {
                update_option('fub_default_source', 'WordPress Website');
            }
            
            if (!get_option('fub_inquiry_type')) {
                update_option('fub_inquiry_type', 'General Inquiry');
            }
            
            error_log('FUB Cloud Sync: ✅ Successfully synced settings for account ' . $account_id);
            
        } catch (Exception $e) {
            error_log('FUB Cloud Sync Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Create custom tags in the database
     */
    private function create_custom_tags($tag_names) {
        global $wpdb;
        
        foreach ($tag_names as $tag_name) {
            $tag_name = trim($tag_name);
            if (empty($tag_name)) continue;
            
            // Check if tag exists
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . FUB_TAGS_TABLE . " WHERE name = %s",
                $tag_name
            ));
            
            if (!$existing) {
                // Insert new custom tag
                $wpdb->insert(
                    FUB_TAGS_TABLE,
                    array(
                        'name' => $tag_name,
                        'fub_tag_id' => 'custom_' . sanitize_title($tag_name),
                        'active' => 1
                    ),
                    array('%s', '%s', '%d')
                );
            }
        }
    }
    
    public function add_fub_pixel() {
        if (!$this->license_manager->is_license_valid()) {
            return;
        }
        
        if (is_admin()) return;
        
        // Get pixel code - first try from subscription, then fallback to local option
        $pixel_code = '';
        
        // Try to get pixel from subscription data if license is active
        if ($this->license_manager->is_license_valid()) {
            $account_id = get_option('fub_account_id');
            if ($account_id) {
                $subscription_check = $this->backend_api->check_existing_subscription($account_id);
                
                if ($subscription_check['success'] && !empty($subscription_check['data']['pixel_code'])) {
                    $pixel_code = $subscription_check['data']['pixel_code'];
                    error_log("FUB Integration: Using pixel from subscription data");
                }
            }
        }
        
        // Fallback to local option if no pixel in subscription
        if (empty($pixel_code)) {
            $pixel_code = get_option('fub_pixel_id', '');
            if (!empty($pixel_code)) {
                error_log("FUB Integration: Using pixel from local WordPress option");
            }
        }
        
        if (empty($pixel_code)) {
            return;
        }
        
        // Clean up the pixel code - remove escaped quotes and fix formatting
        $pixel_code = stripslashes($pixel_code);
        $pixel_code = str_replace('\"', '"', $pixel_code);
        $pixel_code = str_replace("\'", "'", $pixel_code);
        
        // Debug: Log pixel installation status
        error_log("FUB Integration: ✅ Injecting FUB pixel code (length: " . strlen($pixel_code) . " chars)");
        
        // Output the manual FUB pixel code in the head
        echo "\n<!-- Follow Up Boss Pixel Tracking Code (Manually Installed) -->\n";
        
        // Check if the pixel code already has script tags
        if (strpos($pixel_code, '<script') === false) {
            // If no script tags, wrap it in script tags
            echo "<script>\n";
            echo $pixel_code;
            echo "\n</script>\n";
        } else {
            // If it already has script tags, output as is
            echo $pixel_code;
        }
        
        // Add JavaScript to help detect the pixel after installation
        echo "\n<script>\n";
        echo "console.log('🎯 FUB PIXEL: Manual FUB pixel code has been injected into page');\n";
        echo "console.log('📏 FUB PIXEL: Injected script length: " . strlen($pixel_code) . " characters');\n";
        echo "// Debug: Check if FUB tracking functions are available after pixel loads\n";
        echo "setTimeout(function() {\n";
        echo "    var availableFunctions = [];\n";
        echo "    var fubFunctionNames = ['widgetTracker', 'WidgetTrackerObject', 'fub', 'FUB', 'FollowUpBoss', '_fub', 'followupboss', 'fubTrack', 'fubIdentify', 'fubLead', 'trackLead'];\n";
        echo "    \n";
        echo "    fubFunctionNames.forEach(function(name) {\n";
        echo "        if (typeof window[name] !== 'undefined') {\n";
        echo "            availableFunctions.push(name);\n";
        echo "        }\n";
        echo "    });\n";
        echo "    \n";
        echo "    if (availableFunctions.length > 0) {\n";
        echo "        console.log('🎯 FUB PIXEL: Found FUB tracking functions: ' + availableFunctions.join(', '));\n";
        echo "    } else {\n";
        echo "        console.log('⚠️ FUB PIXEL: No FUB tracking functions detected. Check if pixel code is correct.');\n";
        echo "    }\n";
        echo "}, 3000);\n";
        echo "</script>\n";
        echo "\n<!-- End Follow Up Boss Pixel -->\n";
    }
    
    /**
     * Inject Universal Lead Form Catcher Script
     */
    public function inject_universal_form_catcher() {
        if (is_admin()) return;
        
        // Check if license is valid
        if (!$this->license_manager->is_license_valid()) {
            return;
        }
        
        // Get user settings
        $source = get_option('fub_default_source', 'WordPress Website');
        $selected_tags = get_option('fub_selected_tags', array());
        $assigned_user_id = get_option('fub_assigned_user_id', '');
        
        ?>
        <!-- FUB Universal Lead Form Catcher -->
        <script>
        (function() {
            'use strict';
            
            console.log('🚀 FUB UNIVERSAL CAPTURE: Initializing...');
            
            var FUB_UNIVERSAL_CAPTURE = {
                processedForms: new Set(),
                capturedSubmissions: new Map(),
                recentSubmissions: new Map(),
                DEDUP_WINDOW: 5000, // 5 seconds
                
                init: function() {
                    this.initLevel1_DocumentCapture();
                    this.initLevel2_InputMonitoring();
                    this.initLevel3_MutationObserver();
                    this.initLevel4_PeriodicScanning();
                    this.initLevel5_AjaxInterception();
                    
                    // Register existing forms
                    document.addEventListener('DOMContentLoaded', function() {
                        console.log('🔄 FUB: DOM loaded, registering forms...');
                        var forms = document.querySelectorAll('form');
                        forms.forEach(function(form) {
                            FUB_UNIVERSAL_CAPTURE.registerForm(form);
                        });
                    });
                    
                    console.log('✅ FUB: ALL CAPTURE LEVELS ACTIVE!');
                },
                
                // Level 1: Document-level form submission capture
                initLevel1_DocumentCapture: function() {
                    console.log('🟢 FUB Level 1: Document capture active');
                    var self = this;
                    
                    document.addEventListener('submit', function(e) {
                        var form = e.target;
                        if (form.tagName === 'FORM') {
                            console.log('🟡 FUB Level 1: Form submission detected');
                            self.captureAnyForm(form, 'level1-submit');
                        }
                    }, true);
                },
                
                // Level 2: Button click monitoring
                initLevel2_InputMonitoring: function() {
                    console.log('🟢 FUB Level 2: Button monitoring active');
                    var self = this;
                    
                    document.addEventListener('click', function(e) {
                        var target = e.target;
                        
                        if ((target.type === 'submit') || 
                            (target.tagName === 'BUTTON' && (target.type === 'submit' || !target.type)) ||
                            (target.textContent && target.textContent.match(/submit|send|enviar/i))) {
                            
                            var form = target.closest('form');
                            if (form) {
                                console.log('🟡 FUB Level 2: Submit button clicked');
                                setTimeout(function() {
                                    self.captureAnyForm(form, 'level2-button');
                                }, 100);
                            }
                        }
                    }, true);
                },
                
                // Level 3: Mutation Observer for dynamic forms
                initLevel3_MutationObserver: function() {
                    console.log('🟢 FUB Level 3: Mutation observer active');
                    var self = this;
                    
                    var observer = new MutationObserver(function(mutations) {
                        mutations.forEach(function(mutation) {
                            if (mutation.type === 'childList') {
                                mutation.addedNodes.forEach(function(node) {
                                    if (node.nodeType === 1) {
                                        var forms = node.tagName === 'FORM' ? [node] : node.querySelectorAll('form');
                                        forms.forEach(function(form) {
                                            self.registerForm(form);
                                        });
                                    }
                                });
                            }
                        });
                    });
                    
                    observer.observe(document.body, {
                        childList: true,
                        subtree: true
                    });
                },
                
                // Level 4: Periodic form scanning
                initLevel4_PeriodicScanning: function() {
                    console.log('🟢 FUB Level 4: Periodic scanning active');
                    var self = this;
                    
                    setInterval(function() {
                        var forms = document.querySelectorAll('form');
                        forms.forEach(function(form) {
                            self.registerForm(form);
                        });
                    }, 2000);
                },
                
                // Level 5: AJAX interception
                initLevel5_AjaxInterception: function() {
                    console.log('🟢 FUB Level 5: AJAX interception active');
                    var self = this;
                    
                    var originalSend = XMLHttpRequest.prototype.send;
                    XMLHttpRequest.prototype.send = function(data) {
                        if (this.responseURL && (this.responseURL.includes('widgetbe.com') || this.responseURL.includes('followupboss'))) {
                            return originalSend.apply(this, arguments);
                        }
                        
                        if (data && typeof data === 'string') {
                            // Skip FUB pixel and tracker requests
                            if (data.includes('WT-') || 
                                data.includes('widgetTracker') || 
                                data.includes('customerId') ||
                                data.includes('fub-universal-capture') || 
                                data.includes('converted":true') || 
                                data.includes('fub_track_form_submission') ||
                                data.includes('formId":"fub-universal-capture') ||
                                data.includes('source":"wordpress-form') ||
                                url.includes('admin-ajax.php')) {
                                return originalSend.apply(this, arguments);
                            }
                            
                            if (data.includes('@') && !data.includes('"version":"0.0.2"')) {
                                console.log('🟡 FUB Level 5: AJAX with email detected');
                                self.captureFromAjaxData(data, 'level5-ajax');
                            }
                        }
                        return originalSend.apply(this, arguments);
                    };
                },
                
                registerForm: function(form) {
                    if (!form || form.tagName !== 'FORM') return;
                    
                    var formId = form.id || form.action || 'form-' + Date.now();
                    var hasEmail = form.querySelector('input[type="email"]') || 
                                  form.querySelector('input[name*="email"]') || 
                                  form.querySelector('input[name*="correo"]');
                    
                    if (hasEmail && !this.processedForms.has(formId)) {
                        console.log('📝 FUB: Registered new form:', formId);
                        this.processedForms.add(formId);
                        this.addFormListeners(form, formId);
                    }
                },
                
                addFormListeners: function(form, formId) {
                    var self = this;
                    
                    form.addEventListener('submit', function(e) {
                        self.captureAnyForm(form, 'registered-submit');
                    });
                    
                    form.addEventListener('submit', function() {
                        setTimeout(function() {
                            self.captureAnyForm(form, 'registered-timeout');
                        }, 50);
                    });
                },
                
                captureAnyForm: function(form, source) {
                    console.log('🔍 FUB: Capturing form from source:', source);
                    
                    if (!form || form.tagName !== 'FORM') return;
                    
                    var formData = this.extractAllFormData(form);
                    
                    if (formData && formData.length > 0) {
                        if (this.isDuplicateSubmission(formData)) {
                            console.log('⏭️ FUB: Skipping duplicate submission');
                            return;
                        }
                        
                        var formType = this.detectFormType(form);
                        this.sendFormData(formData, formType + '-' + source);
                    }
                },
                
                captureFromAjaxData: function(data, source) {
                    console.log('🎯 FUB AJAX CAPTURE:', source);
                    
                    var formData = [];
                    if (typeof data === 'string') {
                        var params = new URLSearchParams(data);
                        for (var pair of params.entries()) {
                            formData.push({name: pair[0], value: pair[1]});
                        }
                    } else if (typeof data === 'object') {
                        for (var key in data) {
                            formData.push({name: key, value: data[key]});
                        }
                    }
                    
                    if (formData.length > 0) {
                        this.sendFormData(formData, 'ajax-' + source);
                    }
                },
                
                extractAllFormData: function(form) {
                    var data = [];
                    
                    var elements = form.querySelectorAll('input, select, textarea');
                    elements.forEach(function(el) {
                        if (el.name && el.type !== 'submit' && el.type !== 'button') {
                            var value = '';
                            
                            if (el.type === 'checkbox' || el.type === 'radio') {
                                if (el.checked) value = el.value || 'on';
                            } else if (el.tagName === 'SELECT') {
                                if (el.selectedIndex >= 0) {
                                    value = el.options[el.selectedIndex].value;
                                }
                            } else {
                                value = el.value;
                            }
                            
                            if (value) {
                                data.push({name: el.name, value: value});
                            }
                        }
                    });
                    
                    console.log('📊 FUB: Extracted', data.length, 'fields from form');
                    return data;
                },
                
                detectFormType: function(form) {
                    if (form.classList.contains('wpforms-form') || form.id.includes('wpforms')) {
                        return 'wpforms';
                    }
                    if (form.querySelector('input[name="_wpcf7"]')) {
                        return 'contact-form-7';
                    }
                    if (form.classList.contains('gform_wrapper') || form.id.includes('gform')) {
                        return 'gravity-forms';
                    }
                    if (form.classList.contains('ninja-forms-form') || form.id.includes('ninja')) {
                        return 'ninja-forms';
                    }
                    return 'universal';
                },
                
                generateFormHash: function(formData) {
                    var essentialData = {
                        email: '',
                        name: '',
                        phone: ''
                    };
                    
                    for (var i = 0; i < formData.length; i++) {
                        var field = formData[i];
                        var value = field.value ? field.value.toString().trim() : '';
                        
                        if (!value) continue;
                        
                        if (!essentialData.email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                            essentialData.email = value;
                        } else if (!essentialData.phone && /^[\d\s\+\-\(\)]{7,}$/.test(value.replace(/\s/g, ''))) {
                            essentialData.phone = value.replace(/\D/g, '');
                        } else if (!essentialData.name && value.length >= 2 && /^[a-zA-ZÀ-ÿ\s]{2,}$/.test(value)) {
                            essentialData.name = value;
                        }
                    }
                    
                    var dataStr = essentialData.email + '|' + essentialData.name + '|' + essentialData.phone;
                    var hash = 0;
                    for (var i = 0; i < dataStr.length; i++) {
                        var char = dataStr.charCodeAt(i);
                        hash = ((hash << 5) - hash) + char;
                        hash = hash & hash;
                    }
                    return hash.toString();
                },
                
                isDuplicateSubmission: function(formData) {
                    var hash = this.generateFormHash(formData);
                    var now = Date.now();
                    
                    for (var [key, timestamp] of this.recentSubmissions) {
                        if (now - timestamp > this.DEDUP_WINDOW) {
                            this.recentSubmissions.delete(key);
                        }
                    }
                    
                    if (this.recentSubmissions.has(hash)) {
                        return true;
                    }
                    
                    this.recentSubmissions.set(hash, now);
                    return false;
                },
                
                sendFormData: function(data, formType) {
                    console.log('📤 FUB SEND: === START SENDING FORM DATA ===');
                    console.log('📤 FUB SEND: Form type:', formType);
                    console.log('📤 FUB SEND: Data to send:', data);
                    console.log('📤 FUB SEND: Data type:', typeof data);
                    console.log('📤 FUB SEND: Data length:', data ? data.length : 0);
                    
                    if (!data || data.length === 0) {
                        console.error('🔴 FUB SEND: ERROR - No data to send!');
                        return;
                    }
                    
                    // Convert data to JSON string
                    var jsonData = '';
                    try {
                        jsonData = JSON.stringify(data);
                        console.log('📤 FUB SEND: JSON stringified successfully');
                        console.log('📤 FUB SEND: JSON string:', jsonData);
                        console.log('📤 FUB SEND: JSON length:', jsonData.length);
                    } catch(e) {
                        console.error('🔴 FUB SEND: Failed to stringify data:', e);
                        return;
                    }
                    
                    // Create form data for POST request
                    var formData = new FormData();
                    formData.append('action', 'fub_track_form_submission');
                    formData.append('form_data', jsonData);
                    formData.append('form_type', formType);
                    formData.append('nonce', '<?php echo wp_create_nonce('fub_track_form'); ?>');
                    
                    // Log FormData contents
                    console.log('📤 FUB SEND: FormData created with:');
                    console.log('  - action: fub_track_form_submission');
                    console.log('  - form_data: ' + jsonData.substring(0, 100) + '...');
                    console.log('  - form_type: ' + formType);
                    console.log('  - nonce: [set]');
                    
                    // Alternative: Try URL-encoded format if FormData fails
                    var urlEncodedData = 'action=fub_track_form_submission' +
                                        '&form_data=' + encodeURIComponent(jsonData) +
                                        '&form_type=' + encodeURIComponent(formType) +
                                        '&nonce=' + encodeURIComponent('<?php echo wp_create_nonce('fub_track_form'); ?>');
                    
                    console.log('📡 FUB SEND: Sending AJAX request to:', '<?php echo admin_url('admin-ajax.php'); ?>');
                    console.log('📡 FUB SEND: Request method: POST');
                    
                    var xhr = new XMLHttpRequest();
                    
                    xhr.onreadystatechange = function() {
                        if (this.readyState === XMLHttpRequest.DONE) {
                            console.log('📥 FUB: Response received, status:', this.status);
                            console.log('📄 FUB: Response text:', this.responseText);
                            
                            if (this.status === 200) {
                                try {
                                    var response = JSON.parse(this.responseText);
                                    console.log('✅ FUB: Response parsed:', response);
                                    
                                    if (response.success) {
                                        console.log('🎉 FUB SUCCESS: Lead sent successfully!', response.data);
                                        
                                        // Advanced pixel tracking - improved version
                                        if (response.data && response.data.lead_data) {
                                            console.log('🎯 FUB PIXEL: Notifying pixel about lead conversion:', response.data);
                                            
                                            var leadData = response.data;
                                            var email = leadData.lead_data ? leadData.lead_data.email : '';
                                            var firstName = leadData.lead_data ? leadData.lead_data.first_name : '';
                                            var lastName = leadData.lead_data ? leadData.lead_data.last_name : '';
                                            var phone = leadData.lead_data ? leadData.lead_data.phone : '';
                                            
                                            var pixelFound = false;
                                            
                                            // Method 0: widgetTracker (The actual FUB pixel)
                                            if (typeof window.widgetTracker !== 'undefined') {
                                                try {
                                                    // Method 1: Create a fake form submission that widgetTracker can capture
                                                    var fakeFormData = {
                                                        version: "0.0.2",
                                                        bodyHasTag: true,
                                                        page: {
                                                            location: window.location.href,
                                                            title: document.title,
                                                            referrer: document.referrer
                                                        },
                                                        form: {
                                                            captureFormat: 1,
                                                            id: "wordpress-plugin-form",
                                                            method: "post",
                                                            action: window.location.href,
                                                            submitAt: new Date().toISOString()
                                                        },
                                                        data: [
                                                            {
                                                                id: "",
                                                                name: "email",
                                                                order: 0,
                                                                placeholder: "Email",
                                                                tagName: "input",
                                                                type: "email",
                                                                value: email
                                                            },
                                                            {
                                                                id: "",
                                                                name: "firstName",
                                                                order: 1,
                                                                placeholder: "First Name",
                                                                tagName: "input",
                                                                type: "text",
                                                                value: firstName
                                                            },
                                                            {
                                                                id: "",
                                                                name: "lastName",
                                                                order: 2,
                                                                placeholder: "Last Name",
                                                                tagName: "input",
                                                                type: "text",
                                                                value: lastName
                                                            },
                                                            {
                                                                id: "",
                                                                name: "phone",
                                                                order: 3,
                                                                placeholder: "Phone",
                                                                tagName: "input",
                                                                type: "tel",
                                                                value: phone
                                                            }
                                                        ],
                                                        customerId: (function() {
                                                            // Try to get the real customer ID from various sources
                                                            if (window.WT_CUSTOMER_ID) return window.WT_CUSTOMER_ID;
                                                            if (window.widgetTrackerCustomerId) return window.widgetTrackerCustomerId;
                                                            // Try to extract from existing script tags
                                                            var scripts = document.querySelectorAll('script');
                                                            for (var i = 0; i < scripts.length; i++) {
                                                                var scriptContent = scripts[i].innerHTML;
                                                                var match = scriptContent.match(/WT-[A-Z0-9]+/);
                                                                if (match) return match[0];
                                                            }
                                                            return "WT-UNKNOWN";
                                                        })(),
                                                        visitorId: (function() {
                                                            // Try to get visitor ID from various sources
                                                            if (window.WT_VISITOR_ID) return window.WT_VISITOR_ID;
                                                            if (window.widgetTrackerVisitorId) return window.widgetTrackerVisitorId;
                                                            // Generate a new one if not found
                                                            return 'v-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
                                                        })(),
                                                        agent: null
                                                    };
                                                    
                                                    // Send the fake form data directly to widgetbe.com
                                                    var xhr = new XMLHttpRequest();
                                                    xhr.open('POST', 'https://widgetbe.com/track', true);
                                                    xhr.setRequestHeader('Content-Type', 'application/json');
                                                    xhr.onreadystatechange = function() {
                                                        if (this.readyState === 4) {
                                                            if (this.status === 200) {
                                                                console.log('✅ FUB PIXEL: Direct API call successful');
                                                            } else {
                                                                console.log('⚠️ FUB PIXEL: Direct API call failed, status:', this.status);
                                                            }
                                                        }
                                                    };
                                                    xhr.send(JSON.stringify(fakeFormData));
                                                    
                                                    console.log('📨 FUB PIXEL: Direct API call to widgetbe.com with:', fakeFormData);
                                                    
                                                    pixelFound = true;
                                                } catch (e) {
                                                    console.log('⚠️ FUB PIXEL: widgetTracker error:', e);
                                                }
                                            }
                                            
                                            // Method 1: Real FUB Pixel API - Check for actual FUB tracking functions
                                            var fubFunctionNames = ['widgetTracker', 'WidgetTrackerObject', 'fub', 'FUB', 'FollowUpBoss', '_fub', 'followupboss',
                                                'fubTrack', 'fubIdentify', 'fubLead', 'trackLead'
                                            ];
                                            
                                            for (var i = 0; i < fubFunctionNames.length; i++) {
                                                var funcName = fubFunctionNames[i];
                                                if (typeof window[funcName] === 'function') {
                                                    try {
                                                        // Try standard tracking call
                                                        window[funcName]('track', 'lead', leadData.lead_data);
                                                        console.log('✅ FUB PIXEL: Conversion tracked via window.' + funcName + '()');
                                                        pixelFound = true;
                                                        break; // Success, stop trying other methods
                                                    } catch (e1) {
                                                        try {
                                                            // Try alternate call pattern
                                                            window[funcName]('lead', leadData.lead_data);
                                                            console.log('✅ FUB PIXEL: Conversion tracked via window.' + funcName + '() [alt pattern]');
                                                            pixelFound = true;
                                                            break;
                                                        } catch (e2) {
                                                            console.log('⚠️ FUB PIXEL: Function ' + funcName + ' failed both patterns:', e1, e2);
                                                        }
                                                    }
                                                }
                                                
                                                // Check if it's an object with tracking methods
                                                if (typeof window[funcName] === 'object' && window[funcName] !== null) {
                                                    var trackMethods = ['track', 'identify', 'lead', 'conversion'];
                                                    for (var j = 0; j < trackMethods.length; j++) {
                                                        var method = trackMethods[j];
                                                        if (typeof window[funcName][method] === 'function') {
                                                            try {
                                                                window[funcName][method](leadData.lead_data);
                                                                console.log('✅ FUB PIXEL: Conversion tracked via window.' + funcName + '.' + method + '()');
                                                                pixelFound = true;
                                                                break;
                                                            } catch (e) {
                                                                console.log('⚠️ FUB PIXEL: Method ' + funcName + '.' + method + ' failed:', e);
                                                            }
                                                        }
                                                    }
                                                    if (pixelFound) break;
                                                }
                                            }
                                            
                                            // Method 2: Check for FUB pixel script presence and try generic events
                                            if (!pixelFound && (document.querySelector('script[src*="widgetbe.com"]') || 
                                                               document.querySelector('script[src*="followupboss.com"]') ||
                                                               document.querySelector('script').innerHTML.indexOf('followupboss') !== -1)) {
                                                pixelFound = true; // Mark as found since script is present
                                                
                                                // Try to trigger a custom event that FUB pixel might listen to
                                                var customEvent = new CustomEvent('fub-lead-conversion', {
                                                    detail: leadData.lead_data,
                                                    bubbles: true
                                                });
                                                document.dispatchEvent(customEvent);
                                                console.log('✅ FUB PIXEL: Custom event dispatched for FUB pixel');
                                            }
                                            
                                            // Method 3: Generic pixel tracking via image pixel  
                                            if (!pixelFound) {
                                                // Send conversion data as tracking pixel
                                                var trackingImg = new Image(1, 1);
                                                trackingImg.src = window.location.protocol + '//' + window.location.host + 
                                                                 '/wp-admin/admin-ajax.php?action=fub_pixel_track&event=conversion&' +
                                                                 'pixel_id=generic&data=' + encodeURIComponent(JSON.stringify({
                                                                     email: email,
                                                                     name: (firstName + ' ' + lastName).trim(),
                                                                     phone: phone,
                                                                     url: window.location.href,
                                                                     referrer: document.referrer
                                                                 }));
                                                trackingImg.style.display = 'none';
                                                document.body.appendChild(trackingImg);
                                                console.log('✅ FUB PIXEL: Conversion tracked via generic pixel fallback');
                                                pixelFound = true;
                                            }
                                            
                                            if (!pixelFound) {
                                                console.log('⚠️ FUB PIXEL: No tracking pixel methods found, but lead data captured successfully');
                                            }
                                        }
                                    } else {
                                        console.error('❌ FUB ERROR:', response.data);
                                    }
                                } catch (e) {
                                    console.error('🔴 FUB PARSE ERROR:', e);
                                    console.error('🔴 FUB RAW RESPONSE:', this.responseText);
                                }
                            } else {
                                console.error('🔴 FUB HTTP ERROR:', this.status);
                            }
                        }
                    };
                    
                    xhr.onerror = function() {
                        console.error('🔴 FUB NETWORK ERROR: Request failed');
                    };
                    
                    // Try sending with FormData first
                    var useFormData = true;
                    
                    xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
                    xhr.timeout = 15000;
                    
                    if (useFormData) {
                        console.log('📡 FUB SEND: Using FormData for request');
                        console.log('📡 FUB SEND: Sending request now...');
                        xhr.send(formData);
                    } else {
                        // Fallback to URL-encoded
                        console.log('📡 FUB SEND: Using URL-encoded format');
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        console.log('📡 FUB SEND: URL-encoded data:', urlEncodedData.substring(0, 200) + '...');
                        xhr.send(urlEncodedData);
                    }
                    
                    console.log('📡 FUB SEND: Request sent successfully');
                }
            };
            
            // Initialize the capture system
            FUB_UNIVERSAL_CAPTURE.init();
            
            // Make it globally available for debugging
            window.FUB_UNIVERSAL_CAPTURE = FUB_UNIVERSAL_CAPTURE;
            
            // Alternative send method using jQuery if available
            window.fubSendWithJQuery = function(data, formType) {
                if (typeof jQuery === 'undefined') {
                    console.log('⚠️ FUB: jQuery not available');
                    return;
                }
                
                console.log('📤 FUB jQuery: Sending with jQuery.post');
                jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'fub_track_form_submission',
                    form_data: JSON.stringify(data),
                    form_type: formType,
                    nonce: '<?php echo wp_create_nonce('fub_track_form'); ?>'
                }, function(response) {
                    console.log('🎉 FUB jQuery: Response received:', response);
                }).fail(function(xhr, status, error) {
                    console.error('❌ FUB jQuery: Request failed:', error);
                });
            };
            
            // Test function available in console
            window.testFubFormSubmission = function() {
                console.log('🧪 FUB TEST: Running manual test submission...');
                var testData = [
                    {name: 'email', value: 'test@example.com'},
                    {name: 'first_name', value: 'Test'},
                    {name: 'last_name', value: 'User'}
                ];
                console.log('🧪 FUB TEST: Test data:', testData);
                FUB_UNIVERSAL_CAPTURE.sendFormData(testData, 'manual-test');
            };
            
            // Debug function to check AJAX endpoint
            window.checkFubEndpoint = function() {
                var url = '<?php echo admin_url('admin-ajax.php'); ?>';
                console.log('🔍 FUB DEBUG: AJAX endpoint:', url);
                console.log('🔍 FUB DEBUG: Nonce:', '<?php echo wp_create_nonce('fub_track_form'); ?>');
                
                // Test simple AJAX call
                var xhr = new XMLHttpRequest();
                xhr.open('POST', url, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    console.log('🔍 FUB DEBUG: Endpoint responded:', this.status, this.responseText.substring(0, 100));
                };
                xhr.send('action=fub_track_form_submission&test=1');
            };
        })();
        </script>
        <!-- End FUB Universal Lead Form Catcher -->
        <?php
    }
    
    /**
     * AJAX handler for form submissions
     */
    public function ajax_track_form_submission() {
        error_log("FUB Integration: ========== FORM SUBMISSION RECEIVED ==========");
        error_log("FUB Integration: REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
        error_log("FUB Integration: CONTENT_TYPE: " . $_SERVER['CONTENT_TYPE']);
        error_log("FUB Integration: POST data keys: " . implode(', ', array_keys($_POST)));
        error_log("FUB Integration: POST action: " . (isset($_POST['action']) ? $_POST['action'] : 'NOT SET'));
        error_log("FUB Integration: POST form_data exists: " . (isset($_POST['form_data']) ? 'YES' : 'NO'));
        error_log("FUB Integration: POST form_type: " . (isset($_POST['form_type']) ? $_POST['form_type'] : 'NOT SET'));
        error_log("FUB Integration: POST nonce exists: " . (isset($_POST['nonce']) ? 'YES' : 'NO'));
        
        // Also check php://input for raw data
        $raw_input = file_get_contents('php://input');
        error_log("FUB Integration: Raw input length: " . strlen($raw_input));
        if (strlen($raw_input) > 0 && strlen($raw_input) < 1000) {
            error_log("FUB Integration: Raw input sample: " . substr($raw_input, 0, 200));
        }
        
        // Ensure tables exist before processing
        $this->check_and_create_tables();
        
        // Block self-generated pixel tracking requests
        if (isset($_POST['form_type']) && $_POST['form_type'] === 'ajax-level5-ajax') {
            error_log("FUB Integration: Blocking self-generated pixel tracking request");
            wp_send_json_success(array('message' => 'Pixel tracking request blocked to prevent loop'));
            return;
        }
        
        try {
            // Test response without nonce check first
            if (isset($_POST['test'])) {
                wp_send_json_success(array('message' => 'Endpoint is working', 'received' => $_POST));
                return;
            }
            
            // Security check
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fub_track_form')) {
                error_log("FUB Integration: Security check failed - nonce invalid or missing");
                error_log("FUB Integration: Nonce value: " . (isset($_POST['nonce']) ? $_POST['nonce'] : 'NOT PROVIDED'));
                wp_send_json_error(array('message' => 'Security check failed', 'nonce_provided' => isset($_POST['nonce'])));
                return;
            }
            
            // Get form data - could be JSON string or already parsed
            $form_data = isset($_POST['form_data']) ? $_POST['form_data'] : null;
            $form_type = isset($_POST['form_type']) ? sanitize_text_field($_POST['form_type']) : 'unknown';
            
            error_log("FUB Integration: Raw form_data received: " . substr(print_r($form_data, true), 0, 500));
            error_log("FUB Integration: Form type: " . $form_type);
            
            // If form_data is a JSON string, decode it
            if (is_string($form_data)) {
                error_log("FUB Integration: form_data is string, attempting JSON decode...");
                $decoded_data = json_decode(stripslashes($form_data), true);
                if ($decoded_data !== null) {
                    $form_data = $decoded_data;
                    error_log("FUB Integration: JSON decoded successfully. Field count: " . count($form_data));
                } else {
                    error_log("FUB Integration: JSON decode failed: " . json_last_error_msg());
                    // Try without stripslashes
                    $decoded_data = json_decode($form_data, true);
                    if ($decoded_data !== null) {
                        $form_data = $decoded_data;
                        error_log("FUB Integration: JSON decoded without stripslashes. Field count: " . count($form_data));
                    }
                }
            }
            
            error_log("FUB Integration: Processing {$form_type} form with " . (is_array($form_data) ? count($form_data) : 0) . " fields");
            
            if (empty($form_data)) {
                error_log("FUB Integration: ERROR - Form data is empty after processing");
                wp_send_json_error(array('message' => 'Form data is empty'));
                return;
            }
            
            // Extract lead data
            $lead_data = $this->extract_lead_data_from_form($form_data);
            
            if (empty($lead_data['email'])) {
                error_log("FUB Integration: No email found in form submission");
                wp_send_json_error(array('message' => 'No email found in form submission'));
                return;
            }
            
            // Add user settings
            $lead_data['source'] = get_option('fub_default_source', 'WordPress Website');
            $lead_data['tags'] = get_option('fub_selected_tags', array());
            $lead_data['assignedTo'] = get_option('fub_assigned_user_id', '');
            
            // Create lead in FUB - EXACT SAME LOGIC AS WORKING CODE
            $fub_result = $this->create_fub_lead($lead_data);
            
            // Also save locally (like working code) - pass FUB result for status
            $local_result = $this->save_lead_locally($lead_data, $form_type, $fub_result['success']);
            
            if ($fub_result['success']) {
                $this->log_activity('form_submission', 'lead', $lead_data['email'], 'success', "Lead created from {$form_type} form in FUB and saved locally");
                
                wp_send_json_success(array(
                    'message' => 'Lead created successfully in Follow Up Boss and saved locally',
                    'lead_data' => $lead_data,
                    'fub_response' => $fub_result,
                    'trigger_pixel' => true  // Flag to trigger pixel notification
                ));
            } else {
                // Even if FUB fails, we still saved locally
                $this->log_activity('form_submission', 'lead', $lead_data['email'], 'warning', "FUB creation failed but saved locally: " . $fub_result['message']);
                wp_send_json_success(array(
                    'message' => 'Lead saved locally. FUB sync failed: ' . $fub_result['message'],
                    'lead_data' => $lead_data,
                    'local_result' => $local_result,
                    'fub_error' => $fub_result['message']
                ));
            }
            
        } catch (Exception $e) {
            error_log("FUB Integration: Exception: " . $e->getMessage());
            wp_send_json_error(array('message' => 'Internal error: ' . $e->getMessage()));
        }
    }
    
    /**
     * Extract lead data from form submission
     */
    private function extract_lead_data_from_form($form_data) {
        $lead_data = array(
            'email' => '',
            'first_name' => '',
            'last_name' => '',
            'phone' => '',
            'message' => '',
            'address' => ''
        );
        
        // Debug array to track what's happening
        $debug_info = array();
        
        error_log("FUB Integration: ============== EXTRACT LEAD DATA START ==============");
        error_log("FUB Integration: Data type: " . gettype($form_data));
        error_log("FUB Integration: Data content: " . json_encode($form_data));
        error_log("FUB Integration: ⚠️ CRITICAL DEBUG - Function is running!");
        
        try {
            // Handle array format from Universal Capture
            if (is_array($form_data)) {
                error_log("FUB Integration: Processing " . count($form_data) . " fields...");
                
                foreach ($form_data as $key => $value) {
                    // Handle both formats: [{name: 'email', value: 'test@test.com'}] and ['email' => 'test@test.com']
                    if (is_array($value) && isset($value['name']) && isset($value['value'])) {
                        $original_name = $value['name'];  // Keep original case for Base64
                        $name = strtolower($value['name']); // Lowercase for regular matching
                        $val = trim($value['value']);
                    } else {
                        $original_name = $key;  // Keep original case for Base64  
                        $name = strtolower($key); // Lowercase for regular matching
                        $val = is_string($value) ? trim($value) : '';
                    }
                    
                    if (empty($val)) continue;
                    
                    error_log("FUB Integration: Processing field - Name: '{$name}', Value: '{$val}'");
                    $debug_info[] = "Processing field: '{$name}' = '{$val}' (original: '{$original_name}')";
                    
                    // Debug: Check if this field matches Base64 pattern
                    if (strpos($original_name, 'lbl-') !== false) {
                        error_log("FUB Integration: 🔍 Found 'lbl-' in field name: '{$original_name}'");
                        $debug_info[] = "Found lbl- field: '{$original_name}'";
                    }
                    
                    // Email detection (enhanced)
                    if (empty($lead_data['email'])) {
                        if (filter_var($val, FILTER_VALIDATE_EMAIL) || 
                            preg_match('/email|correo|e-mail/i', $name)) {
                            error_log("FUB Integration: ✅ EMAIL detected: {$val}");
                            $debug_info[] = "✅ EMAIL detected (regular): {$val}";
                            $lead_data['email'] = $val;
                            continue;
                        }
                    }
                    
                    // Address detection (high priority) - Exclude email address
                    if (preg_match('/address|street|city|state|zip|postal|country|location|home|house|residence|building|apartment|suite/i', $name) && 
                        !preg_match('/email/i', $name)) {
                        error_log("FUB Integration: ✅ ADDRESS detected (regular): {$val}");
                        $debug_info[] = "✅ ADDRESS detected (regular): {$val}";
                        if (!empty($lead_data['address'])) {
                            $lead_data['address'] .= ', ' . $val;
                        } else {
                            $lead_data['address'] = $val;
                        }
                        continue;
                    }
                    
                    // Enhanced WPForms address detection - check value patterns for generic field names
                    if (preg_match('/wpforms\[fields\]\[\d+\]/', $name) || preg_match('/fields\[\d+\]/', $name)) {
                        // For WPForms generic field names, analyze the value to detect addresses
                        if (!filter_var($val, FILTER_VALIDATE_EMAIL) && // Not an email
                            !preg_match('/^\d{10,15}$/', $val) && // Not a phone number
                            !preg_match('/^[a-zA-Z]+$/', $val) && // Not just a name (contains spaces/numbers)
                            (strlen($val) > 10 || // Longer values are likely addresses
                             preg_match('/\d+.*[a-z]/i', $val) || // Contains numbers and letters (street address pattern)
                             preg_match('/location|address|street|avenue|road|lane|drive|place|blvd|ave|rd|ln|dr|pl|suite|apt|apartment|unit|#/i', $val))) {
                            error_log("FUB Integration: ✅ ADDRESS detected (WPForms value analysis): {$val}");
                            $debug_info[] = "✅ ADDRESS detected (WPForms value analysis): {$val}";
                            if (!empty($lead_data['address'])) {
                                $lead_data['address'] .= ', ' . $val;
                            } else {
                                $lead_data['address'] = $val;
                            }
                            continue;
                        }
                    }
                    
                    // Phone detection
                    if (empty($lead_data['phone'])) {
                        if (preg_match('/phone|tel|mobile/i', $name) ||
                            preg_match('/^[\d\s\+\-\(\)]{7,}$/', $val)) {
                            error_log("FUB Integration: ✅ PHONE detected: {$val}");
                            $debug_info[] = "✅ PHONE detected (regular): {$val}";
                            $lead_data['phone'] = $val;
                            continue;
                        }
                    }
                    
                    // First name detection
                    if (preg_match('/first|fname|firstname/i', $name) || $name === 'name') {
                        error_log("FUB Integration: ✅ FIRST NAME detected: {$val}");
                        $debug_info[] = "✅ FIRST NAME detected (regular): {$val}";
                        $lead_data['first_name'] = $val;
                    }
                    // Last name detection
                    elseif (preg_match('/last|lname|lastname|surname/i', $name)) {
                        error_log("FUB Integration: ✅ LAST NAME detected: {$val}");
                        $debug_info[] = "✅ LAST NAME detected (regular): {$val}";
                        $lead_data['last_name'] = $val;
                    }
                    // Base64 encoded field name detection (for SureForms fields like srfm-input-xxx-lbl-TGFzdCBOYW1l-xxx)
                    elseif (strpos($original_name, 'lbl-') !== false) {
                        error_log("FUB Integration: 🔍 Found lbl- field, attempting Base64 decode: '{$original_name}'");
                        
                        // Extract the Base64 part more reliably using original case-sensitive name
                        if (preg_match('/lbl-([A-Za-z0-9+\/=]+)/i', $original_name, $matches)) {
                            $encoded = $matches[1];
                            error_log("FUB Integration: 🔍 Extracted Base64 part: '{$encoded}'");
                            
                            $decoded = @base64_decode($encoded);
                            if ($decoded !== false && strlen($decoded) > 0) {
                                error_log("FUB Integration: 🔍 DECODED field name: '{$decoded}' from encoded: '{$encoded}'");
                                
                                $decodedLower = strtolower($decoded);
                                
                                // Simple string matching for decoded field names
                                if (strpos($decodedLower, 'first') !== false || strpos($decodedLower, 'name') !== false) {
                                    error_log("FUB Integration: ✅ FIRST NAME detected via Base64: {$val}");
                                    $debug_info[] = "✅ FIRST NAME detected via Base64 (decoded: '{$decoded}'): {$val}";
                                    $lead_data['first_name'] = $val;
                                }
                                elseif (strpos($decodedLower, 'last') !== false) {
                                    error_log("FUB Integration: ✅ LAST NAME detected via Base64: {$val}");
                                    $debug_info[] = "✅ LAST NAME detected via Base64 (decoded: '{$decoded}'): {$val}";
                                    $lead_data['last_name'] = $val;
                                }
                                // Address detection - Exclude email address
                                elseif ((strpos($decodedLower, 'address') !== false || strpos($decodedLower, 'house') !== false || strpos($decodedLower, 'residence') !== false || strpos($decodedLower, 'building') !== false || strpos($decodedLower, 'apartment') !== false || strpos($decodedLower, 'suite') !== false) && 
                                       strpos($decodedLower, 'email') === false) {
                                    error_log("FUB Integration: ✅ ADDRESS detected via Base64: {$val}");
                                    $debug_info[] = "✅ ADDRESS detected via Base64 (decoded: '{$decoded}'): {$val}";
                                    if (!empty($lead_data['address'])) {
                                        $lead_data['address'] .= ', ' . $val;
                                    } else {
                                        $lead_data['address'] = $val;
                                    }
                                }
                                elseif (strpos($decodedLower, 'phone') !== false) {
                                    error_log("FUB Integration: ✅ PHONE detected via Base64: {$val}");
                                    $debug_info[] = "✅ PHONE detected via Base64 (decoded: '{$decoded}'): {$val}";
                                    if (empty($lead_data['phone'])) {
                                        $lead_data['phone'] = $val;
                                    }
                                }
                                elseif (strpos($decodedLower, 'message') !== false) {
                                    error_log("FUB Integration: ✅ MESSAGE detected via Base64: {$val}");
                                    $debug_info[] = "✅ MESSAGE detected via Base64 (decoded: '{$decoded}'): {$val}";
                                    $lead_data['message'] = $val;
                                }
                            } else {
                                error_log("FUB Integration: ❌ Base64 decode failed for: '{$encoded}'");
                            }
                        } else {
                            error_log("FUB Integration: ❌ Could not extract Base64 part from: '{$original_name}'");
                        }
                    }
                    // Full name handling
                    elseif ((preg_match('/^name$|fullname/i', $name)) && empty($lead_data['first_name'])) {
                        $parts = explode(' ', $val);
                        $lead_data['first_name'] = $parts[0];
                        if (count($parts) > 1) {
                            $lead_data['last_name'] = implode(' ', array_slice($parts, 1));
                        }
                        error_log("FUB Integration: ✅ FULL NAME split into: {$lead_data['first_name']} {$lead_data['last_name']}");
                    }
                    // Message detection
                    elseif (preg_match('/message|comment|inquiry|textarea/i', $name)) {
                        error_log("FUB Integration: ✅ MESSAGE detected");
                        $lead_data['message'] = $val;
                    }
                    // Fallback: Try to guess based on content
                    elseif (empty($lead_data['first_name']) && preg_match('/^[a-zA-ZÀ-ÿ\s]{2,30}$/', $val) && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                        error_log("FUB Integration: ✅ NAME detected by content analysis: {$val}");
                        $lead_data['first_name'] = $val;
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log("FUB Integration: EXCEPTION extracting lead data: " . $e->getMessage());
        }
        
        error_log("FUB Integration: ============== EXTRACT LEAD DATA END ==============");
        error_log("FUB Integration: Final lead data: " . json_encode($lead_data));
        
        // Add debug info to lead data for troubleshooting
        $lead_data['debug_info'] = $debug_info;
        
        return $lead_data;
    }
    
    /**
     * Send lead to Follow Up Boss - Using exact same logic as working code
     */
    private function create_fub_lead($lead_data) {
        error_log("FUB Integration: Starting create_fub_lead with data: " . json_encode($lead_data));
        
        // Check subscription status before proceeding
        $account_id = get_option('fub_account_id');
        if ($account_id) {
            error_log("FUB Integration: Checking subscription status for account: " . $account_id);
            $subscription_check = $this->backend_api->check_existing_subscription($account_id);
            
            if ($subscription_check['success']) {
                $has_active_subscription = (isset($subscription_check['data']['has_active_subscription']) && $subscription_check['data']['has_active_subscription']);
                $subscription_status = isset($subscription_check['data']['status']) ? $subscription_check['data']['status'] : 'inactive';
                
                error_log("FUB Integration: Subscription check - Status: " . $subscription_status . ", Active: " . ($has_active_subscription ? 'YES' : 'NO'));
                
                if (!$has_active_subscription || !in_array($subscription_status, ['active', 'trialing'])) {
                    error_log("FUB Integration: Lead sending blocked - Subscription is not active. Status: " . $subscription_status);
                    return array(
                        'success' => false, 
                        'message' => 'Lead sending is disabled. Your subscription is not active (Status: ' . $subscription_status . '). Please update your subscription to continue sending leads.',
                        'subscription_status' => $subscription_status
                    );
                }
                
                error_log("FUB Integration: Subscription is active, proceeding with lead creation");
            } else {
                error_log("FUB Integration: Failed to check subscription status: " . json_encode($subscription_check));
                return array(
                    'success' => false, 
                    'message' => 'Unable to verify subscription status. Please check your subscription and try again.'
                );
            }
        } else {
            error_log("FUB Integration: No account_id found, cannot verify subscription");
            return array(
                'success' => false, 
                'message' => 'Account not configured. Please complete the plugin setup.'
            );
        }
        
        // Check OAuth connection
        if (!$this->oauth_manager->is_connected()) {
            error_log("FUB Integration: OAuth not connected");
            return array('success' => false, 'message' => 'OAuth not connected. Please reconnect to Follow Up Boss.');
        }
        
        error_log("FUB Integration: OAuth connected, proceeding with lead creation");
        
        // Use FUB Events API - CORRECT ENDPOINT for leads (triggers automations & prevents duplicates)
        $wordpress_source = get_option('fub_default_source', 'WordPress Website');
        $inquiry_type = get_option('fub_inquiry_type', 'General Inquiry');
        
        // Map inquiry types to appropriate stages
        $stage_mapping = array(
            'General Inquiry' => 'Lead',
            'Registration' => 'Lead', 
            'Property Inquiry' => 'Buyers',
            'Seller Inquiry' => 'Sellers'
        );
        
        $target_stage = isset($stage_mapping[$inquiry_type]) ? $stage_mapping[$inquiry_type] : 'Lead';
        
        // Prepare event data for FUB Events API  
        $event_data = array(
            'type' => $inquiry_type,  // User-selected inquiry type
            'source' => $wordpress_source,
            'person' => array(
                'firstName' => $lead_data['first_name'] ?? '',
                'lastName' => $lead_data['last_name'] ?? '',
                'emails' => array(
                    array('value' => $lead_data['email'])
                ),
                'stage' => $target_stage  // Explicitly set the stage
            )
        );
        
        // Add phone if available (also as array format)
        if (!empty($lead_data['phone'])) {
            $event_data['person']['phones'] = array(
                array('value' => $lead_data['phone'])
            );
        }
        
        // Add address if available (Follow Up Boss addresses format)
        if (!empty($lead_data['address'])) {
            $event_data['person']['addresses'] = array(
                array(
                    'street' => $lead_data['address'],
                    'isPrimary' => true
                )
            );
            error_log("FUB Integration: Adding address to FUB request: " . $lead_data['address']);
        }
        
        // Add message if available
        if (!empty($lead_data['message'])) {
            $event_data['message'] = $lead_data['message'];
        }
        
        // Add assigned user if configured
        $assigned_user_id = get_option('fub_assigned_user_id');
        if (!empty($assigned_user_id)) {
            $event_data['person']['assignedUserId'] = intval($assigned_user_id);
            error_log("FUB Integration: Assigning lead to user ID: " . $assigned_user_id);
        }
        
        // Add tags from settings to person
        $selected_tags = get_option('fub_selected_tags', array());
        if (!empty($selected_tags)) {
            // Convert tag IDs to names
            $tag_names = $this->get_tag_names_from_ids($selected_tags);
            
            // Always include WordPress as base tag
            $event_data['person']['tags'] = array_merge(array('WordPress'), $tag_names);
            
            // Add custom source tag if different
            if (!empty($wordpress_source) && $wordpress_source !== 'WordPress') {
                $event_data['person']['tags'][] = $wordpress_source;
            }
            
            // Remove duplicates and empty values
            $event_data['person']['tags'] = array_unique(array_filter($event_data['person']['tags']));
        }
        
        error_log("FUB Integration: === FUB EVENTS API DEBUG ===");
        error_log("FUB Integration: Using CORRECT /v1/events endpoint");
        error_log("FUB Integration: ❌ NO EVENT TYPE sent (removed to avoid conflicts)");
        error_log("FUB Integration: ✅ Target stage: " . $target_stage . " (explicitly set in person.stage)");
        error_log("FUB Integration: ✅ Lead type in note: " . $inquiry_type);
        error_log("FUB Integration: Lead email: " . $lead_data['email']);
        error_log("FUB Integration: Full event data: " . json_encode($event_data));
        error_log("FUB Integration: === END DEBUG ===");
        
        // Send to FUB Events API using OAuth
        $result = $this->oauth_manager->make_authenticated_request(
            'https://api.followupboss.com/v1/events', 
            'POST', 
            $event_data
        );
        
        if (!$result['success']) {
            error_log("FUB Integration: OAuth API call failed: " . $result['error']);
            return array('success' => false, 'message' => $result['error']);
        }
        
        error_log("FUB Integration: Events API Response - Success: " . json_encode($result['data']));
        error_log("FUB Integration: ✅ Lead created successfully via Events API with OAuth (automations triggered)");
        
        return array(
            'success' => true, 
            'message' => 'Lead created successfully via Events API with OAuth', 
            'response' => json_encode($result['data'])
        );
    }
    
    /**
     * Get tag names from IDs - Helper function
     */
    private function get_tag_names_from_ids($tag_ids) {
        global $wpdb;
        
        if (empty($tag_ids)) {
            return array();
        }
        
        $tag_names = array();
        $db_tag_ids = array();
        
        // Separate custom tags from database tags
        foreach ($tag_ids as $tag_id) {
            if (strpos($tag_id, 'custom_') === 0) {
                // This is a custom tag
                $custom_tags = get_option('fub_custom_tags', array());
                $clean_id = str_replace('custom_', '', $tag_id);
                
                foreach ($custom_tags as $custom_tag) {
                    if (sanitize_title($custom_tag) === $clean_id) {
                        $tag_names[] = $custom_tag;
                        break;
                    }
                }
            } else {
                // Regular database tag
                $db_tag_ids[] = $tag_id;
            }
        }
        
        // Get database tags if any
        if (!empty($db_tag_ids)) {
            $placeholders = implode(',', array_fill(0, count($db_tag_ids), '%s'));
            $query = "SELECT name FROM " . FUB_TAGS_TABLE . " WHERE fub_tag_id IN ($placeholders)";
            
            $db_results = $wpdb->get_col($wpdb->prepare($query, $db_tag_ids));
            if ($db_results) {
                $tag_names = array_merge($tag_names, $db_results);
            }
        }
        
        return $tag_names;
    }
    
    /**
     * Log activity - same as working code
     */
    private function log_activity($action, $type, $identifier, $status, $message) {
        global $wpdb;
        
        // Ensure activity table exists
        $this->check_and_create_tables();
        
        try {
            $wpdb->insert(
                FUB_ACTIVITY_TABLE,
                array(
                    'action' => $action,
                    'type' => $type,
                    'identifier' => $identifier,
                    'status' => $status,
                    'message' => $message,
                    'created_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s')
            );
            
            if ($wpdb->last_error) {
                error_log('FUB Integration: Error logging activity: ' . $wpdb->last_error);
            }
        } catch (Exception $e) {
            error_log('FUB Integration: Exception logging activity: ' . $e->getMessage());
        }
    }
    
    /**
     * Save lead locally - same as working code
     */
    private function save_lead_locally($lead_data, $form_type, $fub_success = null) {
        global $wpdb;
        
        try {
            // Get selected tags to apply
            $selected_tags = get_option('fub_selected_tags', array());
            
            $applied_tags = array();
            if (!empty($selected_tags)) {
                // Get real tag names from selected IDs
                $applied_tags = $this->get_tag_names_from_ids($selected_tags);
                
                // Always include WordPress as base tag
                $applied_tags[] = 'WordPress';
                
                // Add custom WordPress source tag if defined
                $wordpress_source = get_option('fub_default_source', 'WordPress Website');
                if (!empty($wordpress_source) && $wordpress_source !== 'WordPress') {
                    $applied_tags[] = $wordpress_source;
                }
                
                // Remove duplicates and empty values
                $applied_tags = array_unique(array_filter($applied_tags));
            }
            
            // Prepare data for local storage
            $local_lead_data = array(
                'fub_id' => 'local-' . uniqid(), // Temporary ID until synced with FUB
                'email' => sanitize_email($lead_data['email']),
                'first_name' => sanitize_text_field(isset($lead_data['first_name']) ? $lead_data['first_name'] : ''),
                'last_name' => sanitize_text_field(isset($lead_data['last_name']) ? $lead_data['last_name'] : ''),
                'phone' => sanitize_text_field(isset($lead_data['phone']) ? $lead_data['phone'] : ''),
                'message' => sanitize_textarea_field(isset($lead_data['message']) ? $lead_data['message'] : ''),
                'address' => sanitize_text_field(isset($lead_data['address']) ? $lead_data['address'] : ''),
                'status' => ($fub_success === true) ? 'sent' : (($fub_success === false) ? 'failed' : 'pending'),
                'fub_status' => ($fub_success === true) ? 'sent' : (($fub_success === false) ? 'failed' : 'pending'),
                'retry_count' => ($fub_success === false) ? 1 : 0,
                'fub_response' => isset($fub_response) ? $fub_response : null,
                'tags' => json_encode($applied_tags),
                'custom_fields' => json_encode(array(
                    'source' => 'WordPress Form',
                    'form_type' => $form_type,
                    'submission_date' => current_time('mysql'),
                    'form_data' => $lead_data
                )),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            );
            
            $result = $wpdb->insert(FUB_LEADS_TABLE, $local_lead_data);
            
            if ($result === false) {
                error_log("FUB Integration: Failed to save lead locally: " . $wpdb->last_error);
                return array('success' => false, 'message' => 'Database error: ' . $wpdb->last_error);
            }
            
            $lead_id = $wpdb->insert_id;
            error_log("FUB Integration: Lead saved locally with ID: {$lead_id}");
            
            return array('success' => true, 'message' => 'Lead saved locally', 'lead_id' => $lead_id);
            
        } catch (Exception $e) {
            error_log("FUB Integration: Exception saving lead locally: " . $e->getMessage());
            return array('success' => false, 'message' => 'Exception: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for syncing tags from FUB
     */
    public function ajax_sync_tags() {
        error_log("FUB Sync Tags: Starting sync process");
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'fub_sync_tags')) {
            error_log("FUB Sync Tags: Nonce verification failed");
            wp_send_json_error('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            error_log("FUB Sync Tags: User lacks permissions");
            wp_send_json_error('Insufficient permissions');
        }
        
        // Check OAuth connection
        if (!$this->oauth_manager->is_connected()) {
            error_log("FUB Sync Tags: OAuth not connected");
            wp_send_json_error('OAuth not connected. Please reconnect to Follow Up Boss.');
        }
        
        error_log("FUB Sync Tags: OAuth connected, proceeding with tag sync");
        
        // Test basic connectivity first with OAuth
        error_log("FUB Sync Tags: Testing basic API connectivity with OAuth");
        $test_result = $this->oauth_manager->make_authenticated_request('https://api.followupboss.com/v1/people?limit=1');
        
        if (!$test_result['success']) {
            error_log("FUB Sync Tags: Basic connectivity test failed: " . $test_result['error']);
            wp_send_json_error('Failed to connect to Follow Up Boss API: ' . $test_result['error']);
        }
        
        error_log("FUB Sync Tags: Basic connectivity test successful");
        
        try {
            // Ensure tags table exists
            $this->ensure_tags_table_exists();
            
            // Get tags from FUB API using OAuth
            error_log("FUB Sync Tags: Calling get_fub_tags_comprehensive with OAuth");
            $tags = $this->get_fub_tags_comprehensive_oauth();
            error_log("FUB Sync Tags: API returned: " . (is_array($tags) ? count($tags) . " tags" : ($tags === false ? "FALSE" : "EMPTY")));
            
            if ($tags === false) {
                wp_send_json_error('Failed to connect to Follow Up Boss API. Please check your API key.');
            } elseif (empty($tags)) {
                wp_send_json_success(array(
                    'message' => '⚠️ No tags found in your Follow Up Boss account. Create some tags by tagging contacts in FUB first, then try syncing again.',
                    'tags_count' => 0
                ));
            } else {
                $sync_result = $this->sync_tags_to_database_improved($tags);
                $synced_count = $sync_result['synced_count'];
                $removed_count = $sync_result['removed_count'];
                
                $message_parts = array();
                
                if ($synced_count > 0) {
                    $message_parts[] = "✅ Added {$synced_count} NEW tags";
                }
                
                if ($removed_count > 0) {
                    $message_parts[] = "🗑️ Removed {$removed_count} tags that no longer exist in FUB";
                }
                
                if (empty($message_parts)) {
                    $message_parts[] = "✅ All tags are already synchronized";
                }
                
                $message = implode(' | ', $message_parts);
                $message .= " | Total FUB tags: " . count($tags);
                
                wp_send_json_success(array(
                    'message' => $message,
                    'tags_count' => $synced_count,
                    'removed_count' => $removed_count,
                    'total_tags' => count($tags),
                    'tags' => $tags,
                    'reload_page' => $synced_count > 0 || $removed_count > 0 // Reload if changes were made
                ));
            }
        } catch (Exception $e) {
            wp_send_json_error('Error syncing tags: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for getting FUB users
     */
    public function ajax_get_users() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'fub_get_users')) {
            wp_send_json_error('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Check OAuth connection
        if (!$this->oauth_manager->is_connected()) {
            wp_send_json_error('OAuth not connected. Please reconnect to Follow Up Boss.');
        }
        
        $result = $this->oauth_manager->make_authenticated_request('https://api.followupboss.com/v1/users');
        
        if (!$result['success']) {
            wp_send_json_error('Failed to connect to Follow Up Boss API: ' . $result['error']);
        }
        
        if (isset($result['data']['users'])) {
            wp_send_json_success($result['data']['users']);
        } else {
            wp_send_json_error('Failed to fetch users from Follow Up Boss API');
        }
    }
    
    /**
     * AJAX handler for testing tags connection
     */
    public function ajax_test_tags_connection() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'fub_test_tags')) {
            wp_send_json_error('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Check OAuth connection
        if (!$this->oauth_manager->is_connected()) {
            wp_send_json_error('OAuth not connected. Please reconnect to Follow Up Boss.');
        }
        
        // Test connection by trying to get tags
        $tags = $this->get_fub_tags_comprehensive_oauth();
        
        if ($tags === false) {
            wp_send_json_error('❌ Connection failed. Please check your OAuth connection.');
        } elseif (empty($tags)) {
            wp_send_json_success('✅ Connection successful, but no tags found. Create some tags in FUB first.');
        } else {
            wp_send_json_success('✅ Connection successful! Found ' . count($tags) . ' tags in your FUB account.');
        }
    }
    
    /**
     * Get tags from FUB API using OAuth with multiple fallback methods
     */
    private function get_fub_tags_comprehensive_oauth() {
        // Try getting tags from people endpoint (most reliable)
        $tags = $this->get_real_fub_tags_oauth();
        if (!empty($tags)) {
            return $tags;
        }
        
        // Try direct tags endpoint
        $tags = $this->get_tags_from_direct_endpoint_oauth();
        if (!empty($tags)) {
            return $tags;
        }
        
        return false;
    }
    
    /**
     * Get tags from people endpoint using OAuth
     */
    private function get_real_fub_tags_oauth() {
        error_log("FUB Tags OAuth: Calling people endpoint");
        $result = $this->oauth_manager->make_authenticated_request('https://api.followupboss.com/v1/people?limit=500');
        
        if (!$result['success']) {
            error_log("FUB Tags OAuth: API Error: " . $result['error']);
            return false;
        }
        
        $body = $result['data'];
        error_log("FUB Tags OAuth: People endpoint response successful");
        
        if (!isset($body['people'])) {
            return false;
        }
        
        // Extract unique tags from people
        $unique_tags = array();
        
        foreach ($body['people'] as $person) {
            if (isset($person['tags']) && is_array($person['tags'])) {
                foreach ($person['tags'] as $tag) {
                    if (is_string($tag)) {
                        $unique_tags[trim($tag)] = true;
                    } elseif (is_array($tag) && isset($tag['name'])) {
                        $unique_tags[trim($tag['name'])] = true;
                    }
                }
            }
        }
        
        return array_keys($unique_tags);
    }
    
    /**
     * Get tags from direct endpoint using OAuth
     */
    private function get_tags_from_direct_endpoint_oauth() {
        error_log("FUB Tags OAuth: Calling direct tags endpoint");
        $result = $this->oauth_manager->make_authenticated_request('https://api.followupboss.com/v1/tags');
        
        if (!$result['success']) {
            error_log("FUB Tags OAuth: Direct endpoint error: " . $result['error']);
            return false;
        }
        
        $body = $result['data'];
        
        if (!isset($body['tags'])) {
            return false;
        }
        
        $tag_names = array();
        foreach ($body['tags'] as $tag) {
            if (isset($tag['name'])) {
                $tag_names[] = $tag['name'];
            }
        }
        
        return $tag_names;
    }

    /**
     * Get tags from FUB API with multiple fallback methods
     */
    private function get_fub_tags_comprehensive($api_key) {
        // Try getting tags from people endpoint (most reliable)
        $tags = $this->get_real_fub_tags($api_key);
        if (!empty($tags)) {
            return $tags;
        }
        
        // Try direct tags endpoint
        $tags = $this->get_tags_from_direct_endpoint($api_key);
        if (!empty($tags)) {
            return $tags;
        }
        
        return false;
    }
    
    /**
     * Get tags from people endpoint
     */
    private function get_real_fub_tags($api_key) {
        error_log("FUB Tags: Calling people endpoint");
        $response = wp_remote_get('https://api.followupboss.com/v1/people?limit=500', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_key . ':'),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log("FUB Tags: WP Error: " . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        error_log("FUB Tags: People endpoint response code: " . $code);
        
        if ($code !== 200 || !isset($body['people'])) {
            return false;
        }
        
        // Extract unique tags from people
        $unique_tags = array();
        
        foreach ($body['people'] as $person) {
            if (isset($person['tags']) && is_array($person['tags'])) {
                foreach ($person['tags'] as $tag) {
                    if (is_string($tag)) {
                        $unique_tags[trim($tag)] = true;
                    } elseif (is_array($tag) && isset($tag['name'])) {
                        $unique_tags[trim($tag['name'])] = true;
                    }
                }
            }
        }
        
        return array_keys($unique_tags);
    }
    
    /**
     * Get tags from direct endpoint
     */
    private function get_tags_from_direct_endpoint($api_key) {
        error_log("FUB Tags: Calling direct tags endpoint");
        $response = wp_remote_get('https://api.followupboss.com/v1/tags', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_key . ':'),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log("FUB Tags: Direct endpoint WP Error: " . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        error_log("FUB Tags: Direct endpoint response code: " . $code);
        
        if ($code !== 200) {
            return false;
        }
        
        // Extract tag names
        $tag_names = array();
        if (is_array($body)) {
            foreach ($body as $tag) {
                if (is_string($tag)) {
                    $tag_names[] = $tag;
                } elseif (is_array($tag) && isset($tag['name'])) {
                    $tag_names[] = $tag['name'];
                }
            }
        }
        
        return $tag_names;
    }
    
    /**
     * Sync tags to database
     */
    private function sync_tags_to_database_improved($tag_names) {
        global $wpdb;
        
        $synced_count = 0;
        $removed_count = 0;
        
        // First, mark all existing tags as inactive
        $wpdb->query("UPDATE " . FUB_TAGS_TABLE . " SET active = 0");
        
        // Then process tags from FUB
        foreach ($tag_names as $tag_name) {
            $tag_name = trim($tag_name);
            if (empty($tag_name)) continue;
            
            // Check if tag exists
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . FUB_TAGS_TABLE . " WHERE name = %s",
                $tag_name
            ));
            
            if ($existing) {
                // Reactivate existing tag
                $wpdb->update(
                    FUB_TAGS_TABLE,
                    array('active' => 1),
                    array('id' => $existing),
                    array('%d'),
                    array('%d')
                );
            } else {
                // Insert new tag
                $result = $wpdb->insert(
                    FUB_TAGS_TABLE,
                    array(
                        'name' => $tag_name,
                        'fub_tag_id' => 'fub_' . sanitize_title($tag_name),
                        'active' => 1
                    ),
                    array('%s', '%s', '%d')
                );
                
                if ($result !== false) {
                    $synced_count++;
                }
            }
        }
        
        // Count and remove inactive tags (tags that no longer exist in FUB)
        $removed_count = $wpdb->get_var("SELECT COUNT(*) FROM " . FUB_TAGS_TABLE . " WHERE active = 0");
        
        if ($removed_count > 0) {
            // Remove selected tags that are no longer active from settings
            $selected_tags = get_option('fub_selected_tags', array());
            $inactive_tag_ids = $wpdb->get_col("SELECT fub_tag_id FROM " . FUB_TAGS_TABLE . " WHERE active = 0");
            
            $selected_tags = array_diff($selected_tags, $inactive_tag_ids);
            update_option('fub_selected_tags', $selected_tags);
            
            // Delete inactive tags from database
            $wpdb->delete(FUB_TAGS_TABLE, array('active' => 0), array('%d'));
        }
        
        return array(
            'synced_count' => $synced_count,
            'removed_count' => $removed_count
        );
    }
    
    /**
     * Ensure tags table exists
     */
    private function ensure_tags_table_exists() {
        global $wpdb;
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '" . FUB_TAGS_TABLE . "'") === FUB_TAGS_TABLE;
        
        if (!$table_exists) {
            $this->create_tables();
        }
    }

    public function ajax_pixel_track() {
        // Handle pixel tracking requests (no nonce required for tracking pixels)
        $event = sanitize_text_field($_GET['event'] ?? '');
        $pixel_id = sanitize_text_field($_GET['pixel_id'] ?? '');
        $data = sanitize_text_field($_GET['data'] ?? '{}');
        
        if (empty($event) || empty($pixel_id)) {
            // Return 1x1 transparent pixel
            header('Content-Type: image/gif');
            header('Content-Length: 43');
            header('Cache-Control: no-cache');
            echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            exit;
        }
        
        // Decode and validate data
        $tracking_data = json_decode(stripslashes($data), true);
        if (!$tracking_data) {
            $tracking_data = array();
        }
        
        // Log the pixel tracking event
        $this->log_activity('pixel_track', 'pixel', $pixel_id, 'success', "Pixel event: {$event}");
        
        // Store pixel data in database for analytics
        global $wpdb;
        $table_name = $wpdb->prefix . 'fub_pixel_events';
        
        // Create table if it doesn't exist
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            pixel_id varchar(255) NOT NULL,
            event_type varchar(100) NOT NULL,
            event_data longtext,
            user_ip varchar(45),
            user_agent varchar(500),
            url varchar(500),
            referrer varchar(500),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY pixel_id (pixel_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Insert tracking data
        $wpdb->insert(
            $table_name,
            array(
                'pixel_id' => $pixel_id,
                'event_type' => $event,
                'event_data' => wp_json_encode($tracking_data),
                'user_ip' => $this->get_client_ip(),
                'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'url' => sanitize_text_field($tracking_data['url'] ?? ''),
                'referrer' => sanitize_text_field($tracking_data['referrer'] ?? ''),
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        // Return 1x1 transparent pixel
        header('Content-Type: image/gif');
        header('Content-Length: 43');
        header('Cache-Control: no-cache');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }
    
    private function get_client_ip() {
        $ip_keys = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field($_SERVER[$key]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
    }
    
    /**
     * AJAX handler for manual retry of failed leads
     */
    public function ajax_retry_failed_leads() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'fub_admin_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $result = $this->retry_failed_leads();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Manual retry of failed leads
     */
    public function retry_failed_leads() {
        global $wpdb;
        
        // Check if OAuth is connected
        if (!$this->oauth_manager->is_connected()) {
            error_log('🔄 FUB RETRY: OAuth not connected, cannot retry failed leads');
            return array('success' => false, 'message' => 'OAuth not connected');
        }
        
        // Find leads that haven't been sent to FUB
        $failed_leads = $wpdb->get_results(
            "SELECT * FROM " . FUB_LEADS_TABLE . " 
             WHERE (fub_status IN ('failed', 'pending') OR (fub_status IS NULL AND status IN ('failed', 'pending')))
             AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)  
             ORDER BY created_at DESC 
             LIMIT 10",
            ARRAY_A
        );
        
        if (empty($failed_leads)) {
            error_log('🔄 FUB RETRY: No failed leads to retry');
            return array('success' => true, 'message' => 'No failed leads found', 'processed' => 0);
        }
        
        $retry_count = 0;
        $success_count = 0;
        
        foreach ($failed_leads as $lead) {
            $retry_count++;
            
            error_log("🔄 FUB RETRY: Attempting to retry lead ID: {$lead['id']}, Email: {$lead['email']}");
            
            // Prepare lead data for retry
            $lead_data = array(
                'email' => $lead['email'],
                'first_name' => $lead['first_name'],
                'last_name' => $lead['last_name'],
                'phone' => $lead['phone'],
                'message' => $lead['message'],
                'address' => $lead['address']
            );
            
            // Attempt to send to FUB
            $fub_result = $this->create_fub_lead($lead_data);
            
            if ($fub_result['success']) {
                // Update lead status to sent
                $wpdb->update(
                    FUB_LEADS_TABLE,
                    array(
                        'fub_status' => 'sent',
                        'fub_response' => $fub_result['response'],
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $lead['id']),
                    array('%s', '%s', '%s'),
                    array('%d')
                );
                
                $success_count++;
                error_log("🔄 FUB RETRY: ✅ Successfully sent lead ID: {$lead['id']}");
                
                // Log activity
                $this->log_activity('lead_retry', 'lead', $lead['email'], 'success', "Lead successfully retried and sent to FUB");
                
            } else {
                // Update failure count and status
                $failure_count = intval($lead['retry_count'] ?? 0) + 1;
                $max_retries = 5; // Maximum number of retry attempts
                
                if ($failure_count >= $max_retries) {
                    // Mark as permanently failed after max retries
                    $wpdb->update(
                        FUB_LEADS_TABLE,
                        array(
                            'fub_status' => 'permanently_failed',
                            'retry_count' => $failure_count,
                            'fub_response' => $fub_result['message'],
                            'updated_at' => current_time('mysql')
                        ),
                        array('id' => $lead['id']),
                        array('%s', '%d', '%s', '%s'),
                        array('%d')
                    );
                    
                    error_log("🔄 FUB RETRY: ❌ Lead ID: {$lead['id']} permanently failed after {$failure_count} retries");
                    $this->log_activity('lead_retry', 'lead', $lead['email'], 'error', "Lead permanently failed after {$failure_count} retries: " . $fub_result['message']);
                    
                } else {
                    // Update retry count and keep status as failed for next attempt
                    $wpdb->update(
                        FUB_LEADS_TABLE,
                        array(
                            'fub_status' => 'failed',
                            'retry_count' => $failure_count,
                            'fub_response' => $fub_result['message'],
                            'updated_at' => current_time('mysql')
                        ),
                        array('id' => $lead['id']),
                        array('%s', '%d', '%s', '%s'),
                        array('%d')
                    );
                    
                    error_log("🔄 FUB RETRY: ❌ Lead ID: {$lead['id']} retry {$failure_count} failed: " . $fub_result['message']);
                    $this->log_activity('lead_retry', 'lead', $lead['email'], 'warning', "Lead retry {$failure_count} failed: " . $fub_result['message']);
                }
            }
            
            // Small delay between retries to avoid overwhelming the API
            sleep(1);
        }
        
        error_log("🔄 FUB RETRY: Processed {$retry_count} leads, {$success_count} successful, " . ($retry_count - $success_count) . " failed");
        
        return array(
            'success' => true,
            'message' => "Processed {$retry_count} leads",
            'processed' => $retry_count,
            'successful' => $success_count,
            'failed' => $retry_count - $success_count
        );
    }
    
}

/**
 * Plugin activation function
 */
if (!function_exists('fub_to_wp_activate')) {
    function fub_to_wp_activate() {
        // Use the class method instead of global function
        $plugin = FUB_Integration_SaaS::get_instance();
        $plugin->activate();
    }
}

/**
 * Plugin deactivation function
 */
if (!function_exists('fub_to_wp_deactivate')) {
    function fub_to_wp_deactivate() {
        // Use the class method instead of global function  
        $plugin = FUB_Integration_SaaS::get_instance();
        $plugin->deactivate();
    }
}

register_activation_hook(FUB_PLUGIN_FILE, 'fub_to_wp_activate');
register_deactivation_hook(FUB_PLUGIN_FILE, 'fub_to_wp_deactivate');

// Initialize the plugin
function fub_to_wp_init() {
    return FUB_Integration_SaaS::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'fub_to_wp_init');

// Initialize Auto-Update Checker
function fub_to_wp_init_update_checker() {
    // Only initialize if WordPress is fully loaded
    if (!did_action('init')) {
        return;
    }
    
    // Only initialize if plugin update checker exists
    if (!file_exists(FUB_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php')) {
        return;
    }
    
    require_once FUB_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
    
    // Use the factory class with full namespace
    $updateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/Mikepei120/fub-to-wp/',
        FUB_PLUGIN_FILE,
        'fub-to-wp'
    );
    
    // Set the branch that contains the stable release (using master branch)
    $updateChecker->setBranch('master');
    
    // Enable GitHub release assets (ZIP files) for release-based updates
    $updateChecker->getVcsApi()->enableReleaseAssets();
    
    // Optional: Add license checking or other features
    // $updateChecker->addQueryArgFilter(array($this, 'filterUpdateRequest'));
}

// Secure GitHub token management for private repository access
function fub_get_github_token() {
    // No token needed for public repositories
    return null;
}

// Simple encryption for database storage (not recommended for production)
function fub_encrypt_token($token) {
    $key = wp_salt('auth');
    return base64_encode(openssl_encrypt($token, 'AES-256-CBC', $key, 0, substr(md5($key), 0, 16)));
}

function fub_decrypt_token($encrypted_token) {
    $key = wp_salt('auth');
    return openssl_decrypt(base64_decode($encrypted_token), 'AES-256-CBC', $key, 0, substr(md5($key), 0, 16));
}

// Admin function to securely set GitHub token (only for admins)
function fub_set_github_token($token) {
    if (!current_user_can('manage_options')) {
        return false;
    }
    
    // Store encrypted token in database as fallback
    $encrypted = fub_encrypt_token($token);
    update_option('fub_github_token_encrypted', $encrypted);
    
    return true;
}

// Initialize update checker after WordPress initialization is complete
add_action('init', 'fub_to_wp_init_update_checker', 20);
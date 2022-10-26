<?php
/**
 * Main request class
 *
 * @package WC_Klarna_Payments/Classes/Requests
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base class for all request classes.
 */
abstract class KP_Requests {

	/**
	 * The request method.
	 *
	 * @var string
	 */
	protected $method;

	/**
	 * The request title.
	 *
	 * @var string
	 */
	protected $log_title;

	/**
	 * The request arguments.
	 *
	 * @var array
	 */
	protected $arguments;

	/**
	 * The plugin settings.
	 *
	 * @var array
	 */
	protected $settings;

	/**
	 * The Environment to make the requests to (base url)
	 *
	 * @var string
	 */
	protected $environment;

	/**
	 * The Klarna merchant Id, or MID. Used for calculating the request auth.
	 *
	 * @var string
	 */
	protected $merchant_id;

	/**
	 * The Klarna shared api secret. Used for calculating the request auth.
	 *
	 * @var string
	 */
	protected $shared_secret;

	/**
	 * For backwards compatability. The filter to wrap the entire request args in before we return it.
	 *
	 * @var string
	 */
	protected $request_filter;


	/**
	 * Class constructor.
	 *
	 * @param array $arguments The request args.
	 */
	public function __construct( $arguments = array() ) {
		$this->arguments      = $arguments;
		$this->country_params = KP_Form_Fields::$kp_form_auto_countries[ strtolower( $this->arguments['country'] ?? '' ) ] ?? null;
		$this->load_settings();
		$this->set_environment();
		$this->set_credentials();
		$this->iframe_options = new KP_IFrame( $this->settings );

		add_filter( 'wc_kp_image_url_cart_item', array( $this, 'maybe_allow_product_urls' ), 1, 1 );
		add_filter( 'wc_kp_url_cart_item', array( $this, 'maybe_allow_product_urls' ), 1, 1 );
		add_filter( 'wc_kp_image_url_order_item', array( $this, 'maybe_allow_product_urls' ), 1, 1 );
		add_filter( 'wc_kp_url_order_item', array( $this, 'maybe_allow_product_urls' ), 1, 1 );
	}

	/**
	 * Maybe filters out product urls before we send them to Klarna based on settings.
	 *
	 * @param string $url The URL to the product or product image.
	 * @return string|null
	 */
	public function maybe_allow_product_urls( $url ) {
		if ( 'yes' === $this->settings['send_product_urls'] ?? false ) {
			$url = null;
		}
		return $url;
	}

	/**
	 * Loads the Klarna payments settings and sets them to be used here.
	 *
	 * @return void
	 */
	protected function load_settings() {
		$this->settings = get_option( 'woocommerce_klarna_payments_settings', array() );
	}

	/**
	 * Get the API base URL.
	 *
	 * @return string
	 */
	protected function get_api_url_base() {
		return $this->environment;
	}

	/**
	 * Get the request headers.
	 *
	 * @return array
	 */
	protected function get_request_headers() {
		return array(
			'Content-type'  => 'application/json',
			'Authorization' => $this->calculate_auth(),
		);
	}

	/**
	 * Calculates the basic auth.
	 *
	 * @return string
	 */
	protected function calculate_auth() {
		return 'Basic ' . base64_encode( $this->merchant_id . ':' . htmlspecialchars_decode( $this->shared_secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Base64 used to calculate auth headers.
	}

	/**
	 * Sets the environment.
	 */
	protected function set_environment() {
		$region     = $this->country_params['endpoint'] ?? ''; // Get the region from the country parameters, blank for EU.
		$playground = 'yes' == $this->settings['testmode'] ? 'playground' : ''; // If testmode is enabled, add playground to the subdomain.
		$subdomain  = "api${region}.${playground}"; // Combine the string to one subdomain.

		$this->environment = "https://${subdomain}.klarna.com/"; // Return the full base url for the api.
	}

	/**
	 * Sets Klarna credentials.
	 */
	public function set_credentials() {
		$prefix = 'yes' === $this->settings['testmode'] ? 'test_' : ''; // If testmode is enabled, add test_ to the setting strings.
		$suffix = '_' . strtolower( $this->arguments['country'] ) ?? strtolower( kp_get_klarna_country() ); // Get the country from the arguments, or the fetch from helper method.

		$merchant_id   = "${prefix}merchant_id${suffix}";
		$shared_secret = "${prefix}shared_secret${suffix}";

		$this->merchant_id   = isset( $this->settings[ $merchant_id ] ) ? $this->settings[ $merchant_id ] : '';
		$this->shared_secret = isset( $this->settings[ $shared_secret ] ) ? $this->settings[ $shared_secret ] : '';
	}

	/**
	 * Get the user agent.
	 *
	 * @return string
	 */
	protected function get_user_agent() {
		$wp_version  = get_bloginfo( 'version' );
		$wp_url      = get_bloginfo( 'url' );
		$wc_version  = WC()->version;
		$kp_version  = WC_KLARNA_PAYMENTS_VERSION;
		$php_version = phpversion();

		return apply_filters( 'http_headers_useragent', "WordPress/$wp_version; $wp_url - WooCommerce: $wc_version - KP: $kp_version - PHP Version: $php_version - Krokedil" );
	}

	/**
	 * Get the request args.
	 *
	 * @return array
	 */
	abstract protected function get_request_args();

	/**
	 * Get the request url.
	 *
	 * @return string
	 */
	abstract protected function get_request_url();

	/**
	 * Make the request.
	 *
	 * @return object|WP_Error
	 */
	public function request() {
		$url      = $this->get_request_url();
		$args     = $this->get_request_args();
		$response = wp_remote_request( $url, $args );
		return $this->process_response( $response, $args, $url );
	}

	/**
	 * Processes the response checking for errors.
	 *
	 * @param object|WP_Error $response The response from the request.
	 * @param array           $request_args The request args.
	 * @param string          $request_url The request url.
	 * @return array|WP_Error
	 */
	protected function process_response( $response, $request_args, $request_url ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 || $response_code > 299 ) {
			$data          = 'URL: ' . $request_url . ' - ' . wp_json_encode( $request_args );
			$error_message = '';
			// Get the error messages.
			if ( null !== json_decode( $response['body'], true ) ) {
				foreach ( json_decode( $response['body'], true )['error_messages'] as $error ) {
					$error_message = "$error_message $error";
				}
			}
			$code          = wp_remote_retrieve_response_code( $response );
			$error_message = empty( $error_message ) ? $response['response']['message'] : $error_message;
			$return        = new WP_Error( $code, $error_message, $data );
		} else {
			$return = json_decode( wp_remote_retrieve_body( $response ), true );
		}

		$this->log_response( $response, $request_args, $request_url );
		return $return;
	}

	/**
	 * Logs the response from the request.
	 *
	 * @param object|WP_Error $response The response from the request.
	 * @param array           $request_args The request args.
	 * @param string          $request_url The request URL.
	 * @return void
	 */
	protected function log_response( $response, $request_args, $request_url ) {
		$method   = $this->method;
		$title    = "{$this->log_title} - URL: {$request_url}";
		$code     = wp_remote_retrieve_response_code( $response );
		$order_id = $response['OrderID'] ?? null;
		$log      = KP_Logger::format_log( $order_id, $method, $title, $request_args, $response, $code, $request_url );
		KP_Logger::log( $log );
	}
}

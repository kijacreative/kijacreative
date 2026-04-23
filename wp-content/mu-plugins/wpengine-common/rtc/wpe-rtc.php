<?php
/**
 * WPE Real-Time Collaboration -- WebSocket-based sync provider for the block editor.
 *
 * Version: 0.13.0
 *
 * @package wpengine/common-mu-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPE_RTC_IS_MU_PLUGIN', true );

// Prevent fatal errors if loaded twice (e.g. MU plugin + regular plugin).
if ( defined( 'WPE_RTC_VERSION' ) ) {
	return;
}

// Allow sites to opt out entirely via wp-config.php: define( 'WPE_DISABLE_RTC', true ).
if ( defined( 'WPE_DISABLE_RTC' ) && WPE_DISABLE_RTC ) {
	return;
}

/**
 * JWT token lifetime in seconds. Tokens need to survive both the initial
 * WebSocket handshake and any automatic reconnections by the y-websocket
 * client. The JS provider refreshes tokens periodically, so this is the
 * maximum window between refreshes.
 */
define( 'WPE_RTC_VERSION', '0.13.0' );
define( 'WPE_RTC_TOKEN_TTL', 3600 ); // 1 hour

/*
 * Configuration helpers.
 */

/**
 * Get the JWT secret. Resolution order:
 *   1. WPE_RTC_JWT_SECRET constant (explicit override)
 *   2. WPE_APIKEY constant (auto-detected on WP Engine sites)
 *   3. FW_WPE_APIKEY constant (auto-detected on Flywheel sites)
 *
 * @return string The JWT secret, or empty string if not configured.
 */
function wpe_rtc_get_jwt_secret(): string {
	if ( defined( 'WPE_RTC_JWT_SECRET' ) && WPE_RTC_JWT_SECRET ) {
		return WPE_RTC_JWT_SECRET;
	}

	if ( defined( 'WPE_APIKEY' ) && WPE_APIKEY ) {
		return WPE_APIKEY;
	}

	if ( defined( 'FW_WPE_APIKEY' ) && FW_WPE_APIKEY ) {
		return FW_WPE_APIKEY;
	}

	return '';
}

/**
 * Map an RTC region slug to its WebSocket server URL.
 *
 * @param string $region One of 'us', 'eu', 'apac'.
 * @return string The WebSocket server URL.
 */
function wpe_rtc_region_to_url( string $region ): string {
	switch ( $region ) {
		case 'eu':
			return 'wss://eu.prd.wp-rtc.wpengine.com';
		case 'apac':
			return 'wss://apac.prd.wp-rtc.wpengine.com';
		default:
			return 'wss://us.prd.wp-rtc.wpengine.com';
	}
}

/**
 * Detect the nearest RTC region from the WPE_DATACENTER constant.
 *
 * Uses substring matching on well-known cloud provider region naming
 * conventions (GCP, AWS, Azure). Runs once at site registration and
 * the result is cached in the transient — not called on every request.
 *
 * @return string One of 'us', 'eu', 'apac'.
 */
function wpe_rtc_detect_region(): string {
	if ( defined( 'WPE_DATACENTER' ) && WPE_DATACENTER ) {
		$dc = strtolower( WPE_DATACENTER );

		foreach ( array( 'eu', 'europe', 'uk', 'uae', 'me-' ) as $token ) {
			if ( strpos( $dc, $token ) !== false ) {
				return 'eu';
			}
		}

		foreach ( array( 'asia', 'australia', 'ap' ) as $token ) {
			if ( strpos( $dc, $token ) !== false ) {
				return 'apac';
			}
		}
	}

	return 'us';
}

/**
 * Get the WebSocket server URL. Resolution order:
 *   1. WPE_RTC_WS_URL constant (explicit override)
 *   2. Region cached in the site registration transient (set once per 24h)
 *   3. wpe_rtc_detect_region() — only runs if no transient cache exists
 *
 * @return string The WebSocket server URL.
 */
function wpe_rtc_get_ws_url(): string {
	if ( defined( 'WPE_RTC_WS_URL' ) && WPE_RTC_WS_URL ) {
		return WPE_RTC_WS_URL;
	}

	if ( wpe_rtc_is_hosted_site() ) {
		$cached = get_transient( 'wpe_rtc_site_registered_' . wpe_rtc_get_site_id() );
		if ( is_array( $cached ) && ! empty( $cached['region'] ) && is_string( $cached['region'] ) ) {
			return wpe_rtc_region_to_url( $cached['region'] );
		}

		return wpe_rtc_region_to_url( wpe_rtc_detect_region() );
	}

	return '';
}

/**
 * Get the site identifier used to scope rooms. Resolution order:
 *   1. WPE_RTC_SITE_ID constant (explicit override)
 *   2. PWP_NAME constant (auto-detected on WP Engine sites)
 *   3. FW_PWP_HASH constant (auto-detected on Flywheel sites)
 *   4. SHA-256 hash of home_url() (fallback for non-hosted sites)
 *
 * @return string A unique identifier for this site.
 */
function wpe_rtc_get_site_id(): string {
	if ( defined( 'WPE_RTC_SITE_ID' ) && WPE_RTC_SITE_ID ) {
		return WPE_RTC_SITE_ID;
	}

	if ( defined( 'PWP_NAME' ) && PWP_NAME ) {
		return PWP_NAME;
	}

	if ( defined( 'FW_PWP_HASH' ) && FW_PWP_HASH ) {
		return FW_PWP_HASH;
	}

	return hash( 'sha256', home_url() );
}

/**
 * Check if this site is running on WP Engine (has auto-configured credentials).
 *
 * @return bool True if WPE_APIKEY and PWP_NAME are both available.
 */
function wpe_rtc_is_wpe_site(): bool {
	return defined( 'WPE_APIKEY' ) && WPE_APIKEY
		&& defined( 'PWP_NAME' ) && PWP_NAME;
}

/**
 * Check if this site is running on Flywheel (has Flywheel-specific credentials).
 *
 * @return bool True if FW_WPE_APIKEY and FW_PWP_HASH are both available.
 */
function wpe_rtc_is_fw_site(): bool {
	return defined( 'FW_WPE_APIKEY' ) && FW_WPE_APIKEY
		&& defined( 'FW_PWP_HASH' ) && FW_PWP_HASH;
}

/**
 * Check if this site is running on a hosted platform (WP Engine or Flywheel).
 *
 * @return bool True if either WP Engine or Flywheel credentials are available.
 */
function wpe_rtc_is_hosted_site(): bool {
	return wpe_rtc_is_wpe_site() || wpe_rtc_is_fw_site();
}

/**
 * Get the platform identifier for room namespacing and server registration.
 *
 * @return string 'wpe' for WP Engine, 'fw' for Flywheel, 'dev' for local/unhosted.
 */
function wpe_rtc_get_platform(): string {
	if ( wpe_rtc_is_wpe_site() ) {
		return 'wpe';
	}

	if ( wpe_rtc_is_fw_site() ) {
		return 'fw';
	}

	return 'dev';
}

/*
 * Preflight checks.
 */

/**
 * Verify that the sync system is available (WordPress 7.0+).
 */
function wpe_rtc_check_dependencies(): bool {
	global $wp_version;
	return version_compare( $wp_version, '7.0-RC1', '>=' );
}


// Site Health debug information.
add_filter( 'debug_information', 'wpe_rtc_site_health_info' );

/**
 * Add WPE RTC debug information to the Site Health screen.
 *
 * @param array $info Existing debug information.
 * @return array Modified debug information.
 */
function wpe_rtc_site_health_info( array $info ): array {
	$platform = wpe_rtc_get_platform();

	if ( 'fw' === $platform ) {
		$health_label = __( 'Flywheel Real-Time Collaboration', 'wpe-rtc' );
	} else {
		$health_label = __( 'WP Engine Real-Time Collaboration', 'wpe-rtc' );
	}

	if ( ! wpe_rtc_check_dependencies() ) {
		$info['wpe-rtc'] = array(
			'label'  => $health_label,
			'fields' => array(
				'version' => array(
					'label' => __( 'Version', 'wpe-rtc' ),
					'value' => WPE_RTC_VERSION,
				),
				'status'  => array(
					'label' => __( 'Status', 'wpe-rtc' ),
					'value' => __( 'Not active — requires WordPress 7.0+', 'wpe-rtc' ),
				),
			),
		);

		return $info;
	}

	$is_hosted = wpe_rtc_is_hosted_site();
	$site_id   = wpe_rtc_get_site_id();

	// Read the actual option value without any filters — we no longer force it on.
	$enabled_locally = (bool) get_option( 'wp_collaboration_enabled', false );

	// Check if RTC is disabled at the account level via feature flags.
	$wpe_flags           = get_option( 'wpe_feature_flags', array() );
	$disabled_on_account = is_array( $wpe_flags ) && ! empty( $wpe_flags['rtc_disabled_on_account'] );

	if ( $disabled_on_account && ! $enabled_locally ) {
		$rtc_status = __( 'No (local settings & feature flag)', 'wpe-rtc' );
	} elseif ( $disabled_on_account ) {
		$rtc_status = __( 'No (feature flag)', 'wpe-rtc' );
	} elseif ( ! $enabled_locally ) {
		$rtc_status = __( 'No (local settings)', 'wpe-rtc' );
	} else {
		$rtc_status = __( 'Yes', 'wpe-rtc' );
	}

	if ( wpe_rtc_is_wpe_site() ) {
		$auth_mode = __( 'WP Engine', 'wpe-rtc' );
	} elseif ( wpe_rtc_is_fw_site() ) {
		$auth_mode = __( 'Flywheel', 'wpe-rtc' );
	} elseif ( defined( 'WPE_RTC_JWT_SECRET' ) && WPE_RTC_JWT_SECRET ) {
		$auth_mode = __( 'Shared secret (WPE_RTC_JWT_SECRET constant)', 'wpe-rtc' );
	} else {
		$auth_mode = __( 'Not configured', 'wpe-rtc' );
	}

	$fields = array(
		'version'     => array(
			'label' => __( 'Version', 'wpe-rtc' ),
			'value' => WPE_RTC_VERSION,
		),
		'rtc_enabled' => array(
			'label' => __( 'RTC Enabled', 'wpe-rtc' ),
			'value' => $rtc_status,
		),
		'ws_url'      => array(
			'label'   => __( 'WebSocket URL', 'wpe-rtc' ),
			'value'   => wpe_rtc_get_ws_url(),
			'private' => true,
		),
		'auth_mode'   => array(
			'label' => __( 'Authentication', 'wpe-rtc' ),
			'value' => $auth_mode,
		),
		'site_id'     => array(
			'label'   => __( 'Site ID', 'wpe-rtc' ),
			'value'   => $site_id,
			'private' => true,
		),
	);

	if ( $is_hosted ) {
		$cached = get_transient( 'wpe_rtc_site_registered_' . $site_id );

		if ( is_array( $cached ) && ! empty( $cached['registered_at'] ) && is_int( $cached['registered_at'] ) ) {
			$reg_value = __( 'Registered', 'wpe-rtc' ) . ' (' . sprintf(
				/* translators: %s: human-readable time difference */
				__( '%s ago', 'wpe-rtc' ),
				human_time_diff( $cached['registered_at'] )
			) . ')';
		} else {
			$reg_value = __( 'Not registered', 'wpe-rtc' );
		}

		$fields['site_registered'] = array(
			'label' => __( 'Site registration', 'wpe-rtc' ),
			'value' => $reg_value,
		);
	}

	$info['wpe-rtc'] = array(
		'label'  => $health_label,
		'fields' => $fields,
	);

	return $info;
}

// Only initialize the RTC functionality if everything is configured.
add_action( 'plugins_loaded', 'wpe_rtc_maybe_init' );

/**
 * Gate check called on plugins_loaded. Verifies dependencies, collaboration
 * setting, feature flags, and credentials before initialising the plugin.
 *
 * @return void
 */
function wpe_rtc_maybe_init(): void {
	if ( ! wpe_rtc_check_dependencies() ) {
		return;
	}

	if ( ! get_option( 'wp_collaboration_enabled' ) ) {
		return;
	}

	// Allow account-level disabling via WPE feature flags (read from cached option to avoid triggering HTTP fetch).
	$wpe_flags = get_option( 'wpe_feature_flags', array() );
	if ( is_array( $wpe_flags ) && ! empty( $wpe_flags['rtc_disabled_on_account'] ) ) {
		return;
	}

	if ( empty( wpe_rtc_get_jwt_secret() ) || empty( wpe_rtc_get_ws_url() ) ) {
		return;
	}

	wpe_rtc_init();
}

/**
 * Initialize RTC hooks for the REST API and block editor script enqueue.
 *
 * @return void
 */
function wpe_rtc_init(): void {
	// Register the JWT token REST endpoint.
	add_action( 'rest_api_init', 'wpe_rtc_register_rest_routes' );

	// Enqueue the WebSocket provider script in the block editor.
	add_action( 'enqueue_block_editor_assets', 'wpe_rtc_enqueue_provider' );
}

/**
 * Register the WPE-RTC REST API routes for JWT token generation.
 *
 * @return void
 */
function wpe_rtc_register_rest_routes(): void {
	register_rest_route(
		'wpe-rtc/v1',
		'/collab/token',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'wpe_rtc_generate_token',
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'room'           => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'force_register' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		)
	);
}

/**
 * Generate a JWT token for WebSocket authentication.
 *
 * @param WP_REST_Request $request The REST request.
 * @return WP_REST_Response|WP_Error Response with token or error.
 */
function wpe_rtc_generate_token( WP_REST_Request $request ) {
	$room = $request->get_param( 'room' );

	$permission_check = wpe_rtc_can_user_access_room( $room );

	if ( is_wp_error( $permission_check ) ) {
		return $permission_check;
	}

	// Ensure the site is registered with the WebSocket server.
	// Uses the transient cache to avoid hammering /register-site when
	// multiple token requests arrive concurrently on page load.
	// The client passes force_register=true after a disconnect to handle
	// server redeploys that wipe the in-memory site cache.
	$force = (bool) $request->get_param( 'force_register' );
	wpe_rtc_register_site( $force );

	$now     = time();
	$user_id = get_current_user_id();
	$site_id = wpe_rtc_get_site_id();

	$payload = array(
		'user_id'   => $user_id,
		'site_id'   => $site_id,
		'room_name' => $room,
		'blog_id'   => get_current_blog_id(),
		'iat'       => $now,
		'exp'       => $now + WPE_RTC_TOKEN_TTL,
	);

	$token = wpe_rtc_jwt_encode( $payload );

	if ( is_wp_error( $token ) ) {
		return $token;
	}

	return new WP_REST_Response( array( 'token' => $token ), 200 );
}

/**
 * Check if the current user can access a specific room.
 *
 * Room name format: {platform}/{site_id}/{blog_id}/{entity_kind}/{entity_name}:{object_id}
 * Examples: wpe/abc123/1/postType/post:119, fw/abc123/2/taxonomy/category
 *
 * This mirrors Gutenberg's can_user_sync_entity_type() in
 * class-gutenberg-http-polling-sync-server.php.
 *
 * @param string $room Room identifier.
 * @return true|WP_Error True if allowed, WP_Error if not.
 */
function wpe_rtc_can_user_access_room( string $room ) {
	$parts           = explode( '/', $room, 5 );
	$valid_platforms = array( 'wpe', 'fw', 'dev' );

	if ( 5 !== count( $parts ) || ! in_array( $parts[0], $valid_platforms, true ) ) {
		return new WP_Error(
			'invalid_room_format',
			'Invalid room identifier.',
			array( 'status' => 400 )
		);
	}

	$room_platform     = $parts[0];
	$expected_platform = wpe_rtc_get_platform();

	// Enforce that the room prefix matches the current platform.
	if ( $room_platform !== $expected_platform ) {
		return new WP_Error(
			'invalid_platform',
			'Access denied.',
			array( 'status' => 403 )
		);
	}

	$room_site_id = $parts[1];
	$room_blog_id = $parts[2];
	$entity_kind  = $parts[3];
	$object_parts = explode( ':', $parts[4], 2 );
	$entity_name  = $object_parts[0];
	$object_id    = $object_parts[1] ?? null;

	// Verify the site_id in the room matches this site.
	if ( wpe_rtc_get_site_id() !== $room_site_id ) {
		return new WP_Error(
			'invalid_site',
			'Access denied.',
			array( 'status' => 403 )
		);
	}

	// Verify the blog_id in the room matches the current blog.
	if ( (string) get_current_blog_id() !== $room_blog_id ) {
		return new WP_Error(
			'invalid_blog',
			'Access denied.',
			array( 'status' => 403 )
		);
	}

	if ( 'postType' === $entity_kind && null !== $object_id && is_numeric( $object_id ) ) {
		if ( ! current_user_can( 'edit_post', absint( $object_id ) ) ) {
			return new WP_Error(
				'forbidden',
				'You do not have permission to access this resource.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	if ( 'root' === $entity_kind && 'comment' === $entity_name && null !== $object_id && is_numeric( $object_id ) ) {
		if ( ! current_user_can( 'edit_comment', absint( $object_id ) ) ) {
			return new WP_Error(
				'forbidden',
				'You do not have permission to access this resource.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	if ( null === $object_id ) {
		if ( 'postType' === $entity_kind ) {
			$post_type_obj = get_post_type_object( $entity_name );
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is dynamically resolved from the registered post type object.
			if ( ! $post_type_obj || ! current_user_can( $post_type_obj->cap->edit_posts ) ) {
				return new WP_Error(
					'forbidden',
					'You do not have permission to access this resource.',
					array( 'status' => 403 )
				);
			}
			return true;
		}

		if ( 'taxonomy' === $entity_kind ) {
			$taxonomy_obj = get_taxonomy( $entity_name );
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is dynamically resolved from the registered taxonomy object.
			if ( ! $taxonomy_obj || ! current_user_can( $taxonomy_obj->cap->edit_terms ) ) {
				return new WP_Error(
					'forbidden',
					'You do not have permission to access this resource.',
					array( 'status' => 403 )
				);
			}
			return true;
		}

		if ( 'root' === $entity_kind ) {
			if ( ! current_user_can( 'edit_posts' ) ) {
				return new WP_Error(
					'forbidden',
					'You do not have permission to access this resource.',
					array( 'status' => 403 )
				);
			}
			return true;
		}
	}

	return new WP_Error(
		'forbidden',
		'You do not have permission to access this resource.',
		array( 'status' => 403 )
	);
}

/*
 * JWT encoding.
 */

/**
 * Encode a JWT token using HS256.
 *
 * @param array $payload The token payload.
 * @return string|WP_Error The encoded JWT string or error.
 */
function wpe_rtc_jwt_encode( array $payload ) {
	$secret = wpe_rtc_get_jwt_secret();

	if ( empty( $secret ) ) {
		return new WP_Error(
			'jwt_not_configured',
			'JWT secret is not configured.',
			array( 'status' => 500 )
		);
	}

	// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Deliberate: JWT segments must use raw json_encode for deterministic output used in cryptographic signing.
	$header = wpe_rtc_base64url_encode(
		json_encode(
			array(
				'alg' => 'HS256',
				'typ' => 'JWT',
			)
		)
	);

	$body = wpe_rtc_base64url_encode( json_encode( $payload ) );
	// phpcs:enable WordPress.WP.AlternativeFunctions.json_encode_json_encode
	$sig = wpe_rtc_base64url_encode( hash_hmac( 'sha256', "$header.$body", $secret, true ) );

	return "$header.$body.$sig";
}

/**
 * Base64url-encode a string (RFC 4648 §5).
 *
 * @param string $data The data to encode.
 * @return string The base64url-encoded string.
 */
function wpe_rtc_base64url_encode( string $data ): string {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for JWT base64url encoding (RFC 4648 §5).
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/*
 * Server-side site registration.
 */

/**
 * Convert a WebSocket URL to its HTTP equivalent for API calls.
 *
 * @param string $ws_url The WebSocket URL (ws:// or wss://).
 * @return string The HTTP URL (http:// or https://).
 */
function wpe_rtc_ws_to_http_url( string $ws_url ): string {
	return preg_replace( '/^wss:\/\//', 'https://', preg_replace( '/^ws:\/\//', 'http://', $ws_url ) );
}

/**
 * Register this WPE site with the WebSocket server.
 *
 * Sends the site credentials directly to the WebSocket server via a
 * server-to-server HTTP POST. This keeps the JWT signing secret (WPE_APIKEY)
 * out of the browser entirely.
 *
 * Uses a WordPress transient to avoid making this call on every page load.
 * Pass $force = true to bypass the transient (e.g. during token refresh,
 * in case the server was redeployed and lost its in-memory site cache).
 *
 * @param bool $force Skip the transient check and always register.
 */
function wpe_rtc_register_site( bool $force = false ): void {
	if ( ! wpe_rtc_is_hosted_site() ) {
		return;
	}

	$site_id       = wpe_rtc_get_site_id();
	$transient_key = 'wpe_rtc_site_registered_' . $site_id;

	if ( ! $force && get_transient( $transient_key ) ) {
		return;
	}

	$ws_url   = wpe_rtc_get_ws_url();
	$http_url = wpe_rtc_ws_to_http_url( $ws_url );
	$endpoint = trailingslashit( $http_url ) . 'register-site';

	$response = wp_remote_post(
		$endpoint,
		array(
			'body'    => wp_json_encode(
				array(
					'site_id'     => $site_id,
					'site_secret' => wpe_rtc_get_jwt_secret(),
					'platform'    => wpe_rtc_get_platform(),
				)
			),
			'headers' => array( 'Content-Type' => 'application/json' ),
			'timeout' => 5,
		)
	);

	$failed_transient_key = 'wpe_rtc_registration_failed_' . $site_id;

	if ( is_wp_error( $response ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: registration failures must be logged for debugging.
		error_log( '[WPE-RTC] Site registration failed: ' . $response->get_error_message() );
		set_transient( $failed_transient_key, true, 60 );
		return;
	}

	$status_code = wp_remote_retrieve_response_code( $response );

	if ( 200 === $status_code ) {
		delete_transient( $failed_transient_key );
		set_transient(
			$transient_key,
			array(
				'registered_at' => time(),
				'region'        => wpe_rtc_detect_region(),
			),
			DAY_IN_SECONDS
		);
	} else {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: registration failures must be logged for debugging.
		error_log( '[WPE-RTC] Site registration returned HTTP ' . $status_code );
		set_transient( $failed_transient_key, true, 60 );
	}
}

/*
 * Script enqueue.
 */

/**
 * Enqueue a minimal script that registers an empty sync providers list,
 * effectively disabling collaboration without falling back to HTTP polling.
 */
function wpe_rtc_enqueue_no_providers(): void {
	wp_enqueue_script( 'wpe-rtc-no-providers', '', array( 'wp-hooks' ), WPE_RTC_VERSION, true );
	wp_add_inline_script(
		'wpe-rtc-no-providers',
		"console.warn( '[WPE-RTC] Real-time collaboration is temporarily unavailable. The editor will work normally without collaboration features.' );\n" .
		"wp.hooks.addFilter( 'sync.providers', 'wpe-rtc/ws-provider', function() { return []; } );"
	);
}

/**
 * Enqueue the WebSocket provider script in the block editor.
 */
function wpe_rtc_enqueue_provider(): void {
	// If site registration recently failed, disable collaboration for 60 seconds
	// instead of falling back to HTTP polling or hammering a broken WS server.
	if ( get_transient( 'wpe_rtc_registration_failed_' . wpe_rtc_get_site_id() ) ) {
		wpe_rtc_enqueue_no_providers();
		return;
	}

	$asset_file = plugin_dir_path( __FILE__ ) . 'build/index.asset.php';

	if ( ! file_exists( $asset_file ) ) {
		return;
	}

	// Register the site with the WS server before the client connects.
	wpe_rtc_register_site();

	$asset = require $asset_file;

	// Original source available in wpe-rtc:/wp-plugin/src/.
	if ( defined( 'WPE_RTC_IS_MU_PLUGIN' ) && WPE_RTC_IS_MU_PLUGIN && defined( 'WPE_PLUGIN_URL' ) ) {
		$script_url = trailingslashit( WPE_PLUGIN_URL ) . 'rtc/build/index.js';
	} else {
		$script_url = plugins_url( 'build/index.js', __FILE__ );
	}

	wp_enqueue_script(
		'wpe-rtc-provider',
		$script_url,
		$asset['dependencies'],
		$asset['version'],
		true
	);

	$platform = wpe_rtc_get_platform();

	$settings = array(
		'wsUrl'         => wpe_rtc_get_ws_url(),
		'siteId'        => wpe_rtc_get_site_id(),
		'blogId'        => (string) get_current_blog_id(),
		'platform'      => $platform,
		'tokenEndpoint' => rest_url( 'wpe-rtc/v1/collab/token' ),
		'nonce'         => wp_create_nonce( 'wp_rest' ),
		'debug'         => defined( 'WPE_RTC_DEBUG' ) && WPE_RTC_DEBUG,
	);

	wp_add_inline_script(
		'wpe-rtc-provider',
		'var wpeRtcSettings = ' . wp_json_encode( $settings ) . ';',
		'before'
	);
}

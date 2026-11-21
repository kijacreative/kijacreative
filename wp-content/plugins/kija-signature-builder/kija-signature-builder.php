<?php
/**
 * Plugin Name:       Kija Signature Builder
 * Plugin URI:        https://kijacreative.com
 * Description:       Builds a Gmail-ready email signature. Photos are uploaded straight into this site's media library, so every recipient can load them.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Kija Creative
 * Author URI:        https://kijacreative.com
 * License:           GPL-2.0-or-later
 * Text Domain:       kija-signature-builder
 *
 * @package KijaSignatureBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KJSB_VERSION', '1.0.0' );
define( 'KJSB_FILE', __FILE__ );
define( 'KJSB_DIR', plugin_dir_path( __FILE__ ) );
define( 'KJSB_URL', plugin_dir_url( __FILE__ ) );

/**
 * The capability needed to open the builder and upload a photo.
 *
 * @return string
 */
function kjsb_capability() {
	return apply_filters( 'kjsb_capability', 'upload_files' );
}

/**
 * The square photo size used in the signature.
 */
const KJSB_PHOTO_SIZE = 'kija-sig-photo';

/**
 * Edge length of the signature photo, in pixels.
 *
 * Twice the 104px the signature renders at, so it stays sharp on retina screens.
 */
const KJSB_PHOTO_PX = 208;

/**
 * Register the hard-cropped 1:1 photo size.
 */
function kjsb_register_image_size() {
	add_image_size( KJSB_PHOTO_SIZE, KJSB_PHOTO_PX, KJSB_PHOTO_PX, true );
}
add_action( 'after_setup_theme', 'kjsb_register_image_size' );

/**
 * Return a 1:1 URL for an attachment, cropping it now if that size is missing.
 *
 * Email clients ignore CSS cropping, so the square has to exist as a real file.
 * Images added before this plugin was activated have no `kija-sig-photo` size,
 * so one is generated on demand and recorded in the attachment's metadata.
 *
 * @param int $attachment_id Attachment ID.
 * @return string Square image URL, or '' if the crop could not be made.
 */
function kjsb_square_photo_url( $attachment_id ) {
	$attachment_id = absint( $attachment_id );

	if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
		return '';
	}

	$existing = wp_get_attachment_image_src( $attachment_id, KJSB_PHOTO_SIZE );

	// Index 3 is true only when WordPress served a real intermediate file.
	if ( is_array( $existing ) && ! empty( $existing[3] ) && ! empty( $existing[0] ) ) {
		return $existing[0];
	}

	$file = get_attached_file( $attachment_id );

	if ( ! $file || ! file_exists( $file ) ) {
		return '';
	}

	$editor = wp_get_image_editor( $file );

	if ( is_wp_error( $editor ) ) {
		return '';
	}

	$resized = $editor->resize( KJSB_PHOTO_PX, KJSB_PHOTO_PX, true );

	if ( is_wp_error( $resized ) ) {
		return '';
	}

	$saved = $editor->save();

	if ( is_wp_error( $saved ) || empty( $saved['file'] ) ) {
		return '';
	}

	$meta = wp_get_attachment_metadata( $attachment_id );

	if ( ! is_array( $meta ) ) {
		$meta = array();
	}

	if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
		$meta['sizes'] = array();
	}

	$meta['sizes'][ KJSB_PHOTO_SIZE ] = array(
		'file'      => $saved['file'],
		'width'     => isset( $saved['width'] ) ? (int) $saved['width'] : KJSB_PHOTO_PX,
		'height'    => isset( $saved['height'] ) ? (int) $saved['height'] : KJSB_PHOTO_PX,
		'mime-type' => isset( $saved['mime-type'] ) ? $saved['mime-type'] : get_post_mime_type( $attachment_id ),
	);

	wp_update_attachment_metadata( $attachment_id, $meta );

	$base = wp_get_attachment_url( $attachment_id );

	if ( ! $base ) {
		return '';
	}

	return trailingslashit( dirname( $base ) ) . $saved['file'];
}

/**
 * Send /signature to the builder, so the team has a URL worth remembering.
 *
 * Signed-out visitors land on the login screen and come back here afterwards.
 */
function kjsb_friendly_url_redirect() {
	if ( is_admin() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$request = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	$path    = wp_parse_url( $request, PHP_URL_PATH );
	$path    = is_string( $path ) ? strtolower( trim( $path, '/' ) ) : '';

	if ( 'signature' !== $path ) {
		return;
	}

	wp_safe_redirect( admin_url( 'tools.php?page=kija-signature-builder' ) );
	exit;
}
add_action( 'template_redirect', 'kjsb_friendly_url_redirect' );

/**
 * Brand defaults shared by everyone on the team.
 *
 * Per-person fields (name, title, email, phone, photo) are filled from the
 * logged-in user's own WordPress profile.
 *
 * @return array
 */
function kjsb_defaults() {
	$user = wp_get_current_user();
	$host = wp_parse_url( home_url(), PHP_URL_HOST );

	$defaults = array(
		'name'         => $user->exists() ? $user->display_name : '',
		'role'         => '',
		'company'      => get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'Kija Creative',
		'website'      => $host ? preg_replace( '/^www\./', '', $host ) : 'kijacreative.com',
		'email'        => $user->exists() ? $user->user_email : '',
		'phone'        => '',
		'photoOn'      => true,
		'photoUrl'     => '',
		'photoId'      => 0,
		'links'        => array(
			array(
				'label' => '@kijacreative',
				'url'   => 'https://www.instagram.com/kijacreative/',
			),
		),
		'bookingOn'    => true,
		'bookingLabel' => 'Book a call →',
		'bookingUrl'   => '',
		'accent'       => '#AE0F64',
		'c2'           => '#F9E400',
		'c3'           => '#5DFF92',
		'barColor'     => '#101010',
		'stripesOn'    => true,
		'barOn'        => true,
	);

	return apply_filters( 'kjsb_defaults', $defaults );
}

/**
 * Clean a submitted or stored profile against the default shape.
 *
 * Unknown keys are dropped; every value is cast to the type the builder expects.
 *
 * @param mixed $raw Candidate profile.
 * @return array
 */
function kjsb_sanitize_profile( $raw ) {
	$defaults = kjsb_defaults();

	if ( ! is_array( $raw ) ) {
		return $defaults;
	}

	$clean = $defaults;

	foreach ( array( 'name', 'role', 'company', 'website', 'phone', 'bookingLabel' ) as $key ) {
		if ( isset( $raw[ $key ] ) ) {
			$clean[ $key ] = sanitize_text_field( (string) $raw[ $key ] );
		}
	}

	if ( isset( $raw['email'] ) ) {
		$clean['email'] = sanitize_email( (string) $raw['email'] );
	}

	foreach ( array( 'photoUrl', 'bookingUrl' ) as $key ) {
		if ( isset( $raw[ $key ] ) ) {
			$clean[ $key ] = esc_url_raw( trim( (string) $raw[ $key ] ) );
		}
	}

	if ( isset( $raw['photoId'] ) ) {
		$clean['photoId'] = absint( $raw['photoId'] );
	}

	foreach ( array( 'photoOn', 'bookingOn', 'stripesOn', 'barOn' ) as $key ) {
		if ( isset( $raw[ $key ] ) ) {
			$clean[ $key ] = (bool) $raw[ $key ];
		}
	}

	foreach ( array( 'accent', 'c2', 'c3', 'barColor' ) as $key ) {
		if ( isset( $raw[ $key ] ) ) {
			$hex = sanitize_hex_color( (string) $raw[ $key ] );
			if ( $hex ) {
				$clean[ $key ] = $hex;
			}
		}
	}

	if ( isset( $raw['links'] ) && is_array( $raw['links'] ) ) {
		$links = array();
		foreach ( array_slice( $raw['links'], 0, 8 ) as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$label = isset( $link['label'] ) ? sanitize_text_field( (string) $link['label'] ) : '';
			$url   = isset( $link['url'] ) ? esc_url_raw( trim( (string) $link['url'] ) ) : '';
			if ( '' === $label && '' === $url ) {
				continue;
			}
			$links[] = array(
				'label' => $label,
				'url'   => $url,
			);
		}
		$clean['links'] = $links;
	}

	return $clean;
}

/**
 * Read the signing-in user's saved profile, falling back to the defaults.
 *
 * @return array
 */
function kjsb_get_profile() {
	$saved = get_user_meta( get_current_user_id(), 'kjsb_profile', true );

	if ( empty( $saved ) || ! is_array( $saved ) ) {
		return kjsb_defaults();
	}

	return kjsb_sanitize_profile( $saved );
}

/**
 * Add the builder under Tools.
 */
function kjsb_admin_menu() {
	$hook = add_management_page(
		__( 'Signature Builder', 'kija-signature-builder' ),
		__( 'Signature Builder', 'kija-signature-builder' ),
		kjsb_capability(),
		'kija-signature-builder',
		'kjsb_render_page'
	);

	if ( $hook ) {
		add_action( 'load-' . $hook, 'kjsb_enqueue_assets_hook' );
	}
}
add_action( 'admin_menu', 'kjsb_admin_menu' );

/**
 * Only load the builder's assets on its own screen.
 */
function kjsb_enqueue_assets_hook() {
	add_action( 'admin_enqueue_scripts', 'kjsb_enqueue_assets' );
}

/**
 * Enqueue the builder's styles, script, and the media library modal.
 */
function kjsb_enqueue_assets() {
	wp_enqueue_media();

	wp_enqueue_style(
		'kjsb-builder',
		KJSB_URL . 'assets/builder.css',
		array(),
		KJSB_VERSION
	);

	wp_enqueue_script(
		'kjsb-builder',
		KJSB_URL . 'assets/builder.js',
		array(),
		KJSB_VERSION,
		true
	);

	wp_localize_script(
		'kjsb-builder',
		'KJSB',
		array(
			'restUrl'  => esc_url_raw( rest_url( 'kija-signature/v1/profile' ) ),
			'cropUrl'  => esc_url_raw( rest_url( 'kija-signature/v1/square' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'profile'  => kjsb_get_profile(),
			'defaults' => kjsb_defaults(),
			'strings'  => array(
				'frameTitle'  => __( 'Signature photo', 'kija-signature-builder' ),
				'frameButton' => __( 'Use this photo', 'kija-signature-builder' ),
				'saved'       => __( 'Saved to your profile', 'kija-signature-builder' ),
				'cropping'    => __( 'Cropping to a square…', 'kija-signature-builder' ),
				'cropFailed'  => __( 'Could not crop that image — using it as it is.', 'kija-signature-builder' ),
				'saveFailed'  => __( 'Could not save — copy your signature before leaving.', 'kija-signature-builder' ),
				'copied'      => __( 'Copied ✓', 'kija-signature-builder' ),
				'copyFailed'  => __( 'Use Copy HTML', 'kija-signature-builder' ),
			),
		)
	);
}

/**
 * REST: read and write the current user's signature profile.
 */
function kjsb_register_rest_routes() {
	register_rest_route(
		'kija-signature/v1',
		'/profile',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'kjsb_rest_get_profile',
				'permission_callback' => 'kjsb_rest_permission',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'kjsb_rest_save_profile',
				'permission_callback' => 'kjsb_rest_permission',
			),
		)
	);

	register_rest_route(
		'kija-signature/v1',
		'/square',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'kjsb_rest_square_photo',
			'permission_callback' => 'kjsb_rest_permission',
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'kjsb_register_rest_routes' );

/**
 * Only signed-in users who can upload may read or write a profile.
 *
 * @return bool
 */
function kjsb_rest_permission() {
	return current_user_can( kjsb_capability() );
}

/**
 * REST GET handler.
 *
 * @return WP_REST_Response
 */
function kjsb_rest_get_profile() {
	return rest_ensure_response( kjsb_get_profile() );
}

/**
 * REST POST handler.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function kjsb_rest_save_profile( WP_REST_Request $request ) {
	$profile = kjsb_sanitize_profile( $request->get_json_params() );

	update_user_meta( get_current_user_id(), 'kjsb_profile', $profile );

	return rest_ensure_response(
		array(
			'saved'   => true,
			'profile' => $profile,
		)
	);
}

/**
 * REST: return a 1:1 crop of an attachment, making one if it does not exist.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function kjsb_rest_square_photo( WP_REST_Request $request ) {
	$attachment_id = absint( $request->get_param( 'id' ) );

	if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
		return new WP_Error(
			'kjsb_not_an_image',
			__( 'That attachment is not an image in this media library.', 'kija-signature-builder' ),
			array( 'status' => 404 )
		);
	}

	$url = kjsb_square_photo_url( $attachment_id );

	if ( ! $url ) {
		return new WP_Error(
			'kjsb_crop_failed',
			__( 'This image could not be cropped to a square. Pick another one, or crop it before uploading.', 'kija-signature-builder' ),
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response(
		array(
			'url'    => $url,
			'width'  => KJSB_PHOTO_PX,
			'height' => KJSB_PHOTO_PX,
		)
	);
}

/**
 * Render the builder screen.
 */
function kjsb_render_page() {
	if ( ! current_user_can( kjsb_capability() ) ) {
		wp_die( esc_html__( 'You do not have permission to use the signature builder.', 'kija-signature-builder' ) );
	}
	?>
	<div class="wrap kjsb-wrap">
		<div class="kjsb-head">
			<p class="kjsb-eyebrow"><?php esc_html_e( 'Kija Creative · Brand assets', 'kija-signature-builder' ); ?></p>
			<h1 class="kjsb-title">
				<?php esc_html_e( 'Signature', 'kija-signature-builder' ); ?>
				<span class="kjsb-shadowed"><?php esc_html_e( 'Builder', 'kija-signature-builder' ); ?></span>
			</h1>
			<p class="kjsb-lede">
				<?php esc_html_e( 'Fill in your details, pick a photo, and copy a Gmail-ready signature. Photos you add here are uploaded to this site\'s media library, so they load for everyone you email.', 'kija-signature-builder' ); ?>
			</p>
		</div>

		<div class="kjsb-grid">
			<section class="kjsb-panel">
				<div class="kjsb-panel-head">
					<span class="kjsb-tag"><?php esc_html_e( 'Details', 'kija-signature-builder' ); ?></span>
					<span class="kjsb-status" id="kjsb-status" role="status" aria-live="polite"></span>
				</div>

				<div class="kjsb-stack">
					<fieldset>
						<legend><?php esc_html_e( 'Person', 'kija-signature-builder' ); ?></legend>
						<div class="kjsb-fields">
							<p class="kjsb-field">
								<label for="kjsb-name"><?php esc_html_e( 'Name', 'kija-signature-builder' ); ?></label>
								<input id="kjsb-name" type="text" data-key="name" autocomplete="off">
							</p>
							<p class="kjsb-field">
								<label for="kjsb-role"><?php esc_html_e( 'Title', 'kija-signature-builder' ); ?></label>
								<input id="kjsb-role" type="text" data-key="role" autocomplete="off">
							</p>
							<p class="kjsb-field">
								<label for="kjsb-company"><?php esc_html_e( 'Company', 'kija-signature-builder' ); ?></label>
								<input id="kjsb-company" type="text" data-key="company" autocomplete="off">
							</p>
							<p class="kjsb-field">
								<label for="kjsb-website"><?php esc_html_e( 'Website (shown in the bar)', 'kija-signature-builder' ); ?></label>
								<input id="kjsb-website" type="text" data-key="website" autocomplete="off">
							</p>
							<p class="kjsb-field">
								<label for="kjsb-email"><?php esc_html_e( 'Email', 'kija-signature-builder' ); ?></label>
								<input id="kjsb-email" type="email" data-key="email" autocomplete="off">
							</p>
							<p class="kjsb-field">
								<label for="kjsb-phone"><?php esc_html_e( 'Phone', 'kija-signature-builder' ); ?></label>
								<input id="kjsb-phone" type="tel" data-key="phone" autocomplete="off">
							</p>
						</div>
					</fieldset>

					<fieldset>
						<legend><?php esc_html_e( 'Photo', 'kija-signature-builder' ); ?></legend>
						<div class="kjsb-column">
							<label class="kjsb-toggle">
								<input id="kjsb-photoOn" type="checkbox" data-key="photoOn">
								<?php esc_html_e( 'Show photo', 'kija-signature-builder' ); ?>
							</label>

							<div class="kjsb-media">
								<span class="kjsb-thumb" id="kjsb-thumb" aria-hidden="true"></span>
								<div class="kjsb-media-copy">
									<strong><?php esc_html_e( 'Upload to the media library', 'kija-signature-builder' ); ?></strong>
									<span id="kjsb-photo-note"><?php esc_html_e( 'Drag a file into the uploader or pick one you have already added. It is cropped to a 1:1 square from the centre.', 'kija-signature-builder' ); ?></span>
								</div>
								<div class="kjsb-media-actions">
									<button type="button" class="button button-secondary" id="kjsb-pick"><?php esc_html_e( 'Choose photo', 'kija-signature-builder' ); ?></button>
									<button type="button" class="button-link kjsb-remove" id="kjsb-clear-photo" hidden><?php esc_html_e( 'Remove', 'kija-signature-builder' ); ?></button>
								</div>
							</div>

							<p class="kjsb-field">
								<label for="kjsb-photoUrl"><?php esc_html_e( 'Photo URL', 'kija-signature-builder' ); ?></label>
								<input id="kjsb-photoUrl" type="url" data-key="photoUrl" autocomplete="off">
							</p>
							<p class="kjsb-note" id="kjsb-photo-origin"></p>
						</div>
					</fieldset>

					<fieldset>
						<legend><?php esc_html_e( 'Links', 'kija-signature-builder' ); ?></legend>
						<div class="kjsb-column">
							<div id="kjsb-links" class="kjsb-column"></div>
							<p><button type="button" class="button button-secondary" id="kjsb-add-link"><?php esc_html_e( 'Add link', 'kija-signature-builder' ); ?></button></p>
						</div>
					</fieldset>

					<fieldset>
						<legend><?php esc_html_e( 'Booking button', 'kija-signature-builder' ); ?></legend>
						<div class="kjsb-column">
							<label class="kjsb-toggle">
								<input id="kjsb-bookingOn" type="checkbox" data-key="bookingOn">
								<?php esc_html_e( 'Show booking button', 'kija-signature-builder' ); ?>
							</label>
							<div class="kjsb-fields">
								<p class="kjsb-field">
									<label for="kjsb-bookingLabel"><?php esc_html_e( 'Button text', 'kija-signature-builder' ); ?></label>
									<input id="kjsb-bookingLabel" type="text" data-key="bookingLabel" autocomplete="off">
								</p>
								<p class="kjsb-field">
									<label for="kjsb-bookingUrl"><?php esc_html_e( 'Booking link', 'kija-signature-builder' ); ?></label>
									<input id="kjsb-bookingUrl" type="url" data-key="bookingUrl" placeholder="https://calendly.com/..." autocomplete="off">
								</p>
							</div>
						</div>
					</fieldset>

					<fieldset>
						<legend><?php esc_html_e( 'Colors &amp; trim', 'kija-signature-builder' ); ?></legend>
						<div class="kjsb-column">
							<div class="kjsb-swatches">
								<label class="kjsb-swatch" for="kjsb-accent"><input id="kjsb-accent" type="color" data-key="accent"> <?php esc_html_e( 'Accent', 'kija-signature-builder' ); ?></label>
								<label class="kjsb-swatch" for="kjsb-c2"><input id="kjsb-c2" type="color" data-key="c2"> <?php esc_html_e( 'Stripe 2', 'kija-signature-builder' ); ?></label>
								<label class="kjsb-swatch" for="kjsb-c3"><input id="kjsb-c3" type="color" data-key="c3"> <?php esc_html_e( 'Stripe 3', 'kija-signature-builder' ); ?></label>
								<label class="kjsb-swatch" for="kjsb-barColor"><input id="kjsb-barColor" type="color" data-key="barColor"> <?php esc_html_e( 'Bar', 'kija-signature-builder' ); ?></label>
							</div>
							<label class="kjsb-toggle">
								<input id="kjsb-stripesOn" type="checkbox" data-key="stripesOn">
								<?php esc_html_e( 'Show color stripe', 'kija-signature-builder' ); ?>
							</label>
							<label class="kjsb-toggle">
								<input id="kjsb-barOn" type="checkbox" data-key="barOn">
								<?php esc_html_e( 'Show company bar', 'kija-signature-builder' ); ?>
							</label>
							<p><button type="button" class="button-link kjsb-remove" id="kjsb-reset-colors"><?php esc_html_e( 'Reset colors to brand', 'kija-signature-builder' ); ?></button></p>
						</div>
					</fieldset>
				</div>
			</section>

			<div class="kjsb-preview-wrap">
				<section class="kjsb-panel">
					<div class="kjsb-panel-head">
						<span class="kjsb-tag"><?php esc_html_e( 'Preview', 'kija-signature-builder' ); ?></span>
						<span class="kjsb-tag"><?php esc_html_e( '512px wide', 'kija-signature-builder' ); ?></span>
					</div>
					<div class="kjsb-stage">
						<div class="kjsb-sep"></div>
						<div id="kjsb-out"></div>
					</div>
					<div class="kjsb-actions">
						<button type="button" class="button button-primary" id="kjsb-copy"><?php esc_html_e( 'Copy signature', 'kija-signature-builder' ); ?></button>
						<button type="button" class="button button-secondary" id="kjsb-copy-html"><?php esc_html_e( 'Copy HTML', 'kija-signature-builder' ); ?></button>
					</div>
					<details>
						<summary><?php esc_html_e( 'HTML source', 'kija-signature-builder' ); ?></summary>
						<pre class="kjsb-code" id="kjsb-code"></pre>
					</details>
				</section>

				<ol class="kjsb-steps">
					<li><?php esc_html_e( 'Click Copy signature.', 'kija-signature-builder' ); ?></li>
					<li><?php esc_html_e( 'In Gmail, open Settings → See all settings → General → Signature.', 'kija-signature-builder' ); ?></li>
					<li><?php esc_html_e( 'Create or pick a signature, paste into the box, then Save changes and send yourself a test.', 'kija-signature-builder' ); ?></li>
					<li><?php esc_html_e( 'If a client strips the paste, use Copy HTML and paste the source into an editor that takes raw HTML.', 'kija-signature-builder' ); ?></li>
				</ol>
			</div>
		</div>
	</div>
	<?php
}

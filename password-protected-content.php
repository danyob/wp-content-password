<?php
/**
 * Plugin Name: Password Protected Content
 * Description: Hide part of a page behind a password. Works with page builders.
 *              Wrap:      [protected password="secret"]Hidden content[/protected]
 *              Or split:  [protected_start password="secret"] ...any blocks / widgets... [protected_end]
 * Version:     2.0.0
 * Requires PHP: 7.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PPC_COOKIE_LIFETIME = 10 * DAY_IN_SECONDS;
const PPC_MAX_ATTEMPTS    = 10;                  // Wrong passwords allowed per IP per block...
const PPC_LOCKOUT         = 15 * MINUTE_IN_SECONDS; // ...within this window.

/**
 * How it works
 * ------------
 * The shortcodes only print invisible HTML comment markers. The whole page is
 * output-buffered, and just before it is sent to the browser everything between
 * a start marker and its end marker is either kept (unlocked / editor) or cut out
 * and replaced with the password form. Because this happens on the final HTML,
 * the start and end can live in different page-builder widgets, sections or
 * blocks, and the hidden content never reaches the visitor's browser.
 */

/**
 * ID for one protected area: a keyed hash of the page + password, so the
 * password can't be recovered from the ID that appears in the form.
 */
function ppc_block_id( $object_id, $password ) {
	return substr( hash_hmac( 'sha256', $object_id . '|' . $password, wp_salt( 'auth' ) ), 0, 16 );
}

/**
 * Signed token stored in the cookie once unlocked.
 */
function ppc_token( $block_id ) {
	return hash_hmac( 'sha256', 'unlock|' . $block_id, wp_salt( 'auth' ) );
}

/**
 * Secret part of the markers, so nobody can inject fake markers (e.g. in a comment).
 */
function ppc_marker_key() {
	return substr( hash_hmac( 'sha256', 'ppc-marker', wp_salt( 'auth' ) ), 0, 12 );
}

function ppc_can_view( $block_id ) {
	$object_id = get_queried_object_id();

	if ( is_singular() ? current_user_can( 'edit_post', $object_id ) : current_user_can( 'edit_others_posts' ) ) {
		return true; // Editors always see the content.
	}

	$name = 'ppc_' . $block_id;

	return isset( $_COOKIE[ $name ] ) && hash_equals( ppc_token( $block_id ), (string) $_COOKIE[ $name ] );
}

function ppc_current_url() {
	return ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . wp_unslash( $_SERVER['REQUEST_URI'] );
}

/* -------------------------------------------------------------------------
 * Shortcodes
 * ---------------------------------------------------------------------- */

function ppc_start_marker( $atts ) {
	$atts = shortcode_atts(
		array(
			'password' => '',
			'message'  => __( 'This content is password protected. Enter the password to view it.' ),
			'button'   => __( 'Unlock' ),
		),
		$atts,
		'protected_start'
	);

	if ( '' === $atts['password'] ) {
		return '';
	}

	// Pages with protected content must not be stored in a full-page cache.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	if ( ! headers_sent() ) {
		nocache_headers();
	}

	$block_id = ppc_block_id( get_queried_object_id(), $atts['password'] );
	$options  = base64_encode( wp_json_encode( array( 'message' => $atts['message'], 'button' => $atts['button'] ) ) );

	return '<!--ppc-start:' . ppc_marker_key() . ':' . $block_id . ':' . $options . '-->';
}

function ppc_end_marker() {
	return '<!--ppc-end:' . ppc_marker_key() . '-->';
}

add_shortcode( 'protected_start', 'ppc_start_marker' );
add_shortcode( 'protected_end', 'ppc_end_marker' );

add_shortcode( 'protected', function ( $atts, $content = '' ) {
	$start = ppc_start_marker( $atts );

	return '' === $start ? '' : $start . do_shortcode( $content ) . ppc_end_marker();
} );

/* -------------------------------------------------------------------------
 * Form submission
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', function () {
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['ppc_block'] ) ) {
		return;
	}

	$block_id = sanitize_key( wp_unslash( $_POST['ppc_block'] ) );
	$given    = isset( $_POST['ppc_password'] ) ? (string) wp_unslash( $_POST['ppc_password'] ) : '';
	$back     = remove_query_arg( array( 'ppc_error', 'ppc_limit' ), ppc_current_url() );

	// Basic brute-force protection.
	$attempts_key = 'ppc_' . md5( $_SERVER['REMOTE_ADDR'] . '|' . $block_id );
	$attempts     = (int) get_transient( $attempts_key );

	if ( $attempts >= PPC_MAX_ATTEMPTS ) {
		wp_safe_redirect( add_query_arg( array( 'ppc_error' => $block_id, 'ppc_limit' => 1 ), $back ) . '#ppc-' . $block_id );
		exit;
	}

	// The correct password reproduces the block ID, so no need to look up the shortcode.
	if ( '' !== $given && hash_equals( $block_id, ppc_block_id( get_queried_object_id(), $given ) ) ) {
		delete_transient( $attempts_key );
		setcookie(
			'ppc_' . $block_id,
			ppc_token( $block_id ),
			array(
				'expires'  => time() + PPC_COOKIE_LIFETIME,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		wp_safe_redirect( $back . '#ppc-' . $block_id );
		exit;
	}

	set_transient( $attempts_key, $attempts + 1, PPC_LOCKOUT );
	wp_safe_redirect( add_query_arg( 'ppc_error', $block_id, $back ) . '#ppc-' . $block_id );
	exit;
}, 0 );

/* -------------------------------------------------------------------------
 * Output filtering
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', function () {
	ob_start( 'ppc_filter_output' );
}, PHP_INT_MAX );

function ppc_filter_output( $html ) {
	if ( false === strpos( $html, '<!--ppc-start:' ) ) {
		return $html;
	}

	$key     = ppc_marker_key();
	$pattern = '#<!--ppc-start:' . $key . ':([a-f0-9]+):([A-Za-z0-9+/=]*)-->#';
	$end     = ppc_end_marker();
	$offset  = 0;

	while ( preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
		$start      = $m[0][1];
		$inner_from = $start + strlen( $m[0][0] );
		$stop       = strpos( $html, $end, $inner_from );

		// A missing [protected_end] hides everything to the end of the page (fail closed).
		$inner_to = false === $stop ? strlen( $html ) : $stop;
		$resume   = false === $stop ? strlen( $html ) : $stop + strlen( $end );
		$inner    = substr( $html, $inner_from, $inner_to - $inner_from );
		$block_id = $m[1][0];

		if ( ppc_can_view( $block_id ) ) {
			$replacement = $inner;
		} else {
			$options     = json_decode( base64_decode( $m[2][0] ), true );
			$replacement = ppc_form( $block_id, is_array( $options ) ? $options : array() ) . ppc_structural_tags( $inner );
		}

		$html   = substr( $html, 0, $start ) . $replacement . substr( $html, $resume );
		$offset = $start + strlen( $replacement );
	}

	return str_replace( $end, '', $html );
}

/**
 * When the hidden area spans several builder widgets/sections, the removed HTML
 * contains closing tags for wrappers opened before it and opening tags for
 * wrappers closed after it. Keep only those, so the page layout stays intact.
 */
function ppc_structural_tags( $html ) {
	$html  = preg_replace( '#<(script|style|textarea|template)\b.*?</\1\s*>|<!--.*?-->#is', '', $html );
	$void  = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );
	$open  = array();
	$close = array();

	preg_match_all( '#<(/?)([a-z][a-z0-9-]*)(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i', $html, $tags, PREG_SET_ORDER );

	foreach ( $tags as $tag ) {
		$name = strtolower( $tag[2] );

		if ( in_array( $name, $void, true ) || '/>' === substr( $tag[0], -2 ) ) {
			continue;
		}

		if ( '' === $tag[1] ) {
			$open[] = array( $name, $tag[0] );
			continue;
		}

		for ( $i = count( $open ) - 1; $i >= 0 && $open[ $i ][0] !== $name; $i-- );

		if ( $i >= 0 ) {
			array_splice( $open, $i );
		} else {
			$close[] = $tag[0];
		}
	}

	return implode( '', $close ) . implode( '', array_column( $open, 1 ) );
}

/**
 * Builds the form as a string (ob_start() can't be used inside an output buffer callback).
 */
function ppc_form( $block_id, $options ) {
	$message = isset( $options['message'] ) ? $options['message'] : '';
	$button  = isset( $options['button'] ) ? $options['button'] : __( 'Unlock' );
	$error   = isset( $_GET['ppc_error'] ) && $_GET['ppc_error'] === $block_id;
	$notice  = '';

	if ( $error && ! empty( $_GET['ppc_limit'] ) ) {
		$notice = '<p class="ppc-error" role="alert">' . esc_html__( 'Too many attempts. Please try again later.' ) . '</p>';
	} elseif ( $error ) {
		$notice = '<p class="ppc-error" role="alert">' . esc_html__( 'Incorrect password, please try again.' ) . '</p>';
	}

	return '<div class="ppc-locked" id="ppc-' . esc_attr( $block_id ) . '">'
		. '<p class="ppc-message">' . esc_html( $message ) . '</p>'
		. $notice
		. '<form method="post" action="' . esc_url( remove_query_arg( array( 'ppc_error', 'ppc_limit' ) ) ) . '">'
		. '<input type="hidden" name="ppc_block" value="' . esc_attr( $block_id ) . '">'
		. '<label><span class="screen-reader-text">' . esc_html__( 'Password' ) . '</span>'
		. '<input type="password" name="ppc_password" required autocomplete="off"></label>'
		. '<button type="submit">' . esc_html( $button ) . '</button>'
		. '</form></div>';
}

/**
 * Minimal default styling. Override in your theme as needed.
 */
add_action( 'wp_head', function () {
	echo '<style>
		.ppc-locked{border:1px solid #ddd;padding:1.25em;border-radius:6px;background:#f9f9f9;margin:1.5em 0}
		.ppc-locked form{display:flex;gap:.5em;flex-wrap:wrap}
		.ppc-locked input[type=password]{flex:1;min-width:180px;padding:.5em}
		.ppc-error{color:#b32d2e;font-weight:600}
	</style>';
} );

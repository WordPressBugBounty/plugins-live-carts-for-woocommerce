<?php
namespace BerryPress\LiveCarts;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks a "visitor email" for the current cart: the email address entered
 * into the WooCommerce checkout email field(s), synced into the WC session
 * via AJAX as it's typed, and/or the logged-in user's account email.
 *
 * Precedence between the two (when both are available) is configurable via
 * the "Email address precedence" setting on Settings -> General (rendered
 * and saved directly by AdminPage::settingsPage() / LiveCarts::saveSettings()
 * rather than through a hook, since that runs on every admin settings
 * save/render).
 *
 * LiveCarts::createNewCart() also calls resolveEmailForNewCart() directly
 * (not via a filter) for the same reason.
 */
class CartEmail {

	const SESSION_KEY = 'phplugins_carts_email';
	const OPTION_PRECEDENCE = 'phplugins_carts_email_precedence';
	const AJAX_ACTION = 'phplugins_carts_save_visitor_email';
	const NONCE_ACTION = 'phplugins_carts_visitor_email';

	private static $instance;

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// Frontend: sync the checkout email field(s) into the WC session as they change.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueueFrontendScript' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajaxSaveCartEmail' ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ $this, 'ajaxSaveCartEmail' ] );

		// Keep an existing cart's email in sync with login / account email changes.
		add_action( 'wp_login', [ $this, 'onUserLogin' ], 10, 2 );
		add_action( 'profile_update', [ $this, 'onProfileUpdate' ], 10, 2 );
	}

	/**
	 * @return string 'user' or 'entered'.
	 */
	public static function getPrecedence() {
		return get_option( self::OPTION_PRECEDENCE ) === 'entered' ? 'entered' : 'user';
	}

	// -----------------------------------------------------------------
	// New cart creation
	// -----------------------------------------------------------------

	/**
	 * Resolves the initial `email` column for a newly created cart: the
	 * entered email or the user's account email, whichever is available; if
	 * both are available, the precedence setting decides. Called directly
	 * from LiveCarts::createNewCart(), which already wraps the call in its
	 * own try/catch (best-effort: a failure here must never block cart
	 * creation).
	 */
	public function resolveEmailForNewCart() {
		$enteredEmail = $this->getEnteredEmail();
		$userEmail    = $this->getCurrentUserEmail();

		if ( $enteredEmail && $userEmail ) {
			return self::getPrecedence() === 'user' ? $userEmail : $enteredEmail;
		}

		return $enteredEmail ?: $userEmail;
	}

	private function getEnteredEmail() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		return sanitize_email( (string) WC()->session->get( self::SESSION_KEY, '' ) ) ?: null;
	}

	private function getCurrentUserEmail() {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		return sanitize_email( wp_get_current_user()->user_email ) ?: null;
	}

	private function getCurrentCartId() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		return (int) WC()->session->get( 'phplugins_cart_id', 0 ) ?: null;
	}

	private function updateCartEmail( $cartId, $email ) {
		global $wpdb;

		if ( ! $cartId ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'phplugins_carts',
			[ 'email' => sanitize_email( (string) $email ) ],
			[ 'cart_id' => $cartId ],
			[ 'email' => '%s' ],
			[ 'cart_id' => '%d' ]
		);

		do_action( 'phplugins_live_carts_cart_email_updated', $cartId, $email );
	}

	// -----------------------------------------------------------------
	// Frontend: sync the checkout email field(s) into the WC session
	// -----------------------------------------------------------------

	// lc-hook-safety: skip — only enqueues a script with static args and localizes static data, no DB / IO / 3rd-party calls
	public function enqueueFrontendScript() {
		$selector = 'form.woocommerce-checkout #billing_email, .wc-block-checkout #email, .wp-block-woocommerce-checkout #email';
		
		try {
			// Scoped to WooCommerce's own checkout containers (classic + Blocks), not bare
			// IDs, since #billing_email/#email could collide with unrelated forms on the page.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$selector = apply_filters('phplugins_live_carts_email_field_selector', $selector);
		} catch ( \Exception $ex ) {
			LiveCarts::instance()->debugLog( $ex->getMessage(), __FILE__, __LINE__ );
		} catch ( \Error $err ) {
			LiveCarts::instance()->debugLog( $err->getMessage(), __FILE__, __LINE__ );
		}

		wp_enqueue_script(
			'phplugins-live-carts-visitor-email',
			LiveCarts::$plugin_url . 'assets/js/frontend-visitor-email' . ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min' ) . '.js',
			[],
			LiveCarts::VERSION,
			true
		);

		wp_localize_script(
			'phplugins-live-carts-visitor-email',
			'phplugins_live_carts_email_tracking',
			[
				'selector' => $selector,
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'action'   => self::AJAX_ACTION,
				'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
			]
		);
	}

	public function ajaxSaveCartEmail() {
		try {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! function_exists( 'WC' ) || ! WC()->session ) {
				wp_send_json_error();
				return;
			}

			$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			if ( $email && ! is_email( $email ) ) {
				wp_send_json_error( [ 'message' => __( 'Invalid email address', 'live-carts-for-woocommerce' ) ] );
				return;
			}

			WC()->session->set( self::SESSION_KEY, $email );

			$this->setEmailForCart( $email );

			wp_send_json_success();
			return;
		} catch ( \Exception $ex ) {
			LiveCarts::instance()->debugLog( $ex->getMessage(), __FILE__, __LINE__ );
		} catch ( \Error $err ) {
			LiveCarts::instance()->debugLog( $err->getMessage(), __FILE__, __LINE__ );
		}
		wp_send_json_error();
	}

	/**
	 * If the entered email changes, update the cart's email when the user is
	 * logged out, or when "entered email" takes precedence.
	 */
	public function setEmailForCart( $enteredEmail ) {
		$cartId = $this->getCurrentCartId();
		if ( ! $cartId ) {
			return;
		}

		if ( ! is_user_logged_in() || self::getPrecedence() === 'entered' ) {
			$this->updateCartEmail( $cartId, $enteredEmail );
		}
	}

	// -----------------------------------------------------------------
	// Login / account email changes
	// -----------------------------------------------------------------

	public function onUserLogin( $user_login, $user ) {
		try {
			if ( $user instanceof \WP_User && $user->user_email ) {
				$this->maybeUpdateCartEmailFromUserAccount( $user->user_email );
			}
		} catch ( \Exception $ex ) {
			LiveCarts::instance()->debugLog( $ex->getMessage(), __FILE__, __LINE__ );
		} catch ( \Error $err ) {
			LiveCarts::instance()->debugLog( $err->getMessage(), __FILE__, __LINE__ );
		}
	}

	public function onProfileUpdate( $user_id, $old_user_data ) {
		try {
			$user = get_userdata( $user_id );
			if ( ! $user || ! $user->user_email ) {
				return;
			}

			$oldEmail = is_object( $old_user_data ) && isset( $old_user_data->user_email ) ? $old_user_data->user_email : null;
			if ( $oldEmail === $user->user_email ) {
				// Email didn't change; nothing to do (avoids overwriting an "entered
				// email" precedence choice on unrelated profile field updates).
				return;
			}

			$this->maybeUpdateCartEmailFromUserAccount( $user->user_email );
		} catch ( \Exception $ex ) {
			LiveCarts::instance()->debugLog( $ex->getMessage(), __FILE__, __LINE__ );
		} catch ( \Error $err ) {
			LiveCarts::instance()->debugLog( $err->getMessage(), __FILE__, __LINE__ );
		}
	}

	/**
	 * If a user logs in or changes their account email, update the cart's
	 * email when there's no "entered email" in the session, or when "user
	 * email" takes precedence.
	 */
	private function maybeUpdateCartEmailFromUserAccount( $userEmail ) {
		$cartId = $this->getCurrentCartId();
		if ( ! $cartId ) {
			return;
		}

		$enteredEmail = $this->getEnteredEmail();
		if ( ! $enteredEmail || self::getPrecedence() === 'user' ) {
			$this->updateCartEmail( $cartId, $userEmail );
		}
	}
}

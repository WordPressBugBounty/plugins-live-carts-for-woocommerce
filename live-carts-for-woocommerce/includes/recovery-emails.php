<?php

namespace BerryPress\LiveCarts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BerryPress\LiveCarts\LiveCarts;

/**
 * Class RecoveryEmails
 * 
 * Handles cart recovery email functionality for abandoned carts.
 */
class RecoveryEmails {

	const POST_TYPE = 'lc_recovery_email';
	const META_TIME_VALUE = '_lc_recovery_time_value';
	const META_TIME_UNIT = '_lc_recovery_time_unit';
	
	private static $instance;

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - registers hooks for recovery email features.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'registerPostType' ) );
	}

	/**
	 * Registers the recovery email custom post type.
	 * This is used for data storage but UI is handled in the Live Carts admin page.
	 */
	public function registerPostType() {
		$args = array(
			'labels'                => array( 'name' => __( 'Recovery Emails', 'live-carts-for-woocommerce' ) ),
			'public'                => false,
			'publicly_queryable'    => false,
			'show_ui'               => false, // Hide from admin menu
			'show_in_menu'          => false,
			'show_in_nav_menus'     => false,
			'show_in_admin_bar'     => false,
			'query_var'             => false,
			'rewrite'               => false,
			'capability_type'       => 'post',
			'capabilities'          => array(
				'edit_post'          => LiveCarts::ADMIN_CAPABILITY,
				'read_post'          => LiveCarts::ADMIN_CAPABILITY,
				'delete_post'        => LiveCarts::ADMIN_CAPABILITY,
				'edit_posts'         => LiveCarts::ADMIN_CAPABILITY,
				'edit_others_posts'  => LiveCarts::ADMIN_CAPABILITY,
				'delete_posts'       => LiveCarts::ADMIN_CAPABILITY,
				'publish_posts'      => LiveCarts::ADMIN_CAPABILITY,
				'read_private_posts' => LiveCarts::ADMIN_CAPABILITY,
			),
			'has_archive'           => false,
			'hierarchical'          => false,
			'supports'              => array( 'title', 'editor' ),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Gets formatted time interval string.
	 *
	 * @param int $post_id Post ID.
	 * @return string Formatted time interval.
	 */
	public static function getTimeIntervalString( $post_id ) {
		$time_value = get_post_meta( $post_id, self::META_TIME_VALUE, true );
		$time_unit = get_post_meta( $post_id, self::META_TIME_UNIT, true );
		
		if ( $time_value && $time_unit ) {
			$unit_labels = array(
				'minutes' => _n( 'minute', 'minutes', $time_value, 'live-carts-for-woocommerce' ),
				'hours'   => _n( 'hour', 'hours', $time_value, 'live-carts-for-woocommerce' ),
				'days'    => _n( 'day', 'days', $time_value, 'live-carts-for-woocommerce' ),
			);
			
			return $time_value . ' ' . $unit_labels[ $time_unit ];
		}
		
		return '—';
	}

	/**
	 * Checks for carts that need recovery emails and sends them.
	 */
	public function checkAndSendRecoveryEmails() {
		global $wpdb;

		// Get all published recovery emails
		$recovery_emails = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		) );

		if ( empty( $recovery_emails ) ) {
			return;
		}

		foreach ( $recovery_emails as $email_post ) {
			$time_value = get_post_meta( $email_post->ID, self::META_TIME_VALUE, true );
			$time_unit = get_post_meta( $email_post->ID, self::META_TIME_UNIT, true );

			if ( empty( $time_value ) || empty( $time_unit ) ) {
				continue;
			}

			// Calculate the threshold timestamp
			$seconds = $this->convertToSeconds( $time_value, $time_unit );
			$threshold_time = wp_date( 'Y-m-d H:i:s', time() - $seconds, new \DateTimeZone( 'UTC' ) );

			// Find carts that match this threshold and haven't received this email yet
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$carts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.cart_id, c.user_id, c.email, c.recovery_key, c.last_seen
					FROM {$wpdb->prefix}phplugins_carts c
					LEFT JOIN {$wpdb->prefix}phplugins_recovery_emails_sent s 
						ON c.cart_id = s.cart_id AND s.email_id = %d
					WHERE c.status != 'converted'
						AND trashed = 0
						AND c.type = %s
						AND c.last_seen <= %s
						AND c.last_seen >= %s
						AND s.sent_id IS NULL
					LIMIT 50",
					$email_post->ID,
					ShopperLists::TYPE_CART,
					$threshold_time,
					wp_date( 'Y-m-d H:i:s', time() - ( $seconds + WEEK_IN_SECONDS ), new \DateTimeZone( 'UTC' ) ) // Don't send to very old carts
				)
			);

			foreach ( $carts as $cart ) {
				try {
					// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
					set_time_limit(120);
					$this->sendRecoveryEmail( $email_post, $cart );
				} catch (\Throwable $ex) { }
			}
		}
	}

	/**
	 * Sends a recovery email for a specific cart.
	 *
	 * @param \WP_Post $email_post Recovery email post object.
	 * @param object   $cart       Cart data.
	 */
	private function sendRecoveryEmail( $email_post, $cart ) {
		global $wpdb;
		
		if (!class_exists('WC_Emails')) {
			return;
		}
		
		// Resolve email: tracked column first, then account email for logged-in users.
		$email = $cart->email ?? '';
		if ( empty( $email ) && ! empty( $cart->user_id ) ) {
			$user = get_userdata( (int) $cart->user_id );
			$email = $user ? $user->user_email : '';
		}
		if ( empty( $email ) ) {
			return;
		}

		// Get user data
		$user_data = array_merge( [ 'email' => $email ], $this->getUserData( $cart->user_id ) );
		
		// Get cart contents
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$contents_id = $wpdb->get_var( 
			$wpdb->prepare( 
				"SELECT contents_id FROM {$wpdb->prefix}phplugins_cart_contents WHERE cart_id = %d ORDER BY contents_id DESC LIMIT 1", 
				$cart->cart_id 
			) 
		);
		
		$cart_contents = LiveCarts::getCartContentsArray( $contents_id );
		
		// Skip if cart is empty
		if ( empty( $cart_contents ) ) {
			return;
		}
		
		// Allow hooks to skip this email
		if (!apply_filters( 'phplugins_live_carts_should_send_recovery_email', true, $cart, $email_post )) {
			return;
		}
		
		// Get recovery URL
		if ( empty( $cart->recovery_key ) ) {
			$cart->recovery_key = LiveCarts::generateCartRecoveryKey($cart->cart_id);
		}
		
		$recovery_url = add_query_arg( 'recover_cart', $cart->recovery_key, wc_get_cart_url() );
		
		// Prepare shortcode replacements
		$replacements = array(
			'[first_name]'    => $user_data['first_name'],
			'[cart_contents]' => $this->formatCartContents( $cart_contents ),
			'[cart_url]'      => $recovery_url,
		);
		
		// Allow filtering of replacements
		$replacements = apply_filters( 'phplugins_live_carts_recovery_email_shortcode_replacements', $replacements, $cart, $email_post );
		
		// Process subject and body
		$subject = str_replace( array_keys( $replacements ), array_values( $replacements ), $email_post->post_title );
		$body = str_replace( array_keys( $replacements ), array_values( $replacements ), wpautop( $email_post->post_content ) );
		
		// Send email
		$to = $user_data['email'];
		$senderName  = get_option( 'phplugins_carts_recovery_sender_name', '' ) ?: \WC_Emails::instance()->get_from_name();
		$senderEmail = get_option( 'phplugins_carts_recovery_sender_email', '' ) ?: \WC_Emails::instance()->get_from_address();
		// Header injection is not possible here: sanitize_text_field() strips \n/\r on save,
		// sanitize_email() enforces RFC-valid chars, and PHPMailer validates headers internally.
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $senderName . ' <' . $senderEmail . '>',
		);
		
		// Allow filtering of email parameters
		$to      = apply_filters( 'phplugins_live_carts_recovery_email_to', $to, $cart, $email_post );
		$subject = apply_filters( 'phplugins_live_carts_recovery_email_subject', $subject, $cart, $email_post );
		$body    = apply_filters( 'phplugins_live_carts_recovery_email_body', $body, $cart, $email_post );
		$headers = apply_filters( 'phplugins_live_carts_recovery_email_headers', $headers, $cart, $email_post );

		// Wrap in WooCommerce email template.
		$body = WC()->mailer()->wrap_message( $subject, $body );

		// Inline CSS from email-styles.php — wrap_message() does not do this on its own.
		// WC_Email::send() calls style_inline() before applying the woocommerce_mail_content filter;
		// since we bypass WC_Email::send(), we borrow any initialised WC_Email instance.
		// Style settings come from store options, not from the specific email type, so any instance works.
		$wc_emails = WC()->mailer()->get_emails();
		$wc_email  = ! empty( $wc_emails ) ? reset( $wc_emails ) : null;
		if ( $wc_email instanceof \WC_Email && method_exists( $wc_email, 'style_inline' ) ) {
			$body = $wc_email->style_inline( $body );
		}

		$body = apply_filters( 'woocommerce_mail_content', $body );

		$sent = wp_mail( $to, $subject, $body, $headers );
		
		// Record that we sent this email (even if failed, to avoid retry loops)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'phplugins_recovery_emails_sent',
			array(
				'cart_id'     => $cart->cart_id,
				'email_id'    => $email_post->ID,
				'sent_at'     => current_time( 'mysql', true )
			),
			array( '%d', '%d', '%s' )
		);

		if ( $sent ) {
			do_action( 'phplugins_live_carts_recovery_email_sent', $cart->cart_id, $email_post->ID );
		}
	}

	/**
	 * Gets user data for email personalization.
	 *
	 * @param int $user_id User ID.
	 * @return array|false User data or false if no email available.
	 */
	private function getUserData( $user_id ) {
		$data = array(
			'first_name' => '',
		);

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$data['first_name'] = get_user_meta( $user_id, 'first_name', true );
				
				if ( empty( $data['first_name'] ) ) {
					$data['first_name'] = $user->display_name;
				}
				
				// Try billing first name as fallback
				if ( empty( $data['first_name'] ) ) {
					$data['first_name'] = get_user_meta( $user_id, 'billing_first_name', true );
				}
			}
		}

		// Fallback to generic greeting if no first name
		if ( empty( $data['first_name'] ) ) {
			$data['first_name'] = __( 'Customer', 'live-carts-for-woocommerce' );
		}

		return $data;
	}

	/**
	 * Formats cart contents as HTML list.
	 *
	 * @param array $cart_contents Cart contents array.
	 * @return string Formatted HTML.
	 */
	private function formatCartContents( $cart_contents ) {
		if ( empty( $cart_contents ) ) {
			return '';
		}

		$output = '<ul style="list-style-type: none; padding: 0; margin: 20px 0;">';
		
		foreach ( $cart_contents as $item ) {
			$product = wc_get_product( $item['variation_id'] ?: $item['product_id'] );
			
			if ( ! $product ) {
				continue;
			}

			$product_name = $product->get_name();
			$quantity = $item['quantity'];
			$price = isset( $item['line_total'] ) ? wc_price( $item['line_total'] ) : wc_price( $product->get_price() * $quantity );
			
			// Get product image
			$image_id = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );

			$item_html = sprintf(
				'<li style="list-style-type: none; margin-bottom: 15px; padding: 10px; background: #f7f7f7; border-radius: 4px;">
					<table style="width: 100%%; border-collapse: collapse;">
						<tr>
							<td style="width: 60px; vertical-align: top;">
								<img src="%s" alt="%s" style="width: 60px; height: auto; border-radius: 4px;">
							</td>
							<td style="padding-left: 12px; vertical-align: top;">
								<strong style="font-size: 14px;">%s</strong><br>
								<span style="color: #999; font-size: 13px;">%s × %d</span>
							</td>
							<td style="text-align: right; vertical-align: top;">
								<strong style="color: #333;">%s</strong>
							</td>
						</tr>
					</table>
				</li>',
				esc_url( $image_url ),
				esc_attr( $product_name ),
				esc_html( $product_name ),
				esc_html__( 'Quantity:', 'live-carts-for-woocommerce' ),
				(int) $quantity,
				$price // Already escaped by wc_price
			);
			
			// Allow filtering of individual item HTML
			$item_html = apply_filters( 'phplugins_live_carts_recovery_email_item_html', $item_html, $item, $product );
			
			$output .= $item_html;
		}
		
		$output .= '</ul>';
		
		// Allow filtering of entire cart contents HTML
		return apply_filters( 'phplugins_live_carts_recovery_email_cart_contents_html', $output, $cart_contents );
	}

	/**
	 * Converts time value and unit to seconds.
	 *
	 * @param int    $value Time value.
	 * @param string $unit  Time unit (minutes, hours, days).
	 * @return int Seconds.
	 */
	private function convertToSeconds( $value, $unit ) {
		switch ( $unit ) {
			case 'minutes':
				return $value * 60;
			case 'hours':
				return $value * 3600;
			case 'days':
				return $value * 86400;
			default:
				return $value * 3600; // Default to hours
		}
	}
}

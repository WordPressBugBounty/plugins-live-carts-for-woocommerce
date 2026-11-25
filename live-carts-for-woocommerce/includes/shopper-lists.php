<?php
namespace BerryPress\LiveCarts;

defined( 'ABSPATH' ) || exit;

class ShopperLists {

	const META_KEY_PREFIX = '_wc_shopper_list_';
	const TYPE_CART = 'cart';
	const TYPE_LIST_PREFIX = 'list_';

	private $hooksRegistered = false;

	private static $instance;

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function isAvailable() {
		return class_exists( '\Automattic\WooCommerce\Internal\ShopperLists\ShopperListsController' ) && wc_get_container()->get( '\Automattic\WooCommerce\Internal\ShopperLists\ShopperListsController' )->is_enabled();
	}

	public static function typeForSlug( $slug ) {
		return self::TYPE_LIST_PREFIX . $slug;
	}

	public static function slugFromType( $type ) {
		if ( ! self::isListType( $type ) ) {
			return '';
		}
		return substr( $type, strlen( self::TYPE_LIST_PREFIX ) );
	}

	public static function isListType( $type ) {
		return is_string( $type ) && strncmp( $type, self::TYPE_LIST_PREFIX, strlen( self::TYPE_LIST_PREFIX ) ) === 0;
	}

	public static function isCartType( $type ) {
		return empty( $type ) || $type === self::TYPE_CART;
	}

	public static function getListTypeLabels() {
		return apply_filters(
			'phplugins_live_carts_list_type_labels',
			[
				'wishlist'        => __( 'Wishlist', 'live-carts-for-woocommerce' ),
				'saved-for-later' => __( 'Saved for Later', 'live-carts-for-woocommerce' ),
			]
		);
	}

	public static function getListTypeLabel( $type ) {
		$slug = self::slugFromType( $type );
		if ( ! $slug ) {
			return '';
		}
		$labels = self::getListTypeLabels();
		if ( isset( $labels[ $slug ] ) ) {
			return $labels[ $slug ];
		}
		return ucwords( str_replace( '-', ' ', $slug ) );
	}

	public static function getAdminViewType() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- non-persistent filtering
		return ( isset( $_REQUEST['type'] ) && sanitize_key( wp_unslash( $_REQUEST['type'] ) ) === 'list' ) ? 'list' : 'cart';
	}

	public static function getSlugFromMetaKey( $meta_key ) {
		global $wpdb;

		if ( ! is_string( $meta_key ) || strncmp( $meta_key, self::META_KEY_PREFIX, strlen( self::META_KEY_PREFIX ) ) !== 0 ) {
			return '';
		}

		$slug = substr( $meta_key, strlen( self::META_KEY_PREFIX ) );
		$suffix = '_' . rtrim( $wpdb->get_blog_prefix( get_current_blog_id() ), '_' );
		if ( $suffix !== '_' && strlen( $slug ) > strlen( $suffix ) && substr( $slug, -strlen( $suffix ) ) === $suffix ) {
			$slug = substr( $slug, 0, -strlen( $suffix ) );
		}

		return $slug;
	}

	public function __construct() {
		add_action( 'woocommerce_init', [ $this, 'registerHooks' ] );
		add_action( 'wp_login', [ $this, 'syncListsForUser' ], 10, 2 );
	}

	public function registerHooks() {
		try {
			if ( $this->hooksRegistered || ! self::isAvailable() ) {
				return;
			}
	
			$this->hooksRegistered = true;
	
			add_action( 'updated_user_meta', [ $this, 'onUserMetaChanged' ], 10, 4 );
			add_action( 'added_user_meta', [ $this, 'onUserMetaChanged' ], 10, 4 );
			add_action( 'wp_footer', [ $this, 'listsSeen' ] );

			if (is_admin()) {
				$this->maybeBackfillStoredLists();
			}
		} catch ( \Exception $ex ) {
			LiveCarts::instance()->debugLog( $ex->getMessage(), __FILE__, __LINE__ );
		} catch ( \Error $err ) {
			LiveCarts::instance()->debugLog( $err->getMessage(), __FILE__, __LINE__ );
		}
	}

	public function syncListsForUser( $user_login, $user ) {
		try {
			if ( $user instanceof \WP_User ) {
				$this->syncEnabledListsForUser( (int) $user->ID );
			}
		} catch ( \Exception $ex ) {
			LiveCarts::instance()->debugLog( $ex->getMessage(), __FILE__, __LINE__ );
		} catch ( \Error $err ) {
			LiveCarts::instance()->debugLog( $err->getMessage(), __FILE__, __LINE__ );
		}
	}

	public function onUserMetaChanged( $meta_id, $user_id, $meta_key, $meta_value ) {
		try {
			$slug = self::getSlugFromMetaKey( $meta_key );
			if ( $slug === '' ) {
				return;
			}

			$this->syncListFromUserMeta( (int) $user_id, $slug, is_array( $meta_value ) ? $meta_value : null );
		} catch ( \Exception $ex ) {
			LiveCarts::instance()->debugLog( $ex->getMessage(), __FILE__, __LINE__ );
		} catch ( \Error $err ) {
			LiveCarts::instance()->debugLog( $err->getMessage(), __FILE__, __LINE__ );
		}
	}

	public function listsSeen() {
		global $wpdb;

		try {
			$user_id = get_current_user_id();
			if ( ! $user_id || ! get_option( 'phplugins_carts_migration_1_2_0_complete' ) ) {
				return;
			}

			$listTypeLike = $wpdb->esc_like( self::TYPE_LIST_PREFIX ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$listIds = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT cart_id FROM ' . $wpdb->prefix . 'phplugins_carts WHERE user_id=%d AND type LIKE %s',
					$user_id,
					$listTypeLike
				)
			);

			if ( empty( $listIds ) ) {
				$this->syncEnabledListsForUser( $user_id );
				return;
			}

			$updateValues = [
				'last_seen'  => current_time( 'mysql', true ),
				'archived'   => 0,
				'trashed'    => 0,
				'ip_address' => get_option( 'phplugins_carts_no_ip' ) ? '' : apply_filters( 'phplugins_live_carts_cart_ip_address', sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) ),
			];
			$updateFormats = [
				'last_seen'  => '%s',
				'archived'   => '%d',
				'trashed'    => '%d',
				'ip_address' => '%s',
			];

			if ( ! get_option( 'phplugins_carts_no_url' ) && ! is_404() && ! is_admin() && ! wp_doing_ajax() && ! wp_is_serving_rest_request() ) {
				$updateValues['last_url'] = add_query_arg( [] );
				$updateFormats['last_url'] = '%s';
			}

			foreach ( $listIds as $listId ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'phplugins_carts',
					$updateValues,
					[ 'cart_id' => (int) $listId ],
					$updateFormats,
					[ 'cart_id' => '%d' ]
				);
				do_action( 'phplugins_live_carts_list_seen', (int) $listId );
			}
		} catch ( \Exception $ex ) {
			LiveCarts::instance()->debugLog( $ex->getMessage(), __FILE__, __LINE__ );
		} catch ( \Error $err ) {
			LiveCarts::instance()->debugLog( $err->getMessage(), __FILE__, __LINE__ );
		}
	}

	public function syncEnabledListsForUser( $user_id ) {
		if ( ! self::isAvailable() ) {
			return;
		}

		$slugs = wc_get_container()->get( \Automattic\WooCommerce\Internal\ShopperLists\ShopperListsController::class )->get_enabled_slugs();
		foreach ( $slugs as $slug ) {
			$this->syncListFromUserMeta( $user_id, $slug );
		}
	}

	public function maybeBackfillStoredLists() {
		if ( get_option( 'phplugins_lc_lists_backfill_complete' ) ) {
			return;
		}

		$this->backfillStoredLists();
		update_option( 'phplugins_lc_lists_backfill_complete', 1 );
	}

	public function backfillStoredLists() {
		global $wpdb;

		if ( ! self::isAvailable() || ! get_option( 'phplugins_carts_migration_1_2_0_complete' ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, meta_key, meta_value FROM ' . $wpdb->usermeta . ' WHERE meta_key LIKE %s',
				$wpdb->esc_like( self::META_KEY_PREFIX ) . '%'
			)
		);

		if ( ! $rows ) {
			return;
		}

		foreach ( $rows as $row ) {
			$slug = self::getSlugFromMetaKey( $row->meta_key );
			if ( $slug === '' ) {
				continue;
			}
			$meta_value = maybe_unserialize( $row->meta_value );
			$this->syncListFromUserMeta( (int) $row->user_id, $slug, is_array( $meta_value ) ? $meta_value : null );
		}
	}

	public function syncListFromUserMeta( $user_id, $slug, $meta_value = null ) {
		global $wpdb;

		if ( ! get_option( 'phplugins_carts_migration_1_2_0_complete' ) || ! self::isAvailable() ) {
			return;
		}

		if ( ! is_array( $meta_value ) ) {
			$list = \Automattic\WooCommerce\Internal\ShopperLists\ShopperList::get_by_slug( $slug, $user_id );
			if ( ! $list ) {
				return;
			}
			$meta_value = $list->to_array();
		}

		$type    = self::typeForSlug( $slug );
		$created = ! empty( $meta_value['date_created_gmt'] ) ? $meta_value['date_created_gmt'] : current_time( 'mysql', true );
		$contents = $this->storedItemsToContents( $meta_value );
		$cart_id = $this->getOrCreateListRecord( $user_id, $type, $created, $contents );
		if ( ! $cart_id ) {
			return;
		}

		$value    = LiveCarts::calculateContentsValue( $contents );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'phplugins_carts',
			[
				'value'     => $value,
				'status'    => 'active',
				'last_seen' => current_time( 'mysql', true ),
				'archived'  => 0,
			],
			[ 'cart_id' => $cart_id ],
			[ 'value' => '%f', 'status' => '%s', 'last_seen' => '%s', 'archived' => '%d' ],
			[ 'cart_id' => '%d' ]
		);

		LiveCarts::saveCartContents( $cart_id, $contents );
	}

	private function getOrCreateListRecord( $user_id, $type, $created, $contents ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT cart_id, trashed FROM ' . $wpdb->prefix . 'phplugins_carts WHERE user_id=%d AND type=%s ORDER BY trashed ASC, cart_id ASC LIMIT 1',
				$user_id,
				$type
			)
		);

		if ( $row ) {
			return (int) $row->cart_id;
		}
		
		if (!apply_filters('phplugins_live_carts_should_create_list', !empty($contents), $type, $user_id)) {
			return;
		}

		do {
			$cart_id = random_int( 1, hexdec( 'FFFFFFFF' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$foundCartId = $wpdb->get_col( $wpdb->prepare( 'SELECT cart_id FROM ' . $wpdb->prefix . 'phplugins_carts WHERE cart_id=%d', $cart_id ) );
		} while ( $foundCartId );

		if ( empty( $created ) ) {
			$created = current_time( 'mysql', true );
		}

		list( $insertData, $insertDataFormat ) = apply_filters(
			'phplugins_live_carts_list_insert',
			[
				[
					'cart_id'    => $cart_id,
					'user_id'    => $user_id,
					'created'    => $created,
					'last_seen'  => current_time( 'mysql', true ),
					'status'     => 'active',
					'type'       => $type,
					'ip_address' => get_option( 'phplugins_carts_no_ip' ) ? '' : apply_filters( 'phplugins_live_carts_cart_ip_address', sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) ),
				],
				[
					'cart_id'    => '%d',
					'user_id'    => '%d',
					'created'    => '%s',
					'last_seen'  => '%s',
					'status'     => '%s',
					'type'       => '%s',
					'ip_address' => '%s',
				],
			],
			$type,
			$user_id,
			$created
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $wpdb->prefix . 'phplugins_carts', $insertData, $insertDataFormat );
		if ( ! $result ) {
			throw new \Exception( esc_html__( 'Shopper list record could not be created', 'live-carts-for-woocommerce' ) );
		}

		do_action( 'phplugins_live_carts_list_created', $cart_id, self::slugFromType( $type ), $user_id );

		return (int) $cart_id;
	}

	private function storedItemsToContents( array $stored ) {
		$contents = [];

		if ( empty( $stored['items'] ) || ! is_array( $stored['items'] ) ) {
			return $contents;
		}

		foreach ( $stored['items'] as $key => $item_data ) {
			if ( ! is_array( $item_data ) ) {
				continue;
			}

			try {
				$item = \Automattic\WooCommerce\Internal\ShopperLists\ShopperListItem::from_array( $item_data );
			} catch ( \Throwable $t ) {
				continue;
			}

			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$quantity     = $item->get_quantity();
			$product      = $item->get_product();
			$item_key     = $item->get_key();

			if ( $product ) {
				$line_subtotal = (float) wc_get_price_excluding_tax( $product, [ 'qty' => $quantity ] );
			} else {
				$line_subtotal = 0.0;
			}

			$contents[ $item_key ] = [
				//'key'               => $item_key,
				'product_id'        => $product_id,
				'variation_id'      => $variation_id,
				//'variation'         => $item->get_variation_attributes(),
				'quantity'          => $quantity,
				'line_subtotal'     => $line_subtotal,
				'line_subtotal_tax' => 0,
				'line_total'        => $line_subtotal,
				'line_tax'          => 0,
			];
		}

		return $contents;
	}
}

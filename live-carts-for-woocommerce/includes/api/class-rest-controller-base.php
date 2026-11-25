<?php
namespace BerryPress\LiveCarts\Api;

use BerryPress\LiveCarts\LiveCarts;
use BerryPress\LiveCarts\ShopperLists;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-helpers.php';

/**
 * Shared REST helpers for the Carts and Shopper Lists controllers.
 *
 * Carts and shopper lists are both rows in the phplugins_carts /
 * phplugins_cart_contents(_items) tables (see ShopperLists::TYPE_CART /
 * TYPE_LIST_PREFIX), so the query building, row formatting, contents
 * loading, and revision history formatting are implemented once here and
 * reused by both controllers (and by Pro's extra endpoints).
 */
abstract class RestControllerBase extends \WP_REST_Controller {

	protected $namespace = 'live-carts/v1';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ __CLASS__, 'checkAdminPermission' ],
					'args'                => $this->collectionArgs(),
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[0-9A-Fa-f]{1,8})',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ __CLASS__, 'checkAdminPermission' ],
				],
			]
		);
	}

	public function get_items( $request ) {
		try {
			list( $rows, $total, $perPage, $page ) = self::queryRows( $request, $this->getTypeLike(), $this->rest_base );

			// Same behaviour as WordPress core collections: paging past the end is an error (page 1 of an empty set is fine).
			if ( $page > 1 && $page > (int) ceil( $total / $perPage ) ) {
				return new \WP_Error( 'phplugins_live_carts_rest_invalid_page_number', __( 'The page number requested is larger than the number of pages available.', 'live-carts-for-woocommerce' ), [ 'status' => 400 ] );
			}

			$data = [];
			foreach ( $rows as $row ) {
				$data[] = Helpers::formatRow( $row, $request, false );
			}

			$response = new \WP_REST_Response( $data );
			$response->header( 'X-WP-Total', $total );
			$response->header( 'X-WP-TotalPages', (int) ceil( $total / $perPage ) );
			return $response;
		} catch ( \Exception $ex ) {
			LiveCarts::instance()->debugLog( $ex->getMessage(), __FILE__, __LINE__ );
		} catch ( \Error $err ) {
			LiveCarts::instance()->debugLog( $err->getMessage(), __FILE__, __LINE__ );
		}
		return new \WP_Error( 'phplugins_live_carts_rest_error', __( 'Could not retrieve data.', 'live-carts-for-woocommerce' ), [ 'status' => 500 ] );
	}
	
	public function get_item( $request ) {
		try {
			$row = Helpers::getRow( LiveCarts::unformatCartId( (string) $request['id'] ), $this->getTypeLike() );
			if ( is_wp_error( $row ) ) {
				return $row;
			}

			return new \WP_REST_Response( Helpers::formatRow( $row, $request, true ) );
		} catch ( \Exception $ex ) {
			LiveCarts::instance()->debugLog( $ex->getMessage(), __FILE__, __LINE__ );
		} catch ( \Error $err ) {
			LiveCarts::instance()->debugLog( $err->getMessage(), __FILE__, __LINE__ );
		}
		return new \WP_Error( 'phplugins_live_carts_rest_error', __( 'Could not retrieve the item.', 'live-carts-for-woocommerce' ), [ 'status' => 500 ] );
	}

	public static function checkAdminPermission() {
		if ( ! current_user_can( LiveCarts::ADMIN_CAPABILITY ) ) {
			return new \WP_Error(
				'phplugins_live_carts_rest_forbidden',
				__( 'Sorry, you are not allowed to access Live Carts data.', 'live-carts-for-woocommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Builds and runs a paginated query against phplugins_carts for a given type predicate.
	 *
	 * @param \WP_REST_Request $request     Current request.
	 * @param string           $typeWhere   SQL fragment restricting `type` (already safe/static).
	 * @param array            $typeParams  Params for $typeWhere.
	 * @param string           $viewType    'carts' or 'shopper-lists', passed to filters so Pro can distinguish.
	 * @return array [ rows, total ]
	 */
	protected static function queryRows( $request, $typeLike, $viewType ) {
		global $wpdb;

		$where  = 'WHERE type LIKE %s';
		$params = [ $typeLike ];

		$status = $request->get_param( 'status' );
		if ( $status ) {
			if ( 'trash' === $status ) {
				$where .= ' AND trashed=1';
			} else {
				$where .= ' AND status=%s AND trashed=0';
				$params[] = sanitize_key( $status );
			}
		} else {
			$where .= ' AND trashed=0';
		}

		// 0 is a valid filter value (guest carts), so test for presence rather than truthiness.
		$users = $request->get_param( 'user' );
		if ( null !== $users && '' !== $users && [] !== $users ) {
			$users = array_map( 'absint', (array) $users );
			$placeholders = implode( ',', array_fill( 0, count( $users ), '%d' ) );
			$where .= ' AND user_id IN (' . $placeholders . ')';
			$params = array_merge( $params, $users );
		}

		$after = $request->get_param( 'after' );
		if ( $after ) {
			$where .= ' AND created >= %s';
			$params[] = self::toMysqlGmt( $after );
		}

		$before = $request->get_param( 'before' );
		if ( $before ) {
			$where .= ' AND created <= %s';
			$params[] = self::toMysqlGmt( $before );
		}

		// Pro (and other extensions) can add extra WHERE clauses/params here,
		// e.g. contained product ID(s) or a cart total value range.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		list( $where, $params ) = apply_filters( 'phplugins_live_carts_api_query_where', [ $where, $params ], $request, $viewType );

		$perPage = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$page    = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$offset  = ( $page - 1 ) * $perPage;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where built from static fragments + placeholders above
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'phplugins_carts ' . $where, $params ) );

		$rowsSql = 'SELECT * FROM ' . $wpdb->prefix . 'phplugins_carts ' . $where . ' ORDER BY last_seen DESC, cart_id DESC LIMIT %d OFFSET %d';
		$rowsParams = array_merge( $params, [ $perPage, $offset ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $rowsSql built from static fragments + placeholders above
		$rows = $wpdb->get_results( $wpdb->prepare( $rowsSql, $rowsParams ) );

		return [ $rows, $total, $perPage, $page ];
	}

	protected static function toMysqlGmt( $iso8601 ) {
		$datetime = rest_parse_date( $iso8601 );
		if ( ! $datetime ) {
			return '1970-01-01 00:00:00';
		}
		return gmdate( 'Y-m-d H:i:s', $datetime );
	}

	protected function collectionArgs() {
		$args = [
			'page'     => [
				'description' => __( 'Current page of the collection.', 'live-carts-for-woocommerce' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			],
			'per_page' => [
				'description' => __( 'Maximum number of items to return per page.', 'live-carts-for-woocommerce' ),
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 100,
			],
			'user'     => [
				'description' => __( 'Limit results to carts/lists belonging to these user IDs.', 'live-carts-for-woocommerce' ),
				'type'        => [ 'integer', 'array' ],
				'items'       => [ 'type' => 'integer' ],
			],
			'status'   => [
				'description' => __( 'Limit results to this status: active, converted, abandoned, or trash.', 'live-carts-for-woocommerce' ),
				'type'        => 'string',
				'enum'        => [ 'active', 'converted', 'abandoned', 'trash' ],
			],
			'after'    => [
				'description' => __( 'Limit results to those created after this ISO8601 date.', 'live-carts-for-woocommerce' ),
				'type'        => 'string',
				'format'      => 'date-time',
			],
			'before'   => [
				'description' => __( 'Limit results to those created before this ISO8601 date.', 'live-carts-for-woocommerce' ),
				'type'        => 'string',
				'format'      => 'date-time',
			],
		];

		// Allows Pro to append collection args (product, min_total, max_total, ...).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return apply_filters( 'phplugins_live_carts_api_collection_params', $args, $this->rest_base );
	}
}

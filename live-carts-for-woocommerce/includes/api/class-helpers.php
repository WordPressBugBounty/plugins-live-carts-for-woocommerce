<?php
namespace BerryPress\LiveCarts\Api;

use BerryPress\LiveCarts\LiveCarts;
use BerryPress\LiveCarts\ShopperLists;

defined( 'ABSPATH' ) || exit;

class Helpers {

	/**
	 * Loads a single cart/list row by id, optionally restricted to a type predicate.
	 *
	 * @param int      $id           cart_id.
	 * @param callable $typeMatches  Optional callback( $type ): bool.
	 * @return object|\WP_Error
	 */
	public static function getRow( $id, $typeLike = null ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'phplugins_carts WHERE cart_id=%d'.($typeLike === null ? '' : ' AND type LIKE %s'), $typeLike === null ? [$id] : [$id, $typeLike] ) );

		if ( ! $row ) {
			return new \WP_Error(
				'phplugins_live_carts_rest_not_found',
				__( 'No cart/list found with that ID.', 'live-carts-for-woocommerce' ),
				[ 'status' => 404 ]
			);
		}

		return $row;
	}

	/**
	 * Formats a database row into the common REST representation.
	 *
	 * @param object            $row     Row from phplugins_carts.
	 * @param \WP_REST_Request  $request Current request (passed through to the response filter).
	 * @param bool              $includeContents Whether to include items + revision history (single-item responses).
	 */
	public static function formatRow( $row, $request = null, $includeContents = false ) {
		$isList = ShopperLists::isListType( $row->type ?? '' );

		$data = [
			'id'         => LiveCarts::formatCartId( (int) $row->cart_id ),
			// Redundant for carts (the endpoint already says so); for shopper lists,
			// this is the list type slug, e.g. "wishlist" or "saved-for-later".
			'type'       => $isList ? ShopperLists::slugFromType( $row->type ) : null,
			'admin_url'  => self::adminUrl( $row ),
			'status'     => $row->status,
			'email'      => $row->email,
			'trashed'    => ! empty( $row->trashed ),
			'user_id'    => (int) $row->user_id,
			'order_id'   => (int) $row->order_id ?: null,
			'created'    => self::toIso8601( $row->created ?? null ),
			'last_seen'  => self::toIso8601( $row->last_seen ?? null ),
			'value'      => isset( $row->value ) ? (float) $row->value : 0.0,
			'coupons'    => isset( $row->coupon ) && strlen( (string) $row->coupon ) ? array_values( array_filter( array_map( 'trim', explode( ',', $row->coupon ) ) ) ) : [],
			'ip_address' => $row->ip_address ?? '',
			'last_url'   => $row->last_url ?? '',
		];

		if ( ! $isList ) {
			unset( $data['type'] );
		
			// Get recovery URL
			if ( empty( $row->recovery_key ) ) {
				$row->recovery_key = LiveCarts::generateCartRecoveryKey($row->cart_id);
			}
			$data['recovery_url'] = add_query_arg( 'recover_cart', $row->recovery_key, wc_get_cart_url() );
		}

		if ( $includeContents ) {
			$data['items']     = self::getContents( (int) $row->cart_id, $isList );
			$data['revisions'] = self::getRevisions( (int) $row->cart_id, $isList );
		}
		

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return apply_filters( 'phplugins_live_carts_api_cart_response', $data, $row, $request );
	}

	/**
	 * Builds the admin edit-page URL for a cart/list row.
	 *
	 * Deliberately reimplements AdminPage::getUrl() rather than calling it:
	 * admin-page.php extends the BerryPress admin framework class, which is
	 * only loaded when is_admin() is true (see the main plugin bootstrap) —
	 * requiring it here would fatal on a plain REST API request. The
	 * URL-building itself is a one-liner, so there's nothing to actually share.
	 */
	protected static function adminUrl( $row ) {
		$args = ShopperLists::isListType( $row->type ?? '' ) ? [ 'type' => 'list' ] : [];
		$args['cart_id'] = (int) $row->cart_id;
		return add_query_arg( $args, admin_url( 'admin.php?page=live-carts-for-woocommerce' ) );
	}

	protected static function toIso8601( $mysqlGmtDatetime ) {
		if ( empty( $mysqlGmtDatetime ) || '0000-00-00 00:00:00' === $mysqlGmtDatetime ) {
			return null;
		}
		$datetime = date_create( $mysqlGmtDatetime, new \DateTimeZone( 'UTC' ) );
		return $datetime ? $datetime->format( DATE_W3C ) : null;
	}

	/**
	 * Returns the current contents of a cart/list, each item enriched with basic product data.
	 */
	public static function getContents( $cartId, $isList ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$contentsId = $wpdb->get_var( $wpdb->prepare( 'SELECT contents_id FROM ' . $wpdb->prefix . 'phplugins_cart_contents WHERE cart_id=%d ORDER BY contents_id DESC LIMIT 1', $cartId ) );

		$items = [];
		foreach ( LiveCarts::getCartContentsArray( $contentsId ) as $key => $item ) {
			$items[] = self::formatItem( $key, $item, $isList );
		}
		return $items;
	}

	/**
	 * Formats a raw cart-item array (as returned by LiveCarts::getCartContentsArray())
	 * into the REST `item` shape. Public because Api\Webhooks also uses this — for the
	 * same reason as formatProductSummary(): an item's shape is identical whether it's
	 * in `items`, a revision's `item`/`item_before`, or a webhook's `item` field.
	 */
	public static function formatItem( $key, $item, $isList ) {
		$productId   = (int) ( $item['product_id'] ?? 0 );
		$variationId = (int) ( $item['variation_id'] ?? 0 );
		$product     = wc_get_product( $variationId ?: $productId );

		$formatted = [
			'key'          => (string) $key,
			'product_id'   => $productId,
			'variation_id' => $variationId,
			'product'      => self::formatProductSummary( $product, $productId, $variationId ),
		];

		if ( ! $isList ) {
			$formatted['quantity']          = isset( $item['quantity'] ) ? (float) $item['quantity'] : 0.0;
			$formatted['line_subtotal']     = isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0.0;
			$formatted['line_subtotal_tax'] = isset( $item['line_subtotal_tax'] ) ? (float) $item['line_subtotal_tax'] : 0.0;
			$formatted['line_total']        = isset( $item['line_total'] ) ? (float) $item['line_total'] : 0.0;
			$formatted['line_tax']          = isset( $item['line_tax'] ) ? (float) $item['line_tax'] : 0.0;
		}

		return $formatted;
	}

	/**
	 * Builds the `product` object used both by cart/list items and by
	 * revision entries (formatRevisionDiff()), so the two always carry the
	 * same fields.
	 *
	 * @param \WC_Product|false|null $product    The resolved product/variation, if it still exists.
	 * @param int                    $fallbackId Product/variation ID to report when $product is unavailable (e.g. deleted).
	 */
	protected static function formatProductSummary( $product, $productId, $variationId = 0 ) {
		if ( ! $product ) {
			return [
				'product_id'       => $productId,
				'variation_id'     => $variationId ?: null,
				'name'       => __( 'Unknown product', 'live-carts-for-woocommerce' ),
				'sku'        => null,
				'url'        => null,
				'price'      => null,
				'attributes' => (object) [],
			];
		}

		return [
			'product_id' => $productId,
			'variation_id' => $variationId ?: null,
			'name'       => $product->get_name(),
			'sku'        => $product->get_sku(),
			'url'        => $product->get_permalink(),
			'price'      => $product->get_price(),
			'attributes' => self::formatProductAttributes( $product ),
		];
	}

	/**
	 * Returns a label => value map of a product's (or variation's) attributes.
	 *
	 * For a variation this is its selected attribute values (e.g. Color: Blue)
	 * plus the parent's other visible attributes; for a simple/variable product
	 * it's the product-level attributes (visible ones only), taxonomy terms
	 * resolved to their names.
	 */
	protected static function formatProductAttributes( $product ) {
		$attributes = [];
		
		if ( $product->is_type( 'variation' ) ) {
			foreach ( $product->get_variation_attributes() as $attributeKey => $value ) {
				if ( '' === $value ) {
					continue; // "Any <attribute>" — no specific value selected.
				}

				$taxonomy = strncmp( $attributeKey, 'attribute_', 10 ) === 0 ? substr( $attributeKey, 10 ) : $attributeKey;
				$label    = wc_attribute_label( $taxonomy, $product );

				if ( taxonomy_exists( $taxonomy ) ) {
					$term  = get_term_by( 'slug', $value, $taxonomy );
					$value = $term ? $term->name : $value;
				}

				$attributes[ $label ] = $value;
			}
			$product = wc_get_product( $product->get_parent_id() );
			if ( ! $product ) {
				return (object) $attributes; // Orphaned variation: its parent no longer exists.
			}
		}
		
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute->get_visible() ) {
				continue;
			}

			$label = wc_attribute_label( $attribute->get_name() );

			if ( isset( $attributes[ $label ] ) ) {
				continue; // A variation's selected value, not the parent's full list of options.
			}

			if ( $attribute->is_taxonomy() ) {
				$terms = wc_get_product_terms( $product->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] );
				$attributes[ $label ] = implode( ', ', $terms );
			} else {
				$attributes[ $label ] = implode( ', ', $attribute->get_options() );
			}
		}

		// Force a JSON object (not array) even when there are no attributes.
		return (object) $attributes;
	}

	/**
	 * Returns the revision history of a cart/list formatted for the REST response.
	 */
	public static function getRevisions( $cartId, $isList ) {
		$revisions = [];

		foreach ( LiveCarts::getCartContentsHistory( $cartId ) as list( $timestamp, $diffs ) ) {
			$changes = [];
			foreach ( $diffs as $diff ) {
				$changes[] = self::formatRevisionDiff( $diff, $isList );
			}
			$revisions[] = [
				'date'    => self::toIso8601( $timestamp ),
				'changes' => $changes,
			];
		}

		return $revisions;
	}

	/**
	 * Formats one getCartContentsHistory() diff entry as a revision `changes[]` entry.
	 *
	 * `item` (and, for item_update, `item_before`) are built with formatItem() — the
	 * same helper used for the cart/list's `items` array — so a revision's item object
	 * always has exactly the same fields (including quantity) as a live cart item.
	 */
	protected static function formatRevisionDiff( $diff, $isList ) {
		switch ( $diff[0] ) {
			case 'add':
				$item = $diff[3] ?? [];
				return [
					'type' => 'item_added',
					'item' => self::formatItem( $item['key'] ?? '', $item, $isList ),
				];
			case 'remove':
				$item = $diff[2] ?? [];
				return [
					'type' => 'item_removed',
					'item' => self::formatItem( $item['key'] ?? '', $item, $isList ),
				];
			case 'change-quantity':
				$item       = $diff[4] ?? [];
				$itemBefore = $diff[5] ?? [];
				return [
					'type'        => 'item_update',
					'item'        => self::formatItem( $item['key'] ?? '', $item, $isList ),
					'item_before' => self::formatItem( $itemBefore['key'] ?? '', $itemBefore, $isList ),
				];
			case 'recovery-email':
				return [
					'type'     => 'recovery_email_sent',
					'email_id' => (int) ( $diff[2] ?? 0 ),
					'title'    => (string) ( $diff[1] ?? '' ),
				];
		}

		return [ 'type' => $diff[0] ];
	}
}

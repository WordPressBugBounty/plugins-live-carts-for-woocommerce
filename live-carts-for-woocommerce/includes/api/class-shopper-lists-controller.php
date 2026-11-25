<?php
namespace BerryPress\LiveCarts\Api;

use BerryPress\LiveCarts\ShopperLists;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for shopper lists: /live-carts/v1/shopper-lists
 *
 * Shopper lists (wishlist, saved-for-later, ...) live in the same tables as
 * carts (see ShopperLists::TYPE_LIST_PREFIX), so all of the row formatting,
 * contents, and revision-history logic is shared with CartsController via
 * RestControllerBase.
 */
class ShopperListsController extends RestControllerBase {

	protected $rest_base = 'shopper-lists';
	
	protected function getTypeLike() {
		global $wpdb;
		return $wpdb->esc_like( ShopperLists::TYPE_LIST_PREFIX ) . '%';
	}

}

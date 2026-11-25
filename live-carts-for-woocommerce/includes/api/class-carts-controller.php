<?php
namespace BerryPress\LiveCarts\Api;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for carts: /live-carts/v1/carts
 *
 * Read-only in the free plugin. Pro registers additional routes
 * (update items, convert to order) via class-carts-pro-controller.php,
 * reusing the helpers on RestControllerBase and on this class.
 */
class CartsController extends RestControllerBase {

	protected $rest_base = 'carts';
	
	protected function getTypeLike() {
		return 'cart';
	}

}

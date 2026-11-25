<?php
namespace BerryPress\LiveCarts;

defined('ABSPATH') || exit;

class StatsController extends \Automattic\WooCommerce\Admin\API\Reports\GenericStatsController {
		
	protected $rest_base = 'reports/phplugins-carts/stats';

	public static function register() {
		add_filter('woocommerce_admin_reports', function($reports) {
			$reports[] = [
				'slug' => 'phplugins-carts/stats',
				'description' => __('Stats about carts.', 'live-carts-for-woocommerce')
			];
			return $reports;
		});
		
		add_filter('woocommerce_admin_rest_controllers', function($controllers) {
			$controllers[] = self::class;
			return $controllers;
		});
	}

	public function get_items($request) {
		global $wpdb;

		$start = get_gmt_from_date($request['after']);
		$end = get_gmt_from_date($request['before']);
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$counts = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS count
				FROM '.$wpdb->prefix.'phplugins_carts
				WHERE type=%s
					AND trashed=0
					AND created BETWEEN %s AND %s
				GROUP BY status',
				ShopperLists::TYPE_CART,
				$start,
				$end
			)
		);

		$countsByStatus = array_column($counts, 'count', 'status');

		$countsTotal = array_sum($countsByStatus);

		$totals = [
			'convert_rate' => isset($countsByStatus['converted']) ? round($countsByStatus['converted'] / $countsTotal, 6) : 0,
			'abandon_rate' => isset($countsByStatus['abandoned']) ? round($countsByStatus['abandoned'] / $countsTotal, 6) : 0
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$totals['cart_value'] = round((float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT AVG(value)
				FROM '.$wpdb->prefix.'phplugins_carts
				WHERE type=%s
					AND created BETWEEN %s AND %s',
				ShopperLists::TYPE_CART,
				$start,
				$end
			)
		), 6);

		return ['totals' => $totals];
	}
	
	protected function get_item_properties_schema() {
		return [
			'convert_rate' => [
				'description' => __('Cart conversion rate', 'live-carts-for-woocommerce'),
				'type' => 'number',
				'context' => ['view', 'edit'],
				'readonly' => true
			],
			'abandon_rate' => [
				'description' => __('Cart abandonment rate', 'live-carts-for-woocommerce'),
				'type' => 'number',
				'context' => ['view', 'edit'],
				'readonly' => true
			],
			'cart_value' => [
				'description' => __('Average cart value', 'live-carts-for-woocommerce'),
				'type' => 'number',
				'context' => ['view', 'edit'],
				'readonly' => true,
				'format' => 'currency'
			],
		];
	}
}
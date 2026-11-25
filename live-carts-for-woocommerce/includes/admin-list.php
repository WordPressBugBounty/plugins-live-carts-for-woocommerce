<?php
namespace BerryPress\LiveCarts;

defined('ABSPATH') || exit;

class CartsListTable extends \WP_List_Table {

	private $statuses, $viewType;

	public function __construct() {
		$this->viewType = ShopperLists::getAdminViewType();
		parent::__construct([
			'singular' => $this->viewType === 'list' ? 'list' : 'cart',
			'plural' => $this->viewType === 'list' ? 'lists' : 'carts',
		]);
		$this->statuses = LiveCarts::instance()->getCartStatuses();
	}

	public function isListView() {
		return $this->viewType === 'list';
	}
	
	public function no_items() {
		if ( $this->isListView() && !ShopperLists::isAvailable() ) {
			printf(
				// translators: placeholders are link tags
				esc_html__('Shopper lists are currently disabled in WooCommerce settings. To start using this feature, go to %1$sWooCommerce > Settings > Advanced > Features%2$s and enable Wishlists and/or Save for Later in Cart. Shopper Lists are currently available only with block (Gutenberg) themes.', 'live-carts-for-woocommerce'),
				'<a href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=advanced&section=features')).'" target="_blank">',
				'</a>'
			);
		} else {
			parent::no_items();
		}
	}

	public function get_views() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- non-persistent filtering
		$status  = empty( $_REQUEST['status'] ) ? 'all' : sanitize_key( wp_unslash( $_REQUEST['status'] ) );
		$baseUrl = remove_query_arg( [ 'status', 'paged', 'trashed', 'untrashed', 'deleted' ] );
		$counts  = $this->getStatusCounts();

		$countSpan = static function ( $key ) use ( $counts ) {
			$n = $counts[ $key ] ?? 0;
			return ' <span class="count">(' . esc_html(number_format_i18n( $n )) . ')</span>';
		};

		if ( $this->isListView() ) {
			return [
				'all'     => '<a href="' . esc_url( $baseUrl ) . '"' . ( $status === 'all' ? ' class="current"' : '' ) . '>' . esc_html__( 'All', 'live-carts-for-woocommerce' ) . $countSpan( 'all' ) . '</a>',
				'trashed' => '<a href="' . esc_url( add_query_arg( 'status', 'trashed', $baseUrl ) ) . '"' . ( $status === 'trashed' ? ' class="current"' : '' ) . '>' . esc_html__( 'Trash', 'live-carts-for-woocommerce' ) . $countSpan( 'trashed' ) . '</a>',
			];
		}

		$views = [
			'all' => '<a href="' . esc_url( $baseUrl ) . '"' . ( $status === 'all' ? ' class="current"' : '' ) . '>' . esc_html__( 'All', 'live-carts-for-woocommerce' ) . $countSpan( 'all' ) . '</a>',
		];
		foreach ( $this->statuses as $statusId => $statusDisplay ) {
			$views[ $statusId ] = '<a href="' . esc_url( add_query_arg( 'status', rawurlencode( $statusId ), $baseUrl ) ) . '"' . ( $status === $statusId ? ' class="current"' : '' ) . '>' . esc_html( $statusDisplay ) . $countSpan( $statusId ) . '</a>';
		}
		return $views;
	}

	private function getStatusCounts() {
		global $wpdb;
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT CASE WHEN trashed=1 THEN 'trashed' ELSE status END AS status_key, COUNT(*) AS cnt
				FROM {$wpdb->prefix}phplugins_carts
				WHERE archived=%d AND type LIKE %s
				GROUP BY CASE WHEN trashed=1 THEN 'trashed' ELSE status END",
				0,
				$this->isListView() ? $wpdb->esc_like( ShopperLists::TYPE_LIST_PREFIX ) . '%' : ShopperLists::TYPE_CART
			),
			ARRAY_A
		);

		$counts = [ 'all' => 0 ];
		foreach ( (array) $rows as $row ) {
			$counts[ $row['status_key'] ] = (int) $row['cnt'];
			if ( $row['status_key'] !== 'trashed' ) {
				$counts['all'] += (int) $row['cnt'];
			}
		}
		return $counts;
	}
	
	public function extra_tablenav( $which ) {
		if ( $which !== 'top' ) {
			return;
		}

		if ( $this->isViewingTrash() ) {
			$confirm = sprintf(
				// translators: %s = carts / lists
				__( 'Permanently delete all %s in the Trash? This cannot be undone.', 'live-carts-for-woocommerce' ),
				$this->isListView() ? __( 'lists', 'live-carts-for-woocommerce' ) : __( 'carts', 'live-carts-for-woocommerce' )
			);
			?>
			<button type="submit" name="action" value="empty_trash"
				class="berrypress-btn berrypress-btn-secondary"
				onclick="return confirm('<?php echo esc_js( $confirm ); ?>');">
				<?php esc_html_e( 'Empty Trash', 'live-carts-for-woocommerce' ); ?>
			</button>
			<?php
			return;
		}

		if ( $this->isListView() ) {
			return;
		}

		if ( has_action( 'phplugins_live_carts_list_after_bulk_actions' ) ) {
			do_action( 'phplugins_live_carts_list_after_bulk_actions' );
		} else {
			?>
			<button type="button" class="berrypress-btn berrypress-btn-secondary phplugins-lc-filters-btn" disabled>
				<i class="berrypress-icon-filter_alt" aria-hidden="true"></i>
				<?php esc_html_e( 'Filters', 'live-carts-for-woocommerce' ); ?>
				<span class="phplugins-lc-pro-badge"><?php esc_html_e( 'Pro', 'live-carts-for-woocommerce' ); ?></span>
			</button>
			<?php
		}
	}

	public function get_columns() {
		$idLabel = $this->isListView()
			? esc_html__( 'List ID', 'live-carts-for-woocommerce' )
			: esc_html__( 'Cart ID', 'live-carts-for-woocommerce' );
		$valueLabel = $this->isListView()
			? esc_html__( 'List Value', 'live-carts-for-woocommerce' )
			: esc_html__( 'Cart Value', 'live-carts-for-woocommerce' );
		$selectLabel = $this->isListView()
			? esc_html__( 'Select List', 'live-carts-for-woocommerce' )
			: esc_html__( 'Select Cart', 'live-carts-for-woocommerce' );

		$columns = [
			'cb'           => $selectLabel,
			'cart_id'      => $idLabel,
			'date_created' => esc_html__( 'Created', 'live-carts-for-woocommerce' ),
		];

		if ( ! $this->isListView() ) {
			$columns['status'] = esc_html__( 'Status', 'live-carts-for-woocommerce' );
		} else {
			$columns['list_type'] = esc_html__( 'List Type', 'live-carts-for-woocommerce' );
		}

		$columns['user'] = esc_html__( 'User', 'live-carts-for-woocommerce' );

		if ( ! get_option( 'phplugins_carts_no_ip' ) ) {
			$columns['ip_address'] = esc_html__( 'IP Address', 'live-carts-for-woocommerce' );
		}
		$columns['last_seen'] = esc_html__( 'Last Seen', 'live-carts-for-woocommerce' );

		if ( ! $this->isListView() ) {
			$columns['coupon'] = esc_html__( 'Coupon(s)', 'live-carts-for-woocommerce' );
		}

		$columns['value'] = $valueLabel;
		return $columns;
	}
	
	public function get_bulk_actions() {
		if ( $this->isViewingTrash() ) {
			return [
				'untrash'            => esc_html__( 'Restore', 'live-carts-for-woocommerce' ),
				'delete_permanently' => esc_html__( 'Delete permanently', 'live-carts-for-woocommerce' ),
			];
		}

		return [
			'trash' => esc_html__( 'Move to Trash', 'live-carts-for-woocommerce' ),
		];
	}

	private function isViewingTrash() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- non-persistent filtering
		$status = empty( $_REQUEST['status'] ) ? 'all' : sanitize_key( wp_unslash( $_REQUEST['status'] ) );
		return $status === 'trashed';
	}
	
	public function column_cb( $row ) {
		?>
		<input id="cb-select-<?php echo((int) $row['cart_id']); ?>" type="checkbox" name="post[]" value="<?php echo((int) $row['cart_id']); ?>" />
		<label for="cb-select-<?php echo((int) $row['cart_id']); ?>">
			<span class="screen-reader-text">
			<?php
				if ( $this->isListView() ) {
					/* translators: %s: list ID */
					printf( esc_html__( 'Select list %s', 'live-carts-for-woocommerce' ), esc_html( LiveCarts::formatCartId($row['cart_id']) ) );
				} else {
					/* translators: %s: cart ID */
					printf( esc_html__( 'Select cart %s', 'live-carts-for-woocommerce' ), esc_html( LiveCarts::formatCartId($row['cart_id']) ) );
				}
			?>
			</span>
		</label>
		<?php
	}

	/**
	 * Builds the SQL WHERE fragment and parameters for the admin carts/lists table.
	 * Reads the current request query vars so list filters match cart detail prev/next navigation.
	 *
	 * @param string $viewType 'cart' or 'list'.
	 * @return array{0:string,1:array} Tuple of [ $filterSql, $params ].
	 */
	public static function buildListFilterSql( $viewType ) {
		global $wpdb;

		$statuses   = LiveCarts::instance()->getCartStatuses();
		$isListView = $viewType === 'list';
		$filterSql  = '';
		$params     = [ 0 ];

		if ( $isListView ) {
			$filterSql .= ' AND type LIKE %s';
			$params[]   = $wpdb->esc_like( ShopperLists::TYPE_LIST_PREFIX ) . '%';
		} else {
			$filterSql .= ' AND type=%s';
			$params[]   = ShopperLists::TYPE_CART;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- non-persistent filtering
		$statusFilter = empty( $_REQUEST['status'] ) ? 'all' : sanitize_key( wp_unslash( $_REQUEST['status'] ) );

		if ( $statusFilter === 'trashed' ) {
			$filterSql .= ' AND trashed=1';
		} elseif ( $statusFilter === 'all' ) {
			$filterSql .= ' AND trashed=0';
		} elseif ( isset( $statuses[ $statusFilter ] ) ) {
			$filterSql .= ' AND status=%s AND trashed=0';
			$params[]   = $statusFilter;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- non-persistent searching
		if ( ! empty( $_REQUEST['s'] ) ) {
			$filterSql .= ' AND cart_id=%s';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- non-persistent searching
			$params[] = LiveCarts::unformatCartId( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) );
		}

		list( $filterSql, $params ) = apply_filters( 'phplugins_live_carts_list_filter_sql', [ $filterSql, $params ], $viewType );

		return [ $filterSql, $params ];
	}

	/**
	 * Returns adjacent cart/list IDs in the current filtered result set (ordered by last_seen DESC).
	 *
	 * @param int    $currentCartId   Current cart ID.
	 * @param string $viewType        'cart' or 'list'.
	 * @param string $currentLastSeen Current row last_seen value (GMT MySQL datetime).
	 * @return array{prev:?int,next:?int}
	 */
	public static function getAdjacentCartIds( $currentCartId, $viewType, $currentLastSeen ) {
		global $wpdb;

		$currentCartId = (int) $currentCartId;
		if ( ! $currentCartId || empty( $currentLastSeen ) ) {
			return [ 'prev' => null, 'next' => null ];
		}

		list( $filterSql, $params ) = self::buildListFilterSql( $viewType );
		$table                      = $wpdb->prefix . 'phplugins_carts';

		$params = array_merge( $params, [ $currentLastSeen, $currentLastSeen, $currentCartId ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $filterSql uses placeholders from buildListFilterSql; params is array; $table is safe
		$prevId = $wpdb->get_var($wpdb->prepare('SELECT cart_id FROM ' . $table . ' WHERE archived=%d' . $filterSql . ' AND (last_seen > %s OR (last_seen = %s AND cart_id > %d)) ORDER BY last_seen ASC, cart_id ASC LIMIT 1', $params));

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $filterSql uses placeholders from buildListFilterSql; params is array; $table is safe
		$nextId = $wpdb->get_var( $wpdb->prepare( 'SELECT cart_id FROM ' . $table . ' WHERE archived=%d' . $filterSql . ' AND (last_seen < %s OR (last_seen = %s AND cart_id < %d)) ORDER BY last_seen DESC, cart_id DESC LIMIT 1', $params ) );

		return [
			'prev' => $prevId ? (int) $prevId : null,
			'next' => $nextId ? (int) $nextId : null,
		];
	}

	public function prepare_items() {
		global $wpdb;

		if ( $this->isListView() && ShopperLists::isAvailable() ) {
			ShopperLists::instance()->maybeBackfillStoredLists();
		}

		$perPage = $this->get_items_per_page('berrypress_page_live_carts_for_woocommerce_per_page');

		list( $filterSql, $params ) = self::buildListFilterSql( $this->viewType );
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $filterSql is built with placeholders, prepared on same line
		$count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'phplugins_carts WHERE archived=%d'.$filterSql, $params));
		
		$sql = 'SELECT cart_id, status, trashed, type, user_id, created, last_seen, coupon, last_url, value, ip_address, email
				FROM '.$wpdb->prefix.'phplugins_carts
				WHERE archived=%d'
				.$filterSql.'
				ORDER BY last_seen DESC, cart_id DESC
				LIMIT %d,%d';
		
		$params[] = ($this->get_pagenum() - 1) * $perPage;
		$params[] = $perPage;
	
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on next line
		$query = $wpdb->prepare($sql, $params);
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql contains dynamic $filterSql with placeholders, prepared above
		$this->items = $wpdb->get_results($query, ARRAY_A);
		
		
		$this->set_pagination_args([
			'total_items' => (int) $count,
			'per_page' => $perPage
		]);
	}

	protected function column_cart_id($row) {
		$urlArgs = AdminPage::getListContextQueryArgsForView( $this->viewType );
		$urlArgs['cart_id'] = (int) $row['cart_id'];
		return '<a href="'.esc_url( AdminPage::getUrl( $urlArgs ) ).'" >'.esc_html(LiveCarts::formatCartId($row['cart_id'])).'</a>';
	}

	protected function column_list_type($row) {
		return esc_html( ShopperLists::getListTypeLabel( $row['type'] ?? '' ) );
	}

	protected function _column_status($row, $classNames, $dataAttrs, $isPrimary) {
		$statusKey = empty($row['trashed']) ? $row['status'] : 'trashed';
		return '<td '
					// values in $dataAttrs were already escaped
					.$dataAttrs
				.'><span class="'.esc_attr($classNames.' phplugins-live-carts-'.$statusKey).'" >'
					.esc_html(
						isset($this->statuses[$statusKey])
							? $this->statuses[$statusKey]
							: $statusKey
					)
				.'</span></td>';
	}
	
	protected function column_date_created( $row ) {
		return esc_html( LiveCarts::instance()->formatGmtDatetime( $row['created'] ) );
	}

	protected function column_last_seen($row) {
		return $row['last_url']
					? sprintf(
						// translators: first %s is a timestamp, second %s is a URL
						esc_html__('%1$s at %2$s', 'live-carts-for-woocommerce'),
						esc_html(LiveCarts::instance()->formatGmtDatetime($row['last_seen'])),
						'<a href="'.esc_url($row['last_url']).'" target="_blank">'.esc_url($row['last_url']).'</a>'
					)
					: esc_html(LiveCarts::instance()->formatGmtDatetime($row['last_seen']));
	}
	
	protected function column_coupon($row) {
LiveCarts::outputCouponCodeLinks($row['coupon']);
	}
	
	protected function column_user($row) {
		if ($row['user_id']) {
			$user = get_userdata($row['user_id']);
			if ($user) {
				$userDisplayName = trim($user->first_name.' '.$user->last_name);
				if (empty($userDisplayName)) {
					$userDisplayName = $user->user_login;
				}
			}
		}
		if ( ! empty( $user ) ) {
			return '<a href="'.esc_url(get_edit_user_link($user->ID)).'" target="_blank">'.esc_html($userDisplayName).'</a>';
		}
		if ( ! empty( $row['email'] ) ) {
			return esc_html( sprintf(
				/* translators: %s: customer email address */
				__( 'Guest: %s', 'live-carts-for-woocommerce' ),
				$row['email']
			) );
		}
		return esc_html__( 'Guest', 'live-carts-for-woocommerce' );
	}

	protected function column_ip_address($row) {
		return esc_html($row['ip_address']);
	}

	protected function column_value($row) {
		return wc_price((float) $row['value']);
	}

	public function get_table_classes() {
		return array( 'widefat', 'table-view-list', 'fixed', 'striped', 'berrypress_page_live-carts-for-woocommerce', 'berrypress-table' );
	}

	public function search_box( $text, $input_id ) {
	    // Replicate parent::search_box but add placeholder attribute and custom classes
		
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- non-persistent searching
	    if ( empty( $_REQUEST['s'] ) && ! $this->has_items() ) {
	        return;
	    }

	    $input_id = $input_id . '-search-input';

	    echo '<p class="search-box berrypress-search-box">';
	    echo '<label class="screen-reader-text" for="' . esc_attr( $input_id ) . '">' . esc_html( $text ) . ':</label>';
		
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- non-persistent searching
	    echo '<input type="search" id="' . esc_attr( $input_id ) . '" class="berrypress-search-input" name="s" placeholder="' . esc_attr( $text ) . '" value="' . esc_attr( sanitize_text_field(wp_unslash($_REQUEST['s'] ?? '')) ) . '" />';
	    submit_button( esc_html__( 'Search', 'live-carts-for-woocommerce' ), 'berrypress-btn berrypress-btn-secondary', '', false, array( 'id' => 'search-submit' ) );
	    echo '</p>';
	}


}

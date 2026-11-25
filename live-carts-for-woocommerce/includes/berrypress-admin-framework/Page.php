<?php
namespace BerryPressLiveCarts\Admin;

defined( 'ABSPATH' ) || exit;

abstract class Page {
	
	protected $showExternalLinks = true;
	
	public static function maybeRegisterBerryPressAdminMenu() {
		add_action('admin_menu', function() {
			if (empty($GLOBALS['_registered_pages']['toplevel_page_berrypress'])) {
				$bpLabel = __('BerryPress', 'live-carts-for-woocommerce');
				
				add_menu_page(
				   $bpLabel,
					__('BerryPress', 'live-carts-for-woocommerce'),
					'read',
					'berrypress',
					'',
					'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyBpZD0iV2Fyc3R3YV8xIiBkYXRhLW5hbWU9IldhcnN0d2EgMSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB2aWV3Qm94PSIwIDAgNTguNjkgNTkuOTYiPgogIDxwYXRoIGQ9Ik0xOC43Myw1Mi40NnYtNy4zN2MwLTIuMzgtLjY3LTQuNzEtMS44NC02LjM3bC0yLjg2LTQuMDktMy4yNS00LjY0LDMuMjUtNC42NCwyLjg2LTQuMDljMS4xNy0xLjY3LDEuODQtMy45OSwxLjg0LTYuMzd2LTcuMzdjMC0yLjcyLjYzLTUuMjcsMS43Mi03LjVoLTUuMzljLTIuOSwwLTUuMjQsMi4zNS01LjI0LDUuMjR2Ny43NGMwLDEuOTQtLjYsMy44NC0xLjcxLDUuNDNMMCwyOS45OGw4LjEsMTEuNTZjMS4xMiwxLjU5LDEuNzEsMy40OSwxLjcxLDUuNDN2Ny43NGMwLDIuOSwyLjM1LDUuMjQsNS4yNCw1LjI0aDUuMzljLTEuMS0yLjIzLTEuNzItNC43OC0xLjcyLTcuNVoiIHN0eWxlPSJmaWxsOiAjRkZGRkZGOyIvPgogIDxwYXRoIGQ9Ik01MS4wOSwyOWMzLjE5LTMuMDgsNS4xNy03LjMyLDUuMTctMTEuOTlDNTYuMjUsNy42Myw0OC4zMSwwLDM4LjU0LDBoLTUuODVjLTMuMjQsMC01Ljg2LDMuMzYtNS44Niw3LjV2Ny4zN2MwLDQuMDYtMS4xNyw3Ljk4LTMuMywxMS4wMmwtMi44Niw0LjA5LDIuODYsNC4wOWMyLjEzLDMuMDQsMy4zLDYuOTYsMy4zLDExLjAydjcuMzdjMCw0LjE0LDIuNjIsNy41LDUuODYsNy41aDguMjhjOS43NywwLDE3LjcxLTcuNjMsMTcuNzEtMTcuMDEsMC01Ljc3LTMuMDEtMTAuODgtNy42LTEzLjk1WiIgc3R5bGU9ImZpbGw6ICNGRkZGRkY7Ii8+Cjwvc3ZnPg==',
					59
				);
				
				
				add_action('admin_menu', function() {
					remove_submenu_page('berrypress', 'berrypress');
					if ( apply_filters( 'phplugins_live_carts_admin_menu_external_links', true ) ) {
						$docsLabel = __('Documentation', 'live-carts-for-woocommerce');
						add_submenu_page(
							'berrypress',
							$docsLabel,
							$docsLabel,
							'read',
							'https://berrypress.com/docs/',
							''
						);
					}
				}, 999);
				
				add_action('admin_footer', function() {
					echo('<script>(function(l) {l && (l.target=\'_blank\');})(document.querySelector(\'#toplevel_page_berrypress > ul a[href^="https://berrypress.com/"]\'));</script>');
				});
			}
		}, 1);
	}
	
	public static function should_show_friday_notice() {
		$current_date = current_time('Y-m-d');
		$black_friday_start = '2026-11-19'; // Black Friday start date
		$black_friday_end = '2026-11-30';   // End date
		return $current_date >= $black_friday_start && $current_date <= $black_friday_end;
	}

	/**
	 * Check if we're in Black Friday promotional period and return appropriate notice
	 *
	 * @param string $product_url URL to the product page
	 * @param string $product_name Name of the product (optional)
	 * @return string HTML for the promotional notice or empty string
	 */
	public static function get_black_friday_notice( $product_url, $product_name = 'Pro' ) {
		if ( self::should_show_friday_notice() ) {
			return sprintf(
				'<div class="berrypress-top-bar berrypress-top-bar-promo"><h2><span class="berrypress-fw-bold">%s</span> %s <a target="_blank" class="berrypress-btn berrypress-btn-secondary" href="%s">%s<i class="berrypress-icon-filled berrypress-icon-keyboard_double_arrow_right"></i></a></h2></div>',
				esc_html__( 'Our Black Friday sale is live!', 'live-carts-for-woocommerce' ),
				sprintf(
				/* translators: %s is the product name */
					esc_html__( 'Get %s 25%% OFF — plus discounts on all BerryPress products! ', 'live-carts-for-woocommerce' ),
					esc_html( $product_name )
				),
				esc_html( $product_url ),
				esc_html__( 'Get 25% OFF Now', 'live-carts-for-woocommerce' )
			);
		}

		
		return '';
	}
	
	function render() {
		$this->header();
		if (!apply_filters('berrypress_admin_page_replace_body', false, $this)) {
			$this->body();
		}
		$this->footer();
	}

	abstract function getNav();

	abstract function getLogoUrl();

	abstract function getProductUrl();

	abstract function getHeaderText();

	abstract function getAboveHeaderHtml();

function header() {

	$nav                  = $this->getNav();
	$logo                 = $this->getLogoUrl();
	$product_url          = $this->getProductUrl();
	$header_text          = $this->getHeaderText();
	$display_above_header = $this->getAboveHeaderHtml();
	// Separate dismissal cookies for the Black Friday bar and the default upgrade bar.
	$top_bar_cookie       = self::should_show_friday_notice() ? 'phplugins_live_carts_top_bar_dismissed_bf' : 'phplugins_live_carts_top_bar_dismissed';
	$top_bar_dismissed    = isset( $_COOKIE[ $top_bar_cookie ] )
		&& '1' === sanitize_text_field( wp_unslash( $_COOKIE[ $top_bar_cookie ] ) );

	?>
    <div class="berrypress-settings-container<?php echo $top_bar_dismissed ? ' berrypress-top-bar-dismissed' : ''; ?>">
		<?php echo(wp_kses_post($display_above_header)); ?>

        <header class="berrypress-header">
            <button id="berrypress-toggle-menu-mobile" class="berrypress-btn berrypress-btn-icon" aria-label="Toggle Sidebar"><i class="berrypress-icon-menu" aria-hidden="true"></i></button>
            <a href="<?php echo esc_url( $product_url ); ?>" target="_blank" class="berrypress-header-left berrypress-btn-reset">
                <img src="<?php
                // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage
                echo esc_url($logo);
                ?>" alt="<?php esc_attr_e("Logo", "live-carts-for-woocommerce"); ?>">
                <span class="berrypress-logo"><?php echo esc_html( $header_text ); ?></span>
            </a>
        </header>

        <!--  Sidebar + Content -->
        <div class="berrypress-main">
            <!-- Sidebar -->
            <?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- non-persistent use of page slug for cookie
            $page_slug = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
            $cookie_name = $page_slug ? 'berrypress_sidebar_collapsed_' . $page_slug : '';
            $sidebar_collapsed = $cookie_name && isset( $_COOKIE[ $cookie_name ] ) && $_COOKIE[ $cookie_name ] === '1';
            ?>
            <aside id="berrypress-sidebar" class="berrypress-sidebar<?php echo $sidebar_collapsed ? ' collapsed' : ''; ?>">
                <div class="berrypress-sidebar-header">
                    <h2><?php esc_html_e( 'Menu', 'live-carts-for-woocommerce' ); ?></h2>
                    <button class="berrypress-toggle-sidebar berrypress-btn berrypress-btn-icon" aria-label="Toggle Sidebar"><i class="berrypress-icon-first_page" aria-hidden="true"></i></button>
                </div>

                <ul class="berrypress-menu">
                    <?php foreach ($nav as $navItem) { ?>
                        <li <?php if ($navItem['active'] ?? false) { ?> class="active"<?php } ?>>
                            <a href="<?php echo(esc_url($navItem['link'])); ?>"
	                            <?php if (!empty($navItem['link_id'])) : ?>  id="<?php echo esc_attr($navItem['link_id']); ?>" <?php endif; ?>>
                                <i class="<?php echo(esc_attr($navItem['icon'])); ?>"></i>
					            <?php echo(esc_html($navItem['title'])); ?>
					            <?php if (!empty($navItem['label'])) : ?>
					            <span class="berrypress-nav-label"><?php echo esc_html($navItem['label']); ?></span>
					            <?php endif; ?>
                            </a></li>
		            <?php } ?>
                </ul>

                <?php if ($this->showExternalLinks) { ?>
				<ul class="berrypress-menu">
                    <li><h3><?php esc_html_e( 'External Links', 'live-carts-for-woocommerce' ); ?></h3></li>

                    <li><a href="https://berrypress.com/" target="_blank"><i class="berrypress-icon-home"></i><?php esc_html_e( 'BerryPress Website', 'live-carts-for-woocommerce' ); ?></a></li>
                    <li><a href="https://wordpress.org/support/plugin/live-carts-for-woocommerce/reviews/" target="_blank"><i class="berrypress-icon-star"></i><?php esc_html_e( 'Leave a Review', 'live-carts-for-woocommerce' ); ?></a></li>
                    <li><a href="https://berrypress.com/docs/live-carts-for-woocommerce/" target="_blank"><i class="berrypress-icon-library_books"></i><?php esc_html_e( 'Documentation', 'live-carts-for-woocommerce' ); ?></a></li>
                    <li><a href="https://wordpress.org/support/plugin/live-carts-for-woocommerce/" target="_blank"><i class="berrypress-icon-help"></i><?php esc_html_e( 'Support', 'live-carts-for-woocommerce' ); ?></a></li>
                </ul>
				<?php } ?>

                <div class="berrypress-upgrade-box">
                    <div>
                        <div>
                            <i class="berrypress-icon-filled berrypress-icon-lock"></i>
                            <h4><?php
                            /* translators: %s is the name of the Pro version (e.g., 'Pro') */
                            echo wp_kses_post( sprintf( __( 'Upgrade to <strong>%s</strong>', 'live-carts-for-woocommerce' ), 'Pro' ) ); ?></h4>
                        </div>
                        <div class="berrypress-upgrade-box-content berrypress-mb-3">
                            <ul class="berrypress-upgrade-box-list">
                                <li><i class="berrypress-icon-filled berrypress-icon-check"></i><?php esc_html_e( 'Edit Cart Contents', 'live-carts-for-woocommerce' ); ?></li>
                                <li><i class="berrypress-icon-filled berrypress-icon-check"></i><?php esc_html_e( 'Create Recovery URLs', 'live-carts-for-woocommerce' ); ?></li>
                                <li><i class="berrypress-icon-filled berrypress-icon-check"></i><?php esc_html_e( 'Convert Carts To Orders', 'live-carts-for-woocommerce' ); ?></li>
                            </ul>
                            <p><em><?php esc_html_e( '...plus new features added regularly!', 'live-carts-for-woocommerce' ); ?></em></p>
                        </div>
                        <div class="berrypress-upgrade-box-footer">
                            <a href="https://berrypress.com/live-carts/" target="_blank" class="berrypress-btn berrypress-btn-primary">
					            <?php esc_html_e( 'View Plans', 'live-carts-for-woocommerce' ); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- Main -->
            <main id="berrypress-content" class="berrypress-content">
<?php
	}

	function footer() {
?>
            </main>

     </div>
</div>
<?php
	}
	
	abstract function body();
	
}


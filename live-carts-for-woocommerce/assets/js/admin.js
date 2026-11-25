/*!
This file is part of Live Carts for WooCommerce. For copyright and licensing information, please see ../../license/license.txt
*/

(function() {
	function CartsReport(props) {
		var charts = {
			convert_rate: {
				key: 'convert_rate',
				label: wp.i18n.__('Cart conversion rate', 'live-carts-for-woocommerce'),
				type: 'percent'
			},
			abandon_rate: {
				key: 'abandon_rate',
				label: wp.i18n.__('Cart abandonment rate', 'live-carts-for-woocommerce'),
				type: 'percent'
			},
			cart_value: {
				key: 'cart_value',
				label: wp.i18n.__('Average cart value', 'live-carts-for-woocommerce'),
				type: 'currency'
			}
		};
		var selectedChart = props.query && charts[props.query.chart] ? charts[props.query.chart] : charts.convert_rate;
		
		class ReportSummary extends React.Component {
			render() {
				var props = this.props;
				return React.createElement(
					wc.components.SummaryList,
					null,
					function() {	
						return Object.values(charts).map( function(chart) {
							var currentValue = props.summaryData && props.summaryData.totals.primary ? props.summaryData.totals.primary[ chart.key ] : 0;
							var prevValue = props.summaryData && props.summaryData.totals.secondary ? props.summaryData.totals.secondary[ chart.key ] : 0;
							var valueFormat = chart.type === 'percent'
								? function(val) {
									return (Math.round(val * 1000) / 10) + '%';
								  }
								: wc.currency.default().formatAmount;
							return React.createElement(
								wc.components.SummaryNumber,
								{
									key: chart.key,
									href: wc.navigation.getNewPath({ chart: chart.key }),
									value: valueFormat(currentValue),
									prevValue: valueFormat(prevValue),
									delta: wc.number.calculateDelta(currentValue, prevValue),
									label: chart.label,
									prevLabel: wp.i18n.__('Previous period:', 'live-carts-for-woocommerce'),
									selected: (props.query.chart ? props.query.chart : 'convert_rate') === chart.key,
									reverseTrend: chart.isReverseTrend,
									labelTooltipText: chart.labelTooltipText
								}
							);
						} );
					}
				);
			}
		}

		const NinjalyticsIcon = (props = {}) =>
			React.createElement(
				'svg',
				{
					xmlns: 'http://www.w3.org/2000/svg',
					viewBox: '0 0 28.22 20.33',
					width: 26,
					height: 26,
					'aria-hidden': 'true',
					focusable: 'false'
				},
				React.createElement(
					'g',
					null,
					React.createElement('path', { d: 'M16.77,20.33c-1.98,0-3.58-1.61-3.58-3.58s1.61-3.58,3.58-3.58,3.58,1.61,3.58,3.58-1.61,3.58-3.58,3.58ZM16.77,14.84c-1.05,0-1.91.85-1.91,1.91s.85,1.91,1.91,1.91,1.91-.85,1.91-1.91-.85-1.91-1.91-1.91Z', fill: 'currentColor' }),
					React.createElement('path', { d: 'M3.58,20.33c-1.98,0-3.58-1.61-3.58-3.58s1.61-3.58,3.58-3.58,3.58,1.61,3.58,3.58-1.61,3.58-3.58,3.58ZM3.58,14.84c-1.05,0-1.91.85-1.91,1.91s.85,1.91,1.91,1.91,1.91-.85,1.91-1.91-.85-1.91-1.91-1.91Z', fill: 'currentColor' }),
					React.createElement('path', { d: 'M9.28,11.52c-1.98,0-3.58-1.61-3.58-3.58s1.61-3.58,3.58-3.58,3.58,1.61,3.58,3.58-1.61,3.58-3.58,3.58ZM9.28,6.03c-1.05,0-1.91.85-1.91,1.91s.85,1.91,1.91,1.91,1.91-.85,1.91-1.91-.85-1.91-1.91-1.91Z', fill: 'currentColor' }),
					React.createElement('path', { d: 'M23.22,10c-2.76,0-5-2.24-5-5S20.46,0,23.22,0s5,2.24,5,5-2.24,5-5,5ZM23.22,1.68c-1.83,0-3.32,1.49-3.32,3.32s1.49,3.32,3.32,3.32,3.32-1.49,3.32-3.32-1.49-3.32-3.32-3.32Z', fill: 'currentColor' }),
					React.createElement('path', { d: 'M18.09,14.84c-.16,0-.33-.05-.47-.14-.38-.26-.48-.78-.22-1.16l3.5-5.17c.26-.38.78-.48,1.16-.22.38.26.48.78.22,1.16l-3.5,5.17c-.16.24-.43.37-.7.37Z', fill: 'currentColor' }),
					React.createElement('path', { d: 'M4.63,15.05c-.16,0-.33-.05-.47-.14-.38-.26-.48-.78-.22-1.16l2.79-4.11c.26-.38.78-.48,1.16-.22.38.26.48.78.22,1.16l-2.79,4.11c-.16.24-.43.37-.7.37Z', fill: 'currentColor' }),
					React.createElement('path', { d: 'M14.65,15.18c-.22,0-.45-.09-.61-.26l-3.67-3.89c-.32-.34-.3-.87.04-1.19.34-.32.87-.3,1.19.04l3.67,3.89c.32.34.3.87-.04,1.19-.16.15-.37.23-.57.23Z', fill: 'currentColor' })
				)
			);

		return [
			React.createElement(
				wc.components.ReportFilters,
				{
					query: props.query,
					path: props.path,
					report: 'phplugins-carts',
					filters: [{
						showFilters: function() {
							return false;
						},
					}]
				}
			),
			React.createElement(
				wp.compose.compose(
					wp.data.withSelect( function(sel, props) {
						var settings = sel(wc.data.SETTINGS_STORE_NAME).getSetting('wc_admin', 'wcAdminSettings');
						return {
							summaryData: wc.data.getSummaryNumbers({
								endpoint: 'phplugins-carts',
								query: props.query,
								select: sel,
								filters: [],
								defaultDateRange: settings.woocommerce_default_date_range,
								fields: Object.keys(charts)
							}),
							defaultDateRange: settings.woocommerce_default_date_range
						};
					} )
				)( ReportSummary ),
				{
					charts: charts,
					endpoint: 'phplugins-carts',
					isRequesting: props.isRequesting,
					query: props.query,
					selectedChart: selectedChart,
					filters: []
				}
			),
			React.createElement(
				'div',
				{ id: 'phplugins-live-carts-analytics-ninjalytics-promo' },
				[
					NinjalyticsIcon(),

					React.createElement(
						'span',
						{},
						[
							wp.i18n.__(
								'For more advanced Live Carts reporting (and much more!), check out our free ',
								'live-carts-for-woocommerce'
							),
							React.createElement(
								'strong',
								{},
								wp.i18n.__(
									'Ninjalytics plugin',
									'live-carts-for-woocommerce'
								)
							),
							wp.i18n.__(
								'!',
								'live-carts-for-woocommerce'
							)
						]
					),

					React.createElement(
						'a',
						{
							className: 'button-primary live-carts-ninjalytics-btn',
							href: 'plugin-install.php?s=Ninjalytics&tab=search&type=term'
						},
						wp.i18n.__('More Info', 'live-carts-for-woocommerce')
					)
				]
			)
		];
	}

	wp.hooks.addFilter('woocommerce_admin_reports_list', 'phplugins/liveCarts/wcReportsList', function(reports) {
		reports.push({
			report: 'phplugins-carts',
			title: wp.i18n.__('Carts', 'live-carts-for-woocommerce'),
			component: CartsReport,
			navArgs: {
				id: 'phplugins-carts-analytics'
			}
		});
		return reports;
	});
	
	/** Admin page tabs **/
	
	var STORAGE_KEY = 'phplugins_lc_settings_tab';
	var nav = document.getElementById( 'phplugins-lc-settings-tabs' );
	if ( ! nav ) return;
	var tabs   = nav.querySelectorAll( '.nav-tab' );
	var panels = document.querySelectorAll( '.phplugins-lc-settings-tab' );

	function activate( id ) {
		tabs.forEach( function ( t ) {
			t.classList.toggle( 'nav-tab-active', t.dataset.tab === id );
		} );
		panels.forEach( function ( p ) {
			p.style.display = p.dataset.tabPanel === id ? 'block' : 'none';
		} );
		try { localStorage.setItem( STORAGE_KEY, id ); } catch ( e ) {}
	}

	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			activate( this.dataset.tab );
		} );
	} );

	var saved = null;
	try { saved = localStorage.getItem( STORAGE_KEY ); } catch ( e ) {}
	var valid = Array.from( tabs ).map( function ( t ) { return t.dataset.tab; } );
	activate( valid.indexOf( saved ) !== -1 ? saved : valid[0] );

	// Toggle Cart email source visibility when Track cart emails changes.
	var trackEmailsCb = document.getElementById( 'phplugins_carts_track_cart_emails' );
	var emailSourceWrap = document.getElementById( 'phplugins-lc-email-source-wrap' );
	if ( trackEmailsCb && emailSourceWrap ) {
		trackEmailsCb.addEventListener( 'change', function () {
			emailSourceWrap.hidden = ! this.checked;
		} );
	}

})();

document.addEventListener('DOMContentLoaded', function() {
	var btn = document.getElementById('phplugins-copy-shortcode');
	if (btn) {
		btn.addEventListener('click', function() {
			var text = btn.getAttribute('data-shortcode');
			if (btn.hasAttribute('data-label-copy')) {
				var labelCopy = btn.getAttribute('data-label-copy');
			} else {
				var labelCopy = btn.textContent;
				btn.setAttribute('data-label-copy', labelCopy);
			}
			var labelCopied = btn.getAttribute('data-label-copied');
			navigator.clipboard.writeText(text).then(function() {
				btn.textContent = labelCopied;
				setTimeout(function() { btn.textContent = labelCopy; }, 2000);
			});
		});
	}


	var debugToggle = document.getElementById('phplugins_carts_debug');
	var debugLevelField = document.getElementById('phplugins-carts-debug-level-field');
	if (debugToggle && debugLevelField) {
		debugToggle.addEventListener('change', function() {
			debugLevelField.style.display = debugToggle.checked ? '' : 'none';
		});
	}

	var conversionStatusList = document.getElementById('phplugins-carts-conversion-statuses');
	if (conversionStatusList) {
		var conversionStatusModeToggle = document.getElementById('phplugins_carts_conversion_status_mode');
		var manualConversionStatusSettings = document.getElementById('phplugins-carts-manual-conversion-statuses');
		var conversionStatusBoxes = conversionStatusList.querySelectorAll('input[type="checkbox"]');

		function hasSavedCustomSelection() {
			return Array.prototype.some.call(conversionStatusBoxes, function(box) {
				return box.getAttribute('data-custom-selected') === '1';
			});
		}

		function syncConversionStatusInputs() {
			var isCustom = conversionStatusModeToggle.checked;
			var useCustomSelection = isCustom && hasSavedCustomSelection();

			manualConversionStatusSettings.hidden = !isCustom;
			conversionStatusBoxes.forEach(function(box) {
				box.disabled = !isCustom;
				var selectedAttr = useCustomSelection ? 'data-custom-selected' : 'data-automatic-selected';
				box.checked = box.getAttribute(selectedAttr) === '1';
				if (isCustom && !useCustomSelection) {
					box.setAttribute('data-custom-selected', box.checked ? '1' : '0');
				}
			});
		}

		conversionStatusModeToggle.addEventListener('change', syncConversionStatusInputs);
		conversionStatusBoxes.forEach(function(box) {
			box.addEventListener('change', function() {
				box.setAttribute('data-custom-selected', box.checked ? '1' : '0');
			});
		});
		syncConversionStatusInputs();
	}
});

// Generic dropdown: .berrypress-dropdown-trigger toggles the .berrypress-dropdown-menu in its .berrypress-dropdown wrapper.
(function() {
	function closeAll() {
		document.querySelectorAll('.berrypress-dropdown-menu').forEach(function(menu) {
			menu.classList.add('berrypress-hidden');
		});
		document.querySelectorAll('.berrypress-dropdown-trigger').forEach(function(trigger) {
			trigger.setAttribute('aria-expanded', 'false');
		});
	}

	// position:fixed keeps the menu clear of the table wrapper's overflow:hidden.
	function position(trigger, menu) {
		var padding = 8, gap = 5;
		var t = trigger.getBoundingClientRect();
		menu.style.cssText = 'position:fixed;top:-9999px;left:-9999px;right:auto';
		var m = menu.getBoundingClientRect();
		var top = t.bottom + gap;
		if (top + m.height > window.innerHeight - padding) {
			var above = t.top - m.height - gap;
			top = above >= padding ? above : Math.max(padding, window.innerHeight - m.height - padding);
		}
		var left = Math.max(padding, Math.min(t.right - m.width, window.innerWidth - m.width - padding));
		menu.style.top = top + 'px';
		menu.style.left = left + 'px';
	}

	document.addEventListener('click', function(e) {
		var trigger = e.target.closest('.berrypress-dropdown-trigger');
		if (!trigger) {
			if (!e.target.closest('.berrypress-dropdown')) {
				closeAll();
			}
			return;
		}
		e.preventDefault();
		var menu = trigger.closest('.berrypress-dropdown').querySelector('.berrypress-dropdown-menu');
		var wasOpen = !menu.classList.contains('berrypress-hidden');
		closeAll();
		if (!wasOpen) {
			menu.classList.remove('berrypress-hidden');
			position(trigger, menu);
			trigger.setAttribute('aria-expanded', 'true');
		}
	});

	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') {
			closeAll();
		}
	});

	// The menu is position:fixed, so it does not follow its row when the page (or a scroll
	// container) scrolls or the window is resized: close it rather than leave it next to another row.
	function closeIfOpen(e) {
		if (e && e.target && e.target.closest && e.target.closest('.berrypress-dropdown-menu')) {
			return;
		}
		if (document.querySelector('.berrypress-dropdown-menu:not(.berrypress-hidden)')) {
			closeAll();
		}
	}
	window.addEventListener('scroll', closeIfOpen, true); // capture: scroll does not bubble from containers
	window.addEventListener('resize', closeIfOpen);
})();

// Cancel button: closes the <details> it sits in (e.g. the "Add Webhook" form).
document.addEventListener('click', function(e) {
	var cancel = e.target.closest('[data-lc-close-details]');
	if (cancel) {
		cancel.closest('details').removeAttribute('open');
	}
});

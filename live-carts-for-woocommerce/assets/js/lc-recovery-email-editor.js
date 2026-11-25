/*!
This file is part of Live Carts for WooCommerce. For copyright and licensing information, please see ../../license/license.txt
*/

/* global tinymce */
( function () {
	tinymce.PluginManager.add( 'phplugins_live_carts_shortcodes', function ( editor ) {
		var shortcodes = window.phplugins_live_carts_RecoveryEmailShortcodes || {};

		var items = Object.keys( shortcodes ).map( function ( tag ) {
			return {
				text: '[' + tag + ']  —  ' + shortcodes[ tag ],
				onclick: function () {
					editor.insertContent( '[' + tag + ']' );
				},
			};
		} );

		editor.addButton( 'phplugins_live_carts_shortcodes', {
			type: 'menubutton',
			text: 'Shortcodes',
			icon: false,
			menu: items,
		} );
	} );
} )();

<?php
/**
 * Plugin Name: ChitChats Shipping for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/wc-chitchats-shipping/
 * Description: Displays live ChitChats shipping rates at cart / checkout
 * Version: 1.5.16
 * Tested up to: 6.6
 * Requires PHP: 7.3
 * Author: OneTeamSoftware
 * Author URI: http://oneteamsoftware.com/
 * Developer: OneTeamSoftware
 * Developer URI: http://oneteamsoftware.com/
 * Text Domain: wc-chitchats-shipping
 * Domain Path: /languages
 *
 * Copyright: © 2024 FlexRC, Canada.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace OneTeamSoftware\WooCommerce\Shipping;

defined('ABSPATH') || exit;

require_once(__DIR__ . '/includes/autoloader.php');
	
(new Plugin(
		__FILE__, 
		'ChitChats', 
		sprintf('<div class="notice notice-info inline"><p>%s<br/><li><a href="%s" target="_blank">%s</a><br/><li><a href="%s" target="_blank">%s</a></p></div>', 
			__('Real-time ChitChats live shipping rates', 'wc-chitchats-shipping'),
			'https://1teamsoftware.com/contact-us/',
			__('Do you have any questions or requests?', 'wc-chitchats-shipping'),
			'https://wordpress.org/plugins/wc-chitchats-shipping/', 
			__('Do you like our plugin and can recommend to others?', 'wc-chitchats-shipping')),
		'1.5.16'
	)
)->register();

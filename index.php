<?php
/*
Plugin Name: WPSC Simple Margins
Plugin URI: http://geek.1bigidea.com/
Description: Extends WP-e-commerce with product cost to calculate gross margins from product sales
Version: 1.0
Author: Tom Ransom
Author URI: http://1bigidea.com
Network Only: false

Copyright 2013 Tom Ransom (email: transom@1bigidea.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

class onebigidea_WPSC_SimpleMargins {
	private static $_this;
	var $plugin_slug = "onebigidea_WPSC_SimpleMargins";
	var $plugin_name = "WPSC Simple Margins";
	var $plugin_version = "1.0";

	function __construct() {

		register_activation_hook(   __FILE__, array( __CLASS__, 'activate'   ) );
		register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );

		add_action('init', array($this, 'init'));

	}
	function __destruct(){
	}
	function activate() {
		// Add options, initiate cron jobs here
		register_uninstall_hook( __FILE__, array( __CLASS__, 'uninstall' ) );
	}
	function deactivate() {
		// Remove cron jobs here
	}
	function uninstall() {
		// Delete options here
	}
	static function this(){
		// enables external management of filters/actions
		// http://hardcorewp.com/2012/enabling-action-and-filter-hook-removal-from-class-based-wordpress-plugins/
		// enables external management of filters/actions
		// http://hardcorewp.com/2012/enabling-action-and-filter-hook-removal-from-class-based-wordpress-plugins/
		// http://7php.com/how-to-code-a-singleton-design-pattern-in-php-5/
		if( !is_object(self::$_this) ) self::$_this = new onebigidea_WPSC_SimpleMargins();

		return self::$_this;
	}
	/*
	 *	Functions below actually do the work
	 */
	function init(){
		add_action( 'admin_init', array($this, 'add_metabox_controls') );
	}

	function add_metabox_controls(){
		add_meta_box( 'simplemargin_form', __('Cost of Goods', 'wpsc'), array($this, 'margin_input_box'), 'wpsc-product', 'side', 'low' );
	}
	function margin_input_box(){
		global $post, $wpdb, $variations_processor, $wpsc_product_defaults;
		$product_data = get_post_custom( $post->ID );
		$product_data['meta'] = maybe_unserialize( $product_data );

		$price = $product_data['meta']['_wpsc_price'][0];
		$cogs = (empty($product_data['meta']['_wpsc_cogs'])) ? '' : $product_data['meta']['_wpsc_cogs'][0];
		if( !empty($cogs) ){
			$markup = self::markup($cogs, $price);
			$gross_margin = self::gross_margin($cogs, $price);
		}
?>
    	<?php /* Check product if a product has variations */ ?>
    	<?php if ( wpsc_product_has_children( $post->ID ) ) : ?>
    		<?php $cogs = self::wpsc_product_cogs_price_from( $post->ID ); ?>
			<p><?php printf( __( 'This Product has variations, to edit the COG please use the <a href="%s">Variation Controls</a>.' , 'wpsc'  ), '#wpsc_product_variation_forms' ); ?></p>
			<p><?php printf( __( 'Cost: %s and above.' , 'wpsc' ) , $cogs ); ?></p>
		<?php else: ?>
			<div class='wpsc_floatleft'>
				<label><?php esc_html_e( 'Cost of Goods', 'wpsc-simple-margins' ); ?>:</label><br />
				<input type="text" class="text" size='10' name="meta[_wpsc_cogs]" value="<?php echo esc_attr( wpsc_format_number($cogs) );  ?>" />
				<br style="clear:both" />
				<?php printf('%.2f%% %s', $gross_margin, __('Gross Margin', 'wpsc-simple-margins')); ?><br style="clear:both" />
				<?php printf('%.2f%% %s', $markup, __('Markup', 'wpsc-simple-margins')); ?><br style="clear:both" />
			</div>
			<br style="clear:both" />
			<br style="clear:both" />
<?php
		endif;
	}
	/**
	 * WPSC Product Variation Price From
	 * Gets the formatted lowest price of a product's variations.
	 *
	 * @since  3.8.10
	 *
	 * @param  $product_id  (int)       Product ID
	 * @param  $args        (array)     Array of options
	 * @return              (string)    Number formatted price
	 *
	 * @uses   apply_filters()          Calls 'wpsc_do_convert_price' passing price and product ID.
	 * @uses   wpsc_currency_display()  Passing price and args.
	 */
	function wpsc_product_cogs_price_from( $product_id, $args = null ) {
		global $wpdb;
		$args = wp_parse_args( $args, array(
			'from_text'         => false,
			'only_normal_price' => false,
			'only_in_stock'     => false
		) );

		static $price_data = array();

		if ( isset( $price_data[$product_id] ) ) {
			$results = $price_data[$product_id];
		} else {
			$stock_sql = '';
			if ( $args['only_in_stock'] )
				$stock_sql = "INNER JOIN {$wpdb->postmeta} AS pm3 ON pm3.post_id = p.id AND pm3.meta_key = '_wpsc_stock' AND pm3.meta_value != '0'";
			$sql = $wpdb->prepare( "
				SELECT pm.meta_value AS price
				FROM {$wpdb->posts} AS p
				INNER JOIN {$wpdb->postmeta} AS pm ON pm.post_id = p.id AND pm.meta_key = '_wpsc_cogs'
				$stock_sql
				WHERE p.post_type = 'wpsc-product'
					AND p.post_parent = %d
			", $product_id );

			$results = $wpdb->get_results( $sql );
			$price_data[$product_id] = $results;
		}

		$prices = wp_list_pluck($results, 'price');

		sort( $prices );
		if ( empty( $prices ) )
			$prices[] = 0;
		$price = apply_filters( 'wpsc_do_convert_price', $prices[0], $product_id );
		$price = wpsc_currency_display( $price, array( 'display_as_html' => false ) );

		if ( isset( $prices[0] ) && $prices[0] == $prices[count( $prices ) - 1] )
			$args['from_text'] = false;

		if ( $args['from_text'] )
			$price = sprintf( $args['from_text'], $price );

		return $price;
	}

	function gross_margin($cost, $price){
		return round(($price - $cost) / $price, 2) * 100;
	}
	function markup($cost, $price){
		return round(($price - $cost) / $cost, 2) * 100;
	}
}
new onebigidea_WPSC_SimpleMargins();


if ( ! class_exists( 'Autoload_WP' ) ) {
	/**
	 * Generic autoloader for classes named in WordPress coding style.
	 */
	class Autoload_WP {

		public $dir = __DIR__;

		function __construct( $dir = '' ) {

			if ( ! empty( $dir ) )
				$this->dir = $dir;

			spl_autoload_register( array( $this, 'spl_autoload_register' ) );
		}

		function spl_autoload_register( $class_name ) {

			$class_path = $this->dir . '/class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

			if ( file_exists( $class_path ) )
				include $class_path;
		}
	}
}

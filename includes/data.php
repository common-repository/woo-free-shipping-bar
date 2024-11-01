<?php
if ( ! class_exists( 'WFSPB_F_Data' ) ) {
	class WFSPB_F_Data {
		public $params;
        public static $instance = null;
        
        public static function instance( $new = false ) {
            if ( $new || null == self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

		public function __construct() {
			/**
			 * WFSPB_F_FrontEnd_Data constructor.
			 * Init setting
			 */


			global $woocommerce_free_shipping_settings;

			if ( ! $woocommerce_free_shipping_settings ) {
				$woocommerce_free_shipping_settings = get_option( 'wfspb-param', array() );
			}

			$this->params = $woocommerce_free_shipping_settings;
   
			$args         = array(
				/*General*/
				'enable'              => 0,
				'default-zone'        => '6',

				/*Design*/
				'bg-color'            => 'rgb(32, 98, 150)',
				'text-color'          => '#FFFFFF',
				'link-color'          => '#77B508',
				'font'                => 'PT Sans',
				'font-size'           => 16,
				'text-align'          => 'center',
				'enable-progress'     => 0,
				'bg-color-progress'   => '#C9CFD4',
				'bg-current-progress' => '#0D47A1',
				'font-size-progress'  => '11',
				'progress_effect'     => 0,
				'position'            => 0,
				'custom_css'          => '',


				/*Message*/
				'announce-system'     => array( 'default' => 'Free shipping for billing over {min_amount}' ),
				'message-purchased'   => array( 'default' => 'You have purchased {total_amounts} of {min_amount}' ),
				'message-success'     => array( 'default' => 'Congratulation! You have got free shipping. Go to {checkout_page}' ),
				'message-error'       => array( 'default' => 'You are missing {missing_amount} to get Free Shipping. Continue {shopping}' ),
				/*Effect*/
				'show-giftbox'        => 0,
			);
			$this->params = apply_filters( 'woocommerce_free_shipping_bar_settings_args', wp_parse_args( $this->params, $args ) );

		}

		/**
		 * Get Option
		 *
		 * @param $field_name
		 *
		 * @return bool|mixed|void
		 */
		public function get_option( $field_name, $default = '' ) {

			if ( isset( $this->params[ $field_name ] ) ) {

				return apply_filters( 'woocommerce_free_shipping_bar_get_option_' . $field_name, $this->params[ $field_name ] );
			} else {

				return $default;
			}
		}

		// Get woocommerce free shipping zone from db
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		public function check_woo_shipping_zone() {
			global $wpdb;
            
            // Prepare the SQL query safely to avoid SQL injection vulnerabilities.
            $wfspb_query = $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = %s AND is_enabled = %d",
                'free_shipping',
                1
            );
            
            // Execute the query and get the results.
            
            $zone_data = $wpdb->get_results($wfspb_query, OBJECT); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            
            if (empty($zone_data)) {
                return false;
            } else {
                return true;
            }

		}
  
		// detect user's IP
		public function detect_ip( $country = null, $state = '', $postcode = '' ) {
			global $wpdb;
			if ( $country ) {
				$continent  = strtoupper( wc_clean( WC()->countries->get_continent_code_for_country( $country ) ) );
				$criteria   = array();
				$criteria[] = $wpdb->prepare( "( ( location_type = 'country' AND location_code = %s )", $country );
				if ( $state ) {
					$criteria[] = $wpdb->prepare( "OR ( location_type = 'state' AND location_code = %s )", $country . ':' . $state );
				}
				$criteria[] = $wpdb->prepare( "OR ( location_type = 'continent' AND location_code = %s )", $continent );
				$criteria[] = 'OR ( location_type IS NULL ) )';
				// Postcode range and wildcard matching.
				if ( $postcode ) {
					$postcode_locations = $wpdb->get_results( "SELECT zone_id, location_code FROM {$wpdb->prefix}woocommerce_shipping_zone_locations WHERE location_type = 'postcode';" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

					if ( $postcode_locations ) {
						$zone_ids_with_postcode_rules = array_map( 'absint', wp_list_pluck( $postcode_locations, 'zone_id' ) );
						$matches                      = wc_postcode_location_matcher( $postcode, $postcode_locations, 'zone_id', 'location_code', $country );
						$do_not_match                 = array_unique( array_diff( $zone_ids_with_postcode_rules, array_keys( $matches ) ) );

						if ( ! empty( $do_not_match ) ) {
							$criteria[] = 'AND zones.zone_id NOT IN (' . implode( ',', $do_not_match ) . ')';
						}
					}
				}
				$matching_zone_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					"SELECT zones.zone_id FROM {$wpdb->prefix}woocommerce_shipping_zones as zones
				INNER JOIN {$wpdb->prefix}woocommerce_shipping_zone_locations as locations ON zones.zone_id = locations.zone_id AND location_type != 'postcode'
				WHERE " . implode( ' ', $criteria ) . "
				ORDER BY zone_order ASC LIMIT 1"
				);

				$shipping_methods = new  WC_Shipping_Zone( $matching_zone_id ? $matching_zone_id : 0 );
				$shipping_methods = $shipping_methods->get_shipping_methods();
				foreach ( $shipping_methods as $i => $shipping_method ) {
					if ( is_numeric( $i ) ) {
						if ( $shipping_method->id == 'free_shipping' && $shipping_method->enabled == 'yes' ) {
							return array( 'min_amount' => $shipping_method->min_amount, 'ignore_discounts' => $shipping_method->ignore_discounts );
						}
					}
				}
			}

			return array( 'min_amount' => '', 'ignore_discounts' => '' );

		}

		// get min amount cart with zone_id
		public function get_min_amount( $zone_id ) {
			global $wpdb;
			$wfspb_query = $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = 'free_shipping' AND is_enabled = 1 AND zone_id=%d", $zone_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
   
			$zone_data   = $wpdb->get_results( $wfspb_query, OBJECT ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! empty( $zone_data ) ) {
				$first_zone       = $zone_data[0];
				$instance_id      = $first_zone->instance_id;
				$method_id        = $first_zone->method_id;
				$arr_method       = array( $method_id, $instance_id );
				$implode_method   = implode( "_", $arr_method );
				$free_option      = 'woocommerce_' . $implode_method . '_settings';
				$free_shipping_s  = get_option( $free_option );

				return array( 'min_amount' => $free_shipping_s[ 'min_amount' ], 'ignore_discounts' => $free_shipping_s['ignore_discounts'] );
			} else {
				__return_null();
			}
		}

		/**
		 * Get current shipping method of user with current zone
		 * @return WC_Shipping_Zone
		 */
		public function get_shipping_min_amount() {
			/*Get Shipping method*/
			global $wpdb;

			$country          = strtoupper( wc_clean( WC()->customer->country ) );
			$state            = strtoupper( wc_clean( WC()->customer->state ) );
			$continent        = strtoupper( wc_clean( WC()->countries->get_continent_code_for_country( $country ) ) );
			$postcode         = wc_normalize_postcode( WC()->customer->postcode );
			$cache_key        = WC_Cache_Helper::get_cache_prefix( 'shipping_zones' ) . 'wc_shipping_zone_' . md5( sprintf( '%s+%s+%s', $country, $state, $postcode ) );
			$matching_zone_id = wp_cache_get( $cache_key, 'shipping_zones' );

			if ( false === $matching_zone_id ) {

				// Work out criteria for our zone search
				$criteria   = array();
				$criteria[] = $wpdb->prepare( "( ( location_type = 'country' AND location_code = %s )", $country );
				$criteria[] = $wpdb->prepare( "OR ( location_type = 'state' AND location_code = %s )", $country . ':' . $state );
				$criteria[] = $wpdb->prepare( "OR ( location_type = 'continent' AND location_code = %s )", $continent );
				$criteria[] = "OR ( location_type IS NULL ) )";

				// Postcode range and wildcard matching
				$postcode_locations = $wpdb->get_results( "SELECT zone_id, location_code FROM {$wpdb->prefix}woocommerce_shipping_zone_locations WHERE location_type = 'postcode';" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

				if ( $postcode_locations ) {
					$zone_ids_with_postcode_rules = array_map( 'absint', wp_list_pluck( $postcode_locations, 'zone_id' ) );
					$matches                      = wc_postcode_location_matcher( $postcode, $postcode_locations, 'zone_id', 'location_code', $country );
					$do_not_match                 = array_unique( array_diff( $zone_ids_with_postcode_rules, array_keys( $matches ) ) );

					if ( ! empty( $do_not_match ) ) {
						$criteria[] = "AND zones.zone_id NOT IN (" . implode( ',', $do_not_match ) . ")";
					}
				}

				// Get matching zones
				$matching_zone_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					"SELECT zones.zone_id FROM {$wpdb->prefix}woocommerce_shipping_zones as zones
				INNER JOIN {$wpdb->prefix}woocommerce_shipping_zone_locations as locations ON zones.zone_id = locations.zone_id AND location_type != 'postcode'
				WHERE " . implode( ' ', $criteria ) . "
				ORDER BY zone_order ASC LIMIT 1"
				);
			}

			$shipping_methods = new  WC_Shipping_Zone( $matching_zone_id ? $matching_zone_id : 0 );
			$shipping_methods = $shipping_methods->get_shipping_methods();
			foreach ( $shipping_methods as $i => $shipping_method ) {
				if ( is_numeric( $i ) ) {
					if ( $shipping_method->id == 'free_shipping' ) {
						return array( 'min_amount' => $shipping_method->min_amount, 'ignore_discounts' => $shipping_method->ignore_discounts );
					} else {
						continue;
					}
				}
			}

			return false;
		}

		//convert price to integer
		public function toInt( $str ) {
			return preg_replace( "/([^0-9\\.])/i", "", $str );
		}

		public function get_price_including_tax( $line_price, $round_mode = PHP_ROUND_HALF_UP ) {
			$return_price = $line_price;
			if ( ! wc_prices_include_tax() ) {
				$tax_rates = WC_Tax::get_rates( '' );
				$taxes     = WC_Tax::calc_tax( $line_price, $tax_rates, false );

				if ( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ) {
					$taxes_total = array_sum( $taxes );
				} else {
					$taxes_total = array_sum( array_map( 'wc_round_tax_total', $taxes ) );
				}

				$return_price = round( $line_price + $taxes_total, wc_get_price_decimals(), $round_mode );
			} else {
				$tax_rates      = WC_Tax::get_rates( '' );
				$base_tax_rates = WC_Tax::get_base_tax_rates( '' );

				/**
				 * If the customer is excempt from VAT, remove the taxes here.
				 * Either remove the base or the user taxes depending on woocommerce_adjust_non_base_location_prices setting.
				 */
				if ( ! empty( WC()->customer ) && WC()->customer->get_is_vat_exempt() ) { // @codingStandardsIgnoreLine.
					$remove_taxes = apply_filters( 'woocommerce_adjust_non_base_location_prices', true ) ? WC_Tax::calc_tax( $line_price, $base_tax_rates, true ) : WC_Tax::calc_tax( $line_price, $tax_rates, true );

					if ( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ) {
						$remove_taxes_total = array_sum( $remove_taxes );
					} else {
						$remove_taxes_total = array_sum( array_map( 'wc_round_tax_total', $remove_taxes ) );
					}

					$return_price = round( $line_price - $remove_taxes_total, wc_get_price_decimals(), $round_mode );

					/**
					 * The woocommerce_adjust_non_base_location_prices filter can stop base taxes being taken off when dealing with out of base locations.
					 * e.g. If a product costs 10 including tax, all users will pay 10 regardless of location and taxes.
					 * This feature is experimental @since 2.4.7 and may change in the future. Use at your risk.
					 */
				} elseif ( $tax_rates !== $base_tax_rates && apply_filters( 'woocommerce_adjust_non_base_location_prices', true ) ) {
					$base_taxes   = WC_Tax::calc_tax( $line_price, $base_tax_rates, true );
					$modded_taxes = WC_Tax::calc_tax( $line_price - array_sum( $base_taxes ), $tax_rates, false );

					if ( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ) {
						$base_taxes_total   = array_sum( $base_taxes );
						$modded_taxes_total = array_sum( $modded_taxes );
					} else {
						$base_taxes_total   = array_sum( array_map( 'wc_round_tax_total', $base_taxes ) );
						$modded_taxes_total = array_sum( array_map( 'wc_round_tax_total', $modded_taxes ) );
					}

					$return_price = round( $line_price - $base_taxes_total + $modded_taxes_total, wc_get_price_decimals(), $round_mode );
				}
			}

			return $return_price;
		}

		public function real_amount( $price, $round_mode = PHP_ROUND_HALF_UP ) {
			return $price;

		}
	}
}

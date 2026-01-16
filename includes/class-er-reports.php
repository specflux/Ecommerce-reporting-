<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ER_Reports {
	private const AGGREGATION_DAYS = 30;
	private const COHORT_MONTHS = 12;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'capture_utm_params' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'store_order_attribution' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'update_daily_aggregates' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'update_daily_aggregates' ) );
		add_action( 'er_daily_aggregation', array( __CLASS__, 'run_daily_aggregation' ) );
	}

	public static function capture_utm_params(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$utm_keys = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' );
		$data     = array();

		foreach ( $utm_keys as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$data[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}

		if ( $data ) {
			WC()->session->set( 'er_utm_params', $data );
		}
	}

	public static function store_order_attribution( WC_Order $order, array $data ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$params = WC()->session->get( 'er_utm_params' );
		if ( empty( $params ) || ! is_array( $params ) ) {
			return;
		}

		foreach ( $params as $key => $value ) {
			$order->update_meta_data( "_er_{$key}", $value );
		}
	}

	public static function update_daily_aggregates( int $order_id ): void {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$report_date = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$already_aggregated = $order->get_meta( '_er_aggregated_date' );
		if ( $already_aggregated === $report_date ) {
			return;
		}

		$total       = (float) $order->get_total();
		$refunds     = (float) $order->get_total_refunded();

		$is_new_customer = self::is_new_customer( $order );
		$new_customers   = $is_new_customer ? 1 : 0;
		$returning       = $is_new_customer ? 0 : 1;

		$daily_table = $wpdb->prefix . 'wc_reports_daily';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$daily_table} (report_date, revenue, orders_count, refunds, aov, new_customers, returning_customers)
				VALUES (%s, %f, %d, %f, %f, %d, %d)
				ON DUPLICATE KEY UPDATE
					revenue = revenue + VALUES(revenue),
					orders_count = orders_count + VALUES(orders_count),
					refunds = refunds + VALUES(refunds),
					aov = IF(orders_count + VALUES(orders_count) > 0, (revenue + VALUES(revenue)) / (orders_count + VALUES(orders_count)), 0),
					new_customers = new_customers + VALUES(new_customers),
					returning_customers = returning_customers + VALUES(returning_customers)",
				$report_date,
				$total,
				1,
				$refunds,
				$total,
				$new_customers,
				$returning
			)
		);

		$product_table = $wpdb->prefix . 'wc_reports_products_daily';
		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$line_total = (float) $item->get_total();
			$quantity   = (int) $item->get_quantity();

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$product_table} (report_date, product_id, revenue, quantity)
					VALUES (%s, %d, %f, %d)
					ON DUPLICATE KEY UPDATE
						revenue = revenue + VALUES(revenue),
						quantity = quantity + VALUES(quantity)",
					$report_date,
					$product_id,
					$line_total,
					$quantity
				)
			);
		}

		$order->update_meta_data( '_er_aggregated_date', $report_date );
		self::update_marketing_aggregate( $order, $report_date );
		$order->save();
	}

	public static function get_overview_metrics( int $days = 30 ): array {
		global $wpdb;

		$days       = max( 1, $days );
		$daily_table = $wpdb->prefix . 'wc_reports_daily';

		$results = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(revenue) AS revenue,
					SUM(orders_count) AS orders_count,
					SUM(refunds) AS refunds,
					SUM(new_customers) AS new_customers,
					SUM(returning_customers) AS returning_customers,
					AVG(aov) AS aov
				FROM {$daily_table}
				WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)",
				$days
			),
			ARRAY_A
		);

		return array(
			'revenue'            => (float) ( $results['revenue'] ?? 0 ),
			'orders_count'       => (int) ( $results['orders_count'] ?? 0 ),
			'refunds'            => (float) ( $results['refunds'] ?? 0 ),
			'new_customers'      => (int) ( $results['new_customers'] ?? 0 ),
			'returning_customers' => (int) ( $results['returning_customers'] ?? 0 ),
			'aov'                => (float) ( $results['aov'] ?? 0 ),
		);
	}

	public static function get_top_products( int $days = 30, int $limit = 10 ): array {
		global $wpdb;

		$days        = max( 1, $days );
		$limit       = max( 1, $limit );
		$product_table = $wpdb->prefix . 'wc_reports_products_daily';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, SUM(revenue) AS revenue, SUM(quantity) AS quantity
				FROM {$product_table}
				WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				GROUP BY product_id
				ORDER BY revenue DESC
				LIMIT %d",
				$days,
				$limit
			),
			ARRAY_A
		);

		return $results ?: array();
	}

	public static function get_marketing_sources( int $days = 30, int $limit = 10 ): array {
		global $wpdb;

		$days  = max( 1, $days );
		$limit = max( 1, $limit );
		$marketing_table = $wpdb->prefix . 'wc_reports_marketing_daily';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, medium, campaign, SUM(revenue) AS revenue, SUM(orders_count) AS orders_count
				FROM {$marketing_table}
				WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				GROUP BY source, medium, campaign
				ORDER BY revenue DESC
				LIMIT %d",
				$days,
				$limit
			),
			ARRAY_A
		);

		return $results ?: array();
	}

	public static function get_daily_trends( int $days = 30 ): array {
		global $wpdb;

		$days       = max( 1, $days );
		$daily_table = $wpdb->prefix . 'wc_reports_daily';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT report_date, revenue, orders_count
				FROM {$daily_table}
				WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				ORDER BY report_date ASC",
				$days
			),
			ARRAY_A
		);

		return $results ?: array();
	}

	public static function get_cohort_summary( int $months = 6 ): array {
		global $wpdb;

		$months      = max( 1, $months );
		$cohort_table = $wpdb->prefix . 'wc_reports_cohorts_monthly';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cohort_month, customers_count, orders_count, revenue, repeat_orders_count, repeat_revenue
				FROM {$cohort_table}
				WHERE cohort_month >= DATE_SUB(DATE_FORMAT(CURDATE(), '%%Y-%%m-01'), INTERVAL %d MONTH)
				ORDER BY cohort_month DESC",
				$months
			),
			ARRAY_A
		);

		return $results ?: array();
	}

	public static function run_daily_aggregation(): void {
		global $wpdb;

		$start_date = gmdate( 'Y-m-d', strtotime( '-' . self::AGGREGATION_DAYS . ' days' ) );
		$daily_table = $wpdb->prefix . 'wc_reports_daily';
		$product_table = $wpdb->prefix . 'wc_reports_products_daily';
		$marketing_table = $wpdb->prefix . 'wc_reports_marketing_daily';
		$cohort_table = $wpdb->prefix . 'wc_reports_cohorts_monthly';
		$cohort_start = gmdate( 'Y-m-01', strtotime( '-' . self::COHORT_MONTHS . ' months' ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$daily_table} WHERE report_date >= %s",
				$start_date
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$product_table} WHERE report_date >= %s",
				$start_date
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$marketing_table} WHERE report_date >= %s",
				$start_date
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$cohort_table} WHERE cohort_month >= %s",
				$cohort_start
			)
		);

		$args = array(
			'limit'        => -1,
			'status'       => array( 'completed', 'processing' ),
			'date_created' => '>=' . $start_date,
		);
		$orders = wc_get_orders( $args );

		$daily_totals     = array();
		$product_totals   = array();
		$marketing_totals = array();
		$cohort_totals    = array();
		$first_order_cache = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$report_date = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : gmdate( 'Y-m-d' );

			if ( ! isset( $daily_totals[ $report_date ] ) ) {
				$daily_totals[ $report_date ] = array(
					'revenue'            => 0,
					'orders_count'       => 0,
					'refunds'            => 0,
					'new_customers'      => 0,
					'returning_customers' => 0,
				);
			}

			$is_new_customer = self::is_new_customer( $order );
			$daily_totals[ $report_date ]['revenue'] += (float) $order->get_total();
			$daily_totals[ $report_date ]['orders_count'] += 1;
			$daily_totals[ $report_date ]['refunds'] += (float) $order->get_total_refunded();
			$daily_totals[ $report_date ]['new_customers'] += $is_new_customer ? 1 : 0;
			$daily_totals[ $report_date ]['returning_customers'] += $is_new_customer ? 0 : 1;

			foreach ( $order->get_items() as $item ) {
				$product_id = $item->get_product_id();
				if ( ! $product_id ) {
					continue;
				}

				if ( ! isset( $product_totals[ $report_date ][ $product_id ] ) ) {
					$product_totals[ $report_date ][ $product_id ] = array(
						'revenue'  => 0,
						'quantity' => 0,
					);
				}

				$product_totals[ $report_date ][ $product_id ]['revenue'] += (float) $item->get_total();
				$product_totals[ $report_date ][ $product_id ]['quantity'] += (int) $item->get_quantity();
			}

			$attribution = self::get_order_attribution( $order );
			$source = $attribution['source'];
			$medium = $attribution['medium'];
			$campaign = $attribution['campaign'];

			if ( ! isset( $marketing_totals[ $report_date ][ $source ][ $medium ][ $campaign ] ) ) {
				$marketing_totals[ $report_date ][ $source ][ $medium ][ $campaign ] = array(
					'revenue'      => 0,
					'orders_count' => 0,
				);
			}

			$marketing_totals[ $report_date ][ $source ][ $medium ][ $campaign ]['revenue'] += (float) $order->get_total();
			$marketing_totals[ $report_date ][ $source ][ $medium ][ $campaign ]['orders_count'] += 1;

			$cohort_month = self::get_customer_cohort_month( $order, $first_order_cache );
			if ( $cohort_month ) {
				if ( ! isset( $cohort_totals[ $cohort_month ] ) ) {
					$cohort_totals[ $cohort_month ] = array(
						'customers_count'     => array(),
						'orders_count'        => 0,
						'revenue'             => 0,
						'repeat_orders_count' => 0,
						'repeat_revenue'      => 0,
					);
				}

				$customer_key = self::get_customer_key( $order );
				if ( $customer_key ) {
					$cohort_totals[ $cohort_month ]['customers_count'][ $customer_key ] = true;
				}

				$cohort_totals[ $cohort_month ]['orders_count'] += 1;
				$cohort_totals[ $cohort_month ]['revenue'] += (float) $order->get_total();

				$is_repeat = $cohort_month !== gmdate( 'Y-m-01', strtotime( $report_date ) );
				if ( $is_repeat ) {
					$cohort_totals[ $cohort_month ]['repeat_orders_count'] += 1;
					$cohort_totals[ $cohort_month ]['repeat_revenue'] += (float) $order->get_total();
				}
			}
		}

		foreach ( $daily_totals as $report_date => $totals ) {
			$aov = $totals['orders_count'] > 0 ? $totals['revenue'] / $totals['orders_count'] : 0;

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$daily_table} (report_date, revenue, orders_count, refunds, aov, new_customers, returning_customers)
					VALUES (%s, %f, %d, %f, %f, %d, %d)
					ON DUPLICATE KEY UPDATE
						revenue = VALUES(revenue),
						orders_count = VALUES(orders_count),
						refunds = VALUES(refunds),
						aov = VALUES(aov),
						new_customers = VALUES(new_customers),
						returning_customers = VALUES(returning_customers)",
					$report_date,
					$totals['revenue'],
					$totals['orders_count'],
					$totals['refunds'],
					$aov,
					$totals['new_customers'],
					$totals['returning_customers']
				)
			);
		}

		foreach ( $product_totals as $report_date => $products ) {
			foreach ( $products as $product_id => $totals ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$product_table} (report_date, product_id, revenue, quantity)
						VALUES (%s, %d, %f, %d)
						ON DUPLICATE KEY UPDATE
							revenue = VALUES(revenue),
							quantity = VALUES(quantity)",
						$report_date,
						$product_id,
						$totals['revenue'],
						$totals['quantity']
					)
				);
			}
		}

		foreach ( $marketing_totals as $report_date => $sources ) {
			foreach ( $sources as $source => $media ) {
				foreach ( $media as $medium => $campaigns ) {
					foreach ( $campaigns as $campaign => $totals ) {
						$wpdb->query(
							$wpdb->prepare(
								"INSERT INTO {$marketing_table} (report_date, source, medium, campaign, revenue, orders_count)
								VALUES (%s, %s, %s, %s, %f, %d)
								ON DUPLICATE KEY UPDATE
									revenue = VALUES(revenue),
									orders_count = VALUES(orders_count)",
								$report_date,
								$source,
								$medium,
								$campaign,
								$totals['revenue'],
								$totals['orders_count']
							)
						);
					}
				}
			}
		}

		foreach ( $cohort_totals as $cohort_month => $totals ) {
			$customers_count = count( $totals['customers_count'] );
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$cohort_table} (cohort_month, customers_count, orders_count, revenue, repeat_orders_count, repeat_revenue)
					VALUES (%s, %d, %d, %f, %d, %f)
					ON DUPLICATE KEY UPDATE
						customers_count = VALUES(customers_count),
						orders_count = VALUES(orders_count),
						revenue = VALUES(revenue),
						repeat_orders_count = VALUES(repeat_orders_count),
						repeat_revenue = VALUES(repeat_revenue)",
					$cohort_month,
					$customers_count,
					$totals['orders_count'],
					$totals['revenue'],
					$totals['repeat_orders_count'],
					$totals['repeat_revenue']
				)
			);
		}
	}

	private static function update_marketing_aggregate( WC_Order $order, string $report_date ): void {
		global $wpdb;

		$marketing_table = $wpdb->prefix . 'wc_reports_marketing_daily';
		$attribution = self::get_order_attribution( $order );
		$source = $attribution['source'];
		$medium = $attribution['medium'];
		$campaign = $attribution['campaign'];
		$total  = (float) $order->get_total();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$marketing_table} (report_date, source, medium, campaign, revenue, orders_count)
				VALUES (%s, %s, %s, %s, %f, %d)
				ON DUPLICATE KEY UPDATE
					revenue = revenue + VALUES(revenue),
					orders_count = orders_count + VALUES(orders_count)",
				$report_date,
				$source,
				$medium,
				$campaign,
				$total,
				1
			)
		);
	}

	private static function get_order_attribution( WC_Order $order ): array {
		$source = $order->get_meta( '_er_utm_source' );
		$medium = $order->get_meta( '_er_utm_medium' );
		$campaign = $order->get_meta( '_er_utm_campaign' );

		return array(
			'source'   => $source ? (string) $source : __( 'direct', 'ecommerce-reporting' ),
			'medium'   => $medium ? (string) $medium : __( 'none', 'ecommerce-reporting' ),
			'campaign' => $campaign ? (string) $campaign : '',
		);
	}

	private static function get_customer_key( WC_Order $order ): string {
		$customer_id = $order->get_customer_id();
		if ( $customer_id ) {
			return 'id:' . $customer_id;
		}

		$email = $order->get_billing_email();
		if ( $email ) {
			return 'email:' . strtolower( $email );
		}

		return '';
	}

	private static function get_customer_cohort_month( WC_Order $order, array &$cache ): string {
		$key = self::get_customer_key( $order );
		if ( ! $key ) {
			return '';
		}

		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$args = array(
			'limit'        => 1,
			'status'       => array( 'completed', 'processing' ),
			'orderby'      => 'date',
			'order'        => 'ASC',
			'return'       => 'ids',
		);

		if ( str_starts_with( $key, 'id:' ) ) {
			$args['customer_id'] = (int) substr( $key, 3 );
		} else {
			$args['billing_email'] = substr( $key, 6 );
		}

		$orders = wc_get_orders( $args );
		$first_order_id = $orders[0] ?? 0;
		if ( ! $first_order_id ) {
			$cache[ $key ] = '';
			return '';
		}

		$first_order = wc_get_order( $first_order_id );
		if ( ! $first_order ) {
			$cache[ $key ] = '';
			return '';
		}

		$first_date = $first_order->get_date_created();
		if ( ! $first_date ) {
			$cache[ $key ] = '';
			return '';
		}

		$cohort_month = $first_date->date( 'Y-m-01' );
		$cache[ $key ] = $cohort_month;

		return $cohort_month;
	}

	private static function is_new_customer( WC_Order $order ): bool {
		$customer_id = $order->get_customer_id();
		$email       = $order->get_billing_email();

		$args = array(
			'limit'        => 1,
			'status'       => array( 'completed', 'processing' ),
			'orderby'      => 'date',
			'order'        => 'ASC',
			'return'       => 'ids',
			'exclude'      => array( $order->get_id() ),
		);

		if ( $customer_id ) {
			$args['customer_id'] = $customer_id;
		} elseif ( $email ) {
			$args['billing_email'] = $email;
		}

		$orders = wc_get_orders( $args );
		return empty( $orders );
	}
}

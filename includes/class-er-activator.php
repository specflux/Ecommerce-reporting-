<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ER_Activator {
	public static function activate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$daily_table     = $wpdb->prefix . 'wc_reports_daily';
		$product_table   = $wpdb->prefix . 'wc_reports_products_daily';
		$marketing_table = $wpdb->prefix . 'wc_reports_marketing_daily';
		$cohort_table    = $wpdb->prefix . 'wc_reports_cohorts_monthly';

		$sql_daily = "CREATE TABLE {$daily_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			report_date DATE NOT NULL,
			revenue DECIMAL(15,4) NOT NULL DEFAULT 0,
			orders_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			refunds DECIMAL(15,4) NOT NULL DEFAULT 0,
			aov DECIMAL(15,4) NOT NULL DEFAULT 0,
			new_customers BIGINT UNSIGNED NOT NULL DEFAULT 0,
			returning_customers BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY report_date (report_date)
		) {$charset_collate};";

		$sql_products = "CREATE TABLE {$product_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			report_date DATE NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			revenue DECIMAL(15,4) NOT NULL DEFAULT 0,
			quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY report_product (report_date, product_id),
			KEY product_id (product_id)
		) {$charset_collate};";

		$sql_marketing = "CREATE TABLE {$marketing_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			report_date DATE NOT NULL,
			source VARCHAR(200) NOT NULL,
			medium VARCHAR(200) NOT NULL DEFAULT '',
			campaign VARCHAR(200) NOT NULL DEFAULT '',
			revenue DECIMAL(15,4) NOT NULL DEFAULT 0,
			orders_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY report_source (report_date, source, medium, campaign),
			KEY source (source),
			KEY campaign (campaign)
		) {$charset_collate};";

		$sql_cohort = "CREATE TABLE {$cohort_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cohort_month DATE NOT NULL,
			customers_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			orders_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			revenue DECIMAL(15,4) NOT NULL DEFAULT 0,
			repeat_orders_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			repeat_revenue DECIMAL(15,4) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY cohort_month (cohort_month)
		) {$charset_collate};";

		dbDelta( $sql_daily );
		dbDelta( $sql_products );
		dbDelta( $sql_marketing );
		dbDelta( $sql_cohort );

		if ( ! wp_next_scheduled( 'er_daily_aggregation' ) ) {
			wp_schedule_event( time(), 'daily', 'er_daily_aggregation' );
		}

		if ( class_exists( 'WooCommerce' ) && class_exists( 'ER_Reports' ) ) {
			ER_Reports::run_daily_aggregation();
		}
	}
}

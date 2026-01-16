<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ER_Admin {
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'Ecommerce Reporting', 'ecommerce-reporting' ),
			__( 'Ecommerce Reporting', 'ecommerce-reporting' ),
			'manage_woocommerce',
			'er-analytics',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-chart-line',
			56
		);
	}

	public static function render_dashboard(): void {
		$days = isset( $_GET['er_days'] ) ? (int) $_GET['er_days'] : 30;
		$days = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;

		$overview  = ER_Reports::get_overview_metrics( $days );
		$products  = ER_Reports::get_top_products( $days, 5 );
		$sources   = ER_Reports::get_marketing_sources( $days, 5 );
		$trends    = ER_Reports::get_daily_trends( $days );
		$cohorts   = ER_Reports::get_cohort_summary( 6 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Ecommerce Reporting Overview', 'ecommerce-reporting' ); ?></h1>

			<form method="get">
				<input type="hidden" name="page" value="er-analytics" />
				<label for="er_days"><?php esc_html_e( 'Date Range', 'ecommerce-reporting' ); ?></label>
				<select id="er_days" name="er_days">
					<option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( 'Last 7 Days', 'ecommerce-reporting' ); ?></option>
					<option value="30" <?php selected( $days, 30 ); ?>><?php esc_html_e( 'Last 30 Days', 'ecommerce-reporting' ); ?></option>
					<option value="90" <?php selected( $days, 90 ); ?>><?php esc_html_e( 'Last 90 Days', 'ecommerce-reporting' ); ?></option>
				</select>
				<?php submit_button( __( 'Apply', 'ecommerce-reporting' ), 'secondary', '', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Overview', 'ecommerce-reporting' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'Revenue:', 'ecommerce-reporting' ); ?> <?php echo esc_html( wc_price( $overview['revenue'] ) ); ?></li>
				<li><?php esc_html_e( 'Orders:', 'ecommerce-reporting' ); ?> <?php echo esc_html( $overview['orders_count'] ); ?></li>
				<li><?php esc_html_e( 'Refunds:', 'ecommerce-reporting' ); ?> <?php echo esc_html( wc_price( $overview['refunds'] ) ); ?></li>
				<li><?php esc_html_e( 'AOV:', 'ecommerce-reporting' ); ?> <?php echo esc_html( wc_price( $overview['aov'] ) ); ?></li>
				<li><?php esc_html_e( 'New Customers:', 'ecommerce-reporting' ); ?> <?php echo esc_html( $overview['new_customers'] ); ?></li>
				<li><?php esc_html_e( 'Returning Customers:', 'ecommerce-reporting' ); ?> <?php echo esc_html( $overview['returning_customers'] ); ?></li>
			</ul>

			<h2><?php esc_html_e( 'Top Products', 'ecommerce-reporting' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'ecommerce-reporting' ); ?></th>
						<th><?php esc_html_e( 'Revenue', 'ecommerce-reporting' ); ?></th>
						<th><?php esc_html_e( 'Quantity', 'ecommerce-reporting' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $products ) ) : ?>
						<tr>
							<td colspan="3"><?php esc_html_e( 'No data yet.', 'ecommerce-reporting' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $products as $product ) : ?>
							<tr>
								<td><?php echo esc_html( get_the_title( (int) $product['product_id'] ) ); ?></td>
								<td><?php echo esc_html( wc_price( (float) $product['revenue'] ) ); ?></td>
								<td><?php echo esc_html( (int) $product['quantity'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Top Marketing Sources', 'ecommerce-reporting' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Source', 'ecommerce-reporting' ); ?></th>
						<th><?php esc_html_e( 'Medium', 'ecommerce-reporting' ); ?></th>
						<th><?php esc_html_e( 'Campaign', 'ecommerce-reporting' ); ?></th>
						<th><?php esc_html_e( 'Revenue', 'ecommerce-reporting' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'ecommerce-reporting' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $sources ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No marketing attribution data yet.', 'ecommerce-reporting' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $sources as $source ) : ?>
							<tr>
								<td><?php echo esc_html( $source['source'] ); ?></td>
								<td><?php echo esc_html( $source['medium'] ); ?></td>
								<td><?php echo esc_html( $source['campaign'] ); ?></td>
								<td><?php echo esc_html( wc_price( (float) $source['revenue'] ) ); ?></td>
								<td><?php echo esc_html( (int) $source['orders_count'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Daily Revenue Trend', 'ecommerce-reporting' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'ecommerce-reporting' ); ?></th>
						<th><?php esc_html_e( 'Revenue', 'ecommerce-reporting' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'ecommerce-reporting' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $trends ) ) : ?>
						<tr>
							<td colspan="3"><?php esc_html_e( 'No trend data yet.', 'ecommerce-reporting' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $trends as $trend ) : ?>
							<tr>
								<td><?php echo esc_html( $trend['report_date'] ); ?></td>
								<td><?php echo esc_html( wc_price( (float) $trend['revenue'] ) ); ?></td>
								<td><?php echo esc_html( (int) $trend['orders_count'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Customer Cohorts (Last 6 Months)', 'ecommerce-reporting' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Cohort Month', 'ecommerce-reporting' ); ?></th>
						<th><?php esc_html_e( 'New Customers', 'ecommerce-reporting' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'ecommerce-reporting' ); ?></th>
						<th><?php esc_html_e( 'Revenue', 'ecommerce-reporting' ); ?></th>
						<th><?php esc_html_e( 'Repeat Orders', 'ecommerce-reporting' ); ?></th>
						<th><?php esc_html_e( 'Repeat Revenue', 'ecommerce-reporting' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $cohorts ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'No cohort data yet.', 'ecommerce-reporting' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $cohorts as $cohort ) : ?>
							<tr>
								<td><?php echo esc_html( $cohort['cohort_month'] ); ?></td>
								<td><?php echo esc_html( (int) $cohort['customers_count'] ); ?></td>
								<td><?php echo esc_html( (int) $cohort['orders_count'] ); ?></td>
								<td><?php echo esc_html( wc_price( (float) $cohort['revenue'] ) ); ?></td>
								<td><?php echo esc_html( (int) $cohort['repeat_orders_count'] ); ?></td>
								<td><?php echo esc_html( wc_price( (float) $cohort['repeat_revenue'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

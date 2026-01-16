<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ER_Deactivator {
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( 'er_daily_aggregation' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'er_daily_aggregation' );
		}
	}
}

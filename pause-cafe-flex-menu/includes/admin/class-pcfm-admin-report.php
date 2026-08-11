<?php
/**
 * The kitchen report.
 *
 * Grouped by the day food is served, not the day the order was placed. Every
 * mode produces a service date, so one report covers planned, on-publish and
 * manual schedules without knowing which is which.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Admin_Report {

	public static function init() {
		add_action( 'admin_post_pcfm_export_report', array( __CLASS__, 'handle_export' ) );
	}

	private static function selected_date( $schedule_id ) {
		$dates = PCFM_Product::all_service_dates( $schedule_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$raw = isset( $_GET['service_date'] ) ? sanitize_text_field( wp_unslash( $_GET['service_date'] ) ) : '';

		if ( $raw && in_array( $raw, $dates, true ) ) {
			return $raw;
		}

		$current = PCFM_Product::current_service_date( $schedule_id );

		if ( $current ) {
			return $current;
		}

		return $dates ? end( $dates ) : '';
	}

	public static function render() {
		$schedule_id  = PCFM_Admin::current_schedule_id();
		$service_date = self::selected_date( $schedule_id );
		$dates        = PCFM_Product::all_service_dates( $schedule_id );

		echo '<div class="wrap pcfm-wrap">';
		echo '<h1>' . esc_html__( 'Kitchen report', 'pause-cafe-flex-menu' ) . '</h1>';

		PCFM_Admin::print_notices();
		PCFM_Admin::render_schedule_picker( PCFM_Admin::PAGE_REPORT, $schedule_id );

		if ( ! $dates ) {
			echo '<p>' . esc_html__( 'No menu has been scheduled yet.', 'pause-cafe-flex-menu' ) . '</p></div>';

			return;
		}

		echo '<form method="get" class="pcfm-report-controls">';
		echo '<input type="hidden" name="page" value="' . esc_attr( PCFM_Admin::PAGE_REPORT ) . '">';
		printf( '<input type="hidden" name="schedule" value="%d">', (int) $schedule_id );
		echo '<label for="pcfm-report-date">' . esc_html__( 'Serving', 'pause-cafe-flex-menu' ) . '</label> ';
		echo '<select name="service_date" id="pcfm-report-date">';

		foreach ( array_reverse( $dates ) as $date ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $date ),
				selected( $service_date, $date, false ),
				esc_html( PCFM_Window::format_date( $date, 'l j F Y' ) )
			);
		}

		echo '</select> ';
		submit_button( __( 'Show', 'pause-cafe-flex-menu' ), 'secondary', '', false );

		printf(
			' <a class="button" href="%s">%s</a> <button type="button" class="button" onclick="window.print()">%s</button>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action'       => 'pcfm_export_report',
							'schedule'     => $schedule_id,
							'service_date' => $service_date,
						),
						admin_url( 'admin-post.php' )
					),
					'pcfm_export_report'
				)
			),
			esc_html__( 'Download CSV', 'pause-cafe-flex-menu' ),
			esc_html__( 'Print', 'pause-cafe-flex-menu' )
		);

		echo '</form>';

		self::render_summary( $service_date, $schedule_id );

		echo '</div>';
	}

	private static function render_summary( $service_date, $schedule_id ) {
		$summary = PCFM_Orders::summary_for_date( $service_date, $schedule_id );

		printf(
			'<h2 class="pcfm-report-title">%s</h2>',
			esc_html( PCFM_Window::format_date( $service_date, 'l j F Y' ) )
		);

		if ( PCFM_Blackouts::is_blackout( $service_date ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html( PCFM_Blackouts::label( $service_date ) )
			);
		}

		if ( ! $summary ) {
			echo '<p>' . esc_html__( 'No orders for this date yet.', 'pause-cafe-flex-menu' ) . '</p>';

			return;
		}

		$grand_total = 0;

		foreach ( $summary as $location => $dishes ) {
			$location_total = 0;

			foreach ( $dishes as $dish ) {
				$location_total += $dish['qty'];
			}

			$grand_total += $location_total;

			printf(
				'<h3 class="pcfm-report-location">%s <span class="pcfm-report-count">%s</span></h3>',
				esc_html( $location ),
				esc_html(
					sprintf(
						/* translators: %d: number of meals. */
						_n( '%d meal', '%d meals', $location_total, 'pause-cafe-flex-menu' ),
						$location_total
					)
				)
			);

			echo '<table class="widefat striped pcfm-report"><thead><tr>';
			echo '<th>' . esc_html__( 'Dish', 'pause-cafe-flex-menu' ) . '</th>';
			echo '<th class="pcfm-report__qty">' . esc_html__( 'Qty', 'pause-cafe-flex-menu' ) . '</th>';
			echo '<th class="pcfm-report__qty">' . esc_html__( 'Portions', 'pause-cafe-flex-menu' ) . '</th>';
			echo '<th>' . esc_html__( 'Ordered by', 'pause-cafe-flex-menu' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $dishes as $dish_name => $dish ) {
				sort( $dish['people'] );

				$capacity = PCFM_Product::capacity( $dish['product_id'] );

				printf(
					'<tr><td><strong>%s</strong></td><td class="pcfm-report__qty">%d</td><td class="pcfm-report__qty">%s</td><td>%s</td></tr>',
					esc_html( $dish_name ),
					(int) $dish['qty'],
					esc_html(
						$capacity
							? sprintf( '%d / %d', $capacity['sold'], $capacity['limit'] )
							: '∞'
					),
					esc_html( implode( ', ', $dish['people'] ) )
				);
			}

			echo '</tbody></table>';
		}

		printf(
			'<p class="pcfm-report-total"><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: %d: total number of meals. */
					_n( '%d meal in total', '%d meals in total', $grand_total, 'pause-cafe-flex-menu' ),
					$grand_total
				)
			)
		);
	}

	public static function handle_export() {
		if ( ! current_user_can( PCFM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export orders.', 'pause-cafe-flex-menu' ) );
		}

		check_admin_referer( 'pcfm_export_report' );

		$schedule_id  = isset( $_GET['schedule'] ) ? absint( $_GET['schedule'] ) : 0;
		$service_date = isset( $_GET['service_date'] ) ? sanitize_text_field( wp_unslash( $_GET['service_date'] ) ) : '';

		if ( ! PCFM_Window::parse_date( $service_date ) ) {
			wp_die( esc_html__( 'Invalid service date.', 'pause-cafe-flex-menu' ) );
		}

		$rows = PCFM_Orders::line_items_for_date( $service_date, $schedule_id );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=pause-cafe-' . $service_date . '.csv' );

		$out = fopen( 'php://output', 'w' );

		// Excel needs the BOM to read the Chinese dish names correctly.
		fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv(
			$out,
			array(
				__( 'Service date', 'pause-cafe-flex-menu' ),
				__( 'Schedule', 'pause-cafe-flex-menu' ),
				__( 'Location', 'pause-cafe-flex-menu' ),
				__( 'Dish', 'pause-cafe-flex-menu' ),
				__( 'Quantity', 'pause-cafe-flex-menu' ),
				__( 'Ordered by', 'pause-cafe-flex-menu' ),
				__( 'Order', 'pause-cafe-flex-menu' ),
				__( 'Status', 'pause-cafe-flex-menu' ),
			)
		);

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$service_date,
					$row['schedule'],
					$row['location'],
					$row['name'],
					$row['qty'],
					$row['customer'],
					$row['order_id'],
					wc_get_order_status_name( $row['status'] ),
				)
			);
		}

		fclose( $out );
		exit;
	}
}

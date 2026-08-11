<?php
/**
 * The kitchen report.
 *
 * Grouped by service date, not order date. An order placed on Tuesday for the
 * following Sunday belongs on the following Sunday's cook list, and grouping by
 * when the order was placed quietly gets that wrong.
 */

defined( 'ABSPATH' ) || exit;

class PCM_Admin_Report {

	public static function init() {
		add_action( 'admin_post_pcm_export_report', array( __CLASS__, 'handle_export' ) );
	}

	private static function selected_date() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$raw = isset( $_GET['service_date'] ) ? sanitize_text_field( wp_unslash( $_GET['service_date'] ) ) : '';

		if ( $raw && PCM_Schedule::date_obj( $raw ) ) {
			return $raw;
		}

		$current = PCM_Schedule::current_service_date();

		if ( $current ) {
			return $current;
		}

		$all = PCM_Schedule::all_service_dates();

		return $all ? end( $all ) : '';
	}

	public static function render() {
		$service_date = self::selected_date();
		$dates        = PCM_Schedule::all_service_dates();

		echo '<div class="wrap pcm-wrap">';
		echo '<h1>' . esc_html__( 'Kitchen report', 'pause-cafe-menu' ) . '</h1>';

		PCM_Admin::print_notices();

		if ( ! $dates ) {
			echo '<p>' . esc_html__( 'No menu has been scheduled yet.', 'pause-cafe-menu' ) . '</p></div>';

			return;
		}

		echo '<form method="get" class="pcm-report-controls">';
		echo '<input type="hidden" name="page" value="' . esc_attr( PCM_Admin::PAGE_REPORT ) . '">';
		echo '<label for="pcm-report-date">' . esc_html__( 'Service date', 'pause-cafe-menu' ) . '</label> ';
		echo '<select name="service_date" id="pcm-report-date">';

		foreach ( array_reverse( $dates ) as $date ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $date ),
				selected( $service_date, $date, false ),
				esc_html( PCM_Schedule::format_date( $date, 'l j F Y' ) )
			);
		}

		echo '</select> ';
		submit_button( __( 'Show', 'pause-cafe-menu' ), 'secondary', '', false );

		printf(
			' <a class="button" href="%s">%s</a> <button type="button" class="button" onclick="window.print()">%s</button>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action'       => 'pcm_export_report',
							'service_date' => $service_date,
						),
						admin_url( 'admin-post.php' )
					),
					'pcm_export_report'
				)
			),
			esc_html__( 'Download CSV', 'pause-cafe-menu' ),
			esc_html__( 'Print', 'pause-cafe-menu' )
		);

		echo '</form>';

		self::render_summary( $service_date );

		echo '</div>';
	}

	private static function render_summary( $service_date ) {
		$summary = PCM_Orders::summary_for_date( $service_date );

		printf(
			'<h2 class="pcm-report-title">%s</h2>',
			esc_html( PCM_Schedule::format_date( $service_date, 'l j F Y' ) )
		);

		if ( ! $summary ) {
			echo '<p>' . esc_html__( 'No orders for this date yet.', 'pause-cafe-menu' ) . '</p>';

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
				'<h3 class="pcm-report-location">%s <span class="pcm-report-count">%s</span></h3>',
				esc_html( $location ),
				esc_html(
					sprintf(
						/* translators: %d: number of meals. */
						_n( '%d meal', '%d meals', $location_total, 'pause-cafe-menu' ),
						$location_total
					)
				)
			);

			echo '<table class="widefat striped pcm-report"><thead><tr>';
			echo '<th>' . esc_html__( 'Dish', 'pause-cafe-menu' ) . '</th>';
			echo '<th class="pcm-report__qty">' . esc_html__( 'Qty', 'pause-cafe-menu' ) . '</th>';
			echo '<th>' . esc_html__( 'Ordered by', 'pause-cafe-menu' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $dishes as $dish_name => $dish ) {
				sort( $dish['people'] );

				printf(
					'<tr><td><strong>%s</strong></td><td class="pcm-report__qty">%d</td><td>%s</td></tr>',
					esc_html( $dish_name ),
					(int) $dish['qty'],
					esc_html( implode( ', ', $dish['people'] ) )
				);
			}

			echo '</tbody></table>';
		}

		printf(
			'<p class="pcm-report-total"><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: %d: total number of meals. */
					_n( '%d meal in total', '%d meals in total', $grand_total, 'pause-cafe-menu' ),
					$grand_total
				)
			)
		);
	}

	public static function handle_export() {
		if ( ! current_user_can( PCM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export orders.', 'pause-cafe-menu' ) );
		}

		check_admin_referer( 'pcm_export_report' );

		$service_date = isset( $_GET['service_date'] ) ? sanitize_text_field( wp_unslash( $_GET['service_date'] ) ) : '';

		if ( ! PCM_Schedule::date_obj( $service_date ) ) {
			wp_die( esc_html__( 'Invalid service date.', 'pause-cafe-menu' ) );
		}

		$rows = PCM_Orders::line_items_for_date( $service_date );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=pause-cafe-' . $service_date . '.csv' );

		$out = fopen( 'php://output', 'w' );

		// Excel needs the BOM to read the Chinese dish names correctly.
		fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv(
			$out,
			array(
				__( 'Service date', 'pause-cafe-menu' ),
				__( 'Location', 'pause-cafe-menu' ),
				__( 'Dish', 'pause-cafe-menu' ),
				__( 'Quantity', 'pause-cafe-menu' ),
				__( 'Ordered by', 'pause-cafe-menu' ),
				__( 'Order', 'pause-cafe-menu' ),
				__( 'Status', 'pause-cafe-menu' ),
			)
		);

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$service_date,
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

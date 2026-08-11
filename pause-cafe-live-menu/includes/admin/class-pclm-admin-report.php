<?php
/**
 * The kitchen report, grouped by ordering cycle.
 *
 * A cycle is one published menu and everything ordered against it, so the cook
 * list is right regardless of when in the window each order was placed.
 */

defined( 'ABSPATH' ) || exit;

class PCLM_Admin_Report {

	public static function init() {
		add_action( 'admin_post_pclm_export_report', array( __CLASS__, 'handle_export' ) );
	}

	private static function selected_cycle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$raw = isset( $_GET['cycle'] ) ? sanitize_text_field( wp_unslash( $_GET['cycle'] ) ) : '';

		$cycles = PCLM_Schedule::all_cycles();

		if ( $raw && in_array( $raw, $cycles, true ) ) {
			return $raw;
		}

		$current = PCLM_Schedule::current_cycle();

		if ( $current ) {
			return $current;
		}

		return $cycles ? end( $cycles ) : '';
	}

	public static function render() {
		$cycle  = self::selected_cycle();
		$cycles = PCLM_Schedule::all_cycles();

		echo '<div class="wrap pclm-wrap">';
		echo '<h1>' . esc_html__( 'Kitchen report', 'pause-cafe-live-menu' ) . '</h1>';

		PCLM_Admin::print_notices();

		if ( ! $cycles ) {
			echo '<p>' . esc_html__( 'No menu has been published yet.', 'pause-cafe-live-menu' ) . '</p></div>';

			return;
		}

		echo '<form method="get" class="pclm-report-controls">';
		echo '<input type="hidden" name="page" value="' . esc_attr( PCLM_Admin::PAGE_REPORT ) . '">';
		echo '<label for="pclm-report-cycle">' . esc_html__( 'Serving', 'pause-cafe-live-menu' ) . '</label> ';
		echo '<select name="cycle" id="pclm-report-cycle">';

		foreach ( array_reverse( $cycles ) as $option ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $option ),
				selected( $cycle, $option, false ),
				esc_html( PCLM_Schedule::format_date( PCLM_Schedule::service_date_for_cycle( $option ), 'l j F Y' ) )
			);
		}

		echo '</select> ';
		submit_button( __( 'Show', 'pause-cafe-live-menu' ), 'secondary', '', false );

		printf(
			' <a class="button" href="%s">%s</a> <button type="button" class="button" onclick="window.print()">%s</button>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'pclm_export_report',
							'cycle'  => $cycle,
						),
						admin_url( 'admin-post.php' )
					),
					'pclm_export_report'
				)
			),
			esc_html__( 'Download CSV', 'pause-cafe-live-menu' ),
			esc_html__( 'Print', 'pause-cafe-live-menu' )
		);

		echo '</form>';

		self::render_summary( $cycle );

		echo '</div>';
	}

	private static function render_summary( $cycle ) {
		$summary = PCLM_Orders::summary_for_cycle( $cycle );

		printf(
			'<h2 class="pclm-report-title">%s</h2><p class="description">%s</p>',
			esc_html( PCLM_Schedule::format_date( PCLM_Schedule::service_date_for_cycle( $cycle ), 'l j F Y' ) ),
			esc_html(
				sprintf(
					/* translators: %s: cutoff moment. */
					__( 'Ordering closed %s.', 'pause-cafe-live-menu' ),
					PCLM_Schedule::format_moment( PCLM_Schedule::cutoff_for_cycle( $cycle ) )
				)
			)
		);

		if ( ! $summary ) {
			echo '<p>' . esc_html__( 'No orders for this menu yet.', 'pause-cafe-live-menu' ) . '</p>';

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
				'<h3 class="pclm-report-location">%s <span class="pclm-report-count">%s</span></h3>',
				esc_html( $location ),
				esc_html(
					sprintf(
						/* translators: %d: number of meals. */
						_n( '%d meal', '%d meals', $location_total, 'pause-cafe-live-menu' ),
						$location_total
					)
				)
			);

			echo '<table class="widefat striped pclm-report"><thead><tr>';
			echo '<th>' . esc_html__( 'Dish', 'pause-cafe-live-menu' ) . '</th>';
			echo '<th class="pclm-report__qty">' . esc_html__( 'Qty', 'pause-cafe-live-menu' ) . '</th>';
			echo '<th>' . esc_html__( 'Ordered by', 'pause-cafe-live-menu' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $dishes as $dish_name => $dish ) {
				sort( $dish['people'] );

				printf(
					'<tr><td><strong>%s</strong></td><td class="pclm-report__qty">%d</td><td>%s</td></tr>',
					esc_html( $dish_name ),
					(int) $dish['qty'],
					esc_html( implode( ', ', $dish['people'] ) )
				);
			}

			echo '</tbody></table>';
		}

		printf(
			'<p class="pclm-report-total"><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: %d: total number of meals. */
					_n( '%d meal in total', '%d meals in total', $grand_total, 'pause-cafe-live-menu' ),
					$grand_total
				)
			)
		);
	}

	public static function handle_export() {
		if ( ! current_user_can( PCLM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export orders.', 'pause-cafe-live-menu' ) );
		}

		check_admin_referer( 'pclm_export_report' );

		$cycle = isset( $_GET['cycle'] ) ? sanitize_text_field( wp_unslash( $_GET['cycle'] ) ) : '';

		if ( ! in_array( $cycle, PCLM_Schedule::all_cycles(), true ) ) {
			wp_die( esc_html__( 'Unknown menu cycle.', 'pause-cafe-live-menu' ) );
		}

		$service = PCLM_Schedule::service_date_for_cycle( $cycle );
		$rows    = PCLM_Orders::line_items_for_cycle( $cycle );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=pause-cafe-' . $service . '.csv' );

		$out = fopen( 'php://output', 'w' );

		// Excel needs the BOM to read the Chinese dish names correctly.
		fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv(
			$out,
			array(
				__( 'Service date', 'pause-cafe-live-menu' ),
				__( 'Location', 'pause-cafe-live-menu' ),
				__( 'Dish', 'pause-cafe-live-menu' ),
				__( 'Quantity', 'pause-cafe-live-menu' ),
				__( 'Ordered by', 'pause-cafe-live-menu' ),
				__( 'Order', 'pause-cafe-live-menu' ),
				__( 'Status', 'pause-cafe-live-menu' ),
			)
		);

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$service,
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

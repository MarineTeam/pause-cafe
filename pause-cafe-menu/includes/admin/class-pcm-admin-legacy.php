<?php
/**
 * One-time cleanup for menu items created before this plugin existed.
 *
 * Those products are still published and still purchasable, so an old bookmark
 * lands on a buyable dish from a week that has long passed. Because payment
 * comes out of a wallet balance, such an order can complete and then never
 * appear on any cook list.
 *
 * Nothing is selected by default and nothing is deleted -- items are moved to
 * draft, which keeps every past order's history intact.
 */

defined( 'ABSPATH' ) || exit;

class PCM_Admin_Legacy {

	public static function init() {
		add_action( 'admin_post_pcm_archive_legacy', array( __CLASS__, 'handle_archive' ) );
	}

	public static function render() {
		$ids = PCM_Product::undated_legacy_ids();

		echo '<div class="wrap pcm-wrap">';
		echo '<h1>' . esc_html__( 'Legacy items', 'pause-cafe-menu' ) . '</h1>';

		PCM_Admin::print_notices();

		if ( ! $ids ) {
			echo '<p>' . esc_html__( 'Nothing to clean up. Every published product either carries a service date or sits outside the pickup categories.', 'pause-cafe-menu' ) . '</p></div>';

			return;
		}

		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of products. */
					_n(
						'%d published product has no service date. Until it is archived or dated, it can still be ordered through a direct link.',
						'%d published products have no service date. Until they are archived or dated, they can still be ordered through a direct link.',
						count( $ids ),
						'pause-cafe-menu'
					),
					count( $ids )
				)
			)
		);

		echo '<p>' . esc_html__( 'Check anything that is an old weekly dish and archive it. Leave drinks, desserts and special orders alone — those are meant to stay on sale.', 'pause-cafe-menu' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pcm_archive_legacy' );
		echo '<input type="hidden" name="action" value="pcm_archive_legacy">';

		echo '<table class="widefat striped pcm-legacy"><thead><tr>';
		echo '<td class="check-column"><input type="checkbox" id="pcm-check-all"></td>';
		echo '<th>' . esc_html__( 'Product', 'pause-cafe-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Categories', 'pause-cafe-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Price', 'pause-cafe-menu' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $ids as $product_id ) {
			$product    = wc_get_product( $product_id );
			$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );

			printf(
				'<tr><th scope="row" class="check-column"><input type="checkbox" name="archive[]" value="%1$d"></th>
				<td><a href="%2$s">%3$s</a></td><td>%4$s</td><td>%5$s</td></tr>',
				(int) $product_id,
				esc_url( get_edit_post_link( $product_id ) ),
				esc_html( get_the_title( $product_id ) ),
				esc_html( is_wp_error( $categories ) || ! $categories ? __( 'Uncategorized', 'pause-cafe-menu' ) : implode( ', ', $categories ) ),
				wp_kses_post( $product ? $product->get_price_html() : '' )
			);
		}

		echo '</tbody></table>';

		submit_button( __( 'Move selected to draft', 'pause-cafe-menu' ), 'primary', 'submit', true );
		echo '</form>';

		?>
		<script>
		document.getElementById( 'pcm-check-all' ).addEventListener( 'change', function ( event ) {
			document.querySelectorAll( 'input[name="archive[]"]' ).forEach( function ( box ) {
				box.checked = event.target.checked;
			} );
		} );
		</script>
		<?php

		echo '</div>';
	}

	public static function handle_archive() {
		if ( ! current_user_can( PCM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to archive products.', 'pause-cafe-menu' ) );
		}

		check_admin_referer( 'pcm_archive_legacy' );

		$ids       = isset( $_POST['archive'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['archive'] ) ) : array();
		$permitted = PCM_Product::undated_legacy_ids();
		$archived  = 0;

		foreach ( $ids as $product_id ) {
			// Only ever touch products this screen actually offered.
			if ( ! in_array( $product_id, $permitted, true ) ) {
				continue;
			}

			wp_update_post(
				array(
					'ID'          => $product_id,
					'post_status' => 'draft',
				)
			);

			++$archived;
		}

		PCM_Visibility::flush();

		PCM_Admin::add_notice(
			sprintf(
				/* translators: %d: number of products moved to draft. */
				_n( '%d product moved to draft.', '%d products moved to draft.', $archived, 'pause-cafe-menu' ),
				$archived
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . PCM_Admin::PAGE_LEGACY ) );
		exit;
	}
}

<?php
use PauseCafe\Schedule;
use PauseCafe\Settings;

// The dish itself, and everything it needs to know about ordering, lives in
// partials/dish-card.php -- which is the piece a theme replaces.
$multiple = count( $sections ) > 1;
$anything = false;

foreach ( $sections as $section ) {
	if ( $section['blocks'] || '' !== $section['blackout'] ) {
		$anything = true;
		break;
	}
}
?>

<div class="menu-head">
	<h1><?= e( Settings::get( 'menu_heading' ) ) ?></h1>
</div>

<?php if ( ! $anything ) : ?>

	<div class="panel" style="text-align:center">
		<p class="muted">No menu has been published yet. Please check back soon.</p>
	</div>

<?php else : ?>

	<?php foreach ( $sections as $section ) : ?>
		<?php
		if ( ! $section['blocks'] && '' === $section['blackout'] ) {
			// Nothing published on this schedule yet; with another one showing,
			// an empty heading is just noise.
			continue;
		}
		?>

		<section class="menu-section">
			<?php
			/*
			 * The state of the week, said once at the top rather than repeated
			 * on every card. A visitor should be able to tell whether they can
			 * order without reading a dish.
			 */
			$window = $section['window'] ?? null;
			$state  = $window ? $window->state() : '';
			?>

			<div class="week">
				<?php if ( $section['serviceDate'] ) : ?>
					<p class="week__date"><?= e( Schedule::formatDate( $section['serviceDate'], 'l j F' ) ) ?></p>
				<?php endif; ?>

				<?php if ( $multiple ) : ?>
					<h2 class="week__name"><?= e( $section['rules']['name'] ) ?></h2>
				<?php endif; ?>

				<?php if ( '' !== $section['blackout'] ) : ?>
					<span class="pill pill--blackout"><?= e( $section['blackout'] ) ?></span>
				<?php elseif ( $window && $window->isOrderable() ) : ?>
					<span class="pill pill--open"><?= e( $window->message() ) ?></span>
				<?php elseif ( $window && '' !== $window->message() ) : ?>
					<span class="pill pill--<?= 'upcoming' === $state ? 'upcoming' : 'closed' ?>">
						<?= e( $window->message() ) ?>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $section['blackout'] ) : ?>

				<div class="panel" style="text-align:center">
					<h3><?= e( $section['blackout'] ) ?></h3>
					<p class="muted">
						There is no lunch on <?= e( Schedule::formatDate( $section['serviceDate'], 'j F' ) ) ?>.
					</p>
				</div>

			<?php else : ?>

				<?php
				/*
				 * One grid for the whole week rather than one per pickup location.
				 * A location with a single dish used to get its own grid, so every
				 * card sat alone in column one and nothing was ever side by side.
				 * The location is a label on the card instead.
				 */
				$dishes = array();

				foreach ( $section['blocks'] as $block ) {
					foreach ( $block['items'] as $item ) {
						$dishes[] = array(
							'item'     => $item,
							'location' => $block['location']['name'],
						);
					}
				}
				?>

				<ul class="dishes" style="--cols: <?= (int) $columns ?>">
					<?php foreach ( $dishes as $entry ) : ?>
						<?php
						$dc = array(
							'item'     => $entry['item'],
							'location' => $entry['location'],
						);

						include \PauseCafe\View::locate( 'partials/dish-card' );
						?>
					<?php endforeach; ?>
				</ul>

			<?php endif; ?>
		</section>
	<?php endforeach; ?>

<?php endif; ?>

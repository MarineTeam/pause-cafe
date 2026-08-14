<?php
/**
 * A menu of the sections on a long screen.
 *
 * Callers set $jump before including, as anchor id => label:
 *
 *   $jump = array( 'email' => 'Email', 'groups' => 'Groups' );
 *   include \PauseCafe\View::locate( 'partials/jump-links' );
 *
 * Plain anchors, no script. They work with the keyboard, they can be opened in
 * a new tab, and the browser's own back button undoes a jump — none of which is
 * true of a scroll handler.
 */

$jump = $jump ?? array();

if ( $jump ) :
	?>
	<nav class="jump no-print" aria-label="Sections on this page">
		<span class="jump__label">Jump to</span>
		<ul class="jump__list">
			<?php foreach ( $jump as $jumpId => $jumpLabel ) : ?>
				<li><a href="#<?= e( $jumpId ) ?>"><?= e( $jumpLabel ) ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<?php
endif;

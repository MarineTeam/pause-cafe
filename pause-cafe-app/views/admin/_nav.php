<?php
/**
 * The organiser navigation, in whichever direction this organiser chose.
 *
 * Rendered by the layout rather than included by each screen, because in the
 * side arrangement it has to sit beside the page content rather than above it —
 * something a template included at the top of the content cannot do.
 */

use PauseCafe\AdminNav;
use PauseCafe\Csrf;

$navStyle   = AdminNav::style();
$navCurrent = AdminNav::currentFor( $_SERVER['REQUEST_URI'] ?? '/admin' );
$navOther   = AdminNav::SIDE === $navStyle ? AdminNav::TOP : AdminNav::SIDE;
?>

<?php
/*
 * On a phone the side arrangement becomes a drawer rather than folding into a
 * row across the top -- folding it there contradicts the setting, and ten links
 * wrapped over three rows pushed the actual page down by a third of the screen.
 *
 * A checkbox and a label do the opening, so it works with no JavaScript and the
 * keyboard gets it for free. Both are hidden entirely on wider screens, where
 * the menu is simply always there.
 */
?>
<?php if ( AdminNav::SIDE === $navStyle ) : ?>
	<input type="checkbox" id="admin-drawer" class="admin-drawer__toggle no-print">
	<label for="admin-drawer" class="admin-drawer__open no-print">
		<span aria-hidden="true">☰</span> Organiser menu
	</label>
	<label for="admin-drawer" class="admin-drawer__scrim no-print"></label>
<?php endif; ?>

<nav class="admin-nav admin-nav--<?= e( $navStyle ) ?> no-print" aria-label="Organiser">
	<?php if ( AdminNav::SIDE === $navStyle ) : ?>
		<label for="admin-drawer" class="admin-drawer__close">Close</label>
	<?php endif; ?>

	<ul class="admin-nav__list">
		<?php foreach ( AdminNav::items() as $href => $label ) : ?>
			<li>
				<a href="<?= e( $href ) ?>" class="<?= $href === $navCurrent ? 'is-active' : '' ?>"
					<?= $href === $navCurrent ? 'aria-current="page"' : '' ?>><?= e( $label ) ?></a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php // One click, where somebody would look for it, rather than buried in settings. ?>
	<form method="post" action="/admin/nav" class="admin-nav__flip">
		<?= Csrf::field() ?>
		<input type="hidden" name="style" value="<?= e( $navOther ) ?>">
		<button type="submit" class="link-button">
			<?= AdminNav::SIDE === $navStyle ? 'Menu across the top' : 'Menu down the side' ?>
		</button>
	</form>
</nav>

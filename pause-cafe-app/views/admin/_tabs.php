<?php
$current = parse_url( $_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH ) ?: '/admin';

$tabs = array(
	'/admin'          => 'Overview',
	'/admin/menu'     => 'Menu',
	'/admin/schedules' => 'Schedules',
	'/admin/orders'   => 'Orders',
	'/kitchen'        => 'Kitchen list',
	'/admin/users'    => 'People',
	'/admin/settings' => 'Settings',
);
?>
<nav class="admin-tabs no-print">
	<?php foreach ( $tabs as $href => $label ) : ?>
		<?php $active = ( '/admin' === $href ) ? ( '/admin' === $current ) : str_starts_with( $current, $href ); ?>
		<a href="<?= e( $href ) ?>" class="<?= $active ? 'is-active' : '' ?>"><?= e( $label ) ?></a>
	<?php endforeach; ?>
</nav>

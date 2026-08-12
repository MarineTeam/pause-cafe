<?php
use PauseCafe\Csrf;
use PauseCafe\Money;
use PauseCafe\Schedule;
use PauseCafe\Window;

include __DIR__ . '/_tabs.php';

$labels = array(
	Window::OPEN     => 'Ordering open',
	Window::UPCOMING => 'Upcoming',
	Window::CLOSED   => 'Closed',
	Window::PAST     => 'Served',
	Window::BLACKOUT => 'Blackout',
	Window::NONE     => 'Not scheduled',
);
?>

<h1>Menu</h1>

<p class="muted">
	Mode: <?= e( Schedule::modes()[ $mode ] ?? $mode ) ?>
</p>

<p class="no-print"><a class="button" href="/admin/menu/new">Add a dish</a></p>

<?php if ( ! $items ) : ?>
	<p class="muted">No dishes yet.</p>
<?php else : ?>
	<div class="table-scroll">
		<table>
			<thead>
				<tr>
					<th>Dish</th>
					<th>Pickup</th>
					<th class="num">Price</th>
					<th>Serving</th>
					<th>Ordering</th>
					<th class="num">Sold</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $item ) : ?>
					<?php $state = $item['window']->state(); ?>
					<tr>
						<td>
							<a href="/admin/menu/<?= (int) $item['id'] ?>"><strong><?= e( $item['name'] ) ?></strong></a>
							<?php if ( 'draft' === $item['status'] ) : ?>
								<span class="pill pill--past">Draft</span>
							<?php endif; ?>
						</td>
						<td><?= e( $item['location_name'] ) ?></td>
						<td class="num"><?= e( Money::format( (int) $item['price_cents'] ) ) ?></td>
						<td>
							<?= $item['window']->serviceDate
								? e( Schedule::formatDate( $item['window']->serviceDate, 'j M Y' ) )
								: '<span class="muted">—</span>' ?>
						</td>
						<td>
							<span class="pill pill--<?= e( $state ) ?>"><?= e( $labels[ $state ] ?? $state ) ?></span>
							<br><span class="muted" style="font-size:13px"><?= e( $item['window']->message() ) ?></span>
						</td>
						<td class="num">
							<?= (int) $item['sold'] ?><?= $item['capacity'] > 0 ? ' / ' . (int) $item['capacity'] : '' ?>
						</td>
						<td class="no-print">
							<?php if ( Schedule::MODE_ON_PUBLISH === $mode ) : ?>
								<form method="post" action="/admin/menu/<?= (int) $item['id'] ?>/publish">
									<?= Csrf::field() ?>
									<button type="submit" class="link-button">Open ordering now</button>
								</form>
							<?php endif; ?>

							<form method="post" action="/admin/menu/<?= (int) $item['id'] ?>/delete"
								onsubmit="return confirm('Delete this dish?')">
								<?= Csrf::field() ?>
								<button type="submit" class="link-button">Delete</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>

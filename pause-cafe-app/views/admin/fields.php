<?php
use PauseCafe\Csrf;
use PauseCafe\MenuFields;

include __DIR__ . '/_tabs.php';

/**
 * The editor body, shared by each existing field and the add form.
 */
$form = static function ( ?array $field ): void {
	$isBuiltin = $field && $field['builtin'];
	?>
	<div class="field-row">
		<div>
			<label>Label</label>
			<input type="text" name="label" value="<?= e( $field['label'] ?? '' ) ?>" required>
		</div>

		<div>
			<label>Type</label>
			<?php if ( $isBuiltin ) : ?>
				<input type="text" value="<?= e( MenuFields::types()[ $field['type'] ] ?? $field['type'] ) ?>" disabled>
				<p class="help">Fixed — the kitchen list reads this one by name.</p>
			<?php else : ?>
				<select name="type">
					<?php foreach ( MenuFields::types() as $value => $label ) : ?>
						<option value="<?= e( $value ) ?>" <?= ( $field['type'] ?? 'text' ) === $value ? 'selected' : '' ?>>
							<?= e( $label ) ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
		</div>

		<div>
			<label>Placeholder</label>
			<input type="text" name="placeholder" value="<?= e( $field['placeholder'] ?? '' ) ?>">
		</div>

		<div>
			<label>Order</label>
			<input type="number" name="sort_order" value="<?= (int) ( $field['sort_order'] ?? 0 ) ?>">
		</div>
	</div>

	<div class="field">
		<label>Choices</label>
		<textarea name="options" rows="3"
			placeholder="One per line. Only used by &quot;choose from a list&quot;."><?= e( implode( "\n", $field['options'] ?? array() ) ) ?></textarea>
	</div>

	<div class="field">
		<label>
			<input type="checkbox" name="is_shown" value="1" <?= ( $field['shown'] ?? true ) ? 'checked' : '' ?>>
			Ask for this by default
		</label>
	</div>

	<div class="field">
		<label>
			<input type="checkbox" name="is_required" value="1" <?= ( $field['required'] ?? false ) ? 'checked' : '' ?>>
			Required by default
		</label>
	</div>
	<?php
};
?>

<h1>Order fields</h1>

<p class="muted">
	The questions asked about each meal. What is set here is the site-wide
	default; a <a href="/admin/schedules">schedule</a> or a single dish can
	override any of them.
</p>

<p class="muted">
	<strong>Name, group and note are built in</strong> and cannot be removed —
	the kitchen list, the CSV export and the order emails read them by name. They
	can be set to "do not ask" anywhere, which hides them without breaking those.
</p>

<?php foreach ( $fields as $key => $field ) : ?>
	<div class="panel">
		<h2>
			<?= e( $field['label'] ) ?>
			<?php if ( $field['builtin'] ) : ?>
				<span class="pill pill--past">Built in</span>
			<?php endif; ?>
			<?php if ( ! $field['shown'] ) : ?>
				<span class="pill pill--closed">Not asked</span>
			<?php elseif ( $field['required'] ) : ?>
				<span class="pill pill--open">Required</span>
			<?php endif; ?>
		</h2>

		<p class="muted">Stored as <code><?= e( $key ) ?></code>.</p>

		<details>
			<summary>Edit</summary>

			<form method="post" action="/admin/fields/save" style="margin-top:14px">
				<?= Csrf::field() ?>
				<input type="hidden" name="id" value="<?= (int) $field['id'] ?>">
				<?php $form( $field ); ?>
				<button type="submit">Save field</button>
			</form>

			<?php if ( ! $field['builtin'] ) : ?>
				<form method="post" action="/admin/fields/<?= (int) $field['id'] ?>/delete" style="margin-top:12px"
					onsubmit="return confirm('Remove this field? Answers already given stay on those orders.')">
					<?= Csrf::field() ?>
					<button type="submit" class="link-button">Remove field</button>
				</form>
			<?php endif; ?>
		</details>
	</div>
<?php endforeach; ?>

<div class="panel">
	<h2>Add a field</h2>

	<form method="post" action="/admin/fields/save">
		<?= Csrf::field() ?>
		<?php $form( null ); ?>
		<button type="submit">Create field</button>
	</form>
</div>

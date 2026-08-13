<?php
/**
 * The per-field override control, used on schedules and on dishes.
 *
 * Callers set $fr before including:
 *   rules  decoded overrides at this level (not the resolved result)
 *   note   one line of context under the heading
 */

use PauseCafe\MenuFields;

$fr = array_merge(
	array(
		'rules' => array(),
		'note'  => '',
	),
	$fr ?? array()
);
?>

<h3>Questions asked when ordering</h3>

<?php if ( '' !== $fr['note'] ) : ?>
	<p class="muted"><?= e( $fr['note'] ) ?></p>
<?php endif; ?>

<div class="field-row">
	<?php foreach ( MenuFields::definitions() as $frKey => $frField ) : ?>
		<div>
			<label for="rule-<?= e( $frKey ) ?>">
				<?= e( $frField['label'] ) ?>
				<?php if ( $frField['builtin'] ) : ?>
					<span class="pill pill--past">Built in</span>
				<?php endif; ?>
			</label>

			<select id="rule-<?= e( $frKey ) ?>" name="rule[<?= e( $frKey ) ?>]">
				<?php foreach ( MenuFields::ruleChoices() as $frValue => $frLabel ) : ?>
					<option value="<?= e( $frValue ) ?>"
						<?= $frValue === MenuFields::currentChoice( $fr['rules'], $frKey ) ? 'selected' : '' ?>>
						<?= e( $frLabel ) ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
	<?php endforeach; ?>
</div>

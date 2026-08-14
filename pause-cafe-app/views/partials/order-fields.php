<?php
/**
 * Renders the questions asked about one meal.
 *
 * Callers set $of before including:
 *   fields  array from MenuFields::visibleFor(), or a subset of it
 *   values  current answers, keyed by field key
 *   prefix  unique string for input ids, since a page shows many of these
 *   labels  false to render bare controls for a compact row
 *
 * Field keys are used verbatim as input names, which is what lets
 * MenuFields::collect() read them back without knowing what they are.
 */

use PauseCafe\Groups;

$of = array_merge(
	array(
		'fields' => array(),
		'values' => array(),
		'prefix' => 'f',
		'labels' => true,
	),
	$of ?? array()
);

foreach ( $of['fields'] as $ofKey => $ofField ) :
	$ofId    = $of['prefix'] . '-' . $ofKey;
	$ofValue = (string) ( $of['values'][ $ofKey ] ?? '' );
	$ofReq   = $ofField['required'] ? 'required' : '';
	?>
	<div class="field">
		<?php if ( $of['labels'] ) : ?>
			<label for="<?= e( $ofId ) ?>">
				<?= e( $ofField['label'] ) ?>
				<?php if ( ! $ofField['required'] ) : ?>
					<span class="muted">(optional)</span>
				<?php endif; ?>
			</label>
		<?php endif; ?>

		<?php if ( 'group' === $ofField['type'] ) : ?>

			<?php
			// Falls through to the managed group list, which renders nothing at
			// all when no groups have been set up.
			$gs = array(
				'name'     => $ofKey,
				'id'       => $ofId,
				'value'    => $ofValue,
				'label'    => '',
				'required' => (bool) $ofField['required'],
			);
			include \PauseCafe\View::locate( 'partials/group-select' );
			?>

		<?php elseif ( 'textarea' === $ofField['type'] ) : ?>

			<textarea id="<?= e( $ofId ) ?>" name="<?= e( $ofKey ) ?>" rows="2" maxlength="500"
				placeholder="<?= e( $ofField['placeholder'] ) ?>" <?= $ofReq ?>><?= e( $ofValue ) ?></textarea>

		<?php elseif ( 'select' === $ofField['type'] ) : ?>

			<select id="<?= e( $ofId ) ?>" name="<?= e( $ofKey ) ?>" <?= $ofReq ?>>
				<?php if ( ! $ofField['required'] ) : ?>
					<option value="">— None —</option>
				<?php endif; ?>
				<?php foreach ( $ofField['options'] as $ofOption ) : ?>
					<option value="<?= e( $ofOption ) ?>" <?= $ofOption === $ofValue ? 'selected' : '' ?>>
						<?= e( $ofOption ) ?>
					</option>
				<?php endforeach; ?>
			</select>

		<?php elseif ( 'checkbox' === $ofField['type'] ) : ?>

			<label style="font-weight:400">
				<input type="checkbox" id="<?= e( $ofId ) ?>" name="<?= e( $ofKey ) ?>" value="1"
					<?= '' !== $ofValue ? 'checked' : '' ?>>
				<?= e( $ofField['placeholder'] ?: 'Yes' ) ?>
			</label>

		<?php else : ?>

			<input type="text" id="<?= e( $ofId ) ?>" name="<?= e( $ofKey ) ?>" maxlength="200"
				value="<?= e( $ofValue ) ?>" placeholder="<?= e( $ofField['placeholder'] ) ?>" <?= $ofReq ?>>

		<?php endif; ?>
	</div>
	<?php
endforeach;

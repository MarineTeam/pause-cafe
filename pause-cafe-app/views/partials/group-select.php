<?php
/**
 * A group dropdown.
 *
 * Callers set $gs before including:
 *   name      form field name
 *   value     currently selected group, may be ''
 *   id        input id, for the label
 *   label     label text, or '' to render the select alone
 *   required  true to make a choice mandatory
 *
 * Renders nothing at all when no groups are configured, so the field simply
 * does not appear until an organiser has set some up.
 */

use PauseCafe\Groups;

$gs = array_merge(
	array(
		'name'     => 'group_name',
		'value'    => '',
		'id'       => 'group-' . substr( md5( (string) mt_rand() ), 0, 6 ),
		'label'    => 'Group',
		'required' => false,
	),
	$gs ?? array()
);

if ( ! Groups::any() ) {
	return;
}

$gsValue   = (string) $gs['value'];
$gsOrphan  = '' !== $gsValue && ! Groups::has( $gsValue );
?>
<?php if ( '' !== $gs['label'] ) : ?>
	<label for="<?= e( $gs['id'] ) ?>"><?= e( $gs['label'] ) ?></label>
<?php endif; ?>

<select id="<?= e( $gs['id'] ) ?>" name="<?= e( $gs['name'] ) ?>" <?= $gs['required'] ? 'required' : '' ?>>
	<?php if ( ! $gs['required'] ) : ?>
		<option value="">— No group —</option>
	<?php endif; ?>

	<?php foreach ( Groups::all() as $gsGroup ) : ?>
		<option value="<?= e( $gsGroup['name'] ) ?>" <?= 0 === strcasecmp( $gsGroup['name'], $gsValue ) ? 'selected' : '' ?>>
			<?= e( $gsGroup['name'] ) ?>
		</option>
	<?php endforeach; ?>

	<?php if ( $gsOrphan ) : ?>
		<option value="<?= e( $gsValue ) ?>" selected><?= e( $gsValue ) ?> (no longer listed)</option>
	<?php endif; ?>
</select>

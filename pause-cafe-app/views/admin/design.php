<?php
/**
 * How the site looks.
 *
 * Built from Design::grouped(), so a token added to that list appears here with
 * its own control and needs no change to this file.
 */

use PauseCafe\Csrf;
use PauseCafe\Design;
use PauseCafe\Settings;

?>

<h1>Design</h1>

<p class="muted">
	Colours, type and shape for the whole site — the menu, the cart and these
	organiser screens. Changes show up as soon as you save.
</p>

<div class="panel">
	<h3>Start from a look</h3>
	<p class="muted">Fills in everything below. You can change any of it afterwards.</p>

	<div class="field-row">
		<?php foreach ( $presets as $slug => $preset ) : ?>
			<div>
				<form method="post" action="/admin/design">
					<?= Csrf::field() ?>
					<input type="hidden" name="preset" value="<?= e( $slug ) ?>">
					<button type="submit" class="button button--quiet"><?= e( $preset['label'] ) ?></button>
				</form>
				<p class="help"><?= e( $preset['note'] ) ?></p>
			</div>
		<?php endforeach; ?>
	</div>
</div>

<form method="post" action="/admin/design">
	<?= Csrf::field() ?>

	<?php foreach ( $groups as $group => $tokens ) : ?>
		<div class="panel">
			<h3><?= e( $group ) ?></h3>

			<div class="field-row">
				<?php foreach ( $tokens as $key => $token ) : ?>
					<div>
						<label for="<?= e( $key ) ?>"><?= e( $token['label'] ) ?></label>

						<?php $value = Settings::get( $key, (string) $token['default'] ); ?>

						<?php if ( Design::TYPE_COLOR === $token['type'] ) : ?>

							<input type="color" id="<?= e( $key ) ?>" name="<?= e( $key ) ?>"
								value="<?= e( $value ) ?>" style="height:40px; padding:3px">

						<?php elseif ( Design::TYPE_SELECT === $token['type'] ) : ?>

							<select id="<?= e( $key ) ?>" name="<?= e( $key ) ?>">
								<?php foreach ( $token['options'] as $option => $optionLabel ) : ?>
									<option value="<?= e( $option ) ?>" <?= $option === $value ? 'selected' : '' ?>>
										<?= e( $optionLabel ) ?>
									</option>
								<?php endforeach; ?>
							</select>

						<?php elseif ( Design::TYPE_RANGE === $token['type'] ) : ?>

							<input type="number" id="<?= e( $key ) ?>" name="<?= e( $key ) ?>"
								value="<?= e( $value ) ?>"
								min="<?= e( $token['min'] ) ?>" max="<?= e( $token['max'] ) ?>" step="1">

						<?php elseif ( Design::TYPE_IMAGE === $token['type'] ) : ?>

							<input type="text" id="<?= e( $key ) ?>" name="<?= e( $key ) ?>"
								value="<?= e( $value ) ?>" placeholder="/assets/uploads/logo.png">

							<?php if ( '' !== $value ) : ?>
								<p class="help"><img src="<?= e( $value ) ?>" alt="" style="max-height:40px"></p>
							<?php endif; ?>

						<?php else : ?>

							<input type="text" id="<?= e( $key ) ?>" name="<?= e( $key ) ?>"
								value="<?= e( $value ) ?>" maxlength="80">

						<?php endif; ?>

						<?php if ( ! empty( $token['help'] ) ) : ?>
							<p class="help"><?= e( $token['help'] ) ?></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endforeach; ?>

	<div class="panel">
		<h3>Theme</h3>
		<p class="muted">
			A theme can replace whole pages, not just their colours. The settings
			above still apply on top of one.
		</p>

		<div class="field">
			<label for="design_theme">Active theme</label>
			<select id="design_theme" name="design_theme">
				<option value="">Built in</option>
				<?php foreach ( $themes as $slug => $theme ) : ?>
					<option value="<?= e( $slug ) ?>" <?= $slug === $active ? 'selected' : '' ?>>
						<?= e( $theme['name'] ) ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php if ( ! $themes ) : ?>
				<p class="help">
					No themes are installed. A theme is a folder under <code>themes/</code>
					containing a <code>theme.php</code>, and optionally a
					<code>style.css</code> and its own copies of any page it wants to change.
				</p>
			<?php else : ?>
				<p class="help">
					A theme keeps its own copy of any page it replaces, so it will not pick
					up later changes to that page. Everything it leaves alone stays current.
				</p>
			<?php endif; ?>
		</div>
	</div>

	<button type="submit">Save design</button>
</form>

<form method="post" action="/admin/design" style="margin-top:20px"
	onsubmit="return confirm('Put every design setting back to its default?')">
	<?= Csrf::field() ?>
	<input type="hidden" name="reset" value="1">
	<button type="submit" class="button button--quiet">Reset to defaults</button>
</form>

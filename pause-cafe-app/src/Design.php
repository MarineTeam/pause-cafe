<?php

namespace PauseCafe;

/**
 * How the site looks, as settings rather than as a stylesheet.
 *
 * Every token here names a CSS custom property that app.css already uses, so
 * changing one reaches the whole site — including admin screens nobody thought
 * about — without a line of CSS being written. That only holds while nothing in
 * app.css hardcodes a colour; see the note at the top of that file.
 *
 * css() emits *only* what differs from the default. A site nobody has themed
 * therefore ships no extra bytes, and a site that has changed one colour ships
 * one line. It also means the defaults live in one place: to restyle the app
 * for everybody, change the default here, not the stylesheet.
 */
class Design {

	public const TYPE_COLOR  = 'color';
	public const TYPE_TEXT   = 'text';
	public const TYPE_SELECT = 'select';
	public const TYPE_RANGE  = 'range';
	public const TYPE_IMAGE  = 'image';

	/** Font stacks offered by name, so no webfont is ever fetched. */
	private const FONTS = array(
		'sans'   => 'Inter, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Helvetica, Arial, sans-serif',
		'serif'  => 'Georgia, "Iowan Old Style", "Palatino Linotype", Palatino, serif',
		'system' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
		'mono'   => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
	);

	/**
	 * Every adjustable thing, keyed by the setting it is stored under.
	 *
	 * `css` is the custom property it writes; tokens without one (the brand
	 * name, the logo) are read by templates instead.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function tokens(): array {
		return array(
			'design_brand_name'   => array(
				'label'   => 'Site name',
				'group'   => 'Brand',
				'type'    => self::TYPE_TEXT,
				'default' => 'Pause Cafe',
				'css'     => '',
				'help'    => 'Shown in the header, the browser tab and outgoing email.',
			),
			'design_logo'         => array(
				'label'   => 'Logo',
				'group'   => 'Brand',
				'type'    => self::TYPE_IMAGE,
				'default' => '',
				'css'     => '',
				'help'    => 'Replaces the site name in the header. A wide image works best.',
			),
			'design_page_bg'      => array(
				'label'   => 'Page background',
				'group'   => 'Colour',
				'type'    => self::TYPE_COLOR,
				'default' => '#ffffff',
				'css'     => '--page-bg',
			),
			'design_card_bg'      => array(
				'label'   => 'Card background',
				'group'   => 'Colour',
				'type'    => self::TYPE_COLOR,
				'default' => '#f4f1ea',
				'css'     => '--card-bg',
			),
			'design_ink'          => array(
				'label'   => 'Text',
				'group'   => 'Colour',
				'type'    => self::TYPE_COLOR,
				'default' => '#111314',
				'css'     => '--ink',
			),
			'design_ink_soft'     => array(
				'label'   => 'Muted text',
				'group'   => 'Colour',
				'type'    => self::TYPE_COLOR,
				'default' => '#5f5e5a',
				'css'     => '--ink-soft',
			),
			'design_line'         => array(
				'label'   => 'Lines',
				'group'   => 'Colour',
				'type'    => self::TYPE_COLOR,
				'default' => '#e6e2d8',
				'css'     => '--line',
			),
			'design_button'       => array(
				'label'   => 'Buttons',
				'group'   => 'Colour',
				'type'    => self::TYPE_COLOR,
				'default' => '#0f6e56',
				'css'     => '--button',
			),
			'design_button_hover' => array(
				'label'   => 'Buttons, hovered',
				'group'   => 'Colour',
				'type'    => self::TYPE_COLOR,
				'default' => '#0b5442',
				'css'     => '--button-hover',
			),
			'design_font_body'    => array(
				'label'   => 'Body text',
				'group'   => 'Type',
				'type'    => self::TYPE_SELECT,
				'default' => 'sans',
				'css'     => '--font-body',
				'options' => array(
					'sans'   => 'Sans',
					'serif'  => 'Serif',
					'system' => 'Whatever the device uses',
					'mono'   => 'Monospace',
				),
			),
			'design_font_heading' => array(
				'label'   => 'Headings and dish names',
				'group'   => 'Type',
				'type'    => self::TYPE_SELECT,
				'default' => 'sans',
				'css'     => '--font-heading',
				'options' => array(
					'sans'   => 'Sans',
					'serif'  => 'Serif',
					'system' => 'Whatever the device uses',
					'mono'   => 'Monospace',
				),
			),
			'design_radius'       => array(
				'label'   => 'Corner rounding',
				'group'   => 'Shape',
				'type'    => self::TYPE_RANGE,
				'default' => '14',
				'css'     => '--radius',
				'min'     => '0',
				'max'     => '20',
				'suffix'  => 'px',
			),
			'design_pad_card'     => array(
				'label'   => 'Card padding',
				'group'   => 'Shape',
				'type'    => self::TYPE_RANGE,
				'default' => '18',
				'css'     => '--pad-card',
				'min'     => '10',
				'max'     => '40',
				'suffix'  => 'px',
			),
			'design_card_style'   => array(
				'label'   => 'Card edges',
				'group'   => 'Shape',
				'type'    => self::TYPE_SELECT,
				'default' => 'flat',
				'css'     => '--card-border',
				'options' => array(
					'border' => 'Outlined',
					'flat'   => 'No outline',
				),
			),
			'design_mode'         => array(
				'label'   => 'Dark mode',
				'group'   => 'Shape',
				'type'    => self::TYPE_SELECT,
				'default' => 'auto',
				'css'     => '',
				'options' => array(
					'auto' => 'Follow the visitor’s device',
					'off'  => 'Always light',
					'on'   => 'Always dark',
				),
				'help'    => 'Dark colours are built in and are not the ones chosen above.',
			),
		);
	}

	/**
	 * Named starting points. Applying one writes each of its tokens; it is not
	 * remembered, so an organiser is free to adjust afterwards.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function presets(): array {
		return array(
			'current' => array(
				'label'  => 'Plain',
				'note'   => 'White, square and charcoal. Matches the old WordPress site.',
				'values' => array(
					'design_page_bg'      => '#ffffff',
					'design_card_bg'      => '#ffffff',
					'design_ink'          => '#111314',
					'design_ink_soft'     => '#55595c',
					'design_line'         => '#e3e4e6',
					'design_button'       => '#333333',
					'design_button_hover' => '#111314',
					'design_font_heading' => 'sans',
					'design_font_body'    => 'sans',
					'design_radius'       => '0',
					'design_pad_card'     => '22',
					'design_card_style'   => 'border',
				),
			),
			'bold'    => array(
				'label'  => 'Bold',
				'note'   => 'Tinted cards with no outline, rounded corners, a green accent.',
				'values' => array(
					'design_page_bg'      => '#ffffff',
					'design_card_bg'      => '#f4f1ea',
					'design_ink'          => '#111314',
					'design_ink_soft'     => '#5f5e5a',
					'design_line'         => '#e6e2d8',
					'design_button'       => '#0f6e56',
					'design_button_hover' => '#0b5442',
					'design_font_heading' => 'sans',
					'design_font_body'    => 'sans',
					'design_radius'       => '14',
					'design_pad_card'     => '18',
					'design_card_style'   => 'flat',
				),
			),
			'warm'    => array(
				'label'  => 'Warm paper',
				'note'   => 'Off-white page, white cards, a serif for dish names.',
				'values' => array(
					'design_page_bg'      => '#faf8f4',
					'design_card_bg'      => '#ffffff',
					'design_ink'          => '#1c1a17',
					'design_ink_soft'     => '#6b655c',
					'design_line'         => '#e6e1d8',
					'design_button'       => '#7a4b2a',
					'design_button_hover' => '#5d3820',
					'design_font_heading' => 'serif',
					'design_font_body'    => 'sans',
					'design_radius'       => '10',
					'design_pad_card'     => '20',
					'design_card_style'   => 'border',
				),
			),
		);
	}

	public static function value( string $key ): string {
		$token = self::tokens()[ $key ] ?? null;

		if ( ! $token ) {
			return '';
		}

		return Settings::get( $key, (string) $token['default'] );
	}

	public static function brandName(): string {
		$name = trim( self::value( 'design_brand_name' ) );

		return '' !== $name ? $name : 'Pause Cafe';
	}

	public static function logo(): string {
		return trim( self::value( 'design_logo' ) );
	}

	/** off | auto | on — what to stamp on <html>, or nothing for auto. */
	public static function themeAttribute(): string {
		$mode = self::value( 'design_mode' );

		if ( 'off' === $mode ) {
			return 'light';
		}

		if ( 'on' === $mode ) {
			return 'dark';
		}

		// Auto: say nothing and let prefers-color-scheme decide.
		return '';
	}

	/**
	 * The inline stylesheet, or an empty string when nothing has been changed.
	 *
	 * Values are sanitised rather than trusted. Settings are only writable by
	 * an organiser, but this lands inside a <style> block, where a stray "}"
	 * would let the rest of the value become arbitrary CSS.
	 */
	public static function css(): string {
		$lines = array();

		foreach ( self::tokens() as $key => $token ) {
			if ( '' === $token['css'] ) {
				continue;
			}

			$value = Settings::get( $key, (string) $token['default'] );

			if ( $value === (string) $token['default'] ) {
				continue;
			}

			$rendered = self::render( $token, $value );

			if ( '' !== $rendered ) {
				$lines[] = "\t" . $token['css'] . ': ' . $rendered . ';';
			}
		}

		if ( ! $lines ) {
			return '';
		}

		return ":root {\n" . implode( "\n", $lines ) . "\n}";
	}

	/**
	 * Turns a stored value into something safe to put after a colon.
	 *
	 * Anything not recognised returns empty and is left out entirely, so a
	 * malformed setting means "unstyled", never "broken page".
	 */
	private static function render( array $token, string $value ): string {
		switch ( $token['type'] ) {
			case self::TYPE_COLOR:
				return preg_match( '/^#[0-9a-f]{3}$|^#[0-9a-f]{6}$/i', $value ) ? strtolower( $value ) : '';

			case self::TYPE_RANGE:
				if ( ! preg_match( '/^\d{1,3}$/', $value ) ) {
					return '';
				}

				$number = max( (int) $token['min'], min( (int) $token['max'], (int) $value ) );

				return $number . ( $token['suffix'] ?? '' );

			case self::TYPE_SELECT:
				if ( ! isset( $token['options'][ $value ] ) ) {
					return '';
				}

				// The two selects that write CSS write structural values, not
				// the option key.
				if ( '--font-body' === $token['css'] || '--font-heading' === $token['css'] ) {
					return self::FONTS[ $value ] ?? '';
				}

				if ( '--card-border' === $token['css'] ) {
					return 'flat' === $value ? '1px solid transparent' : '1px solid var(--line)';
				}

				return '';

			default:
				return '';
		}
	}

	/**
	 * Stores a submitted value, ignoring anything that does not validate.
	 *
	 * @return bool Whether it was kept.
	 */
	public static function set( string $key, string $value ): bool {
		$token = self::tokens()[ $key ] ?? null;

		if ( ! $token ) {
			return false;
		}

		$value = trim( $value );

		switch ( $token['type'] ) {
			case self::TYPE_COLOR:
				if ( ! preg_match( '/^#[0-9a-f]{3}$|^#[0-9a-f]{6}$/i', $value ) ) {
					return false;
				}

				$value = strtolower( $value );
				break;

			case self::TYPE_RANGE:
				if ( ! preg_match( '/^\d{1,3}$/', $value ) ) {
					return false;
				}

				$value = (string) max( (int) $token['min'], min( (int) $token['max'], (int) $value ) );
				break;

			case self::TYPE_SELECT:
				if ( ! isset( $token['options'][ $value ] ) ) {
					return false;
				}

				break;

			case self::TYPE_TEXT:
				// Reaches a <title> and an email subject; newlines have no
				// business in either.
				$value = trim( preg_replace( '/[\r\n]+/', ' ', $value ) ?? '' );
				$value = mb_substr( $value, 0, 80 );
				break;
		}

		Settings::set( $key, $value );

		return true;
	}

	/** Applies a preset. Unknown names change nothing. */
	public static function applyPreset( string $name ): bool {
		$preset = self::presets()[ $name ] ?? null;

		if ( ! $preset ) {
			return false;
		}

		foreach ( $preset['values'] as $key => $value ) {
			self::set( $key, (string) $value );
		}

		return true;
	}

	/** Puts every token back to its default. */
	public static function reset(): void {
		foreach ( self::tokens() as $key => $token ) {
			Settings::set( $key, (string) $token['default'] );
		}
	}

	/**
	 * @return array<string,array<string,array<string,mixed>>> Tokens by group.
	 */
	public static function grouped(): array {
		$groups = array();

		foreach ( self::tokens() as $key => $token ) {
			$groups[ $token['group'] ][ $key ] = $token;
		}

		return $groups;
	}
}

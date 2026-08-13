<?php

namespace PauseCafe;

/**
 * The questions asked about each meal.
 *
 * Three are built in — who it is for, their group, and a note. The kitchen list,
 * the CSV and the order emails read those by name, so they can be hidden but
 * never deleted. Anything else an organiser adds is stored as an answer against
 * the order line and shown alongside them.
 *
 * Whether a field appears is resolved in three steps, each overriding the last:
 *
 *   1. the field's own definition — the global setting
 *   2. the schedule the dish belongs to
 *   3. the dish itself
 *
 * A level that says nothing about a field inherits.
 */
class MenuFields {

	public const PERSON = 'person_name';
	public const GROUP  = 'group_name';
	public const NOTE   = 'note';

	/** Keys whose answers live in their own order_lines columns. */
	public const BUILTINS = array( self::PERSON, self::GROUP, self::NOTE );

	private static ?array $cache = null;

	/**
	 * @return array<string,string> Type => label for the editor.
	 */
	public static function types(): array {
		return array(
			'text'     => 'Single line of text',
			'textarea' => 'Longer text',
			'select'   => 'Choose from a list',
			'checkbox' => 'Yes or no',
			'group'    => 'The managed group list',
		);
	}

	public static function isBuiltin( string $key ): bool {
		return in_array( $key, self::BUILTINS, true );
	}

	public static function seedBuiltins(): void {
		$pdo    = Database::pdo();
		$insert = $pdo->prepare(
			'INSERT OR IGNORE INTO custom_fields
				(field_key, label, type, placeholder, is_builtin, is_shown, is_required, sort_order)
			 VALUES (?, ?, ?, ?, 1, 1, ?, ?)'
		);

		$insert->execute( array( self::PERSON, 'Name on this meal', 'text', '', 1, 0 ) );
		$insert->execute( array( self::GROUP, 'Group', 'group', '', 0, 1 ) );
		$insert->execute( array( self::NOTE, 'Note', 'text', 'e.g. no onions', 0, 2 ) );

		self::$cache = null;
	}

	/**
	 * Every field definition, built-ins first.
	 *
	 * @return array<string,array>
	 */
	public static function definitions(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$rows   = Database::pdo()
			->query( 'SELECT * FROM custom_fields ORDER BY is_builtin DESC, sort_order, id' )
			->fetchAll();
		$fields = array();

		foreach ( $rows as $row ) {
			$fields[ (string) $row['field_key'] ] = array(
				'id'          => (int) $row['id'],
				'key'         => (string) $row['field_key'],
				'label'       => (string) $row['label'],
				'type'        => (string) $row['type'],
				'options'     => self::splitOptions( (string) $row['options'] ),
				'placeholder' => (string) $row['placeholder'],
				'builtin'     => 1 === (int) $row['is_builtin'],
				'shown'       => 1 === (int) $row['is_shown'],
				'required'    => 1 === (int) $row['is_required'],
				'sort_order'  => (int) $row['sort_order'],
			);
		}

		self::$cache = $fields;

		return $fields;
	}

	/**
	 * @return string[]
	 */
	public static function splitOptions( string $raw ): array {
		$lines = preg_split( '/\r?\n/', $raw ) ?: array();

		return array_values( array_filter( array_map( 'trim', $lines ), static fn( $o ) => '' !== $o ) );
	}

	/**
	 * Decodes a field_rules JSON blob into overrides.
	 *
	 * @return array<string,array{shown?:bool,required?:bool}>
	 */
	public static function decodeRules( string $json ): array {
		if ( '' === trim( $json ) ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$rules = array();

		foreach ( $decoded as $key => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$entry = array();

			// Only keys actually present count as an override; anything else
			// inherits from the level above.
			if ( array_key_exists( 'shown', $rule ) ) {
				$entry['shown'] = (bool) $rule['shown'];
			}

			if ( array_key_exists( 'required', $rule ) ) {
				$entry['required'] = (bool) $rule['required'];
			}

			if ( $entry ) {
				$rules[ (string) $key ] = $entry;
			}
		}

		return $rules;
	}

	public static function encodeRules( array $rules ): string {
		return $rules ? (string) json_encode( $rules ) : '';
	}

	/**
	 * The fields to ask for on one dish, already resolved.
	 *
	 * @param array|null $item A menu_items row, or null for the bare global set.
	 *
	 * @return array<string,array> Definitions with final shown/required.
	 */
	public static function forItem( ?array $item = null ): array {
		$fields = self::definitions();

		$layers = array();

		if ( $item ) {
			// Both backings expose field_rules, so the default schedule and a
			// named one are read the same way.
			$rules = Schedules::rulesFor( $item['schedule_id'] ?? Schedules::DEFAULT_ID );

			$layers[] = self::decodeRules( (string) ( $rules['field_rules'] ?? '' ) );
			$layers[] = self::decodeRules( (string) ( $item['field_rules'] ?? '' ) );
		}

		foreach ( $layers as $layer ) {
			foreach ( $layer as $key => $rule ) {
				if ( ! isset( $fields[ $key ] ) ) {
					continue;
				}

				if ( array_key_exists( 'shown', $rule ) ) {
					$fields[ $key ]['shown'] = $rule['shown'];
				}

				if ( array_key_exists( 'required', $rule ) ) {
					$fields[ $key ]['required'] = $rule['required'];
				}
			}
		}

		return $fields;
	}

	/**
	 * Just the ones a customer will actually see.
	 *
	 * @return array<string,array>
	 */
	public static function visibleFor( ?array $item = null ): array {
		return array_filter( self::forItem( $item ), static fn( $field ) => $field['shown'] );
	}

	/**
	 * Cleans one submitted answer.
	 *
	 * A select only accepts what it offers and a group only accepts a managed
	 * group, so a hand-written POST cannot invent a value the reports would then
	 * have to display.
	 */
	public static function sanitiseValue( array $field, $raw ): string {
		$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';

		switch ( $field['type'] ) {
			case 'checkbox':
				return '' !== $value ? 'Yes' : '';

			case 'group':
				return Groups::sanitise( $value );

			case 'select':
				foreach ( $field['options'] as $option ) {
					if ( 0 === strcasecmp( $option, $value ) ) {
						return $option;
					}
				}

				return '';

			case 'textarea':
				return mb_substr( $value, 0, 500 );

			default:
				return mb_substr( $value, 0, 200 );
		}
	}

	/**
	 * The four states an override can be in.
	 *
	 * Show and require are one control rather than two, because the fourth
	 * combination -- hidden but required -- is not a thing anyone means.
	 *
	 * @return array<string,string>
	 */
	public static function ruleChoices(): array {
		return array(
			'inherit'  => 'Inherit',
			'hide'     => 'Do not ask',
			'optional' => 'Ask, optional',
			'required' => 'Ask, required',
		);
	}

	/**
	 * Which choice a stored override represents.
	 */
	public static function currentChoice( array $rules, string $key ): string {
		if ( ! isset( $rules[ $key ] ) ) {
			return 'inherit';
		}

		$rule = $rules[ $key ];

		if ( array_key_exists( 'shown', $rule ) && ! $rule['shown'] ) {
			return 'hide';
		}

		return ! empty( $rule['required'] ) ? 'required' : 'optional';
	}

	/**
	 * Builds a field_rules blob from a submitted rule[key] map.
	 */
	public static function rulesFromPost( array $post ): string {
		$submitted = (array) ( $post['rule'] ?? array() );
		$rules     = array();

		foreach ( self::definitions() as $key => $field ) {
			$choice = (string) ( $submitted[ $key ] ?? 'inherit' );

			switch ( $choice ) {
				case 'hide':
					$rules[ $key ] = array( 'shown' => false );
					break;

				case 'optional':
					$rules[ $key ] = array(
						'shown'    => true,
						'required' => false,
					);
					break;

				case 'required':
					$rules[ $key ] = array(
						'shown'    => true,
						'required' => true,
					);
					break;

				// inherit writes nothing, which is what makes it inherit.
			}
		}

		return self::encodeRules( $rules );
	}

	/**
	 * Reads and cleans every visible answer for a dish out of a submitted form.
	 *
	 * Only visible fields are read: a hidden field cannot be filled in by
	 * hand-posting it. Name and group fall back to the account when left blank,
	 * so ordering for yourself stays a single click.
	 *
	 * @return array<string,string>
	 */
	public static function collect( array $item, array $post, ?array $user = null ): array {
		$values = array();

		foreach ( self::visibleFor( $item ) as $key => $field ) {
			$value = self::sanitiseValue( $field, $post[ $key ] ?? '' );

			if ( '' === $value && $user ) {
				if ( self::PERSON === $key ) {
					$value = (string) ( $user['name'] ?? '' );
				} elseif ( self::GROUP === $key ) {
					$value = Groups::sanitise( (string) ( $user['group_name'] ?? '' ) );
				}
			}

			$values[ $key ] = $value;
		}

		return $values;
	}

	/**
	 * Labels of required fields left blank.
	 *
	 * @return string[]
	 */
	public static function missingRequired( array $item, array $values ): array {
		$missing = array();

		foreach ( self::visibleFor( $item ) as $key => $field ) {
			if ( $field['required'] && '' === (string) ( $values[ $key ] ?? '' ) ) {
				$missing[] = $field['label'];
			}
		}

		return $missing;
	}

	/**
	 * Extra answers rendered for a person to read.
	 *
	 * Labels come from the current definitions, falling back to the stored key
	 * so an answer to a since-deleted field still shows something.
	 */
	public static function describeExtras( $stored ): string {
		$values = is_array( $stored ) ? $stored : json_decode( (string) $stored, true );

		if ( ! is_array( $values ) || ! $values ) {
			return '';
		}

		$definitions = self::definitions();
		$parts       = array();

		foreach ( $values as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}

			$label   = $definitions[ $key ]['label'] ?? ucfirst( str_replace( '_', ' ', (string) $key ) );
			$parts[] = $label . ': ' . $value;
		}

		return implode( ' · ', $parts );
	}

	/**
	 * @return int The field's id.
	 * @throws \RuntimeException When the key clashes or is unusable.
	 */
	public static function save( array $data, ?int $id = null ): int {
		$existing = $id ? self::byId( $id ) : null;
		$builtin  = $existing && $existing['builtin'];

		/*
		 * The form posts field_key as an empty string rather than omitting it, so
		 * ?? is no help here -- it only falls back on null. Blank means "derive it
		 * from the label".
		 */
		$raw = trim( (string) ( $data['field_key'] ?? '' ) );

		if ( '' === $raw ) {
			$raw = (string) ( $data['label'] ?? '' );
		}

		$key = $builtin ? $existing['key'] : self::makeKey( $raw );

		if ( '' === $key ) {
			throw new \RuntimeException( 'Give the field a name.' );
		}

		$clash = self::definitions()[ $key ] ?? null;

		if ( $clash && ( ! $existing || $clash['id'] !== $existing['id'] ) ) {
			throw new \RuntimeException( 'There is already a field called that.' );
		}

		$fields = array(
			'label'       => trim( (string) ( $data['label'] ?? '' ) ) ?: $key,
			// A built-in's type is fixed: the columns and reports depend on it.
			'type'        => $builtin
				? $existing['type']
				: ( array_key_exists( (string) ( $data['type'] ?? '' ), self::types() ) ? (string) $data['type'] : 'text' ),
			'options'     => trim( (string) ( $data['options'] ?? '' ) ),
			'placeholder' => trim( (string) ( $data['placeholder'] ?? '' ) ),
			'is_shown'    => ! empty( $data['is_shown'] ) ? 1 : 0,
			'is_required' => ! empty( $data['is_required'] ) ? 1 : 0,
			'sort_order'  => (int) ( $data['sort_order'] ?? 0 ),
		);

		$pdo = Database::pdo();

		if ( $existing ) {
			$sets = array();

			foreach ( array_keys( $fields ) as $column ) {
				$sets[] = $column . ' = :' . $column;
			}

			$statement = $pdo->prepare( 'UPDATE custom_fields SET ' . implode( ', ', $sets ) . ' WHERE id = :id' );
			$statement->execute( array_merge( $fields, array( 'id' => $existing['id'] ) ) );

			self::$cache = null;

			return $existing['id'];
		}

		$fields['field_key']  = $key;
		$fields['is_builtin'] = 0;

		$columns      = array_keys( $fields );
		$placeholders = array_map( static fn( $c ) => ':' . $c, $columns );

		$statement = $pdo->prepare(
			'INSERT INTO custom_fields (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ')'
		);

		$statement->execute( $fields );

		self::$cache = null;

		return (int) $pdo->lastInsertId();
	}

	public static function byId( int $id ): ?array {
		foreach ( self::definitions() as $field ) {
			if ( $field['id'] === $id ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * @return bool False when the field is built in and was left alone.
	 */
	public static function delete( int $id ): bool {
		$field = self::byId( $id );

		if ( ! $field || $field['builtin'] ) {
			return false;
		}

		$statement = Database::pdo()->prepare( 'DELETE FROM custom_fields WHERE id = ? AND is_builtin = 0' );
		$statement->execute( array( $id ) );

		self::$cache = null;

		return true;
	}

	/**
	 * Turns a label into a storage key.
	 */
	public static function makeKey( string $raw ): string {
		$key = strtolower( trim( $raw ) );
		$key = preg_replace( '/[^a-z0-9]+/', '_', $key );
		$key = trim( (string) $key, '_' );

		// Never let a new field shadow a built-in column.
		if ( self::isBuiltin( $key ) ) {
			$key .= '_custom';
		}

		return mb_substr( $key, 0, 40 );
	}

	public static function flush(): void {
		self::$cache = null;
	}
}

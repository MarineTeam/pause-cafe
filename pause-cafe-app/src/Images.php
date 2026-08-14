<?php

namespace PauseCafe;

/**
 * Uploaded pictures of food.
 *
 * An upload form is the one place a visitor's bytes land on disk under a name
 * the server later hands back out, so everything here is arranged around not
 * trusting any of it:
 *
 *   - **The type comes from the bytes, never the name.** getimagesize() has to
 *     recognise the file as one of four formats; a PHP script called
 *     lunch.jpg fails that and is refused. The extension written to disk is
 *     derived from what was detected, so a file can never keep an extension it
 *     merely claimed.
 *
 *   - **The name is random.** The uploaded filename is discarded entirely,
 *     which disposes of directory traversal, overwriting somebody else's
 *     photo, and the various ways a name can be made to mean something to a
 *     web server.
 *
 *   - **Uploads are re-encoded.** GD reads the image and writes a new one, so
 *     whatever else was in the file -- a comment block with a script in it, the
 *     usual polyglot tricks -- does not survive the round trip. It also caps
 *     the dimensions, which is the difference between a 12 megapixel phone
 *     photo and something a congregation on mobile data can load.
 *
 * The directory it writes to also carries an .htaccess denying execution, for
 * the case where all of the above is somehow got past.
 */
class Images {

	/** Longest edge kept, in pixels. A menu card is never larger than this. */
	private const MAX_EDGE = 1200;

	/** Refused above this, before anything is decoded. */
	private const MAX_BYTES = 6 * 1024 * 1024;

	private static string $dir = '';

	private static string $url = '';

	public static function configure( string $dir, string $url ): void {
		self::$dir = rtrim( $dir, '/\\' );
		self::$url = rtrim( $url, '/' );
	}

	public static function isSupported(): bool {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'getimagesize' );
	}

	/**
	 * Accepts one uploaded file.
	 *
	 * @param array $file An entry from $_FILES.
	 *
	 * @return string The public URL of the stored image.
	 * @throws \RuntimeException With something an organiser can act on.
	 */
	public static function accept( array $file ): string {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );

		if ( UPLOAD_ERR_NO_FILE === $error ) {
			throw new \RuntimeException( 'No picture was chosen.' );
		}

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			throw new \RuntimeException( 'That picture is too large for this server to accept.' );
		}

		if ( UPLOAD_ERR_OK !== $error ) {
			throw new \RuntimeException( 'That picture did not upload properly. Please try again.' );
		}

		$temp = (string) ( $file['tmp_name'] ?? '' );

		/*
		 * Confirms the file really came through PHP's upload handling rather
		 * than being a path an attacker managed to get into the array.
		 */
		if ( '' === $temp || ! is_uploaded_file( $temp ) ) {
			throw new \RuntimeException( 'That upload could not be read.' );
		}

		if ( filesize( $temp ) > self::MAX_BYTES ) {
			throw new \RuntimeException( 'Pictures have to be under 6 MB. Try saving it smaller.' );
		}

		if ( ! self::isSupported() ) {
			throw new \RuntimeException( 'This server cannot process images, so pictures cannot be uploaded.' );
		}

		$info = @getimagesize( $temp );

		if ( ! is_array( $info ) ) {
			throw new \RuntimeException( 'That file is not a picture.' );
		}

		$readers = array(
			IMAGETYPE_JPEG => array( 'imagecreatefromjpeg', 'jpg' ),
			IMAGETYPE_PNG  => array( 'imagecreatefrompng', 'png' ),
			IMAGETYPE_GIF  => array( 'imagecreatefromgif', 'gif' ),
			IMAGETYPE_WEBP => array( 'imagecreatefromwebp', 'webp' ),
		);

		$type = (int) ( $info[2] ?? 0 );

		if ( ! isset( $readers[ $type ] ) || ! function_exists( $readers[ $type ][0] ) ) {
			throw new \RuntimeException( 'Pictures have to be JPEG, PNG, GIF or WebP.' );
		}

		list( $reader ) = $readers[ $type ];

		$source = @$reader( $temp );

		if ( ! $source ) {
			throw new \RuntimeException( 'That picture could not be read. It may be damaged.' );
		}

		$resized = self::scale( $source, (int) $info[0], (int) $info[1] );

		if ( '' === self::$dir ) {
			imagedestroy( $resized );

			throw new \RuntimeException( 'Uploads are not set up on this server.' );
		}

		if ( ! is_dir( self::$dir ) && ! mkdir( self::$dir, 0755, true ) && ! is_dir( self::$dir ) ) {
			imagedestroy( $resized );

			throw new \RuntimeException( 'The uploads folder could not be created.' );
		}

		self::protect();

		// Everything is re-encoded to JPEG except PNG, which is kept so a
		// transparent logo stays transparent.
		$keepPng = IMAGETYPE_PNG === $type;
		$name    = bin2hex( random_bytes( 12 ) ) . ( $keepPng ? '.png' : '.jpg' );
		$path    = self::$dir . '/' . $name;

		$written = $keepPng
			? imagepng( $resized, $path, 6 )
			: imagejpeg( $resized, $path, 82 );

		imagedestroy( $resized );

		if ( ! $written ) {
			throw new \RuntimeException( 'That picture could not be saved.' );
		}

		return self::$url . '/' . $name;
	}

	/**
	 * Returns a copy no larger than MAX_EDGE, or a straight copy when it
	 * already fits. Either way the result is freshly encoded.
	 *
	 * @param \GdImage $source
	 */
	private static function scale( $source, int $width, int $height ) {
		$longest = max( $width, $height );
		$scale   = $longest > self::MAX_EDGE ? self::MAX_EDGE / $longest : 1.0;

		$target = imagecreatetruecolor( max( 1, (int) round( $width * $scale ) ), max( 1, (int) round( $height * $scale ) ) );

		// Keeps PNG transparency through the copy instead of turning it black.
		imagealphablending( $target, false );
		imagesavealpha( $target, true );

		imagecopyresampled(
			$target,
			$source,
			0,
			0,
			0,
			0,
			imagesx( $target ),
			imagesy( $target ),
			$width,
			$height
		);

		imagedestroy( $source );

		return $target;
	}

	/**
	 * Deletes a stored image.
	 *
	 * Only touches files inside the uploads directory whose names look like the
	 * ones written above, so a stored value that has been tampered with cannot
	 * turn this into a way to delete anything else.
	 */
	public static function forget( string $url ): void {
		if ( '' === $url || '' === self::$dir ) {
			return;
		}

		$name = basename( $url );

		if ( ! preg_match( '/^[0-9a-f]{24}\.(jpg|png)$/', $name ) ) {
			return;
		}

		$path = self::$dir . '/' . $name;

		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * Drops an .htaccess into the uploads directory.
	 *
	 * Belt and braces: nothing that gets written there is executable as far as
	 * the checks above are concerned, but this is the directory where being
	 * wrong about that would matter most, and Apache is what DreamHost runs.
	 */
	private static function protect(): void {
		$file = self::$dir . '/.htaccess';

		if ( is_file( $file ) ) {
			return;
		}

		file_put_contents(
			$file,
			"# Pictures only. Nothing in here should ever be run.\n"
			. "php_flag engine off\n"
			. "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps\n"
			. "RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phps\n"
			. "<FilesMatch \"\\.(?i:php|phtml|phar|cgi|pl|py|sh)$\">\n"
			. "\tRequire all denied\n"
			. "</FilesMatch>\n"
		);
	}
}

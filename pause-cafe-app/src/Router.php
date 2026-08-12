<?php

namespace PauseCafe;

/**
 * A small router. Paths may contain {name} placeholders, which arrive as
 * arguments to the handler in the order they appear.
 */
class Router {

	private array $routes = array();

	public function get( string $path, callable $handler ): void {
		$this->add( 'GET', $path, $handler );
	}

	public function post( string $path, callable $handler ): void {
		$this->add( 'POST', $path, $handler );
	}

	private function add( string $method, string $path, callable $handler ): void {
		$pattern = preg_replace( '#\{[a-z_]+\}#i', '([^/]+)', $path );

		$this->routes[] = array(
			'method'  => $method,
			'pattern' => '#^' . $pattern . '$#',
			'handler' => $handler,
		);
	}

	/**
	 * @return bool False when nothing matched, so the caller can render a 404.
	 */
	public function dispatch( string $method, string $uri ): bool {
		$path = parse_url( $uri, PHP_URL_PATH ) ?: '/';
		$path = '/' . trim( $path, '/' );

		$pathMatched = false;

		foreach ( $this->routes as $route ) {
			if ( ! preg_match( $route['pattern'], $path, $matches ) ) {
				continue;
			}

			$pathMatched = true;

			if ( $route['method'] !== $method ) {
				continue;
			}

			array_shift( $matches );

			call_user_func_array( $route['handler'], $matches );

			return true;
		}

		// A path that exists but not for this verb is a 405, not a 404. Most
		// often it means a form posted to a GET-only route.
		if ( $pathMatched ) {
			http_response_code( 405 );
			echo 'Method not allowed.';

			return true;
		}

		return false;
	}
}

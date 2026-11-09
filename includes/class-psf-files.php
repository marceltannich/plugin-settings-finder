<?php
/**
 * Reads a plugin's own files.
 *
 * @package PluginSettingsFinder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded walks over a plugin directory: skips dependency and test folders,
 * large files, and stops after a file count and a time budget.
 */
final class PSF_Files {

	/**
	 * Folders never worth reading.
	 */
	const SKIP_DIRS = array( 'node_modules', '.git', 'tests', 'test', 'languages' );

	/**
	 * Files of a plugin matching a filter.
	 *
	 * @param array    $plugin     Plugin record (paths, is_dir).
	 * @param callable $accept     function ( SplFileInfo $file ): bool.
	 * @param string[] $extra_skip Additional folder names to skip.
	 * @param int      $limit      Maximum number of files.
	 * @return string[] Absolute paths.
	 */
	public static function find( array $plugin, $accept, array $extra_skip = array(), $limit = 1500 ) {
		$root = $plugin['paths'][0];
		if ( ! $plugin['is_dir'] ) {
			return is_file( $root ) && $accept( new SplFileInfo( $root ) ) ? array( $root ) : array();
		}
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$skip  = array_merge( self::SKIP_DIRS, $extra_skip );
		$files = array();
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
					static function ( $current ) use ( $skip, $accept ) {
						if ( $current->isDir() ) {
							return ! in_array( strtolower( $current->getFilename() ), $skip, true );
						}
						return $current->getSize() < 1048576 && $accept( $current );
					}
				)
			);
			foreach ( $iterator as $file ) {
				$files[] = $file->getPathname();
				if ( count( $files ) >= $limit ) {
					break;
				}
			}
		} catch ( Exception $e ) {
			unset( $e );
		}
		return $files;
	}

	/**
	 * PHP files of a plugin.
	 *
	 * @param array    $plugin     Plugin record.
	 * @param string[] $extra_skip Additional folder names to skip.
	 * @return string[]
	 */
	public static function php( array $plugin, array $extra_skip = array() ) {
		return self::find(
			$plugin,
			static function ( $file ) {
				return 'php' === strtolower( $file->getExtension() );
			},
			$extra_skip
		);
	}

	/**
	 * Read files one by one until a time budget runs out.
	 *
	 * @param string[] $files   Absolute paths.
	 * @param callable $reader  function ( string $code, string $file ): void.
	 * @param float    $seconds Time budget.
	 */
	public static function read_each( array $files, $reader, $seconds = 1.5 ) {
		$deadline = microtime( true ) + $seconds;
		foreach ( $files as $file ) {
			if ( microtime( true ) > $deadline ) {
				return;
			}
			$reader( self::read( $file ), $file );
		}
	}

	/**
	 * Contents of a local file, '' if unreadable.
	 *
	 * @param string $file   Absolute path.
	 * @param int    $length Maximum bytes, 0 for all.
	 * @return string
	 */
	public static function read( $file, $length = 0 ) {
		if ( ! is_readable( $file ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file, not a URL.
		$code = $length > 0 ? file_get_contents( $file, false, null, 0, $length ) : file_get_contents( $file );
		return false === $code ? '' : $code;
	}
}

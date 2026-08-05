<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PSR-4 autoloader for the EduCBTPro namespace.
 *
 * Maps EduCBTPro\ to the includes/ directory.
 * Safe to call register() multiple times — duplicate registrations are ignored.
 */
class Autoloader {

    /**
     * Whether the autoloader has been registered.
     *
     * @var bool
     */
    private static bool $registered = false;

    /**
     * Register the SPL autoloader.
     */
    public static function register(): void {
        if ( self::$registered ) {
            return;
        }

        spl_autoload_register( [ __CLASS__, 'autoload' ] );
        self::$registered = true;
    }

    /**
     * Autoload a class file based on the PSR-4 mapping.
     *
     * Autoloader.php lives at includes/Core/Autoloader.php, so one dirname()
     * call gets us to includes/ which is the PSR-4 base for EduCBTPro\.
     *
     * @param string $class The fully-qualified class name.
     */
    public static function autoload( string $class ): void {
        $prefix   = 'EduCBTPro\\';
        $base_dir = dirname( __DIR__ ) . DIRECTORY_SEPARATOR;

        $len = strlen( $prefix );
        if ( strncmp( $prefix, $class, $len ) !== 0 ) {
            return;
        }

        $relative_class = substr( $class, $len );
        $file           = $base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}

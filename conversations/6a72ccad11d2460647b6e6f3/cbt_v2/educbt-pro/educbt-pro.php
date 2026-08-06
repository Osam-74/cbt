<?php
/**
 * Plugin Name: EduCBT Pro
 * Plugin URI:  https://deodevs.com/educbt-pro
 * Description: Enterprise Multi-School CBT, School Management, Results & Academic Intelligence Platform for WordPress.
 * Version:     3.4.0
 * Author:      DeoDevs Team
 * Author URI:  https://deodevs.com
 * Text Domain: educbt-pro
 * Domain Path: /languages
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * License:     GPLv2 or later
 *
 * @package EduCBTPro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Prevent fatal "Cannot redeclare" errors if multiple copies of the plugin exist.
//
// The guard stops the crash, but whichever copy loads FIRST wins and owns every
// path — so a stale duplicate silently takes over and the newly uploaded files are
// never executed. That is far more confusing than a crash, so say so out loud.
if ( defined( 'EDUCBT_PRO_LOADED' ) ) {
    if ( ! function_exists( 'educbt_pro_duplicate_notice' ) ) {
        $educbt_pro_duplicate_paths   = (array) get_option( 'educbt_pro_duplicate_paths', [] );
        $educbt_pro_duplicate_paths[] = __FILE__;

        update_option( 'educbt_pro_duplicate_paths', array_values( array_unique( $educbt_pro_duplicate_paths ) ), false );

        function educbt_pro_duplicate_notice() {
            if ( ! current_user_can( 'activate_plugins' ) ) {
                return;
            }

            $active = defined( 'EDUCBT_PRO_FILE' ) ? EDUCBT_PRO_FILE : '(unknown)';
            $extras = (array) get_option( 'educbt_pro_duplicate_paths', [] );

            echo '<div class="notice notice-error"><p><strong>EduCBT Pro is installed more than once.</strong></p>';
            echo '<p>WordPress is running this copy:<br><code>' . esc_html( $active ) . '</code></p>';
            echo '<p>These extra copies are being ignored and should be deleted:</p><ul>';

            foreach ( $extras as $path ) {
                echo '<li><code>' . esc_html( (string) $path ) . '</code></li>';
            }

            echo '</ul><p>Until the duplicates are removed, uploading an update may appear to do nothing.</p></div>';
        }

        add_action( 'admin_notices', 'educbt_pro_duplicate_notice' );
    }

    return;
}

delete_option( 'educbt_pro_duplicate_paths' );
define( 'EDUCBT_PRO_LOADED', true );

define( 'EDUCBT_PRO_FILE', __FILE__ );
define( 'EDUCBT_PRO_DIR', plugin_dir_path( __FILE__ ) );

// Aliases used by the Phase 10 templates. PATH mirrors DIR; URL is needed to
// enqueue the portal stylesheet.
define( 'EDUCBT_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'EDUCBT_PRO_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook( EDUCBT_PRO_FILE, 'educbt_pro_activate_plugin' );

if ( ! function_exists( 'educbt_pro_is_php_compatible' ) ) {
    function educbt_pro_is_php_compatible(): bool {
        return version_compare( PHP_VERSION, '8.2.0', '>=' );
    }
}

if ( ! function_exists( 'educbt_pro_is_wp_compatible' ) ) {
    function educbt_pro_is_wp_compatible(): bool {
        global $wp_version;
        return isset( $wp_version ) && version_compare( (string) $wp_version, '6.8', '>=' );
    }
}

if ( ! function_exists( 'educbt_pro_missing_requirements' ) ) {
    function educbt_pro_missing_requirements(): array {
        $requirements = [
            'json',
            'mbstring',
            'openssl',
            'pdo',
            'pdo_mysql',
            'mysqli',
            'zip',
            'intl',
        ];

        $missing = [];
        foreach ( $requirements as $requirement ) {
            if ( ! extension_loaded( $requirement ) ) {
                $missing[] = $requirement;
            }
        }

        return $missing;
    }
}

if ( ! function_exists( 'educbt_pro_render_php_notice' ) ) {
    function educbt_pro_render_php_notice(): void {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        $missing_requirements = educbt_pro_missing_requirements();

        echo '<div class="notice notice-error"><p>';
        echo esc_html__( 'EduCBT Pro requires PHP 8.2+, WordPress 6.8+, and the required PHP extensions. Please upgrade your environment and try again.', 'educbt-pro' );
        if ( ! empty( $missing_requirements ) ) {
            echo ' ' . esc_html( sprintf( 'Missing extensions: %s.', implode( ', ', $missing_requirements ) ) );
        }
        echo '</p></div>';
    }
}

if ( ! function_exists( 'educbt_pro_register_autoloader' ) ) {
    function educbt_pro_register_autoloader(): void {
        if ( ! class_exists( 'EduCBTPro\Core\Autoloader' ) ) {
            require_once EDUCBT_PRO_DIR . 'includes/Core/Autoloader.php';
        }
        EduCBTPro\Core\Autoloader::register();
    }
}

if ( ! function_exists( 'educbt_pro_activate_plugin' ) ) {
    function educbt_pro_activate_plugin(): void {
        if ( ! educbt_pro_is_php_compatible() || ! educbt_pro_is_wp_compatible() || ! empty( educbt_pro_missing_requirements() ) ) {
            deactivate_plugins( plugin_basename( EDUCBT_PRO_FILE ) );
            wp_die(
                esc_html__( 'EduCBT Pro requires PHP 8.2+, WordPress 6.8+, and the required PHP extensions. Upgrade your environment, then activate again.', 'educbt-pro' ),
                esc_html__( 'Plugin Activation Failed', 'educbt-pro' ),
                [ 'back_link' => true ]
            );
        }

        educbt_pro_register_autoloader();

        EduCBTPro\Core\Plugin::on_activate();
    }
}

if ( ! educbt_pro_is_php_compatible() || ! educbt_pro_is_wp_compatible() || ! empty( educbt_pro_missing_requirements() ) ) {
    add_action( 'admin_notices', 'educbt_pro_render_php_notice' );
    return;
}

// Register autoloader early for all runtime code (deactivation, uninstall, plugins_loaded).
educbt_pro_register_autoloader();

require_once EDUCBT_PRO_DIR . 'includes/Core/Plugin.php';

register_deactivation_hook( EDUCBT_PRO_FILE, [ 'EduCBTPro\\Core\\Plugin', 'on_deactivate' ] );
register_uninstall_hook( EDUCBT_PRO_FILE, [ 'EduCBTPro\\Core\\Plugin', 'on_uninstall' ] );

if ( ! function_exists( 'educbt_pro_load_plugin' ) ) {
    function educbt_pro_load_plugin() {
        $plugin = EduCBTPro\Core\Plugin::instance();
        $plugin->run();
    }
}
add_action( 'plugins_loaded', 'educbt_pro_load_plugin' );

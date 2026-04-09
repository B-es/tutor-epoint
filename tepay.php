<?php

/**
 * Plugin Name:    	TE Pay
 * Plugin URI:      https://github.com/B-es/tutor-epoint
 * Version:         1.0.0
 * Author:          B_es
 * License:         GPLv2 or later
 * Text Domain:     tepay
 * Domain Path:     /languages
 */
defined("ABSPATH") || exit();

final class TEPay
{
    private static $instance = null;
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        $this->load_dependencies();
        $this->define_constants();
        $this->init_hooks();
    }

    private function load_dependencies(): void
    {
        require_once __DIR__ . "/vendor/autoload.php";

        if (!function_exists("is_plugin_active")) {
            require_once ABSPATH . "wp-admin/includes/plugin.php";
        }
    }

    private function define_constants(): void
    {
        define("TEPAY_VERSION", "1.0.7");
        define("TEPAY_URL", plugin_dir_url(__FILE__));
        define("TEPAY_PATH", plugin_dir_path(__FILE__));
    }

    private function init_hooks(): void
    {
        add_action("plugins_loaded", [$this, "init_gateway"], 100);
    }

    public function init_gateway(): void
    {
        if (is_plugin_active("tutor/tutor.php")) {
            new TEPay\Init();
        }
    }
}

TEPay::get_instance();

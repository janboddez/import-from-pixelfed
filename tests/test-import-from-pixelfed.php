<?php

class Test_Import_From_Pixelfed extends \WP_Mock\Tools\TestCase {
	public function setUp() : void {
		\WP_Mock::setUp();
	}

	public function tearDown() : void {
		\WP_Mock::tearDown();
	}

	public function test_import_from_pixelfed_register() {
		\WP_Mock::userFunction( 'get_option', array(
			'times'  => 1,
			'args'   => array(
				'import_from_pixelfed_settings',
				\Import_From_Pixelfed\Options_Handler::DEFAULT_SETTINGS,
			),
			'return' => \Import_From_Pixelfed\Options_Handler::DEFAULT_SETTINGS,
		) );

		$plugin = \Import_From_Pixelfed\Import_From_Pixelfed::get_instance();

		\WP_Mock::userFunction( 'register_deactivation_hook', array(
			'times' => 1,
			'args'  => array(
				dirname( dirname( __FILE__ ) ) . '/import-from-pixelfed.php',
				array( $plugin, 'deactivate' ),
			),
		) );

		\WP_Mock::expectFilterAdded( 'cron_schedules', array( $plugin, 'add_cron_schedule' ) );

		\WP_Mock::expectActionAdded( 'init', array( $plugin, 'activate' ) );
		\WP_Mock::expectActionAdded( 'plugins_loaded', array( $plugin, 'load_textdomain' ) );

		$plugin->register();

		$this->assertHooksAdded();
	}
}

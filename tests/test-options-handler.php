<?php

class Test_Options_Handler extends \WP_Mock\Tools\TestCase {
	public function setUp() : void {
		\WP_Mock::setUp();
	}

	public function tearDown() : void {
		\WP_Mock::tearDown();
	}

	public function test_options_handler_register() {
		$options_handler = new \Import_From_Pixelfed\Options_Handler();

		\WP_Mock::expectActionAdded( 'admin_menu', array( $options_handler, 'create_menu' ) );
		\WP_Mock::expectActionAdded( 'admin_enqueue_scripts', array( $options_handler, 'enqueue_scripts' ) );
		\WP_Mock::expectActionAdded( 'admin_post_import_from_pixelfed', array( $options_handler, 'admin_post' ) );
		\WP_Mock::expectActionAdded( 'import_from_pixelfed_refresh_token', array( $options_handler, 'cron_verify_token' ) );
		\WP_Mock::expectActionAdded( 'import_from_pixelfed_refresh_token', array( $options_handler, 'cron_refresh_token' ), 11 );

		$options_handler->register();

		$this->assertHooksAdded();
	}

	public function test_options_handler_add_settings() {
		$options_handler = new \Import_From_Pixelfed\Options_Handler();

		\WP_Mock::userFunction( 'add_options_page', array(
			'times' => 1,
			'args'  => array(
				'Import From Pixelfed',
				'Import From Pixelfed',
				'manage_options',
				'import-from-pixelfed',
				array( $options_handler, 'settings_page' )
			),
		) );

		\WP_Mock::expectActionAdded( 'admin_init', array( $options_handler, 'add_settings' ) );

		$options_handler->create_menu();

		$this->assertHooksAdded();
	}
}

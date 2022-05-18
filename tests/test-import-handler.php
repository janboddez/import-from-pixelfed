<?php

class Test_Import_Handler extends \WP_Mock\Tools\TestCase {
	public function setUp() : void {
		\WP_Mock::setUp();
	}

	public function tearDown() : void {
		\WP_Mock::tearDown();
	}

	public function test_import_handler_register() {
		$import_handler = new \Import_From_Pixelfed\Import_Handler( new \Import_From_Pixelfed\Options_Handler() );

		\WP_Mock::expectActionAdded( 'import_from_pixelfed_get_statuses', array( $import_handler, 'get_statuses' ) );

		$import_handler->register();

		$this->assertHooksAdded();
	}
}

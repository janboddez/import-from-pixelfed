<?php
/**
 * Plugin Name: Import from Pixelfed
 * Description: Import Pixelfed statuses into WordPress.
 * Author:      Jan Boddez
 * Author URI:  https://jan.boddez.net/
 * License:     GNU General Public License v3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: import-from-pixelfed
 * Version:     0.1.0
 *
 * @package Import_From_Pixelfed
 */

namespace Import_From_Pixelfed;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __FILE__ ) . '/includes/class-import-from-pixelfed.php';
require_once dirname( __FILE__ ) . '/includes/class-import-handler.php';
require_once dirname( __FILE__ ) . '/includes/class-options-handler.php';

$import_from_pixelfed = Import_From_Pixelfed::get_instance();
$import_from_pixelfed->register();

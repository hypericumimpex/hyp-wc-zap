<?php

use OM4\Zapier\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * This function should be used to access the OM4\Zapier\Plugin singleton class.
 * It's simpler to use this function instead of a global variable.
 *
 * @return OM4\Zapier\Plugin
 */
function WC_Zapier() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return Plugin::instance();
}

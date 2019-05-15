<?php

namespace OM4\Zapier\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Administration (dashboard) functionality
 *
 * Class Dashboard
 */
class Dashboard {

	public function __construct() {

		new Pointers();
		new FeedUI();
		new SystemStatus();
		new Privacy();

	}

}

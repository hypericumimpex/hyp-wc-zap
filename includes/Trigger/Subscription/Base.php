<?php

namespace OM4\Zapier\Trigger\Subscription;

use OM4\Zapier\Logger;
use OM4\Zapier\Plugin;
use OM4\Zapier\Feed\Feed;
use OM4\Zapier\Trigger\Order\Base as OrderBase;
use OM4\Zapier\Payload\Plugin\Subscription\Subscription as Payload;

defined( 'ABSPATH' ) || exit;

abstract class Base extends OrderBase {

	/**
	 * @var WC_Subscription instance
	 */
	protected $wc_subscription;

	/**
	 * The sample WooCommerce subscription data that is sent to Zapier as sample data.
	 *
	 * @return array
	 */
	public function get_sample_data() {
		$subscription = Payload::from_sample();
		return $subscription->to_array();
	}

	/**
	 * The WooCommerce Subscription hooks/actions that we use, provide the WC_Subscription object as the first parameter.
	 *
	 * This object can't reliably be serialized (which wp-cron requires), so instead convert it to a plain old subscription ID,
	 * and assemble_data() can convert it back to an object later.
	 *
	 * @param string $action_name
	 * @param array  $arguments
	 */
	public function __call( $action_name, array $arguments ) {
		if ( isset( $arguments[0] ) && is_a( $arguments[0], 'WC_Subscription' ) ) {
			$arguments[0] = $arguments[0]->get_id();
		}
		parent::__call( $action_name, $arguments );
	}

	/**
	 * Collect and convert all subscription data what we sending to Zapier.
	 *
	 * @param array  $args        Array of subscription ID, new_status & previous_status (if applicable)
	 * @param string $action_name Name of the WP Action that initiated the the Feed to run.
	 * @return array
	 */
	public function assemble_data( $args, $action_name ) {

		// The webhook/trigger is being tested. Send the store's most recent
		// subscription, or if that doesn't exist then send the static
		// hard-coded sample order data.
		if ( $this->is_sample() ) {
			$subscriptions = wcs_get_subscriptions(
				[
					'subscriptions_per_page' => 1,
					'orderby'                => 'start_date',
					'order'                  => 'DESC',
				]
			);

			// No existing subscriptions found, so send static hard-coded order sample data.
			if ( ! $subscriptions || empty( $subscriptions ) ) {
				return $this->get_sample_data();
			}

			$args[0] = array_shift( $subscriptions );
		}

		// Test The first argument.
		if ( is_a( $args[0], 'WC_Subscription' ) ) {
			// Is a subscription object - unlikely due to the conversion to a
			// subscription ID in OM4\Zapier\Trigger\Subscription::__call() above.
			$this->wc_subscription = $args[0];
		} elseif ( is_numeric( $args[0] ) ) {
			// Is a subscription ID.
			$this->wc_subscription = wcs_get_subscription( absint( $args[0] ) );
		} else {
			( new Logger() )->notice( 'Unknown Subscription argument $args[0]: %s', json_encode( $args[0] ) );
		}

		$new_status      = '';
		$previous_status = '';

		if ( 'woocommerce_subscription_status_updated' === $action_name ) {
			$new_status      = $args[1];
			$previous_status = $args[2];
		}

		if ( empty( $new_status ) ) {
			$new_status = $this->wc_subscription->get_status();
		}

		/*
		Compile the subscription details/data that will be sent to Zapier.
		WooCommerce Subscriptions are WooCommerce Orders, but with a few extra attributes.
		*/

		// Retrieve the basic "order" information first.
		$order_args   = array( $this->wc_subscription->get_id() );
		$subscription = Payload::from_data( parent::assemble_data( $order_args, $action_name ) );

		$subscription->status          = $new_status;
		$subscription->status_previous = $previous_status;

		// Now add the Subscription-specific information.
		$subscription->start_date        = Plugin::format_date( $this->wc_subscription->get_date_created() );
		$subscription->trial_end_date    = Plugin::format_date( $this->wc_subscription->get_date( 'trial_end_date' ) );
		$subscription->next_payment_date = Plugin::format_date( $this->wc_subscription->get_date( 'next_payment_date' ) );
		$subscription->end_date          = Plugin::format_date( $this->wc_subscription->get_date( 'end_date' ) );
		$subscription->last_payment_date = Plugin::format_date( $this->wc_subscription->get_date( 'last_order_date_paid' ) );
		$subscription->billing_period    = $this->wc_subscription->get_billing_period();
		$subscription->billing_interval  = $this->wc_subscription->get_billing_interval();

		$subscription->completed_payment_count = (int) $this->wc_subscription->get_completed_payment_count();
		// TODO: Add completed payment total?
		$subscription->failed_payment_count = (int) $this->wc_subscription->get_failed_payment_count();
		// TODO: Add failed payment total?

		$subscription->view_url = $this->wc_subscription->get_view_order_url();

		return $subscription->to_array();
	}

	protected function data_sent_to_feed( Feed $feed, $result, $action_name, $arguments, $num_attempts = 0 ) {

		$note = '';

		if ( 1 == $num_attempts  ) {
			// Successful on the first attempt
			$note .= sprintf( __( 'Subscription sent to Zapier via the <a href="%1$s">%2$s</a> Zapier feed.', 'wc_zapier' ), $feed->edit_url(), $feed->title() );
		} else {
			// It took more than 1 attempt so add that to the note
			$note .= sprintf( __( 'Subscription sent to Zapier via the <a href="%1$s">%2$s</a> Zapier feed after %3$d attempts.', 'wc_zapier' ), $feed->edit_url(), $feed->title(), $num_attempts );
		}

		$note .= sprintf( __( '<br ><br />Trigger:<br />%1$s<br />%2$s', 'wc_zapier' ), $feed->trigger()->get_trigger_title(), "<small>{$action_name}</small>" );

		$note .= $this->data_sent_note_suffix( $feed, $result, $action_name, $arguments, $num_attempts );
		// Add a private note to this order
		$this->wc_subscription->add_order_note( $note );

		( new Logger() )->debug( "Subscription #%s: Added note:\n%s", [ $this->wc_subscription->get_id(), $note ] );

	}

}

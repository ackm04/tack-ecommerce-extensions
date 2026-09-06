<?php
/**
 * Payment and shipping methods restricted by TackQuote buyer group.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS FOR
 * ─────────────────────────────────────────────────────────────────────────────
 * "Net 30 is for approved trade accounts only." Every B2B store needs some
 * version of that, and WooCommerce has no concept of one: a gateway is either
 * enabled for everybody or nobody. Retail shoppers therefore see an invoice
 * option they cannot use, and the seller finds out when an unapproved buyer
 * places a 30-day order.
 *
 * TackQuote already knows which group a buyer belongs to — the badge added in
 * 1.7.0 asks for exactly that — so the group is the natural thing to gate on.
 * No new lookup is introduced here; this reuses `Tack_B2B_Notices::buyer_group()`
 * and the answer it has already cached for the request.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THE RULE IS "ALLOW-LIST, AND EMPTY MEANS UNRESTRICTED"
 * ─────────────────────────────────────────────────────────────────────────────
 * Each restricted method names the groups that MAY use it. A method with no
 * groups named is left alone entirely.
 *
 * That default matters more than it looks. The alternative — "a method with no
 * groups is available to nobody" — turns activating this feature into an
 * immediate checkout outage, because every method starts unconfigured. A
 * merchant would switch it on, lose every payment option, and have no idea
 * why. So configuring nothing changes nothing.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AND WHY IT FAILS OPEN
 * ─────────────────────────────────────────────────────────────────────────────
 * If TackQuote cannot be reached the buyer's group is unknown. Hiding every
 * restricted method in that case would mean a slow API removes the customer's
 * ability to pay. The unknown-group case therefore leaves the methods visible,
 * for the same reason order limits do not block on a timeout: an over-permissive
 * checkout costs a conversation, an unavailable one costs the day.
 *
 * A merchant who genuinely needs the strict reading can invert it with the
 * `tackquote_restrict_when_group_unknown` filter.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Filters the available payment gateways and shipping rates by buyer group.
 */
class Tack_Group_Restrictions {

	/** Master switch. */
	const OPTION_ENABLED = 'tack_quotes_enable_group_restrictions';

	/**
	 * Gateway id => comma-separated group codes allowed to use it.
	 *
	 * Stored as one option rather than one per gateway so the whole map is
	 * saved, read and removed as a unit.
	 */
	const OPTION_PAYMENT_MAP = 'tack_quotes_payment_group_map';

	/** Shipping method id => comma-separated group codes. */
	const OPTION_SHIPPING_MAP = 'tack_quotes_shipping_group_map';

	/** Supplies the buyer group. @var Tack_B2B_Notices */
	private $notices;

	/**
	 * Constructor.
	 *
	 * @param Tack_B2B_Notices|null $notices Injected in tests; built here otherwise.
	 */
	public function __construct( $notices = null ) {
		$this->notices = $notices instanceof Tack_B2B_Notices ? $notices : new Tack_B2B_Notices();
	}

	/**
	 * Is the feature switched on?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_gateways' ), 20 );
		add_filter( 'woocommerce_package_rates', array( $this, 'filter_shipping_rates' ), 20, 2 );
	}

	/**
	 * Remove payment gateways this buyer's group may not use.
	 *
	 * @param array $gateways id => WC_Payment_Gateway.
	 * @return array
	 */
	public function filter_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || empty( $gateways ) ) {
			return $gateways;
		}
		$map = $this->parse_map( get_option( self::OPTION_PAYMENT_MAP, '' ) );
		if ( empty( $map ) ) {
			return $gateways;
		}

		$allowed = $this->group_code();

		$out = array();
		foreach ( $gateways as $id => $gateway ) {
			if ( $this->permitted( (string) $id, $map, $allowed ) ) {
				$out[ $id ] = $gateway;
			}
		}

		/*
		 * NEVER return an empty gateway list. A checkout with no payment method
		 * is a checkout nobody can complete, and a misconfiguration should
		 * degrade to "too many options" rather than "no way to pay". If the
		 * rules would remove everything, the rules are wrong — keep the
		 * original set and say so in the log.
		 */
		if ( empty( $out ) ) {
			$this->log(
				'Buyer-group restrictions would have removed EVERY payment gateway; leaving them all enabled. Check the group codes under WooCommerce -> TackQuote.'
			);
			return $gateways;
		}

		return $out;
	}

	/**
	 * Remove shipping rates this buyer's group may not use.
	 *
	 * @param array $rates   rate id => WC_Shipping_Rate.
	 * @param array $package Shipping package.
	 * @return array
	 */
	public function filter_shipping_rates( $rates, $package = array() ) {
		unset( $package );
		if ( ! is_array( $rates ) || empty( $rates ) ) {
			return $rates;
		}
		$map = $this->parse_map( get_option( self::OPTION_SHIPPING_MAP, '' ) );
		if ( empty( $map ) ) {
			return $rates;
		}

		$allowed = $this->group_code();

		$out = array();
		foreach ( $rates as $id => $rate ) {
			/*
			 * A rate id is `method_id:instance_id`, and merchants configure the
			 * METHOD. So the rule is looked up by full id first and by method
			 * id second, then evaluated ONCE.
			 *
			 * The obvious `permitted(id) || permitted(method)` is wrong, and
			 * this file's own test caught it: an unconfigured id is "permitted"
			 * by design, so the OR short-circuits to true and the method's rule
			 * is never consulted — every restriction on a method id silently
			 * did nothing.
			 */
			$method = strtok( (string) $id, ':' );
			$key    = isset( $map[ (string) $id ] ) ? (string) $id : (string) $method;
			if ( $this->permitted( $key, $map, $allowed ) ) {
				$out[ $id ] = $rate;
			}
		}

		// Same reasoning as gateways: no shipping rate at all blocks checkout.
		if ( empty( $out ) ) {
			$this->log(
				'Buyer-group restrictions would have removed EVERY shipping rate; leaving them all enabled. Check the group codes under WooCommerce -> TackQuote.'
			);
			return $rates;
		}

		return $out;
	}

	/**
	 * May this method be used?
	 *
	 * @param string      $id      Gateway or shipping method id.
	 * @param array       $map     id => array of group codes.
	 * @param string|null $allowed The buyer's group code, or null when unknown.
	 * @return bool
	 */
	private function permitted( $id, $map, $allowed ) {
		if ( ! isset( $map[ $id ] ) || empty( $map[ $id ] ) ) {
			// Unconfigured means unrestricted — see the class docblock.
			return true;
		}

		if ( null === $allowed ) {
			/**
			 * Filters whether a restricted method is hidden when the buyer's
			 * group could not be determined (anonymous, or TackQuote
			 * unreachable).
			 *
			 * Default false: leave it visible. Returning true is the strict
			 * reading, and means a TackQuote outage removes payment options.
			 *
			 * @param bool  $restrict Whether to hide the method.
			 * @param string $id      Gateway or shipping method id.
			 */
			return ! apply_filters( 'tackquote_restrict_when_group_unknown', false, $id );
		}

		/*
		 * Compared case-INSENSITIVELY, and the codes were upper-cased when the
		 * map was parsed. A merchant typing `bacs: tier3` while TackQuote
		 * returns `TIER3` would otherwise match nothing — and because the rule
		 * then fails for EVERY group, that gateway silently disappears for
		 * every customer, permanently. The "never empty the list" guard does
		 * not catch it either: that only fires when a rule removes every
		 * gateway, not when one gateway is wrongly blocked for everyone.
		 */
		return in_array( strtoupper( $allowed ), $map[ $id ], true );
	}

	/**
	 * The buyer's group code, or null when unknown.
	 *
	 * @return string|null
	 */
	private function group_code() {
		$group = $this->notices->buyer_group();
		if ( ! is_array( $group ) || empty( $group['code'] ) ) {
			return null;
		}
		return (string) $group['code'];
	}

	/**
	 * Parse the stored map.
	 *
	 * Stored one rule per line as `method_id: GROUP_A, GROUP_B`, because a
	 * merchant edits this in a textarea and JSON in a textarea is a support
	 * ticket. Blank lines and unparsable lines are skipped rather than
	 * throwing — a typo must not take the checkout down.
	 *
	 * @param string $raw Stored value.
	 * @return array<string, string[]>
	 */
	public function parse_map( $raw ) {
		$out = array();
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return $out;
		}

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$parts = explode( ':', $line, 2 );
			if ( count( $parts ) < 2 ) {
				continue;
			}
			$id = trim( $parts[0] );
			if ( '' === $id ) {
				continue;
			}
			$groups = array();
			foreach ( explode( ',', $parts[1] ) as $code ) {
				// Upper-cased here so the comparison in `permitted()` can be a
				// strict in_array against a single normalised form.
				$code = strtoupper( trim( $code ) );
				if ( '' !== $code ) {
					$groups[] = $code;
				}
			}
			if ( ! empty( $groups ) ) {
				$out[ $id ] = $groups;
			}
		}

		return $out;
	}

	/**
	 * Write to WooCommerce's log under the plugin's own source.
	 *
	 * @param string $message Message.
	 */
	private function log( $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( $logger ) {
			$logger->warning( $message, array( 'source' => 'tackquote' ) );
		}
	}
}

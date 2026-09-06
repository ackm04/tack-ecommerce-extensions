<?php
/**
 * The settings SCREEN: how it is grouped, and what a save does to the rules a
 * merchant already had.
 *
 * The owner's complaint about this plugin was that it "looks simple but
 * complicated" — a flat wall of controls with no order, and the two hardest
 * settings asking the merchant to hand-write a mini-language out of WooCommerce
 * gateway ids and TackQuote group codes. The grid that replaced those boxes is
 * the risky part of the fix: it can only draw rows for what the store has right
 * now, so every test below that says "preserved" is guarding against the same
 * accident — deleting a working rule because a page could not draw a widget.
 *
 * @package TackQuotes
 */

$settings = new Tack_Settings();

tack_test_reset_settings_api();
$settings->register_settings();

$sections = $GLOBALS['TACK_SECTIONS'];
$fields   = $GLOBALS['TACK_FIELDS'];

$section_ids = array();
foreach ( $sections as $section ) {
	$section_ids[] = $section['id'];
}

// ── The page has a readable shape ───────────────────────────────────────────

check(
	'the page is divided into sections',
	count( $sections ) >= 5,
	'sections: ' . implode( ', ', $section_ids )
);

/*
 * THE REGRESSION THIS GUARDS. Every field used to be added to whatever section
 * happened to exist, and for a while to none at all — `add_settings_field()`
 * defaults its section to 'default', and a field pointed at a section that was
 * never registered renders in an undifferentiated list above everything else.
 * Nothing errors; the page just silently stops being grouped.
 */
$orphans = array();
foreach ( $fields as $field ) {
	if ( ! in_array( $field['section'], $section_ids, true ) ) {
		$orphans[] = $field['id'] . ' -> ' . $field['section'];
	}
}
check(
	'every field is inside a registered section',
	empty( $orphans ),
	'orphaned: ' . implode( ', ', $orphans )
);

check(
	'every section has intro copy explaining it',
	0 === count(
		array_filter(
			$sections,
			function ( $section ) {
				return ! is_callable( $section['callback'] );
			}
		)
	)
);

// Connection first: nothing else on the page works without an API key, so it
// is the only section that can honestly come first.
check(
	'Connection is the FIRST section a merchant reads',
	isset( $section_ids[0] ) && 'tack_quotes_connection' === $section_ids[0],
	'first section is: ' . ( isset( $section_ids[0] ) ? $section_ids[0] : '(none)' )
);

/*
 * Payment/shipping restrictions are not pricing. They lived under the "B2B
 * pricing" heading with four genuine pricing switches, which is most of why
 * seven controls under one heading read as an undifferentiated pile.
 */
$section_of = array();
foreach ( $fields as $field ) {
	$section_of[ $field['id'] ] = $field['section'];
}
check(
	'the checkout restrictions have their own section, apart from B2B pricing',
	isset( $section_of[ Tack_Group_Restrictions::OPTION_PAYMENT_MAP ] )
		&& isset( $section_of[ Tack_Wholesale_Pricing::OPTION_ENABLED ] )
		&& $section_of[ Tack_Group_Restrictions::OPTION_PAYMENT_MAP ] !== $section_of[ Tack_Wholesale_Pricing::OPTION_ENABLED ],
	'payment map in: ' . ( $section_of[ Tack_Group_Restrictions::OPTION_PAYMENT_MAP ] ?? '?' )
		. ', wholesale in: ' . ( $section_of[ Tack_Wholesale_Pricing::OPTION_ENABLED ] ?? '?' )
);

check(
	'both rule fields and the codes field sit together in that section',
	isset( $section_of[ Tack_Group_Restrictions::OPTION_SHIPPING_MAP ], $section_of[ Tack_Settings::OPTION_GROUP_CODES ] )
		&& $section_of[ Tack_Group_Restrictions::OPTION_SHIPPING_MAP ] === $section_of[ Tack_Group_Restrictions::OPTION_PAYMENT_MAP ]
		&& $section_of[ Tack_Settings::OPTION_GROUP_CODES ] === $section_of[ Tack_Group_Restrictions::OPTION_PAYMENT_MAP ]
);

// ── The grid renders real methods, not a syntax lesson ──────────────────────

tack_test_set_option( Tack_Settings::OPTION_GROUP_CODES, array( 'TIER2', 'TIER3' ) );
tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, "cod: TIER2\n" );
tack_test_set_gateways(
	array(
		'cod'  => 'Cash on delivery',
		'bacs' => 'Direct bank transfer',
	)
);

ob_start();
$settings->field_payment_group_map();
$html = ob_get_clean();

check(
	'the grid shows the merchant-facing gateway TITLE, not just the id',
	false !== strpos( $html, 'Cash on delivery' ) && false !== strpos( $html, 'Direct bank transfer' ),
	substr( $html, 0, 200 )
);
check(
	'...with the id alongside it, because the stored rule is keyed by id',
	false !== strpos( $html, '<code>cod</code>' )
);
check(
	'groups are ticked, not typed',
	false !== strpos( $html, 'type="checkbox"' ) && false === strpos( $html, '<textarea' )
);
check(
	'an existing rule comes back already ticked',
	(bool) preg_match( '/name="tack_quotes_payment_group_map\[groups\]\[cod\]\[\]" value="TIER2" checked/', $html ),
	$html
);
check(
	'a gateway with no rule is ticked nowhere and reads as unrestricted',
	false !== strpos( $html, 'Available to everyone.' )
);
check(
	'the form declares which ids it drew, so the save knows what it owns',
	false !== strpos( $html, 'name="tack_quotes_payment_group_map[rendered][]" value="cod"' )
		&& false !== strpos( $html, 'name="tack_quotes_payment_group_map[rendered][]" value="bacs"' )
);

// A code that only exists inside a stored rule must still be tickable — if it
// had no checkbox, saving the page would silently drop it.
tack_test_set_option( Tack_Settings::OPTION_GROUP_CODES, array() );
tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, "cod: LEGACY_TIER\n" );
ob_start();
$settings->field_payment_group_map();
$html = ob_get_clean();
check(
	'a group code known only from an existing rule still gets a checkbox',
	false !== strpos( $html, 'value="LEGACY_TIER" checked' ),
	$html
);

// ── Degraded: WooCommerce told us nothing ───────────────────────────────────

/*
 * THE WORST OUTCOME AVAILABLE HERE. If the grid rendered empty when the store
 * reports no gateways, it would post an empty rule set and the next save would
 * delete every rule the merchant has. It must fall back to text instead, still
 * showing what is stored.
 */
tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, "cod: TIER2\nbacs: TIER3" );
tack_test_set_gateways( null );
ob_start();
$settings->field_payment_group_map();
$html = ob_get_clean();

check(
	'with no gateways to list, the field degrades to a textarea',
	false !== strpos( $html, '<textarea' ) && false === strpos( $html, 'type="checkbox"' )
);
check(
	'...and the textarea still contains every stored rule',
	false !== strpos( $html, 'cod: TIER2' ) && false !== strpos( $html, 'bacs: TIER3' ),
	$html
);
check(
	'...and it says it is in text mode, so the save cannot mistake it for a grid',
	false !== strpos( $html, 'value="text"' )
);

tack_test_set_shipping_methods( null );
ob_start();
$settings->field_shipping_group_map();
$html = ob_get_clean();
check(
	'the shipping field degrades the same way',
	false !== strpos( $html, '<textarea' )
);

tack_test_set_shipping_methods( array( 'free_shipping' => 'Free shipping' ) );
ob_start();
$settings->field_shipping_group_map();
$html = ob_get_clean();
check(
	'a store WITH shipping methods gets the grid',
	false !== strpos( $html, 'Free shipping' ) && false !== strpos( $html, '<code>free_shipping</code>' )
);

// ── Saving: the stored format does not change ───────────────────────────────

tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, '' );
$saved = $settings->sanitize_payment_group_map(
	array(
		'mode'     => 'matrix',
		'rendered' => array( 'cod', 'bacs' ),
		'groups'   => array( 'cod' => array( 'TIER2', 'TIER3' ) ),
	)
);

check(
	'a grid save writes the SAME line format the old textarea wrote',
	'cod: TIER2, TIER3' === $saved,
	"got: '$saved'"
);

$restrictions = new Tack_Group_Restrictions();
check(
	'...and parse_map, still the only reader, reads it back unchanged',
	array( 'cod' => array( 'TIER2', 'TIER3' ) ) === $restrictions->parse_map( $saved ),
	wp_json_encode( $restrictions->parse_map( $saved ) )
);
check(
	'a method with nothing ticked gets NO rule, which means unrestricted',
	! isset( $restrictions->parse_map( $saved )['bacs'] )
);

// Round trip: save what the grid would post back for the stored state, and the
// stored state must be identical. A grid that quietly rewrites rules on every
// unrelated save is its own kind of data loss.
tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, 'cod: TIER2, TIER3' );
$round = $settings->sanitize_payment_group_map(
	array(
		'mode'     => 'matrix',
		'rendered' => array( 'cod', 'bacs' ),
		'groups'   => array( 'cod' => array( 'TIER2', 'TIER3' ) ),
	)
);
check( 'saving without changing anything is a no-op', 'cod: TIER2, TIER3' === $round, "got: '$round'" );

// ── Saving: what the grid must NOT destroy ──────────────────────────────────

/*
 * Deactivate the plugin that provides `stripe`, save any TackQuote setting, and
 * a rebuilt-from-the-grid option would drop the stripe rule with no message.
 */
tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, "cod: TIER2\nstripe: TIER3" );
$kept = $settings->sanitize_payment_group_map(
	array(
		'mode'     => 'matrix',
		'rendered' => array( 'cod' ),
		'groups'   => array( 'cod' => array( 'TIER2' ) ),
	)
);
check(
	'a rule for a gateway the grid could not draw is PRESERVED',
	false !== strpos( $kept, 'stripe: TIER3' ),
	"got: '$kept'"
);

tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, "# net 30 accounts only\ncod: TIER2\n\nbacs: TIER3" );
$kept = $settings->sanitize_payment_group_map(
	array(
		'mode'     => 'matrix',
		'rendered' => array( 'cod', 'bacs' ),
		'groups'   => array(
			'cod'  => array( 'TIER2' ),
			'bacs' => array( 'TIER3' ),
		),
	)
);
check(
	'a merchant\'s own comments survive a grid save',
	false !== strpos( $kept, '# net 30 accounts only' ),
	"got: '$kept'"
);

/*
 * WordPress's own options.php calls `update_option( $option, null )` for every
 * registered option MISSING from the POST. A sanitizer that answers '' to that
 * deletes the whole rule set whenever the field fails to render.
 */
tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, 'cod: TIER2' );
check(
	'a null submission keeps the stored rules instead of wiping them',
	'cod: TIER2' === $settings->sanitize_payment_group_map( null ),
	"got: '" . $settings->sanitize_payment_group_map( null ) . "'"
);
check(
	'a grid that rendered no rows at all keeps the stored rules',
	'cod: TIER2' === $settings->sanitize_payment_group_map(
		array(
			'mode'     => 'matrix',
			'rendered' => array(),
			'groups'   => array(),
		)
	)
);
check(
	'an id the form never offered cannot be written through it',
	'cod: TIER2' === $settings->sanitize_payment_group_map(
		array(
			'mode'     => 'matrix',
			'rendered' => array( 'cod' ),
			'groups'   => array(
				'cod'      => array( 'TIER2' ),
				'injected' => array( 'TIER3' ),
			),
		)
	)
);

// The two maps are separate options and must not read each other's rules.
tack_test_set_option( Tack_Group_Restrictions::OPTION_SHIPPING_MAP, 'free_shipping: TIER3' );
check(
	'the shipping sanitizer merges onto the SHIPPING option, not the payment one',
	'free_shipping: TIER3' === $settings->sanitize_shipping_group_map( null )
);

// ── Text mode still behaves exactly as it did ───────────────────────────────

check(
	'a text-mode save stores the raw rules unchanged',
	"cod: TIER2\nbacs: TIER3" === $settings->sanitize_payment_group_map(
		array(
			'mode' => 'text',
			'text' => "cod: TIER2\nbacs: TIER3",
		)
	)
);
check(
	'a plain string still works, for update_option() callers and older saves',
	'cod: TIER2' === $settings->sanitize_payment_group_map( 'cod: TIER2' )
);
check(
	'markup is stripped out of raw rules',
	'cod: TIER2' === $settings->sanitize_payment_group_map( '<b>cod</b>: TIER2' ),
	"got: '" . $settings->sanitize_payment_group_map( '<b>cod</b>: TIER2' ) . "'"
);

// ── Group codes ─────────────────────────────────────────────────────────────

check(
	'group codes are de-duplicated, upper-cased and split on commas',
	array( 'TIER2', 'TIER3' ) === $settings->sanitize_group_codes( 'tier3, TIER2, tier3' ),
	wp_json_encode( $settings->sanitize_group_codes( 'tier3, TIER2, tier3' ) )
);
check(
	'a code cannot smuggle in the separators that would corrupt a rule line',
	array( 'TIER2TIER3' ) === $settings->sanitize_group_codes( array( "TIER2:,\nTIER3" ) ),
	wp_json_encode( $settings->sanitize_group_codes( array( "TIER2:,\nTIER3" ) ) )
);
check(
	'a garbage submission yields no codes rather than an error',
	array() === $settings->sanitize_group_codes( null )
);

tack_test_set_option( Tack_Settings::OPTION_GROUP_CODES, array( 'GOLD' ) );
tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, 'cod: SILVER' );
tack_test_set_option( Tack_Group_Restrictions::OPTION_SHIPPING_MAP, 'free_shipping: BRONZE' );
check(
	'codes already used by a saved rule are offered even if never typed',
	array( 'BRONZE', 'GOLD', 'SILVER' ) === $settings->known_group_codes(),
	wp_json_encode( $settings->known_group_codes() )
);

// ── A stored code must survive being shown and saved again ──────────────────

/*
 * The subtle way the grid could have eaten a rule. Codes typed into the codes
 * FIELD go through an allow-list, which is right for new input. Pushing an
 * ALREADY STORED code through the same allow-list rewrites it — and a rewritten
 * code no longer equals the one in the rule, so its checkbox renders unticked
 * and the very next save drops the rule. The merchant loses a restriction by
 * opening the page and pressing Save, with nothing to see.
 */
tack_test_set_option( Tack_Settings::OPTION_GROUP_CODES, array() );
tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, 'cod: TIER~2' );
tack_test_set_gateways( array( 'cod' => 'Cash on delivery' ) );

ob_start();
$settings->field_payment_group_map();
$html = ob_get_clean();

check(
	'an unusual stored code is still offered, and offered TICKED',
	false !== strpos( $html, 'value="TIER~2" checked' ),
	$html
);

check(
	'...and saving the page back gives the rule returned unchanged',
	'cod: TIER~2' === $settings->sanitize_payment_group_map(
		array(
			'mode'     => 'matrix',
			'rendered' => array( 'cod' ),
			'groups'   => array( 'cod' => array( 'TIER~2' ) ),
		)
	),
	"got: '" . $settings->sanitize_payment_group_map(
		array(
			'mode'     => 'matrix',
			'rendered' => array( 'cod' ),
			'groups'   => array( 'cod' => array( 'TIER~2' ) ),
		)
	) . "'"
);

// Faithful is not the same as trusting. The two characters that would split one
// rule into two, or one code into two, are still removed on the way in.
tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, '' );
$saved = $settings->sanitize_payment_group_map(
	array(
		'mode'     => 'matrix',
		'rendered' => array( 'cod' ),
		'groups'   => array( 'cod' => array( "TIER2,\nbacs: TIER3" ) ),
	)
);
check(
	'a code cannot inject a second rule through the grid',
	1 === count( $restrictions->parse_map( $saved ) ) && isset( $restrictions->parse_map( $saved )['cod'] ),
	"got: '$saved'"
);

<?php
/**
 * Builds the Eli's TimeShiftKE demo store inside WordPress Playground.
 * Run by the blueprint after WooCommerce and the theme are installed.
 * Product photos are read from /wordpress/et-images.
 *
 * DEMO CONTENT: the watches below are unbranded SAMPLE listings (drawn by catalogue/build-images.js)
 * that show how the shop works. Eli's own stock replaces them before launch.
 */
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// The photos are already web-sized, so each one is copied straight into uploads and registered with its
// size. (media_handle_sideload opens every image, which made the demo slow to build.)
function et_sideload( $tmp, $name, $parent, $title ) {
	$up   = wp_upload_dir();
	$file = wp_unique_filename( $up['path'], $name );
	$dest = trailingslashit( $up['path'] ) . $file;
	if ( ! @rename( $tmp, $dest ) && ! copy( $tmp, $dest ) ) {
		return new WP_Error( 'et_copy', 'Could not copy ' . $name );
	}
	$type = wp_check_filetype( $file );
	$size = @getimagesize( $dest ) ?: [ 0, 0 ];
	$id   = wp_insert_attachment( [ 'post_mime_type' => $type['type'], 'post_title' => $title, 'post_status' => 'inherit', 'guid' => trailingslashit( $up['url'] ) . $file ], $dest, $parent, true, false );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	wp_update_attachment_metadata( $id, [ 'width' => $size[0], 'height' => $size[1], 'file' => _wp_relative_upload_path( $dest ), 'sizes' => [], 'image_meta' => [] ] );
	return $id;
}

// One transaction for the whole import: SQLite otherwise commits (and syncs to disk) after every query.
wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );
$wpdb->query( 'START TRANSACTION' );

// Fast demo build: the photos are already web-sized, so skip making thumbnails of each one.
if ( defined( 'ET_FAST' ) && ET_FAST ) {
	add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
	add_filter( 'big_image_size_threshold', '__return_false' );
	add_filter( 'woocommerce_background_image_regeneration', '__return_false' );
	add_filter( 'woocommerce_resize_images', '__return_false' );
}

foreach ( [
	'blogname'                               => 'Eli\'s TimeShiftKE',
	'blogdescription'                        => 'We sell & source luxurious premium quality new timepieces. Payment on delivery, free delivery in Nairobi.',
	'timezone_string'                        => 'Africa/Nairobi',
	'woocommerce_currency'                   => 'KES',
	'woocommerce_currency_pos'               => 'left_space',
	'woocommerce_price_num_decimals'         => '0',
	'woocommerce_price_thousand_sep'         => ',',
	'woocommerce_default_country'            => 'KE:KE30',
	'woocommerce_store_city'                 => 'Nairobi',
	'woocommerce_manage_stock'               => 'yes',
	'woocommerce_notify_low_stock_amount'    => '0',
	'woocommerce_notify_no_stock_amount'     => '0',
	'woocommerce_allowed_countries'          => 'specific',
	'woocommerce_specific_allowed_countries' => [ 'KE' ],
	'woocommerce_ship_to_countries'          => '',
	'woocommerce_enable_reviews'             => 'yes',
	'woocommerce_review_rating_verification_label'    => 'yes',
	'woocommerce_review_rating_verification_required' => 'yes',
	'woocommerce_onboarding_profile'         => [ 'skipped' => true ],
	'woocommerce_task_list_hidden'           => 'yes',
	'woocommerce_coming_soon'                => 'no',
	'woocommerce_checkout_phone_field'       => 'required',
	'woocommerce_enable_coupons'             => 'yes',
] as $k => $v ) {
	update_option( $k, $v );
}

// Remove sample content.
foreach ( get_posts( [ 'post_type' => [ 'post', 'page' ], 'name' => 'hello-world', 'numberposts' => 1 ] ) as $p ) wp_delete_post( $p->ID, true );
$sample = get_page_by_path( 'sample-page' );
if ( $sample ) wp_delete_post( $sample->ID, true );

// Collections. NEAT Piece$ and Fa$hionablyLux are Eli's own Instagram highlight names.
// Menu order = order in the header, filters and home page.
$cat = [];
$i   = 0;
foreach ( [
	'neat-pieces'        => [ 'NEAT Piece$', 'Executive essentials: clean dials, slim cases and leather or steel for the office and the evening.' ],
	'fashionably-lux'    => [ 'Fa$hionablyLux', 'Statement lifestyle pieces, crystal bezels and colour for the ladies and the bold.' ],
	'divers-gmts'        => [ 'Divers & GMTs', 'Rotating bezels, luminous hands and a second time zone for the traveller.' ],
	'chronographs'       => [ 'Chronographs', 'Stopwatch complications with sub-dials, from racing to tachymeter.' ],
	'skeleton-statement' => [ 'Skeleton & Statement', 'Open-worked movements and bold cases that get noticed.' ],
	'collector-box'      => [ 'Collector Box', 'Winders, rolls and tools. 10% off when you add them with a watch.' ],
] as $slug => [ $name, $desc ] ) {
	$t = term_exists( $slug, 'product_cat' ) ?: wp_insert_term( $name, 'product_cat', [ 'slug' => $slug, 'description' => $desc ] );
	$cat[ $slug ] = (int) $t['term_id'];
	update_term_meta( $cat[ $slug ], 'order', $i++ );
}

// Availability tags, in Eli's own words from Instagram ("In $tores", "PRE-ORDER$").
$tag = [];
foreach ( [ 'ready' => [ 'in-stores', 'In $tores' ], 'order' => [ 'pre-order', 'PRE-ORDER$' ] ] as $key => [ $slug, $name ] ) {
	$t = term_exists( $slug, 'product_tag' ) ?: wp_insert_term( $name, 'product_tag', [ 'slug' => $slug ] );
	$tag[ $key ] = (int) $t['term_id'];
}

// Movement and strap: global attributes with their own pages (/movement/automatic/), used by the filter chips.
function et_attribute( $slug, $label, $terms ) {
	if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
		wc_create_attribute( [ 'name' => $label, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => true ] );
	}
	$tax = wc_attribute_taxonomy_name( $slug );
	if ( ! taxonomy_exists( $tax ) ) {
		register_taxonomy( $tax, [ 'product' ], [ 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ] );
	}
	$ids = [];
	foreach ( $terms as $n => [ $term_slug, $name ] ) {
		$t = term_exists( $term_slug, $tax ) ?: wp_insert_term( $name, $tax, [ 'slug' => $term_slug ] );
		$ids[ $term_slug ] = (int) $t['term_id'];
		update_term_meta( $ids[ $term_slug ], 'order', $n );
	}
	return $ids;
}
$attr_ids = [
	'movement' => et_attribute( 'movement', 'Movement', [ [ 'automatic', 'Automatic' ], [ 'quartz', 'Quartz' ] ] ),
	'strap'    => et_attribute( 'strap', 'Strap', [ [ 'leather', 'Leather' ], [ 'steel', 'Steel bracelet' ], [ 'rubber', 'Rubber' ], [ 'mesh', 'Steel mesh' ] ] ),
];
delete_transient( 'wc_attribute_taxonomies' );

// Delivery: free in Nairobi, as on Eli's Instagram bio. Countrywide terms are a PLACEHOLDER to confirm.
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'Nairobi' );
$zone->add_location( 'KE:KE30', 'state' );
$zone->save();
$id = $zone->add_shipping_method( 'free_shipping' );
update_option( "woocommerce_free_shipping_{$id}_settings", [ 'title' => 'FREE Nairobi delivery 🔌', 'requires' => '', 'min_amount' => '0' ] );

$rest = new WC_Shipping_Zone();
$rest->set_zone_name( 'Rest of Kenya' );
$rest->add_location( 'KE', 'country' );
$rest->save();
$id = $rest->add_shipping_method( 'free_shipping' );
update_option( "woocommerce_free_shipping_{$id}_settings", [ 'title' => 'Countrywide parcel – we call to confirm the courier', 'requires' => '', 'min_amount' => '0' ] );

// Payment on delivery, as on Eli's Instagram.
update_option( 'woocommerce_cod_settings', [
	'enabled'            => 'yes',
	'title'              => 'Payment on delivery 📦',
	'description'        => 'Pay by M-Pesa or cash when the watch is in your hand. We call to confirm your order and delivery time first.',
	'instructions'       => 'We will call you to confirm your order and arrange delivery. Pay by M-Pesa or cash on delivery.',
	'enable_for_methods' => [],
	'enable_for_virtual' => 'yes',
] );

// Welcome code for the promo box. PLACEHOLDER: confirm the offer with Eli or delete the coupon.
if ( ! wc_get_coupon_id_by_code( 'TIMESHIFT5' ) ) {
	$c = new WC_Coupon();
	$c->set_code( 'TIMESHIFT5' );
	$c->set_description( 'Demo welcome code: 5% off watches.' );
	$c->set_discount_type( 'percent' );
	$c->set_amount( 5 );
	$c->set_excluded_product_categories( [ $cat['collector-box'] ] );
	$c->save();
}

// SAMPLE watches. avail: ready = "In $tores", order = "PRE-ORDER$".
// options: dial choices for variable watches; the first is the main photo, the rest are images/<slug>--<key>.webp.
$products = [
	[
		'slug' => 'meridian-moonphase-40', 'name' => 'Meridian Moonphase 40', 'cat' => 'neat-pieces', 'price' => 28500, 'avail' => 'ready', 'stock' => 3,
		'movement' => 'automatic', 'strap' => 'leather',
		'options' => [ '' => 'Silver', 'navy' => 'Navy' ],
		'desc' => 'A sunray dial with a moon-phase window at six and a date at three, in a polished 40 mm case on a soft leather strap. Self-winding, with an exhibition case back so you can watch the rotor turn.',
		'specs' => [ 'Case' => 'Stainless steel, 40 mm', 'Thickness' => '11.5 mm', 'Movement' => 'Automatic self-wind, 40 h reserve', 'Functions' => 'Hours, minutes, seconds, date, moon phase', 'Glass' => 'Sapphire crystal', 'Water resistance' => '50 m', 'Strap' => 'Leather (brown with silver dial, navy with navy dial)', 'Clasp' => 'Deployant buckle', 'Case back' => 'Exhibition' ],
	],
	[
		'slug' => 'regent-date-39', 'name' => 'Regent Date 39', 'cat' => 'neat-pieces', 'price' => 26000, 'sale' => 23500, 'avail' => 'ready', 'stock' => 4,
		'movement' => 'quartz', 'strap' => 'steel',
		'desc' => 'Champagne sunray dial, slim polished batons and a date window, on a three-link steel bracelet. The everyday office watch: light, accurate and easy to wear.',
		'specs' => [ 'Case' => 'Stainless steel, 39 mm', 'Thickness' => '9 mm', 'Movement' => 'Japanese quartz', 'Functions' => 'Hours, minutes, seconds, date', 'Glass' => 'Sapphire crystal', 'Water resistance' => '50 m', 'Strap' => 'Three-link steel bracelet', 'Clasp' => 'Fold-over clasp' ],
	],
	[
		'slug' => 'aurelian-date-41', 'name' => 'Aurelian Date 41', 'cat' => 'neat-pieces', 'price' => 30000, 'avail' => 'order', 'stock' => 0,
		'movement' => 'automatic', 'strap' => 'steel',
		'desc' => 'A gold-tone case and bracelet around a deep green sunray dial with Roman numerals. Made for weddings, boardrooms and big evenings. Taking pre-orders for the next drop.',
		'specs' => [ 'Case' => 'Gold-tone stainless steel, 41 mm', 'Thickness' => '12 mm', 'Movement' => 'Automatic self-wind', 'Functions' => 'Hours, minutes, seconds, date', 'Glass' => 'Sapphire crystal', 'Water resistance' => '50 m', 'Strap' => 'Gold-tone steel bracelet', 'Clasp' => 'Hidden deployant clasp' ],
	],
	[
		'slug' => 'abyss-diver-42', 'name' => 'Abyss Diver 42', 'cat' => 'divers-gmts', 'price' => 27500, 'avail' => 'ready', 'stock' => 6,
		'movement' => 'automatic', 'strap' => 'steel',
		'options' => [ '' => 'Black', 'blue' => 'Blue', 'green' => 'Green' ],
		'desc' => 'A unidirectional dive bezel, big luminous markers and a screw-down crown, on a solid steel bracelet. Choose a black, blue or green dial and bezel.',
		'specs' => [ 'Case' => 'Stainless steel, 42 mm', 'Thickness' => '13 mm', 'Movement' => 'Automatic self-wind', 'Functions' => 'Hours, minutes, seconds, date', 'Bezel' => 'Unidirectional 60-minute dive bezel', 'Glass' => 'Sapphire crystal', 'Water resistance' => '200 m', 'Strap' => 'Steel bracelet', 'Clasp' => 'Fold-over clasp with dive extension' ],
	],
	[
		'slug' => 'voyager-gmt-40', 'name' => 'Voyager GMT 40', 'cat' => 'divers-gmts', 'price' => 29000, 'avail' => 'order', 'stock' => 0,
		'movement' => 'automatic', 'strap' => 'steel',
		'desc' => 'A fourth, 24-hour hand and a black-and-bronze 24-hour bezel keep home time while you travel. Taking pre-orders for the next drop.',
		'specs' => [ 'Case' => 'Stainless steel, 40 mm', 'Thickness' => '12.5 mm', 'Movement' => 'Automatic self-wind with GMT hand', 'Functions' => 'Hours, minutes, seconds, date, second time zone', 'Bezel' => 'Bidirectional 24-hour bezel', 'Glass' => 'Sapphire crystal', 'Water resistance' => '100 m', 'Strap' => 'Steel bracelet', 'Clasp' => 'Fold-over clasp' ],
	],
	[
		'slug' => 'field-cushion-42', 'name' => 'Field Cushion 42', 'cat' => 'divers-gmts', 'price' => 24500, 'avail' => 'ready', 'stock' => 3,
		'movement' => 'quartz', 'strap' => 'rubber',
		'desc' => 'A light titanium-tone cushion case, an olive dial with big numerals and a soft rubber strap. Made for weekends, travel and the gym.',
		'specs' => [ 'Case' => 'Titanium-tone, 42 mm cushion', 'Thickness' => '10.5 mm', 'Movement' => 'Japanese quartz', 'Functions' => 'Hours, minutes, seconds', 'Glass' => 'Mineral crystal', 'Water resistance' => '100 m', 'Strap' => 'Olive rubber', 'Clasp' => 'Pin buckle' ],
	],
	[
		'slug' => 'circuit-chrono-43', 'name' => 'Circuit Chrono 43', 'cat' => 'chronographs', 'price' => 29500, 'sale' => 26500, 'avail' => 'ready', 'stock' => 2,
		'movement' => 'quartz', 'strap' => 'steel',
		'desc' => 'A white "panda" dial with black sub-dials, a tachymeter bezel and a working stopwatch, on a steel bracelet.',
		'specs' => [ 'Case' => 'Stainless steel, 43 mm', 'Thickness' => '12 mm', 'Movement' => 'Quartz chronograph', 'Functions' => 'Hours, minutes, small seconds, stopwatch, date', 'Bezel' => 'Tachymeter', 'Glass' => 'Sapphire crystal', 'Water resistance' => '100 m', 'Strap' => 'Steel bracelet', 'Clasp' => 'Fold-over clasp' ],
	],
	[
		'slug' => 'racing-chrono-42', 'name' => 'Racing Chrono 42', 'cat' => 'chronographs', 'price' => 29500, 'avail' => 'ready', 'stock' => 2,
		'movement' => 'automatic', 'strap' => 'leather',
		'desc' => 'A black sunray dial with gold sub-dials, on a black leather strap. A self-winding stopwatch with a motorsport feel.',
		'specs' => [ 'Case' => 'Stainless steel, 42 mm', 'Thickness' => '14 mm', 'Movement' => 'Automatic chronograph', 'Functions' => 'Hours, minutes, small seconds, stopwatch', 'Glass' => 'Sapphire crystal', 'Water resistance' => '100 m', 'Strap' => 'Black leather', 'Clasp' => 'Deployant buckle', 'Case back' => 'Exhibition' ],
	],
	[
		'slug' => 'atelier-skeleton-42', 'name' => 'Atelier Skeleton 42', 'cat' => 'skeleton-statement', 'price' => 28000, 'avail' => 'ready', 'stock' => 3,
		'movement' => 'automatic', 'strap' => 'leather',
		'desc' => 'An open-worked dial that shows the gears and balance wheel at work, in a rose-gold-tone case on black leather.',
		'specs' => [ 'Case' => 'Rose-gold-tone stainless steel, 42 mm', 'Thickness' => '12 mm', 'Movement' => 'Automatic, skeletonised', 'Functions' => 'Hours, minutes, seconds', 'Glass' => 'Sapphire crystal', 'Water resistance' => '30 m', 'Strap' => 'Black leather', 'Clasp' => 'Deployant buckle', 'Case back' => 'Exhibition' ],
	],
	[
		'slug' => 'onyx-statement-45', 'name' => 'Onyx Statement 45', 'cat' => 'skeleton-statement', 'price' => 29500, 'avail' => 'ready', 'stock' => 2,
		'movement' => 'automatic', 'strap' => 'rubber',
		'desc' => 'A black case with a gold-marked bezel, a carbon-pattern dial and gold hands, on a black rubber strap. Big, bold and light on the wrist.',
		'specs' => [ 'Case' => 'Black PVD steel, 45 mm', 'Thickness' => '13.5 mm', 'Movement' => 'Automatic self-wind', 'Functions' => 'Hours, minutes, seconds, date', 'Glass' => 'Sapphire crystal', 'Water resistance' => '100 m', 'Strap' => 'Black rubber', 'Clasp' => 'Deployant buckle' ],
	],
	[
		'slug' => 'celeste-ladies-32', 'name' => 'Celeste Ladies 32', 'cat' => 'fashionably-lux', 'price' => 24000, 'avail' => 'ready', 'stock' => 5,
		'movement' => 'quartz', 'strap' => 'leather',
		'options' => [ '' => 'Pink', 'teal' => 'Teal', 'mint' => 'Mint' ],
		'desc' => 'A mother-of-pearl-effect dial with crystal hour markers and a crystal-set bezel, on a coloured leather strap. Choose pink, teal or mint.',
		'specs' => [ 'Case' => 'Stainless steel, 32 mm', 'Thickness' => '8 mm', 'Movement' => 'Japanese quartz', 'Functions' => 'Hours, minutes', 'Bezel' => 'Crystal-set', 'Glass' => 'Mineral crystal', 'Water resistance' => '30 m', 'Strap' => 'Coloured leather', 'Clasp' => 'Pin buckle' ],
	],
	[
		'slug' => 'aria-ladies-28', 'name' => 'Aria Ladies 28', 'cat' => 'fashionably-lux', 'price' => 23500, 'avail' => 'ready', 'stock' => 4,
		'movement' => 'quartz', 'strap' => 'mesh',
		'desc' => 'A small rose-gold-tone case, a guilloché-pattern ivory dial with Roman numerals and a fine mesh bracelet. Dainty and easy to stack.',
		'specs' => [ 'Case' => 'Rose-gold-tone stainless steel, 28 mm', 'Thickness' => '7 mm', 'Movement' => 'Japanese quartz', 'Functions' => 'Hours, minutes', 'Glass' => 'Mineral crystal', 'Water resistance' => '30 m', 'Strap' => 'Rose-gold-tone mesh', 'Clasp' => 'Sliding magnetic clasp' ],
	],
	// Collector Box accessories.
	[
		'slug' => 'automatic-watch-winder', 'name' => 'Automatic Watch Winder', 'cat' => 'collector-box', 'price' => 6500, 'avail' => 'ready', 'stock' => 5, 'accessory' => true,
		'desc' => 'Keeps one automatic watch wound and on time while you are not wearing it. Quiet motor, mains adapter included.',
		'specs' => [ 'Holds' => 'One watch', 'Rotation' => 'Clockwise, anticlockwise or both', 'Power' => 'Mains adapter or batteries' ],
	],
	[
		'slug' => 'leather-watch-roll', 'name' => 'Leather Watch Roll', 'cat' => 'collector-box', 'price' => 3500, 'avail' => 'ready', 'stock' => 8, 'accessory' => true,
		'desc' => 'A three-watch travel roll in leather with soft pillows, so your pieces travel without scratches.',
		'specs' => [ 'Holds' => 'Three watches', 'Material' => 'Leather, suede lining' ],
	],
	[
		'slug' => 'strap-tool-kit', 'name' => 'Strap Removal Tool Kit', 'cat' => 'collector-box', 'price' => 1500, 'avail' => 'ready', 'stock' => 10, 'accessory' => true,
		'desc' => 'A spring-bar tool and a pack of spare spring bars to change straps at home in a minute.',
		'specs' => [ 'Includes' => 'Spring-bar tool, 5 spring bars, screwdriver' ],
	],
];

function et_attach_images( $pid, $slug, $name ) {
	$files = glob( "/wordpress/et-images/$slug*.webp" ) ?: [];
	$files = array_values( array_filter( $files, fn( $f ) => preg_match( '#/' . preg_quote( $slug, '#' ) . '(-\d+)?\.webp$#', $f ) ) );
	// slug.webp is the main photo, then slug-2.webp, slug-3.webp…
	$num = fn( $f ) => preg_match( '#-(\d+)\.webp$#', substr( $f, strlen( $slug ) ), $m ) ? (int) $m[1] : 1;
	usort( $files, fn( $a, $b ) => $num( basename( $a ) ) <=> $num( basename( $b ) ) );
	$labels = [ '', ' – dial close-up', ' – case back' ];
	$ids    = [];
	foreach ( $files as $i => $file ) {
		$ids[] = et_one_image( $file, $pid, $name . ( $labels[ $i ] ?? ' – photo ' . ( $i + 1 ) ) );
	}
	return array_values( array_filter( $ids ) );
}
function et_one_image( $file, $pid, $title ) {
	if ( ! file_exists( $file ) ) return 0;
	$tmp = wp_tempnam( basename( $file ) );
	copy( $file, $tmp );
	$att = et_sideload( $tmp, basename( $file ), $pid, $title );
	if ( is_wp_error( $att ) ) return 0;
	update_post_meta( $att, '_wp_attachment_image_alt', $title . ' (sample watch)' );
	return $att;
}

function et_tax_attribute( $slug, $term_ids, $position ) {
	$a = new WC_Product_Attribute();
	$a->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
	$a->set_name( wc_attribute_taxonomy_name( $slug ) );
	$a->set_options( array_values( $term_ids ) );
	$a->set_position( $position );
	$a->set_visible( true );
	$a->set_variation( false );
	return $a;
}

foreach ( $products as $order => $d ) {
	$variable = ! empty( $d['options'] );
	$p = $variable ? new WC_Product_Variable() : new WC_Product_Simple();
	$p->set_name( $d['name'] );
	$p->set_slug( $d['slug'] );
	$p->set_status( 'publish' );
	$p->set_menu_order( $order );
	$p->set_description( $d['desc'] );
	$p->set_short_description( $d['desc'] );
	$p->set_category_ids( [ $cat[ $d['cat'] ] ] );
	$p->set_featured( empty( $d['accessory'] ) );
	if ( isset( $tag[ $d['avail'] ] ) && empty( $d['accessory'] ) ) {
		$p->set_tag_ids( [ $tag[ $d['avail'] ] ] );
	}
	$p->update_meta_data( '_et_sample', 'yes' );
	$p->update_meta_data( '_et_condition', 'Brand new' );
	$p->update_meta_data( '_et_specs', implode( "\n", array_map( fn( $k, $v ) => "$k: $v", array_keys( $d['specs'] ), $d['specs'] ) ) );

	$attrs = [];
	if ( ! empty( $d['movement'] ) ) {
		$attrs[] = et_tax_attribute( 'movement', [ $attr_ids['movement'][ $d['movement'] ] ], 1 );
		$attrs[] = et_tax_attribute( 'strap', [ $attr_ids['strap'][ $d['strap'] ] ], 2 );
	}
	if ( $variable ) {
		$a = new WC_Product_Attribute();
		$a->set_name( 'Dial' );
		$a->set_options( array_values( $d['options'] ) );
		$a->set_position( 0 );
		$a->set_visible( true );
		$a->set_variation( true );
		array_unshift( $attrs, $a );
		$p->set_default_attributes( [ 'dial' => array_values( $d['options'] )[0] ] );
	} else {
		$p->set_regular_price( $d['price'] );
		if ( ! empty( $d['sale'] ) ) $p->set_sale_price( $d['sale'] );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $d['stock'] );
		$p->set_backorders( $d['avail'] === 'order' ? 'notify' : 'no' );
	}
	$p->set_attributes( $attrs );
	$pid = $p->save();

	$imgs = et_attach_images( $pid, $d['slug'], $d['name'] );
	if ( $imgs ) {
		set_post_thumbnail( $pid, $imgs[0] );
		// Written as meta: a second full product save here slowed the import.
		if ( count( $imgs ) > 1 ) update_post_meta( $pid, '_product_image_gallery', implode( ',', array_slice( $imgs, 1 ) ) );
	}

	if ( $variable ) {
		$i = 0;
		foreach ( $d['options'] as $key => $label ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $pid );
			$v->set_attributes( [ 'dial' => $label ] );
			$v->set_regular_price( $d['price'] );
			$v->set_manage_stock( true );
			$v->set_stock_quantity( max( 1, (int) floor( $d['stock'] / count( $d['options'] ) ) ) );
			$v->set_menu_order( $i++ );
			$img = $key === '' ? ( $imgs[0] ?? 0 ) : et_one_image( "/wordpress/et-images/{$d['slug']}--{$key}.webp", $pid, $d['name'] . ' – ' . $label . ' dial' );
			if ( $img ) $v->set_image_id( $img );
			$v->save();
		}
		WC_Product_Variable::sync( $pid );
	}
}

// Pages.
function et_page( $slug, $title, $content ) {
	$existing = get_page_by_path( $slug );
	if ( $existing ) return $existing->ID;
	return wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ] );
}
et_page( 'wishlist', 'Your wishlist', "<!-- wp:shortcode -->\n[et_wishlist]\n<!-- /wp:shortcode -->" );
et_page( 'sourcing-desk', 'Sourcing Desk', "<!-- wp:shortcode -->\n[et_sourcing_page]\n<!-- /wp:shortcode -->" );
$shop_page = (int) wc_get_page_id( 'shop' );
if ( $shop_page > 0 ) wp_update_post( [ 'ID' => $shop_page, 'post_title' => 'All timepieces' ] );

update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

// Skip WooCommerce's first-run redirect and setup checklist so the admin opens on the store itself.
delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_task_list_hidden_lists', [ 'setup', 'extended' ] );
update_option( 'woocommerce_task_list_complete', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_admin_install_timestamp', time() - WEEK_IN_SECONDS );

$wpdb->query( 'COMMIT' );
wp_suspend_cache_invalidation( false );
wp_defer_term_counting( false );

<?php
/* Minimal WordPress stand-ins for exercising the cache contract without a site. */
const OBJECT = 'OBJECT';
const DAY_IN_SECONDS = 86400;

class WP_Post {
	public $ID;
	public $post_title;
	public function __construct( $id, $title ) {
		$this->ID = $id;
		$this->post_title = $title;
	}
}

$GLOBALS['posts'] = [
	10 => new WP_Post( 10, 'Alpha' ),
	11 => new WP_Post( 11, 'Other' ),
];
$GLOBALS['card'] = new WP_Post( 30, 'Card' );
$GLOBALS['markup'] = '%%$post->post_title%%';
$GLOBALS['transients'] = [];
$GLOBALS['options'] = [];
$GLOBALS['actions'] = [];
$GLOBALS['post_reads'] = 0;

function absint( $value ) { return abs( (int) $value ); }
function get_the_ID() { return 10; }
function get_queried_object_id() { return 20; }
function get_post_status( $id ) { return isset( $GLOBALS['posts'][ $id ] ) ? 'publish' : false; }
function get_page_by_path( $slug, $output, $type ) { return $GLOBALS['card']; }
function get_current_blog_id() { return 1; }
function get_post_modified_time( $format, $gmt, $id ) { return '1234567890'; }
function get_post_thumbnail_id( $id ) { return 0; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function apply_filters( $name, $value ) { return $value; }
function get_post_meta( $id, $key = '', $single = false ) {
	return $key === 'card_markup' ? $GLOBALS['markup'] : [];
}
function get_post( $id ) {
	$GLOBALS['post_reads']++;
	return $GLOBALS['posts'][ $id ] ?? null;
}
function get_transient( $key ) { return $GLOBALS['transients'][ $key ]['value'] ?? false; }
function set_transient( $key, $value, $ttl ) {
	$GLOBALS['transients'][ $key ] = [ 'value' => $value, 'ttl' => $ttl ];
	return true;
}
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; }
function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); }
function wp_is_post_revision( $id ) { return false; }
function add_action( $name, $callback, $priority = 10 ) { $GLOBALS['actions'][ $name ][] = $callback; }
function sanitize_text_field( $value ) { return strip_tags( $value ); }

function check( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
}
function save_post( $id ) {
	foreach ( $GLOBALS['actions']['save_post'] as $callback ) call_user_func( $callback, $id );
}

require __DIR__ . '/../Magic_Card.php';

$first = Magic_Card::get_card( 'sample', 10, 'block-a', 20 );
check( $first === '<div class="magic_card">Alpha</div>', 'First render failed' );
check( $GLOBALS['post_reads'] === 1, 'First render did not resolve source' );
$keys = array_keys( $GLOBALS['transients'] );
check( count( $keys ) === 1, 'Transient not created' );
check( strpos( $keys[0], '_p20_c30_s10_' ) !== false, 'Key does not identify page, card and source' );
check( $GLOBALS['transients'][ $keys[0] ]['ttl'] === DAY_IN_SECONDS, 'Default TTL is not 24 hours' );

ob_start();
$second = Magic_Card::get_card( 'sample', 10, 'block-a', 20 );
$output = ob_get_clean();
check( $second === $first && $GLOBALS['post_reads'] === 1, 'Cache hit rendered again' );
check( $output === '', 'Cache hit emitted output' );

Magic_Card::get_card( 'sample', 10, 'block-b', 20 );
Magic_Card::get_card( 'sample', 11, 'block-a', 20 );
check( count( $GLOBALS['transients'] ) === 3, 'Distinct block or source did not get a separate key' );

$GLOBALS['posts'][10]->post_title = 'Beta';
save_post( 10 );
check( count( $GLOBALS['transients'] ) === 1, 'Source save did not remove all related entries' );
check( Magic_Card::get_card( 'sample', 10, 'block-a', 20 ) === '<div class="magic_card">Beta</div>', 'Source save did not refresh card' );

$GLOBALS['posts'][10]->post_title = 'Gamma';
save_post( 20 );
check( count( $GLOBALS['transients'] ) === 0, 'Page save did not remove its entries' );
check( Magic_Card::get_card( 'sample', 10, 'block-a', 20 ) === '<div class="magic_card">Gamma</div>', 'Page save did not refresh card' );

$GLOBALS['markup'] = 'Updated: %%$post->post_title%%';
save_post( 30 );
check( count( $GLOBALS['transients'] ) === 0, 'Card template save did not remove its entry' );
check( Magic_Card::get_card( 'sample', 10, 'block-a', 20 ) === '<div class="magic_card">Updated: Gamma</div>', 'Template save did not refresh card' );

$GLOBALS['markup'] = '%%$qs ->campaign%%';
save_post( 30 );
$_GET['campaign'] = 'one';
check( Magic_Card::get_card( 'sample', 10, 'block-a', 20 ) === '<div class="magic_card">one</div>', 'First request value failed' );
$_GET['campaign'] = 'two';
check( Magic_Card::get_card( 'sample', 10, 'block-a', 20 ) === '<div class="magic_card">two</div>', 'Request-specific value was cached' );
check( count( $GLOBALS['transients'] ) === 0, 'Request-specific template wrote a transient' );

echo "Cache checks passed\n";

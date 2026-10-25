<?php
/**
 * 
 * Name:  Magic_Card::
 * Description: Echo Framework. Find'n replace $post-> and other variables within cards
 * Version: 1.9
 * 
 * @authors: Junjie Canonio, Chris Chatterton
 * @collaborators: Von Sienard Tionson Vibar
 *
 * Release Notes:
 * -------------
 * 1.9  20261025: Transient card cache with post-save invalidation.
 * 1.8  20250914 CC: WP core-like gallery markup (.wp-block-gallery + inner .wp-block-image); admin Help tabs for "card" CPT.
 * 1.7  20250914 CC: ACF deep arrays (path:... + recursive image collection).
 * 1.6  20250914 CC: Full Echo merge language; ACF gallery normalization; $qs->; modifier pipeline.
 * 1.5  20250914 CC: Generic Echo merge language; $function; safer handlers.
 * 1.4  20250318 CC: apply_filters for post->post_content
 * 1.3  20241212 JC: fixed acf lookup
 * 
 */

if ( ! class_exists( 'Magic_Card' ) ):

class Magic_Card {

	public $pt = 'card';
	private static $gallery_instance = 0;

	/**
	 * Render a card template (CPT 'card' with meta 'card_markup') replacing %%...%% Echo tokens.
	 */
	public static function get_card( $template, $id = 0, $block_id = '', $page_id = null ) {
		$id = absint( $id ) ?: get_the_ID();
		if ( ! $id || get_post_status( $id ) !== 'publish' ) return false;

		$card_p = get_page_by_path( $template, OBJECT, 'card' );
		if ( ! $card_p ) return false;

		$block_id = is_scalar( $block_id ) ? (string) $block_id : '';
		$page_id  = $page_id === null ? get_queried_object_id() : absint( $page_id );
		$page_id  = absint( $page_id );
		$ttl      = DAY_IN_SECONDS;
		$ttl      = max( 0, (int) apply_filters( 'magic_card_cache_ttl', $ttl, $template, $id, $card_p->ID ) );
		$use_cache = (bool) apply_filters( 'magic_card_cache_enabled', true, $template, $id, $card_p->ID );

		$markup  = '<div class="magic_card">';
		$markup .= get_post_meta( $card_p->ID, 'card_markup', true );
		$markup .= '</div>';
		$markup  = htmlspecialchars_decode( $markup );

		// These sources can change per request without a post save.
		$has_tokens = preg_match_all( '/%%\s*(.*?)\s*%%/s', $markup, $matches );
		$cacheable = $use_cache && $ttl > 0;
		foreach ( $matches[1] ?? [] as $expression ) {
			$parts = explode( '->', $expression, 3 );
			$focus = strtolower( trim( $parts[0] ) );
			$key = strtolower( trim( $parts[1] ?? '' ) );
			if ( in_array( $focus, [ '$qs', '$function', '$lov', '$option' ], true ) || ( $focus === '$post' && $key === 'post_content' ) ) {
				$cacheable = false;
				break;
			}
		}
		if ( $cacheable ) {
			$key_parts = [
				'v'      => 'mc2',
				'blog'   => get_current_blog_id(),
				'block'  => $block_id,
				'page'   => $page_id,
				'card'   => (int) $card_p->ID,
				'source' => (int) $id,
				'page_modified' => $page_id ? (string) get_post_modified_time( 'U', true, $page_id ) : '',
				'pm'     => (string) get_post_modified_time( 'U', true, $id ),
				'tm'     => (string) get_post_modified_time( 'U', true, $card_p->ID ),
				'thumb'  => (string) get_post_thumbnail_id( $id ),
			];
			$key_parts = apply_filters( 'magic_card_cache_key_parts', $key_parts, $template, $id, $card_p->ID );
			$cache_key = 'mc2_b' . substr( md5( $block_id ), 0, 10 ) . '_p' . $page_id . '_c' . $card_p->ID . '_s' . $id . '_' . md5( wp_json_encode( $key_parts ) );
			$cached = get_transient( $cache_key );
			if ( $cached !== false ) return $cached;
		}

		if ( ! $has_tokens ) {
			if ( $cacheable && set_transient( $cache_key, $markup, $ttl ) ) {
				self::remember_cache_key( $cache_key, [ $page_id, $card_p->ID, $id ], $ttl );
			}
			return $markup;
		}

		$placeholders = $matches[0];
		$expressions  = $matches[1];

		$source_post = null;
		$source_meta = null;

		$replacements = [];

		foreach ( $expressions as $i => $expr ) {
			$raw = trim( $expr );

			$parts = explode( '->', $raw );
			$focus = isset( $parts[0] ) ? trim( $parts[0] ) : '';
			$key   = isset( $parts[1] ) ? trim( $parts[1] ) : '';
			$mods  = array_map( 'trim', array_slice( $parts, 2 ) );

			if ( $source_post === null && strpos( $raw, '$post->' ) === 0 ) {
				$source_post = get_post( $id );
			}
			if ( $source_meta === null && (strpos( $raw, '$meta->' ) === 0 || strpos( $raw, '$cptonomy->' ) === 0) ) {
				$source_meta = get_post_meta( $id );
			}

			$value = self::resolve_token( $id, $focus, $key, $mods, $source_post, $source_meta );

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			} elseif ( is_object( $value ) ) {
				$value = '';
			}

			$replacements[ $placeholders[ $i ] ] = (string) $value;
		}
		
		$rendered = strtr( $markup, $replacements );
		if ( $cacheable && set_transient( $cache_key, $rendered, $ttl ) ) {
			self::remember_cache_key( $cache_key, [ $page_id, $card_p->ID, $id ], $ttl );
		}

		return $rendered;
	}

	/** Keep exact transient names indexed by every post that contributes to them. */
	private static function remember_cache_key( $cache_key, $post_ids, $ttl ) {
		foreach ( array_unique( array_map( 'absint', $post_ids ) ) as $post_id ) {
			if ( ! $post_id ) continue;
			$index_name = 'magic_card_keys_' . $post_id;
			$keys = get_option( $index_name, [] );
			if ( ! is_array( $keys ) ) $keys = [];
			$now = time();
			foreach ( $keys as $key => $expires ) {
				if ( $expires <= $now ) unset( $keys[ $key ] );
			}
			$keys[ $cache_key ] = $now + $ttl;
			update_option( $index_name, $keys, false );
		}
	}

	/** Delete cards that use a saved page, card template, or source post. */
	public static function invalidate_post_cache( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) return;
		$revision_parent = wp_is_post_revision( $post_id );
		if ( $revision_parent ) $post_id = absint( $revision_parent );
		$index_name = 'magic_card_keys_' . $post_id;
		$keys = get_option( $index_name, [] );
		if ( is_array( $keys ) ) {
			foreach ( array_keys( $keys ) as $cache_key ) delete_transient( $cache_key );
		}
		delete_option( $index_name );
	}

	/**
	 * Resolve one Echo token → raw value → run modifiers.
	 */
	private static function resolve_token( $post_id, $focus, $key, $mods, $source_post, $source_meta ) {
		$raw = '';

		switch ( $focus ) {
			case '$post':
				if ( ! $source_post instanceof WP_Post ) break;

				if ( $key === 'post_permalink' || $key === 'permalink' ) {
					$raw = get_permalink( $source_post->ID );
					break;
				}
				if ( $key === 'post_content' ) {
					$raw = apply_filters( 'the_content', $source_post->post_content );
					break;
				}
				if ( in_array( $key, ['post_date','post_date_gmt','post_modified','post_modified_gmt'], true ) ) {
					$raw = self::maybe_format_date( $source_post->{$key} ?? '', null );
					break;
				}
				if ( property_exists( $source_post, $key ) ) {
					$raw = $source_post->{$key};
					if ( $key === 'post_excerpt' && $raw === '' ) {
						$raw = self::make_excerpt_from_content( $source_post->post_content, 250 );
					}
				}
				break;

			case '$meta':
				if ( isset( $source_meta[ $key ][0] ) && ! is_array( $source_meta[ $key ][0] ) ) {
					$raw = $source_meta[ $key ][0];
				}
				break;

			case '$acf':
				if ( function_exists( 'get_field' ) ) {
					$val = get_field( $key, $post_id );

					// Follow path:first.second if provided
					$val = self::apply_path_modifier_if_any( $val, $mods );

					// Recursively collect images
					$urls = self::collect_image_urls( $val );
					if ( ! empty( $urls ) ) {
						$raw = $urls; // array of URLs for gallery/img modifiers
						break;
					}

					// Non-image fallback
					if ( is_array( $val ) || is_object( $val ) ) {
						if ( isset( $val['url'] ) )       $raw = $val['url'];
						elseif ( isset( $val['title'] ) ) $raw = $val['title'];
						elseif ( isset( $val['ID'] ) )    $raw = (string) $val['ID'];
						elseif ( is_array( $val ) )       $raw = implode( ', ', array_map( 'strval', $val ) );
						else                              $raw = '';
					} else {
						$raw = $val;
					}
				}
				break;

			case '$taxonomy':
				$terms = wp_get_post_terms( $post_id, $key, [ 'fields' => 'names' ] );
				$raw   = $terms ?: [];
				break;

			case '$option':
				$val = get_option( $key, '' );
				$raw = ( ! is_array( $val ) && ! is_object( $val ) ) ? $val : '';
				break;

			case '$lov':
				if ( function_exists( 'get_lov' ) ) {
					$val = get_lov( $key );
					$raw = ( ! is_array( $val ) && ! is_object( $val ) ) ? $val : '';
				}
				break;

			case '$image':
				$size  = self::first_modifier_arg( $mods, 'size' ) ?: 'large';
				$thumb = get_post_thumbnail_id( $post_id );
				if ( $thumb ) {
					$src = wp_get_attachment_image_src( $thumb, $size );
					if ( $src && isset( $src[0] ) ) {
						$raw = $src[0];
						global $as3cf;
						if ( isset( $as3cf ) && $as3cf ) {
							$raw = $as3cf->filter_local->filter_post( $raw );
						}
					}
				}
				break;

			case '$function':
				$allowed = apply_filters( 'magic_card_allowed_functions', [
					'esc_url', 'esc_html', 'wp_kses_post',
				] );
				if ( in_array( $key, $allowed, true ) && function_exists( $key ) ) {
					$arg = self::first_modifier_arg( $mods, 'arg' );
					$raw = $arg !== null ? call_user_func( $key, $arg ) : call_user_func( $key );
				}
				break;

			case '$qs':
				$param = $key ?: '';
				$val   = isset( $_GET[ $param ] ) ? $_GET[ $param ] : null;
				if ( $val !== null ) {
					if ( is_array( $val ) ) $val = array_map( 'sanitize_text_field', $val );
					else $val = sanitize_text_field( $val );
				}
				$raw = $val;
				break;

			// Legacy CPT-onomies (back-compat)
			case '$cptonomy':
				if ( isset( $source_meta[ $key ][0] ) && ! is_array( $source_meta[ $key ][0] ) ) {
					$tags = @unserialize( $source_meta[ $key ][0] );
					if ( is_array( $tags ) ) {
						$items = [];
						$sub   = isset( $mods[0] ) ? $mods[0] : '';
						foreach ( $tags as $tag_id ) {
							$tag_p = get_post( $tag_id );
							if ( $tag_p ) {
								if ( $sub === 'icon' ) {
									$img_id = get_post_meta( $tag_p->ID, 'icon', true );
									$src    = $img_id ? wp_get_attachment_image_src( $img_id, 'medium' ) : null;
									if ( $src ) $items[] = '<img src="' . esc_url( $src[0] ) . '">';
								} else {
									$items[] = $tag_p->post_title;
								}
							}
						}
						$raw = $items;
					}
				}
				break;
		}

		return self::apply_modifiers( $raw, $mods, $post_id );
	}

	/* ====================== Modifiers & Helpers ====================== */

	private static function apply_modifiers( $value, $mods, $post_id ) {
		if ( empty( $mods ) ) return $value;

		$list = is_array( $value ) ? $value : null;

		foreach ( $mods as $m ) {
			$pair = explode( ':', $m, 2 );
			$mod  = strtolower( trim( $pair[0] ) );
			$arg  = isset( $pair[1] ) ? $pair[1] : null;

			switch ( $mod ) {
				case 'date':
					$value = self::maybe_format_date( $value, $arg ?: get_option( 'date_format' ) );
					break;

				case 'size':
					// consumed by $image
					break;

				case 'excerpt':
					$len   = is_numeric( $arg ) ? (int) $arg : 150;
					$value = self::make_excerpt_from_content( (string) $value, $len );
					break;

				case 'strip_tags':
					$value = is_array( $value ) ? array_map( 'wp_strip_all_tags', $value ) : wp_strip_all_tags( (string) $value );
					break;

				case 'nl2br':
					$value = is_array( $value ) ? array_map( 'nl2br', $value ) : nl2br( (string) $value );
					break;

				case 'esc_html':
					$value = is_array( $value ) ? array_map( 'esc_html', $value ) : esc_html( (string) $value );
					break;

				case 'esc_attr':
					$value = is_array( $value ) ? array_map( 'esc_attr', $value ) : esc_attr( (string) $value );
					break;

				case 'upper':
					$value = is_array( $value ) ? array_map( 'strtoupper', $value ) : strtoupper( (string) $value );
					break;

				case 'lower':
					$value = is_array( $value ) ? array_map( 'strtolower', $value ) : strtolower( (string) $value );
					break;

				case 'ucfirst':
					$value = is_array( $value ) ? array_map( 'ucfirst', $value ) : ucfirst( (string) $value );
					break;

				case 'ucwords':
					$value = is_array( $value ) ? array_map( 'ucwords', $value ) : ucwords( (string) $value );
					break;

				case 'join':
				case 'csv':
					$sep   = ( $mod === 'csv' ) ? ', ' : ( $arg !== null ? $arg : ', ' );
					$list  = $list ?? (array) $value;
					$value = implode( $sep, array_map( 'strval', $list ) );
					break;

				case 'ul':
					$list  = $list ?? (array) $value;
					$html  = '<ul class="term-list">';
					foreach ( $list as $li ) $html .= '<li class="term-item">' . esc_html( (string) $li ) . '</li>';
					$html .= '</ul>';
					$value = $html;
					break;

				case 'wrap':
					if ( $arg && strpos( $arg, '|' ) !== false ) {
						list( $before, $after ) = array_map( 'strval', explode( '|', $arg, 2 ) );
						$value = $before . ( is_array( $value ) ? implode( '', $value ) : (string) $value ) . $after;
					}
					break;

				case 'default':
					if ( self::is_emptyish( $value ) ) $value = $arg !== null ? $arg : '';
					break;

				case 'int':
					$value = is_array( $value ) ? array_map( 'intval', (array) $value ) : intval( $value );
					break;

				case 'bool':
					$to_bool = function( $v ) {
						$v = is_string( $v ) ? strtolower( trim( $v ) ) : $v;
						if ( $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'yes' ) return true;
						return ! empty( $v );
					};
					$b = is_array( $value ) ? array_map( $to_bool, (array) $value ) : $to_bool( $value );
					$value = is_array( $b ) ? array_map( fn( $x ) => $x ? '1' : '', $b ) : ( $b ? '1' : '' );
					break;

				case 'img':
					$url = is_array( $value ) ? ( $value[0] ?? '' ) : (string) $value;
					$url = esc_url( $url );
					if ( $url ) $value = '<img src="' . $url . '" loading="lazy" decoding="async">';
					break;

				/* ----- NEW: gallery outputs ----- */
				case 'gallery':
                	$style   = $arg ?: 'wp'; // 'wp' (default) or 'legacy'
                	$urls    = is_array( $value ) ? $value : ( $value ? [ $value ] : [] );
                	$urls    = array_values( array_filter( array_map( 'esc_url', $urls ) ) );
                	if ( empty( $urls ) ) { $value = ''; break; }
                
                	// Legacy UL/LI if explicitly requested
                	if ( $style === 'legacy' ) {
                		$html = '<ul class="mc-gallery">';
                		foreach ( $urls as $u ) $html .= '<li class="mc-gallery__item"><img src="' . $u . '" loading="lazy" decoding="async"></li>';
                		$html .= '</ul>';
                		$value = $html;
                		break;
                	}
                
                	// Core-like figure.wp-block-gallery + nested figure.wp-block-image
                	$cols    = self::first_modifier_arg( $mods, 'cols' );              // e.g. cols:3
                	$cropped = in_array( 'cropped', array_map( 'strtolower', $mods ), true );
                	$imgsize = self::first_modifier_arg( $mods, 'imgsize' ) ?: 'large'; // default "large"
                
                	self::$gallery_instance++;
                	$instance_class = 'wp-block-gallery-' . self::$gallery_instance;
                
                	$classes = [ 'wp-block-gallery', 'has-nested-images', $instance_class, 'is-layout-flex', 'wp-block-gallery-is-layout-flex' ];
                	if ( $cols && is_numeric( $cols ) ) {
                		$classes[] = 'has-' . intval( $cols ) . '-columns';
                	} else {
                		// Match core’s “columns-default” when no explicit columns
                		$classes[] = 'columns-default';
                	}
                	if ( $cropped ) {
                		$classes[] = 'is-cropped';
                	}
                
                	$html = '<figure class="' . esc_attr( implode( ' ', array_unique( $classes ) ) ) . '">';
                
                	// Build each inner wp-block-image figure with srcset/sizes when possible
                	foreach ( $urls as $u ) {
                		list( $img_html, $size_class ) = self::build_core_image_html( $u, $imgsize );
                		$html .= '<figure class="wp-block-image ' . esc_attr( $size_class ) . '">' . $img_html . '</figure>';
                	}
                
                	$html .= '</figure>';
                	$value = $html;
                	break;
                	
                case 'number': // e.g. ->number or ->number:2
                    $dec = is_numeric($arg) ? (int) $arg : 0;
                    $fmt = function($v) use ($dec) {
                        // cast to float; WP will apply locale thousands/decimal separators
                        return is_numeric($v) ? number_format_i18n((float)$v, $dec) : (string)$v;
                    };
                    if (is_array($value)) {
                        $value = array_map($fmt, $value);
                    } else {
                        $value = $fmt($value);
                    }
                    break;


				case 'raw':
					return $value;
			}
		}
		return $value;
	}

	private static function maybe_format_date( $val, $format = null ) {
		if ( ! $val ) return '';
		$ts = is_numeric( $val ) ? (int) $val : strtotime( (string) $val );
		if ( ! $ts ) return (string) $val;
		return date_i18n( $format ?: get_option( 'date_format' ), $ts );
	}

	private static function make_excerpt_from_content( $content, $len ) {
		$plain = trim( wp_strip_all_tags( (string) $content ) );
		if ( strlen( $plain ) <= $len ) return $plain;
		$subst = substr( $plain, 0, $len );
		$last  = strrpos( $subst, ' ' );
		return rtrim( substr( $subst, 0, $last !== false ? $last : $len ) ) . '…';
	}

	private static function first_modifier_arg( $mods, $name ) {
		foreach ( $mods as $m ) {
			$pair = explode( ':', $m, 2 );
			if ( strtolower( trim( $pair[0] ) ) === strtolower( $name ) ) {
				return isset( $pair[1] ) ? $pair[1] : '';
			}
		}
		return null;
	}

	/* ====================== ACF deep image helpers ====================== */

	private static function apply_path_modifier_if_any( $val, $mods ) {
		$path = self::first_modifier_arg( $mods, 'path' );
		if ( ! $path ) return $val;

		$keys = array_filter( array_map( 'trim', explode( '.', $path ) ) );
		foreach ( $keys as $k ) {
			if ( is_array( $val ) ) {
				if ( array_key_exists( $k, $val ) ) {
					$val = $val[ $k ];
					continue;
				}
				$next = [];
				foreach ( $val as $item ) {
					if ( is_array( $item ) && array_key_exists( $k, $item ) ) {
						$next[] = $item[ $k ];
					}
				}
				$val = empty( $next ) ? $val : $next;
			} else {
				break;
			}
		}
		return $val;
	}

	private static function collect_image_urls( $val ) : array {
		$found = [];

		$push = function( $url ) use ( &$found ) {
			if ( $url && is_string( $url ) ) $found[] = $url;
		};
		$from_id = function( $id ) {
			$src = wp_get_attachment_image_src( (int) $id, 'large' );
			return $src && isset( $src[0] ) ? $src[0] : '';
		};
		$walk = function( $node ) use ( &$walk, $push, $from_id ) {
			if ( is_string( $node ) ) {
				if ( stripos( $node, 'http://' ) === 0 || stripos( $node, 'https://' ) === 0 ) $push( $node );
				return;
			}
			if ( is_int( $node ) ) { $push( $from_id( $node ) ); return; }
			if ( is_array( $node ) ) {
				if ( isset( $node['url'] ) && is_string( $node['url'] ) ) { $push( $node['url'] ); return; }
				if ( isset( $node['ID'] ) && is_numeric( $node['ID'] ) )  { $push( $from_id( (int) $node['ID'] ) ); return; }
				if ( isset( $node['sizes'] ) && is_array( $node['sizes'] ) ) {
					$url = $node['sizes']['large'] ?? $node['sizes']['full'] ?? current( $node['sizes'] );
					if ( is_string( $url ) ) $push( $url );
					return;
				}
				foreach ( $node as $child ) $walk( $child );
			}
		};

		$walk( $val );

		global $as3cf;
		if ( isset( $as3cf ) && $as3cf ) {
			$found = array_map( fn( $u ) => $as3cf->filter_local->filter_post( $u ), $found );
		}
		return array_values( array_filter( array_unique( $found ) ) );
	}
	
    /**
     * Build a core-like <img> with width/height, data-id, class "wp-image-ID",
     * and proper srcset/sizes when attachment ID can be resolved.
     *
     * @return array [ $img_html, $size_class ]  e.g. ['<img ...>', 'size-large']
     */
    private static function build_core_image_html( $url, $size = 'large' ) : array {
    	$size_class = 'size-' . sanitize_html_class( $size );
    	$id = self::attachment_id_from_url( $url );
    
    	// If ID is available, use WP image functions for accurate dims/srcset/sizes
    	if ( $id ) {
    		// Get main source + intrinsic size
    		$src = wp_get_attachment_image_src( $id, $size );
    		if ( $src && isset( $src[0], $src[1], $src[2] ) ) {
    			$src_url = $src[0];
    			$w       = intval( $src[1] );
    			$h       = intval( $src[2] );
    		} else {
    			// Fallback to provided URL
    			$src_url = $url;
    			$w = $h = 0;
    		}
    
    		$srcset = wp_get_attachment_image_srcset( $id, $size );
    		$sizes  = wp_get_attachment_image_sizes( $id, $size );
    
    		$attrs = [
    			'decoding' => 'async',
    			'loading'  => 'lazy',
    			'class'    => 'wp-image-' . $id,
    			'src'      => $src_url,
    			'alt'      => esc_attr( get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
    			'data-id'  => (string) $id,
    		];
    		if ( $w > 0 && $h > 0 ) { $attrs['width'] = (string) $w; $attrs['height'] = (string) $h; }
    		if ( $srcset ) $attrs['srcset'] = $srcset;
    		if ( $sizes )  $attrs['sizes']  = $sizes;
    
    		// Render attributes
    		$attr_html = '';
    		foreach ( $attrs as $k => $v ) {
    			if ( $v === '' || $v === null ) continue;
    			$attr_html .= ' ' . $k . '="' . esc_attr( $v ) . '"';
    		}
    		return [ '<img' . $attr_html . '>', $size_class ];
    	}
    
    	// No ID → do a best-effort <img>
    	$attrs = [
    		'decoding' => 'async',
    		'loading'  => 'lazy',
    		'src'      => $url,
    		'alt'      => '',
    	];
    	$attr_html = '';
    	foreach ( $attrs as $k => $v ) {
    		$attr_html .= ' ' . $k . '="' . esc_attr( $v ) . '"';
    	}
    	return [ '<img' . $attr_html . '>', $size_class ];
    }
    
    /**
     * Try to resolve an attachment ID from a URL.
     * Works for local uploads; returns 0 if not found.
     */
    private static function attachment_id_from_url( $url ) : int {
    	if ( ! $url ) return 0;
    
    	// Fast path — core helper
    	if ( function_exists( 'attachment_url_to_postid' ) ) {
    		$id = attachment_url_to_postid( $url );
    		if ( $id ) return (int) $id;
    	}
    
    	// If Offload plugin rewrites URLs, try mapping back
    	if ( function_exists( 'wp_upload_dir' ) ) {
    		$uploads = wp_upload_dir();
    		if ( ! empty( $uploads['baseurl'] ) && str_contains( $url, $uploads['baseurl'] ) ) {
    			$id = attachment_url_to_postid( $url ); // retry in local form
    			if ( $id ) return (int) $id;
    		}
    	}
    
    	return 0;
    }


	/* ====================== Misc utils ====================== */

	private static function is_emptyish( $v ) : bool {
		if ( is_array( $v ) ) return count( $v ) === 0;
		return $v === null || $v === '' || $v === false;
	}
}

endif;

// Immediate initialization.
new Magic_Card();

add_action( 'save_post', [ 'Magic_Card', 'invalidate_post_cache' ], 100 );

/* ================================================================
 * Admin Help Tabs for “card” CPT: one tab per Echo focus
 * ================================================================ */
add_action( 'load-edit.php',      'magic_card_add_help_tabs_router' );
add_action( 'load-post.php',      'magic_card_add_help_tabs_router' );
add_action( 'load-post-new.php',  'magic_card_add_help_tabs_router' );

function magic_card_add_help_tabs_router() {
	$screen = get_current_screen();
	if ( ! $screen ) return;

	// Accept either explicit screen id or post_type param
	$post_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : '';
	if ( empty($post_type) && ! empty($screen->post_type) ) {
		$post_type = $screen->post_type;
	}

	// Only for our CPT
	if ( $post_type !== 'card' ) return;

	magic_card_add_help_tabs( $screen );
}

function magic_card_add_help_tabs( WP_Screen $screen ) {
	$help = [
		'$post' => [
			'title' => '$post (WP_Post fields)',
			'html'  => '
				<p>Pull from the source post object.</p>
				<pre>%%$post->post_title%%</pre>
				<pre>%%$post->post_date->date:d M Y%%</pre>
				<pre>%%$post->post_excerpt->excerpt:180%%</pre>
				<pre>%%$post->permalink%%</pre>
			',
		],
		'$acf' => [
			'title' => '$acf (Advanced Custom Fields)',
			'html'  => '
				<p>Reads ACF fields. Images/Repeaters/Galleries are auto-normalised.</p>
				<pre>%%$acf->hero_headline%%</pre>
				<pre>%%$acf->gallery->gallery%%</pre>
				<pre>%%$acf->repeater->path:image->gallery:wp->cols:3->cropped%%</pre>
				<pre>%%$acf->gallery->img%%</pre>
			',
		],
		'$meta' => [
			'title' => '$meta (post meta)',
			'html'  => '
				<p>Reads from <code>get_post_meta()</code>.</p>
				<pre>%%$meta->subtitle->esc_html%%</pre>
			',
		],
		'$taxonomy' => [
			'title' => '$taxonomy (terms)',
			'html'  => '
				<p>Outputs term names for a taxonomy.</p>
				<pre>%%$taxonomy->category->csv%%</pre>
				<pre>%%$taxonomy->post_tag->ul%%</pre>
			',
		],
		'$option' => [
			'title' => '$option (site options)',
			'html'  => '
				<p>Reads from <code>get_option()</code>.</p>
				<pre>%%$option->blogdescription%%</pre>
			',
		],
		'$lov' => [
			'title' => '$lov (custom getter)',
			'html'  => '
				<p>Calls your theme/plugin <code>get_lov()</code>.</p>
				<pre>%%$lov->shop_url->esc_url%%</pre>
			',
		],
		'$image' => [
			'title' => '$image (featured image URL)',
			'html'  => '
				<p>Returns the featured image URL. Control size via modifier.</p>
				<pre>%%$image->featured->size:medium%%</pre>
			',
		],
		'$function' => [
			'title' => '$function (whitelisted PHP functions)',
			'html'  => '
				<p>Runs a safe function (extend via <code>magic_card_allowed_functions</code> filter).</p>
				<pre>%%$function->esc_url->arg:https://example.com%%</pre>
			',
		],
		'$qs' => [
			'title' => '$qs (query string)',
			'html'  => '
				<p>Reads <code>$_GET</code> parameters (sanitised).</p>
				<pre>%%$qs->utm_campaign%%</pre>
				<pre>%%$qs->page->default:1->int%%</pre>
				<pre>%%$qs->return_to->esc_url%%</pre>
			',
		],
		'Legacy' => [
			'title' => 'Legacy ($cptonomy etc.)',
			'html'  => '
				<p>Kept for back-compat.</p>
				<pre>%%$cptonomy->skills%%</pre>
				<pre>%%$cptonomy->skills->icon%%</pre>
			',
		],
	];

	$mods = '
		<p><strong>Common modifiers</strong></p>
		<ul>
			<li><code>date:FORMAT</code>, <code>excerpt:N</code>, <code>strip_tags</code>, <code>nl2br</code>, <code>esc_html</code>, <code>esc_attr</code></li>
			<li><code>upper</code>, <code>lower</code>, <code>ucfirst</code>, <code>ucwords</code></li>
			<li><code>join:SEP</code>, <code>csv</code>, <code>ul</code>, <code>wrap:BEFORE|AFTER</code></li>
			<li><code>default:VALUE</code>, <code>int</code>, <code>bool</code>, <code>raw</code></li>
			<li><code>path:a.b</code> (ACF deep access)</li>
			<li><code>gallery[:wp|legacy]</code>, <code>cols:N</code>, <code>cropped</code>, <code>img</code></li>
		</ul>
	';

	foreach ( $help as $id => $tab ) {
		$screen->add_help_tab( [
			'id'      => 'magic_card_' . sanitize_key( $id ),
			'title'   => $tab['title'],
			'content' => '<div class="magic-card-help">' . $tab['html'] . $mods . '</div>',
		] );
	}

	$screen->set_help_sidebar(
		'<p><strong>Echo Merge Language</strong></p>
		 <p>Tokens are <code>%%$focus->key->modifier%%</code>. Modifiers can be piped.</p>
		 <p><code>$focus</code> ∈ { $post, $acf, $meta, $taxonomy, $option, $lov, $image, $function, $qs }</p>'
	);
}

add_action( 'acf/save_post', function( $post_id ) {
	if ( get_post_type( $post_id ) !== 'card' ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	$slug = get_field( 'slug', $post_id );

	if ( ! $slug ) {
		return;
	}

	$slug = sanitize_title( $slug );

	remove_action( 'acf/save_post', __FUNCTION__ );

	wp_update_post( array(
		'ID'        => $post_id,
		'post_name' => $slug,
	) );
}, 20 );

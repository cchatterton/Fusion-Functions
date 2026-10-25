# Magic Card cache

## Behaviour

`Magic_Card::get_card()` returns a saved transient when available. On a miss it renders the card as a string, stores that string, and returns it. The default TTL is 24 hours (`DAY_IN_SECONDS` in `cards.php`); `magic_card_cache_ttl` and `magic_card_cache_enabled` remain available to change it.

The cache key material includes the block instance ID, calling page ID, card template ID and source post ID. The transient name contains a short hash of the block ID and the three post IDs in readable form. A stable block ID and an explicit page ID may be supplied as the third and fourth arguments. If the page ID is omitted, WordPress's queried object ID is used.

On a successful write, `cards.php` records the exact transient name under each of the three post IDs: calling page, card template and source. Its `save_post` callback checks the matching index, calls `delete_transient()` for every recorded name, then removes the index. This works whether transients are in the options table or an external object cache; scanning transient names in the database would not.

Templates using `$qs`, `$function`, `$lov`, `$option` or filtered `$post->post_content` tokens bypass the shared transient because their output may change without any of those three posts being saved.

## Integration

Load `cards.php` on display requests **and on WordPress edit/save requests**, so the save hook can run. `Magic_Card.php` remains as a compatibility loader for existing include paths.

```php
require_once __DIR__ . '/cards.php';
$html = Magic_Card::get_card( 'card-slug', $source_post_id, $stable_block_id, $calling_page_id );
```

The third and fourth arguments are optional for existing callers. A missing block ID is treated as an empty ID, so callers should provide one when separate block instances need separate entries.

## Limits

Changes that do not fire `save_post`, such as a standalone metadata or term update, may leave an entry until its 24-hour expiry. A save invalidates the relevant page, template and source entries. The index is updated on cache writes and prunes expired names; old names can remain in other indexes until their next write or save. A transient may also disappear early, and the card is regenerated on the next request.

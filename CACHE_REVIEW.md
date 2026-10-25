# Magic Card cache review

## Current behaviour

`get_card()` builds one key for each card template ID and source post ID. It also includes the current blog ID, the two posts' modification times, and the featured image ID. On a hit it returns saved rendered markup.

The implementation needs repair before this is a reliable cross-request cache:

1. `$use_cache` and `$ttl` are read before assignment. Their default values are therefore undefined. A filter could supply values, but the source does not enable caching by itself.
2. WordPress always defines `wp_cache_get()` and `wp_cache_set()`. The `get_transient()` and `set_transient()` branches will not normally execute. The default object cache is request-local unless a persistent object-cache drop-in is installed.
3. A hit executes `echo 'cached';`, leaking debug text into page output.
4. The key does not change when ACF fields, post meta, taxonomy assignments, options, custom `get_lov()` results, or query parameters change. These values can affect rendered output. Attachment metadata and image URLs can also change without changing the featured image ID.
5. The cache is checked before the template's tokens are inspected, so the renderer cannot currently decide whether a particular template is safe to cache. A time limit alone bounds stale output; it does not prevent it.

## Recommended next change

Keep the supplied file as the baseline and make a focused cache patch in a later commit:

1. Define explicit defaults for enablement and TTL, retaining the existing filters.
2. Choose one storage policy deliberately. For site-wide caching without requiring Redis or Memcached, use the WordPress Transients API. A persistent object cache can still back transients where configured.
3. Remove the debug `echo` and calculate the rendered markup once before writing it.
4. Classify the template's token dependencies before reading the cache. Bypass shared caching for request-specific or uncontrolled sources (`$qs`, `$function`, `$lov`) by default. Decide whether `$option` should also bypass or use a specific invalidation strategy.
5. Invalidate or version keys when the source post, card template, relevant post meta/ACF fields, taxonomy relationships, or attachment data change. Keep a finite TTL as a recovery bound.
6. Test a miss, hit, expiry, and each invalidation path in a WordPress test environment, both with and without a persistent object cache.

The exact cache policy should be chosen before code changes because a rendered card can mix stable post data with request-dependent values.

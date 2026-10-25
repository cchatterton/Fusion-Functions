# Fusion Functions

This repository is a collection of reusable PHP code for WordPress projects, not a WordPress plugin. It starts with `Magic_Card`, version 1.9. The original version 1.8 remains in the first commit.

Load `cards.php` in the host project; `Magic_Card.php` remains as a compatibility loader. Render with `Magic_Card::get_card( $card_slug, $source_post_id, $block_id, $page_id )`. The block and page arguments are optional for existing calls. See [CACHE_REVIEW.md](CACHE_REVIEW.md) for transient caching, save invalidation and integration requirements.

Development follows the applicable general and function standards in [codex-standards](https://github.com/cchatterton/codex-standards). Plugin packaging, update-controller, and plugin release rules do not apply to this repository.

# What it counts as a reference

Written against plugin version 1.11.0.

**Strong references** (the file is definitely in use):

| Source | What it catches |
|---|---|
| `post_content` | Upload URLs, `wp-image-123` classes, `[caption id="attachment_123"]`, `[gallery ids="1,2"]`, block JSON (`<!-- wp:image {"id":123} -->`), `?attachment_id=123` |
| `_thumbnail_id` | Featured images |
| Post meta | Any upload URL, plus JSON keys `id`, `ids`, `image_id`, `attachment_id`, `thumbnail_id`, `media_id`. Covers Elementor, Beaver Builder, Divi, and most block-based builders |
| Options | Theme mods, custom logo, site icon, media widgets, plugin settings |
| Term, user, and comment meta | Taxonomy images, profile images, attachment fields |
| Nav menus | Menu items pointing at an attachment |
| Post revisions | Optional. On by default so restoring a revision does not break |
| Other network sites | Optional. Catches an image this site hosts that a sibling site displays |

**Weak references** (`Referenced by ID only`): a custom field storing a bare attachment ID with no surrounding context. This is the standard ACF image and gallery field pattern, so it is on by default. These files show as in use but are listed separately under the **Referenced by ID only** filter so you can spot-check them.

A bare number is weak evidence, and numbers collide. A field named
`source_user_id` holding `1215` means user 1215 on some other system, not
attachment 1215 here. So keys whose name states they hold another object's ID (matching `(^|_)(user|author|customer|post|page|parent|term|category|tag|comment|order|site|blog|form|entry)_id$`) are refused the weak match. Adjust the list through the
`uams_mra_foreign_id_key_pattern` filter; return `''` to disable the check.

This withholds only the *weak* match. If one of those fields really does hold an
upload URL or a JSON `"id"` key, that is strong evidence and still counts. Keys
that could legitimately hold a media ID (`image_id`, `media_id`, `attachment_id`, `thumbnail_id`) are deliberately absent from the pattern, and
anything you add to it risks a live file reporting as unreferenced.

Weak matching is also off for **user meta** entirely. WordPress and its plugins
park screen preferences, counters, and progress blobs there (`gform_recent_forms`, `_sfwd-quizzes`, `users_network_per_page`) and a bare number in one of those
means nothing to the media library. Measured against a real UAMS network: of 69
weak user-meta matches, none was a genuine reference, while every genuine one
came from post meta or an option. Strong evidence in user meta (an actual
upload URL, a JSON `"id"` key) still counts. If a site stores a user's photo as
a bare attachment ID in an ACF user field, switch it back on:

```php
add_filter( 'uams_mra_numeric_match_sources', function ( $sources ) {
	$sources['usermeta'] = true;
	return $sources;
} );
```

Meta keys that describe the attachment itself (`_wp_attached_file` and friends)
never count as a reference, in any of the four meta passes. Extend that list with
the `uams_mra_skipped_meta_keys` filter.

URL matching normalizes away every generated size, so `hero-1024x768.jpg`, `hero-scaled.jpg`, and `hero-e1712345678901.jpg` all resolve back to attachment `hero.jpg`. Filenames containing their own dots (`State-Plan-2.11.25-Final.pdf`) match whole.

A size suffix may carry a short crop marker after it (`hero-768x432-cc.jpg`),
which is how themes that build their own derivatives name them. Those collapse
too. The marker is letters only and at most four, so a real filename ending in
`-1920x1080-v2` keeps its name. Extend the list with the
`uams_mra_derivative_patterns` filter if a theme uses a shape this misses; the
symptom is a file you can see on the page reporting as unreferenced.


# Phase 3: Large-Library Mode

Status: **Design draft** — not implemented. Tracks [issue #22](https://github.com/1fixdotio/media-search-enhanced/issues/22), Phase 3.

This document is a design contract for a future opt-in "large-library mode" built around a plugin-owned denormalized search index. It exists so the work can be split into focused follow-up issues (see section 10) rather than landing as one large PR.

Phase 1 (`mse_search_fields` filter, PR #23) and Phase 2 (composite `wp_postmeta` index guidance) are prerequisites. This doc assumes both have landed or are in flight, and treats Phase 3 as the option you reach for only after Phase 1 + 2 stop paying off.

---

## 1. Problem statement & when Phase 3 pays off

### Why Phase 1 + 2 eventually stop helping

Phase 1 lets a site turn off expensive clauses (`guid`, `taxonomy`, `description`) for a narrower query. Phase 2 adds composite indexes on `wp_postmeta(meta_key, post_id)` / `(meta_key, meta_value(191))` so the correlated `EXISTS` subqueries for `_wp_attachment_image_alt` and `_wp_attached_file` can at least seek on `meta_key` before scanning.

Two structural problems survive both phases:

1. **`LIKE '%term%'` is index-hostile.** No BTREE index — composite or otherwise — can serve a leading-wildcard LIKE. Phase 2's composite index helps by making the `meta_key` lookup cheap, but the `meta_value LIKE '%term%'` probe still scans every row that matches the key. On a 200k-attachment library that is 200k rows scanned for alt text, 200k for filename, per term.
2. **Taxonomy search is an unavoidable three-table join.** `wp_term_relationships` + `wp_term_taxonomy` + `wp_terms` with a `LIKE` on `t.slug`, `t.name`, and `tt.description`. Phase 1 lets a site disable it; Phase 2 cannot index around the LIKE.

### Workload profile where Phase 3 wins

Phase 3 is the right tool when **all** of the following hold:

- Library has roughly **100k+ attachments** (ballpark — benchmark before committing). Below that, the write amplification and rebuild cost are unlikely to beat a well-indexed Phase 2 install.
- Search latency on `attachment` post type is a user-visible problem — the media modal takes seconds to populate, or `upload.php` filters freeze the admin.
- The site actively wants taxonomy / alt / filename matches (can't just disable them via Phase 1).
- The site runs MySQL **5.7+** or MariaDB **10.0.5+** (FULLTEXT on InnoDB). See section 9.
- The operator is comfortable with an opt-in plugin table that needs a one-time rebuild.

Sites that do **not** fit this profile should stay on Phase 1 + 2. The honest framing for the README is: "If `composer test:profile -- 100000` shows acceptable latency on your hardware with Phase 2 indexes applied, you do not need Phase 3."

---

## 2. Schema decision

### Candidate (a): plain normalized columns

```sql
CREATE TABLE {$prefix}mse_search_index (
  attachment_id  BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  title          TEXT,
  alt_text       TEXT,
  filename       VARCHAR(512),
  description    MEDIUMTEXT,
  caption        TEXT,
  guid           VARCHAR(2083),
  tax_terms      TEXT,          -- space-joined slugs + names + descriptions
  language_code  VARCHAR(16),   -- WPML / Polylang
  blog_id        BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at     DATETIME NOT NULL,
  KEY (blog_id, language_code),
  -- no FULLTEXT on individual columns; per-field LIKE still scans.
);
```

Trade-offs:
- **Pro:** Per-field disable (`mse_search_fields`) works naturally at query time — just omit columns from the WHERE.
- **Pro:** Straightforward to reason about, easy to inspect.
- **Con:** Still uses `LIKE '%x%'`. Smaller table than `wp_postmeta` helps but does not change asymptotic cost. The win is "no more three-table taxonomy join," not "inverted index."
- **Con:** FULLTEXT on every column is wasteful and requires maintaining many FTS indexes on write.

### Candidate (b): consolidated `searchable` FULLTEXT column

```sql
CREATE TABLE {$prefix}mse_search_index (
  attachment_id  BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  searchable     MEDIUMTEXT NOT NULL,
  language_code  VARCHAR(16),
  blog_id        BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at     DATETIME NOT NULL,
  FULLTEXT KEY ft_searchable (searchable) /*!50700 WITH PARSER ngram */,
  KEY (blog_id, language_code)
) ENGINE=InnoDB;
```

Trade-offs:
- **Pro:** Real inverted index. `MATCH(searchable) AGAINST (...)` is O(log n) in term count, not O(rows). This is the only candidate that structurally beats Phase 2.
- **Pro:** Single FULLTEXT to maintain.
- **Con:** Cannot filter by field at query time — a FULLTEXT index does not know `"Sunset" came from alt_text vs title`.
- **Con:** Subject to `innodb_ft_min_token_size` (default 3) and stopword list. See section 3.

### Recommendation: **hybrid — candidate (b) with a documented semantic shift for `mse_search_fields`**

Schema:

```sql
CREATE TABLE {$prefix}mse_search_index (
  attachment_id  BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  searchable     MEDIUMTEXT NOT NULL,          -- field values joined with a separator
  searchable_short VARCHAR(191) NOT NULL,      -- filename + title for LIKE fallback (sub-min-token-size terms)
  language_code  VARCHAR(16) NOT NULL DEFAULT '',
  blog_id        BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at     DATETIME NOT NULL,
  FULLTEXT KEY ft_searchable (searchable) /*!50700 WITH PARSER ngram */,
  KEY ix_short (searchable_short(191)),
  KEY ix_scope (blog_id, language_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Why hybrid wins:

1. `searchable` is the main payload, built at write time from the fields the site has enabled via `mse_search_fields`. Disabled fields are **not written into the index** — this redefines the filter as an indexing-time filter, not a query-time one. Toggling the filter requires a rebuild; the README must call this out explicitly.
2. `searchable_short` catches the `innodb_ft_min_token_size` footgun (see section 3) by keeping title+filename searchable via a regular BTREE prefix for short/rare-token queries that FULLTEXT cannot tokenize. It is the "LIKE fallback" the spike verifies is wirable.
3. Taxonomy terms and alt text get stuffed into `searchable` (see section 8) so the taxonomy join disappears entirely — which is the single largest structural win available.
4. The `mse_search_fields` filter retains its current semantics **when large-library mode is off**. When on, the filter runs at **index write time** instead of query time. Existing Phase 1 callsites keep working; the change is clearly documented and gated on the large-library constant.

This is an intentional semantic shift. It is the only honest answer: a FULLTEXT inverted index cannot be filtered per-field at query time, and per-column LIKE would defeat the point of having FULLTEXT.

**Per-field disable (`mse_search_fields`) → index-time effect.** Calling `mse_search_fields` with `guid=false` in large-library mode means "when I next write attachment N into the index, do not include its GUID in the `searchable` blob." Operationally: toggling the filter invalidates the index (rebuild required). The plugin should expose `mse_search_index_version()` and compare against a stored version on each query; mismatch → fall back to the Phase 1 query path until rebuild completes.

**Migration UX for sites that already use `mse_search_fields`.** When `MSE_LARGE_LIBRARY_MODE` is enabled on a site that has the filter wired up, the schema version stored in `wp_options` should hash the *active* `mse_search_fields` config at index-build time. On subsequent requests, if the runtime hash diverges from the stored hash, the read path falls back to Phase 1 and an admin notice fires: "`mse_search_fields` configuration changed since the last rebuild. Run `wp mse-search-index rebuild` to apply." This keeps the contract honest — the filter still "works", just with an explicit acknowledgement that the index must be rebuilt to honor the change.

A sibling filter `mse_large_library_search_fields` was considered as an alternative — it would let sites express two different field configs (one for the Phase 1 path, one for the index path) without surprise. **Rejected for v1** because it doubles the configuration surface for a feature most sites won't enable; revisit if real-world usage shows confusion. Tracked as a follow-up consideration rather than a v1 deliverable.

**WPML / Polylang.** One row per attachment per language. The `language_code` column is populated from `sitepress->get_current_language()` (WPML) or `pll_get_post_language()` (Polylang) at write time. Queries filter on `language_code = ?` matching the request language. If the site has neither, `language_code=''` for all rows.

---

## 3. Index strategy: FULLTEXT vs LIKE on the index table

### The `innodb_ft_min_token_size` footgun

MySQL's InnoDB FULLTEXT parser default is `innodb_ft_min_token_size = 3`. Tokens shorter than 3 characters are **silently dropped from the index**. That means:

- `"AI"`, `"v2"`, `"UX"`, `"3D"` → not tokenized, not matched.
- Two-character CJK substrings (very common) → not tokenized under the default parser.
- Short numeric IDs searchable as tokens (`"42"`) → dropped.

This is the single most surprising failure mode. We have three options:

1. **Require site owner to lower `innodb_ft_min_token_size` to 2 or 1.** Requires a server restart and an `ALTER TABLE … DROP INDEX / ADD FULLTEXT INDEX` rebuild. Not a blocker for a self-hosted admin, but unacceptable on managed WordPress hosts. **Reject** — we cannot assume MySQL config control.
2. **Accept the limitation.** Document it prominently. Small tokens fall through to `searchable_short` LIKE fallback.
3. **Fall back to LIKE for short queries.** Query path: if `strlen($term) < innodb_ft_min_token_size`, run `LIKE '%term%'` against `searchable_short` instead of `MATCH`. This is the recommended approach.

### CJK support

MySQL 5.7+ ships the `ngram` parser for bigram-based CJK indexing (`ngram_token_size = 2` default). MariaDB 10.0.5+ ships the `mroonga` engine / Mecab parser, but that's not universally available.

Recommendation: add `WITH PARSER ngram` to the FULLTEXT index. This:
- Makes 2-char CJK substrings tokenizable.
- Does **not** regress Latin-script behavior (ngram indexes non-whitespace sequences as bigrams, and whitespace-separated Latin text is handled by the default parser path for `MATCH … IN BOOLEAN MODE` with quoted terms — but bigram on Latin text changes relevance ranking).
- **Caveat:** under `ngram`, `MATCH('searchable') AGAINST ('sunset' IN BOOLEAN MODE)` against text containing `sunset` may behave differently than the default parser. We should benchmark both parsers on a representative Latin-only corpus before committing.

**Open question:** Do we ship one FULLTEXT index (ngram) or two (ngram + default)? Two indexes double write cost but allow script-appropriate search. Tentative recommendation: one ngram index, with a fallback to `searchable_short` LIKE for exact-phrase needs. Revisit after benchmarks.

### Stopword list

InnoDB ships a default stopword list including `"a"`, `"the"`, `"is"`, etc. These are dropped from the index silently. For attachment search this is mostly fine — nobody searches for a photo by typing "the". Document the behavior and point to `information_schema.INNODB_FT_DEFAULT_STOPWORD` for operators who want to override via `innodb_ft_server_stopword_table`.

### Query shape

```sql
-- Primary path (term length >= innodb_ft_min_token_size):
SELECT attachment_id
FROM {$prefix}mse_search_index
WHERE blog_id = ? AND language_code = ?
  AND MATCH(searchable) AGAINST (? IN BOOLEAN MODE)
LIMIT 1000;

-- Fallback path (short terms):
SELECT attachment_id
FROM {$prefix}mse_search_index
WHERE blog_id = ? AND language_code = ?
  AND searchable_short LIKE CONCAT('%', ?, '%')
LIMIT 1000;
```

Results flow into `posts_clauses` as `AND {$posts}.ID IN (...)`. See section 5.

---

## 4. Write path

The index has to stay consistent with the source of truth in `wp_posts`, `wp_postmeta`, and the taxonomy tables. Hooks:

| Hook | When | What the index does |
|---|---|---|
| `add_attachment` (int $post_id) | New attachment inserted | `upsert($post_id)` — compose searchable text from enabled fields, write row |
| `attachment_updated` (int $post_id, WP_Post $after, WP_Post $before) | Title/content/excerpt/guid changed | `upsert($post_id)` |
| `delete_attachment` (int $post_id) | Attachment hard-deleted | `DELETE FROM mse_search_index WHERE attachment_id = $post_id` |
| `updated_post_meta` (int $meta_id, int $post_id, string $meta_key, mixed $meta_value) | `_wp_attachment_image_alt` or `_wp_attached_file` changed | If `get_post_type($post_id) === 'attachment'` and `$meta_key` is in our tracked set, `upsert($post_id)` |
| `added_post_meta` / `deleted_post_meta` | Same meta keys added/deleted | Same as above |
| `set_object_terms` (int $object_id, array $terms, array $tt_ids, string $taxonomy) | Taxonomy terms changed on an attachment | If `$taxonomy` is attachment-bound and `get_post_type($object_id) === 'attachment'`, `upsert($object_id)` |
| `edited_term` (int $term_id, int $tt_id, string $taxonomy) | Term name/slug/description changed | Reindex all attachments with this term (expensive; see below) |

### Race conditions

- **Double `upsert` on attachment save.** Saving an attachment via media modal fires `attachment_updated` plus several `updated_post_meta` events. `upsert` should be idempotent — `INSERT ... ON DUPLICATE KEY UPDATE` keyed on `attachment_id`. We tolerate 3-4x redundant writes per save; debouncing via `wp_schedule_single_event` is a Phase-4 optimization.
- **`edited_term` fan-out.** A term rename on a taxonomy with 20k attachments triggers 20k upserts. **Threshold:** if `wp_term_taxonomy.count >= 100` for the affected term, the handler MUST queue rather than reindex inline; below that threshold, reindex inline is acceptable. Queue path: Action Scheduler if available, else chunked `wp_schedule_single_event` with batches of 500. **Failure handling:** if enqueueing fails (e.g., AS table missing, cron disabled), the term edit itself is *not* rolled back — attachment search staleness is a recoverable degradation, but blocking a term-edit transaction would be worse UX. Instead, log via `error_log()`, set a transient `mse_search_index_stale = true`, and surface an admin notice "Index reindex pending — run `wp mse-search-index rebuild`." The next successful `rebuild` clears the transient.
- **Meta value race.** `updated_post_meta` fires *before* the value is committed in some edge cases. Our upsert reads fresh values via `get_post_meta($post_id, $key, true)` inside the handler — at hook fire time, WP has already run `update_post_meta`, so reads return the new value.

---

## 5. Read path

### Option (a): short-circuit in `posts_clauses`, inject `ID IN (...)`

Same bootstrap as current plugin: `posts_search` returns empty to suppress core's LIKE fragment, `posts_clauses` appends our conditions. In large-library mode, `posts_clauses` instead queries `mse_search_index` directly, collects matching `attachment_id`s, and appends `AND {$posts}.ID IN (1,2,3,...)`.

```php
// Large-library branch of posts_clauses.
$ids = $index->query( $terms, $language, get_current_blog_id() );
if ( empty( $ids ) ) {
    $pieces['where'] .= ' AND 1=0';
    return $pieces;
}
$ids_sql = implode( ',', array_map( 'absint', $ids ) );
$pieces['where'] .= " AND {$wpdb->posts}.ID IN ( $ids_sql )";
return $pieces;
```

WP core still applies `post_status`, `post_type`, `post_mime_type`, `post_parent`, date filters, `read_private_posts` visibility. The existing `str_replace` that widens `post_status = 'inherit'` to include `'private'` **still runs**, because we stay in `posts_clauses`. That is the single biggest correctness win of option (a).

**Result-set ceiling and pagination.** `IN (...)` is fine for up to ~5k ids; past that MySQL's parser starts complaining (`max_allowed_packet` / stack depth). The index query uses `LIMIT 1000` by default, filterable via `mse_search_index_limit`.

This is an explicit **hard cap, not a paginated window**. A search matching 5,000 attachments returns the first 1,000 by `post_date DESC` ordering; the WordPress admin pagination control above page N (where N × per-page > 1000) returns no results. The accepted UX contract is: "If your search exceeds the cap, narrow with MIME type / date / parent filters in the media-modal sidebar." This matches how operators already work around large-library performance today.

Cursor-based pagination (passing the last-seen `attachment_id` or last-seen `post_date` to subsequent queries) was considered and **deferred**. It would require either replacing `WP_Query` (rejected as option (b) below) or layering an extra round-trip per pagination click; neither is justified in v1 for a feature whose primary win is search latency, not result-set depth. Tracked as a future enhancement only if real-world feedback shows the cap is a frequent blocker.

### Option (b): replace `WP_Query` entirely

We'd build a custom query that selects from `mse_search_index` joined to `wp_posts`. Rejected because:

- Reimplementing post_status visibility, WPML language filtering, and mime/date/parent filters from scratch is a lot of surface area.
- `the_posts` filter works on `WP_Post` objects; option (a) preserves that.
- `wp_prepare_attachment_for_js` and other media-library-specific path code assumes a normal `WP_Query` ran.

### Recommendation: **Option (a)**

The only real cost is that `posts_clauses` runs one extra query before the main one. On large libraries this is acceptable because the index query is O(log n) and the main query becomes `ID IN (<bounded list>)` which is trivially fast.

---

## 6. Rebuild & backfill

The index starts empty. For a 200k-attachment library we need a backfill path that:

- Chunks work to fit in `max_execution_time`.
- Is safely re-runnable after crash.
- Reports progress.
- Handles attachments created/deleted mid-rebuild.

### Proposed approach: WP-CLI command (primary) + Action Scheduler (fallback)

WP-CLI is preferred because:
- Operators on large libraries already have SSH access.
- No PHP-Fatal-on-admin-page-load risk.
- Can run under `nice -n 19` to stay out of the way.

Command sketch (design only — do **not** build in the spike):

```
wp mse-search-index rebuild [--batch-size=500] [--since=<post_id>] [--dry-run]
wp mse-search-index status
wp mse-search-index drop
```

Behavior:

1. `rebuild` writes a progress row to the WordPress options table: `mse_search_index_rebuild_state = { 'last_id' => 0, 'total' => ?, 'started_at' => ... }`. On multisite this is a per-site option (because options are per-site by default), so each blog's progress is tracked independently without bespoke keying.
2. Each batch: `SELECT ID FROM wp_posts WHERE post_type='attachment' AND ID > last_id ORDER BY ID LIMIT batch_size`, upsert each, update `last_id`.
3. If interrupted, re-running picks up at `last_id` (re-upserts the last batch — idempotent).
4. Deletions during rebuild: the `delete_attachment` hook already fires in parallel; if the attachment is gone, `upsert` becomes a no-op (`get_post` returns null, skip).
5. Creations during rebuild: new attachments `ID > last_id` are picked up naturally when the rebuild reaches them. Their `add_attachment` hook also fires in parallel; duplicate upsert is idempotent.
6. `status` reads the progress row and reports percent complete.
7. `drop` truncates the index table (useful after a failed rebuild or when disabling large-library mode permanently).

**Multisite invocation.** WP-CLI inherits `--url=` and `--network` from core; the plugin's commands do not need their own multisite flags. Operators rebuild a single blog with `wp --url=blog.example.com mse-search-index rebuild` and rebuild every blog sequentially with `wp mse-search-index rebuild --network` (which iterates `get_sites()` and re-invokes the command per site). Per-blog progress is naturally isolated because both the index table (per `$wpdb->prefix`) and the progress option are per-site.

### Action Scheduler fallback

For sites without WP-CLI access (rare on large libraries but possible), an admin-initiated background job using Action Scheduler (bundled with WooCommerce; add it as a soft dependency) can run the same batching logic. Keep this behind a second feature flag — we don't want to pull in AS unconditionally.

### Post-install behavior

When `MSE_LARGE_LIBRARY_MODE` is first defined and the table exists but is empty (or stale, per `mse_search_index_version` mismatch), the read path should **fall back to the Phase 1 LIKE query** rather than return zero results. This keeps the site functional until rebuild completes.

**Visibility of fallback mode is mandatory.** The fallback path is correct for keeping the site working, but it is also a silent performance cliff if the operator forgets to rebuild. Required behavior:

1. **Persistent admin notice** on `upload.php` and any media-modal-bearing screen, *not user-dismissible*: "Large-library mode is enabled but the search index is empty / out of date. Run `wp mse-search-index rebuild` (or trigger a rebuild from Tools → Media Search Index)." The notice clears automatically once the next query path uses the index successfully.
2. **`_doing_it_wrong()` once per request** when the read path falls back, gated by a static flag so a page with many `WP_Query` calls doesn't fire it repeatedly. Logged via WordPress's standard channel so site monitoring picks it up.
3. **No "strict mode" in v1.** A configurable `MSE_LARGE_LIBRARY_STRICT` constant that returns zero results instead of falling back was considered and rejected for v1: silent zero-results is a worse failure mode than slow-but-correct results. If real-world feedback shows operators want a forcing function, revisit in v2.

---

## 7. Configuration surface

### Options considered

| Mechanism | Pros | Cons |
|---|---|---|
| Filter (`mse_use_large_library_mode`) | Consistent with Phase 1 style | Too easy to flip on without running rebuild |
| Admin toggle (Settings page) | Discoverable | Adds UI surface this plugin doesn't currently have; makes rollback a click-away accident |
| Constant `MSE_LARGE_LIBRARY_MODE` in `wp-config.php` | Opt-in, hard to flip accidentally, signals "you know what you're doing" | Requires code access |

### Recommendation: **constant**

```php
// wp-config.php
define( 'MSE_LARGE_LIBRARY_MODE', true );
```

- **Migration on enable:** plugin detects the constant, checks for the table. If table doesn't exist, create it. If empty, fall back to Phase 1 query path and surface the admin notice + `_doing_it_wrong()` described in §6's "Visibility of fallback mode" subsection.
- **Rollback story:** Remove the constant (or set to `false`). The plugin's `posts_clauses` immediately reverts to the Phase 1 LIKE path. The index table stays as dead data — not dropped until uninstall or `wp mse-search-index drop`. This is the cheapest rollback: one line in `wp-config.php`, zero-downtime, no data loss.
- **Version mismatch:** expose a stored `mse_search_index_version` option. On mismatch (plugin upgrade introduced a schema change), fall back to Phase 1 and require a rebuild.

**Multisite scope of the constant.** `MSE_LARGE_LIBRARY_MODE` is defined in `wp-config.php`, so it is **network-wide** by definition — every blog in the network sees the same value. Per-blog opt-in is not a separate constant; it falls out of the rebuild model: a blog whose index is empty (no rebuild ever ran) takes the fallback path on every search, which is functionally equivalent to "large-library mode off for this blog" with the cost of one extra empty-table read per query. Operators with mixed workloads (e.g., blog A = 500k attachments, blog B = 5k) enable the constant network-wide, run `wp --url=blog-a.example.com mse-search-index rebuild`, and skip the rebuild on blog B. If the empty-table read overhead matters for blog B, a future per-site option `mse_large_library_disabled` can short-circuit before touching the index — deferred to v2 unless operators report it as a real cost.

---

## 8. Taxonomy: in or out of the index?

Two candidates:

- **(i) Denormalize into `searchable`.** At write time, fetch all attachment taxonomies, join slug + name + description into the searchable blob.
- **(ii) Keep taxonomy as a separate EXISTS join at query time.** The index handles post/meta fields; taxonomy stays as-is.

### Recommendation: **(i) — denormalize into the row**

Rationale:
- Taxonomy search is explicitly called out in the issue as the heaviest cost. Leaving it out of the index means Phase 3 does not solve the problem it was created for.
- Option (ii) requires joining `term_relationships` + `term_taxonomy` + `terms` on every query — defeating the FULLTEXT win.
- Write churn from `set_object_terms` and `edited_term` is manageable with batching (section 4).

Trade-off accepted: `edited_term` on a popular term triggers many upserts. Queue via Action Scheduler / `wp_schedule_single_event`; document the ops consideration in the README.

---

## 9. Compatibility floor

| Requirement | Minimum | Reason |
|---|---|---|
| MySQL | 5.7 | InnoDB FULLTEXT shipped in 5.6; `WITH PARSER ngram` in 5.7. WordPress itself requires 5.7.21+ from WP 6.6 onward, so this is not a practical regression. |
| MariaDB | 10.0.5 | InnoDB FULLTEXT support. |
| WordPress | matches plugin's existing floor (currently "Requires at least: 3.5" but WP 5.9+ in practice for media modal features) | No new floor required for Phase 3 read/write code. |
| PHP | matches plugin's existing CI matrix (7.4 - 8.3) | No new PHP requirements. |
| Action Scheduler | optional, used only if site opts into admin-initiated rebuild | Soft dependency, fall back to WP-CLI. |

**Do not ship Phase 3 if the server reports `ENGINE=MyISAM` for InnoDB or pre-5.6 MySQL.** The activation check for `MSE_LARGE_LIBRARY_MODE` should verify via `SHOW ENGINES` and `SELECT VERSION()` and admin-notice the operator if the server is too old.

---

## 10. Follow-up issue breakdown

The design above splits cleanly into these issues. Each should be small enough to land in one PR.

1. **Schema + migration: `mse_search_index` table.** Add `includes/class-mse-search-index.php` with `create_table()` (dbDelta-compatible), schema version constant, drop/recreate helper. No hooks yet.
2. **Write path: attachment + meta + taxonomy hooks.** Wire `add_attachment`, `attachment_updated`, `delete_attachment`, `updated_post_meta` (filtered to attachment + tracked keys), `set_object_terms`, `edited_term` to `upsert()` / `delete()`. Handle the fan-out case for `edited_term` via Action Scheduler or chunked `wp_schedule_single_event`.
3. **Read path: `posts_clauses` short-circuit.** When `MSE_LARGE_LIBRARY_MODE` is on and the index is ready (version matches, row count > 0), query the index and inject `ID IN (...)`. Fall back to Phase 1 query path when off, empty, or version-mismatched. Preserves the existing private-post visibility `str_replace`.
4. **WP-CLI rebuild.** `wp mse-search-index rebuild|status|drop` using the progress-row pattern in section 6. Chunked, crash-safe, idempotent.
5. **Feature flag + docs.** `MSE_LARGE_LIBRARY_MODE` constant, admin notice when on-but-empty, README section with enable / rebuild / rollback runbook. Document the `mse_search_fields` semantic shift (write-time vs query-time).
6. **Benchmark harness.** Extend `tests/benchmark/LargeScaleProfilingTest.php` with a companion scenario that seeds the index and measures index-path vs LIKE-path at 5k, 20k, 100k attachments. Log MATCH vs LIKE EXPLAIN side by side.
7. **Rollback + uninstall.** Add `uninstall.php` handling that drops `mse_search_index` and its options. Document flag-removal behavior. Cover WPML / Polylang uninstall and multisite edge cases (section 11).

One optional follow-up:

8. **(Optional) Ngram vs default FULLTEXT parser benchmark.** Decide whether to ship one or two FULLTEXT indexes. Do this before #2 so the schema is stable.

---

## 11. Open questions / risks

These must be resolved before the first Phase 3 PR lands.

- **Multisite (maintainer decision gate).** The sketched schema is redundant: it uses `$wpdb->prefix` (per-blog) **and** a `blog_id` column. Only one of these can be the answer. Per-blog tables with `$wpdb->prefix` are idiomatic WordPress and make per-site uninstall / export trivial; one global table with `blog_id` is cheaper for operators running many small sites and centralises the rebuild. This choice gates the schema, the rebuild command's iteration model, and the uninstall story. **Pick one before follow-up issue #1 opens.** Recommendation leans toward per-blog via `$wpdb->prefix` and dropping the `blog_id` column, since single-site is the overwhelmingly common case and multisite operators already expect per-blog tables. The `MSE_LARGE_LIBRARY_MODE` constant scope and the WP-CLI rebuild signature for multisite are no longer open — see §7 and §6 respectively.
- **WPML vs Polylang.** Both set a post language. Do we write one row per (attachment, language) — which bloats the table by the language count — or one row that includes all language variants? Current sketch assumes WPML-style duplication (one row per translated post), since WPML itself treats translations as separate posts with different IDs. Polylang may need different handling.
- **GDPR / uninstall.** `uninstall.php` currently does nothing. Phase 3 adds: `DROP TABLE {$prefix}mse_search_index; delete_option('mse_search_index_version'); delete_option('mse_search_index_rebuild_state');`. Multisite uninstall must iterate sites.
- **`wp_delete_attachment` during backfill.** The hook already fires in parallel with the rebuild loop. Our `upsert` is idempotent and `get_post` returns null for deleted posts, so the race is self-healing — but worth a test.
- **Term rename fan-out.** Renaming a popular taxonomy term (e.g., 10k attachments) triggers 10k upserts. Without Action Scheduler, this is a 10k-row loop during the admin term-edit request. **Mitigation:** queue via single-event cron; show an admin notice "Reindexing in progress."
- **Schema version bumps.** Adding a column to `searchable` (e.g., including captions per a future `mse_search_fields` change) invalidates every existing row. Need a stored `mse_search_index_version` in `wp_options` and a "stale — rebuild required" state, not silent corruption.
- **Stopwords and short terms.** Even with the `searchable_short` LIKE fallback, a site searching for `"the"` (a stopword) will get zero matches from FULLTEXT and a full-table scan on `searchable_short`. Document as "avoid stopwords; use more specific terms."
- **FULLTEXT relevance ordering.** `ORDER BY MATCH(searchable) AGAINST (?)` gives relevance-ranked results, but the current plugin orders by `post_date DESC`. Large-library mode should preserve `post_date DESC` ordering in the outer `posts_clauses` WHERE — relevance ranking is a separate feature, not a Phase-3 scope creep.
- **Admin filtering (MIME/date/parent).** Verified in section 5: option (a) preserves core's filters, because we only inject `ID IN (...)` into the existing WHERE. Worth an explicit test in follow-up issue #3.
- **`mse_search_fields` semantic shift.** We are redefining the filter's behavior *only when large-library mode is on*. The Phase 1 query-time filter keeps working in the fallback path. This needs very clear docs: "In large-library mode, `mse_search_fields` runs at write time; toggling it requires `wp mse-search-index rebuild`." Failure to communicate this will cause silent "why is my filter not working" bug reports.
- **The attachment ID exact-match contract.** Current plugin treats numeric search as exact `ID = N`. FULLTEXT on `searchable` containing the ID as a token would make `"42"` match any attachment whose description says "42 photos". The read path must preserve the existing numeric-is-exact-ID contract — treat the integer test as a pre-FULLTEXT shortcut that returns `[$id]` if the post exists and is an attachment.

---

## Appendix A: load-bearing unknown verified during exploration

Before writing this document, a throwaway spike (not shipped — kept on a private branch during exploration) verified the single load-bearing unknown:

> `posts_clauses` can inject `AND {$posts}.ID IN (...)` alongside the existing suppress-core-search + private-post visibility widening, without breaking the existing search-fields filter or returning a different set of rows than a LIKE-path query with the same matching IDs would.

The spike layered a second `posts_clauses` filter at priority 50 on top of the plugin's existing priority-20 callback to demonstrate SQL interop in isolation. The production read path (follow-up issue #3) should instead collapse Phase 1's LIKE builder and the index's `ID IN` emission into the same priority-20 method — and re-verify the contract with explicit tests covering the search-fields filter, private-post visibility, and zero-match cases.

Everything else in this document — schema DDL, FULLTEXT parser choice, WP-CLI rebuild, Action Scheduler fan-out — is mechanical once that load-bearing unknown is settled.

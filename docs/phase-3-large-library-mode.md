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

### Recommendation: **candidate (b) with denormalized filter columns and an explicit state field**

Schema (revised after PR #25 review — see "Resolved during review" in §11 for the iteration trail):

```sql
CREATE TABLE {$prefix}mse_search_index (
  attachment_id   BIGINT UNSIGNED NOT NULL,
  language_code   VARCHAR(16)     NOT NULL DEFAULT '',
  searchable      MEDIUMTEXT      NOT NULL,        -- field values joined with a separator
  -- Denormalized filter columns: required so the index query can apply
  -- WP_Query's MIME / date / parent / status filters BEFORE the LIMIT.
  -- Without these, LIMIT 1000 in the index step can drop valid rows that
  -- the outer WP_Query would have kept.
  post_mime_type  VARCHAR(100)    NOT NULL DEFAULT '',
  post_date       DATETIME        NOT NULL,
  post_parent     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  post_status     VARCHAR(20)     NOT NULL DEFAULT 'inherit',
  post_author     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at      DATETIME        NOT NULL,
  PRIMARY KEY (attachment_id, language_code),
  FULLTEXT KEY ft_searchable (searchable) /*!50700 WITH PARSER ngram */,
  KEY ix_filters (post_mime_type, post_date, post_parent, post_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Why this shape:

1. **`searchable` is the main payload**, built at write time from the fields enabled via `mse_search_fields`. Disabled fields are **not written into the index** — this redefines the filter as an indexing-time filter, not a query-time one. Toggling the filter requires a rebuild; see §6 for the schema-version handshake that enforces this.
2. **Denormalized filter columns (`post_mime_type`, `post_date`, `post_parent`, `post_status`, `post_author`)** let the index query apply WP_Query's filters before `LIMIT 1000`. Without them, the LIMIT is applied first and a search that matches 5,000 attachments — only 100 of which are JPGs the user filtered on — could miss most or all of the JPGs. See §5 for the corrected query shape. Trade-off accepted: any change to those columns triggers an `upsert`; in practice they change rarely.
3. **Taxonomy terms and alt text get stuffed into `searchable`** (see §8) so the taxonomy join disappears entirely — the single largest structural win available.
4. **`PRIMARY KEY (attachment_id, language_code)` is composite** to support per-language rows on WPML where translations have separate post IDs but a site that wants per-language indexing still needs the language column distinguished. On non-multilingual sites `language_code = ''` for every row and the composite key collapses to one row per attachment.
5. **No `searchable_short` LIKE-fallback column.** Earlier drafts proposed this but it was wrong on two counts: (a) `LIKE '%term%'` is not sargable on a BTREE prefix index — the BTREE doesn't help — and (b) restricting it to title + filename meant short-token queries would silently miss alt text, taxonomy, and caption matches that the main index does cover. See §3 for the actual short-token strategy (ngram parser + Phase 1 fallback for sub-2-character tokens).
6. The `mse_search_fields` filter retains its current semantics **when large-library mode is off**. When on, it runs at **index write time** instead of query time — an intentional semantic shift, the only honest answer for a FULLTEXT inverted index.

**Per-field disable (`mse_search_fields`) → index-time effect.** Calling `mse_search_fields` with `guid=false` in large-library mode means "when I next write attachment N into the index, do not include its GUID in the `searchable` blob." Operationally: toggling the filter invalidates the index (rebuild required). The plugin should expose `mse_search_index_version()` and compare against a stored version on each query; mismatch → fall back to the Phase 1 query path until rebuild completes.

**Migration UX for sites that already use `mse_search_fields`.** When `MSE_LARGE_LIBRARY_MODE` is enabled on a site that has the filter wired up, the schema version stored in `wp_options` should hash the *active* `mse_search_fields` config at index-build time. On subsequent requests, if the runtime hash diverges from the stored hash, the read path falls back to Phase 1 and an admin notice fires: "`mse_search_fields` configuration changed since the last rebuild. Run `wp mse-search-index rebuild` to apply." This keeps the contract honest — the filter still "works", just with an explicit acknowledgement that the index must be rebuilt to honor the change.

A sibling filter `mse_large_library_search_fields` was considered as an alternative — it would let sites express two different field configs (one for the Phase 1 path, one for the index path) without surprise. **Rejected for v1** because it doubles the configuration surface for a feature most sites won't enable; revisit if real-world usage shows confusion. Tracked as a follow-up consideration rather than a v1 deliverable.

**WPML / Polylang.** The `language_code` column **must be derived from the attachment itself, not from the request context**, so CLI rebuilds and background queue handlers (which have no request language) write the correct value. Sources:

- WPML: `apply_filters( 'wpml_post_language_details', null, $attachment_id )` returns the post's own language; use the `language_code` field of that result.
- Polylang: `pll_get_post_language( $attachment_id, 'slug' )`.
- Sites with neither: `language_code = ''` for every row.

Read-path queries filter on `language_code = ?` where `?` is the **request** language (`ICL_LANGUAGE_CODE` for WPML, `pll_current_language()` for Polylang). The mismatch is intentional: write-time honors the post's identity, read-time honors the user's session.

WPML stores translations as separate posts with separate IDs, so each translation gets its own row keyed by its own `(attachment_id, language_code)` tuple. Polylang stores translations as a single post with associated language metadata, so for Polylang we still write one row per attachment, with the language taken from `pll_get_post_language`.

---

## 3. Short tokens, parser choice, and stopwords

### Short-token strategy

MySQL's InnoDB FULLTEXT default `innodb_ft_min_token_size = 3` drops tokens shorter than 3 characters. For Western text this matters less than it sounds; for CJK and short proper nouns (`"AI"`, `"v2"`, `"UX"`, `"3D"`) it would be a real regression.

**Recommendation: ship the ngram parser (`WITH PARSER ngram`, default `ngram_token_size = 2`).** Under ngram, 2-character tokens — including all 2-character CJK substrings — are tokenized natively. This covers the practical short-token cases without forcing operators to change MySQL server config.

**1-character queries:** unsupported by FULLTEXT regardless of parser. The read path falls back to the Phase 1 LIKE query for **any search whose smallest token is shorter than 2 characters**. This is rare enough in attachment-search workloads that we accept the perf hit when it happens. The earlier `searchable_short` BTREE-prefix column is gone — `LIKE '%x%'` is not sargable on a BTREE, so it would have been a full scan dressed up as an index lookup, and it would have silently dropped alt-text / taxonomy / caption matches that the main `searchable` blob does cover.

### Parser availability per engine

| Engine          | InnoDB FULLTEXT | `WITH PARSER ngram` | Behavior on Phase 3 |
|---|---|---|---|
| MySQL 5.7+      | yes             | yes                 | Full support; schema includes `WITH PARSER ngram`. |
| MariaDB 10.0.5+ | yes             | **no** (not shipped) | Schema migration omits the parser clause; default parser is used. CJK and 2-character Latin tokens degrade — document as a known limitation; CJK-heavy MariaDB sites should stay on Phase 1. |

The schema migration (follow-up issue #1) MUST detect the engine via `SELECT VERSION()` and emit the `WITH PARSER ngram` clause only when the server identifies as MySQL (not `MariaDB` substring). The activation check should also surface a `_doing_it_wrong()` notice on MariaDB to set CJK expectations.

### Stopword list

InnoDB ships a default stopword list including `"a"`, `"the"`, `"is"`, etc. — dropped silently from the index. For attachment search this is mostly fine. Document the behavior and point to `information_schema.INNODB_FT_DEFAULT_STOPWORD` for operators who want to override via `innodb_ft_server_stopword_table`.

### Query shape

The actual query is in §5 (it includes the denormalized filter columns). The relevant point for this section: only one path reads from the index (FULLTEXT), and short-token searches bypass the index entirely by routing back through the Phase 1 LIKE callback.

---

## 4. Write path

The index has to stay consistent with the source of truth in `wp_posts`, `wp_postmeta`, and the taxonomy tables. Hooks:

| Hook | When | What the index does |
|---|---|---|
| `add_attachment` (int $post_id) | New attachment inserted | `upsert($post_id)` — compose `searchable` blob from enabled fields, capture denormalized filter columns (`post_mime_type`, `post_date`, `post_parent`, `post_status`, `post_author`), write row |
| `attachment_updated` (int $post_id, WP_Post $after, WP_Post $before) | Title/content/excerpt/guid/mime/date/parent changed | `upsert($post_id)` — covers most filter-column changes since they go through `wp_update_post` |
| `transition_post_status` ($new, $old, WP_Post $post) | Attachment moves between `inherit` / `private` / etc. | If `$post->post_type === 'attachment'` and `$new !== $old`, `upsert($post->ID)` to refresh the denormalized `post_status` column |
| `delete_attachment` (int $post_id) | Attachment hard-deleted | `DELETE FROM mse_search_index WHERE attachment_id = $post_id` (cascades across all language rows via the composite PK) |
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

Same bootstrap as current plugin: `posts_search` returns empty to suppress core's LIKE fragment, `posts_clauses` appends our conditions. In large-library mode, `posts_clauses` instead queries `mse_search_index` — applying the **same** WP_Query filters denormalized into the index — collects matching `attachment_id`s, and appends `AND {$posts}.ID IN (1,2,3,...)`.

The denormalization is the load-bearing correctness fix: the LIMIT in the index query is meaningful only because the filters have already been applied. If we did `SELECT id ... MATCH ... LIMIT 1000` and then let WP_Query filter the resulting 1,000 IDs, a search matching 5,000 attachments — only 100 of which are JPGs the user is filtering on — would silently miss most or all of the JPGs.

```sql
-- Index query, parameterized from WP_Query's $query_vars at call time.
-- Filter clauses are conditional — only included when WP_Query has them set.
SELECT attachment_id
FROM {$prefix}mse_search_index
WHERE language_code = ?
  AND MATCH(searchable) AGAINST (? IN BOOLEAN MODE)
  -- visibility: anonymous + non-author users see only inherit;
  -- read_private_posts users see inherit + private; logged-in non-cap
  -- users see inherit + their own private rows
  AND post_status IN (?, ?)
  AND ( post_status = 'inherit' OR post_author = ? )
  -- conditional filters, injected only when set on WP_Query
  AND post_mime_type IN (?, ?, ?)   -- if 'post_mime_type' set
  AND post_date BETWEEN ? AND ?     -- if date_query set
  AND post_parent = ?               -- if 'post_parent' set
ORDER BY post_date DESC
LIMIT 1000;
```

Then the PHP wrapper:

```php
// Large-library branch of posts_clauses.
$ids = $index->query( $terms, $language, $query->query_vars );
if ( empty( $ids ) ) {
    $pieces['where'] .= ' AND 1=0';
    return $pieces;
}
$ids_sql = implode( ',', array_map( 'absint', $ids ) );
$pieces['where'] .= " AND {$wpdb->posts}.ID IN ( $ids_sql )";
return $pieces;
```

WP core's `posts_clauses` still applies `post_status`, `post_type`, `post_mime_type`, `post_parent`, date filters, `read_private_posts` visibility on top. Those filters now run as harmless duplicates (the index already enforced them); the existing `str_replace` that widens `post_status = 'inherit'` to include `'private'` **still runs**, because we stay in `posts_clauses`. That is the single biggest correctness win of option (a).

**Result cap.** `LIMIT 1000` is a true cap on filtered+ordered results — operators see "the first 1,000 matching all filters by `post_date DESC`." The cap is filterable via `mse_search_index_limit`. If the index query returns exactly the cap, the read path emits `_doing_it_wrong()` once per request: "Search exceeded the result cap; narrow with MIME / date / parent filters or raise `mse_search_index_limit`." Without the cap warning the operator has no signal that they may be missing matches.

Cursor-based pagination (passing the last-seen `attachment_id` or last-seen `post_date` to subsequent queries) was considered and **deferred** for v1. It would require replacing `WP_Query` (rejected as option (b) below) or layering an extra round-trip per pagination click; not justified for a feature whose primary win is search latency, not result-set depth. Tracked as a future enhancement only if real-world feedback shows the cap is a frequent blocker.

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

### Index state machine

A boolean "is the index built" check is not safe: a partially populated table can satisfy "row count > 0" while still returning incomplete search results during backfill. The read path needs an explicit state field, not an inference from row count.

Stored in `wp_options` as `mse_search_index_state`, with values:

| State      | Meaning                                                                 | Read path |
|---|---|---|
| `empty`    | Default after table creation. No rebuild has run.                       | Fall back to Phase 1. |
| `building` | Rebuild in progress (or crashed mid-rebuild — same observed state).     | Fall back to Phase 1. |
| `ready`    | Rebuild completed successfully and the table is consistent with `wp_posts`. | Use the index. |
| `stale`    | Schema-version hash diverged (e.g., `mse_search_fields` config changed since the last rebuild). | Fall back to Phase 1, surface admin notice. |

Transitions:

- `wp mse-search-index rebuild` flips `empty` → `building` at start, `building` → `ready` on successful completion. If it crashes mid-rebuild, the state stays `building` and the next read falls back to Phase 1 — no partial-results window.
- A re-run of `rebuild` from a `building` state resumes from the stored `last_id` (the rebuild loop is idempotent — see §6 below).
- Schema-version mismatch (`mse_search_fields` config hash diverges, or plugin upgrade bumped the schema) flips `ready` → `stale`. The next successful rebuild flips it back to `ready`.
- `wp mse-search-index drop` returns the state to `empty` and truncates the table.

Optional v2 upgrade: shadow-table swap. Rebuild writes into `mse_search_index_new`, then atomically renames `mse_search_index → mse_search_index_old` and `mse_search_index_new → mse_search_index`. This eliminates the read-path-fallback window entirely. Deferred from v1 because the state-flag approach is simpler and the fallback window is an acceptable degradation, not a correctness bug.

### Post-install behavior

When `MSE_LARGE_LIBRARY_MODE` is first defined the schema migration runs and the state is `empty`. The read path falls back to the Phase 1 LIKE query, surfaces the admin notice from §6's "Visibility of fallback mode" subsection, and waits for `wp mse-search-index rebuild`. The site is functional throughout — fallback semantics are exactly Phase 1.

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

| Requirement | Minimum | Notes |
|---|---|---|
| MySQL | 5.7 | InnoDB FULLTEXT shipped in 5.6; `WITH PARSER ngram` in 5.7. The schema migration emits `WITH PARSER ngram` only on MySQL. |
| MariaDB | 10.0.5 | InnoDB FULLTEXT supported, but **MariaDB does not ship the InnoDB ngram parser** at any version. The schema migration omits `WITH PARSER ngram` on MariaDB; the FULLTEXT index uses the default parser. CJK and 2-character Latin tokens degrade — the activation notice should warn CJK-heavy MariaDB sites that Phase 3 results may be incomplete and recommend staying on Phase 1 for those workloads. |
| WordPress | matches plugin's existing floor (currently "Requires at least: 3.5" but WP 5.9+ in practice for media modal features) | No new floor required for Phase 3 read/write code. |
| PHP | matches plugin's existing CI matrix (7.4 - 8.3) | No new PHP requirements. |
| Action Scheduler | optional, used only if site opts into admin-initiated rebuild | Soft dependency, fall back to WP-CLI. |

**Activation guard.** On `MSE_LARGE_LIBRARY_MODE` first-load the plugin must verify via `SHOW ENGINES` and `SELECT VERSION()`:

- InnoDB present and is the default engine → continue.
- MySQL 5.7+ → emit schema with `WITH PARSER ngram`.
- MariaDB any version → emit schema without parser clause, raise a CJK-degradation admin notice.
- Pre-5.6 MySQL or no InnoDB → refuse to activate large-library mode; admin notice with upgrade guidance; fallback to Phase 1 stays in effect.

---

## 10. Follow-up issue breakdown

The design above splits cleanly into these issues. Each should be small enough to land in one PR.

1. **Schema + migration: `mse_search_index` table.** Add `includes/class-mse-search-index.php` with `create_table()` (dbDelta-compatible), schema version constant, drop/recreate helper. No hooks yet.
2. **Write path: attachment + meta + taxonomy hooks.** Wire `add_attachment`, `attachment_updated`, `delete_attachment`, `updated_post_meta` (filtered to attachment + tracked keys), `set_object_terms`, `edited_term` to `upsert()` / `delete()`. Handle the fan-out case for `edited_term` via Action Scheduler or chunked `wp_schedule_single_event`.
3. **Read path: `posts_clauses` short-circuit.** When `MSE_LARGE_LIBRARY_MODE` is on **and `mse_search_index_state === 'ready'`** (per §6's state machine — never an inferred check from row count), query the index and inject `ID IN (...)`. Fall back to Phase 1 query path for any other state (`empty`, `building`, `stale`) and for searches whose smallest token is shorter than 2 characters. Preserves the existing private-post visibility `str_replace`. Includes the denormalized filter clauses (`post_status` / `post_mime_type` / `post_date` / `post_parent`) inside the index query so `LIMIT 1000` is correctness-preserving.
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
- **GDPR / uninstall.** `uninstall.php` currently does nothing. Phase 3 adds: `DROP TABLE {$prefix}mse_search_index; delete_option('mse_search_index_version'); delete_option('mse_search_index_state'); delete_option('mse_search_index_rebuild_state');`. Multisite uninstall must iterate sites.
- **`wp_delete_attachment` during backfill.** The hook already fires in parallel with the rebuild loop. Our `upsert` is idempotent and `get_post` returns null for deleted posts, so the race is self-healing — but worth a test.
- **Term rename fan-out.** Renaming a popular taxonomy term (e.g., 10k attachments) triggers 10k upserts. Specified mitigation: see §4 — queue via Action Scheduler / `wp_schedule_single_event` when `wp_term_taxonomy.count >= 100`.
- **Schema version bumps.** Adding a column to the schema (or changing how `searchable` is composed) invalidates every existing row. Tracked via `mse_search_index_version` in `wp_options`; on mismatch the state machine flips `ready → stale` and the read path falls back until rebuild.
- **Stopwords.** A site searching for `"the"` (a default InnoDB stopword) gets zero matches from FULLTEXT. There is no separate fallback column anymore (see §3 — `searchable_short` was dropped); these queries return zero from the index and fall back semantics are not triggered for stopwords specifically because the search itself is non-empty. Document as "avoid stopwords; use more specific terms," and consider exposing a filter to seed `innodb_ft_server_stopword_table` with an empty list for sites that want stopwords searchable.
- **FULLTEXT relevance ordering.** `ORDER BY MATCH(searchable) AGAINST (?)` gives relevance-ranked results, but the current plugin orders by `post_date DESC`. The §5 query already preserves `post_date DESC`. Relevance ranking is a separate feature, not Phase-3 scope creep.
- **`mse_search_fields` semantic shift.** We are redefining the filter's behavior *only when large-library mode is on*. The Phase 1 query-time filter keeps working in the fallback path. The schema-version hash + state machine catches changes (`ready → stale`); the docs must still call this out: "In large-library mode, `mse_search_fields` runs at write time; toggling it requires `wp mse-search-index rebuild`." Failure to communicate this will cause silent "why is my filter not working" bug reports.
- **The attachment ID exact-match contract.** Current plugin treats numeric search as exact `ID = N`. FULLTEXT on `searchable` containing the ID as a token would make `"42"` match any attachment whose description says "42 photos". The read path must preserve the existing numeric-is-exact-ID contract — treat the integer test as a pre-FULLTEXT shortcut that returns `[$id]` if the post exists and is an attachment.

### Resolved during review (kept here as a paper trail)

- ~~WPML vs Polylang one-row-per-language~~ — answered in §2 / §4: `PRIMARY KEY (attachment_id, language_code)`, language code derived from the post itself (not the request) at write time. WPML stores translations as separate posts so each gets its own row; Polylang stores one post per language so we still write one row per attachment with that post's language.
- ~~`searchable_short` BTREE-prefix LIKE fallback for short tokens~~ — removed in §3 (the BTREE doesn't help `LIKE '%x%'`, and restricting it to title + filename silently dropped alt / taxonomy / caption matches). Replaced with: ngram parser handles 2+ characters natively, 1-character queries fall back to Phase 1.
- ~~`LIMIT 1000` correctness~~ — fixed in §2 / §5 by denormalizing `post_mime_type` / `post_date` / `post_parent` / `post_status` / `post_author` into the index so filters apply before the LIMIT.
- ~~"index is ready" defined as version-match + row-count~~ — replaced in §6 with an explicit `empty | building | ready | stale` state machine. Read path requires `ready`.

---

## Appendix A: load-bearing unknown verified during exploration

Before writing this document, a throwaway spike (not shipped — kept on a private branch during exploration) verified the single load-bearing unknown:

> `posts_clauses` can inject `AND {$posts}.ID IN (...)` alongside the existing suppress-core-search + private-post visibility widening, without breaking the existing search-fields filter or returning a different set of rows than a LIKE-path query with the same matching IDs would.

The spike layered a second `posts_clauses` filter at priority 50 on top of the plugin's existing priority-20 callback to demonstrate SQL interop in isolation. The production read path (follow-up issue #3) should instead collapse Phase 1's LIKE builder and the index's `ID IN` emission into the same priority-20 method — and re-verify the contract with explicit tests covering the search-fields filter, private-post visibility, and zero-match cases.

Everything else in this document — schema DDL, FULLTEXT parser choice, WP-CLI rebuild, Action Scheduler fan-out — is mechanical once that load-bearing unknown is settled.

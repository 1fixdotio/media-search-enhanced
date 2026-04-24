# Phase 2: composite `wp_postmeta` index benchmarks

Issue [#22](https://github.com/1fixdotio/media-search-enhanced/issues/22) Phase 2
asks whether a composite index on `wp_postmeta` would speed up the correlated
`EXISTS` subqueries this plugin emits for alt text and filename lookups. This
document records the empirical data and the recommendation.

## TL;DR

**A composite index is not recommended.** At 5k and 20k attachments, on MySQL
8.0, the candidate index is neutral-to-slightly-worse across every scenario we
tested. The EXISTS subqueries are already cheap (1-2 row lookups) because the
stock `wp_postmeta.post_id` index handles the correlation, and the leading-wildcard
`LIKE '%term%'` is non-sargable regardless of index shape. The performance
ceiling is elsewhere (outer `wp_posts` scan, taxonomy joins) and Phase 3
(denormalized search index) is the right next step.

## Candidate tested

A single composite:

```sql
ALTER TABLE wp_postmeta ADD INDEX mse_phase2_idx_meta_key_post_id_value
    (meta_key, post_id, meta_value(191));
```

Rationale: the EXISTS subqueries have the shape
`WHERE post_id = X AND meta_key = 'K' AND meta_value LIKE '%term%'`. The leading
wildcard makes the `LIKE` non-sargable, so an index can only help the equality
portion. A `(meta_key, post_id)` prefix satisfies both equalities, and trailing
`meta_value(191)` keeps the non-sargable `LIKE` inside the index leaves so
InnoDB doesn't have to visit the clustered PK.

### What we deliberately did not test

- `(meta_key, meta_value(191))` — drops the `post_id` correlation column,
  which is exactly the part the optimizer currently uses most effectively
  (see EXPLAIN below). Strictly worse on paper, skipped.
- `(post_id, meta_key, meta_value(191))` — duplicates the existing stock
  `post_id` single-column index; prefix leads with the same column.

## How to reproduce

The benchmark harness is `tests::LargeScaleProfilingTest::test_phase2_composite_index_comparison`.
It runs under the same `--group slow` / `bin/profile.sh` path as the existing
one-pass profiling test, shares its seeded fixtures, and creates + drops the
candidate index inside a single test method (with a `register_shutdown_function`
guard so a fatal still drops the index).

```bash
bin/profile.sh 5000  2>&1 | tee /tmp/phase2-profile-5k.txt
bin/profile.sh 20000 2>&1 | tee /tmp/phase2-profile-20k.txt
```

### Methodology

- Each scenario runs **3 measured passes** after a discarded warmup pass on the
  same schema state (so buffer-pool warmth is comparable before vs. after).
- Between passes, `wp_cache_flush()` empties the WP object cache and
  `WP_Query` is called with `cache_results => false` so the measurements hit
  MySQL instead of returning from WP's result cache.
- Wall-clock times reported as min / median across the 3 runs.
- InnoDB `FLUSH TABLES` is **not** used — it isn't representative of a
  production host's hot-cache state.

## Results

### 5,000 attachments (`bin/profile.sh 5000`)

Captured to `/tmp/phase2-profile-5k.txt`.

| scenario      | before\_min | before\_med | after\_min | after\_med | delta\_med |
|---------------|------------:|------------:|-----------:|-----------:|-----------:|
| zero-match    |      0.1132 |      0.1137 |     0.1158 |     0.1169 |    +0.0032 |
| broad-title   |      0.0078 |      0.0080 |     0.0078 |     0.0082 |    +0.0002 |
| title-only    |      0.1135 |      0.1136 |     0.1139 |     0.1141 |    +0.0005 |
| alt-only      |      0.1130 |      0.1144 |     0.1141 |     0.1162 |    +0.0017 |
| filename-only |      0.1135 |      0.1140 |     0.1140 |     0.1145 |    +0.0004 |
| taxonomy-only |      0.1154 |      0.1163 |     0.1163 |     0.1170 |    +0.0007 |
| multi-term    |      0.2221 |      0.2232 |     0.2220 |     0.2222 |    -0.0010 |

EXPLAIN for the two `wp_postmeta` subqueries (representative — same for every
scenario):

```
BEFORE
  DEPENDENT SUBQUERY  wptests_postmeta  key=post_id                              rows=1  filtered=5.55  Using where
  DEPENDENT SUBQUERY  wptests_postmeta  key=post_id                              rows=1  filtered=5.55  Using where

AFTER
  DEPENDENT SUBQUERY  wptests_postmeta  key=mse_phase2_idx_meta_key_post_id_value  rows=1  filtered=11.11 Using where
  DEPENDENT SUBQUERY  wptests_postmeta  key=mse_phase2_idx_meta_key_post_id_value  rows=1  filtered=11.11 Using where
```

### 20,000 attachments (`bin/profile.sh 20000`)

Captured to `/tmp/phase2-profile-20k.txt`.

| scenario      | before\_min | before\_med | after\_min | after\_med | delta\_med |
|---------------|------------:|------------:|-----------:|-----------:|-----------:|
| zero-match    |      0.4546 |      0.4560 |     0.4558 |     0.4582 |    +0.0022 |
| broad-title   |      0.0261 |      0.0261 |     0.0263 |     0.0273 |    +0.0012 |
| title-only    |      0.4562 |      0.4564 |     0.4534 |     0.4550 |    -0.0014 |
| alt-only      |      0.4537 |      0.4542 |     0.4545 |     0.4566 |    +0.0024 |
| filename-only |      0.4548 |      0.4575 |     0.4562 |     0.4566 |    -0.0009 |
| taxonomy-only |      0.4652 |      0.4652 |     0.4663 |     0.4690 |    +0.0037 |
| multi-term    |      0.8920 |      0.8951 |     0.8962 |     0.8967 |    +0.0015 |

Same EXPLAIN shift for the `wp_postmeta` subqueries as at 5k:

```
BEFORE
  DEPENDENT SUBQUERY  wptests_postmeta  key=post_id                              rows=2  filtered=5.56  Using where
  DEPENDENT SUBQUERY  wptests_postmeta  key=post_id                              rows=2  filtered=5.56  Using where

AFTER
  DEPENDENT SUBQUERY  wptests_postmeta  key=mse_phase2_idx_meta_key_post_id_value  rows=1  filtered=11.11 Using where
  DEPENDENT SUBQUERY  wptests_postmeta  key=mse_phase2_idx_meta_key_post_id_value  rows=1  filtered=11.11 Using where
```

The outer `wp_posts` access stays on `type_status_date` with
`rows=9,844, filtered=100.00`. Taxonomy subquery access is unchanged.

## Interpretation

- **Plan switch is real.** When the composite exists, MySQL picks it over
  `post_id` for both EXISTS subqueries. The change is stable across scales —
  no plan flip between 5k and 20k — so this isn't an optimizer corner case
  that resolves itself at higher volume.
- **Savings from the plan switch are not real.** The stock `post_id` index
  already gives `rows=1-2`, and the composite gives `rows=1`. That's a
  1-to-2-row difference per subquery execution. At 20k attachments with 9,844
  posts matching the outer `type_status_date` ref lookup, the subquery is
  executed per outer row, but each execution is already bound to the same
  tiny cost either way. There's nothing meaningful left to save at the meta
  level.
- **Where the time actually goes.** At 20k, the ~0.45s for a single-result
  scenario is dominated by the outer scan over all attachments (9,844 rows ×
  whatever per-row subquery cost), not by the postmeta lookups themselves.
  The `LIKE '%..%'` on `post_title`, `post_content`, `post_excerpt`, `guid`
  cannot use any index regardless of what we do to `postmeta`.
- **Variance is comparable to the deltas.** The largest regression observed
  (+0.0127s on multi-term at 20k in an earlier isolated run, +0.0015s in the
  full run) is within the noise floor at n=3. Don't read "the composite
  makes things slower" into this — read it as "no meaningful movement in
  either direction."
- **Why the task brief's "rows ≈ 500, filtered = 1.11%" doesn't show up here.**
  That plan is the EXPLAIN for the correctness-test fixture, which has only
  a handful of attachments per term. At 5k+ the optimizer has enough stats
  to prefer the tighter `post_id` correlation path. So the "500 rows scanned
  per subquery" observation was specific to tiny fixtures and is not the
  scaling behavior we need to optimize for.

## Per-scenario summary

| scenario      | does the index help? | notes                                                                 |
|---------------|----------------------|-----------------------------------------------------------------------|
| zero-match    | no                   | Outer scan dominates; postmeta probe is already cheap.                |
| broad-title   | no                   | Short-circuits on `post_title LIKE` before postmeta even runs.        |
| title-only    | no (noise)           | Same as above — postmeta is not on the hot path.                      |
| alt-only      | no                   | The one scenario where postmeta matters most; no measurable win.      |
| filename-only | no                   | Same as alt-only.                                                     |
| taxonomy-only | no                   | Taxonomy joins dominate; index doesn't touch them.                    |
| multi-term    | no                   | Doubles the EXISTS count but still bounded by the single outer scan.  |

No scenario shows a consistent win. No scenario shows a large regression.

## Recommendation

### Should a composite `wp_postmeta` index be added?

**No**, not as a general recommendation, and not as something the plugin
should create on activation. The measured improvement is within noise and
the index carries real costs on production sites:

- index write amplification on every `update_post_meta` (every attachment
  edit, media-library scroll triggering regenerated thumbnails, etc.),
- buffer-pool pressure — `wp_postmeta` is one of the largest tables on most
  sites,
- schema ownership boundaries — sites with managed hosting (WP Engine,
  Pantheon, Kinsta) typically do not accept plugin-initiated DDL on core
  tables, and a plugin silently changing `wp_postmeta` structure would be
  rejected by any competent DBA review.

### Does the guidance belong in `README.md` or developer docs?

**Developer docs only.** Rationale:

- `README.md` / `README.txt` are read by site owners deciding whether to
  install the plugin. A DBA-level discussion of `wp_postmeta` indexes on
  that page invites cargo-cult additions of the index (with the costs
  above) on sites where it will not measurably help.
- Phase 2's open question in issue #22 is "Decide whether any index
  guidance belongs in the main README." The empirical answer is that
  there isn't guidance worth giving yet: "we tried it, it didn't help."
  That belongs in this file, not in the README.
- If Phase 3 (denormalized search table) lands, *that* table's indexes
  are the plugin's own and the plugin can create them freely — at which
  point the README discussion is about turning that feature on, not
  about modifying core WordPress tables.

If a future contributor wants to revisit this for a specific workload
(e.g. a site with millions of attachments where the outer scan is no
longer the bottleneck), the harness in
`tests/benchmark/LargeScaleProfilingTest.php::test_phase2_composite_index_comparison`
is the starting point — add a new candidate index shape, rerun, compare.

### If a recommendation were ever added to README

This is the snippet the maintainer could drop into `README.md` if future
data changes the conclusion. **Do not merge this into `README.md` now** —
the current data does not support it.

~~~markdown
### Optional: composite index on `wp_postmeta` (advanced)

On very large libraries (hundreds of thousands of attachments), a DBA may
evaluate adding a composite index to `wp_postmeta` to reduce the cost of
the plugin's alt-text and filename EXISTS subqueries:

```sql
ALTER TABLE wp_postmeta ADD INDEX mse_meta_key_post_value
    (meta_key, post_id, meta_value(191));
```

This is **not recommended by default**. At 5k and 20k attachments on MySQL
8.0 we observed no measurable improvement; the existing `wp_postmeta.post_id`
index already handles the correlated lookup. Evaluate with
`bin/profile.sh` against your own data before deploying.
~~~

## Follow-ups out of scope for this doc

- A future `MSE_CLI` subcommand that creates the candidate index on demand
  and then `EXPLAIN ANALYZE`s a representative query before/after — nice
  for site-operators who want their own numbers but **not** something the
  plugin should do without explicit operator action. Tracked in issue #22
  as a Phase 2 follow-up suggestion.
- Phase 3: a plugin-owned denormalized search table keyed by attachment
  ID. The data above makes this the more promising direction: it
  eliminates the `LIKE '%..%'` on `wp_posts.post_title` etc., which is
  where the actual scaling pain is.

# Media Search Enhanced

A WordPress plugin that extends the Media Library search to cover all fields: ID, title, caption, alt text, description, filename, GUID, and taxonomy terms.

For WordPress.org plugin details, see [README.txt](README.txt).

## Development

### Prerequisites

- PHP 7.4+
- MySQL 8.0 (or compatible)
- Composer

### Setup

```bash
composer install
bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
```

### Test commands

| Command | What it runs |
|---|---|
| `composer test` | 17 correctness tests + 5 benchmark structural tests (fast, ~3s) |
| `composer test:benchmark` | Only the 5 benchmark structural tests |
| `composer test:profile` | Large-scale profiling with 5,000 attachments (slow) |
| `composer test:profile -- 20000` | Profiling with custom attachment count |

CI runs `composer test` on every push/PR across PHP 7.4-8.3 with MySQL 8.0.

## Validating SQL performance changes

When working on query changes (e.g., [#10](https://github.com/1fixdotio/media-search-enhanced/issues/10)), use these three layers to validate correctness and performance.

### Layer 1: Correctness tests (automated)

```bash
composer test
```

The 17 correctness tests in `tests/SearchTest.php` verify that search results are correct across all fields (title, alt text, filename, ID, GUID, taxonomy, etc.) and all filters (MIME type, date, post parent). These must pass before and after any refactoring.

If a test fails after your change, a search field or filter is broken.

### Layer 2: Query structure assertions (automated)

The benchmark tests in `tests/benchmark/QueryStructureTest.php` assert on the **generated SQL string**, not on results. They document the expected query shape.

**Current assertions (pre-#10):**

| Test | Asserts |
|---|---|
| `test_current_query_uses_distinct` | SQL contains `DISTINCT` |
| `test_current_query_uses_left_join_postmeta` | SQL contains `LEFT JOIN ... postmeta AS mse_pm` |
| `test_current_query_does_not_use_exists` | SQL does **not** contain `EXISTS` |
| `test_current_query_uses_id_like` | SQL uses `ID LIKE` (string comparison) |

**How to update for #10:**

When implementing each phase of #10, flip the relevant assertions:

- **Phase 1** (EXISTS subqueries): Change to assert `EXISTS` present, `DISTINCT` absent, `LEFT JOIN postmeta` absent
- **Phase 2** (ID integer match): Change to assert `ID =` instead of `ID LIKE`
- **Phase 3** (multi-term search): Add new assertions for comma-separated OR groups

If the structural tests pass, the query is shaped as intended.

### Layer 3: EXPLAIN and timing (manual comparison)

Run profiling **before and after** your changes to compare:

```bash
# 1. On the base branch, run profiling and save output
git checkout master
composer test:profile -- 20000 2>&1 | tee /tmp/profile-before.txt

# 2. On your feature branch, run the same
git checkout your-branch
composer test:profile -- 20000 2>&1 | tee /tmp/profile-after.txt

# 3. Compare
diff /tmp/profile-before.txt /tmp/profile-after.txt
```

The profiling output includes:

- **Query time** — wall-clock execution time (run multiple times to reduce noise)
- **SQL** — the full generated query (verify it matches expectations)
- **EXPLAIN output** — MySQL's query execution plan

**What to look for in EXPLAIN:**

| Column | Before (JOINs + DISTINCT) | After (EXISTS) |
|---|---|---|
| Extra | `Using temporary; Using filesort` | Neither present |
| select_type | `SIMPLE` (flat join) | `DEPENDENT SUBQUERY` |
| rows | High (row multiplication from JOINs) | Lower (EXISTS short-circuits) |

The `Using temporary` disappears because `DISTINCT` is no longer needed. This is the single biggest performance indicator.

### PR checklist for SQL changes

1. `composer test` passes (correctness + structure)
2. Benchmark assertions updated to reflect the new expected query shape
3. Profiling output compared before/after showing improved EXPLAIN plan
4. Profiling timing compared at 20k+ attachments showing improvement (or at minimum no regression)

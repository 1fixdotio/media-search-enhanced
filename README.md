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
| `composer test` | Correctness tests + benchmark structural tests (fast, ~3-4s) |
| `composer test:benchmark` | Only the benchmark structural tests |
| `composer test:profile` | Large-scale profiling with 5,000 attachments (slow) |
| `composer test:profile -- 20000` | Profiling with custom attachment count |

CI runs `composer test` on every push/PR across PHP 7.4-8.3 with MySQL 8.0.

## Validating SQL performance changes

When working on query changes (e.g., [#10](https://github.com/1fixdotio/media-search-enhanced/issues/10)), use these three layers to validate correctness and performance.

### Layer 1: Correctness tests (automated)

```bash
composer test
```

The correctness tests in `tests/SearchTest.php` verify that search results are correct across all fields (title, alt text, filename, ID, GUID, taxonomy, etc.), all filters (MIME type, date, post parent), multi-term comma search, and private attachment visibility. These must pass before and after any refactoring.

If a test fails after your change, a search field or filter is broken.

### Layer 2: Query structure assertions (automated)

The benchmark tests in `tests/benchmark/QueryStructureTest.php` assert on the **generated SQL string**, not on results. They document the expected query shape.

**Current assertions:**

| Test | Asserts |
|---|---|
| `test_query_does_not_use_distinct` | SQL does **not** contain `DISTINCT` |
| `test_query_does_not_use_left_join_postmeta` | SQL does **not** contain `LEFT JOIN ... postmeta AS mse_pm` |
| `test_query_uses_exists_subqueries` | SQL contains `EXISTS` with correct correlation structure |
| `test_numeric_search_uses_id_equals` | SQL uses `ID =` (integer comparison) |
| `test_non_numeric_search_skips_id_match` | SQL uses neither `ID LIKE` nor `ID =` for text searches |
| `test_multi_term_search_generates_or_groups` | Multi-term SQL contains both terms with multiple EXISTS groups |

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

| Column | What to check |
|---|---|
| Extra | Should **not** contain `Using temporary` (indicates DISTINCT overhead) |
| select_type | `DEPENDENT SUBQUERY` rows indicate EXISTS subqueries are in use |
| key | Should show index usage (e.g. `type_status_date`, `meta_key`, `PRIMARY`) |

### PR checklist for SQL changes

1. `composer test` passes (correctness + structure)
2. Benchmark assertions updated to reflect the new expected query shape
3. Profiling output compared before/after showing improved EXPLAIN plan
4. Profiling timing compared at 20k+ attachments showing improvement (or at minimum no regression)

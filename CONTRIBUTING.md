# Contributing to Media Search Enhanced

## Running Tests

1. Install Composer dependencies: `composer install`
2. Set up the WordPress test database:
   ```bash
   bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
   ```
3. Run the test suite: `composer test`

### Available test commands

| Command | Description |
|---|---|
| `composer test` | All correctness + benchmark tests |
| `composer test:benchmark` | Benchmark structural tests only |
| `composer test:profile` | Large-scale profiling (5,000 attachments) |
| `composer test:profile -- 20000` | Profiling with custom attachment count |

## Local Performance Profiling

The profiling test seeds a configurable number of attachments, runs a search query, and logs the SQL, EXPLAIN output, and timing to stderr.

```bash
composer test:profile            # default 5,000 attachments
composer test:profile -- 20000   # custom count
```

This is excluded from CI and default test runs due to the time required to seed the database.

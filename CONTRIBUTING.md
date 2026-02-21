# Contributing to Media Search Enhanced

Thank you for your interest in contributing to Media Search Enhanced!

## Development Environment
This project uses `wp-env` to provide a local WordPress environment for development and testing.

### Setup
1.  Ensure you have Node.js and Docker installed.
2.  Install dependencies:
    ```bash
    npm install
    ```
3.  Start the development environment:
    ```bash
    npm run env:start
    ```
    This command starts the environment with SPX profiling enabled and configures the container to persist benchmark data to the local `benchmarks/` directory.

4.  Visit `http://localhost:8888` to access the site.
5.  Visit `http://localhost:8888/?SPX_KEY=dev&SPX_UI_URI=/` to access the SPX profiler.

### Benchmarking
We encourage benchmarking changes, especially those affecting performance.
However, **DO NOT commit benchmark data files** (in `benchmarks/`) to the repository. These files may contain sensitive information such as file paths, database queries, and execution traces.
The `benchmarks/` directory is ignored by git for this reason.

**Security Warning:**
SPX profiling exposes detailed execution data. Ensure you do not share raw profile files publicly unless you have sanitized them. The `benchmarks/` directory must never be deployed to production environments.

### Stopping the Environment
To stop the environment:
```bash
npm run env:stop
```

#!/usr/bin/env bash
#
# Run large-scale profiling tests.
#
# Usage: bin/profile.sh [count]
#   count - Number of attachments to seed (default: 5000)
#
# Examples:
#   bin/profile.sh          # 5,000 attachments
#   bin/profile.sh 20000    # 20,000 attachments

MSE_PROFILE_COUNT="${1:-5000}" exec vendor/bin/phpunit --group slow

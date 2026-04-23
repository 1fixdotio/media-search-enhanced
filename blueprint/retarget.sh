#!/usr/bin/env bash
#
# Rewrites the GitHub ref used by the Blueprint demo files so the Blueprint
# can be tested from a feature branch before it's merged to master.
#
# Both playground.blueprint.json and seed-media.php hardcode
#   raw.githubusercontent.com/1fixdotio/media-search-enhanced/<ref>/blueprint/...
# URLs. This script swaps <ref> to any git ref (branch name, tag, commit).
#
# Usage:
#   ./blueprint/retarget.sh <git-ref>           # swap master -> <ref>
#   ./blueprint/retarget.sh master              # swap back before merging
#
# Example workflow:
#   git checkout -b demo-tweaks
#   # ...edit blueprint/ files...
#   ./blueprint/retarget.sh demo-tweaks
#   git commit -am "test: retarget Blueprint to demo-tweaks" && git push
#   # open the printed Playground URL, verify
#   ./blueprint/retarget.sh master
#   git commit -am "test: retarget Blueprint back to master" && git push
#   # open PR

set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <git-ref>" >&2
  exit 2
fi

REF="$1"
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

BLUEPRINT="${REPO_ROOT}/blueprint/playground.blueprint.json"
SEED_PHP="${REPO_ROOT}/blueprint/seed/seed-media.php"

for f in "$BLUEPRINT" "$SEED_PHP"; do
  if [[ ! -f "$f" ]]; then
    echo "Missing file: $f" >&2
    exit 1
  fi
done

# 1. raw.githubusercontent.com/<owner>/<repo>/<any-ref>/blueprint/...
#    → swap <any-ref> to $REF. Affects both playground.blueprint.json and seed-media.php.
URL_PATTERN='s|(raw\.githubusercontent\.com/[^/]+/[^/]+/)[^/]+(/blueprint)|\1'"${REF}"'\2|g'
sed -i.bak -E "$URL_PATTERN" "$BLUEPRINT" "$SEED_PHP"

# 2. "ref": "<any>" inside the installPlugin git:directory resource in the Blueprint.
#    This is what tells Playground which branch to clone the plugin source from.
REF_PATTERN='s|("ref"[[:space:]]*:[[:space:]]*")[^"]*(")|\1'"${REF}"'\2|g'
sed -i.bak -E "$REF_PATTERN" "$BLUEPRINT"

rm -f "${BLUEPRINT}.bak" "${SEED_PHP}.bak"

echo "Retargeted Blueprint files to ref: ${REF}"
echo
echo "Playground test URL:"
echo "  https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/1fixdotio/media-search-enhanced/${REF}/blueprint/playground.blueprint.json"
echo
echo "Remember to run \`$0 master\` before merging to master."

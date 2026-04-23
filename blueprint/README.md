# Playground Demo

A one-click, browser-only demo of Media Search Enhanced powered by [WordPress Playground](https://wordpress.github.io/wordpress-playground/).

## Try it

<!-- markdownlint-disable-next-line MD034 -->
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/1fixdotio/media-search-enhanced/master/blueprint/demo.blueprint.json

Clicking the link boots WordPress in your browser with the plugin pre-installed and a seeded Media Library.

## What the demo shows

The library is seeded with 8 attachments. Seven relate to mountains — but `mountain` appears in a different field per file. Landing on the Media Library with `?s=mountain` pre-filled, you see:

| Match lives in | Core WP search finds it? | MSE finds it? |
|---|---|---|
| Title | Yes | Yes |
| Description (`post_content`) | Yes | Yes |
| Caption (`post_excerpt`) | Yes | Yes |
| **Filename** (`_wp_attached_file` meta) | **No** | **Yes** |
| **Alt text** (`_wp_attachment_image_alt` meta) | **No** | **Yes** |
| **Exact numeric ID** | **No** | **Yes** |

Core WP returns 3 hits. MSE returns 6. The three extra hits — filename-only, alt-only, and filename+alt — are the headline difference. Hover any row to grab its ID, then search that number to see the exact-ID match.

## Files

- `demo.blueprint.json` — the Blueprint; fetch URL above points at this.
- `seed/seed-media.php` — runs inside Playground's PHP. Downloads the placeholder images and creates 8 attachments with crafted metadata.
- `seed/mu-demo-notice.php` — mu-plugin that prints the explanatory admin notice on `upload.php`. Only loaded inside the demo.
- `seed/images/*.jpg` — 8 placeholder JPEGs (tiny 1x1 grayscale, ~330 bytes each). **Intended to be replaced** with real CC0 mountain photos before the 1.0.0 launch.

## Swapping in real photos

Drop real CC0 photos (Wikimedia Commons, Pixabay, etc.) into `seed/images/` using the exact same filenames:

```
photo-01.jpg
mountain-trail-alps.jpg
photo-03.jpg
photo-04.jpg
photo-05.jpg
photo-06.jpg
mountain-peak-dolomites.jpg
photo-08.jpg
```

Resize each to roughly 800x600 to keep the Blueprint lightweight. No other file changes are needed — filenames are the only coupling between the images and the seed script.

## How it works

Boot sequence when someone opens the Playground link:

1. Playground boots PHP + WordPress in the browser (WASM).
2. `installPlugin` clones `master` from GitHub and activates the plugin. (Swap `ref` to a release tag to pin.)
3. `writeFile` drops `mu-demo-notice.php` into `wp-content/mu-plugins/`.
4. `writeFile` drops `seed-media.php` into `/tmp/`.
5. `runPHP` loads WordPress and runs the seed script — fetches the 8 placeholder JPEGs from `raw.githubusercontent.com` and creates attachment posts with the crafted metadata.
6. Browser opens on `/wp-admin/upload.php?mode=list&s=mountain`; the mu-plugin notice explains the demo.

Because the seed script and mu-plugin are fetched from the repo's raw URLs, the Blueprint only works after the files are on `master`. Local edits to these files don't take effect until pushed.

## Testing on a branch

`demo.blueprint.json` and `seed-media.php` hardcode `master` in their raw URLs, so you can't test changes by pushing to a feature branch alone — Playground would fetch the old `master` copies. Use `retarget.sh` to swap the refs:

```bash
git checkout -b my-demo-change
# ...edit blueprint/ files...
./blueprint/retarget.sh my-demo-change
git commit -am "test: retarget Blueprint to my-demo-change" && git push
# The script prints the Playground URL — open it, verify the demo.

# Before opening the PR:
./blueprint/retarget.sh master
git commit -am "test: retarget Blueprint back to master" && git push
```

The script rewrites the `raw.githubusercontent.com/.../<ref>/blueprint/...` URLs in both files. Don't forget the swap-back — if a PR lands on `master` still pointing at a branch name, the live demo will break.

## After cutting v1.0.0

Once the `v1.0.0` release is published, the `.github/workflows/deploy.yml` workflow attaches a clean plugin zip (built by `10up/action-wordpress-plugin-deploy` honoring `.distignore`) to the GitHub release. Switch the Blueprint's `pluginData` from `git:directory` to a `url` resource pointing at that zip — cleaner install (no `tests/`, `composer.json`, etc.) and the demo pins to the release instead of tracking `master`:

```json
"pluginData": {
  "resource": "url",
  "url": "https://github.com/1fixdotio/media-search-enhanced/releases/download/v1.0.0/media-search-enhanced.zip"
}
```

# Changelog

This is a fork of [Meow Gallery](https://meowapps.com/meow-gallery/) by Jordy Meow.
The **fork version** below is independent from upstream; each entry records the
**upstream Meow Gallery version** it is based on. When upstream changes are
merged, bump the fork version here, update `MGL_UPSTREAM_VERSION` in
`meow-gallery.php`, and the `Stable tag:` in `readme.txt`.

Versioning: the fork uses its own [semantic version](https://semver.org/),
starting at 1.0.0, decoupled from upstream's version number.

## 1.0.0 — 2026-06-14
**Upstream base: Meow Gallery 5.5.0**

- Add: display a public Google Photos album via the `[mgl-google-photos]`
  shortcode and the "Meow Gallery: Google Photos" block.
- Add: fetch album photos directly (cURL with auto-decompression + redirect
  following) to work around WordPress HTTP API issues with Google's responses.
- Add: extract real photo dimensions, plus album title, owner, and per-photo
  capture date; wire these into the gallery and the Meow Lightbox data.
- Add: GPL-2.0 LICENSE and upstream credits.

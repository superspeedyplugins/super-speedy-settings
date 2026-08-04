# Brand assets in this submodule

Every Super Speedy plugin bundles this submodule, so anything here is available
to all of them. Reference assets with `plugins_url( '<file>', __FILE__ )` from a
file inside the submodule, or build the path from your own plugin's URL.

## Wordmark (the "superspeedy" text logo)

| File | Use it on |
|---|---|
| `super-speedy-plugins-black-text.png` | light backgrounds — this is what the shared settings page header uses |
| `super-speedy-plugins-white-text.png` | dark backgrounds |

Both files are **302 x 64** — a 2x asset meant to be DISPLAYED at 151 x 32, so
it stays sharp on HiDPI screens. Always set the display size explicitly:

```php
<img src="<?php echo esc_url( plugins_url( 'super-speedy-plugins-black-text.png', __FILE__ ) ); ?>"
     width="151" height="32" alt="Super Speedy Plugins">
```

Without those attributes it renders at 302 x 64, twice the intended size.

Both are RGBA with a transparent background and identical apart from the text
colour: the white file is the black one recoloured, so the alpha channel is
byte-identical and the glyph shapes and antialiasing match exactly. The ink
fills the canvas edge to edge in both, with no padding.

They are rasterised from the vector master at
`~/marketing/site/company-icons-and-logos/super-speedy-plugins-logo-black-text-only.svg`.
Re-export at a larger size from that SVG if a plugin ever needs the wordmark
bigger — do not upscale these.

PNG rather than SVG on purpose: customer sites run all sorts of security
plugins and WAFs, a minority of which block or mangle `.svg` requests. A bitmap
cannot be second-guessed. (Core's SVG restriction is about media-library
uploads, not bundled plugin assets, so that is not the reason.)

## Also here, from earlier work

- `super-speedy-plugins.svg`, `super-speedy-plugins-black.svg`,
  `super-speedy-plugins-white.svg` — older logo lockups including the icon.
- `super-speedy.png` — the wp-admin menu icon.
- `purple-icon.svg`, `purple-wp-admin-icon.svg`, `purple-wp-admin-icon-2.svg`,
  `lightning.svg` — icon-only marks.
- `packs/` — the Atlas icon packs the settings page's service panels use.

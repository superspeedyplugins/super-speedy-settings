# Brand assets in this submodule

Every Super Speedy plugin bundles this submodule, so anything here is available
to all of them. Reference assets with `plugins_url( '<file>', __FILE__ )` from a
file inside the submodule, or build the path from your own plugin's URL.

## Wordmark (the "superspeedy" text logo)

| File | Use it on |
|---|---|
| `super-speedy-plugins-black-text.png` | light backgrounds — this is what the shared settings page header uses |
| `super-speedy-plugins-white-text.png` | dark backgrounds |

Both are 151 x 32, RGBA with a transparent background, and identical apart from
the text colour: the white file is the black one recoloured, so the glyph shapes
and antialiasing match exactly. Source of truth for the black file is
`~/marketing/site/company-icons-and-logos/super-speedy-plugins-black-text.png`.

Set `width="151" height="32"` on the `<img>` so the layout does not shift while
it loads. They are bitmaps at their natural size — if a plugin needs the
wordmark large or crisp on a HiDPI screen, take the vector from
`~/marketing/site/company-icons-and-logos/super-speedy-plugins-logo-black-text-only.svg`
(and the white equivalent) rather than scaling these up.

## Also here, from earlier work

- `super-speedy-plugins.svg`, `super-speedy-plugins-black.svg`,
  `super-speedy-plugins-white.svg` — older logo lockups including the icon.
- `super-speedy.png` — the wp-admin menu icon.
- `purple-icon.svg`, `purple-wp-admin-icon.svg`, `purple-wp-admin-icon-2.svg`,
  `lightning.svg` — icon-only marks.
- `packs/` — the Atlas icon packs the settings page's service panels use.

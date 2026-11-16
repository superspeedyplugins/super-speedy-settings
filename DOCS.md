# Where the design documents live

This repository is public. Its design documents, plans and internal notes are kept in the
private `superspeedy-plugin-meta` repository, under `super-speedy-settings/.docs/`, so that
publishing the code does not publish the working notes.

Moved there on 2026-08-22, when this repository was made public so that the public plugin
repositories that bundle it as a submodule can be cloned by anyone.

The maintainer rules for agents working on this repository live there too, as
`super-speedy-settings/CLAUDE.md`. A checkout can link them in with gitignored symlinks:
`CLAUDE.md -> ../../superspeedy-plugin-meta/super-speedy-settings/CLAUDE.md` and
`AGENTS.md -> CLAUDE.md`. Moved there on 2026-11-16 so the plugins that bundle this submodule never
ship a developer agent guide.

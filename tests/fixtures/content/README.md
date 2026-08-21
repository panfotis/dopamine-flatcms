# The suite's content fixture

Every test reads and writes a **private copy** of this directory, made under
`var/cache/` at process start by `content_root()` in `lib.php` and removed at
exit. Nothing in here is ever written to.

That indirection exists because the suite mutates content constantly - hostile
saves, stale baselines, revision restores - and it used to do so against the
repository's own `content/`. A failing test then left the real files dirty:
`Παλιός τίτλος` and `Τίτλος alert(1) με πππ…` were both recovered by
`git checkout` more than once, and the revisions those runs wrote piled up as
untracked files inside a tracked directory, ready to be committed by accident.

Shapes here are load-bearing, not decoration. Assertions need a hero carrying
an image (og:image derivation), a footer linking to an internal page (prefixed
route resolution), a select declared `editable: false`, a list whose rows hold
images, and a non-decorative image to refuse for a missing alt. Change the
words freely; run the suite before changing the structure.

`content/` in the repository root is now only the development site - what
`ddev launch` serves - and is free to be edited from the panel.

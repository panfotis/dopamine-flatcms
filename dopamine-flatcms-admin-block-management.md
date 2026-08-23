# Changing a page's blocks from the panel

> **Status:** two versions on the table, neither started. Companion to
> `dopamine-flatcms-plan-v2.md`, which remains the phase bible. This document
> records a **product decision that would reverse the core rule in `CLAUDE.md`**,
> and the exact shape of the reversal. Read it before touching `Admin::save()`.
>
> **Decide between the two versions first** — the smaller one is immediately
> below, the full one is everything after it. They are not "more" and "less" of
> the same thing; the smaller one leaves the product promise intact and the full
> one does not.

## Context

The panel today enforces "structure is developer-owned": block `id`, `type`,
order and count come from the YAML file only. `Admin::save()`
([Admin.php:469](src/Admin.php#L469)) iterates `$page['blocks']` read from disk
and only ever assigns `['fields']`, so structure is not merely hidden in the UI
— it is unreachable from a request. Three layers defend it: the core rule and
non-negotiable #3 in `CLAUDE.md`, the save path itself, and
`tests/03_lockdown.php:47-52`, which posts a forged payload and asserts the
block count, ids, order and types are unchanged.

That rule is now relaxed, deliberately and narrowly:

| | editor | admin |
|---|---|---|
| edit field values | ✅ | ✅ |
| add / reorder / remove blocks on a normal page | ❌ | ✅ |
| add / reorder / remove blocks in a global (`_header`, `_footer`) | ❌ | ❌ |
| change a block's `type`, the page id or `slug` | ❌ | ❌ |

Everything else stands. A **plain save still cannot change structure** — the
hostile-payload assertions in `03_lockdown.php` keep passing byte-for-byte,
because structural change requires explicit parameters the hostile fixture does
not post. `Fields::map()` remains the only schema walk, `editable` is still
refused server-side at every depth, and a structural save runs the identical
field sanitisation as any other.

The data model needs no migration: `blocks:` is already an ordered list of
`{id, type, fields}` — the exact shape this feature wants.

### Decisions taken, with the reasoning

- **Reorder and remove are client-side, persisted only on Save.** The first
  design made each ↑/↓ click a server round-trip. It was less code, but every
  click would take a snapshot: shuffling four blocks buries the useful revisions
  under a dozen junk ones. So the buttons move the card in the DOM, and one Save
  writes one revision.
- **Up/down buttons, not drag-and-drop.** ~10 lines of vanilla JS, keyboard
  accessible, and it matches the existing `image_list` reorder precedent
  ([editor.js:168](admin-theme/assets/js/editor.js#L168)). The server contract
  below is a posted list of ids, so SortableJS or the HTML5 drag API can be
  layered on later without touching a line of PHP.
- **Add is a server round-trip.** Rendering a blank form for an arbitrary
  component type client-side means a `<template>` per type in every edit page.
  Posting the form and letting `Admin::edit()` render the new card afterwards is
  free. Adds are rare, and each one is a revision worth having.
- **Globals stay locked.** Breaking the site chrome from the panel is the
  riskiest case, and it buys nothing — a header changes structure about once a
  year, by a developer.
- **Admins only.** Editors keep the guarantee the product was sold on: the worst
  they can do is write bad copy.

---

# Version 1 — reorder and hide only

**No add. No remove.** The client rearranges and toggles the blocks a designer
already placed on that page, and can do nothing else.

This is not a subset of the full version, it is a different proposition, and the
difference is the sentence above: **no component can ever appear on a page that
the designer did not put there.** The page keeps its building blocks; the client
arranges them and switches them on and off.

That matters because the product promise is not "a small codebase" — it is that
you hand the keys to a non-technical client and *the worst that can happen to the
site is bad copy*. That is what makes it safe to ship without a support contract,
and for an agency it is something you sell rather than a limitation. Add and
remove break it: three heroes in a row, a deleted contact form, layouts nobody
designed. Reorder and hide do not.

### Why it is dramatically cheaper, not just smaller

**Neither verb creates a new state.** Reorder is the same blocks in a different
sequence. Hide is one boolean on a block that already exists. So every doctor
rule is inert:

| doctor rule | reorder | hide |
|---|---|---|
| only one form per page ([doctor:635](bin/doctor#L635)) | count unchanged | can only take 1 → 0, which *relaxes* it |
| a form page must declare `private: true` ([doctor:637](bin/doctor#L637)) | unchanged | same — relaxed, never violated |
| duplicate block ids ([doctor:620](bin/doctor#L620)) | ids untouched | ids untouched |
| block type has a component ([doctor:628](bin/doctor#L628)) | types untouched | types untouched |

**§4 of this document evaporates entirely.** There is no runtime path to a state
the deploy gate would have refused, so the panel has nothing to inherit. Also
gone: the `addable:` flag and `Components::addable()`, the collision-free id
generator, `add_type` validation, the second-form check, and the
`enforceRequired: false` special case for a newly created block — that existed
only because a new block has no posted values.

### What to build

**Reorder** — the `order[]` mechanism from §1 below, with one restriction that
makes it much easier to validate: the posted ids must be a **strict permutation**
of the ids on disk. Same set, different sequence. Anything missing, unknown or
duplicated is `err.structure_block` and a 400. `applyOrder()` stays; `addBlock()`
is never written.

**Hide** — already specified in full as **Part B of
`dopamine-flatcms-publish-state.md`**, and not repeated here so the two cannot
drift. One `continue` in `Cms::renderBlocks()` ([Cms.php:728](src/Cms.php#L728))
is the entire public-site change, plus the three non-obvious consequences that
doc lists: `Form::blockOn()` and `Cms::pageSummary()` must respect the flag, and
component assets correctly stop shipping.

Everything else carries over unchanged from the full version below: admin-only,
globals excluded, client-side buttons persisted on Save (one save, one revision),
the same hidden `order[]` input travelling with each card, the same `data-move`
JS following the `image_list` precedent.

### What it deliberately does not cover

Adding a section stays a phone call to the developer — which is usually the right
outcome, because "I want another box here" is a design decision more often than
it is a content one. Removing stays a file edit, and hide covers the case the
client actually means nine times out of ten: take it down for a month, put it
back.

### Deciding between the two

The question that settles it: **are you actually losing time to "please add
another feature box" tickets?** If yes, the full version earns its cost. If not,
it solves a problem you do not have, and reorder + hide covers the two requests
that genuinely recur — "put the testimonials above the features" and "take the
promotion down".

---

# Version 2 — add, reorder and remove

Everything from here on is the full version. It **includes** reorder as described
above, so §1 is shared; the rest is the part Version 1 leaves out.

## Design

### 1. Reorder + remove: a posted list of block ids

Each block card gains a hidden input, **rendered only for admins on non-global
pages**:

```html
<input type="hidden" name="order[]" value="{{ block.id }}">
```

The input lives inside the card, so moving or deleting the card in the DOM
changes what the form posts. Nothing else about the card's inputs changes.

On save, when `order[]` is present:

- Rebuild the block list from the posted order. Each id maps to **its block as
  read from disk** — `id`, `type` and the stored `fields` still come from the
  file. Only *sequence* and *presence* come from the request.
- An id on disk but absent from the posted order → that block is removed.
- An id posted that is not on disk, or posted twice → `err.structure_block`,
  400, nothing written.
- `order[]` from a non-admin → `requireAdmin()`
  ([Admin.php:167](src/Admin.php#L167)) → 403.
- `order[]` against a global → `err.structure_global` → 400.
- `order[]` absent — every editor save, every global save — → iterate disk order
  exactly as today. This is the path `03_lockdown.php` exercises, and it is
  unchanged.

The existing per-block `cleanValues()` walk then runs over the rebuilt list,
untouched. A structural save never weakens sanitisation, and that is worth a
test of its own (see below).

### 2. Add: the same save, one extra parameter

An "add section" card at the foot of the form, admins and non-globals only:

```twig
<select name="add_type">…{{ Components::addable() }}…</select>
<button name="structure" value="add" formnovalidate>{{ t('edit.add_block') }}</button>
```

Clicking it posts the whole form — csrf, baseline, every typed field value, and
the current `order[]`, so pending reorders and removals are saved in the same
transaction. The server applies the order, appends
`{id, type, fields: []}`, walks fields (the new block has no posted input, so
`Fields::map()` produces pure schema defaults), saves, and 303-redirects back to
`?action=edit`, where the new block's form renders through the normal path.

**New id:** the type name, then `<type>-2`, `-3`, … until it does not collide
with an existing id. A type is a directory basename, so the generated id already
satisfies the charset the rest of the code assumes.

**`enforceRequired: false` for the new block only.** The parameter already
exists ([Admin.php:412](src/Admin.php#L412)). Without it, a component with a
required field could never be added — the save that creates it would refuse it.
Every other block on the page keeps full enforcement on every save. The
consequence is documented and tested: a freshly added block can sit with empty
required fields until the next plain Save demands them. That is the same
situation that already exists when a developer adds a required field to a schema
of an existing page.

**Enter-key safety:** the savebar's Save button is associated to the form via
`form="editor"` and renders before it in tree order
([edit.twig:48](admin-theme/edit.twig#L48)), so it stays the form's default
submit button. Pressing Enter in a text field still means "save", never "add
section". The add button carries `formnovalidate` so an unrelated empty required
field cannot trap the click in a browser validation bubble — the server does the
refusing and re-renders with inline errors, as it already does.

`save` is already in `Admin::MUTATIONS` ([Admin.php:40](src/Admin.php#L40)) and
already runs inside `Content::transaction()` — lock, baseline check, snapshot.
Structural changes inherit all of it: atomic, conflict-checked, revisioned, edge
purged. No new action, no new lock, no change to dispatch.

### 3. `addable:` in `schema.yml`

A top-level `addable: false`, defaulting to true. It exists for one reason:
`site_header` / `site_footer` are real components, and the picker must not offer
to insert one into a normal page.

`Components::addable()` is the **single truth table**, asked by both the template
that builds the picker and the save path that validates `add_type` — the same
philosophy as `mayEdit()` ([Components.php:41](src/Components.php#L41)). The
form can never offer a type the server would refuse, and filtering the picker can
never be mistaken for enforcing it.

### 4. The panel inherits doctor's checks

**This is the general principle, and it outlives this feature.**

Several invariants in this codebase are enforced not by the runtime but by
`bin/doctor`, which `bin/deploy.sh:99` runs **before** flipping the release. That
is a sound and cheap design *as long as every path to the invariant goes through
a deploy*. Until now every one did, because structure was only ever changed by a
developer editing a file.

Adding structural controls to the panel breaks that assumption. A save is a
**runtime** action, and doctor never runs at runtime. So every doctor rule that a
panel action can now reach has to be re-enforced at save time — otherwise the
client publishes a state doctor would have refused, and nobody finds out until
the next deploy.

For *this* feature there is exactly one such rule, and it is not cosmetic.

**Only one form per page** ([doctor:635-637](bin/doctor#L635)). `Form::blockOn()`
returns **the first** block whose component declares `form:`, and
`Form::handle()` opens with `$block = $this->blockOn($page)` and uses only that
one. But `renderBlocks()` has no form-specific case, so it renders every form
block on the page. With two, a visitor sees both, both post to the same URL, and
the server always processes the first:

- the second form's fields are validated against the first form's schema, so the
  visitor is told to fill in fields that are not on the form they used;
- errors are attributed to `$block['id']` of the *first* block, so they appear on
  the wrong form;
- the recipient comes from the first block's `fields`, so the second form's
  submissions are emailed to the wrong address.

So `addBlock()` must refuse when the type being added declares `form:` and the
page already carries a form block — the same condition doctor uses, asked at save
time. New key: `err.structure_form`.

Two related rules need no new code, and it is worth recording why:

- **Duplicate block ids** ([doctor:620-626](bin/doctor#L620)) — already covered,
  because the id generator picks the first non-colliding name.
- **A page with a form must declare `private: true`**
  ([doctor:637-640](bin/doctor#L637)) — not a security boundary.
  `Cms::cacheHeaders()` ([Cms.php:1154](src/Cms.php#L1154)) detects the form
  block itself and forces `no-store` whether or not the flag is present; the
  comment there says so explicitly ("the runtime owns the security invariant").
  Adding a form block from the panel therefore cannot leak a CSRF token to the
  edge. Doctor still reports the missing declaration, which is the right division
  of labour and needs nothing here.

The same principle applies to anything else moved into the panel later — see
`dopamine-flatcms-publish-state.md`, where republish has to re-check the
duplicate-slug rule for exactly this reason.

---

## Files touched

| File | Change |
|---|---|
| [src/Components.php](src/Components.php) | normalise `addable` in `all()`; add `addable()` (~8 lines) |
| [src/Admin.php](src/Admin.php) | `save()` reads `order[]`/`structure`; private `applyOrder()` + `addBlock()`; `edit()` passes `structural` + `addable`; class docblock (~50 lines) |
| [lang/en.php](lang/en.php), [lang/el.php](lang/el.php) | 6 new keys |
| [admin-theme/edit.twig](admin-theme/edit.twig) | `order[]` input, ↑/↓/remove buttons, add-section card |
| [admin-theme/assets/js/editor.js](admin-theme/assets/js/editor.js) | card swap + confirm-remove (~15 lines) |
| [admin-theme/assets/css/admin.css](admin-theme/assets/css/admin.css) | ~4 lines, follow existing `.tools` styling |
| [tests/02_admin.php](tests/02_admin.php), [tests/03_lockdown.php](tests/03_lockdown.php) | rewritten assertions + new cases |
| `CLAUDE.md`, `dopamine-flatcms-plan-v2.md`, `README.md` | the rule change |

No new files, no new PHP action, no new dependencies, no build step.

---

## Implementation order

1. **`src/Components.php`** — `$schema['addable'] = ($schema['addable'] ?? true) !== false;`
   beside the other defaults in `all()` ([:56](src/Components.php#L56)); add
   `addable(): array` filtering `all()`. Set `addable: false` on the header and
   footer components in `theme/components/` and
   `tests/fixtures/theme/components/`.

2. **`src/Admin.php`**
   - `save()` ([:437](src/Admin.php#L437)): read
     `$order = $request->request->all('order')` and
     `$op = (string) $request->request->get('structure', '')`. If either is
     non-empty → `requireAdmin($user)` and refuse globals
     (`err.structure_global`). Inside the transaction callback, **before** the
     field loop:
     - `$order` posted → `applyOrder($page['blocks'], $order)`
     - `$op === 'add'` → `addBlock($blocks, $addType)`, returning the new id.
       Validates against `Components::addable()`, **and refuses a second form
       block** per §4 — `$this->cms->components->get($type)['form'] ?? []` is
       non-empty and the page already has such a block → `err.structure_form`
     - in the field loop, pass `enforceRequired: $block['id'] !== $newId`
   - Two new private helpers (~40 lines total). Both throw `RuntimeException`
     from inside the transaction, so they refuse before `snapshot()`/`save()` run
     and the file is untouched — the same guarantee the field walk already relies
     on.
   - `edit()` ([:263](src/Admin.php#L263)): pass
     `structural` (`$user['role'] === 'admin' && !Content::isGlobal($id)`) and
     `addable` (`$this->cms->components->addable()`).
   - Class docblock ([:15-33](src/Admin.php#L15)): an **editor** changes values,
     never structure; an **admin** may additionally reorder, remove (`order[]`)
     and add (`structure=add`) blocks on a normal page, all validated
     server-side; globals never change structure.

3. **`lang/en.php` + `lang/el.php`** — `edit.add_block` ("Add section" /
   "Προσθήκη ενότητας"), `edit.remove_block`, `edit.remove_confirm` ("Remove this
   section? It is removed when you save; earlier versions keep its content."),
   `err.structure_global`, `err.structure_type`, `err.structure_block`,
   `err.structure_form` ("This page already carries a form; only one form per
   page is supported."). Reuse the existing `edit.move_earlier` /
   `edit.move_later` for the button titles.

4. **`admin-theme/edit.twig`** — inside each block card when `structural`: the
   hidden `order[]` input, and ↑ / ↓ / remove in the card header. All
   `type="button"`, never submit: `data-move="-1"`, `data-move="1"`,
   `data-block-remove`, following the `image_list` markup precedent. After the
   block loop when `structural`: the add-section card. Add `removeConfirm` to the
   `const T` strings object.

   **Hazard:** never `disabled` an input and never remove a *field* input from
   the DOM to hide a card — hidden inputs still submit and the save path depends
   on it. Removing a whole card is the one deliberate exception, and it is what
   `order[]` reports.

5. **`admin-theme/assets/js/editor.js`** (~15 lines) — `data-move` swaps the card
   with its previous/next sibling card (same pattern as
   [:168](admin-theme/assets/js/editor.js#L168)); `data-block-remove` confirms via
   `T.removeConfirm` then removes the card node. Both mark the form dirty. Add is
   a native form submission and needs no JS; the existing submit handler already
   clears the dirty flag.

6. **Tests** — see below.

7. **Docs** — `CLAUDE.md` core rule and non-negotiable #3 rewritten (below);
   #10 untouched, because it is being honoured. Update the test-count line.
   `dopamine-flatcms-plan-v2.md:85-88` — move the bullet out of "Out of scope"
   with a dated note, globals still excluded. `README.md:618` — drop the
   limitation bullet.

### The rewritten rule

> **The core rule:** structure is developer-and-admin-owned and lives in files;
> content is client-owned and edited in the panel. An **editor** can never add,
> remove, reorder or retype a component. An **admin** may add, reorder and remove
> blocks on a normal page through explicit, server-validated structure
> parameters; never in a global, and never a block's `type`, the page id or the
> `slug`. A save that does not carry those parameters cannot change structure at
> all. Enforced on save, not merely hidden in the UI.

---

## Verification

```bash
ddev exec bash tests/run.sh                          # all ten files, not just 02/03
ddev exec php -l src/Admin.php src/Components.php
php bin/doctor                                       # catches el.php falling behind
```

`04_hardening.php` and `06_production.php` reuse the hostile fixture and pin
panel output, so "the two files I edited pass" is not the bar.

### `tests/02_admin.php`

- `:166-170`, currently "No structural controls exist in the UI" → **"Structural
  controls are admin-only, and structure params are still never field inputs"**.
  The dev-bypass user is an admin, so assert the add button, the `order[]` hidden
  inputs and `data-move` / `data-block-remove` are **present**. **Keep**
  `missing($edit, 'name="blocks[hero][type]"')` and `missing($edit, 'name="slug"')`
  exactly as they are — type and slug are still never inputs. Assert an
  `addable: false` component is not offered in the select.
- `:513` (globals): keep the missing-add-button assertion, with its comment
  updated to "locked by product decision"; add `missing($headerForm, 'name="order[]"')`.
- New section, full round-trip as admin, restoring the fixture afterwards like
  its neighbours: (a) save with `order[]` reversed → 303, YAML order reversed,
  **one** new revision; (b) save with one id omitted → that block gone from the
  YAML; (c) `structure=add&add_type=callout` → a schema-defaulted `callout`
  appended, and the redirected edit screen renders its card.
- `:547` (gallery up/down) and `:673` (page creation stays developer-only):
  untouched.

### `tests/03_lockdown.php`

- `:47-52` **stay byte-for-byte.** The hostile payload posts neither `order[]`
  nor `structure`, so block count, ids, order and types must still be unchanged.
  These assertions now prove "a plain save still cannot change structure", which
  is a stronger claim than before, not a weaker one.
- New section, **Structure ops are admin-only, validated, and refused on
  globals**:
  - editor posts `order[]`, and separately `structure=add` → **403**, file
    byte-identical (`file_get_contents` compare)
  - the editor's rendered edit form contains no `order[]` and no add button
  - admin posts `add_type=site_header` (unaddable), `does_not_exist`, and
    `../../etc` → **400**, nothing written
  - admin posts an unknown id, and a duplicated id, in `order[]` → **400**,
    file byte-identical
  - admin posts a valid reversed `order[]` **combined with hostile field values**
    → 303, order changed, but `align` still clamped, undeclared keys still
    dropped, `<script>` still stripped. This is the assertion that says a
    structural save does not weaken the field walk.
- New section, **The panel enforces what doctor enforces** (§4): admin posts
  `structure=add&add_type=contact_form` against a page that already carries a
  form → **400**, file byte-identical. Then the same add against a page with no
  form → 303, and `bin/doctor` still passes on the resulting content tree. The
  second half is the one that matters: it proves the panel cannot publish a
  state the deploy gate would have refused.
- `:784-796` (globals): keep every existing assertion; add admin +
  `page=_header` + `order[]` → **400**, `_header.yml` byte-identical.
- `tests/fixtures/hostile_save.php`: **unchanged.** Adding `structure` to it
  would be a legitimate admin operation under dev-bypass and would destroy the
  fixture's meaning.

### Manual

`ddev launch /admin.php` as the dev-bypass admin: add a `callout`, move it up
twice with the buttons (no page reload), remove a different block (confirm
dialog), then Save **once** — verify the final order in
`content/pages/*/home.yml` and exactly **one** new file in `.revisions/`. Open
`_header` and confirm no structural controls render.

---

## Deliberately skipped

- **Drag-and-drop / SortableJS.** Up/down buttons chosen. The server contract is
  a posted list of ids either way, so drag is a pure front-end addition later.
- **Saving each move as it happens.** Rejected: one junk revision per click.
- **Restoring a removed block from the panel.** `Admin::restore()` walks the
  *current* file's blocks, so a revision cannot bring a deleted block back
  through the UI. Its content is safe in `.revisions/` and a developer can
  recover it. The fix is ~25 lines — rebuild the block list from the revision,
  types filtered through `Components::has()`, ids revalidated, values through
  `cleanValues(..., enforceRequired: false)` — plus its own lockdown case ("a
  revision cannot resurrect a deleted component type"). Restore is already
  admin-only and already re-sanitises, so the new invariants hold. Ship the main
  feature first.
- **Page creation from the panel.** Still adding a file, still developer-only.
  `02_admin.php:673` stands.
- **Split view and live preview** — `dopamine-flatcms-admin-split-view.md`, still
  its own decision. Its phase 1 (an iframe of the real page, refreshing on the
  303 after save) pairs well with this feature precisely because structural
  changes land on Save. htmx was raised for the live-preview half and rejected
  for the reason already recorded there: htmx swaps into the parent DOM, and the
  iframe is what isolates the site's CSS from the panel's.

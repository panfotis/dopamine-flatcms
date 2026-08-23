/*
 * The panel's editor script. A real .js file rather than 260 lines buried at
 * the bottom of edit.twig: there is no Twig in it, so it belongs somewhere an
 * editor will highlight it and a linter will read it.
 *
 * It is an ordinary admin-theme asset: edit.twig attaches it with
 * theme_attach(), and the pipeline inlines it wrapped in DOMContentLoaded —
 * which is also why the queries below can assume the DOM exists.
 *
 * Every string it shows comes from the `const T` object edit.twig renders in
 * the <script> immediately above this one: those are translated, so they have
 * to be built in PHP. Everything else here is driven off data-* attributes, so
 * adding a widget never means editing this file.
 */
(function () {
  const form = document.getElementById('editor');
  const status = document.getElementById('status');
  const csrf = form.querySelector('[name=csrf]').value;
  let dirty = false;
  const touch = () => { dirty = true; };

  /* ---- go to the first refused field ---------------------------------
     The message is at the top of a long form and the field it names can be
     three cards down — and inside the SEO card, which is closed by default. */
  const bad = document.querySelector('.field.has-error');
  if (bad) {
    bad.closest('details')?.setAttribute('open', '');
    bad.scrollIntoView({ block: 'center' });
    bad.querySelector('input, textarea, select')?.focus();
  }

  /* ---- character counters ------------------------------------------- */
  document.querySelectorAll('.counter[data-for]').forEach(el => {
    const input = document.getElementById(el.dataset.for);
    if (!input) return;
    const max = parseInt(el.dataset.max, 10);
    const paint = () => {
      const n = (input.value || '').length;
      el.textContent = n + '/' + max;
      el.classList.toggle('over', n > max);
    };
    input.addEventListener('input', paint);
    paint();
  });

  /* ---- minimal rich text --------------------------------------------
     A contenteditable with four buttons. Deliberately tiny: the server
     strips everything except p/br/strong/em/u/a/ul/ol/li anyway, so there
     is no point offering the client formatting that will be thrown away. */
  document.querySelectorAll('.rt[contenteditable]').forEach(rt => {
    const target = document.getElementById(rt.dataset.target);
    const sync = () => { target.value = rt.innerHTML; touch(); };
    rt.addEventListener('input', sync);

    rt.addEventListener('paste', e => {
      e.preventDefault();
      const text = (e.clipboardData || window.clipboardData).getData('text/plain');
      document.execCommand('insertText', false, text);
    });

    const bar = document.querySelector('[data-rt-toolbar="' + rt.dataset.target + '"]');
    if (!bar) return;
    bar.addEventListener('click', e => {
      const btn = e.target.closest('button');
      if (!btn) return;
      rt.focus();
      if (btn.dataset.cmd === 'createLink') {
        const url = prompt(T.linkPrompt, 'https://');
        if (url) document.execCommand('createLink', false, url);
      } else {
        document.execCommand(btn.dataset.cmd, false, null);
      }
      sync();
    });
  });

  /* ---- image upload --------------------------------------------------- */
  document.querySelectorAll('[data-upload]').forEach(btn => {
    const id = btn.dataset.upload;
    const file = document.getElementById(id + '-file');
    const hidden = document.getElementById(id);
    const thumb = document.getElementById(id + '-thumb');
    const path = document.getElementById(id + '-path');
    const alt = document.getElementById(id + '-alt');

    btn.addEventListener('click', () => file.click());

    file.addEventListener('change', async () => {
      if (!file.files.length) return;
      const body = new FormData();
      body.append('action', 'upload');
      body.append('csrf', csrf);
      body.append('file', file.files[0]);

      btn.disabled = true;
      status.textContent = T.uploading;
      try {
        const res = await fetch('?action=upload', { method: 'POST', body });
        const data = await res.json();
        if (!data.ok) throw new Error(data.message || T.uploadFailed);
        hidden.value = data.url;
        // A video has no thumbnail of its own — its poster is a separate image
        // field with its own preview, so painting the mp4 URL in here would
        // just break the tile.
        if (!data.video) thumb.style.backgroundImage = "url('" + (data.preview || data.url) + "')";
        path.textContent = data.url;
        if (alt && !data.video) alt.required = true;
        status.textContent = data.video ? T.videoUploaded : T.imageUploaded;
        touch();
      } catch (err) {
        status.textContent = err.message;
      } finally {
        btn.disabled = false;
        file.value = '';
      }
    });
  });

  document.querySelectorAll('[data-clear]').forEach(btn => {
    const id = btn.dataset.clear;
    btn.addEventListener('click', () => {
      document.getElementById(id).value = '';
      document.getElementById(id + '-thumb').style.backgroundImage = '';
      document.getElementById(id + '-path').textContent = T.noImage;
      // No image, no alt to demand — same condition the save path uses.
      const alt = document.getElementById(id + '-alt');
      if (alt) alt.required = false;
      touch();
    });
  });

  /* ---- galleries -------------------------------------------------------
     A grid of tiles, each one hidden src + optional alt. Reorder is up/down
     buttons rather than drag: ten lines, keyboard-accessible for free, and no
     library in a project with no front-end build. Indices are rewritten after
     every change because the input *names* carry them — the server re-keys
     with array_values() anyway, but duplicate names inside one form would lose
     rows before they ever got there. */
  document.querySelectorAll('[data-gallery]').forEach(grid => {
    const id = grid.dataset.gallery;
    const name = grid.dataset.name;
    const max = parseInt(grid.dataset.max, 10) || 60;
    const decorative = grid.dataset.decorative === '1';
    const files = document.getElementById(id + '-files');
    const add = document.querySelector('[data-gallery-add="' + id + '"]');
    const note = document.querySelector('[data-gallery-status="' + id + '"]');
    if (!add) return;   // locked field: no controls were rendered

    const renumber = () => {
      grid.querySelectorAll('[data-tile]').forEach((tile, i) => {
        tile.querySelectorAll('input').forEach(input => {
          input.name = input.name.replace(/\[\d+\]/, '[' + i + ']');
        });
      });
      add.disabled = grid.querySelectorAll('[data-tile]').length >= max;
    };

    const tile = (url, preview) => {
      const fig = document.createElement('figure');
      fig.className = 'tile';
      fig.setAttribute('data-tile', '');
      fig.innerHTML =
        '<div class="thumb"></div>' +
        '<input type="hidden" name="' + name + '[0][src]">' +
        (decorative ? '' :
          '<input type="text" class="alt" name="' + name + '[0][alt]" maxlength="120" placeholder="'
          + grid.dataset.altLabel + '">') +
        '<div class="tools"><button type="button" data-move="-1">↑</button>' +
        '<button type="button" data-move="1">↓</button>' +
        '<button type="button" data-drop>✕</button></div>';
      fig.querySelector('.thumb').style.backgroundImage = "url('" + (preview || url) + "')";
      fig.querySelector('input[type=hidden]').value = url;
      grid.appendChild(fig);
    };

    add.addEventListener('click', () => files.click());

    files.addEventListener('change', async () => {
      const picked = Array.from(files.files);
      files.value = '';
      const room = max - grid.querySelectorAll('[data-tile]').length;
      if (room <= 0) { note.textContent = T.atLimit; return; }

      add.disabled = true;
      let done = 0;
      let failed = false;
      // One at a time on purpose: thirty parallel uploads through GD is how a
      // shared worker pool runs out mid-gallery, and the client sees half a
      // grid with no idea which half saved.
      for (const f of picked.slice(0, room)) {
        note.textContent = T.uploadingN.replace('%1', ++done).replace('%2', Math.min(picked.length, room));
        const body = new FormData();
        body.append('action', 'upload');
        body.append('csrf', csrf);
        body.append('file', f);
        try {
          const res = await fetch('?action=upload', { method: 'POST', body });
          const data = await res.json();
          if (!data.ok) throw new Error('upload failed');
          tile(data.url, data.preview);
        } catch (err) {
          failed = true;
        }
      }
      renumber();
      touch();
      note.textContent = failed ? T.uploadSomeFailed : T.uploadDone;
      add.disabled = false;
    });

    grid.addEventListener('click', e => {
      const fig = e.target.closest('[data-tile]');
      if (!fig) return;

      if (e.target.hasAttribute('data-drop')) {
        fig.remove();
      } else if (e.target.hasAttribute('data-move')) {
        const dir = parseInt(e.target.dataset.move, 10);
        const sibling = dir < 0 ? fig.previousElementSibling : fig.nextElementSibling;
        if (sibling) grid.insertBefore(dir < 0 ? fig : sibling, dir < 0 ? sibling : fig);
      } else {
        return;
      }
      renumber();
      touch();
    });

    renumber();
  });

  /* ---- list repeaters -------------------------------------------------
     Rows are content, so the client adds and removes them. The fields inside
     a row are not, so they come from the template and nothing here invents
     one. Indices need no tidying on remove: the server re-keys with
     array_values() and cuts to `max` before it sanitises anything. */
  document.querySelectorAll('[data-row-add]').forEach(btn => {
    const id = btn.dataset.rowAdd;
    const rows = document.getElementById(id + '-rows');
    const tpl = document.getElementById(id + '-template');
    const max = parseInt(rows.dataset.max, 10);
    let next = rows.querySelectorAll('[data-row]').length;

    const paint = () => { btn.disabled = rows.querySelectorAll('[data-row]').length >= max; };

    btn.addEventListener('click', () => {
      if (rows.querySelectorAll('[data-row]').length >= max) return;
      const html = tpl.innerHTML.replaceAll('__INDEX__', String(next++));
      rows.insertAdjacentHTML('beforeend', html);
      paint();
      touch();
    });

    rows.addEventListener('click', e => {
      if (!e.target.closest('[data-row-remove]')) return;
      e.target.closest('[data-row]').remove();
      paint();
      touch();
    });

    paint();
  });

  /* ---- don't lose work ------------------------------------------------ */
  // A required field inside a closed <details> is not focusable, and the
  // browser then refuses the submit with a bubble nobody can see.
  form.addEventListener('invalid', e => {
    e.target.closest('details')?.setAttribute('open', '');
  }, true);

  form.addEventListener('input', touch);
  // A preview submits the same form into the dialog's iframe, but nothing was
  // saved — the changes are exactly as unsaved as before the click, and the
  // beforeunload warning must survive it.
  form.addEventListener('submit', (e) => {
    if (e.submitter?.value !== 'preview') { dirty = false; }
  });
  window.addEventListener('beforeunload', e => {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });
})();

<?php

/**
 * The source language. Every key the panel and the engine can show lives here,
 * and every other catalogue is a translation of this file.
 *
 * Keys are dotted and named after where the string appears, not after what it
 * says: renaming a message must not mean renaming a key in five files.
 *
 * `%s` and `%d` go straight to sprintf, so a translator can move a placeholder
 * to wherever the sentence needs it.
 */

declare(strict_types=1);

return [
    // The document language of the panel itself. One key rather than a
    // second Twig function for one attribute.
    '_locale' => 'en',

    // ── Chrome ──────────────────────────────────────────────────────────────
    'admin' => 'Admin',
    'nav.pages' => 'Pages',
    'nav.back' => '← All pages',
    'nav.back_submissions' => '← All messages',

    // ── Page list ───────────────────────────────────────────────────────────
    'list.title' => 'Pages',
    'list.intro' => 'Pick a page to change its text and images.',
    'list.col_page' => 'Page',
    'list.col_address' => 'Address',
    'list.empty' => 'No pages found.',
    'list.view' => 'View',
    'list.edit' => 'Edit',
    'list.globals' => 'On every page',
    'list.globals_intro' => 'The header and the footer. What you change here changes everywhere.',
    'list.missing_in' => 'missing: %s',
    'list.untranslated' => 'In another language but not here: %s. Creating a page is a developer job.',
    'list.submissions' => 'Messages from the form',

    // ── Edit ────────────────────────────────────────────────────────────────
    'edit.everywhere' => 'appears on every page',
    'edit.seo' => 'SEO and sharing',
    'edit.optional' => 'optional',
    'edit.page' => 'Page',
    'edit.page_title' => 'Page title',
    'edit.page_title_hint' => 'Shown in the browser tab and in search results.',
    'edit.save' => 'Save',
    'edit.view_page' => 'View page',
    'edit.revisions' => 'Earlier versions',
    'edit.published' => 'Changes are published as soon as you save.',
    'edit.locked' => 'locked',
    'edit.admin_only' => 'admin only',
    'edit.no_fields' => 'This section has no editable fields.',
    'edit.missing_component' => 'Component “%s” was not found in components/.',
    'edit.up_to' => 'up to %d',
    'edit.add_row' => 'Add',
    'edit.remove_row' => 'Remove',
    'edit.no_page' => '— No page —',
    'edit.dead_link' => 'Page “%s” no longer exists. The link renders as plain text.',
    'edit.change_image' => 'Change image',
    'edit.change_video' => 'Change video',
    'edit.remove' => 'Remove',
    'edit.no_image' => 'No image',
    'edit.no_video' => 'No video',
    'edit.add_photos' => 'Add photos',
    'edit.uploading' => 'Uploading…',
    'edit.uploading_n' => 'Uploading %1/%2…',
    'edit.upload_done' => 'Done. Press Save.',
    'edit.upload_some_failed' => 'Some photos did not upload.',
    'edit.at_limit' => 'You have reached the limit.',
    'edit.image_uploaded' => 'Image uploaded. Press Save.',
    'edit.video_uploaded' => 'Video uploaded. Press Save.',
    'edit.move_earlier' => 'Earlier',
    'edit.move_later' => 'Later',
    'edit.poster' => 'Poster image',
    'edit.poster_hint' => 'Shown before the video starts.',
    'edit.stored_as' => 'Stored: %s / %s',
    'edit.unsaved' => 'You have unsaved changes.',

    // Rich text toolbar
    'rt.bold' => 'Bold',
    'rt.italic' => 'Italic',
    'rt.list' => 'List',
    'rt.link' => 'Link',
    'rt.clear' => 'Clear formatting',
    'rt.link_prompt' => 'Address for the link',

    // ── Flashes and refusals ────────────────────────────────────────────────
    'flash.saved' => 'Your changes are saved.',
    'flash.restored' => 'The earlier version was restored.',
    'flash.nothing_saved' => 'Nothing was saved.',
    'flash.fields_need_filling' => '%d field needs filling in — it is marked in red below.',
    'flash.fields_need_filling_plural' => '%d fields need filling in — they are marked in red below.',
    'flash.text_kept' => 'Everything else you typed is still in the form; fill those in and press Save again.',
    'flash.conflict_help' => 'Nothing was saved. What you typed is still in the form below — check it and press Save again to replace the other version, or reload the page to discard it.',
    'flash.held_by' => '%s opened this page %s. Talk to them before saving.',
    'flash.held_just_now' => 'just now',
    'flash.held_minutes' => '%d minutes ago',

    // ── Revisions ───────────────────────────────────────────────────────────
    'rev.title' => 'Earlier versions',
    'rev.intro' => 'Restoring rebuilds the page from that version. The current one is saved first, so this is undoable.',
    'rev.when' => 'When',
    'rev.restore' => 'Restore',
    'rev.empty' => 'No earlier versions yet.',
    'rev.confirm' => 'Restore this version? The current one is kept and can be restored back.',

    // ── Submissions ─────────────────────────────────────────────────────────
    'sub.title' => 'Messages',
    'sub.retention' => 'Deleted automatically after %d months.',
    'sub.when' => 'Date',
    'sub.from' => 'From',
    'sub.status' => 'Status',
    'sub.open' => 'Open',
    'sub.empty' => 'No messages yet.',
    'sub.sent' => 'sent',
    'sub.unsent' => 'not sent',
    'sub.review' => 'needs review',
    'sub.attempts' => '%d attempts',
    'sub.one' => 'Message',
    'sub.content' => 'Content',
    'sub.retry' => 'Try sending again',
    'sub.delete' => 'Delete',
    'sub.delete_confirm' => 'Delete this message permanently?',
    'sub.reference' => 'Reference %s',
    'sub.failed' => 'Not sent (%d attempts).',
    'sub.ambiguous' => 'The send failed ambiguously. It may already have been delivered — which is why it is not retried automatically. Check the mailbox before trying again.',
    'sub.sent_ok' => 'Sent.',
    'sub.not_sent' => 'Not sent — check the log.',
    'sub.not_retryable' => 'This message is not safe to retry. Check its delivery status first.',
    'sub.deleted' => 'The message was deleted.',

    // ── Errors ──────────────────────────────────────────────────────────────
    'err.title' => 'Something went wrong',
    'err.back' => 'Back to pages',
    'err.generic' => 'Something went wrong and the action did not complete. Try again.',
    'err.denied' => 'No access',
    'err.denied_ask' => 'Ask an administrator to add you.',
    'err.not_listed' => 'Your account was recognised but has no access to this site. Ask an administrator to add you.',
    'err.admin_required' => 'This action needs an administrator.',
    'err.session' => 'Your session expired. Reload the page and try again.',
    'err.page_missing' => 'The page was not found.',
    'err.revision_unknown' => 'Unknown version.',
    'err.revision_missing' => 'The version was not found.',
    'err.submission_missing' => 'The message was not found.',
    'err.submission_unknown' => 'Unknown message.',
    'err.stale' => 'The page changed elsewhere while you were editing it.',
    'err.invalid_id' => 'Invalid %s.',
    'err.locked' => 'Could not lock the page for editing. Try again.',

    // ── Uploads ─────────────────────────────────────────────────────────────
    'up.failed' => 'The upload failed.',
    'up.too_large' => 'The file is too large.',
    'up.images_only' => 'Only images are allowed (JPG, PNG, WebP).',
    'up.not_an_image' => 'The file is not a valid image.',
    'up.too_many_pixels' => 'The image dimensions are too large.',
    'up.mp4_only' => 'Only MP4 files are allowed.',
    'up.not_an_mp4' => 'The file is not a valid MP4.',
    'up.video_too_large' => 'The video is too large (limit %d MB).',

    // ── Field labels the engine owns ────────────────────────────────────────
    'field.image' => 'Image',
    'field.alt' => 'Image description',
    'field.alt_hint' => 'What the image shows, in one sentence. Read aloud to anyone using a screen reader, shown in its place if it does not load, and read by Google.',
    'field.required' => 'The field “%s” is required.',
    'field.required_in' => 'The field “%s” in section “%s” cannot be empty.',

    'seo.label' => 'SEO',
    'seo.title' => 'Title in Google',
    'seo.title_hint' => 'The blue line people click in search results. Empty = the page title.',
    'seo.description' => 'Description in Google',
    'seo.description_hint' => 'The two lines under the title in search results. Empty = the first text on the page.',
    'seo.og_image' => 'Sharing image',
    'seo.og_image_hint' => 'The preview image when someone sends the link on Facebook, LinkedIn, Viber or WhatsApp. Empty = the first image on the page.',
    'seo.noindex' => 'Hide from search engines',
    'seo.noindex_hint' => 'Asks Google and Bing not to show the page in results, and removes it from the sitemap. Anyone with the link still sees it — this is not a lock.',
    'seo.canonical' => 'Canonical URL',
    'seo.canonical_hint' => 'Tells Google the original copy of this content lives at another address, and to count its popularity there. Leave empty if unsure.',

    // ── Visitor-facing (the contact form) ───────────────────────────────────
    'form.rate_limited' => 'Too many attempts. Try again in a few minutes.',
    'form.expired' => 'The page was open too long. Reload it and try again.',
    'form.no_form' => 'This page has no form.',
    'form.bad_email' => 'Check the email address.',
    'form.consent_required' => 'We need your consent to continue.',
    'form.challenge_failed' => 'The security check failed. Try again.',
    'form.send' => 'Send',
    'form.subject' => 'New message from %s',
    'form.honeypot_label' => 'Do not fill in this field',
    'form.body_page' => 'Page',
    'form.body_date' => 'Date',
    'form.body_reference' => 'Reference',
    'form.name' => 'Name',
    'form.email' => 'Email',
    'form.phone' => 'Phone',
    'form.message' => 'Message',
    'contact.phone' => 'Phone',
    'contact.email' => 'Email',
    'contact.address' => 'Address',
    'video.play' => 'Play video',
    'site.home' => 'Home',
    'error.not_found' => 'The page %s was not found.',
    'error.server' => 'Something went wrong. Try again shortly.',
];

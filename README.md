# Cross References

GitHub-style cross-references for **Flarum 2**. Posts can reference other
discussions inline with `#42` or by pasting a discussion URL; the target
discussion automatically gets a backlink, the target's author can be
notified, and a sidebar widget surfaces every inbound reference.

Built as a more robust Flarum 2 successor to
[`club-1/flarum-ext-cross-references`](https://github.com/club-1/flarum-ext-cross-references),
with rename-safe rendering, visibility-aware backlinks, batched queries,
and a `references:N` search filter.

## Features

- **`#42`** and **`#42/p7`** inline syntax — autoresolves to the current
  discussion title, with the post number as a suffix when set.
- **Pasted-URL detection** — bare forum discussion URLs in post bodies
  are rewritten to the inline form at parse time. Markdown links
  (`[label](url)`) are left alone.
- **Bidirectional backlinks** — when a post references another
  discussion, a small "Referenced from #X" event-post appears in the
  target. Optional, admin-toggleable.
- **Notifications** — the target discussion's author gets an in-app
  alert. Optional, admin-toggleable, deduped per source-post.
- **Rename-safe rendering** — titles are NEVER baked into the post
  content. Each render resolves the current title from the database, so
  renames flow through automatically and deletions degrade gracefully.
- **Visibility-aware** — references to discussions the viewer can't see
  render as a muted `#42` chip without the title. Backlinks and
  notifications respect the same scope.
- **`references:N` search filter** — find every discussion whose posts
  reference discussion #N. Negation supported as `-references:N`.
- **Inbound-references sidebar widget** — renders on `DiscussionPage`
  showing the last 50 inbound refs with source author + relative time.
- **Batched queries** — one DB round-trip per render to resolve all
  titles for a post; one batched visibility check for the sidebar.

## Installation

```bash
composer require ernestdefoe/cross-references
php flarum migrate
php flarum cache:clear
```

Enable from the admin panel under **Extensions → Cross References**.

## Configuration

Admin panel exposes three toggles:

| Setting | Default | What it does |
|---|---|---|
| Show inline references in posts | on | Render `#42` / pasted-URL refs as rich chips |
| Create backlink event-posts | on | Insert a "Referenced from #X" post in the target discussion |
| Notify the target discussion's author | on | Send an in-app alert when their discussion gets referenced |

## Architecture notes

- Storage: `cross_references` table with a unique constraint on
  `(source_post_id, target_discussion_id, target_post_id)` and indexes
  on both source and target discussion ids. Cascade-on-delete via FKs
  to `posts` and `discussions`.
- Backlinks are first-class posts (`CrossReferenceEventPost extends
  AbstractEventPost`), so they participate in moderation history,
  search, and the standard reply pipeline.
- The TextFormatter pipeline registers a `CROSSREF` tag at compile time;
  the render callback batch-resolves titles + visibility per request.
- The `Posted`/`Revised` listener wraps its body in a `try/catch` with
  PSR-3 logging so a cross-ref bug can never block a post save.

## License

[MIT](LICENSE.md) © Ernestdefoe

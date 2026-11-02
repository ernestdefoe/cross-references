import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';

/**
 * Titles in the composer preview. The preview is built in the browser from
 * the raw text, so `#12` renders as a bare chip until the post is saved. Watch
 * each preview, and fill in the title of every chip the server would title:
 * from discussions already loaded, else one request once typing pauses, for
 * the numbers still in the text (not every prefix of `#12345` on the way).
 */

// id → title, or null for a discussion the reader can't see (or that doesn't exist).
const titles = new Map<string, string | null>();
const loading = new Set<string>();
const previews = new Set<HTMLElement>();
let timer: ReturnType<typeof setTimeout> | undefined;

const untitled = (root: HTMLElement) =>
  Array.from(root.querySelectorAll<HTMLElement>('a.CrossReference[data-cross-ref-id]')).filter(
    (chip) => !chip.querySelector('.CrossReference-title')
  );

function label(root: HTMLElement) {
  let missing = false;

  for (const chip of untitled(root)) {
    const id = chip.dataset.crossRefId!;
    if (!titles.has(id)) {
      const title = app.store.getById<any>('discussions', id)?.title();
      if (title) titles.set(id, title);
    }

    const title = titles.get(id);
    if (title === undefined) {
      missing ||= !loading.has(id);
      continue;
    }
    if (title === null) continue;

    // Same markup as the server's template: `#12 Title (post #3)`.
    const span = document.createElement('span');
    span.className = 'CrossReference-title';
    span.textContent = title;
    chip.querySelector('.CrossReference-id')?.after(' ', span);
  }

  if (missing) {
    clearTimeout(timer);
    timer = setTimeout(fetchTitles, 300);
  }
}

function fetchTitles() {
  const wanted = new Set<string>();
  previews.forEach((root) => untitled(root).forEach((chip) => wanted.add(chip.dataset.crossRefId!)));
  const ids = Array.from(wanted)
    .filter((id) => !titles.has(id) && !loading.has(id))
    .slice(0, 20);
  if (!ids.length) return;
  ids.forEach((id) => loading.add(id));

  app
    .request<{ data: Record<string, string> }>({
      method: 'GET',
      url: `${app.forum.attribute('apiUrl')}/cross-references/titles`,
      params: { ids: ids.join(',') },
    })
    .then(
      (response) => ids.forEach((id) => titles.set(id, response.data[id] ?? null)),
      () => ids.forEach((id) => titles.set(id, null))
    )
    .then(() => {
      ids.forEach((id) => loading.delete(id));
      previews.forEach(label);
    });
}

export default function labelPreviewReferences() {
  extend('flarum/forum/components/ComposerPostPreview', 'oncreate', function (this: any, _: unknown, vnode: any) {
    const root = vnode.dom as HTMLElement;
    previews.add(root);
    // The preview redraws itself every few keystrokes, outside Mithril; a
    // title we add is a mutation too, but the chip then has one and is skipped.
    this.crossRefObserver = new MutationObserver(() => label(root));
    this.crossRefObserver.observe(root, { childList: true, subtree: true, characterData: true });
    label(root);
  });

  extend('flarum/forum/components/ComposerPostPreview', 'onremove', function (this: any, _: unknown, vnode: any) {
    this.crossRefObserver?.disconnect();
    previews.delete(vnode.dom);
  });
}

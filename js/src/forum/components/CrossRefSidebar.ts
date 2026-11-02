import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';

/**
 * Sidebar widget listing inbound cross-references to the current discussion.
 *
 * Fetches /api/discussions/{id}/cross-references on mount and on
 * discussionId change. Re-fetches are debounced through a per-instance
 * loadKey so a fast sidebar re-mount from a route change doesn't fire two
 * concurrent requests against the same id.
 *
 * Visibility filtering happens server-side (§5 — actor's whereVisibleTo
 * scope is applied) so we trust the rows we get back without re-checking
 * in JS.
 */
export default class CrossRefSidebar extends Component {
  // State (assigned in oninit / load — definite-assignment asserted).
  loading!: boolean;
  error!: string | null;
  refs!: any[];
  cappedAt50!: boolean;
  loadKey!: number | string | null;
  // Phones only: the widget sits above the first post there, so it starts
  // as one row and opens on tap. Desktop CSS ignores this.
  expanded!: boolean;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.loading = true;
    this.error = null;
    this.refs = [];
    this.cappedAt50 = false;
    this.loadKey = null;
    this.expanded = false;
    this.load(vnode.attrs.discussionId);
  }

  onupdate(vnode: any) {
    if (vnode.attrs.discussionId !== this.loadKey) {
      this.load(vnode.attrs.discussionId);
    }
  }

  load(discussionId: number | string) {
    if (!discussionId) return;
    this.loadKey = discussionId;
    this.expanded = false;
    this.loading = true;
    this.error = null;

    // app.request(), not app.store, on purpose: a read-only per-discussion
    // aggregate fetched once; see ListInboundReferencesController for why.
    app
      .request({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/discussions/${discussionId}/cross-references`,
      })
      .then((response: any) => {
        if (this.loadKey !== discussionId) return; // stale response — discard
        this.refs = this.group(Array.isArray(response?.data) ? response.data : []);
        this.cappedAt50 = !!response?.meta?.capped50;
        this.loading = false;
        m.redraw();
      })
      .catch((e: any) => {
        if (this.loadKey !== discussionId) return;
        this.refs = [];
        this.loading = false;
        this.error = e?.response?.error || app.translator.trans('ernestdefoe-cross-references.forum.sidebar.load_error');
        m.redraw();
      });
  }

  /**
   * One row per citing discussion, not per citing post: a thread that cites
   * this one three times is listed once, with its newest citation and a
   * count. Rows arrive newest first.
   */
  group(rows: any[]) {
    const byDiscussion = new Map<string, any>();

    rows.forEach((ref) => {
      const key = String(ref.sourceDiscussionId);
      const seen = byDiscussion.get(key);

      if (seen) seen.mentions++;
      else byDiscussion.set(key, { ...ref, mentions: 1 });
    });

    return [...byDiscussion.values()];
  }

  view() {
    if (this.loading) {
      return m('.Sidebar-CrossReferences', m(LoadingIndicator, { display: 'inline', size: 'small' }));
    }

    if (this.error) {
      return m('.Sidebar-CrossReferences', m('p.muted', this.error));
    }

    if (!this.refs.length) {
      return null; // hide silently when nothing references this discussion
    }

    return m('.Sidebar-CrossReferences', { className: this.expanded ? 'is-expanded' : '' }, [
      m('h4.Sidebar-heading', [
        m(
          'button.CrossReferences-toggle',
          {
            type: 'button',
            'aria-expanded': this.expanded ? 'true' : 'false',
            onclick: () => {
              this.expanded = !this.expanded;
            },
          },
          [
            m('i.icon.fas.fa-link'),
            ' ',
            app.translator.trans('ernestdefoe-cross-references.forum.sidebar.heading', { count: this.refs.length }),
            m('i.icon.fas.fa-chevron-down.CrossReferences-chevron', { 'aria-hidden': 'true' }),
          ]
        ),
      ]),
      m(
        'ul.CrossReferences-list',
        this.refs.map((ref: any) =>
          m(
            'li.CrossReferences-item',
            m(
              'a.CrossReferences-link',
              {
                href: app.route('discussion', { id: `${ref.sourceDiscussionId}${ref.source.discussionSlug ? '-' + ref.source.discussionSlug : ''}` }),
                onclick: (e: MouseEvent) => {
                  if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
                  e.preventDefault();
                  const href = (e.target as HTMLElement).closest('a')?.getAttribute('href');
                  if (href) m.route.set(href);
                },
              },
              [
                m('span.CrossReferences-title', ref.source.discussionTitle || `#${ref.sourceDiscussionId}`),
                m('span.CrossReferences-meta', [
                  ref.source.author
                    ? app.translator.trans('ernestdefoe-cross-references.forum.sidebar.by_user', {
                        username: ref.source.author.displayName,
                      })
                    : null,
                  ' · ',
                  humanTime(ref.createdAt),
                  ref.mentions > 1
                    ? [' · ', app.translator.trans('ernestdefoe-cross-references.forum.sidebar.mentions', { count: ref.mentions })]
                    : null,
                ]),
              ]
            )
          )
        )
      ),
      this.cappedAt50 ? m('p.muted.CrossReferences-cap', app.translator.trans('ernestdefoe-cross-references.forum.sidebar.capped_notice')) : null,
    ]);
  }
}

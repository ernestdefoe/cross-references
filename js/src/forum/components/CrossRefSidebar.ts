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

  oninit(vnode: any) {
    super.oninit(vnode);
    this.loading = true;
    this.error = null;
    this.refs = [];
    this.cappedAt50 = false;
    this.loadKey = null;
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
    this.loading = true;
    this.error = null;

    app
      .request({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/discussions/${discussionId}/cross-references`,
      })
      .then((response: any) => {
        if (this.loadKey !== discussionId) return; // stale response — discard
        this.refs = Array.isArray(response?.data) ? response.data : [];
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

    return m('.Sidebar-CrossReferences', [
      m('h4.Sidebar-heading', [
        m('i.icon.fas.fa-link'),
        ' ',
        app.translator.trans('ernestdefoe-cross-references.forum.sidebar.heading', { count: this.refs.length }),
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
                ]),
              ]
            )
          )
        )
      ),
      this.cappedAt50
        ? m('p.muted.CrossReferences-cap',
            app.translator.trans('ernestdefoe-cross-references.forum.sidebar.capped_notice'))
        : null,
    ]);
  }
}

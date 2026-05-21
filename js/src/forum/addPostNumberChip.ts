// @ts-nocheck — TODO: declare class properties + parameter types
// Transitional marker from the audit-driven TS conversion. The
// underlying JS uses Flarum's `this.foo = ...` initialiser pattern
// which TypeScript strict mode rejects. Remove once a follow-up pass
// adds explicit property declarations and vnode/callback types.
import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';

/**
 * Adds a small "#N" chip to every comment post's header.
 *
 * The post number is the per-discussion sequential index (1, 2, 3...),
 * already on the model as post.number(). Clicking the chip copies the
 * canonical cross-reference string for that post — `#{discussionId}/p{N}`
 * — to the clipboard, so the next reader can paste it straight into
 * their reply.
 *
 * Only runs for CommentPost (not event posts like "tester referenced this
 * from #6"), since event posts don't have a useful post number in the
 * cross-reference sense.
 */
export default function addPostNumberChip() {
  extend(CommentPost.prototype, 'headerItems', function (items) {
    const post = this.attrs.post;
    if (!post) return;

    const discussionId = post.discussion()?.id();
    const postNumber = post.number();
    if (!discussionId || !postNumber) return;

    const ref = `#${discussionId}/p${postNumber}`;

    items.add(
      'crossRefPostNumber',
      m(
        'button.Button.Button--text.PostHeader-crossRefNumber',
        {
          type: 'button',
          title: app.translator.trans(
            'ernestdefoe-cross-references.forum.post_number.tooltip',
            { ref }
          ),
          onclick: async (e) => {
            e.preventDefault();
            e.stopPropagation();
            try {
              await navigator.clipboard.writeText(ref);
              app.alerts.show(
                { type: 'success', dismissible: true, autoshow: true },
                app.translator.trans(
                  'ernestdefoe-cross-references.forum.post_number.copied',
                  { ref }
                )
              );
            } catch {
              /* Clipboard API can fail on insecure-origin / pre-permission;
                 fall back to a selection + execCommand so the user still
                 gets the value to copy manually. */
              const temp = document.createElement('textarea');
              temp.value = ref;
              temp.style.position = 'fixed';
              temp.style.opacity = '0';
              document.body.appendChild(temp);
              temp.select();
              try {
                document.execCommand('copy');
                app.alerts.show(
                  { type: 'success', dismissible: true, autoshow: true },
                  app.translator.trans(
                    'ernestdefoe-cross-references.forum.post_number.copied',
                    { ref }
                  )
                );
              } catch {
                app.alerts.show(
                  { type: 'error', dismissible: true },
                  app.translator.trans(
                    'ernestdefoe-cross-references.forum.post_number.copy_failed',
                    { ref }
                  )
                );
              } finally {
                document.body.removeChild(temp);
              }
            }
          },
        },
        [m('i.fas.fa-hashtag.PostHeader-crossRefNumber-icon'), postNumber]
      ),
      50 // priority — between user (100) and meta (default 0) so it sits before the timestamp
    );
  });
}

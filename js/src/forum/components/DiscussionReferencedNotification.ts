// @ts-nocheck — TODO: declare class properties + parameter types
// Transitional marker from the audit-driven TS conversion. The
// underlying JS uses Flarum's `this.foo = ...` initialiser pattern
// which TypeScript strict mode rejects. Remove once a follow-up pass
// adds explicit property declarations and vnode/callback types.
import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';

/**
 * Notification renderer for the DiscussionReferencedBlueprint.
 *
 * Subject = the SOURCE discussion (per blueprint contract — what the
 * recipient navigates to, not their own target). All display data comes
 * from the live subject relation, not from the notification's data column
 * (§19: data is IDs only; the subject relation is visibility-gated).
 */
export default class DiscussionReferencedNotification extends Notification {
  icon() {
    return 'fas fa-link';
  }

  href() {
    const notification = this.attrs.notification;
    const subject = notification.subject();
    if (!subject) return app.route('index');

    const data = notification.content() || {};
    const sourcePostId = data.sourcePostId;
    const slug = subject.slug ? subject.slug() : '';
    const base = app.route('discussion', { id: `${subject.id()}${slug ? '-' + slug : ''}` });
    return sourcePostId ? `${base}#post-${sourcePostId}` : base;
  }

  content() {
    /* Pass strings (not models or vnodes) to the translator — Flarum's
     * preprocessParameters() inspects each param for a `.displayName()`
     * method and throws if a non-model value (string, vnode) sneaks in. */
    const notification = this.attrs.notification;
    const subject = notification.subject();
    return app.translator.trans('ernestdefoe-cross-references.forum.notification.text', {
      discussion: subject ? subject.title() : '',
    });
  }

  excerpt() {
    return '';
  }
}

import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import username from 'flarum/common/helpers/username';

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
    const notification = this.attrs.notification;
    const fromUser = notification.fromUser();
    const subject = notification.subject();
    return app.translator.trans('ernestdefoe-cross-references.forum.notification.text', {
      user: username(fromUser),
      discussion: subject ? subject.title() : '',
    });
  }

  excerpt() {
    return '';
  }
}

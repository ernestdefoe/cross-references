import app from 'flarum/forum/app';
import EventPost from 'flarum/forum/components/EventPost';
import icon from 'flarum/common/helpers/icon';

/**
 * Renders an inline "Referenced from #X" event-post inside the target
 * discussion. The post's `content` column carries IDs only (per §19); we
 * render a link by id and let routing resolve the slug. Title text uses
 * the locale string with the source discussion id as the {ref} parameter
 * so translators can rearrange wording per language.
 */
export default class CrossReferenceEventPost extends EventPost {
  static initAttrs(attrs) {
    super.initAttrs(attrs);
  }

  icon() {
    return 'fas fa-link';
  }

  descriptionKey() {
    return 'ernestdefoe-cross-references.forum.event_post.text';
  }

  descriptionData() {
    const post = this.attrs.post;
    const content = post.content() || {};
    const sourceId = content.sourceDiscussionId;
    const targetPostId = content.targetPostId;
    const href = sourceId ? app.route('discussion', { id: sourceId }) : '#';

    return {
      ref: m(
        'a.CrossReference-eventLink',
        {
          href,
          onclick: (e) => {
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
            if (!sourceId) return;
            e.preventDefault();
            m.route.set(href);
          },
        },
        `#${sourceId}`
      ),
      // Suffix indicating whether the ref points at a specific post.
      suffix: targetPostId
        ? app.translator.trans('ernestdefoe-cross-references.forum.event_post.post_specific')
        : '',
    };
  }
}

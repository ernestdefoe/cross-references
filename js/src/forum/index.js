import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import DiscussionPage from 'flarum/forum/components/DiscussionPage';

import CrossRefSidebar from './components/CrossRefSidebar';
import CrossReferenceEventPost from './components/CrossReferenceEventPost';
import DiscussionReferencedNotification from './components/DiscussionReferencedNotification';
import addPostNumberChip from './addPostNumberChip';

app.initializers.add('ernestdefoe-cross-references', () => {
  app.postComponents.crossReference = CrossReferenceEventPost;
  app.notificationComponents.discussionReferenced = DiscussionReferencedNotification;

  addPostNumberChip();

  /* Sidebar widget — render after the existing sidebar items so we don't
   * displace tag controls or moderation controls. Priority -100 puts us at
   * the bottom of the column. */
  extend(DiscussionPage.prototype, 'sidebarItems', function (items) {
    const discussion = this.discussion;
    if (!discussion) return;
    items.add(
      'crossReferences',
      m(CrossRefSidebar, { discussionId: discussion.id() }),
      -100
    );
  });
});

<?php

namespace Ernestdefoe\CrossReferences\Listener;

use Ernestdefoe\CrossReferences\Job\SyncReferencesJob;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Illuminate\Contracts\Queue\Queue;

/**
 * After a comment is posted or revised, queue a job to reconcile its
 * cross-references (parse the post's CROSSREF tags, diff against existing
 * cross_references rows, create/delete backlink event-posts, notify target
 * authors). The actual work runs off-request in {@see SyncReferencesJob} so a
 * post with many references — or a slow notification path — never blocks the
 * post save.
 */
class SyncReferencesOnPostSave
{
    public function __construct(
        protected Queue $queue
    ) {}

    public function handle(Posted|Revised $event): void
    {
        $post = $event->post;
        if (! $post instanceof CommentPost) {
            return;
        }

        $this->queue->push(new SyncReferencesJob((int) $post->id));
    }
}

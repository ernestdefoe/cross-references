<?php

namespace Ernestdefoe\CrossReferences\Listener;

use Ernestdefoe\CrossReferences\Model\CrossReference;
use Ernestdefoe\CrossReferences\Notification\DiscussionReferencedBlueprint;
use Ernestdefoe\CrossReferences\Post\CrossReferenceEventPost;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * After a comment is posted or revised, walk its parsed XML for CROSSREF
 * tags, diff against the cross_references rows we already have for this
 * source post, and reconcile:
 *
 *   - INSERT rows for newly-added references (source author starts mentioning
 *     a discussion they weren't before).
 *   - DELETE rows for removed references (edit drops a `#42`).
 *   - For each NEW ref, optionally create a CrossReferenceEventPost in the
 *     target so the target's reading audience sees the backlink. Optionally
 *     dispatch a DiscussionReferencedBlueprint notification to the target
 *     discussion's author (deduped — the unique index on cross_references
 *     prevents two listener runs from inserting two rows; a re-emit of the
 *     notification for an existing ref is suppressed by the early-exit on
 *     the inserted-id list).
 *
 * Self-references (post mentions its own discussion) are silently ignored —
 * not useful as a backlink and would spam the source thread.
 */
class SyncReferencesOnPostSave
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected NotificationSyncer $notifications,
        protected LoggerInterface $log,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Posted::class, [$this, 'handle']);
        $events->listen(Revised::class, [$this, 'handle']);
    }

    public function handle(Posted|Revised $event): void
    {
        try {
            $post = $event->post;
            if (! $post instanceof CommentPost) {
                return;
            }

            $extracted = $this->extractRefsFromXml((string) $post->parsed_content);

            /**
             * Dedupe inside the post — if the user writes "#42 #42 #42" we
             * want one ref row, not three. Keyed by (target_discussion_id,
             * target_post_id ?? 0) so #42 and #42/p7 are distinct.
             */
            $unique = [];
            foreach ($extracted as $ref) {
                if ((int) $ref['discussionId'] === (int) $post->discussion_id) {
                    // Self-reference — skip silently.
                    continue;
                }
                $key = $ref['discussionId'] . ':' . ($ref['postId'] ?? '0');
                $unique[$key] = $ref;
            }

            $existing = CrossReference::query()
                ->where('source_post_id', $post->id)
                ->get(['id', 'target_discussion_id', 'target_post_id'])
                ->keyBy(fn (CrossReference $r) => $r->target_discussion_id . ':' . ((int) $r->target_post_id));

            $newKeys = array_diff(array_keys($unique), $existing->keys()->all());
            $goneKeys = array_diff($existing->keys()->all(), array_keys($unique));

            // Delete removed refs first (so their backlink event-posts stay
            // but the relationship row goes away; we deliberately leave the
            // event-post in place — moderation history.)
            if (! empty($goneKeys)) {
                CrossReference::query()
                    ->where('source_post_id', $post->id)
                    ->whereIn('id', $existing->only($goneKeys)->pluck('id'))
                    ->delete();
            }

            if (empty($newKeys)) {
                return;
            }

            $createBacklinks = (bool) $this->settings->get('ernestdefoe-cross-references.createBacklinks', true);
            $notifyAuthor    = (bool) $this->settings->get('ernestdefoe-cross-references.notifyAuthor', true);

            foreach ($newKeys as $key) {
                $ref = $unique[$key];

                $row = CrossReference::query()->create([
                    'source_post_id'       => $post->id,
                    'source_discussion_id' => $post->discussion_id,
                    'target_discussion_id' => (int) $ref['discussionId'],
                    'target_post_id'       => $ref['postId'] !== null ? (int) $ref['postId'] : null,
                ]);

                if ($createBacklinks) {
                    $eventPost = CrossReferenceEventPost::reply(
                        targetDiscussionId: (int) $ref['discussionId'],
                        sourceUserId:       (int) $post->user_id,
                        sourceDiscussionId: (int) $post->discussion_id,
                        sourcePostId:       (int) $post->id,
                        targetPostId:       $ref['postId'] !== null ? (int) $ref['postId'] : null,
                    );
                    $eventPost->save();

                    /**
                     * Bump the target discussion's post count / last-posted
                     * timestamps so the backlink surfaces in the discussion
                     * list "recent activity" feed. We touch the target via
                     * the event post's own discussion relation; the post
                     * count is auto-incremented by Flarum core's
                     * Post::saved listener.
                     */
                }

                if ($notifyAuthor) {
                    $target = $row->targetDiscussion()->first();
                    if ($target !== null && (int) $target->user_id !== (int) $post->user_id) {
                        $recipient = User::find($target->user_id);
                        if ($recipient !== null && $recipient->can('viewForum')) {
                            $blueprint = new DiscussionReferencedBlueprint($row);
                            $this->notifications->sync($blueprint, [$recipient]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Never let a cross-ref bug block a post-save. The post is the
            // user's actual content; refs are best-effort metadata.
            $this->log->error('[cross-references] SyncReferencesOnPostSave failed', [
                'post_id'   => $event->post->id ?? null,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pull CROSSREF tags out of parsed XML.
     *
     * @return list<array{discussionId:int, postId:int|null}>
     */
    protected function extractRefsFromXml(string $xml): array
    {
        if ($xml === '' || ! str_contains($xml, '<CROSSREF')) {
            return [];
        }

        // Greedy single-pass extraction. The render callback writes title /
        // visible attributes back into the XML — we only care about id and
        // postnum here.
        if (! preg_match_all('/<CROSSREF\b([^>]*)\/?>/', $xml, $tagMatches)) {
            return [];
        }

        $refs = [];
        foreach ($tagMatches[1] as $attrs) {
            if (! preg_match('/\bid="(\d+)"/', $attrs, $idMatch)) {
                continue;
            }
            $postnum = null;
            if (preg_match('/\bpostnum="(\d+)"/', $attrs, $postMatch)) {
                $n = (int) $postMatch[1];
                if ($n > 0) {
                    $postnum = $n;
                }
            }

            // postnum → postId resolution: the tag stores a post NUMBER
            // (the per-discussion index, 1, 2, 3...). We need the actual
            // post id for the cross_references row's target_post_id. Look
            // it up lazily here — most posts have no post-level refs so
            // this rarely fires.
            $postId = null;
            if ($postnum !== null) {
                $postId = \Flarum\Post\Post::query()
                    ->where('discussion_id', (int) $idMatch[1])
                    ->where('number', $postnum)
                    ->value('id');
                if ($postId === null) {
                    // Post number didn't resolve — drop the post-level ref,
                    // fall back to a discussion-level ref.
                    $postId = null;
                }
            }

            $refs[] = [
                'discussionId' => (int) $idMatch[1],
                'postId'       => $postId !== null ? (int) $postId : null,
            ];
        }

        return $refs;
    }
}

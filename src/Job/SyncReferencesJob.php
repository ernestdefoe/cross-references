<?php

namespace Ernestdefoe\CrossReferences\Job;

use Ernestdefoe\CrossReferences\Model\CrossReference;
use Ernestdefoe\CrossReferences\Notification\DiscussionReferencedBlueprint;
use Ernestdefoe\CrossReferences\Post\CrossReferenceEventPost;
use Flarum\Discussion\Discussion;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Off-request reconciliation of a post's cross-references. Parsing XML, creating
 * backlink event-posts and dispatching notifications can be non-trivial work and
 * (for notifications) touch other discussions, so it runs on the queue rather
 * than inline on every post save.
 *
 * @see \Ernestdefoe\CrossReferences\Listener\SyncReferencesOnPostSave
 */
class SyncReferencesJob extends AbstractJob
{
    /** Hard cap on references reconciled per post — bounds work on a paste-bomb. */
    private const MAX_REFS = 50;

    public function __construct(
        protected int $postId
    ) {}

    public function handle(
        SettingsRepositoryInterface $settings,
        NotificationSyncer $notifications,
        LoggerInterface $log
    ): void {
        try {
            $post = Post::query()->find($this->postId);
            if (! $post instanceof CommentPost) {
                return;
            }
            $this->sync($post, $settings, $notifications, $log);
        } catch (QueryException $e) {
            // Transient/operational (missing table, lock timeout, FK). Log with
            // the SQLSTATE; the post itself is already saved, so nothing is lost.
            $log->error('[cross-references] sync failed: database error', [
                'post_id'   => $this->postId,
                'sqlstate'  => $e->getCode(),
                'exception' => $e,
            ]);
        } catch (\Throwable $e) {
            // Deterministic bugs — logged with full stack trace (not silently
            // swallowed) so they're diagnosable, while never failing the job.
            $log->error('[cross-references] sync failed: unexpected error', [
                'post_id'   => $this->postId,
                'exception' => $e,
            ]);
        }
    }

    protected function sync(
        CommentPost $post,
        SettingsRepositoryInterface $settings,
        NotificationSyncer $notifications,
        LoggerInterface $log
    ): void {
        $unique  = $this->uniqueRefs($post, $log);
        $newKeys = $this->reconcile($post, $unique);

        if (empty($newKeys)) {
            return;
        }

        $createBacklinks = (bool) $settings->get('ernestdefoe-cross-references.createBacklinks', true);
        $notifyAuthor    = (bool) $settings->get('ernestdefoe-cross-references.notifyAuthor', true);

        [$targets, $authors] = $notifyAuthor
            ? $this->loadTargets($unique, $newKeys)
            : [new Collection(), new Collection()];

        foreach ($newKeys as $key) {
            $ref = $unique[$key];

            $row = CrossReference::query()->create([
                'source_post_id'       => $post->id,
                'source_discussion_id' => $post->discussion_id,
                'target_discussion_id' => (int) $ref['discussionId'],
                'target_post_id'       => $ref['postId'] !== null ? (int) $ref['postId'] : null,
            ]);

            if ($createBacklinks) {
                $this->createBacklink($post, $ref);
            }

            if ($notifyAuthor) {
                $this->maybeNotify($post, $ref, $row, $targets, $authors, $notifications);
            }
        }
    }

    /**
     * Diff the desired reference set against what's stored, delete relationship
     * rows for removed references (their backlink event-posts stay — moderation
     * history), and return the keys of references that are newly added.
     *
     * @param  array<string, array{discussionId:int, postId:int|null}>  $unique
     * @return list<string>
     */
    protected function reconcile(CommentPost $post, array $unique): array
    {
        $existing = CrossReference::query()
            ->where('source_post_id', $post->id)
            ->get(['id', 'target_discussion_id', 'target_post_id'])
            ->keyBy(fn (CrossReference $r) => $r->target_discussion_id . ':' . ((int) $r->target_post_id));

        $newKeys  = array_values(array_diff(array_keys($unique), $existing->keys()->all()));
        $goneKeys = array_diff($existing->keys()->all(), array_keys($unique));

        if (! empty($goneKeys)) {
            CrossReference::query()
                ->where('source_post_id', $post->id)
                ->whereIn('id', $existing->only($goneKeys)->pluck('id'))
                ->delete();
        }

        return $newKeys;
    }

    /**
     * Parsed, deduped, self-reference-stripped, capped reference set for the
     * post, keyed by "targetDiscussionId:targetPostId" with postnum resolved to
     * a real post id.
     *
     * @return array<string, array{discussionId:int, postId:int|null}>
     */
    protected function uniqueRefs(CommentPost $post, LoggerInterface $log): array
    {
        $extracted = $this->extractRefsFromXml((string) $post->parsed_content);

        $unique = [];
        foreach ($extracted as $ref) {
            if ((int) $ref['discussionId'] === (int) $post->discussion_id) {
                continue; // self-reference — skip silently
            }
            $key = $ref['discussionId'] . ':' . ($ref['postId'] ?? '0');
            $unique[$key] = $ref;

            if (count($unique) >= self::MAX_REFS) {
                $log->info('[cross-references] post ' . $post->id . ' hit the '
                    . self::MAX_REFS . '-reference cap; extra references were ignored.');
                break;
            }
        }

        return $unique;
    }

    /**
     * Pull CROSSREF tags out of parsed XML, resolving every postnum → post id in
     * a SINGLE batch query (was one lookup per post-level reference — an N+1).
     *
     * @return list<array{discussionId:int, postId:int|null}>
     */
    protected function extractRefsFromXml(string $xml): array
    {
        if ($xml === '' || ! str_contains($xml, '<CROSSREF')) {
            return [];
        }
        if (! preg_match_all('/<CROSSREF\b([^>]*)\/?>/', $xml, $tagMatches)) {
            return [];
        }

        // First pass: collect (discussionId, postnum) pairs.
        $raw = [];
        foreach ($tagMatches[1] as $attrs) {
            if (! preg_match('/\bid="(\d+)"/', $attrs, $idMatch)) {
                continue;
            }
            $postnum = null;
            if (preg_match('/\bpostnum="(\d+)"/', $attrs, $postMatch) && (int) $postMatch[1] > 0) {
                $postnum = (int) $postMatch[1];
            }
            $raw[] = ['discussionId' => (int) $idMatch[1], 'postnum' => $postnum];
        }

        $postIdByKey = $this->resolvePostNumbers($raw);

        $refs = [];
        foreach ($raw as $r) {
            $postId = null;
            if ($r['postnum'] !== null) {
                $postId = $postIdByKey[$r['discussionId'] . ':' . $r['postnum']] ?? null;
            }
            $refs[] = ['discussionId' => $r['discussionId'], 'postId' => $postId];
        }

        return $refs;
    }

    /**
     * Resolve all (discussionId, postnum) pairs to post ids in one query.
     *
     * @param  list<array{discussionId:int, postnum:int|null}>  $raw
     * @return array<string, int>  keyed "discussionId:postnum"
     */
    protected function resolvePostNumbers(array $raw): array
    {
        $discussionIds = [];
        $numbers = [];
        foreach ($raw as $r) {
            if ($r['postnum'] !== null) {
                $discussionIds[$r['discussionId']] = true;
                $numbers[$r['postnum']] = true;
            }
        }
        if (empty($numbers)) {
            return [];
        }

        $map = [];
        Post::query()
            ->whereIn('discussion_id', array_keys($discussionIds))
            ->whereIn('number', array_keys($numbers))
            ->get(['id', 'discussion_id', 'number'])
            ->each(function ($p) use (&$map) {
                $map[$p->discussion_id . ':' . $p->number] = (int) $p->id;
            });

        return $map;
    }

    /**
     * Batch-load target discussions + their authors for the new refs (two
     * queries total regardless of how many references the post contains).
     *
     * @param  array<string, array{discussionId:int, postId:int|null}>  $unique
     * @param  list<string>  $newKeys
     * @return array{0: Collection, 1: Collection}
     */
    protected function loadTargets(array $unique, array $newKeys): array
    {
        $targetIds = array_values(array_unique(array_map(
            fn (string $k) => (int) $unique[$k]['discussionId'],
            $newKeys
        )));

        $targets = Discussion::query()
            ->whereIn('id', $targetIds)
            ->get(['id', 'user_id'])
            ->keyBy('id');

        $authorIds = $targets->pluck('user_id')->unique()->filter()->all();
        $authors = empty($authorIds)
            ? new Collection()
            : User::query()->whereIn('id', $authorIds)->get()->keyBy('id');

        return [$targets, $authors];
    }

    protected function createBacklink(CommentPost $post, array $ref): void
    {
        $eventPost = CrossReferenceEventPost::reply(
            targetDiscussionId: (int) $ref['discussionId'],
            sourceUserId:       (int) $post->user_id,
            sourceDiscussionId: (int) $post->discussion_id,
            sourcePostId:       (int) $post->id,
            targetPostId:       $ref['postId'] !== null ? (int) $ref['postId'] : null,
        );

        // Direct save() is correct here — the post number IS assigned. Core's
        // Post::boot() registers a `creating` hook (inherited through
        // AbstractEventPost) that sets `number` to MAX(number)+1 for the
        // discussion via a SQL subquery, and a `created` hook that saves the
        // target discussion. We deliberately do NOT route through
        // Discussion::mergePost(): (1) Post doesn't implement
        // MergeableInterface, so CrossReferenceEventPost has no saveAfter() to
        // merge through; (2) mergePost()'s purpose is to coalesce consecutive
        // same-type event posts by the same author (the rename-revert case) —
        // backlinks must stay distinct, never merged; (3) it fires an extra
        // `posts()->latest()->first()` query per backlink we don't need in a
        // batched job. Verified on a live install: a backlink saved as
        // posts.number = 4 in its target discussion.
        $eventPost->save();
    }

    protected function maybeNotify(
        CommentPost $post,
        array $ref,
        CrossReference $row,
        Collection $targets,
        Collection $authors,
        NotificationSyncer $notifications
    ): void {
        $target = $targets->get((int) $ref['discussionId']);
        if ($target === null || (int) $target->user_id === (int) $post->user_id) {
            return;
        }

        $recipient = $authors->get((int) $target->user_id);
        if ($recipient === null) {
            return;
        }

        // Only notify if the recipient can actually see the SOURCE discussion —
        // otherwise the notification links them to content they can't view (and
        // leaks that the source exists).
        $canSeeSource = Discussion::query()
            ->whereVisibleTo($recipient)
            ->whereKey($post->discussion_id)
            ->exists();

        if ($canSeeSource) {
            $notifications->sync(new DiscussionReferencedBlueprint($row), [$recipient]);
        }
    }
}

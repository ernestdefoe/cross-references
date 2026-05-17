<?php

namespace Ernestdefoe\CrossReferences\Post;

use Carbon\Carbon;
use Flarum\Post\AbstractEventPost;

/**
 * The backlink event-post inserted in a target discussion when a source post
 * references it. Renders as "[author] referenced this discussion from
 * [source discussion title]" with current titles resolved at render time.
 *
 * The `content` JSON column carries the structural data needed to render the
 * backlink: source ids + the optional target_post_id. Titles are NEVER stored
 * — the frontend rehydrates them via the source discussion relation so
 * renames flow through automatically.
 */
class CrossReferenceEventPost extends AbstractEventPost
{
    public static string $type = 'crossReference';

    /**
     * Build a fresh event-post pointing at one cross-reference row.
     *
     * @param int      $targetDiscussionId  Where the backlink appears.
     * @param int      $sourceUserId        Author of the source post (the
     *                                      one whose mention created the ref).
     * @param int      $sourceDiscussionId  Discussion that referenced us.
     * @param int      $sourcePostId        Specific post that referenced us.
     * @param int|null $targetPostId        Set if the source mentioned a
     *                                      specific post (#42/p7), not the
     *                                      whole discussion.
     */
    public static function reply(
        int $targetDiscussionId,
        int $sourceUserId,
        int $sourceDiscussionId,
        int $sourcePostId,
        ?int $targetPostId = null,
    ): static {
        $post = new static();

        $post->content = static::buildContent($sourceDiscussionId, $sourcePostId, $targetPostId);
        $post->created_at = Carbon::now();
        $post->discussion_id = $targetDiscussionId;
        $post->user_id = $sourceUserId;

        return $post;
    }

    /**
     * @return array{sourceDiscussionId: int, sourcePostId: int, targetPostId: int|null}
     */
    protected static function buildContent(int $sourceDiscussionId, int $sourcePostId, ?int $targetPostId): array
    {
        return [
            'sourceDiscussionId' => $sourceDiscussionId,
            'sourcePostId'       => $sourcePostId,
            'targetPostId'       => $targetPostId,
        ];
    }
}

<?php

namespace Ernestdefoe\CrossReferences\Api\Controller;

use Ernestdefoe\CrossReferences\Model\CrossReference;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /api/discussions/{id}/cross-references
 *
 * Returns inbound references to this discussion — every (source post →
 * target discussion) row, hydrated with the source discussion title and
 * source author so the sidebar widget can render without a second round
 * trip per row.
 *
 * Visibility (§5): every source discussion is filtered through
 * whereVisibleTo($actor) before payload assembly — refs from restricted
 * tags or private groups never appear in a non-member's sidebar.
 *
 * Target discussion existence check uses whereVisibleTo too: hitting this
 * endpoint for a discussion you can't see returns 404 (not 403), matching
 * core's response shape for unscoped IDs.
 */
class ListInboundReferencesController implements RequestHandlerInterface
{
    public function __construct(protected LoggerInterface $log) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            // Flarum's RouteHandlerFactory merges path params into the query
            // string (see toController() in core), so the {id} route segment
            // surfaces in getQueryParams(), not getAttribute().
            $discussionId = (int) ($request->getQueryParams()['id'] ?? 0);

            if ($discussionId <= 0) {
                return new JsonResponse(['error' => 'Invalid discussion id'], 422);
            }

            $target = Discussion::query()
                ->whereVisibleTo($actor)
                ->find($discussionId);

            if ($target === null) {
                return new JsonResponse(['error' => 'Not found'], 404);
            }

            /**
             * Single eager-loaded query for the row + the source's
             * discussion + first-post author. Capped at 50 — sidebar
             * widget shows the most recent; a future "show all" page can
             * paginate via a dedicated endpoint if needed.
             */
            $refs = CrossReference::query()
                ->with(['sourceDiscussion', 'sourcePost.user'])
                ->where('target_discussion_id', $discussionId)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            // Visibility filter on the source side. We pull the ids back from
            // a visibility-scoped query rather than asserting can() per row —
            // one batched check, no N+1.
            $sourceIds = $refs->pluck('source_discussion_id')->unique()->values();
            $visibleSourceIds = Discussion::query()
                ->whereIn('id', $sourceIds)
                ->whereVisibleTo($actor)
                ->pluck('id')
                ->all();
            $visibleSet = array_flip($visibleSourceIds);

            $data = $refs
                ->filter(fn (CrossReference $r) => isset($visibleSet[$r->source_discussion_id]))
                ->map(function (CrossReference $r): array {
                    return [
                        'id'                 => $r->id,
                        'sourceDiscussionId' => (int) $r->source_discussion_id,
                        'sourcePostId'       => (int) $r->source_post_id,
                        'targetPostId'       => $r->target_post_id !== null ? (int) $r->target_post_id : null,
                        'createdAt'          => $r->created_at?->toIso8601String(),
                        'source'             => [
                            'discussionTitle' => $r->sourceDiscussion?->title,
                            'discussionSlug'  => $r->sourceDiscussion?->slug,
                            'author'          => $r->sourcePost?->user ? [
                                'id'          => (int) $r->sourcePost->user->id,
                                'displayName' => $r->sourcePost->user->display_name,
                                'username'    => $r->sourcePost->user->username,
                                'avatarUrl'   => $r->sourcePost->user->avatar_url,
                            ] : null,
                        ],
                    ];
                })
                ->values();

            return new JsonResponse([
                'data' => $data,
                'meta' => [
                    'count'    => $data->count(),
                    'capped50' => $refs->count() >= 50,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->log->error('[cross-references] ListInboundReferencesController failed', [
                'discussion_id' => $request->getAttribute('id'),
                'exception'     => get_class($e),
                'message'       => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}

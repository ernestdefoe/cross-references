<?php

namespace Ernestdefoe\CrossReferences\Api\Controller;

use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Titles for the composer preview: `?ids=1,12` → `{"data": {"1": "…"}}`.
 * The preview is built in the browser from the raw text, so `#12` has no title
 * until the post is saved; this fills it in while the writer types. One query,
 * visibility-scoped to the actor: a discussion they can't see is simply absent.
 */
class ListTitlesController implements RequestHandlerInterface
{
    /** A preview rarely names more; anything past this waits for the save. */
    public const MAX_IDS = 20;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) ($request->getQueryParams()['ids'] ?? ''))),
            fn (int $id) => $id > 0
        ))), 0, self::MAX_IDS);

        $titles = $ids === [] ? [] : Discussion::query()
            ->whereVisibleTo(RequestUtil::getActor($request))
            ->whereIn('id', $ids)
            ->pluck('title', 'id')
            ->all();

        // An object even when empty, so the client never gets `[]`.
        return new JsonResponse(['data' => (object) $titles]);
    }
}

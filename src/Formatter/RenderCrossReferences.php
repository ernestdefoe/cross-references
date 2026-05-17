<?php

namespace Ernestdefoe\CrossReferences\Formatter;

use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;
use s9e\TextFormatter\Renderer;

/**
 * Pre-render enrichment: walk every <CROSSREF id="…"> tag in the XML, look
 * up the current discussion title from the database (one batched query for
 * all refs in the document), and inject `title=` + `visible=` attributes so
 * the XSL template can render the live title with no second query per ref.
 *
 * Visibility is checked against the request actor via Discussion's
 * whereVisibleTo scope (§5 of CLAUDE.md). Refs the actor cannot view render
 * as `#42` with the `CrossReference--hidden` modifier — never leaking the
 * target title.
 *
 * This is the source-of-truth for "renames flow through automatically":
 * titles are never persisted in the post content, only resolved on each
 * render against current DB state.
 */
class RenderCrossReferences
{
    public function __invoke(
        Renderer $renderer,
        mixed $context,
        string $xml,
        ?ServerRequestInterface $request = null,
    ): string {
        if (! str_contains($xml, '<CROSSREF ')) {
            return $xml;
        }

        // Collect every referenced id in one pass — set semantics, no dupes.
        if (! preg_match_all('/<CROSSREF\b[^>]*\bid="(\d+)"/', $xml, $matches)) {
            return $xml;
        }

        $ids = array_values(array_unique(array_map('intval', $matches[1])));
        if (empty($ids)) {
            return $xml;
        }

        $actor = $request !== null ? RequestUtil::getActor($request) : null;

        /**
         * Single batched fetch with visibility scope applied. Anything the
         * actor cannot see simply doesn't come back — we then render those
         * refs as `<span class="CrossReference--hidden">#42</span>` so the
         * title never lands in the HTML for a non-viewer.
         */
        $query = Discussion::query()->whereIn('id', $ids);
        if ($actor !== null) {
            $query->whereVisibleTo($actor);
        }

        $titles = $query->pluck('title', 'id')->all();

        /**
         * Rewrite each <CROSSREF/> in-place. The s9e parser sometimes emits
         * the tag as self-closing (<CROSSREF .../>) and sometimes paired
         * (<CROSSREF ...>...</CROSSREF>) depending on the surrounding
         * markup — match both shapes.
         */
        return preg_replace_callback(
            '/<CROSSREF\b([^>]*?)(\/?)>/',
            function (array $m) use ($titles): string {
                $attrs = $m[1];
                $closing = $m[2];

                if (! preg_match('/\bid="(\d+)"/', $attrs, $idMatch)) {
                    return $m[0];
                }

                $id = (int) $idMatch[1];
                $existingTitle = isset($titles[$id]) ? (string) $titles[$id] : null;

                /**
                 * Strip any pre-existing title / visible attributes so we
                 * can authoritatively rewrite them — defends against a
                 * caller storing stale state in the post XML.
                 */
                $attrs = preg_replace('/\s+(title|visible)="[^"]*"/', '', $attrs);

                if ($existingTitle === null) {
                    $attrs .= ' visible="0"';
                } else {
                    $attrs .= ' visible="1" title="' . htmlspecialchars($existingTitle, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"';
                }

                return '<CROSSREF' . $attrs . $closing . '>';
            },
            $xml,
        ) ?? $xml;
    }
}

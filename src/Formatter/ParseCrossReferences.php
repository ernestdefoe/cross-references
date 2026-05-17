<?php

namespace Ernestdefoe\CrossReferences\Formatter;

use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use s9e\TextFormatter\Parser;

/**
 * Pre-parse: rewrite pasted forum discussion URLs (the bare ones, not the
 * ones the user explicitly wrapped in markdown link syntax) to the canonical
 * `#42` shorthand so the Preg matcher in ConfigureCrossReferences picks them
 * up and the stored XML always uses CROSSREF tags — single representation,
 * one render path, one listener path.
 *
 * Markdown bracket-wrapped links (`[label](url)`) are left alone — they
 * carry intentional custom text and shouldn't be flattened.
 */
class ParseCrossReferences
{
    public function __construct(protected UrlGenerator $url) {}

    public function __invoke(Parser $parser, mixed $context, string $text, ?User $actor = null): string
    {
        $base = rtrim($this->url->to('forum')->base(), '/');
        if ($base === '') {
            return $text;
        }

        /**
         * Match a bare forum discussion URL outside of a `(...)` or `[...]`
         * bracket context. The trailing assertion stops the match before any
         * `)` or `]` — preventing `[text](https://forum/d/1)` from being
         * captured.
         */
        $quoted = preg_quote($base, '@');
        $pattern = '@'
            . '(?<![\(\[\w])'                            // not preceded by `(` `[` or a word char
            . $quoted
            . '/d/(\d+)(?:-[^/\s<>\)\]]*)?(?:/(\d+))?'   // /d/{id}[-slug][/{post}]
            . '(?![^\s<>\)\]]*[\)\]])'                   // not inside a `()` or `[]` pair
            . '@';

        $replaced = preg_replace_callback($pattern, function (array $m): string {
            $id = (int) $m[1];
            $postNumber = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;

            // Preserve a leading space if there was one before the URL to keep
            // word-boundary detection working downstream.
            return $postNumber > 0 ? "#{$id}/p{$postNumber}" : "#{$id}";
        }, $text);

        return is_string($replaced) ? $replaced : $text;
    }
}

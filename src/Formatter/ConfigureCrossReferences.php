<?php

namespace Ernestdefoe\CrossReferences\Formatter;

use Flarum\Http\UrlGenerator;
use s9e\TextFormatter\Configurator;

/**
 * Registers the CROSSREF tag with the s9e/TextFormatter pipeline.
 *
 * Two parse-time matchers attach to this tag:
 *   1. The `#42` / `#42/p7` inline shortcut (via the Preg plugin here).
 *   2. Pasted forum discussion URLs (handled in ParseCrossReferences, which
 *      runs after Autolink has emitted its URL tags).
 *
 * Render-time enrichment (resolve current discussion title, visibility-check
 * against the actor) is done in RenderCrossReferences before the XSL template
 * fires — so renames flow through automatically and private titles never leak.
 */
class ConfigureCrossReferences
{
    public const TAG = 'CROSSREF';

    public function __construct(protected UrlGenerator $url) {}

    public function __invoke(Configurator $config): void
    {
        $tag = $config->tags->add(self::TAG);

        /**
         * Attribute filters:
         *   - id, postnum: cast to uint at parse time (defeats negative /
         *     non-numeric injection at the tag level).
         *   - title / visible / postId: written by RenderCrossReferences at
         *     render time; no parse-time filter needed.
         */
        $tag->attributes->add('id')->filterChain->append('#uint');
        $tag->attributes->add('postnum')->filterChain->append('#uint');
        $tag->attributes->add('postnum')->required = false;

        // Optional render-time attributes — declared so XSL can reference them
        // without "undefined attribute" warnings on the first render of fresh
        // content.
        $tag->attributes->add('title')->required = false;
        $tag->attributes->add('visible')->required = false;
        $tag->attributes->add('postId')->required = false;

        /**
         * Render parameter: the discussion route prefix
         * (e.g. "https://forum.example/d/"). Captured once at compile time;
         * if the forum URL ever changes the formatter cache must be cleared.
         */
        $config->rendering->parameters['CROSSREF_DISCUSSION_URL'] =
            rtrim($this->url->to('forum')->route('discussion', ['id' => '']), '/') . '/';

        /**
         * XSL template — renders to:
         *
         *   <a class="CrossReference" href="…/d/42[/7]" data-cross-ref-id="42">
         *     #42 · Resolved Title
         *   </a>
         *
         * When `visible="0"` (set by RenderCrossReferences for restricted
         * targets), we render plain "#42" with the `CrossReference--hidden`
         * modifier so the actor never sees a leaked title.
         */
        $tag->template = '
            <xsl:choose>
                <xsl:when test="@visible = \'0\'">
                    <span class="CrossReference CrossReference--hidden" data-cross-ref-id="{@id}">#<xsl:value-of select="@id"/></span>
                </xsl:when>
                <xsl:otherwise>
                    <a class="CrossReference" data-cross-ref-id="{@id}">
                        <xsl:attribute name="data-cross-ref-post-number">
                            <xsl:value-of select="@postnum"/>
                        </xsl:attribute>
                        <xsl:attribute name="href">
                            <xsl:value-of select="$CROSSREF_DISCUSSION_URL"/>
                            <xsl:value-of select="@id"/>
                            <xsl:if test="@postnum &gt; 0">
                                <xsl:text>/</xsl:text>
                                <xsl:value-of select="@postnum"/>
                            </xsl:if>
                        </xsl:attribute>
                        <span class="CrossReference-id">#<xsl:value-of select="@id"/></span>
                        <xsl:if test="string(@title) != \'\'">
                            <xsl:text> </xsl:text>
                            <span class="CrossReference-title"><xsl:value-of select="@title"/></span>
                        </xsl:if>
                        <xsl:if test="@postnum &gt; 0">
                            <xsl:text> </xsl:text>
                            <span class="CrossReference-postRef">(post #<xsl:value-of select="@postnum"/>)</span>
                        </xsl:if>
                    </a>
                </xsl:otherwise>
            </xsl:choose>';

        /**
         * `#42` and `#42/p7` are registered as two separate Preg matchers
         * because s9e's JS-compatible parser cannot emit optional named
         * captures inside a non-capturing group (would need negative
         * lookbehind support that the JS regexp conversion lacks).
         *
         * `\B#` mirrors flarum/mentions' `\B@` shape: match the `#` only
         * when it isn't immediately preceded by a word char — same
         * semantics as `(?<![A-Za-z0-9_])` without the lookbehind.
         */
        $config->Preg->match(
            '/\B#(?<id>\d+)\/p(?<postnum>\d+)\b/',
            self::TAG
        );
        $config->Preg->match(
            '/\B#(?<id>\d+)\b(?!\/p)/',
            self::TAG
        );
    }
}

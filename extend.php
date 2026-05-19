<?php

use Ernestdefoe\CrossReferences\Api\Controller\ListInboundReferencesController;
use Ernestdefoe\CrossReferences\Formatter\ConfigureCrossReferences;
use Ernestdefoe\CrossReferences\Formatter\ParseCrossReferences;
use Ernestdefoe\CrossReferences\Formatter\RenderCrossReferences;
use Ernestdefoe\CrossReferences\Listener\SyncReferencesOnPostSave;
use Ernestdefoe\CrossReferences\Notification\DiscussionReferencedBlueprint;
use Ernestdefoe\CrossReferences\Post\CrossReferenceEventPost;
use Ernestdefoe\CrossReferences\Search\Filter\ReferencesFilter;
use Flarum\Extend;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\Formatter())
        ->configure(ConfigureCrossReferences::class)
        ->parse(ParseCrossReferences::class)
        ->render(RenderCrossReferences::class),

    (new Extend\Post())
        ->type(CrossReferenceEventPost::class),

    (new Extend\Event())
        ->listen(Posted::class, SyncReferencesOnPostSave::class)
        ->listen(Revised::class, SyncReferencesOnPostSave::class),

    (new Extend\Notification())
        ->type(DiscussionReferencedBlueprint::class, ['alert']),

    (new Extend\SearchDriver(\Flarum\Search\Database\DatabaseSearchDriver::class))
        ->addFilter(\Flarum\Discussion\Search\DiscussionSearcher::class, ReferencesFilter::class),

    (new Extend\Routes('api'))
        ->get(
            '/discussions/{id}/cross-references',
            'cross-references.inbound',
            ListInboundReferencesController::class
        ),

    /**
     * Two backend-only behaviour flags read by SyncReferencesOnPostSave.
     * Default true (both notifications and backlink event-posts on);
     * admins can flip either via the settings panel in admin/extend.js.
     *
     * Intentionally NOT serializeToForum'd — neither value is consumed
     * by the frontend, so pushing them into every forum bootstrap
     * payload would be dead-weight bytes for nothing. The old
     * 'showInline' toggle was removed entirely: the audit caught that
     * no code read it, and the formatter pipeline that renders inline
     * references is the extension's core feature — making it
     * disableable doesn't earn its complexity.
     */
    (new Extend\Settings())
        ->default('ernestdefoe-cross-references.notifyAuthor', true)
        ->default('ernestdefoe-cross-references.createBacklinks', true),
];

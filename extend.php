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

    (new Extend\Settings())
        ->serializeToForum('crossReferences.showInline', 'ernestdefoe-cross-references.showInline', 'boolval', true)
        ->serializeToForum('crossReferences.notifyAuthor', 'ernestdefoe-cross-references.notifyAuthor', 'boolval', true)
        ->serializeToForum('crossReferences.createBacklinks', 'ernestdefoe-cross-references.createBacklinks', 'boolval', true)
        ->default('ernestdefoe-cross-references.showInline', true)
        ->default('ernestdefoe-cross-references.notifyAuthor', true)
        ->default('ernestdefoe-cross-references.createBacklinks', true),
];

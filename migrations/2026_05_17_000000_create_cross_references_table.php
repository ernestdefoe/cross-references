<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTableIfNotExists('cross_references', function (Blueprint $table) {
    $table->bigIncrements('id');

    /**
     * The source side: the post that authored the reference.
     * Cascade on post delete so editing/deleting a post wipes its outbound refs.
     *
     * INT (not BIGINT) to match Flarum core's `increments('id')` on posts
     * and discussions — both are 32-bit `int unsigned`. MySQL FK creation
     * requires exact size/sign match, so widening to bigint here would
     * actually break the FK constraint.
     */
    $table->unsignedInteger('source_post_id');
    $table->unsignedInteger('source_discussion_id');

    /**
     * The target side: a discussion (always) and optionally a specific post
     * inside that discussion. Cascade on discussion delete so removing a
     * discussion wipes inbound refs to it (and stops resurfacing them via
     * the sidebar widget).
     */
    $table->unsignedInteger('target_discussion_id');
    $table->unsignedInteger('target_post_id')->nullable();

    $table->timestamp('created_at')->useCurrent();

    /**
     * Dedupe at the storage layer: one (source_post, target_discussion,
     * target_post) tuple maximum. Re-saving a post that mentions #42 five
     * times still produces one row.
     */
    $table->unique(
        ['source_post_id', 'target_discussion_id', 'target_post_id'],
        'cross_ref_dedupe_unique'
    );

    /**
     * Indexes:
     *  - target_discussion_id: inbound-refs lookup (sidebar widget, search
     *    filter `references:N`).
     *  - source_discussion_id: outbound-refs lookup (used by listener to
     *    suppress duplicate event-posts when the same discussion already
     *    references the target).
     */
    $table->index('target_discussion_id', 'cross_ref_target_idx');
    $table->index('source_discussion_id', 'cross_ref_source_idx');

    $table->foreign('source_post_id')
          ->references('id')->on('posts')
          ->cascadeOnDelete();

    $table->foreign('target_discussion_id')
          ->references('id')->on('discussions')
          ->cascadeOnDelete();
});

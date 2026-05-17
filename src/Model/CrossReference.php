<?php

namespace Ernestdefoe\CrossReferences\Model;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;

/**
 * @property int $id
 * @property int $source_post_id
 * @property int $source_discussion_id
 * @property int $target_discussion_id
 * @property int|null $target_post_id
 * @property \Carbon\Carbon|null $created_at
 *
 * @property-read Post $sourcePost
 * @property-read Discussion $sourceDiscussion
 * @property-read Discussion $targetDiscussion
 * @property-read Post|null $targetPost
 */
class CrossReference extends AbstractModel
{
    protected $table = 'cross_references';

    /**
     * Only created_at is tracked — there's no "update" semantic on a cross-ref
     * row. Edits to a source post DELETE its prior rows and INSERT new ones via
     * the listener (see SyncReferencesOnPostSave), so a row's lifetime is
     * always tied to one (source_post_id, target_*) tuple.
     */
    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected $fillable = [
        'source_post_id',
        'source_discussion_id',
        'target_discussion_id',
        'target_post_id',
    ];

    public function sourcePost()
    {
        return $this->belongsTo(Post::class, 'source_post_id');
    }

    public function sourceDiscussion()
    {
        return $this->belongsTo(Discussion::class, 'source_discussion_id');
    }

    public function targetDiscussion()
    {
        return $this->belongsTo(Discussion::class, 'target_discussion_id');
    }

    public function targetPost()
    {
        return $this->belongsTo(Post::class, 'target_post_id');
    }
}

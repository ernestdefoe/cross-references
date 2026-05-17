<?php

namespace Ernestdefoe\CrossReferences\Notification;

use Ernestdefoe\CrossReferences\Model\CrossReference;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * Notification fired when an actor's discussion gets referenced from another
 * discussion. The blueprint carries the CrossReference row by typed
 * constructor (no implicit subject type — see CLAUDE.md §46.1: polymorphic
 * subject_type corruption is a real risk when subject is set from a free
 * parameter).
 *
 * Subject = the SOURCE discussion (the one doing the referencing). That's
 * what the recipient clicks through to. Data column carries IDs only, never
 * titles or excerpts — those get re-resolved against current DB state at
 * render time (which respects visibility, so a target that's gone private
 * doesn't leak its old title via a stale notification).
 */
class DiscussionReferencedBlueprint implements BlueprintInterface, AlertableInterface
{
    public const TYPE = 'discussionReferenced';

    public function __construct(public CrossReference $reference) {}

    public function getFromUser(): ?User
    {
        return $this->reference->sourcePost?->user;
    }

    /**
     * The subject is the SOURCE discussion — the actor who got notified
     * wants to navigate to "who referenced me". Returning the target here
     * would point them at their own discussion, which is useless.
     */
    public function getSubject(): ?AbstractModel
    {
        return $this->reference->sourceDiscussion;
    }

    /**
     * @return array{sourcePostId:int, sourceDiscussionId:int, targetPostId:int|null}
     */
    public function getData(): array
    {
        return [
            'sourcePostId'       => (int) $this->reference->source_post_id,
            'sourceDiscussionId' => (int) $this->reference->source_discussion_id,
            'targetPostId'       => $this->reference->target_post_id !== null
                ? (int) $this->reference->target_post_id
                : null,
        ];
    }

    public static function getType(): string
    {
        return self::TYPE;
    }

    public static function getSubjectModel(): string
    {
        return Discussion::class;
    }
}

<?php

namespace Ernestdefoe\CrossReferences\Tests\integration\console;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class MigrateLegacyTest extends ConsoleTestCase
{
    use RetrievesAuthorizedUsers;

    /** What club-1/flarum-ext-cross-references stored for "See #1, http://localhost/d/1-target-discussion/2, **#3** and http://localhost/d/1-target-discussion." */
    private const LEGACY = '<r><p>See <CROSSREFERENCESHORT id="1" title="Old &amp; &quot;stale&quot; title">#1</CROSSREFERENCESHORT>, '
        .'<CROSSREFERENCEURLCOMMENT comment="" id="1" title="Old &amp; &quot;stale&quot; title" url="http://localhost/d/1-target-discussion/2">http://localhost/d/1-target-discussion/2</CROSSREFERENCEURLCOMMENT>, '
        .'<STRONG><s>**</s><CROSSREFERENCESHORT id="3" title="Staff secrets">#3</CROSSREFERENCESHORT><e>**</e></STRONG> and '
        .'<CROSSREFERENCEURL id="1" title="Old &amp; &quot;stale&quot; title" url="http://localhost/d/1-target-discussion">http://localhost/d/1-target-discussion</CROSSREFERENCEURL>.</p></r>';

    private const MIGRATED = '<r><p>See <CROSSREF id="1">#1</CROSSREF>, <CROSSREF id="1" postnum="2">#1/p2</CROSSREF>, '
        .'<STRONG><s>**</s><CROSSREF id="3">#3</CROSSREF><e>**</e></STRONG> and <CROSSREF id="1">#1</CROSSREF>.</p></r>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-cross-references');

        $discussion = fn (int $id, string $title, int $user) => [
            'id' => $id, 'title' => $title, 'slug' => strtolower(str_replace(' ', '-', $title)), 'created_at' => Carbon::now(),
            'last_posted_at' => Carbon::now(), 'user_id' => $user, 'first_post_id' => $id, 'last_post_number' => 2, 'comment_count' => 2,
        ];
        $post = fn (int $id, int $discussion, int $number, int $user, string $content = '<t><p>Post</p></t>') => [
            'id' => $id, 'discussion_id' => $discussion, 'number' => $number, 'user_id' => $user,
            'type' => 'comment', 'content' => $content, 'created_at' => Carbon::now()->subYear(),
        ];

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'target_author', 'email' => 'target@machine.local', 'is_email_confirmed' => 1],
            ],
            Tag::class => [
                ['id' => 9, 'name' => 'Everyone', 'slug' => 'everyone', 'position' => 1],
                ['id' => 1, 'name' => 'Staff', 'slug' => 'staff', 'position' => 0, 'is_restricted' => true],
            ],
            Discussion::class => [
                $discussion(1, 'Target discussion', 3),
                $discussion(2, 'Source discussion', 2),
                $discussion(3, 'Staff secrets', 3),
            ],
            'discussion_tag' => [
                ['discussion_id' => 3, 'tag_id' => 1],
            ],
            Post::class => [
                $post(1, 1, 1, 3),
                $post(5, 1, 2, 3),
                $post(2, 2, 1, 2, self::LEGACY),
                $post(6, 2, 2, 2, '<r><p>Already ours: <CROSSREF id="1">#1</CROSSREF></p></r>'),
                $post(3, 3, 1, 3),
            ],
        ]);
    }

    private function migrate(bool $dryRun = false): string
    {
        return $this->runCommand(['command' => 'crossref:migrate-legacy'] + ($dryRun ? ['--dry-run' => true] : []));
    }

    private function refs(): array
    {
        // Sorted here: Postgres puts NULL last in ORDER BY, MySQL and SQLite first.
        return $this->database()->table('cross_references')
            ->get(['source_post_id', 'source_discussion_id', 'target_discussion_id', 'target_post_id'])
            ->map(fn ($r) => array_map(fn ($v) => $v === null ? null : (int) $v, (array) $r))
            ->sortBy('target_post_id')->values()
            ->all();
    }

    #[Test]
    public function legacy_references_become_ours_and_are_recorded_without_backlinks_or_notifications()
    {
        $this->assertSame('Posts updated: 1', $this->migrate());

        $this->assertSame(self::MIGRATED, $this->database()->table('posts')->where('id', 2)->value('content'));
        $this->assertSame([
            ['source_post_id' => 2, 'source_discussion_id' => 2, 'target_discussion_id' => 1, 'target_post_id' => null],
            ['source_post_id' => 2, 'source_discussion_id' => 2, 'target_discussion_id' => 1, 'target_post_id' => 5],
        ], $this->refs(), 'The restricted #3 is rewritten but never recorded: its author cannot see it');
        $this->assertSame(0, $this->database()->table('posts')->where('type', 'crossReference')->count(), 'Old references post no backlinks');
        $this->assertSame(0, $this->database()->table('notifications')->count());
        $this->assertSame('Posts updated: 0', $this->migrate(), 'A second run finds nothing left');
    }

    #[Test]
    public function migrated_references_render_as_links_with_the_live_title()
    {
        $this->migrate();

        $html = json_decode((string) $this->send($this->request('GET', '/api/posts/2'))->getBody(), true)['data']['attributes']['contentHtml'];

        $this->assertStringContainsString('href="http://localhost/d/1"', $html);
        $this->assertStringContainsString('href="http://localhost/d/1/2"', $html);
        $this->assertStringContainsString('Target discussion', $html);
        $this->assertStringNotContainsString('stale', $html, 'The title stored by the old extension is dropped');
        $this->assertStringNotContainsString('Staff secrets', $html, 'A guest never sees a restricted title');
    }

    #[Test]
    public function a_dry_run_changes_nothing()
    {
        $this->assertSame('Posts to update: 1', $this->migrate(dryRun: true));

        $this->assertSame(self::LEGACY, $this->database()->table('posts')->where('id', 2)->value('content'));
        $this->assertSame([], $this->refs());
    }
}

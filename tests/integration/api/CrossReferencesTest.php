<?php

namespace Ernestdefoe\CrossReferences\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class CrossReferencesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-cross-references');

        $discussion = fn (int $id, string $title, int $user, array $extra = []) => $extra + [
            'id' => $id, 'title' => $title, 'slug' => strtolower(str_replace(' ', '-', $title)), 'created_at' => Carbon::now(),
            'last_posted_at' => Carbon::now(), 'user_id' => $user, 'first_post_id' => $id, 'last_post_number' => 2, 'comment_count' => 2,
        ];
        $post = fn (int $id, int $discussion, int $number, int $user) => [
            'id' => $id, 'discussion_id' => $discussion, 'number' => $number, 'user_id' => $user,
            // An hour ago, so a test's own reply isn't refused as flooding.
            'type' => 'comment', 'content' => '<t><p>Post</p></t>', 'created_at' => Carbon::now()->subHour(),
        ];

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'target_author', 'email' => 'target@machine.local', 'is_email_confirmed' => 1],
            ],
            Tag::class => [
                // Tags denies viewForum to anyone who can see fewer primary tags than a discussion needs.
                ['id' => 9, 'name' => 'Everyone', 'slug' => 'everyone', 'position' => 2],
                ['id' => 1, 'name' => 'Staff', 'slug' => 'staff', 'position' => 0, 'is_restricted' => true],
                // Members may read here but not reply.
                ['id' => 2, 'name' => 'Announcements', 'slug' => 'announcements', 'position' => 1, 'is_restricted' => true],
            ],
            Discussion::class => [
                $discussion(1, 'Target discussion', 3),
                $discussion(2, 'Source discussion', 2),
                $discussion(3, 'Staff secrets', 3),
                $discussion(4, 'Read-only target', 3),
            ],
            'discussion_tag' => [
                ['discussion_id' => 3, 'tag_id' => 1],
                ['discussion_id' => 4, 'tag_id' => 2],
            ],
            'group_permission' => [
                ['group_id' => 3, 'permission' => 'tag2.viewForum'],
            ],
            Post::class => [
                $post(1, 1, 1, 3),
                $post(5, 1, 2, 3),
                $post(2, 2, 1, 2),
                $post(3, 3, 1, 3),
                $post(4, 4, 1, 3),
            ],
        ]);
    }

    /** Posts a reply as the given user and returns the new post's id. */
    private function reply(int $discussion, string $content, int $actor = 2): int
    {
        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => $actor,
            'json' => ['data' => [
                'type' => 'posts',
                'attributes' => ['content' => $content],
                'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $discussion]]],
            ]],
        ]));

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) json_decode((string) $response->getBody(), true)['data']['id'];
    }

    private function refs(): array
    {
        return $this->database()->table('cross_references')
            ->orderBy('target_discussion_id')
            ->get(['source_post_id', 'source_discussion_id', 'target_discussion_id', 'target_post_id'])
            ->map(fn ($r) => array_map(fn ($v) => $v === null ? null : (int) $v, (array) $r))
            ->all();
    }

    private function backlinks(int $discussion): int
    {
        return $this->database()->table('posts')->where('discussion_id', $discussion)->where('type', 'crossReference')->count();
    }

    private function notifications(int $user): int
    {
        return $this->database()->table('notifications')->where('user_id', $user)->where('type', 'discussionReferenced')->count();
    }

    private function inbound(int $discussion, ?int $actor = null): array
    {
        $response = $this->send($this->request('GET', "/api/discussions/$discussion/cross-references", $actor ? ['authenticatedAs' => $actor] : []));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function a_reference_records_a_backlink_and_notifies_the_target_author()
    {
        $id = $this->reply(2, 'As discussed in #1 and its second post #1/p2.');

        $this->assertSame([
            ['source_post_id' => $id, 'source_discussion_id' => 2, 'target_discussion_id' => 1, 'target_post_id' => null],
            ['source_post_id' => $id, 'source_discussion_id' => 2, 'target_discussion_id' => 1, 'target_post_id' => 5],
        ], collect($this->refs())->sortBy('target_post_id')->values()->all());
        $this->assertSame(2, $this->backlinks(1));
        $this->assertSame(2, $this->notifications(3));
    }

    #[Test]
    public function a_pasted_discussion_url_becomes_a_reference()
    {
        $this->reply(2, 'See http://localhost/d/1-target-discussion for details.');

        $this->assertSame([1], array_column($this->refs(), 'target_discussion_id'));
    }

    #[Test]
    public function the_rendered_reference_shows_a_title_only_to_those_who_can_see_it()
    {
        $id = $this->reply(2, 'Compare #1 with #3.');

        $html = json_decode((string) $this->send($this->request('GET', "/api/posts/$id"))->getBody(), true)['data']['attributes']['contentHtml'];

        $this->assertStringContainsString('Target discussion', $html);
        $this->assertStringContainsString('href="http://localhost/d/1"', $html);
        $this->assertStringNotContainsString('Staff secrets', $html, 'A guest never sees a restricted title');
        $this->assertStringContainsString('CrossReference--hidden', $html);
    }

    #[Test]
    public function a_discussion_the_author_cannot_see_is_never_referenced()
    {
        $this->reply(2, 'Leaking into #3, and #999 does not exist.');

        $this->assertSame([], $this->refs());
        $this->assertSame(0, $this->backlinks(3));
        $this->assertSame(0, $this->notifications(3));
    }

    #[Test]
    public function a_self_reference_is_ignored()
    {
        $this->reply(2, 'Back to #2.');

        $this->assertSame([], $this->refs());
    }

    #[Test]
    public function a_discussion_the_author_cannot_reply_to_gets_no_backlink()
    {
        $this->reply(2, 'Mentioning #4.');

        $this->assertSame([4], array_column($this->refs(), 'target_discussion_id'), 'The reference is still recorded');
        $this->assertSame(0, $this->backlinks(4));
    }

    #[Test]
    public function the_settings_turn_backlinks_and_notifications_off()
    {
        $this->setting('ernestdefoe-cross-references.createBacklinks', '0');
        $this->setting('ernestdefoe-cross-references.notifyAuthor', '0');

        $this->reply(2, 'Mentioning #1.');

        $this->assertCount(1, $this->refs());
        $this->assertSame(0, $this->backlinks(1));
        $this->assertSame(0, $this->notifications(3));
    }

    #[Test]
    public function editing_a_reference_out_removes_it_but_keeps_the_backlink()
    {
        $id = $this->reply(2, 'Mentioning #1 and #4.');
        $edit = fn (string $content) => $this->send($this->request('PATCH', "/api/posts/$id", [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'posts', 'id' => (string) $id, 'attributes' => ['content' => $content]]],
        ]))->getStatusCode();

        $this->assertSame(200, $edit('Only #4 now.'));
        $this->assertSame([4], array_column($this->refs(), 'target_discussion_id'), 'Only the reference edited out goes');

        $this->assertSame(200, $edit('Never mind.'));
        $this->assertSame([], $this->refs());
        $this->assertSame(1, $this->backlinks(1), 'Moderation history stays');
    }

    #[Test]
    public function the_inbound_list_shows_only_what_the_reader_may_see()
    {
        $this->reply(2, 'Mentioning #1.');
        // An admin's reference from the restricted discussion.
        $this->reply(3, 'Also #1.', 1);

        [$status, $body] = $this->inbound(1);
        $this->assertSame(200, $status);
        $this->assertSame([2], array_column($body['data'], 'sourceDiscussionId'), 'The restricted source is left out');
        $this->assertSame('Source discussion', $body['data'][0]['source']['discussionTitle']);
        $this->assertSame('normal', $body['data'][0]['source']['author']['username']);

        [, $body] = $this->inbound(1, 1);
        $this->assertCount(2, $body['data']);

        $this->assertSame(404, $this->inbound(3)[0], 'A target the reader cannot see');
        $this->assertSame(404, $this->inbound(999)[0]);
    }

    #[Test]
    public function the_inbound_list_does_not_query_per_reference()
    {
        $discussions = [];
        $posts = [];
        $refs = [];
        for ($id = 10; $id < 25; $id++) {
            $discussions[] = ['id' => $id, 'title' => "Source $id", 'created_at' => Carbon::now(), 'user_id' => $id % 2 ? 2 : 3, 'first_post_id' => $id + 100, 'comment_count' => 1];
            $posts[] = ['id' => $id + 100, 'discussion_id' => $id, 'number' => 1, 'user_id' => $id % 2 ? 2 : 3, 'type' => 'comment', 'content' => '<t><p>#1</p></t>', 'created_at' => Carbon::now()];
            $refs[] = ['source_post_id' => $id + 100, 'source_discussion_id' => $id, 'target_discussion_id' => 1, 'target_post_id' => null];
        }
        $this->prepareDatabase([Discussion::class => $discussions, Post::class => $posts, 'cross_references' => $refs]);

        // The repeated-query detector fails the request on a per-reference query.
        [$status, $body] = $this->inbound(1);

        $this->assertSame(200, $status);
        $this->assertCount(15, $body['data']);
    }

    #[Test]
    public function discussions_can_be_filtered_by_what_they_reference()
    {
        $this->reply(2, 'Mentioning #1.');
        // Discussion 1 references another discussion; it must not match.
        $this->database()->table('cross_references')->insert(['source_post_id' => 1, 'source_discussion_id' => 1, 'target_discussion_id' => 4]);

        $response = $this->send($this->request('GET', '/api/discussions')->withQueryParams(['filter' => ['references' => '1']]));
        $ids = array_column(json_decode((string) $response->getBody(), true)['data'], 'id');

        $this->assertSame(['2'], $ids);
    }
}

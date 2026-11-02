<?php

namespace Ernestdefoe\CrossReferences\Console;

use Ernestdefoe\CrossReferences\Job\SyncReferencesJob;
use Flarum\Console\AbstractCommand;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Rewrites references written by club-1/flarum-ext-cross-references (Flarum 1)
 * into this extension's CROSSREF tag, so they render as links again, and
 * records them so they show under "Referenced from". Old references get no
 * backlink posts or notifications. Safe to run twice: a rewritten post no
 * longer matches.
 */
class MigrateLegacyCommand extends AbstractCommand
{
    /**
     * Every tag club-1 wrote: `#730` → CROSSREFERENCESHORT, a pasted discussion
     * URL → CROSSREFERENCEURL, a pasted post URL → CROSSREFERENCEURLCOMMENT, and
     * CROSSREFERENCE from its pre-0.5 builds. Each wraps only the matched text.
     */
    public const PATTERN = '#<(CROSSREFERENCE(?:SHORT|URL|URLCOMMENT)?)\b([^>]*)>[^<]*</\1>#';

    public function __construct(
        protected ConnectionInterface $db,
        protected Container $container
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('crossref:migrate-legacy')
            ->setDescription('Turn references left by club-1/flarum-ext-cross-references into links again.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the posts that would change, and change nothing.');
    }

    protected function fire(): int
    {
        $dryRun = (bool) $this->input->getOption('dry-run');
        $count = 0;

        $this->db->table('posts')
            ->where('type', 'comment')
            ->where('content', 'like', '%<CROSSREFERENCE%')
            ->select(['id', 'content'])
            ->chunkById(200, function ($posts) use ($dryRun, &$count) {
                foreach ($posts as $post) {
                    $content = self::rewrite((string) $post->content);
                    if ($content === $post->content) {
                        continue;
                    }

                    $count++;
                    if ($dryRun) {
                        continue;
                    }

                    $this->db->table('posts')->where('id', $post->id)->update(['content' => $content]);
                    $this->container->call([new SyncReferencesJob((int) $post->id, announce: false), 'handle']);
                }
            });

        $this->info(($dryRun ? 'Posts to update: ' : 'Posts updated: ').$count);

        return 0;
    }

    /** The post's XML with every legacy tag replaced by the CROSSREF tag `#N` or `#N/pM` produces. */
    public static function rewrite(string $xml): string
    {
        return preg_replace_callback(self::PATTERN, function (array $m): string {
            if (! preg_match('/\bid="(\d+)"/', $m[2], $id)) {
                return $m[0];
            }

            // A post link's number is the path segment after the slug: /d/12-slug/5.
            $number = preg_match('#\burl="[^"]*/d/\d+[^/"]*/(\d+)#', $m[2], $n) ? (int) $n[1] : 0;

            return $number > 0
                ? '<CROSSREF id="'.$id[1].'" postnum="'.$number.'">#'.$id[1].'/p'.$number.'</CROSSREF>'
                : '<CROSSREF id="'.$id[1].'">#'.$id[1].'</CROSSREF>';
        }, $xml) ?? $xml;
    }
}

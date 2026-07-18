<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * A throwaway local git repository for exercising the history-facing commands
 * (analyse:snapshot, analyse:blame) against a hand-built, fully deterministic history.
 */
final class GitFixtureRepo
{
    public function __construct(public readonly string $root) {}

    public static function init(string $root): self
    {
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root);

        $repo = new self($root);
        $repo->git(['init', '-b', 'main']);

        return $repo;
    }

    public function write(string $relative, string $contents): self
    {
        $path = $this->root.'/'.$relative;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);

        return $this;
    }

    /** Commit everything staged-able with a fixed author/committer date; returns the sha. */
    public function commit(string $message, string $date): string
    {
        $this->git(['add', '-A']);
        $this->git(['commit', '-m', $message, '--no-gpg-sign'], [
            'GIT_AUTHOR_DATE' => $date,
            'GIT_COMMITTER_DATE' => $date,
        ]);

        return $this->head();
    }

    public function head(): string
    {
        return trim($this->git(['rev-parse', 'HEAD']));
    }

    public function destroy(): void
    {
        File::deleteDirectory($this->root);
    }

    public static function phpUnitTestClass(string $class, string ...$methods): string
    {
        $bodies = '';
        foreach ($methods as $method) {
            $bodies .= <<<PHP

                    public function {$method}(): void
                    {
                        \$this->assertTrue(true);
                    }

                PHP;
        }

        return "<?php\n\nuse PHPUnit\\Framework\\TestCase;\n\nclass {$class} extends TestCase\n{{$bodies}}\n";
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, array $env = []): string
    {
        $process = new Process(['git', ...$arguments], $this->root, [
            'GIT_AUTHOR_NAME' => 'Fixture',
            'GIT_AUTHOR_EMAIL' => 'fixture@example.test',
            'GIT_COMMITTER_NAME' => 'Fixture',
            'GIT_COMMITTER_EMAIL' => 'fixture@example.test',
            ...$env,
        ]);

        return $process->mustRun()->getOutput();
    }
}

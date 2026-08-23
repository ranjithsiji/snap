<?php

declare(strict_types=1);

namespace JuryTool\Tests\Unit;

use JuryTool\Infrastructure\Commons\ReplicaCredentials;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The replica credentials decide whether the tool can read Commons at all,
 * and a misparse presents as an authentication failure rather than as a
 * parsing bug — so the awkward shapes are pinned down here.
 */
class ReplicaCredentialsTest extends TestCase
{
    #[Test]
    public function it_reads_the_client_section(): void
    {
        $parsed = ReplicaCredentials::parseCnf(<<<'CNF'
            [client]
            user = s12345
            password = secret
            CNF);

        $this->assertSame('s12345', $parsed->user);
        $this->assertSame('secret', $parsed->password);
        $this->assertTrue($parsed->isComplete());
    }

    /**
     * The documented example file for these credentials annotates the
     * username inline. parse_ini_file keeps that comment as part of the
     * value, which is the bug this parser exists to avoid.
     */
    #[Test]
    public function it_strips_inline_comments(): void
    {
        $parsed = ReplicaCredentials::parseCnf(<<<'CNF'
            [client]
            user = s12345        # Your Toolforge user ID (e.g., s12345)
            password = hunter2   ; another comment style
            CNF);

        $this->assertSame('s12345', $parsed->user);
        $this->assertSame('hunter2', $parsed->password);
    }

    /** A hash inside the password is part of it, not a comment. */
    #[Test]
    public function it_keeps_hashes_that_belong_to_the_value(): void
    {
        $parsed = ReplicaCredentials::parseCnf(<<<'CNF'
            [client]
            user = s12345
            password = pa#ssword
            CNF);

        $this->assertSame('pa#ssword', $parsed->password);
    }

    #[Test]
    public function it_keeps_hashes_inside_quotes(): void
    {
        $parsed = ReplicaCredentials::parseCnf(<<<'CNF'
            [client]
            user = "s12345"
            password = "pass # word"
            CNF);

        $this->assertSame('s12345', $parsed->user);
        $this->assertSame('pass # word', $parsed->password);
    }

    /** Only [client] applies; Toolforge files carry other sections too. */
    #[Test]
    public function it_ignores_other_sections(): void
    {
        $parsed = ReplicaCredentials::parseCnf(<<<'CNF'
            [client]
            user = s12345
            password = secret

            [mysqldump]
            user = wrong
            password = wrong
            CNF);

        $this->assertSame('s12345', $parsed->user);
        $this->assertSame('secret', $parsed->password);
    }

    #[Test]
    public function it_reports_incomplete_credentials(): void
    {
        $parsed = ReplicaCredentials::parseCnf("[client]\nuser = s12345\n");

        $this->assertFalse($parsed->isComplete());
    }

    #[Test]
    public function it_handles_windows_line_endings(): void
    {
        $parsed = ReplicaCredentials::parseCnf("[client]\r\nuser = s12345\r\npassword = secret\r\n");

        $this->assertSame('s12345', $parsed->user);
        $this->assertSame('secret', $parsed->password);
    }

    #[Test]
    public function environment_variables_take_precedence(): void
    {
        $found = ReplicaCredentials::discover($this->env([
            'REPLICA_USER' => 'envuser',
            'REPLICA_PASSWORD' => 'envpass',
        ]));

        $this->assertSame('envuser', $found->user);
        $this->assertSame('environment', $found->source);
    }

    /** The names mist uses, so credentials already exported still work. */
    #[Test]
    public function it_accepts_the_tool_replica_variable_names(): void
    {
        $found = ReplicaCredentials::discover($this->env([
            'TOOL_REPLICA_USER' => 'envuser',
            'TOOL_REPLICA_PASSWORD' => 'envpass',
        ]));

        $this->assertSame('envuser', $found->user);
        $this->assertSame('envpass', $found->password);
    }

    #[Test]
    public function it_reads_the_cnf_from_home(): void
    {
        $home = $this->tempHome("[client]\nuser = s54321\npassword = filepass\n");

        $found = ReplicaCredentials::discover($this->env(['HOME' => $home]));

        $this->assertSame('s54321', $found->user);
        $this->assertSame('filepass', $found->password);
        $this->assertSame($home . '/replica.my.cnf', $found->source);
    }

    /**
     * REPLICA_CNF names a file, not a directory, and must be the only
     * place consulted — it is how a developer points at a copy that is
     * deliberately not in any of the usual locations.
     */
    #[Test]
    public function an_explicit_path_wins_and_is_used_alone(): void
    {
        $dir = $this->tempHome("[client]\nuser = sExplicit\npassword = p\n");
        $cnf = $dir . '/replica.my.cnf';

        $found = ReplicaCredentials::discover($this->env([
            'REPLICA_CNF' => $cnf,
            // Ignored entirely: the explicit path short-circuits the search.
            'HOME' => '/nonexistent-' . uniqid(),
            'TOOL_NAME' => 'someothertool',
        ]));

        $this->assertSame('sExplicit', $found->user);
        $this->assertSame($cnf, $found->source);
    }

    /**
     * A web service can run with HOME unset. The tool's own directory is
     * named in the class for exactly that case, so discovery still knows
     * where to look.
     */
    #[Test]
    public function it_falls_back_to_the_tool_directory(): void
    {
        $this->assertSame('/data/project/snap', ReplicaCredentials::TOOL_DIR);

        // Nothing is readable in this environment, so this asserts the
        // search completes rather than that it finds anything.
        $found = ReplicaCredentials::discover(
            $this->env(['HOME' => '/nonexistent-' . uniqid()])
        );

        $this->assertFalse($found->isComplete());
    }

    #[Test]
    public function it_reports_nothing_when_no_credentials_exist(): void
    {
        $found = ReplicaCredentials::discover($this->env(['HOME' => '/nonexistent-' . uniqid()]));

        $this->assertFalse($found->isComplete());
        $this->assertNull($found->source);
    }

    /**
     * Off Toolforge there is no cnf and no variables, and the tool must
     * fall back to the Commons API rather than fail to boot.
     */
    #[Test]
    public function discovery_never_throws_without_an_environment(): void
    {
        $found = ReplicaCredentials::discover(
            static fn (string $key, ?string $default = null): ?string => $default
        );

        $this->assertFalse($found->isComplete());
    }

    /**
     * Mirrors the resolver in config/settings.php: a lookup returning the
     * default when the variable is absent.
     *
     * @param array<string, string> $values
     * @return callable(string, ?string=): ?string
     */
    private function env(array $values): callable
    {
        return static fn (string $key, ?string $default = null): ?string
            => $values[$key] ?? $default;
    }

    private function tempHome(string $contents): string
    {
        $dir = sys_get_temp_dir() . '/jurytool-cnf-' . uniqid();
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/replica.my.cnf', $contents);

        register_shutdown_function(static function () use ($dir): void {
            @unlink($dir . '/replica.my.cnf');
            @rmdir($dir);
        });

        return $dir;
    }
}

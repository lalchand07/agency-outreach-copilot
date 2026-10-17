<?php

declare(strict_types=1);

namespace App\Cli;

use App\App;
use App\Db\Migrator;
use App\Pipeline\Status;

final class Console
{
    private const HELP = <<<'TXT'
agency-outreach-copilot

Usage: php bin/console <command> [options]

  migrate                     Create or update the database tables
  hash-password               Create ADMIN_PASSWORD_HASH for .env (prompts for the password)
  send [--limit=N]            Send queued, approved emails (daily cap and suppression enforced)
  followups [--limit=N]       Draft follow-ups for contacted leads with no reply (drafts need approval)
  retention [--dry-run]       Delete details of old "not interested" / "do not contact" leads
  research [--campaign=ID] [--limit=N] [--no-draft]
                              Research, score and draft "new" leads (drafts need approval)
  status                      Show counts and configuration checks

TXT;

    /** @var resource */
    private $out;

    public function __construct(private App $app, mixed $out = null)
    {
        $this->out = is_resource($out) ? $out : STDOUT;
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $command = $args[0] ?? 'help';
        $options = self::options(array_slice($args, 1));
        try {
            return match ($command) {
                'migrate' => $this->migrate(),
                'hash-password' => $this->hashPassword(),
                'send' => $this->send($options),
                'followups' => $this->followups($options),
                'retention' => $this->retention($options),
                'research' => $this->research($options),
                'status' => $this->status(),
                default => $this->help($command),
            };
        } catch (\Throwable $e) {
            $this->line('Error: ' . $e->getMessage());

            return 1;
        }
    }

    private function migrate(): int
    {
        $applied = (new Migrator($this->app->db(), $this->app->root . '/migrations'))->migrate();
        $this->line($applied === [] ? 'Database is up to date.' : 'Applied: ' . implode(', ', $applied));

        return 0;
    }

    private function hashPassword(): int
    {
        $password = getenv('ADMIN_PASSWORD') ?: '';
        if ($password === '') {
            fwrite($this->out, 'Password (min 12 characters): ');
            if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
                @shell_exec('stty -echo');
            }
            $password = trim((string) fgets(STDIN));
            if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
                @shell_exec('stty echo');
            }
            fwrite($this->out, PHP_EOL);
        }
        if (strlen($password) < 12) {
            $this->line('Use at least 12 characters.');

            return 1;
        }
        $this->line("Add this line to .env (single quotes keep the \$ signs intact):\n");
        $this->line("ADMIN_PASSWORD_HASH='" . password_hash($password, PASSWORD_DEFAULT) . "'");

        return 0;
    }

    /** @param array<string, string|bool> $options */
    private function send(array $options): int
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : null;
        $summary = $this->app->outbox()->process($limit);
        $this->line($summary['message']);

        return $summary['failed'] > 0 ? 2 : 0;
    }

    /** @param array<string, string|bool> $options */
    private function followups(array $options): int
    {
        $report = $this->app->followUps()->draftDue((int) ($options['limit'] ?? 20));
        $this->line(sprintf('%d follow-up draft(s) created and waiting for review.', $report['created']));
        foreach ($report['failed'] as $failure) {
            $this->line('  ! ' . $failure);
        }

        return 0;
    }

    /** @param array<string, string|bool> $options */
    private function retention(array $options): int
    {
        $retention = $this->app->retention();
        if (!empty($options['dry-run'])) {
            $candidates = $retention->candidates();
            $this->line(sprintf('%d lead(s) would be deleted.', count($candidates)));
            foreach ($candidates as $lead) {
                $this->line(sprintf('  #%d %s (%s since %s)', $lead['id'], $lead['name'], $lead['status'], $lead['status_changed_at']));
            }

            return 0;
        }
        $this->line(sprintf('%d lead(s) deleted. Suppression entries kept.', $retention->run()));

        return 0;
    }

    /** @param array<string, string|bool> $options */
    private function research(array $options): int
    {
        $filters = ['status' => Status::NEW];
        if (isset($options['campaign'])) {
            $filters['campaign_id'] = (int) $options['campaign'];
        }
        $leads = $this->app->leads()->search($filters, (int) ($options['limit'] ?? 10));
        foreach ($leads as $lead) {
            $this->line(sprintf('#%d %s', $lead['id'], $lead['name'] !== '' ? $lead['name'] : $lead['website']));
            foreach ($this->app->processLead((int) $lead['id'], empty($options['no-draft'])) as $message) {
                $this->line('  ' . $message);
            }
        }
        $this->line(sprintf('%d lead(s) processed. Review drafts in the web UI before anything is sent.', count($leads)));

        return 0;
    }

    private function status(): int
    {
        foreach ($this->app->leads()->countByStatus() as $status => $n) {
            $this->line(sprintf('%-16s %d', Status::label($status), $n));
        }
        $this->line(sprintf('Pending drafts:  %d', $this->app->review()->pendingCount()));
        $this->line(sprintf('Sent today:      %d / %d', $this->app->outbox()->sentToday(), $this->app->settings()->int('daily_send_cap')));
        $this->line('AI:              ' . ($this->app->ai() !== null ? 'configured' : 'not configured'));
        $this->line('Google Places:   ' . ($this->app->places()->isConfigured() ? 'configured' : 'not configured'));
        $missing = $this->app->product()->missingSenderFields();
        $this->line('Sender details:  ' . ($missing === [] ? 'complete' : 'missing ' . implode(', ', $missing)));

        return 0;
    }

    private function help(string $command): int
    {
        if (!in_array($command, ['help', '--help', '-h'], true)) {
            $this->line('Unknown command: ' . $command . PHP_EOL);
        }
        fwrite($this->out, self::HELP);

        return $command === 'help' || $command === '--help' || $command === '-h' ? 0 : 1;
    }

    private function line(string $text): void
    {
        fwrite($this->out, $text . PHP_EOL);
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, string|bool>
     */
    public static function options(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
                $options[$m[1]] = $m[2] ?? true;
            }
        }

        return $options;
    }
}

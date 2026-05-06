<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class DashboardServe extends Command
{
    protected $signature = 'dashboard:serve';
    protected $description = 'Start queue worker + scheduler together (for local development)';

    public function handle(): void
    {
        $this->info('Starting AI Dashboard background services...');
        $this->info('  Queue worker (agent queue)');
        $this->info('  Laravel scheduler');
        $this->info('Press Ctrl+C to stop.');
        $this->newLine();

        $php = PHP_BINARY;
        $artisan = base_path('artisan');

        $queue = new Process([$php, $artisan, 'queue:work', '--queue=agent,default', '--timeout=1800', '--sleep=3']);
        $queue->setTimeout(null);
        $queue->start();

        $scheduler = new Process([$php, $artisan, 'schedule:work']);
        $scheduler->setTimeout(null);
        $scheduler->start();

        // Monitor both processes
        while ($queue->isRunning() || $scheduler->isRunning()) {
            // Relay output
            $this->relayOutput($queue, 'queue');
            $this->relayOutput($scheduler, 'scheduler');

            // Restart if crashed
            if (! $queue->isRunning()) {
                $this->warn('Queue worker stopped, restarting...');
                $queue = new Process([$php, $artisan, 'queue:work', '--queue=agent,default', '--timeout=1800', '--sleep=3']);
                $queue->setTimeout(null);
                $queue->start();
            }

            if (! $scheduler->isRunning()) {
                $this->warn('Scheduler stopped, restarting...');
                $scheduler = new Process([$php, $artisan, 'schedule:work']);
                $scheduler->setTimeout(null);
                $scheduler->start();
            }

            usleep(500000); // 0.5s
        }
    }

    private function relayOutput(Process $process, string $label): void
    {
        $out = $process->getIncrementalOutput();
        if ($out) {
            foreach (explode("\n", trim($out)) as $line) {
                if (trim($line)) $this->line("  <fg=gray>[{$label}]</> {$line}");
            }
        }

        $err = $process->getIncrementalErrorOutput();
        if ($err) {
            foreach (explode("\n", trim($err)) as $line) {
                if (trim($line)) $this->error("  [{$label}] {$line}");
            }
        }
    }
}

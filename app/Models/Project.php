<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'name',
        'path',
        'status',
        'default_branch',
        'allowed_tools',
        'model',
        'max_run_minutes',
        'max_turns',
        'nightly_enabled',
        'nightly_schedule',
        'consecutive_failures',
        'last_run_at',
        'paused_at',
    ];

    protected function casts(): array
    {
        return [
            'nightly_enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ProjectRun::class)->orderByDesc('id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function scopeActive($q)
    {
        return $q->where('status', 'active');
    }

    public function scopeNightlyDue($q)
    {
        return $q->where('status', 'active')->where('nightly_enabled', true);
    }

    /**
     * Canonical state folder for this project (nightly.md / review.md / log/).
     */
    public function statePath(): string
    {
        return storage_path('app/private/dashboard/projects/' . $this->name);
    }

    public function nightlyMdPath(): string
    {
        return $this->statePath() . '/nightly.md';
    }

    public function reviewMdPath(): string
    {
        return $this->statePath() . '/review.md';
    }

    public function readNightly(): string
    {
        $p = $this->nightlyMdPath();
        return is_file($p) ? (string) file_get_contents($p) : '';
    }

    public function readReview(): string
    {
        $p = $this->reviewMdPath();
        return is_file($p) ? (string) file_get_contents($p) : '';
    }

    public function writeNightly(string $content): void
    {
        @mkdir(dirname($this->nightlyMdPath()), 0755, true);
        file_put_contents($this->nightlyMdPath(), $content);
    }

    public function writeReview(string $content): void
    {
        @mkdir(dirname($this->reviewMdPath()), 0755, true);
        file_put_contents($this->reviewMdPath(), $content);
    }
}

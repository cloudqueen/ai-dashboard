<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VaultNote extends Model
{
    protected $fillable = [
        'relative_path',
        'title',
        'vault_folder',
        'frontmatter',
        'body_preview',
        'content_hash',
        'vault_modified_at',
    ];

    protected function casts(): array
    {
        return [
            'frontmatter' => 'array',
            'vault_modified_at' => 'datetime',
        ];
    }
}

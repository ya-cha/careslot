<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MailLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property list<array{address: string, name: string}> $from
 * @property list<array{address: string, name: string}> $to
 * @property list<array{address: string, name: string}> $cc
 * @property list<array{address: string, name: string}> $bcc
 * @property string $subject
 * @property string $html
 * @property CarbonImmutable $sent_at
 */
#[Fillable(['from', 'to', 'cc', 'bcc', 'subject', 'html', 'message_id', 'sent_at'])]
class MailLog extends Model
{
    /** @use HasFactory<MailLogFactory> */
    use HasFactory;

    protected $hidden = ['html'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from' => 'encrypted:array',
            'to' => 'encrypted:array',
            'cc' => 'encrypted:array',
            'bcc' => 'encrypted:array',
            'subject' => 'encrypted',
            'html' => 'encrypted',
            'sent_at' => 'immutable_datetime',
        ];
    }
}

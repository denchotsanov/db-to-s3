<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Email extends Model
{
    /** @use HasFactory<\Database\Factories\EmailFactory> */
    use HasFactory;

    protected $table = 'emails';

    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'loan_id',
        'email_template_id',
        'receiver_email',
        'sender_email',
        'subject',
        'body',
        'file_ids',
        'created_at',
        'sent_at',
        'body_s3_path',
        'file_s3_paths',
    ];

    protected $casts = [
        'file_ids'   => 'array',
        'created_at' => 'datetime',
        'sent_at'    => 'datetime',
    ];

    /**
     * Get the files associated with this email.
     */
    public function files()
    {
        return $this->hasMany(File::class, 'id', 'file_ids');
    }
}


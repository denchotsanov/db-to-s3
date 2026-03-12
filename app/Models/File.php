<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    /** @use HasFactory<\Database\Factories\FileFactory> */
    use HasFactory;

    protected $table = 'files';

    protected $fillable = [
        'name',
        'path',
        'size',
        'type',
        's3_path',
    ];
}

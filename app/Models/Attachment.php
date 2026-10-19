<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $guarded = ['id'];

    protected $table = 'attachments';

    protected $hidden = ['content'];
}

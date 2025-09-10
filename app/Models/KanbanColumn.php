<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KanbanColumn extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'column_name',
        'position',
        'deleted_at',
        'created_at',
        'updated_at'
    ];
}

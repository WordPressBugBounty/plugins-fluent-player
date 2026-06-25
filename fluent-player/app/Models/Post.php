<?php

namespace FluentPlayer\App\Models;

if (!defined('ABSPATH')) exit;

use FluentPlayer\App\Models\Model;

class Post extends Model
{   
    const CREATED_AT = 'post_date';
    
    const UPDATED_AT = null;

    protected $table = 'posts';
    
    protected $primaryKey = 'ID';

    public static function boot()
    {
        parent::boot();
    }
}

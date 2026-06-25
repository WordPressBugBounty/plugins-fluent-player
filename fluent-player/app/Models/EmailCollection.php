<?php

namespace FluentPlayer\App\Models;

if (!defined('ABSPATH')) exit;

use FluentPlayer\Framework\Database\Orm\Model;

class EmailCollection extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'flp_email_collections';
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'email',
        'media_id',
        'preset_slug',
        'layer_id',
        'video_time',
        'ip_address',
        'browser',
        'device',
        'user_id',
        'meta',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'media_id' => 'integer',
        'preset_slug' => 'string',
        'layer_id' => 'integer',
        'video_time' => 'float',
        'user_id' => 'integer',
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    
    /**
     * Find a submission by email
     *
     * @param string $email
     * @return self|null
     */
    public static function findByEmail($email)
    {
        return static::where('email', $email)->first();
    }
}

<?php

declare(strict_types=1);

namespace App\Model;



/**
 * @property int $id 
 * @property int $member_id 
 * @property int $video_id 
 */
class MemberHasVideo extends Model
{
    public const PAGE_PER = 10;
    /**
     * The table associated with the model.
     */
    protected ?string $table = 'member_has_videos';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'member_id' => 'integer', 'video_id' => 'integer'];
}

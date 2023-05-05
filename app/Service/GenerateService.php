<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace App\Service;

use App\Constants\Constants;
use App\Model\Actor;
use App\Model\ActorCorrespond;
use App\Model\ImageGroup;

class GenerateService
{
    public function generateImageGroups(array $result, array $imageGroups): array
    {
        foreach ($imageGroups as $imageGroup) {
            $url = $this->getImageUrl($imageGroup);
            $imageGroup['thumbnail'] = $url . $imageGroup['thumbnail'];
            $imageGroup['url'] = $url . $imageGroup['url'];
            $count = 0;
            if (empty($imageGroup['images_limit'])) {
                $result[] = $imageGroup;
                continue;
            }
            $imageGroup['actors'] = $this->getActors('image_group', $imageGroup['id']);
            foreach ($imageGroup['images_limit'] as $key => $image) {
                if ($count >= ImageGroup::DEFAULT_FREE_LIMIT) {
                    unset($imageGroup['images_limit'][$key]);
                    continue;
                }
                $imageGroup['images_limit'][$key]['thumbnail'] = $url . $imageGroup['images_limit'][$key]['thumbnail'];
                $imageGroup['images_limit'][$key]['url'] = $url . $imageGroup['images_limit'][$key]['url'];
                ++$count;
            }

            $result[] = $imageGroup;
        }

        return $result;
    }

    public function getActors(string $type, int $id): array
    {
        $actorIds = ActorCorrespond::where('correspond_type', $type)
            ->where('correspond_id', $id)
            ->get()
            ->pluck('actor_id')
            ->toArray();

        if (empty($actorIds)) {
            return [Constants::DEFAULT_ACTOR];
        }

        $actors = Actor::whereIn('id', $actorIds)->get()->toArray();

        $result = [];
        $baseUrl = $this->getBaseUrl();
        foreach ($actors as $actor) {
            if (! empty($actor['avatar'])) {
                $actor['avatar'] = $baseUrl . $actor['avatar'];
            }
            $result[] = $actor;
        }

        return $result;
    }

    protected function generateVideos(array $result, array $videos): array
    {
        foreach ($videos as $video) {
            $video['cover_thumb'] = env('VIDEO_THUMB_URL', 'https://new.cnzuqiu.mobi') . $video['cover_thumb'];
            $video['full_m3u8'] = env('VIDEO_SOURCE_URL', 'https://video.iwanna.tv') . $video['full_m3u8'];
            $video['m3u8'] = env('VIDEO_SOURCE_URL', 'https://video.iwanna.tv') . $video['m3u8'];
            $video['source'] = env('VIDEO_SOURCE_URL', 'https://video.iwanna.tv') . $video['source'];
            $video['actors'] = $this->getActors('video', $video['id']);
            unset($video['coins']);

            $result[] = $video;
        }

        return $result;
    }

    protected function getBaseUrl()
    {
        return env('TEST_IMG_URL');
    }

    protected function generateAdvertisements(array $result, array $advertisements): array
    {
        foreach ($advertisements as $advertisement) {
            $advertisement['image_url'] = $this->getBaseUrl() . $advertisement['image_url'];
            $result[] = $advertisement;
        }

        return $result;
    }

    protected function getImageUrl(array $model): string
    {
        if ($model['sync_id'] > 0) {
            return env('IMAGE_GROUP_ENCRYPT_URL');
        }

        return $this->getBaseUrl();
    }

    protected function getIds(array $models): array
    {
        $result = [];

        foreach ($models as $model) {
            $result[] = $model['id'];
        }

        return $result;
    }
}

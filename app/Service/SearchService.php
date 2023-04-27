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

use App\Model\Advertisement;
use App\Model\ImageGroup;
use App\Model\Video;
use Hyperf\HttpServer\Contract\RequestInterface;

class SearchService
{
    public const NORMAL_IMAGE_GROUP_PERCENT = 0.35;

    public const NORMAL_VIDEO_PERCENT = 0.35;

    public const HOT_ORDER_IMAGE_GROUP_PERCENT = 0.1;

    public const HOT_ORDER_VIDEO_PERCENT = 0.1;

    public const ADVERTISEMENT_PAGE_PER = 20;

    public const OTHER_LIMIT = 1;

    public const POPULAR_CACHE_KEY = 'search:popular:';

    public VideoService $videoService;

    public ImageGroupService $imageGroupService;

    public AdvertisementService $advertisementService;

    public string $url;

    public function __construct(
        ImageGroupService $imageGroupService,
        VideoService $videoService,
        AdvertisementService $advertisementService,
        RequestInterface $request
    ) {
        $this->imageGroupService = $imageGroupService;
        $this->videoService = $videoService;
        $this->advertisementService = $advertisementService;
        $this->url = $request->url();
    }

    public function navigationSuggest(array $suggest, int $page, int $limit) : array
    {
        $advertisementLimit = $this->getAdvertisementsLimit($page, $limit);
        if ($advertisementLimit > 0) {
            $limit--;
        }
        $imageGroupLimit = (int) floor($limit / 2);
        $videoLimit = $limit - $imageGroupLimit;

        $imageGroups = $this->navigationSuggestImageGroups($suggest, $page, $imageGroupLimit);
        $videos = $this->navigationSuggestVideos($suggest, $page, $videoLimit);
        $advertisements = $this->advertisementService->getAdvertisementBySearch($page, $advertisementLimit);

        $result = [];

        $result = $this->generateImageGroups($result, $imageGroups);
        $result = $this->generateVideos($result, $videos);
        return $this->generateAdvertisements($result, $advertisements);
    }

    public function navigationSuggestSortById(array $suggest, int $page, int $limit) : array
    {
        $result = $this->navigationSuggest($suggest, $page, $limit);
        $collect = \Hyperf\Collection\collect($result);
        $collect = $collect->sortByDesc('created_at');

        return $collect->toArray();
    }

    protected function navigationSuggestImageGroups(array $suggest, int $page, int $limit) : array
    {
        $hotOrderLimit = $this->getHotOrderPerLimit(ImageGroup::class, $limit);
        $hotOrderModels = $this->imageGroupService->getImageGroupsByHotOrder($page, $hotOrderLimit);
        $otherLimit = self::OTHER_LIMIT;
        $suggestLimit = $limit - $hotOrderLimit - $otherLimit;
        if($suggestLimit <= 0) {
            return $hotOrderModels;
        }
        $suggestModels = $this->imageGroupService->getImageGroupsBySuggest($suggest, $page, $suggestLimit);
        $remain = $suggestLimit - count($suggestModels);
        if($remain >= 1) {
            $otherLimit += $remain;
        }

        $models = $this->imageGroupService->getImageGroups(null, $page, $otherLimit)->toArray();

        return array_merge($hotOrderModels, $suggestModels, $models);
    }

    protected function navigationSuggestVideos(array $suggest, int $page, int $limit) : array
    {
        $hotOrderLimit = $this->getHotOrderPerLimit(Video::class, $limit);
        $hotOrderModels = $this->videoService->getVideosByHotOrder($page, $hotOrderLimit);
        $otherLimit = self::OTHER_LIMIT;
        $suggestLimit = $limit - $hotOrderLimit - $otherLimit;
        if($suggestLimit <= 0) {
            return $hotOrderModels;
        }

        $suggestModels = $this->videoService->getVideosBySuggest($suggest, $page, $limit);

        $remain = $suggestLimit - count($suggestModels);
        if($remain >= 1) {
            $otherLimit += $remain;
        }

        $models = $this->videoService->getVideos(null, $page, $otherLimit)->toArray();

        return array_merge($hotOrderModels, $suggestModels, $models);
    }

    public function search(?array $tagIds, int $page, int $limit): array
    {
        $imageGroups = $this->imageGroupService->getImageGroups($tagIds, $page, $this->getPerLimit(ImageGroup::class, $limit))->toArray();
        $videos = $this->videoService->getVideos($tagIds, $page, 9, $this->getPerLimit(Video::class, $limit))->toArray();
        $advertisements = $this->advertisementService->getAdvertisementBySearch($page, $this->getPerLimit(Advertisement::class, $limit));

        $result = [];

        $result = $this->generateImageGroups($result, $imageGroups);
        $result = $this->generateVideos($result, $videos);
        return $this->generateAdvertisements($result, $advertisements);
    }

    public function suggest(array $suggest, int $page, int $limit = 10): array
    {
        $imageGroups = $this->imageGroupService->getImageGroupsBySuggest($suggest, $page, $this->getPerLimit(ImageGroup::class, $limit));
        $videos = $this->videoService->getVideosBySuggest($suggest, $page, $this->getPerLimit(Video::class, $limit));
        $advertisements = $this->advertisementService->getAdvertisementBySearch($page, $this->getPerLimit(Advertisement::class, $limit));

        $result = [];

        $result = $this->generateImageGroups($result, $imageGroups);
        $result = $this->generateVideos($result, $videos);
        return $this->generateAdvertisements($result, $advertisements);
    }

    public function keyword(string $keyword, int $page, int $limit = 10): array
    {
        $imageGroups = $this->imageGroupService->getImageGroupsByKeyword($keyword, $page, $this->getPerLimit(ImageGroup::class, $limit))->toArray();
        $videos = $this->videoService->searchVideo($keyword, 0, 0, $this->getPerLimit(Video::class, $limit))->toArray();
        $advertisements = $this->advertisementService->getAdvertisementBySearch($page, $this->getPerLimit(Advertisement::class, $limit));

        $result = [];

        $result = $this->generateImageGroups($result, $imageGroups);
        $result = $this->generateVideos($result, $videos);
        return $this->generateAdvertisements($result, $advertisements);
    }

    // TODO 可以做快取去優化，但是需要增加非同步 task 去處理
    public function popular(int $page, int $limit = 10): array
    {
        $advertisements = $this->advertisementService->getAdvertisementBySearch($page, $this->getPerLimit(Advertisement::class, $limit));
        $imageGroups = $this->popularImageGroups($page, $this->getPerLimit(ImageGroup::class, $limit));
        $videos = $this->popularVideos($page, $this->getPerLimit(Video::class, $limit));

        $result = [];

        $result = $this->generateImageGroups($result, $imageGroups);
        $result = $this->generateVideos($result, $videos);
        return $this->generateAdvertisements($result, $advertisements);
    }

    protected function generateAdvertisements(array $result, array $advertisements): array
    {
        foreach ($advertisements as $advertisement) {
            $advertisement['image_url'] = $this->getBaseUrl() . $advertisement['image_url'];
            $result[] = $advertisement;
        }

        return $result;
    }

    protected function getBaseUrl()
    {
        $urlArr = parse_url($this->url);
        $port = $urlArr['port'] ?? '80';

        if ($urlArr['scheme'] == 'https' and empty($urlArr['port'])) {
            $port = 443;
        }

        $result = $urlArr['scheme'] . '://' . $urlArr['host'] . ':' . $port;
        if(!empty(env("TEST_IMG_URL"))) {
            return env("TEST_IMG_URL");
        }

        return $result;
    }

    protected function generateImageGroups(array $result, array $imageGroups): array
    {
        foreach ($imageGroups as $imageGroup) {
            $url = $this->getUrl($imageGroup);
            $imageGroup['thumbnail'] = $url . $imageGroup['thumbnail'];
            $imageGroup['url'] = $url . $imageGroup['url'];
            foreach ($imageGroup['images_limit'] as $key => $image) {
                $imageGroup['images_limit'][$key]['thumbnail'] = $url . $imageGroup['images_limit'][$key]['thumbnail'];
                $imageGroup['images_limit'][$key]['url'] = $url . $imageGroup['images_limit'][$key]['url'];
            }

            $result[] = $imageGroup;
        }

        return $result;
    }

    protected function generateVideos(array $result, array $videos): array
    {
        foreach ($videos as $video) {
            $video['cover_thumb'] = 'https://new.cnzuqiu.mobi' . $video['cover_thumb'];
            $video['full_m3u8'] = 'https://video.iwanna.tv' . $video['full_m3u8'];
            $video['m3u8'] = 'https://video.iwanna.tv' . $video['m3u8'];
            $video['source'] = 'https://video.iwanna.tv' . $video['source'];

            $result[] = $video;
        }

        return $result;
    }

    protected function popularImageGroups(int $page, int $limit = 10): array
    {
        $hotImages = ImageGroup::where('hot_order', '>=', 1)
            ->with(['tags', 'imagesLimit'])
            ->orderBy('hot_order')
            ->offset($page * $limit)
            ->limit($limit)
            ->get();

        $remain = $limit - $hotImages->count();
        if ($remain == 0) {
            return $hotImages->toArray();
        }

        $hotImageIds = $hotImages->pluck('id')->toArray();
        $clickService = make(ClickService::class);
        $clicks = $clickService->calculatePopularClick(ImageGroup::class, $remain, $page, $hotImageIds);

        $ids = $this->getIds($clicks);

        $clickImageGroups = ImageGroup::with(['tags', 'imagesLimit'])->whereIn('id', $ids)->get()->toArray();
        $clickImageGroupsArr = $this->sortClickAndModels($clicks, $clickImageGroups);
        $remain = $remain - count($clickImageGroups);
        $result = array_merge($clickImageGroupsArr, $hotImages->toArray());

        if ($remain == 0) {
            return $result;
        }

        $ids = $this->getIds($result);

        $imageGroups = $this->imageGroupService->getImageGroups(null, $page, $remain, $ids)->toArray();

        return array_merge($result, $imageGroups);
    }

    protected function sortClickAndModels(array $clicks, array $models): array
    {
        $result = [];
        foreach ($models as $model) {
            foreach ($clicks as $click) {
                if ($click['id'] == $model['id']) {
                    $model['total'] = $click['total'];
                    $result[] = $model;
                }
            }
        }

        return \Hyperf\Collection\collect($result)->sortByDesc('total')->toArray();
    }

    protected function popularVideos(int $page, int $limit = 10): array
    {
        $hotVideos = Video::with('tags')
            ->where('hot_order', '>=', 1)
            ->orderBy('hot_order')
            ->offset($page * $limit)
            ->limit($limit)
            ->get();

        $remain = $limit - $hotVideos->count();
        if ($remain == 0) {
            return $hotVideos->toArray();
        }

        $hotVideoIds = $hotVideos->pluck('id')->toArray();
        $clickService = make(ClickService::class);
        $clicks = $clickService->calculatePopularClick(Video::class, $remain, $page, $hotVideoIds);

        $ids = $this->getIds($clicks);

        $clickVideos = Video::with('tags')->whereIn('id', $ids)->get()->toArray();
        $clickVideosArr = $this->sortClickAndModels($clicks, $clickVideos);

        $remain = $remain - count($clickVideos);

        $result = array_merge($clickVideosArr, $hotVideos->toArray());

        if ($remain == 0) {
            return $result;
        }

        $videos = $this->videoService->getVideos(null, $page, 9, $remain)->toArray();

        return array_merge($videos, $result);
    }

    protected function getIds(array $models): array
    {
        $result = [];

        foreach ($models as $model) {
            $result[] = $model['id'];
        }

        return $result;
    }

    protected function getPerLimit(string $type, int $limit): int
    {
        return (int) match ($type) {
            Video::class => floor($limit * self::NORMAL_VIDEO_PERCENT),
            ImageGroup::class => floor($limit * self::NORMAL_IMAGE_GROUP_PERCENT),
            Advertisement::class => floor($limit * self::ADVERTISEMENT_PAGE_PER),
        };
    }

    protected function getHotOrderPerLimit(string $type, int $limit): int
    {
        return (int) match ($type) {
            Video::class => floor($limit * self::HOT_ORDER_VIDEO_PERCENT),
            ImageGroup::class => floor($limit * self::HOT_ORDER_IMAGE_GROUP_PERCENT),
        };
    }

    protected function getUrl(array $model): string
    {
        if ($model['sync_id'] > 0) {
            return env('IMAGE_GROUP_ENCRYPT_URL');
        }

        return $this->getBaseUrl();
    }

    protected function getAdvertisementsLimit(int $page, int $limit) : int
    {
        $last =  floor($limit * ($page - 1) / self::ADVERTISEMENT_PAGE_PER);
        $now = floor($limit * $page / self::ADVERTISEMENT_PAGE_PER);

        if ($now - $last > 0) {
            return (int) ($now - $last);
        }

        return 0;
    }
}

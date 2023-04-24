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

use App\Middleware\LoginLimitMiddleware;
use App\Model\Advertisement;
use App\Model\Member;
use App\Model\MemberFollow;
use App\Model\BuyMemberLevel;
use App\Model\ImageGroup;
use App\Model\MemberLevel;
use App\Model\MemberVerification;
use App\Model\Order;
use App\Model\Product;
use App\Model\Role;
use App\Model\User;
use App\Model\MemberTag;
use App\Model\OrderDetail;
use App\Model\Video;
use Carbon\Carbon;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;

class MemberService
{
    public const CACHE_KEY = 'member:token:';

    public const DEVICE_CACHE_KEY = 'member:device:';

    public const EXPIRE_VERIFICATION_MINUTE = 10;

    protected Redis $redis;

    protected \Psr\Log\LoggerInterface $logger;

    public function __construct(Redis $redis, LoggerFactory $loggerFactory)
    {
        $this->redis = $redis;
        $this->logger = $loggerFactory->get('reply');
    }

    public function apiGetUser(array $userInfo)
    {
        $user = $this->getUserFromAccount($userInfo['account']);

        if (! $user) {
            return false;
        }

        return $user;
    }

    public function checkPassword($plain, $hash): bool
    {
        if (password_verify($plain, $hash)) {
            return true;
        }
        return false;
    }

    public function apiRegisterUser(array $data): Member
    {
        $name = $data['name'];
        if (empty($name)) {
            $name = Member::VISITOR_NAME . substr(hash('sha256', $this->randomStr(), false), 0, 10);
        }
        $model = new Member();
        $model->name = $name;
        if (! empty($data['password'])) {
            $model->password = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        $model->sex = $data['sex'];
        $model->age = $data['age'];
        $model->avatar = $model->avatar ?? '';
        if (! empty($data['avatar'])) {
            $model->avatar = $data['avatar'];
        }
        if (! empty($data['email'])) {
            $model->email = $data['email'];
        }
        if (! empty($data['phone'])) {
            $model->phone = $data['phone'];
        }
        // $model->email = $data['email'];
        // $model->phone = $data['phone'];
        $model->status = Member::STATUS['VISITORS'];
        $model->member_level_status = Role::API_DEFAULT_USER_ROLE_ID;
        $model->account = $data['account'];
        $model->device = $data['device'];
        $model->register_ip = $data['register_ip'];
        $model->save();

        return $model;
    }

    public function moveUserAvatar($file): string
    {
        $extension = $file->getExtension();
        $filename = sha1(Carbon::now()->toDateTimeString());
        if (! file_exists(BASE_PATH . '/public/avatar')) {
            mkdir(BASE_PATH . '/public/avatar', 0755);
        }
        $imageUrl = '/image/' . $filename . '.' . $extension;
        $path = BASE_PATH . '/public' . $imageUrl;
        $file->moveTo($path);

        return $imageUrl;
    }

    public function saveToken(int $userId, string $token): void
    {
        $this->redis->set(self::CACHE_KEY . $userId, $token);
    }

    public function checkAndSaveDevice(int $userId, string $uuid): bool
    {
        $key = self::DEVICE_CACHE_KEY . $userId;
        if (! $this->redis->exists($key)) {
            $today = Carbon::now()->toDateString();
            $nextDay = Carbon::parse($today . ' 00:00:00')->addDay()->timestamp;
            $expire = $nextDay - time();
            $this->redis->set($key, $uuid, $expire);
            return true;
        }

        $redisUUid = $this->redis->get($key);

        if ($redisUUid == $uuid) {
            return true;
        }

        return false;
    }

    public function updateUser(int $id, array $data): void
    {
        $model = Member::find($id);
        if (! empty($data['name']) and empty($model->name)) {
            $model->name = $data['name'];
        }

        if (! empty($data['password'])) {
            $model->password = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        if (! empty($data['sex'])) {
            $model->sex = $data['sex'];
        }

        if (! empty($data['age'])) {
            $model->age = $data['age'];
        }

        if (! empty($data['avatar'])) {
            $model->avatar = $data['avatar'];
        }

        if (! empty($data['email'])) {
            $model->email = $data['email'];
        }

        if (! empty($data['phone'])) {
            $model->phone = $data['phone'];
        }

        if (! empty($data['account'])) {
            $model->account = $data['account'];
            // 遊客 -> 會員未驗證
            if ($model->status == Member::STATUS['VISITORS']) {
                $model->status = Member::STATUS['NOT_VERIFIED'];
            }
        }

        if (! empty($data['device'])) {
            $model->device = $data['device'];
        }

        if (! empty($data['last_ip'])) {
            $model->last_ip = $data['last_ip'];
        }
        $model->save();
    }

    // 使用者列表
    public function getList($page, $pagePer)
    {
        // 撈取 遊客 註冊未驗證 註冊已驗證 會員
        return Member::select()->where('status', '<=', 2)->offset(($page - 1) * $pagePer)->limit($pagePer)->get();
    }

    // 使用者列表
    public function allCount(): int
    {
        return Member::count();
    }

    public function storeUser(array $data)
    {
        $model = new Member();
        if (! empty($data['id']) and Member::where('id', $data['id'])->exists()) {
            $model = Member::find($data['id']);
        }

        if (! empty($data['name']) and empty($model->name)) {
            $model->name = $data['name'];
        }
        if (! empty($data['password'])) {
            $model->password = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        $model->sex = $data['sex'];
        $model->age = $data['age'];
        $model->avatar = $data['avatar'];
        if (! empty($data['email'])) {
            $model->email = $data['email'];
        }
        if (! empty($data['phone'])) {
            $model->phone = $data['phone'];
        }
        $model->status = $data['status'];
        $model->member_level_status = $data['member_level_status'];
        $model->coins = $data['coins'];
        $model->diamond_coins = $data['diamond_coins'];
        $model->diamond_quota = $data['diamond_quota'];
        $model->vip_quota = $data['vip_quota'];
        $model->free_quota = $data['free_quota'];
        $model->free_quota_limit = $data['free_quota_limit'];

        $model->save();

        if($data['member_level_status'] > MemberLevel::NO_MEMBER_LEVEL){
            if (! empty($data['id']) and BuyMemberLevel::where('member_id', $data['id'])->where('member_level_type', MemberLevel::TYPE_LIST[$data['member_level_status']-1])->whereNull('deleted_at')->exists()) {
                $buy_model = BuyMemberLevel::where('member_id', $data['id'])->where('member_level_type', MemberLevel::TYPE_LIST[$data['member_level_status']-1])->whereNull('deleted_at')->first();
                $buy_model -> start_time = $data['start_time'];
                $buy_model -> end_time = $data['end_time'];
                $buy_model -> save();
            }else{
                $buy_model = new BuyMemberLevel();
                $buy_model -> member_id = $model -> id;
                $buy_model -> member_level_type = MemberLevel::TYPE_LIST[$data['member_level_status']-1];
                $buy_model -> member_level_id = 0;
                $buy_model -> order_number = '';
                $buy_model -> start_time = $data['start_time'];
                $buy_model -> end_time = $data['end_time'];
                $buy_model->save();
            }
        }
    }

    public function deleteUser($id)
    {
        $record = Member::findOrFail($id);
        $record->status = User::STATUS['DELETE'];
        $record->save();
    }

    public function getVerificationCode(int $memberId): string
    {
        $now = Carbon::now()->toDateTimeString();
        $model = MemberVerification::where('member_id', $memberId)
            ->where('expired_at', '>=', $now)
            ->first();

        if (! empty($model)) {
            return $model->code;
        }

        return $this->createVerificationCode($memberId);
    }

    public function getUserFromAccount(?string $account)
    {
        $user = Member::where('account', $account)->first();

        if (empty($user)) {
            return false;
        }

        return $user;
    }

    public function getMemberFollowList($user_id, $follow_type = '')
    {
        if (empty($follow_type)) {
            $type_arr = MemberFollow::TYPE_LIST;
        } else {
            $type_arr = [$follow_type];
        }

        foreach ($type_arr as $key => $value) {
            // image video略過 有需要再開啟
            if ($value == 'image' || $value == 'video') {
                continue;
            }
            $class_name = MemberFollow::TYPE_CORRESPOND_LIST[$value];
            switch ($value) {
                case 'image':
                    $query = $class_name::join('member_follows', function ($join) use ($class_name) {
                        $join->on('member_follows.correspond_id', '=', 'images.id')
                            ->where('member_follows.correspond_type', '=', $class_name);
                    })->select('images.id', 'images.title', 'images.thumbnail', 'images.description');
                    break;
                case 'video':
                    $query = $class_name::join('member_follows', function ($join) use ($class_name) {
                        $join->on('member_follows.correspond_id', '=', 'videos.id')
                            ->where('member_follows.correspond_type', '=', $class_name);
                    })->select('videos.*');
                    break;
                case 'actor':
                    $query = $class_name::join('member_follows', function ($join) use ($class_name) {
                        $join->on('member_follows.correspond_id', '=', 'actors.id')
                            ->where('member_follows.correspond_type', '=', $class_name);
                    })->select('actors.id', 'actors.sex', 'actors.name');
                    break;
                case 'tag':
                    $query = $class_name::join('member_follows', function ($join) use ($class_name) {
                        $join->on('member_follows.correspond_id', '=', 'tags.id')
                            ->where('member_follows.correspond_type', '=', $class_name);
                    })->select('tags.id', 'tags.name');
                    break;
                default:
                    # code...
                    break;
            }
            $result[$value] = $query->where('member_follows.member_id', '=', $user_id)->whereNull('member_follows.deleted_at')->get()->toArray();
        }

        return $result;
    }

    // 亂處產生一個string
    public function randomStr($length = 8)
    {
        $url = '';
        $charray = array_merge(range('a', 'z'), range('0', '9'));
        $max = count($charray) - 1;
        for ($i = 0; $i < $length; ++$i) {
            $randomChar = mt_rand(0, $max);
            $url .= $charray[$randomChar];
        }
        return $url;
    }

    public function createOrUpdateLoginLimitRedisKey(string $deviceId)
    {
        $now = Carbon::now()->timestamp;
        $tomorrow = Carbon::tomorrow()->setHour(0)->setMinute(0)->setSecond(0)->timestamp;
        $expire = $tomorrow - $now;

        if ($this->redis->exists(LoginLimitMiddleware::LOGIN_LIMIT_CACHE_KEY . $deviceId)) {
            $this->redis->incr(LoginLimitMiddleware::LOGIN_LIMIT_CACHE_KEY . $deviceId);
        } else {
            $this->redis->set(LoginLimitMiddleware::LOGIN_LIMIT_CACHE_KEY . $deviceId, 1, $expire);
        }
    }

    protected function createVerificationCode(int $memberId): string
    {
        $model = new MemberVerification();
        $model->member_id = $memberId;
        $model->code = str_random();
        $model->expired_at = Carbon::now()->addMinutes(self::EXPIRE_VERIFICATION_MINUTE)->toDateTimeString();
        $model->save();

        return $model->code;
    }

    public function getMemberProductId($memberId, $type, $offset, $limit): array
    {
        $query = Order::join('order_details', 'order_details.order_id', 'orders.id')
                    ->join('products', 'products.id', 'order_details.product_id')
                    ->select('products.id', 'products.type', 'products.correspond_id', 'products.name', 'products.expire')
                    ->where('orders.user_id', $memberId)
                    ->where('orders.status', Order::ORDER_STATUS['finish']);
        switch ($type) {
            case 'all':
                $query = $query -> whereIn('products.type', [Product::TYPE_LIST[0], Product::TYPE_LIST[1]]);
                break;
            case 'image':
                $query = $query -> where('products.type', Product::TYPE_LIST[0]);
                break;
            case 'video':
                $query = $query -> where('products.type', Product::TYPE_LIST[1]);
                break;
            default:
                $query = $query -> whereIn('products.type', [Product::TYPE_LIST[0], Product::TYPE_LIST[1]]);
                break;
        }

        if(!empty($offset)){
            $query = $query -> offset($offset);
        }
        if(!empty($limit)){
            $query = $query -> limit($limit);
        }
        $model = $query -> get();
        if(!empty($model)){
            $image_arr = [];
            $video_arr = [];
            // ActorClassification::findOrFail($id);
            foreach ($model as $key => $value) {
                if($value->type == Product::TYPE_LIST[0]){
                    $image = ImageGroup::findOrFail($value->correspond_id);
                    array_push($image_arr, array(
                        'product_id' => $value->id,
                        'source_id' => $value->correspond_id, 
                        'name' => $value->name, 
                        'thumbnail' => env('IMG_DOMAIN') . $image->thumbnail ?? '',
                        'expire' =>  $value->expire,
                    ));
                }
                
                if($value->type == Product::TYPE_LIST[1]){
                    $video = Video::findOrFail($value->correspond_id);
                    array_push($video_arr, array(
                        'product_id' => $value->id,
                        'source_id' => $value->correspond_id,
                        'name' => $value->name, 
                        'thumbnail' => env('IMG_DOMAIN') . $video->cover_thumb ?? '', 
                        'expire' =>  $value->expire,
                    ));
                }
            }
            $data['image'] = $image_arr;
            $data['video'] = $video_arr;
        }else{
            $data['image'] = [];
            $data['video'] = [];
        }  
        return $data;
    }

    // 獲取個人推薦商品 (上架中的) (暫時遮掉)
    // public function getPersonalList($user_id, $method, $offset, $limit)
    // {
    //     // 
    //     $half_offset = ceil($offset/2);

    //     // 扣掉廣告
    //     $limit = $limit - 1 ;
    //     $image_limit = floor($limit/2);
    //     $video_limit = ceil($limit/2);

    //     // 
    //     if($method == 'most'){
    //         var_dump($method);
    //         $sub_query = OrderDetail::selectRaw('count(*) as count')->whereColumn('order_details.product_id', '=', 'products.id');
    //     }

    //     // 獲取該會員前五個點擊標籤
    //     $tags = [];
    //     $member_tags = MemberTag::select('tag_id')->where('member_id', $user_id)->OrderBy('count')->limit(5)->get();
    //     foreach ($member_tags as $key => $member_tag) {
    //         array_push($tags, $member_tag -> tag_id);
    //     }

    //     // 撈取包含這五個標籤的上架商品 (圖片)
    //     $type = Product::TYPE_LIST[0];
    //     $img_query = ImageGroup::join('tag_corresponds', function ($join) use ($type, $tags) {
    //                             $join->on('image_groups.id', '=', 'tag_corresponds.correspond_id')
    //                                 ->where('tag_corresponds.correspond_type', Product::TYPE_CORRESPOND_LIST[$type])
    //                                 ->whereIn('tag_corresponds.tag_id',$tags)
    //                                 ->join('products', function ($join) use ($type) {
    //                                     $join->on('products.correspond_id', '=', 'tag_corresponds.correspond_id')
    //                                     ->where('products.type',$type)
    //                                     ->where('products.expire',Product::EXPIRE['no']);
    //                                 });
    //                         })->selectRaw('products.id, products.name, products.type as product_type, products.correspond_id, products.currency, products.selling_price, products.diamond_price, image_groups.pay_type, image_groups.thumbnail, (select count(*) from images where group_id = image_groups.id ) as num');

    //     // 撈取包含這五個標籤的上架商品 (影片)
    //     $type = Product::TYPE_LIST[1];
    //     $video_query = Video::join('tag_corresponds', function ($join) use ($type, $tags) {
    //                             $join->on('videos.id', '=', 'tag_corresponds.correspond_id')
    //                                 ->where('tag_corresponds.correspond_type', $type)
    //                                 ->whereIn('tag_corresponds.tag_id',$tags)
    //                                 ->join('products', function ($join) use ($type) {
    //                                     $join->on('products.correspond_id', '=', 'tag_corresponds.correspond_id')
    //                                     ->where('products.type',$type)
    //                                     ->where('products.expire',Product::EXPIRE['no']);
    //                                 });
    //                         })->selectRaw('products.id, products.name, products.type as product_type, products.correspond_id, products.currency, products.selling_price, products.diamond_price, videos.is_free as pay_type, videos.cover_thumb as thumbnail, videos.duration');
        
    //     if(!empty($offset)){
    //         $img_query = $img_query -> offset($half_offset);
    //         $video_query = $video_query -> offset($half_offset);
    //     }
    //     if(!empty($limit)){
    //         $img_query = $img_query -> limit($image_limit);
    //         if($img_query -> count() < $image_limit){
    //             $video_query = $video_query -> limit($video_limit + ($image_limit - $img_query -> count()));
    //         }else{
    //             $video_query = $video_query -> limit($video_limit);
    //         } 
    //     }
    //     switch ($method) {
    //         case 'recommend':
    //             // 個人推薦
    //             $images = $img_query -> groupBy('products.id') -> get();
    //             $videos = $video_query -> groupBy('products.id') -> get();
    //             break;
    //         case 'new':
    //             // 個人推薦
    //             $images = $img_query -> groupBy('products.id') -> orderBy('products.updated_at', 'desc') -> get();
    //             $videos = $video_query -> groupBy('products.id') -> orderBy('products.updated_at', 'desc') -> get();
    //             break;
    //         case 'most':
    //             // 個人推薦
    //             $images = $img_query -> selectRaw('(select count(*) from order_details where order_details.product_id = products.id) as count') -> groupBy('products.id') -> orderBy('count', 'desc') -> get();
    //             $videos = $video_query -> selectRaw('(select count(*) from order_details where order_details.product_id = products.id) as count') -> groupBy('products.id') -> orderBy('count', 'desc') -> get();
    //             break;
    //         default:
    //             # code...
    //             break;
    //     }
    //     $merge_arr = $this->shuffleMergeArray($images->toArray(), $videos->toArray());

    //     // 撈出圖片廣告
    //     $ads = Advertisement::select('name', 'image_url', 'url')
    //                         ->where('position', Advertisement::POSITION['ad_image'])
    //                         ->where('expire', Product::EXPIRE['no'])
    //                         ->inRandomOrder()->take(1)->get()->toArray();
    //     $result = array_merge($ads, $merge_arr);
    //     foreach ($result as $key => $value) {
    //         if(! empty($result[$key]['thumbnail']))$result[$key]['thumbnail'] = env('IMG_DOMAIN') . $value['thumbnail'];
    //         if(! empty($result[$key]['image_url']))$result[$key]['image_url'] = env('IMG_DOMAIN') . $value['image_url'];
    //     }
    //     return $result;
    // }

    //隨機合併兩個陣列元素，保持原有資料的排序不變（即各個陣列的元素在合併後的陣列中排序與自身原來一致）
    protected function shuffleMergeArray($array1, $array2) {
        $mergeArray = array();
        $sum = count($array1) + count($array2);
        for ($k = $sum; $k > 0; $k--) {
        $number = mt_rand(1, 2);
        if ($number == 1) {
            $mergeArray[] = $array2 ? array_shift($array2) : array_shift($array1);
        } else {
            $mergeArray[] = $array1 ? array_shift($array1) : array_shift($array2);
        }
        }
        return $mergeArray;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AbstractController;
use App\Model\User;
use App\Model\UserTag;
use App\Request\AddUserTagRequest;
use App\Request\UserDetailRequest;
use App\Request\UserLoginRequest;
use App\Request\UserRegisterRequest;
use App\Request\UserUpdateRequest;
use App\Service\UserService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * @Controller()
 */
class UserController extends AbstractController
{
    /**
     * @RequestMapping(path="login", methods="post")
     */
    public function login(UserLoginRequest $request, UserService $service)
    {
        $user = $service->checkUser([
            'name' => $request->input('name'),
            'password' => $request->input('password'),
            'uuid' => $request->input('uuid')
        ]);


        if ($user) {
            $token = auth()->login($user);
            return $this->success([
                'token' => $token
            ]);
        }

        return $this->error('',401);
    }

    /**
     * @RequestMapping(path="register", methods="post")
     */
    public function register(UserRegisterRequest $request, UserService $service)
    {
        $path = '';
        if ($request->hasFile('avatar')) {
            $path = $service->moveUserAvatar($request->file('avatar'));
        }

        $user = $service->apiRegisterUser([
            'name' => $request->input('name'),
            'password' => $request->input('password'),
            'sex' => $request->input('sex', User::SEX['DEFAULT']),
            'age' => $request->input('age', 18),
            'avatar' => $path,
            'email' => $request->input('email', ''),
            'phone' => $request->input('phone', ''),
            'uuid' => $request->input('uuid', null)
        ]);

        $token = auth()->login($user);
        return $this->success([
            'token' => $token
        ]);
    }

    /**
     * @RequestMapping(path="tag", methods="post")
     */
    public function addUserTag(AddUserTagRequest $request)
    {
        $tags = $request->input('tags');
        $userId = auth()->user()->getId();

        foreach ($tags as $tag) {
            if (!is_int($tag)) {
                continue;
            }

            $model = UserTag::where('user_id', $userId)
                ->where('tag_id', $tag)
                ->first();

            if (empty($model)) {
                $model = new UserTag();
            }

            $model->user_id = $userId;
            $model->tag_id = $tag;
            $model->count = empty($model->count) ? 0 : $model->count++;
            $model->save();
        }

        return $this->success();
    }

    /**
     * @RequestMapping(path="logout", methods="get")
     */
    public function logout()
    {
        auth()->logout();

        return $this->success();
    }

    /**
     * @RequestMapping(path="update", methods="put")
     */
    public function update(UserUpdateRequest $request, UserService $service)
    {
        $userId = auth()->user()->getId();
        $path = '';
        if ($request->hasFile('avatar')) {
            $path = $service->moveUserAvatar($request->file('avatar'));
        }

        $service->updateUser($userId, [
            'name' => $request->input('name'),
            'password' => $request->input('password'),
            'sex' => $request->input('sex'),
            'age' => $request->input('age'),
            'avatar' => $path,
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'uuid' => $request->input('uuid')
        ]);

        return $this->success();
    }
    /**
     * @RequestMapping(path="detail", methods="get")
     */
    public function detail(UserDetailRequest $request)
    {
       $id = $request->input('id');

       return $this->success(User::find($id)->toArray());
    }
}

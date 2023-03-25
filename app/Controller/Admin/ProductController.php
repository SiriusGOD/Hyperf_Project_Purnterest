<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use App\Middleware\PermissionMiddleware;
use App\Model\Image;
use Carbon\Carbon;
use App\Service\ImageService;
use App\Service\VideoService;
use App\Service\ProductService;
use App\Request\ProductRequest;
use App\Request\ProductMultipleStoreRequest;
use App\Model\Product;
use App\Model\Video;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\Paginator\Paginator;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\View\RenderInterface;
use HyperfExt\Jwt\Contracts\JwtFactoryInterface;
use HyperfExt\Jwt\Contracts\ManagerInterface;
use HyperfExt\Jwt\Jwt;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * @Controller
 * @Middleware(PermissionMiddleware::class)
 */
class ProductController extends AbstractController
{
    /**
     * 提供了对 JWT 编解码、刷新和失活的能力。
     */
    protected ManagerInterface $manager;

    /**
     * 提供了从请求解析 JWT 及对 JWT 进行一系列相关操作的能力。
     */
    protected Jwt $jwt;

    protected RenderInterface $render;

    /**
     * @Inject
     */
    protected ValidatorFactoryInterface $validationFactory;

    public function __construct(ManagerInterface $manager, JwtFactoryInterface $jwtFactory, RenderInterface $render)
    {
        parent::__construct();
        $this->manager = $manager;
        $this->jwt = $jwtFactory->make();
        $this->render = $render;
    }

    /**
     * @RequestMapping(path="index", methods={"GET"})
     */
    public function index(RequestInterface $request, ResponseInterface $response)
    {
        // 顯示幾筆
        $step = Product::PAGE_PER;
        $page = $request->input('page') ? intval($request->input('page'), 10) : 1;
        $query = Product::select('*')
            ->whereNull('deleted_at')
            ->offset(($page - 1) * $step)
            ->limit($step);
        $products = $query->get();

        $query = Product::select('*')->whereNull('deleted_at');
        $total = $query->count();

        $data['last_page'] = ceil($total / $step);
        if ($total == 0) {
            $data['last_page'] = 1;
        }
        $data['navbar'] = trans('default.product_control.product_control');
        $data['product_active'] = 'active';
        $data['total'] = $total;
        $data['datas'] = $products;
        $data['page'] = $page;
        $data['step'] = $step;
        $path = '/admin/product/index';
        $data['next'] = $path . '?page=' . ($page + 1);
        $data['prev'] = $path . '?page=' . ($page - 1);
        $paginator = new Paginator($products, $step, $page);

        $data['paginator'] = $paginator->toArray();

        return $this->render->render('admin.product.index', $data);
    }

    /**
     * @RequestMapping(path="expire", methods={"POST"})
     */
    public function expire(RequestInterface $request, ResponseInterface $response, ProductService $service): PsrResponseInterface
    {
        $query = Product::where('id', $request->input('id'));
        $record = $query->first();

        if (empty($record)) {
            return $response->redirect('/admin/product/index');
        }

        $record->expire = $request->input('expire', 1);
        $record->save();
        $service->updateCache();
        return $response->redirect('/admin/product/index');
    }

    /**
     * @RequestMapping(path="create", methods={"get"})
     */
    public function create(RequestInterface $request)
    {
        $id = $request->input('id');
        $product_type = $request->input('product_type');
        if(!empty($product_type)){
            switch($product_type){
                case Image::class :
                    $model = Image::findOrFail($id);
                    break;

                case Video::class :
                    $model = Video::findOrFail($id);
                    break;    
            }
        }
        $model->expire = Product::EXPIRE['no'];
        $model->product_id = $model->id;
        $model->id = '';
        $data['navbar'] = trans('default.product_control.product_create');
        $data['product_active'] = 'active';
        $data['model'] = $model;
        $data['product_type'] = $product_type;
        return $this->render->render('admin.product.form', $data);
    }

    /**
     * @RequestMapping(path="choose", methods={"GET"})
     */
    public function choose(RequestInterface $request)
    {
        // 顯示幾筆
        $step = Product::PAGE_PER;
        $page = $request->input('page') ? intval($request->input('page'), 10) : 1;
        $product_type = $request->input('product_type');
        $product_name = $request->input('product_name');

        if(!empty($product_type)){
            $query = $product_type::select('*');
            $query_tatal = $product_type::select('*');

            if(!empty($product_name)){
                $query = $query->where('title', 'like', '%'.$product_name.'%');
                $query_tatal = $query_tatal->where('title', 'like', '%'.$product_name.'%');
            }

            $query = $query->offset(($page - 1) * $step)->limit($step);
            $products = $query->get();
            $total = $query_tatal->count();
            $data['last_page'] = ceil($total / $step);
        }else{
            $products = '';
            $total = 0;
        }

        if ($total == 0) {
            $data['last_page'] = 1;
        }
        
        $data['product_type'] = $product_type;
        $data['navbar'] = trans('default.product_control.product_create');
        $data['product_active'] = 'active';
        $data['total'] = $total;
        $data['datas'] = $products;
        $data['page'] = $page;
        $data['step'] = $step;
        $path = '/admin/product/choose';
        $data['next'] = $path . '?page=' . ($page + 1) . '&product_type=' . $product_type . '&product_name=' . $product_name;
        $data['prev'] = $path . '?page=' . ($page - 1) . '&product_type=' . $product_type . '&product_name=' . $product_name;
        $paginator = new Paginator($products, $step, $page);

        $data['paginator'] = $paginator->toArray();

        return $this->render->render('admin.product.choose', $data);
    }

    /**
     * @RequestMapping(path="store", methods={"POST"})
     */
    public function store(ProductRequest $request, ResponseInterface $response, ProductService $service)
    {
        $data['id'] = $request->input('id') ? $request->input('id') : null;
        $data['user_id'] = (int)auth('session')->user()->id;
        $data['type'] = $request->input('product_type');
        $data['correspond_id'] = $request->input('product_id') ? $request->input('product_id') : $request->input('correspond_id');
        $data['name'] = $request->input('product_name');
        $data['expire'] = (int) $request->input('expire');
        $data['start_time'] = $request->input('start_time');
        $data['end_time'] = $request->input('end_time');
        $data['currency'] = $request->input('product_currency');
        $data['selling_price'] = $request->input('product_price');

        $re = $service -> store($data);

        return $response->redirect('/admin/product/index');
    }

    /**
     * @RequestMapping(path="edit", methods={"get"})
     */
    public function edit(RequestInterface $request)
    {
        $id = $request->input('id');
        $model = Product::findOrFail($id);
        $model -> title = $model -> name;
        $data['model'] = $model;
        $data['product_type'] = $model -> type;
        $data['navbar'] = trans('default.product_control.product_edit');
        $data['product_active'] = 'active';
        return $this->render->render('admin.product.form', $data);
    }

    /**
     * @RequestMapping(path="delete", methods={"get"})
     */
    public function delete(RequestInterface $request, ResponseInterface $response, ProductService $service)
    {
        $id = $request->input('id');
        $service -> delete($id);

        return $response->redirect('/admin/product/index');
    }

    /**
     * @RequestMapping(path="multipleChoice", methods={"GET"})
     */
    public function multipleChoice(RequestInterface $request)
    {
        // 顯示幾筆
        $step = Product::PAGE_PER;
        $page = $request->input('page') ? intval($request->input('page'), 10) : 1;
        $product_type = $request->input('product_type');
        $product_name = $request->input('product_name');

        if(!empty($product_type)){
            $query = $product_type::select('*');
            $query_tatal = $product_type::select('*');
            
            if(!empty($product_name)){
                $query = $query->where('title', 'like', '%'.$product_name.'%');
                $query_tatal = $query_tatal->where('title', 'like', '%'.$product_name.'%');
            }
            $query = $query->offset(($page - 1) * $step)->limit($step);
            $products = $query->get();
            $total = $query_tatal->count();
            $data['last_page'] = ceil($total / $step);
        }else{
            $products = '';
            $total = 0;
        }

        if ($total == 0) {
            $data['last_page'] = 1;
        }
        
        $data['product_type'] = $product_type;
        $data['navbar'] = trans('default.product_control.product_create');
        $data['product_active'] = 'active';
        $data['total'] = $total;
        $data['datas'] = $products;
        $data['page'] = $page;
        $data['step'] = $step;
        $path = '/admin/product/multipleChoice';
        $data['next'] = $path . '?page=' . ($page + 1) . '&product_type=' . $product_type . '&product_name=' . $product_name;
        $data['prev'] = $path . '?page=' . ($page - 1) . '&product_type=' . $product_type . '&product_name=' . $product_name;
        $paginator = new Paginator($products, $step, $page);

        $data['paginator'] = $paginator->toArray();

        return $this->render->render('admin.product.multiplechoice', $data);
    }

    /**
     * @RequestMapping(path="multipleInsert", methods={"GET"})
     */
    public function multipleInsert(RequestInterface $request)
    {
        $data = json_decode($request->input('data'),true);
        $type = urldecode($request->input('type'));
        
        $product_id_arr = [];
        $product_name_arr = [];
        foreach ($data as $key => $value) {
            $model = $type::findOrFail($value);
            array_push($product_id_arr, $value);
            array_push($product_name_arr, $model -> title);
        }

        $data['model'] = $model;
        $data['product_type'] = $type;
        $data['product_id_arr'] = json_encode($product_id_arr);
        $data['product_name_arr'] = json_encode($product_name_arr);
        $data['navbar'] = trans('default.product_control.product_multiple_create');
        $data['product_active'] = 'active';
        return $this->render->render('admin.product.multipleform', $data);
    }

    /**
     * @RequestMapping(path="multipleStore", methods={"POST"})
     */
    public function multipleStore(ProductMultipleStoreRequest $request, ResponseInterface $response, ProductService $service)
    {
        $correspond_id = json_decode($request->input('correspond_id'), true);
        $correspond_name = json_decode($request->input('correspond_name'), true);

        $data['id'] = $request->input('id') ? $request->input('id') : null;
        $data['user_id'] = (int)auth('session')->user()->id;
        $data['type'] = $request->input('product_type');
        // $data['correspond_id'] = $request->input('product_id') ? $request->input('product_id') : $request->input('correspond_id');
        // $data['name'] = $request->input('product_name');
        $data['expire'] = (int) $request->input('expire');
        $data['start_time'] = $request->input('start_time');
        $data['end_time'] = $request->input('end_time');
        $data['currency'] = $request->input('product_currency');
        $data['selling_price'] = $request->input('product_price');

        foreach ($correspond_id as $key => $value) {
            $data['correspond_id'] = $value;
            $data['name'] = $correspond_name[$key];
            $re = $service -> store($data);
        }

        return $response->redirect('/admin/product/index');
    }

     /**
     * @RequestMapping(path="search", methods={"GET"})
     */
    public function search(RequestInterface $request)
    {
        // 顯示幾筆
        $step = Product::PAGE_PER;
        $page = $request->input('page') ? intval($request->input('page'), 10) : 1;
        $product_type = $request->input('product_type');
        $product_name = $request->input('product_name');

        if(!empty($product_type)){
            $query = Product::select('*')->where('type',$product_type);
            $query_tatal = Product::select('*')->where('type',$product_type);

            if(!empty($product_name)){
                $query = $query->where('name', 'like', '%'.$product_name.'%');
                $query_tatal = $query_tatal->where('name', 'like', '%'.$product_name.'%');
            }

            $query = $query->offset(($page - 1) * $step)->limit($step);
            $products = $query->get();
            $total = $query_tatal->count();
            $data['last_page'] = ceil($total / $step);
        }else{
            $products = '';
            $total = 0;
        }

        if ($total == 0) {
            $data['last_page'] = 1;
        }
        
        $data['product_type'] = $product_type;
        $data['navbar'] = trans('default.product_control.product_control');
        $data['product_active'] = 'active';
        $data['total'] = $total;
        $data['datas'] = $products;
        $data['page'] = $page;
        $data['step'] = $step;
        $path = '/admin/product/search';
        $data['next'] = $path . '?page=' . ($page + 1) . '&product_type=' . $product_type . '&product_name=' . $product_name;
        $data['prev'] = $path . '?page=' . ($page - 1) . '&product_type=' . $product_type . '&product_name=' . $product_name;
        $paginator = new Paginator($products, $step, $page);

        $data['paginator'] = $paginator->toArray();

        return $this->render->render('admin.product.index', $data);
    }
}

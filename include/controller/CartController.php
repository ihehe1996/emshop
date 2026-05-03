<?php

declare(strict_types=1);

/**
 * 购物车控制器。
 *
 * 方法说明：
 * - _index()  购物车页面
 * - add()     AJAX 添加商品
 * - update()  AJAX 更新数量
 * - remove()  AJAX 移除商品
 * - clear()   AJAX 清空购物车
 * - count()   AJAX 获取数量（页头角标用）
 */
class CartController extends BaseController
{
    private CartModel $cartModel;
    private int $userId = 0;
    private string $guestToken = '';

    public function __construct(View $view, Dispatcher $dispatcher, string $controllerName)
    {
        parent::__construct($view, $dispatcher, $controllerName);
        $this->cartModel = new CartModel();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 购物车所有者：登录用户用 user_id，游客用 guest_token（与订单 guest_token 一致）
        $frontUser = $_SESSION['em_front_user'] ?? null;
        if (!empty($frontUser['id'])) {
            $this->userId = (int) $frontUser['id'];
        } else {
            $this->guestToken = GuestToken::get();
        }
    }

    /**
     * 购物车页面。
     */
    public function _index(): void
    {
        $this->view->setTitle('购物车');

        $items = $this->cartModel->getItems($this->userId, $this->guestToken);

        // 计算汇总
        $totalPrice = 0.0;
        $totalCount = 0;
        foreach ($items as $item) {
            if ($item['is_valid']) {
                $totalPrice += $item['subtotal'];
            }
            $totalCount += $item['quantity'];
        }

        // —— 支付方式：未登录时禁用余额支付；默认选中第一个可用项
        $paymentMethods = PaymentService::getMethods();
        $isGuest = $this->userId <= 0;
        $defaultAssigned = false;
        foreach ($paymentMethods as &$pm) {
            $pm['disabled'] = ($pm['code'] === 'balance' && $isGuest);
            $pm['selected'] = false;
            if (!$defaultAssigned && !$pm['disabled']) {
                $pm['selected'] = true;
                $defaultAssigned = true;
            }
        }
        unset($pm);

        // 购物车任一商品声明 needs_address 时，结算页要求选收货地址（登录用户可选、游客被拦）
        //   - 走 GoodsTypeManager::getTypeConfig 读插件 goods_type_register 里的扩展字段
        //   - 对插件通用（不硬编码 physical）：任何新增商品类型插件只要声明 needs_address=true 都自动生效
        $needsAddress = false;
        foreach ($items as $item) {
            if (!empty($item['is_valid']) && !empty($item['goods_type'])) {
                $cfg = GoodsTypeManager::getTypeConfig((string) $item['goods_type']);
                $need = !empty($cfg['needs_address']);
                $need = (bool) applyFilter('goods_needs_address', $need, $item);
                if ($need) {
                    $needsAddress = true;
                    break;
                }
            }
        }

        $userAddresses = [];
        $defaultAddressId = 0;
        if ($needsAddress && $this->userId > 0) {
            $userAddresses = UserAddressModel::listByUserId((int) $this->userId);
            foreach ($userAddresses as $addr) {
                if ((int) ($addr['is_default'] ?? 0) === 1) {
                    $defaultAddressId = (int) $addr['id'];
                    break;
                }
            }
            if ($defaultAddressId === 0 && !empty($userAddresses)) {
                $defaultAddressId = (int) $userAddresses[0]['id'];
            }
        }

        $this->view->setData([
            'cart_items'       => $items,
            'total_price'      => $totalPrice,
            'total_count'      => $totalCount,
            'payment_methods'  => $paymentMethods,
            'is_guest'         => $isGuest,
            'guest_find_config' => GuestFindModel::getConfig(),
            'needs_address'    => $needsAddress,
            'user_addresses'   => $userAddresses,
            'default_address_id' => $defaultAddressId,
        ]);
        $this->view->render('cart');
    }

    /**
     * AJAX：添加商品到购物车。
     */
    public function add(): void
    {
        if (!Request::isPost()) {
            Response::error('无效请求');
        }

        $goodsId  = (int) Input::post('goods_id', 0);
        $specId   = (int) Input::post('spec_id', 0);
        $quantity = (int) Input::post('quantity', 1);

        if ($goodsId <= 0) {
            Response::error('商品不存在');
        }
        if ($quantity <= 0) {
            $quantity = 1;
        }

        // 验证商品是否存在且上架
        $goods = GoodsModel::getById($goodsId);
        if (!$goods || (int) $goods['status'] !== 1 || (int) $goods['is_on_sale'] !== 1 || $goods['deleted_at'] !== null) {
            Response::error('商品不存在或已下架');
        }
        // 作用域校验：防止用户拿着别家商户 / 商户专属商品 id 在主站前台加购
        if (!MerchantContext::isGoodsVisibleToCurrentScope($goods)) {
            Response::error('商品不存在或已下架');
        }

        // 验证规格
        if ($specId > 0) {
            $specs = GoodsModel::getSpecsByGoodsId($goodsId);
            $specFound = false;
            foreach ($specs as $s) {
                if ((int) $s['id'] === $specId) {
                    $specFound = true;
                    if ((int) $s['stock'] <= 0) {
                        Response::error('该规格暂无库存');
                    }
                    break;
                }
            }
            if (!$specFound) {
                Response::error('规格不存在');
            }
        } else {
            // 单规格商品，取第一个规格
            $specs = GoodsModel::getSpecsByGoodsId($goodsId);
            if (!empty($specs)) {
                $specId = (int) $specs[0]['id'];
            }
        }

        $this->cartModel->addItem($this->userId, $this->guestToken, $goodsId, $specId, $quantity);

        // 返回最新购物车数量
        $count = $this->cartModel->getCount($this->userId, $this->guestToken);
        Response::success('已加入购物车', ['cart_count' => $count]);
    }

    /**
     * AJAX：更新购物车项数量。
     */
    public function update(): void
    {
        if (!Request::isPost()) {
            Response::error('无效请求');
        }

        $id       = (int) Input::post('id', 0);
        $quantity = (int) Input::post('quantity', 1);

        if ($id <= 0) {
            Response::error('参数错误');
        }

        $this->cartModel->updateQuantity($id, $quantity, $this->userId, $this->guestToken);

        // 重新计算汇总
        $items = $this->cartModel->getItems($this->userId, $this->guestToken);
        $totalPrice = 0.0;
        $totalCount = 0;
        foreach ($items as $item) {
            if ($item['is_valid']) {
                $totalPrice += $item['subtotal'];
            }
            $totalCount += $item['quantity'];
        }

        Response::success('已更新', [
            'cart_count'  => $totalCount,
            'total_price' => number_format($totalPrice, 2),
        ]);
    }

    /**
     * AJAX：移除购物车项。
     */
    public function remove(): void
    {
        if (!Request::isPost()) {
            Response::error('无效请求');
        }

        $id = (int) Input::post('id', 0);
        if ($id <= 0) {
            Response::error('参数错误');
        }

        $this->cartModel->removeItem($id, $this->userId, $this->guestToken);

        $count = $this->cartModel->getCount($this->userId, $this->guestToken);
        Response::success('已移除', ['cart_count' => $count]);
    }

    /**
     * AJAX：清空购物车。
     */
    public function clear(): void
    {
        if (!Request::isPost()) {
            Response::error('无效请求');
        }

        $this->cartModel->clearCart($this->userId, $this->guestToken);
        Response::success('购物车已清空', ['cart_count' => 0]);
    }

    /**
     * AJAX：修改购物车项的规格。
     */
    public function change_spec(): void
    {
        if (!Request::isPost()) {
            Response::error('无效请求');
        }

        $id      = (int) Input::post('id', 0);
        $specId  = (int) Input::post('spec_id', 0);

        if ($id <= 0 || $specId <= 0) {
            Response::error('参数错误');
        }

        // 获取当前购物车项
        $items = $this->cartModel->getItems($this->userId, $this->guestToken);
        $cartItem = null;
        foreach ($items as $item) {
            if ($item['id'] === $id) { $cartItem = $item; break; }
        }
        if (!$cartItem) {
            Response::error('购物车项不存在');
        }

        // 如果规格没变，直接返回
        if ($cartItem['spec_id'] === $specId) {
            Response::success('未变更');
            return;
        }

        // 验证新规格
        $rawSpecs = GoodsModel::getSpecsByGoodsId($cartItem['goods_id']);
        $newSpec = null;
        foreach ($rawSpecs as $s) {
            if ((int) $s['id'] === $specId) { $newSpec = $s; break; }
        }
        if (!$newSpec) {
            Response::error('规格不存在');
        }

        // 删除旧项，添加新项（处理同规格合并）
        $qty = $cartItem['quantity'];
        $this->cartModel->removeItem($id, $this->userId, $this->guestToken);
        $this->cartModel->addItem($this->userId, $this->guestToken, $cartItem['goods_id'], $specId, $qty);

        // 返回更新后的购物车数据
        $newItems = $this->cartModel->getItems($this->userId, $this->guestToken);
        $totalPrice = 0.0;
        $totalCount = 0;
        foreach ($newItems as $ni) {
            if ($ni['is_valid']) $totalPrice += $ni['subtotal'];
            $totalCount += $ni['quantity'];
        }

        Response::success('规格已更新', [
            'cart_count'  => $this->cartModel->getCount($this->userId, $this->guestToken),
            'total_price' => number_format($totalPrice, 2),
            'total_count' => $totalCount,
        ]);
    }

    /**
     * AJAX：获取商品的规格列表（购物车修改规格用）。
     */
    public function specs(): void
    {
        $goodsId = (int) Input::get('goods_id', 0);
        if ($goodsId <= 0) {
            Response::error('参数错误');
        }

        $rawSpecs = GoodsModel::getSpecsByGoodsId($goodsId);
        $list = [];
        foreach ($rawSpecs as $s) {
            $list[] = [
                'id'    => (int) $s['id'],
                'name'  => $s['name'],
                'price' => (float) $s['price'],
                'stock' => (int) $s['stock'],
            ];
        }

        Response::success('', ['specs' => $list]);
    }

    /**
     * AJAX：获取购物车数量（页头角标）。
     */
    public function count(): void
    {
        $count = $this->cartModel->getCount($this->userId, $this->guestToken);
        Response::success('', ['cart_count' => $count]);
    }
}

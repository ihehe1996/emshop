<?php

declare(strict_types=1);

/**
 * 订单控制器。
 *
 * 方法说明：
 * - create()   AJAX 创建订单（商品详情页直接购买）
 * - checkout() AJAX 购物车结算创建订单
 * - pay()      AJAX 支付订单
 * - _detail()  订单详情/结果页
 */
class OrderController extends BaseController
{
    /**
     * 获取当前用户身份。
     */
    private function getIdentity(): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $frontUser = $_SESSION['em_front_user'] ?? null;
        return [
            'user_id'     => !empty($frontUser['id']) ? (int) $frontUser['id'] : 0,
            'guest_token' => GuestToken::get(),
        ];
    }

    /**
     * AJAX：从商品详情页直接下单。
     * 所有必填/合法性校验都在此完成，前端不做二次校验。
     *
     * POST 参数：goods_id, spec_id, quantity, payment_code,
     *           extra_*（附加选项，可选）、
     *           guest_find_contact_query / guest_find_contact_type / guest_find_password_query（游客）
     */
    public function create(): void
    {
        if (!Request::isPost()) {
            Response::error('无效请求');
        }

        $goodsId     = (int) Input::post('goods_id', 0);
        $specId      = (int) Input::post('spec_id', 0);
        $quantity    = (int) Input::post('quantity', 1);
        $paymentCode = trim((string) Input::post('payment_code', ''));

        // —— 基础参数校验
        if ($goodsId <= 0) {
            Response::error('请选择商品');
        }
        if ($quantity <= 0) {
            $quantity = 1;
        }
        if ($paymentCode === '') {
            Response::error('请选择支付方式');
        }

        // —— 身份信息（提前到余额支付校验使用）
        $identity = $this->getIdentity();

        // —— 余额支付仅登录用户可用
        if ($paymentCode === 'balance' && $identity['user_id'] <= 0) {
            Response::error('未登录用户无法使用余额支付');
        }

        // —— 读取商品，用于附加选项/规格校验
        $goodsRow = GoodsModel::getById($goodsId);
        if (!$goodsRow
            || (int) $goodsRow['status'] !== 1
            || (int) $goodsRow['is_on_sale'] !== 1
            || $goodsRow['deleted_at'] !== null
        ) {
            Response::error('商品不存在或已下架');
        }
        // 作用域校验：主站前台只能下主站商品；商户前台只能下本店自建 + 主站引用
        if (!MerchantContext::isGoodsVisibleToCurrentScope($goodsRow)) {
            Response::error('商品不存在或已下架');
        }

        // —— 规格校验：多规格商品必须显式选中一个
        $allSpecs = GoodsModel::getSpecsByGoodsId($goodsId);
        if (count($allSpecs) > 1 && $specId <= 0) {
            Response::error('请选择规格');
        }

        // —— 附加选项必填校验（商品 configs.extra_fields 里 required=1 的项）
        $goodsConfigs = json_decode($goodsRow['configs'] ?? '{}', true) ?: [];
        $extraFields = [];
        foreach (($goodsConfigs['extra_fields'] ?? []) as $ef) {
            $name = (string) ($ef['name'] ?? '');
            if ($name === '') continue;
            $val = trim((string) Input::post('extra_' . $name, ''));
            if (!empty($ef['required']) && $val === '') {
                Response::error('请填写 ' . ($ef['title'] ?? $name));
            }
            $extraFields[$name] = $val;
        }

        // —— 游客查单字段必填校验（仅未登录用户；登录用户 DOM 里本就没这些字段）
        $isGuest = $identity['user_id'] === 0;

        $contactQuery = trim((string) Input::post('guest_find_contact_query', ''));
        $contactType = trim((string) Input::post('guest_find_contact_type', ''));
        $orderPassword = trim((string) Input::post('guest_find_password_query', ''));

        if ($isGuest) {
            if (GuestFindModel::isContactEnabled() && $contactQuery === '') {
                Response::error('请输入' . GuestFindModel::getContactTypeLabel());
            }
            if (GuestFindModel::isPasswordEnabled() && $orderPassword === '') {
                Response::error('请设置订单密码');
            }
        }

        // —— 查找支付方式信息
        $payment = $this->findPaymentMethod($paymentCode);

        try {
            // 商户上下文：在商户店铺下单 → 订单归属该商户
            $merchantId = MerchantContext::currentId();
            $merchantOwnerId = MerchantContext::currentOwnerId();

            $createData = [
                'user_id'             => $identity['user_id'],
                'guest_token'         => $identity['guest_token'],
                'merchant_id'         => $merchantId,
                'owner_id'            => $merchantOwnerId,
                'payment_code'        => $payment['code'] ?? '',
                'payment_name'        => $payment['name'] ?? '',
                'payment_plugin'      => $payment['plugin'] ?? '',
                'payment_plugin_name' => $payment['plugin_name'] ?? '',
                'payment_channel'     => $payment['channel'] ?? '',
                // 收货地址：OrderModel 按 goods_type_register.needs_address 判断是否必填
                //   - 登录用户：address_id 指向地址簿记录
                //   - 游客：guest_address 是下单页手填的 6 字段数组
                'address_id'          => (int) Input::post('address_id', 0),
                'guest_address'       => Input::post('guest_address', null),
            ];

            // 附加选项优先，其次游客联系方式（contact_info 同字段存储）
            if (!empty($extraFields)) {
                $createData['contact_info'] = $extraFields;
            } elseif ($contactQuery !== '') {
                $createData['contact_info'] = $contactQuery;
            }

            // 游客查单密码（明文存储）
            if ($orderPassword !== '') {
                $createData['order_password'] = $orderPassword;
            }

            // —— 优惠券（可选）：前端已通过 coupon/check 校验过折扣；此处再次后端校验后一起入创建事务
            $couponCode = trim((string) Input::post('coupon_code', ''));
            if ($couponCode !== '') {
                $couponService = new CouponService();
                try {
                    // 商品详情页下单金额 = 单商品价 × 数量
                    //   price_raw 是 GoodsModel::getSpecsByGoodsId() 动态计算字段（商户站会乘 factor），
                    //   不能直接 Database::find 查 raw 表（那样只有 price 字段，拿不到带重写的价格）。
                    //   走 GoodsModel 保证和详情页展示价 / OrderModel::create 实扣价三端一致。
                    $priceRaw = 0;
                    foreach (GoodsModel::getSpecsByGoodsId($goodsId) as $_s) {
                        if ((int) $_s['id'] === $specId) {
                            $priceRaw = (int) ($_s['price_raw'] ?? $_s['price']);
                            break;
                        }
                    }
                    $goodsAmountRaw = $priceRaw * $quantity;
                    $goodsType = $goodsRow['goods_type'] ?? '';
                    $categoryId = (int) ($goodsRow['category_id'] ?? 0);

                    $checkRes = $couponService->check($couponCode, [
                        'goods_amount_raw' => $goodsAmountRaw,
                        'goods_items'      => [[
                            'goods_id'    => $goodsId,
                            'category_id' => $categoryId,
                            'goods_type'  => $goodsType,
                        ]],
                        'user_id'          => $identity['user_id'],
                    ]);
                    $createData['coupon'] = $checkRes['coupon'];
                    $createData['coupon_discount'] = $checkRes['discount_raw'];
                } catch (RuntimeException $e) {
                    Response::error($e->getMessage());
                }
            }

            // 返佣归因快照（2 级：直推 / 间推）
            [$in1, $in2] = RebateService::resolveOrderInviters($identity['user_id']);
            if ($in1) $createData['inviter_l1'] = $in1;
            if ($in2) $createData['inviter_l2'] = $in2;

            // 详情页只有 1 个商品，StockShortageException::getMessage() 返回的简短消息即可，
            // 无需拼商品名。其他 RuntimeException 由外层 catch 统一响应。
            $result = OrderModel::create($createData, [
                ['goods_id' => $goodsId, 'spec_id' => $specId, 'quantity' => $quantity],
            ]);

            // 余额支付直接扣款
            $paidNow = false;
            if ($paymentCode === 'balance' && $identity['user_id'] > 0) {
                OrderModel::payWithBalance($result['order_id'], $identity['user_id']);
                $paidNow = true;
            }

            // 非余额支付：触发 payment_create 过滤器，让对应插件生成 pay_url
            $payUrl = '';
            if (!$paidNow) {
                $orderRow = OrderModel::getById((int) $result['order_id']);
                $ctx = applyFilter('payment_create', [
                    'order'   => $orderRow,
                    'payment' => $payment,
                    'pay_url' => '',
                ]);
                $payUrl = (string) ($ctx['pay_url'] ?? '');
            }



            Response::success('下单成功', [
                'order_id'   => $result['order_id'],
                'order_no'   => $result['order_no'],
                'pay_amount' => bcdiv((string) $result['pay_amount'], '1000000', 2),
                'paid'       => $paidNow,
                'pay_url'    => $payUrl,
            ]);

        } catch (RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * AJAX：购物车结算下单。
     *
     * POST 参数：
     *   payment_code
     *   items[cartItemId][fieldName]           每个购物车项的附加选项填写
     *   guest_find_contact_query / _type       游客联系方式查单（仅未登录时）
     *   guest_find_password_query              游客订单密码查单（仅未登录时）
     *
     * 所有校验（支付方式 / 附加选项必填 / 查单凭据）都在后端完成。
     */
    public function checkout(): void
    {
        if (!Request::isPost()) {
            Response::error('无效请求');
        }

        $paymentCode = trim((string) Input::post('payment_code', ''));
        if ($paymentCode === '') {
            Response::error('请选择支付方式');
        }

        $identity = $this->getIdentity();

        // —— 余额支付仅登录用户可用
        if ($paymentCode === 'balance' && $identity['user_id'] <= 0) {
            Response::error('未登录用户无法使用余额支付');
        }

        // 获取购物车商品
        $cartModel = new CartModel();
        $userId = $identity['user_id'];
        $guestToken = $userId > 0 ? '' : $identity['guest_token'];
        $cartItems = $cartModel->getItems($userId, $guestToken);

        // 过滤有效商品 + 仅结算当前店铺归属的项（方案 §9.10 宽松策略：
        // 不属本店的商品留在购物车，不影响本单；若一项都没剩下则报错）
        $validItems = [];
        $skippedOtherShop = 0;
        foreach ($cartItems as $item) {
            if (!$item['is_valid']) continue;
            if (isset($item['belongs_to_current_shop']) && $item['belongs_to_current_shop'] === false) {
                $skippedOtherShop++;
                continue;
            }
            $validItems[] = $item;
        }
        if (empty($validItems)) {
            Response::error($skippedOtherShop > 0
                ? '当前店铺购物车为空；其他店铺的 ' . $skippedOtherShop . ' 件商品需切换到对应店铺后结算'
                : '购物车中没有可购买的商品');
        }

        // —— 校验每项的附加选项必填，并把所有 extras 合并为 "商品名 · 字段" 形式的字典
        $itemsInput = $_POST['items'] ?? [];
        $mergedExtras = [];
        foreach ($validItems as $item) {
            $cartItemId = (int) $item['id'];
            $itemExtra = is_array($itemsInput[$cartItemId] ?? null) ? $itemsInput[$cartItemId] : [];
            $configs = $item['configs'] ?? [];
            foreach (($configs['extra_fields'] ?? []) as $ef) {
                $name = (string) ($ef['name'] ?? '');
                if ($name === '') continue;
                $val = trim((string) ($itemExtra[$name] ?? ''));
                if (!empty($ef['required']) && $val === '') {
                    Response::error('请填写【' . $item['goods_name'] . '】的 ' . ($ef['title'] ?? $name));
                }
                if ($val !== '') {
                    $mergedExtras[$item['goods_name'] . ' · ' . ($ef['title'] ?? $name)] = $val;
                }
            }
        }

        // —— 游客查单字段必填校验
        $isGuest = $identity['user_id'] === 0;
        $contactQuery = trim((string) Input::post('guest_find_contact_query', ''));
        $orderPassword = trim((string) Input::post('guest_find_password_query', ''));
        if ($isGuest) {
            if (GuestFindModel::isContactEnabled() && $contactQuery === '') {
                Response::error('请输入' . GuestFindModel::getContactTypeLabel());
            }
            if (GuestFindModel::isPasswordEnabled() && $orderPassword === '') {
                Response::error('请设置订单密码');
            }
        }

        // —— 组装给 OrderModel 的商品项（简化结构，附加选项已经合并到订单级）
        $goodsItems = [];
        $couponItems = []; // 券校验用的扩展字段
        $totalGoodsAmountRaw = 0;
        foreach ($validItems as $item) {
            $goodsItems[] = [
                'goods_id' => $item['goods_id'],
                'spec_id'  => $item['spec_id'],
                'quantity' => $item['quantity'],
            ];
            $totalGoodsAmountRaw += (int) round(((float) $item['price']) * 1000000) * (int) $item['quantity'];
            // 券范围校验要用到 category_id / goods_type；从 goods 表补齐
            $goodsRow = GoodsModel::getById((int) $item['goods_id']);
            $couponItems[] = [
                'goods_id'    => (int) $item['goods_id'],
                'category_id' => (int) ($goodsRow['category_id'] ?? 0),
                'goods_type'  => (string) ($goodsRow['goods_type'] ?? ''),
            ];
        }

        // —— 优惠券（可选）
        $couponCode = trim((string) Input::post('coupon_code', ''));
        $couponForCreate = null;
        $couponDiscountRaw = 0;
        if ($couponCode !== '') {
            $couponService = new CouponService();
            try {
                $checkRes = $couponService->check($couponCode, [
                    'goods_amount_raw' => $totalGoodsAmountRaw,
                    'goods_items'      => $couponItems,
                    'user_id'          => $identity['user_id'],
                ]);
                $couponForCreate = $checkRes['coupon'];
                $couponDiscountRaw = (int) $checkRes['discount_raw'];
            } catch (RuntimeException $e) {
                Response::error($e->getMessage());
            }
        }

        // 查找支付方式信息
        $payment = $this->findPaymentMethod($paymentCode);

        try {
            // 商户上下文：购物车结算同样要归属当前商户店铺
            $merchantId = MerchantContext::currentId();
            $merchantOwnerId = MerchantContext::currentOwnerId();

            $createData = [
                'user_id'             => $identity['user_id'],
                'guest_token'         => $identity['guest_token'],
                'merchant_id'         => $merchantId,
                'owner_id'            => $merchantOwnerId,
                'payment_code'        => $payment['code'] ?? '',
                'payment_name'        => $payment['name'] ?? '',
                'payment_plugin'      => $payment['plugin'] ?? '',
                'payment_plugin_name' => $payment['plugin_name'] ?? '',
                'payment_channel'     => $payment['channel'] ?? '',
                // 收货地址：OrderModel 按 goods_type_register.needs_address 判断是否必填
                //   - 登录用户：address_id 指向地址簿记录
                //   - 游客：guest_address 是购物车页面手填的 6 字段数组
                'address_id'          => (int) Input::post('address_id', 0),
                'guest_address'       => Input::post('guest_address', null),
            ];

            // 附加选项优先（多商品合并字典），其次游客联系方式
            if (!empty($mergedExtras)) {
                $createData['contact_info'] = $mergedExtras;
            } elseif ($contactQuery !== '') {
                $createData['contact_info'] = $contactQuery;
            }

            if ($orderPassword !== '') {
                $createData['order_password'] = $orderPassword;
            }

            if ($couponForCreate !== null) {
                $createData['coupon'] = $couponForCreate;
                $createData['coupon_discount'] = $couponDiscountRaw;
            }

            // 返佣归因快照（登录用户取 user 祖链，游客走 Cookie；2 级）
            [$in1, $in2] = RebateService::resolveOrderInviters($identity['user_id']);
            if ($in1) $createData['inviter_l1'] = $in1;
            if ($in2) $createData['inviter_l2'] = $in2;

            $result = OrderModel::create($createData, $goodsItems);

            // 下单成功，清空购物车
            $cartModel->clearCart($userId, $guestToken);

            // 余额支付直接扣款
            $paidNow = false;
            if ($paymentCode === 'balance' && $identity['user_id'] > 0) {
                OrderModel::payWithBalance($result['order_id'], $identity['user_id']);
                $paidNow = true;
            }

            // 非余额支付：触发 payment_create 过滤器，让对应插件生成 pay_url
            $payUrl = '';
            if (!$paidNow) {
                $orderRow = OrderModel::getById((int) $result['order_id']);
                $ctx = applyFilter('payment_create', [
                    'order'   => $orderRow,
                    'payment' => $payment,
                    'pay_url' => '',
                ]);
                $payUrl = (string) ($ctx['pay_url'] ?? '');
            }

            Response::success('下单成功', [
                'order_id'   => $result['order_id'],
                'order_no'   => $result['order_no'],
                'pay_amount' => bcdiv((string) $result['pay_amount'], '1000000', 2),
                'paid'       => $paidNow,
                'pay_url'    => $payUrl,
            ]);

        } catch (StockShortageException $e) {
            // 购物车多商品场景：错误消息带上商品名，方便用户定位
            Response::error($e->getFullMessage());
        } catch (RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 订单结果页（默认页面）。
     */
    public function _index(): void
    {
        $orderNo = (string) Input::get('order_no', '');

        $order = null;
        $orderGoods = [];

        if ($orderNo !== '') {
            $order = OrderModel::getByOrderNo($orderNo);

            // 权限校验：只能查看自己的订单
            if ($order) {
                $identity = $this->getIdentity();
                $isOwner = false;

                if ($identity['user_id'] > 0 && (int) $order['user_id'] === $identity['user_id']) {
                    $isOwner = true;
                } elseif ($identity['user_id'] === 0 && $order['guest_token'] === $identity['guest_token']) {
                    $isOwner = true;
                }

                if (!$isOwner) {
                    $order = null;
                } else {
                    $orderGoods = OrderModel::getOrderGoods((int) $order['id']);
                }
            }
        }

        $this->view->setTitle($order ? '订单详情' : '订单不存在');
        $this->view->setData([
            'order'       => $order,
            'order_goods' => $orderGoods,
        ]);
        $this->view->render('order_result');
    }

    /**
     * 查找支付方式。
     */
    private function findPaymentMethod(string $code): array
    {
        if ($code === '') {
            return [];
        }

        $methods = PaymentService::getMethods();
        foreach ($methods as $m) {
            if ($m['code'] === $code) {
                return $m;
            }
        }

        return [];
    }
}

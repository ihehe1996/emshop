<?php
defined('EM_ROOT') || exit('access denied!');

// 游客查单配置：用于在购物车底部为未登录用户渲染查单凭据输入
$gfCfg = $guest_find_config ?? [];
$gfContactOn  = !empty($gfCfg['contact_enabled']);
$gfPasswordOn = !empty($gfCfg['password_enabled']);
$needGuestFind = !empty($is_guest) && ($gfContactOn || $gfPasswordOn);
?>
<!-- 购物车 · CartController::_index() -->
<div class="page-body">

    <div class="page-title">购物车</div>

    <?php if (!empty($cart_items)): ?>
    <!-- 整页包一个 form 便于 $.serialize()；items[..]/guest_find_*/payment_code 都在里面 -->
    <form id="cartForm" class="cart-layout" onsubmit="return false;">

        <!-- 左侧：商品列表 -->
        <div class="cart-list" id="cartList">

            <?php if (!empty($needs_address)): ?>
            <!-- 省市区联动库：游客手填表单用；登录用户看列表时可省略但加载也无副作用 -->
            <link rel="stylesheet" href="/content/static/lib/cityAreaSelect/dist/css/cityAreaSelect.css">
            <script src="/content/static/lib/cityAreaSelect/dist/js/cityAreaSelect.min.js"></script>

            <!-- 收货地址（含 needs_address 商品时显示）—— 放左侧顶部避免右侧摘要卡太高 -->
            <div class="cart-address">
                <div class="cart-address-title">
                    <i class="fa fa-map-marker"></i> 收货地址 <span class="cart-address-required">*</span>
                </div>

                <?php if (!empty($is_guest)): ?>
                    <!-- 游客：下单页手填（不入地址簿，只进订单快照） -->
                    <div class="cart-address-form">
                        <div class="cart-address-form-row">
                            <div class="cart-address-form-field">
                                <label>收件人</label>
                                <input type="text" name="guest_address[recipient]" maxlength="50" placeholder="姓名" class="cart-address-input">
                            </div>
                            <div class="cart-address-form-field">
                                <label>手机号</label>
                                <input type="text" name="guest_address[mobile]" maxlength="11" placeholder="11 位手机号" class="cart-address-input">
                            </div>
                        </div>
                        <div class="cart-address-form-field">
                            <label>所在地区</label>
                            <div class="cart-address-cascade">
                                <select class="cityAreaSelect-select cart-address-select" id="cartAddrProv" name="guest_address[province]"><option value="">请选择省/直辖市</option></select>
                                <select class="cityAreaSelect-select cart-address-select" id="cartAddrCity" name="guest_address[city]"><option value="">请选择城市/区</option></select>
                                <select class="cityAreaSelect-select cart-address-select" id="cartAddrArea" name="guest_address[district]"><option value="">请选择区/县</option></select>
                            </div>
                        </div>
                        <div class="cart-address-form-field">
                            <label>详细地址</label>
                            <textarea name="guest_address[detail]" maxlength="255" rows="2" placeholder="街道、门牌号、楼栋等" class="cart-address-textarea"></textarea>
                        </div>
                        <div class="cart-address-form-tip">
                            <i class="fa fa-info-circle"></i> 若您已有账号，可 <a href="?c=login" data-pjax>登录</a> 使用已保存的收货地址。
                        </div>
                    </div>
                    <script>
                    // 每次 PJAX 进购物车都重新实例化（避免 DOM 被替换后旧引用失效）
                    (function () {
                        if (typeof ProvinceCityAreaSelect === 'undefined') return;
                        // new 之前清一下 change 绑定（PJAX 重复渲染保险）
                        new ProvinceCityAreaSelect({
                            addrValElem: ['cartAddrProv', 'cartAddrCity', 'cartAddrArea']
                        });
                    })();
                    </script>
                <?php elseif (empty($user_addresses)): ?>
                    <div class="cart-address-guest-tip">
                        <i class="fa fa-info-circle"></i> 您还没有收货地址，请先 <a href="/user/address.php" data-pjax>添加收货地址</a>。
                    </div>
                <?php else: ?>
                    <div class="cart-address-list">
                        <?php foreach ($user_addresses as $addr):
                            $isChecked = (int) $addr['id'] === (int) ($default_address_id ?? 0);
                        ?>
                        <label class="cart-address-item<?= $isChecked ? ' is-active' : '' ?>">
                            <input type="radio" name="address_id" value="<?= (int) $addr['id'] ?>" <?= $isChecked ? 'checked' : '' ?>>
                            <div class="cart-address-item-body">
                                <div class="cart-address-item-head">
                                    <strong><?= htmlspecialchars($addr['recipient']) ?></strong>
                                    <span class="cart-address-item-mobile"><?= htmlspecialchars($addr['mobile']) ?></span>
                                    <?php if ((int) $addr['is_default'] === 1): ?>
                                        <span class="cart-address-item-default">默认</span>
                                    <?php endif; ?>
                                </div>
                                <div class="cart-address-item-text">
                                    <?= htmlspecialchars(($addr['province'] ?? '') . ' ' . ($addr['city'] ?? '') . ' ' . ($addr['district'] ?? '')) ?>
                                    <?= htmlspecialchars($addr['detail'] ?? '') ?>
                                </div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <a href="/user/address.php" data-pjax class="cart-address-manage">
                        <i class="fa fa-cog"></i> 管理我的收货地址
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php foreach ($cart_items as $item): ?>
            <?php
            $cartId     = (int) $item['id'];
            $extraList  = is_array($item['configs']['extra_fields'] ?? null) ? $item['configs']['extra_fields'] : [];
            ?>
            <?php
            $belongsToCurrentShop = !isset($item['belongs_to_current_shop']) || $item['belongs_to_current_shop'];
            $shopBadge = $item['shop_badge'] ?? '';
            $classes = ['cart-item'];
            if (!$item['is_valid']) $classes[] = 'cart-item--invalid';
            if (!$belongsToCurrentShop) $classes[] = 'cart-item--other-shop';
            ?>
            <div class="<?= implode(' ', $classes) ?>"
                 data-cart-id="<?= $cartId ?>"
                 data-goods-id="<?= $item['goods_id'] ?>"
                 data-spec-id="<?= $item['spec_id'] ?>"
                 data-price="<?= $item['price'] ?>"
                 data-current-shop="<?= $belongsToCurrentShop ? '1' : '0' ?>">
                <div class="cart-item-main">
                    <div class="cart-item-img">
                        <?php if (!empty($item['image'])): ?>
                        <img src="<?= htmlspecialchars($item['image']) ?>" alt="">
                        <?php else: ?>
                        <span class="cart-item-img-placeholder"><i class="fa fa-picture-o"></i></span>
                        <?php endif; ?>
                    </div>
                    <div class="cart-item-info">
                        <a href="<?= url_goods((int) $item['goods_id']) ?>" data-pjax class="cart-item-name"><?= htmlspecialchars($item['goods_name']) ?></a>
                        <?php if (!empty($item['spec_name'])): ?>
                        <div class="cart-item-spec">
                            <span class="cart-item-spec-text"><?= htmlspecialchars($item['spec_name']) ?></span>
                            <?php if ($item['is_valid']): ?>
                            <button type="button" class="cart-spec-change-btn" data-id="<?= $cartId ?>" data-goods-id="<?= $item['goods_id'] ?>" data-spec-id="<?= $item['spec_id'] ?>"><i class="fa fa-pencil"></i></button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!$item['is_valid']): ?>
                        <div class="cart-item-invalid-tag">商品已失效</div>
                        <?php endif; ?>
                        <?php if (!$belongsToCurrentShop && $shopBadge !== ''): ?>
                        <div class="cart-item-other-shop-tag">
                            <i class="fa fa-info-circle"></i> <?= htmlspecialchars($shopBadge) ?>，本店结算时自动跳过
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="cart-item-price-col">
                        <!-- unit-price-val 跟着访客币种走；data-price 保持主货币数值供后端 AJAX 用 -->
                        <span class="cart-item-unit-price"><?= $currency_symbol ?><span class="unit-price-val"><?= number_format((float) $item['price'] * $currency_rate, 2) ?></span></span>
                    </div>
                    <?php if ($item['is_valid']): ?>
                    <div class="cart-item-qty">
                        <button type="button" class="cart-qty-btn cart-qty-minus" data-id="<?= $cartId ?>">−</button>
                        <input type="number" class="cart-qty-input" data-id="<?= $cartId ?>"
                               value="<?= $item['quantity'] ?>"
                               min="<?= max(1, $item['min_buy']) ?>"
                               <?= $item['max_buy'] > 0 ? 'max="' . $item['max_buy'] . '"' : '' ?>>
                        <button type="button" class="cart-qty-btn cart-qty-plus" data-id="<?= $cartId ?>">+</button>
                    </div>
                    <?php else: ?>
                    <div class="cart-item-qty">
                        <span style="color:#ccc;">×<?= $item['quantity'] ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="cart-item-subtotal">
                        <?= $currency_symbol ?><span class="subtotal-value"><?= number_format((float) $item['subtotal'] * $currency_rate, 2) ?></span>
                    </div>
                    <button type="button" class="cart-item-del" data-id="<?= $cartId ?>" title="移除"><i class="fa fa-trash-o"></i></button>
                </div>

                <?php if (!empty($extraList) && $item['is_valid']): ?>
                <!-- 该商品的附加选项（商品 configs.extra_fields）—— 每个购物车项独立表单项 -->
                <div class="cart-item-extras">
                    <div class="cart-item-extras-title"><i class="fa fa-list-ul"></i> 附加选项</div>
                    <?php foreach ($extraList as $ef): ?>
                    <?php
                    $efName        = (string) ($ef['name'] ?? '');
                    if ($efName === '') continue;
                    $efFormat      = (string) ($ef['format'] ?? 'text');
                    $efType        = 'text';
                    $efMaxLen      = 64;
                    if ($efFormat === 'email') { $efType = 'email'; }
                    elseif ($efFormat === 'phone') { $efType = 'tel'; $efMaxLen = 20; }
                    elseif ($efFormat === 'number') { $efType = 'number'; $efMaxLen = 20; }
                    $efRequired    = !empty($ef['required']);
                    $efLabel       = (string) ($ef['title'] ?? $efName);
                    $efPlaceholder = (string) ($ef['placeholder'] ?? '');
                    ?>
                    <div class="cart-item-extra-field">
                        <label class="cart-item-extra-label">
                            <?= htmlspecialchars($efLabel) ?>
                            <?php if ($efRequired): ?><span class="cart-item-extra-required">*</span><?php endif; ?>
                        </label>
                        <input type="<?= $efType ?>"
                               name="items[<?= $cartId ?>][<?= htmlspecialchars($efName) ?>]"
                               class="cart-item-extra-input"
                               placeholder="<?= htmlspecialchars($efPlaceholder) ?>"
                               maxlength="<?= $efMaxLen ?>"
                               <?= $efRequired ? 'required' : '' ?>>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div class="cart-bottom-bar">
                <button type="button" class="cart-clear-btn" id="cartClearBtn"><i class="fa fa-trash"></i> 清空购物车</button>
                <span class="cart-count-text">共 <strong id="cartItemCount"><?= (int) $total_count ?></strong> 件商品</span>
            </div>
        </div>

        <!-- 右侧：订单摘要 -->
        <div class="cart-summary">
            <h3>订单摘要</h3>
            <div class="cart-summary-row">
                <span>商品件数</span>
                <span><span id="summaryCount"><?= (int) $total_count ?></span> 件</span>
            </div>
            <div class="cart-summary-row">
                <span>商品总额</span>
                <span><?= $currency_symbol ?><span id="summaryPrice"><?= number_format((float) $total_price * $currency_rate, 2) ?></span></span>
            </div>
            <hr class="cart-summary-divider">
            <div class="cart-summary-total">
                <span>应付总额</span>
                <span class="total-price"><?= $currency_symbol ?><span id="summaryTotal"><?= number_format((float) $total_price * $currency_rate, 2) ?></span></span>
            </div>

            <!-- 优惠券：输入码 + 选择弹窗 -->
            <div class="cart-coupon">
                <div class="cart-coupon-title"><i class="fa fa-ticket"></i> 优惠券</div>
                <div class="cart-coupon-input-row">
                    <input type="text" id="cartCouponInput" name="coupon_code"
                           placeholder="输入优惠券码" maxlength="32" class="cart-coupon-input">
                    <button type="button" class="cart-coupon-btn cart-coupon-btn--primary" id="cartCouponApplyBtn">使用</button>
                </div>
                <?php if (!empty($is_guest)): ?>
                <div class="cart-coupon-guest-tip">
                    <i class="fa fa-info-circle"></i> 登录后可<a href="<?= url_coupon() ?>" data-pjax>领取并选择优惠券</a>
                </div>
                <?php else: ?>
                <button type="button" class="cart-coupon-choose" id="cartCouponChooseBtn">
                    <i class="fa fa-folder-open-o"></i> 从已领券中选择
                </button>
                <?php endif; ?>
                <div class="cart-coupon-applied" id="cartCouponApplied" style="display:none;">
                    <span class="cart-coupon-applied-name"></span>
                    <span class="cart-coupon-applied-saved"></span>
                </div>
            </div>

            <?php if ($needGuestFind): ?>
            <!-- 游客查单凭据（未登录 + 后台开启时才出现） -->
            <div class="cart-guest-find">
                <div class="cart-guest-find-title"><i class="fa fa-search"></i> 查单凭据（便于您后续查询订单）</div>

                <?php if ($gfContactOn): ?>
                <div class="cart-guest-find-section" id="guestFindContactSection">
                    <label class="cart-guest-find-label"><?= htmlspecialchars($gfCfg['contact_type_label']) ?></label>
                    <input type="<?= $gfCfg['contact_input_type'] ?>"
                           id="guestFindContactQuery" name="guest_find_contact_query"
                           class="cart-guest-find-input"
                           placeholder="<?= htmlspecialchars($gfCfg['contact_checkout_placeholder']) ?>" maxlength="32">
                    <input type="hidden" id="guestFindContactType" name="guest_find_contact_type"
                           value="<?= htmlspecialchars($gfCfg['contact_type']) ?>">
                </div>
                <?php endif; ?>

                <?php if ($gfPasswordOn): ?>
                <div class="cart-guest-find-section" id="guestFindPasswordSection">
                    <label class="cart-guest-find-label">订单密码</label>
                    <input type="text"
                           id="guestFindPasswordQuery" name="guest_find_password_query"
                           class="cart-guest-find-input"
                           placeholder="<?= htmlspecialchars($gfCfg['password_checkout_placeholder']) ?>" maxlength="32">
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- 支付方式（控制器预标记 disabled/selected） -->
            <?php if (!empty($payment_methods)): ?>
            <div class="cart-payment-wrap">
                <div class="cart-payment-label">支付方式</div>
                <div class="cart-payment-list">
                    <?php foreach ($payment_methods as $pm): ?>
                    <?php
                    $cls = 'cart-payment-item';
                    if (!empty($pm['selected'])) $cls .= ' active';
                    if (!empty($pm['disabled'])) $cls .= ' is-disabled';
                    ?>
                    <button type="button" class="<?= $cls ?>" data-code="<?= htmlspecialchars($pm['code']) ?>"
                            <?= !empty($pm['disabled']) ? 'disabled title="未登录用户无法使用余额支付"' : '' ?>>
                        <img src="<?= htmlspecialchars($pm['image']) ?>" alt="">
                        <span><?= htmlspecialchars($pm['display_name'] ?? $pm['name']) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <button type="button" class="btn btn-primary btn-block cart-checkout-btn" id="checkoutBtn">去结算</button>
            <a href="<?= url_home() ?>" data-pjax class="cart-continue-link">继续购物</a>
        </div>

    </form>

    <!-- 购物车 JS -->
    <script>
    (function () {
        $(document).off('.emCart');
        // 货币三要素：显示 × 符号 + 数值 × rate；送后端 AJAX 仍用主货币数值（data-price 原值）
        var CUR = (window.EMSHOP_CURRENCY || { symbol: '¥', rate: 1 });
        var IS_GUEST = <?= !empty($is_guest) ? 'true' : 'false' ?>;
        var currencySymbol = CUR.symbol;
        function fmtMoney(mainPrice) { return (mainPrice * CUR.rate).toFixed(2); }

        // 重新计算页面上的汇总数据
        function recalcSummary() {
            var totalPrice = 0, totalQty = 0;
            $('.cart-item:not(.cart-item--invalid)').each(function () {
                var price = parseFloat($(this).data('price')) || 0;
                var qty = parseInt($(this).find('.cart-qty-input').val()) || 0;
                totalPrice += price * qty;
                totalQty += qty;
            });
            // .text() 里是给人看的，要换成访客币种数值；totalPrice 变量本身保留主货币（后面算折扣、发后端都用它）
            $('#summaryPrice, #summaryTotal').text(fmtMoney(totalPrice));
            $('#summaryCount, #cartItemCount').text(totalQty);
        }

        // 更新单行小计
        function updateRowSubtotal($item) {
            var price = parseFloat($item.data('price')) || 0;
            var qty = parseInt($item.find('.cart-qty-input').val()) || 0;
            $item.find('.subtotal-value').text(fmtMoney(price * qty));
        }

        // 更新数量（AJAX） —— 请求期间加 loading 态：禁用按钮 + 遮罩 spinner
        function updateQty(cartId, qty, $item) {
            var $qtyBox = $item.find('.cart-item-qty');
            if ($qtyBox.data('loading')) return; // 防重复点击
            $qtyBox.data('loading', true).addClass('is-loading');
            $qtyBox.find('button, input').prop('disabled', true);

            function done() {
                $qtyBox.removeClass('is-loading').data('loading', false);
                $qtyBox.find('button, input').prop('disabled', false);
            }

            $.post('?c=cart&a=update', { id: cartId, quantity: qty }, function (res) {
                done();
                if (res.code === 200) {
                    $item.find('.cart-qty-input').val(qty);
                    updateRowSubtotal($item);
                    recalcSummary();
                    updateCartBadge(res.data.cart_count);
                } else {
                    layui.layer.msg(res.msg || '操作失败');
                }
            }, 'json').fail(function () {
                done();
                layui.layer.msg('网络异常');
            });
        }

        $(document).on('click.emCart', '.cart-qty-minus', function () {
            var $item = $(this).closest('.cart-item');
            var $input = $item.find('.cart-qty-input');
            var min = parseInt($input.attr('min')) || 1;
            var val = parseInt($input.val()) || min;
            if (val > min) updateQty(parseInt($(this).data('id')), val - 1, $item);
        });

        $(document).on('click.emCart', '.cart-qty-plus', function () {
            var $item = $(this).closest('.cart-item');
            var $input = $item.find('.cart-qty-input');
            var max = parseInt($input.attr('max')) || 0;
            var val = parseInt($input.val()) || 1;
            if (max > 0 && val >= max) return;
            updateQty(parseInt($(this).data('id')), val + 1, $item);
        });

        $(document).on('change.emCart', '.cart-qty-input', function () {
            var $input = $(this);
            var $item = $input.closest('.cart-item');
            var min = parseInt($input.attr('min')) || 1;
            var max = parseInt($input.attr('max')) || 0;
            var val = parseInt($input.val()) || min;
            if (val < min) val = min;
            if (max > 0 && val > max) val = max;
            updateQty(parseInt($input.data('id')), val, $item);
        });

        // 移除
        $(document).on('click.emCart', '.cart-item-del', function () {
            var cartId = parseInt($(this).data('id'));
            var $item = $(this).closest('.cart-item');
            layui.layer.confirm('确定移除该商品吗？', function (idx) {
                layui.layer.close(idx);
                $.post('?c=cart&a=remove', { id: cartId }, function (res) {
                    if (res.code === 200) {
                        updateCartBadge(res.data.cart_count);
                        $item.slideUp(200, function () {
                            $item.remove();
                            if ($('.cart-item').length === 0) {
                                $.pjax({ url: window.EMSHOP_URLS.cart, container: '#main', fragment: '#main', timeout: 10000 });
                            } else {
                                recalcSummary();
                            }
                        });
                    } else {
                        layui.layer.msg(res.msg || '操作失败');
                    }
                }, 'json');
            });
        });

        // 清空购物车
        $(document).on('click.emCart', '#cartClearBtn', function () {
            layui.layer.confirm('确定清空购物车吗？', function (idx) {
                layui.layer.close(idx);
                $.post('?c=cart&a=clear', {}, function (res) {
                    if (res.code === 200) {
                        updateCartBadge(0);
                        $.pjax({ url: window.EMSHOP_URLS.cart, container: '#main', fragment: '#main', timeout: 10000 });
                    } else {
                        layui.layer.msg(res.msg || '操作失败');
                    }
                }, 'json');
            });
        });

        // 修改规格（弹窗）
        $(document).on('click.emCart', '.cart-spec-change-btn', function () {
            var $btn = $(this);
            var cartId = parseInt($btn.data('id'));
            var goodsId = parseInt($btn.data('goods-id'));
            var currentSpecId = parseInt($btn.data('spec-id'));

            $.get('?c=cart&a=specs&goods_id=' + goodsId, function (res) {
                if (res.code !== 200 || !res.data || !res.data.specs) {
                    layui.layer.msg('获取规格失败');
                    return;
                }
                var specList = res.data.specs;
                if (specList.length <= 1) {
                    layui.layer.msg('该商品只有一个规格');
                    return;
                }

                var html = '<div style="padding:20px;">';
                html += '<div style="font-size:14px;color:#666;margin-bottom:12px;">选择规格：</div>';
                html += '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
                for (var i = 0; i < specList.length; i++) {
                    var s = specList[i];
                    var isActive = s.id === currentSpecId;
                    var isDisabled = s.stock <= 0;
                    var cls = 'spec-btn';
                    if (isActive) cls += ' active';
                    if (isDisabled) cls += ' disabled';
                    html += '<button type="button" class="' + cls + '" data-spec-id="' + s.id + '"'
                         + (isDisabled ? ' disabled' : '')
                         + ' style="padding:6px 16px;border:1px solid ' + (isActive ? '#4e6ef2' : '#dcdfe6')
                         + ';border-radius:6px;background:' + (isActive ? '#eef1fe' : (isDisabled ? '#fafafa' : '#fff'))
                         + ';color:' + (isActive ? '#4e6ef2' : (isDisabled ? '#ccc' : '#333'))
                         + ';cursor:' + (isDisabled ? 'not-allowed' : 'pointer')
                         + ';font-size:13px;">'
                         + s.name + ' (' + currencySymbol + parseFloat(s.price).toFixed(2) + ')'
                         + '</button>';
                }
                html += '</div></div>';

                var specLayer = layui.layer.open({
                    type: 1,
                    title: '修改规格',
                    area: ['400px', 'auto'],
                    content: html,
                    success: function (layero) {
                        layero.on('click', '.spec-btn:not(.disabled):not(.active)', function () {
                            var newSpecId = parseInt($(this).data('spec-id'));
                            layui.layer.close(specLayer);

                            $.post('?c=cart&a=change_spec', { id: cartId, spec_id: newSpecId }, function (cres) {
                                if (cres.code === 200) {
                                    $.pjax({ url: window.EMSHOP_URLS.cart, container: '#main', fragment: '#main', timeout: 10000 });
                                    updateCartBadge(cres.data.cart_count);
                                    layui.layer.msg('规格已更新');
                                } else {
                                    layui.layer.msg(cres.msg || '修改失败');
                                }
                            }, 'json');
                        });
                    }
                });
            }, 'json');
        });

        // 支付方式选中（禁用态跳过）
        $(document).on('click.emCart', '.cart-payment-item:not(.is-disabled)', function () {
            $(this).addClass('active').siblings().removeClass('active');
        });

        // 收货地址 radio 选中态切换（视觉）
        $(document).on('change.emCart', '.cart-address input[name="address_id"]', function () {
            $(this).closest('.cart-address-list').find('.cart-address-item').removeClass('is-active');
            $(this).closest('.cart-address-item').addClass('is-active');
        });

        // ======== 优惠券 ========
        // 当前应用券的折扣（用于前端重算 summary；最终以后端为准）
        var couponState = { applied: false, discount: 0 };

        function refreshSummaryWithCoupon() {
            var goodsTotal = 0;
            $('.cart-item:not(.cart-item--invalid)').each(function () {
                var price = parseFloat($(this).data('price')) || 0;
                var qty = parseInt($(this).find('.cart-qty-input').val()) || 0;
                goodsTotal += price * qty;
            });
            // goodsTotal / discount 都是主货币数值，显示时乘 rate
            var pay = Math.max(0, goodsTotal - (couponState.discount || 0));
            $('#summaryPrice').text(fmtMoney(goodsTotal));
            $('#summaryTotal').text(fmtMoney(pay));
        }

        // 覆盖原本的 recalcSummary：加入券折扣
        var origRecalc = recalcSummary;
        recalcSummary = function () {
            origRecalc.apply(null, arguments);
            refreshSummaryWithCoupon();
        };

        // 使用按钮
        $(document).on('click.emCart', '#cartCouponApplyBtn', function () {
            var $btn = $(this);
            if ($btn.text() === '更换') {
                // 切回编辑态
                $('#cartCouponInput').prop('readonly', false).val('').focus();
                $btn.text('使用').removeClass('cart-coupon-btn--ghost').addClass('cart-coupon-btn--primary');
                $('#cartCouponApplied').hide();
                couponState = { applied: false, discount: 0 };
                refreshSummaryWithCoupon();
                return;
            }
            var code = ($('#cartCouponInput').val() || '').trim();
            if (!code) { layui.layer.msg('请输入优惠券码'); return; }
            applyCoupon(code);
        });

        // 从已领券中选择（弹窗）
        $(document).on('click.emCart', '#cartCouponChooseBtn', function () {
            $.get('?c=coupon&a=mine', function (res) {
                if (res.code !== 200) { layui.layer.msg(res.msg || '获取失败'); return; }
                var list = (res.data && res.data.coupons) || [];
                if (!list.length) { layui.layer.msg('您暂无可用优惠券'); return; }

                var html = '<div class="cart-coupon-picker">';
                // 券面额 / 门槛按访客币种展示（c.value 和 c.min_amount 是主货币元值）
                list.forEach(function (c) {
                    var valTxt = c.type === 'fixed_amount' ? (CUR.symbol + (parseFloat(c.value) * CUR.rate).toFixed(2))
                               : c.type === 'percent'     ? (c.value/10).toFixed(1)+'折'
                               : '免邮';
                    var minTxt = parseFloat(c.min_amount) > 0
                        ? '满 ' + CUR.symbol + (parseFloat(c.min_amount) * CUR.rate).toFixed(2) + ' 可用'
                        : '无门槛';
                    html += '<div class="cart-coupon-pick-item" data-code="'+c.code+'">';
                    html += '<div class="cart-coupon-pick-value">'+valTxt+'</div>';
                    html += '<div class="cart-coupon-pick-main">';
                    html += '<div class="cart-coupon-pick-title">'+(c.title||c.name)+'</div>';
                    html += '<div class="cart-coupon-pick-meta">' + minTxt
                          + (c.end_at? ' · 至 ' + String(c.end_at).substring(0,16) : '')
                          + '</div>';
                    html += '</div>';
                    html += '<button type="button" class="cart-coupon-pick-btn">使用</button>';
                    html += '</div>';
                });
                html += '</div>';

                var lay = layui.layer.open({
                    type: 1, title: '选择优惠券',
                    skin: 'admin-modal',
                    area: [window.innerWidth >= 600 ? '520px':'92%', window.innerHeight >= 700 ? '520px':'80%'],
                    content: html,
                    success: function (layero) {
                        layero.on('click', '.cart-coupon-pick-btn', function () {
                            var code = $(this).closest('.cart-coupon-pick-item').data('code');
                            layui.layer.close(lay);
                            $('#cartCouponInput').val(code);
                            applyCoupon(code);
                        });
                    }
                });
            }, 'json');
        });

        function applyCoupon(code) {
            var goodsAmount = 0;
            $('.cart-item:not(.cart-item--invalid)').each(function () {
                var price = parseFloat($(this).data('price')) || 0;
                var qty = parseInt($(this).find('.cart-qty-input').val()) || 0;
                goodsAmount += price * qty;
            });
            if (goodsAmount <= 0) { layui.layer.msg('购物车为空'); return; }

            var items = [];
            $('.cart-item:not(.cart-item--invalid)').each(function () {
                items.push({ goods_id: parseInt($(this).data('goods-id')) });
            });

            $.post('?c=coupon&a=check', {
                code: code,
                goods_amount: goodsAmount.toFixed(2),
                goods_items: JSON.stringify(items)
            }, function (res) {
                if (res.code !== 200) {
                    layui.layer.msg(res.msg || '优惠券不可用');
                    return;
                }
                var discount = parseFloat(res.data.discount) || 0;
                couponState = { applied: true, discount: discount };

                // 锁定输入 + 按钮变更换
                $('#cartCouponInput').prop('readonly', true);
                $('#cartCouponApplyBtn').text('更换')
                    .removeClass('cart-coupon-btn--primary').addClass('cart-coupon-btn--ghost');
                $('#cartCouponApplied')
                    .find('.cart-coupon-applied-name').text(res.data.coupon.title).end()
                    // "已优惠"金额也要按访客币种展示（discount 是主货币值）
                    .find('.cart-coupon-applied-saved').text('已优惠 ' + CUR.symbol + fmtMoney(discount)).end()
                    .show();

                refreshSummaryWithCoupon();
                layui.layer.msg('优惠券已应用');
            }, 'json').fail(function () {
                layui.layer.msg('网络异常');
            });
        }

        // 去结算 —— 前端只收集数据，所有校验由后端统一处理
        $(document).on('click.emCart', '#checkoutBtn', function () {
            var $btn = $(this);
            var $activePay = $('.cart-payment-item.active');
            var paymentCode = $activePay.length ? $activePay.data('code') : '';

            // 必填校验统一由后端（OrderModel::create）处理，前端不重复拦一遍

            $btn.prop('disabled', true).text('提交中...');

            // 整表单 serialize（包含 items[..] 附加选项 + 查单字段），再拼上 payment_code
            var payload = $('#cartForm').serialize();
            if (paymentCode) payload += '&payment_code=' + encodeURIComponent(paymentCode);

            $.post('?c=order&a=checkout', payload, function (res) {
                $btn.prop('disabled', false).text('去结算');
                if (res.code === 200) {
                    updateCartBadge(0);
                    var orderDetailUrl = '/user/order_detail.php?order_no=' + encodeURIComponent(res.data.order_no || '');
                    var guestFindUrl = '/user/find_order.php';
                    // 余额已付 → 提示并去详情；非余额插件返回 pay_url → 跳支付页
                    if (res.data.paid) {
                        layui.layer.msg('支付成功');
                        location.href = IS_GUEST ? guestFindUrl : orderDetailUrl;
                    } else if (res.data.pay_url) {
                        location.href = res.data.pay_url;
                    } else {
                        location.href = IS_GUEST ? guestFindUrl : orderDetailUrl;
                    }
                } else {
                    layui.layer.msg(res.msg || '下单失败');
                }
            }, 'json').fail(function () {
                $btn.prop('disabled', false).text('去结算');
                layui.layer.msg('网络异常');
            });
        });
    })();
    </script>

    <?php else: ?>
    <!-- 空购物车 -->
    <div class="card empty-state">
        <div class="empty-icon">&#128722;</div>
        <h3>购物车是空的</h3>
        <p>快去挑选心仪的商品吧</p>
        <a href="<?= url_goods_list() ?>" data-pjax class="btn btn-primary">去逛逛</a>
    </div>
    <?php endif; ?>

</div>

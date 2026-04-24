<?php
defined('EM_ROOT') || exit('access denied!');

// ===== 查单页 tabs 动态生成 =====
// tab1：浏览器订单（guest_token 列近 10 条）
// tab2：凭据查单（联系方式/订单密码任一或组合，按后台配置动态显示字段和名称）
// tab3：订单编号查单（订单号 + 凭据）
// 只要 contact 或 password 任一开启，tab2/tab3 才有意义；都不开时只显示 tab1
$contactOn  = !empty($gfConfig['contact_enabled']);
$passwordOn = !empty($gfConfig['password_enabled']);

// tab2 / tab3 名称按实际开启的凭据组合
if ($contactOn && $passwordOn) {
    $credLabel = '联系方式/订单密码查单';
} elseif ($contactOn) {
    $credLabel = '联系方式查单';
} elseif ($passwordOn) {
    $credLabel = '订单密码查单';
} else {
    $credLabel = '';
}
$showCredTab = ($contactOn || $passwordOn);
$showOrderNoTab = ($contactOn || $passwordOn);
?>
<!-- 游客查询订单视图（由 user/find_order.php 直接包含；事件交给 guest_find.js） -->
<div class="find-order-layout">

    <!-- 查询方式 tab -->
    <div class="find-order-tabs" id="findOrderTabs">
        <button type="button" class="find-order-tab active" data-mode="token" id="tabToken">
            <i class="fa fa-user"></i> 浏览器订单
        </button>
        <?php if ($showCredTab): ?>
        <button type="button" class="find-order-tab" data-mode="credentials" id="tabCredentials">
            <i class="fa fa-id-card"></i> <?= htmlspecialchars($credLabel) ?>
        </button>
        <?php endif; ?>
        <?php if ($showOrderNoTab): ?>
        <button type="button" class="find-order-tab" data-mode="orderno" id="tabOrderNo">
            <i class="fa fa-file-text-o"></i> 订单编号查单
        </button>
        <?php endif; ?>
    </div>

    <!-- 面板 1：浏览器订单 -->
    <div class="find-order-panel" id="panelToken">
        <div class="find-order-hint">
            <i class="fa fa-info-circle"></i> 正在从浏览器获取订单记录...
        </div>
        <div class="find-order-loading" id="tokenLoading">
            <div class="find-order-spinner"></div>
            正在加载订单...
        </div>
    </div>

    <?php if ($showCredTab): ?>
    <!-- 面板 2：凭据查单（不需要订单号，按凭据列出用户所有订单） -->
    <div class="find-order-panel" id="panelCredentials" style="display:none;">
        <div class="find-order-form">
            <?php if ($contactOn): ?>
            <div class="find-order-form-group">
                <label><?= htmlspecialchars($gfConfig['contact_type_label']) ?></label>
                <input type="<?= $gfConfig['contact_input_type'] ?>" class="find-order-input" id="credContactQuery"
                       placeholder="<?= htmlspecialchars($gfConfig['contact_lookup_placeholder']) ?>" maxlength="32">
            </div>
            <?php endif; ?>
            <?php if ($passwordOn): ?>
            <div class="find-order-form-group">
                <label>订单密码</label>
                <input type="text" class="find-order-input" id="credPasswordQuery"
                       placeholder="<?= htmlspecialchars($gfConfig['password_lookup_placeholder']) ?>" maxlength="32">
            </div>
            <?php endif; ?>
            <button type="button" class="find-order-submit" id="credentialsSubmitBtn">
                <i class="fa fa-search"></i> 查询订单
            </button>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($showOrderNoTab): ?>
    <!-- 面板 3：仅订单编号查单（无需凭据） -->
    <div class="find-order-panel" id="panelOrderNo" style="display:none;">
        <div class="find-order-form">
            <div class="find-order-form-group">
                <label>订单编号</label>
                <input type="text" class="find-order-input" id="orderNoInput"
                       placeholder="请输入订单编号" maxlength="32">
            </div>
            <button type="button" class="find-order-submit" id="orderNoSubmitBtn">
                <i class="fa fa-search"></i> 查询订单
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- 查询结果容器（GuestFind.renderResults 写入） -->
    <div class="find-order-results" id="findOrderResults" style="display:none;">
        <div class="find-order-results-header">
            <i class="fa fa-check-circle" style="color:#52c41a;"></i>
            查询到 <strong id="resultsCount">0</strong> 条订单
        </div>
        <div id="resultsList"></div>
    </div>

</div>

<script>
(function () {
    var guestToken = <?= json_encode($guestToken ?: '') ?>;
    var currencySymbol = <?= json_encode($currencySymbol) ?>;
    var showCredTab = <?= $showCredTab ? 'true' : 'false' ?>;
    var showOrderNoTab = <?= $showOrderNoTab ? 'true' : 'false' ?>;

    // 缓存首次加载的浏览器订单结果，切回本 tab 时直接重显，避免数据"消失"
    var tokenResultsCache = null;

    // 初始化组件（tab 切换 + 两个查单按钮事件由 guest_find.js 自动处理）
    GuestFind.init({ currencySymbol: currencySymbol });

    // 浏览器订单：页面加载自动查询；autoScroll=false 避免自动滚动到结果
    function autoLoadByToken() {
        if (!guestToken) {
            $('#tokenLoading').hide();
            $('#panelToken .find-order-hint').hide();
            $('#panelToken').append('<div class="find-order-empty">未找到浏览器订单记录<br><small>请使用其他方式查询订单</small></div>');
            $('#tabToken').hide();
            if (showCredTab) $('#tabCredentials').trigger('click');
            else if (showOrderNoTab) $('#tabOrderNo').trigger('click');
            return;
        }
        $.post('/user/find_order.php', { mode: 'token', guest_token: guestToken }, function (res) {
            $('#tokenLoading').hide();
            $('#panelToken .find-order-hint').hide();
            if (res.code === 200 && res.data && res.data.length > 0) {
                tokenResultsCache = res.data;
                GuestFind.renderResults(res.data, { autoScroll: false });
            } else {
                $('#panelToken').append('<div class="find-order-empty">未找到浏览器订单记录<br><small>请确认您之前在此浏览器下过单</small></div>');
            }
        }, 'json').fail(function () {
            $('#tokenLoading').hide();
            $('#panelToken .find-order-hint').hide();
            $('#panelToken').append('<div class="find-order-empty">加载失败，请刷新重试</div>');
        });
    }

    // 切回"浏览器订单"时重新展示缓存（guest_find.js 的 tab 切换会 hide 结果区）
    $(document).off('click.findOrderTokenTab').on('click.findOrderTokenTab', '#tabToken', function () {
        if (tokenResultsCache) {
            GuestFind.renderResults(tokenResultsCache, { autoScroll: false });
        }
    });

    autoLoadByToken();
})();
</script>

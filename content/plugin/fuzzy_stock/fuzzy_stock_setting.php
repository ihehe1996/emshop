<?php
defined('EM_ROOT') || exit('access denied!');

function plugin_setting_view() {
    $storage = Storage::getInstance('fuzzy_stock');
    $rules = $storage->getValue('rules');

    if (empty($rules) || !is_array($rules)) {
        $rules = [
            ['min' => 0,   'max' => 0,   'label' => '售罄'],
            ['min' => 1,   'max' => 10,  'label' => '少量'],
            ['min' => 11,  'max' => 100, 'label' => '有货'],
            ['min' => 101, 'max' => 9999999,   'label' => '充足'],
        ];
    }
?>

<div class="popup-inner">
<form class="layui-form" id="fuzzyStockForm" lay-filter="fuzzyStockForm">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) Csrf::token(), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="popup-section">
        

        <div class="spec-table-wrap">
        <table class="layui-table" id="fuzzyStockTable">
            <colgroup>
                <col width="30">
                <col width="140">
                <col width="140">
                <col width="160">
                <col width="60">
            </colgroup>
            <thead>
                <tr>
                    <th></th>
                    <th>最小库存</th>
                    <th>最大库存</th>
                    <th>显示文字</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody id="fuzzyStockList">
                <?php foreach ($rules as $idx => $rule): ?>
                <tr class="fuzzy-stock-row">
                    <td class="drag-handle"><i class="fa fa-bars"></i></td>
                    <td><input type="number" name="rules[<?php echo $idx; ?>][min]" class="layui-input" value="<?php echo (int) $rule['min']; ?>" placeholder="最小值"></td>
                    <td><input type="number" name="rules[<?php echo $idx; ?>][max]" class="layui-input" value="<?php echo (int) $rule['max']; ?>" placeholder=""></td>
                    <td><input type="text" name="rules[<?php echo $idx; ?>][label]" class="layui-input" value="<?php echo htmlspecialchars($rule['label']); ?>" placeholder="显示文字"></td>
                    <td style="text-align:center;"><button type="button" class="layui-btn layui-btn-danger layui-btn-xs" onclick="$(this).closest('tr').remove()"><i class="fa fa-trash"></i></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <button type="button" class="layui-btn layui-btn-sm" id="addFuzzyRuleBtn"><i class="fa fa-plus"></i> 添加规则</button>
    </div>
</form>
</div>

<div class="popup-footer">
    <button type="button" class="popup-btn popup-btn--default" id="fsCancelBtn">取消</button>
    <button type="button" class="popup-btn popup-btn--primary" id="fsSubmitBtn"><i class="layui-icon layui-icon-ok"></i> 保存配置</button>
</div>

<script>
(function(){
    layui.use(['layer', 'form'], function(){
        var $ = layui.$;
        var form = layui.form;
        var ruleIndex = <?php echo count($rules); ?>;

        form.render();

        // 添加规则行
        $('#addFuzzyRuleBtn').on('click', function(){
            var idx = ruleIndex++;
            var html = '<tr class="fuzzy-stock-row">' +
                '<td class="drag-handle"><i class="fa fa-bars"></i></td>' +
                '<td><input type="number" name="rules[' + idx + '][min]" class="layui-input" placeholder="最小值"></td>' +
                '<td><input type="number" name="rules[' + idx + '][max]" class="layui-input" placeholder=""></td>' +
                '<td><input type="text" name="rules[' + idx + '][label]" class="layui-input" placeholder="显示文字"></td>' +
                '<td style="text-align:center;"><button type="button" class="layui-btn layui-btn-danger layui-btn-xs" onclick="$(this).closest(\'tr\').remove()"><i class="fa fa-trash"></i></button></td>' +
                '</tr>';
            $('#fuzzyStockList').append(html);
        });

        // 取消
        $('#fsCancelBtn').on('click', function(){
            var index = parent.layer.getFrameIndex(window.name);
            parent.layer.close(index);
        });

        // 保存
        $('#fsSubmitBtn').on('click', function(){
            var $btn = $(this);
            $btn.prop('disabled', true).html('<i class="layui-icon layui-icon-loading"></i> 保存中...');

            $.ajax({
                type: 'POST',
                url: window.PLUGIN_SAVE_URL || '/admin/plugin.php',
                data: $('#fuzzyStockForm').serialize() + '&_action=save_config&name=fuzzy_stock',
                dataType: 'json',
                success: function(res){
                    if (res.code === 0 || res.code === 200) {
                        if (res.data && res.data.csrf_token) {
                            $('#fuzzyStockForm input[name=csrf_token]').val(res.data.csrf_token);
                        }
                        parent.layer.msg('配置已保存', {icon: 1});
                        var index = parent.layer.getFrameIndex(window.name);
                        parent.layer.close(index);
                    } else {
                        layui.layer.msg(res.msg || '保存失败', {icon: 2});
                        $btn.prop('disabled', false).html('<i class="layui-icon layui-icon-ok"></i> 保存配置');
                    }
                },
                error: function(){
                    layui.layer.msg('网络异常', {icon: 2});
                    $btn.prop('disabled', false).html('<i class="layui-icon layui-icon-ok"></i> 保存配置');
                }
            });
        });
    });
})();
</script>

<?php }

function plugin_setting() {
    $csrf = (string) Input::postStrVar('csrf_token');
    if (!Csrf::validate($csrf)) {
        Output::fail('请求已失效，请刷新页面后重试');
    }

    $rawRules = $_POST['rules'] ?? [];
    $rules = [];

    if (is_array($rawRules)) {
        foreach ($rawRules as $r) {
            $label = trim($r['label'] ?? '');
            if ($label === '') continue;
            $rules[] = [
                'min'   => max(0, (int) ($r['min'] ?? 0)),
                'max'   => max(0, (int) ($r['max'] ?? 0)),
                'label' => $label,
            ];
        }
    }

    $storage = Storage::getInstance('fuzzy_stock');
    $storage->setValue('rules', $rules);

    Output::ok('配置已保存', ['csrf_token' => Csrf::refresh()]);
}

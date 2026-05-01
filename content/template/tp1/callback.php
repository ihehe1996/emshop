<?php
defined('EM_ROOT') || exit('access denied!');

function callback_init() {
    // do something(本演示模板无需初始化)
}

function callback_rm() {
    // 卸载时清掉本模板所有配置
    TemplateStorage::getInstance('tp1')->deleteAllName('YES');
}

function callback_up() {
    // do something
}

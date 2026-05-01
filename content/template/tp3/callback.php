<?php
defined('EM_ROOT') || exit('access denied!');

function callback_init() {
    // do something
}

function callback_rm() {
    TemplateStorage::getInstance('tp3')->deleteAllName('YES');
}

function callback_up() {
    // do something
}

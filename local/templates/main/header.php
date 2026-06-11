<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();
use Bitrix\Main\Page\Asset;
$asset = Asset::getInstance();
?>

<!DOCTYPE html>
<html lang="<?=LANGUAGE_ID?>">
<head>
    <meta charset="UTF-8">
    <?//php CJSCore::Init(array('jquery'));?>
    
    <?php $APPLICATION->ShowHead();?>
    <?php $asset->addCss(SITE_TEMPLATE_PATH . '/css/template_styles.css');?>
    <?php $asset->addCss(SITE_TEMPLATE_PATH . '/css/styles.css');?>
    <?php Asset::getInstance()->addJs(SITE_TEMPLATE_PATH . '/js/jquery-3.6.0.min.js');?>
    <?php Asset::getInstance()->addJs(SITE_TEMPLATE_PATH . '/js/vendor.min.js');?>
    <?php Asset::getInstance()->addJs(SITE_TEMPLATE_PATH . '/js/common.min.js');?>
    <title><?php $APPLICATION->ShowTitle(); ?></title>
</head>
<body>

<?php
// Вывод административной панели Битрикс поверх сайта (для авторизованных)
$APPLICATION->ShowPanel();
?>
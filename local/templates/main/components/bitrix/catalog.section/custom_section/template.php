<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();?>
<div class="products-grid" id="products-list">
        <?if(!empty($arResult["ITEMS"])):?>
            <?foreach($arResult["ITEMS"] as $arItem):
                $price = $arItem['PROPERTIES']['PRICE']['VALUE'] ?? 0;
                $amount = (int)$arItem['PROPERTIES']['STORE_AMOUNT']['VALUE'];
                $img = $arItem['PROPERTIES']['GALLERY']['RESIZED'];
            ?>
                <div class="product-item">
                    <a href="<?=$arItem["DETAIL_PAGE_URL"]?>" class="product-img-wrap"><img src="<?=$img?>" alt="<?=$arItem["NAME"]?>"></a>
                    <div class="product-desc">
                        <span class="product-art">Арт: <?=$arItem['PROPERTIES']['ARTICLE']['VALUE']?></span>
                        <a href="<?=$arItem["DETAIL_PAGE_URL"]?>" class="product-name"><?=$arItem["NAME"]?></a>
                        <div class="product-footer">
                            <span class="product-price"><?=number_format((float)$price, 0, '.', ' ')?> ₽</span>
                            <span class="stock-badge <?=$amount > 0 ? 'yes' : 'no'?>"><?=$amount > 0 ? "Есть ({$amount})" : 'Под заказ'?></span>
                        </div>
                    </div>
                </div>
            <?endforeach;?>
                
        <?else:?>
            <p class="empty-text">Товары не найдены.</p>
        <?endif;?>
</div>
<?//php debug($arParams);?>
<?if($arParams["DISPLAY_BOTTOM_PAGER"]):?>
    <?=$arResult["NAV_STRING"]?>
<?endif?>

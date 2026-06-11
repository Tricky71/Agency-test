<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

/** @var array $arResult */
/** @var array $arParams */

use Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__); 

$price = $arResult['PROPERTIES']['PRICE']['VALUE'] ?? 0;
$amount = (int)$arResult['PROPERTIES']['STORE_AMOUNT']['VALUE'];

// // Собираем галерею картинок
$galleryIds = $arResult['PROPERTIES']['GALLERY']['VALUE'];
if (!is_array($galleryIds)) {
    $galleryIds = $galleryIds ? [$galleryIds] : [];
}
// Уникальный ID для контейнера, чтобы избежать конфликтов при AJAX
$sliderUniqueId = "detail_slider_" . $arResult['ID'];
$brand = $arResult["DISPLAY_PROPERTIES"]["BRAND"]["DISPLAY_VALUE"];
$article = $arResult["PROPERTIES"]["ARTICLE"]["VALUE"];
$firstImg = $arResult['RESIZED'][0];
$amount = $arResult["PROPERTIES"]["STORE_AMOUNT"]["VALUE"];
$name = $arResult["NAME"];
$desc = $arResult["PREVIEW_TEXT"];
$text = $arResult["DETAIL_TEXT"];
$price = $arResult["PROPERTIES"]["PRICE"]["VALUE"];

// debug($arResult);
?>

		<section class="single card">
			<div class="wrapper">
				<div class="product">
					<div class="product__gallery">
						<div class="product__main-photo">
							<img class="product__img" 
							src="<?=!empty($firstImg) ? $firstImg : SITE_TEMPLATE_PATH . '/local/templates/main/images/no-photo.png'?>" 
							alt="<?=$name?>" data-index="0">
						</div>

					<?if(!empty($arResult['RESIZED']) && count($arResult['RESIZED']) > 1):?>
						<div class="product__slider">

						<?foreach($arResult['RESIZED'] as $item):?>	

							<div class="product__item">
								<img class="product__pic" src="<?=$item?>" alt="banner">
							</div>

						<?endforeach?>	

						</div>

					<?endif?>	

					</div>
					<div class="product__about">
						<div class="product__article">
						  <?=Loc::getMessage("ELEM_ARTICLE");?>
							<span class="product__article-value"><?=htmlspecialcharsbx($article)?></span>
						</div>
						<div class="availability true"><!-- Если у .availability нет класса .true – товара Нет в наличии -->

						<?if($amount && $amount > 0):?>

							<span class="true"><?=Loc::getMessage("ELEM_AMOUNT");?></span>

						<?else:?>	

							<span class="true"><?=Loc::getMessage("ELEM_NO_AMOUNT");?></span>

						<?endif?>	

						</div>
						<h1 class="product__title"><?=htmlspecialcharsbx($name)?></h1>

				  <?if($desc):?>

						<p class="product__desc"><?=htmlspecialcharsbx($desc)?></p>

					<?endif?>	
						<form class="product__form">
							<div class="product__price">
								<?=htmlspecialcharsbx($price)?>
								<span class="currency"><?=Loc::getMessage("ELEM_CURRENCY");?></span>
							</div>
							<div class="product__btns">
								<input type="submit" data-submit value="<?=Loc::getMessage("ELEM_TO_CART");?>" class="product__btn btn">
							</div>
						</form>
						
					</div>
				</div>
				<div class="tabs">
					<ul class="tabs__caption">
						<li class="active"><?=Loc::getMessage("ELEM_DESCR");?></li>
					</ul>
					<div class="tabs__content active">
						<div class="description">
							<p><?=htmlspecialcharsbx($text)?></p>
						</div>
					</div>
					
				</div>
<!-- Вывод блока Похожие товары (D7 ORM с корректным ЧПУ категории) -->
      <?if($arResult["RELATED"]):?>

				<div class="related-products-section">
						<h3><?=Loc::getMessage("ELEM_RELATED");?></h3>
						<div class="products-grid">

					  <?foreach($arResult["RELATED"] as $item):?>
												<div class="product-item">
														<a href="<?=$item["URL"]?>" class="product-img-wrap">
																<img src="<?=$item["IMG"]?>" alt="<?=htmlspecialcharsbx($item['NAME'])?>" loading="lazy">
														</a>
														<div class="product-desc">
																<a href="<?=$item["URL"]?>" class="product-name"><?=$item['NAME']?></a>
																<span class="product-price"><?=number_format((float)$item['PRICE_VAL'], 0, '.', ' ')?> ₽</span>
														</div>
												</div>
						<?endforeach?>		
						</div>
				</div>
      <?endif?>
			</div>
		</section>


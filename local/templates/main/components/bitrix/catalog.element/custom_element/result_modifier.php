<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();
?>

<?php
$galleryIds = $arResult['PROPERTIES']['GALLERY']['VALUE'];
if (!is_array($galleryIds)) {
    $galleryIds = $galleryIds ? [$galleryIds] : [];
}
if (empty($galleryIds) && $arResult['DETAIL_PICTURE']) {
    $galleryIds[] = $arResult['DETAIL_PICTURE']['ID'];
}
?>

<?php if (!empty($galleryIds)):?>
    <?foreach($galleryIds as $fileId): 
        $arResized = CFile::ResizeImageGet(
            $fileId,
            array("width" => 550, "height" => 550),
            BX_RESIZE_IMAGE_PROPORTIONAL,
            true
        );
        $img = $arResized['src'];
        $resized[] = $img;
       
    endforeach;?>
        <?$arResult['RESIZED'] = $resized;?>
        
<?else:?>
      <?php $arResult['RESIZED'][0] = "/local/templates/main/images/no-photo.png";?>
        
    
<?endif;?>



<!-- Вывод блока Похожие товары (D7 ORM с корректным ЧПУ категории) -->
				
<?
$brandXmlId = $arResult['PROPERTIES']['BRAND']['VALUE'];

if (!empty($brandXmlId) && \Bitrix\Main\Loader::includeModule('iblock')) {
        
        // Запрашиваем товары, принудительно вытягивая CODE раздела через IBLOCK_SECTION
        $related = \Bitrix\Iblock\Elements\ElementCatalogTable::getList([
                'select' => [
                        'ID', 
                        'NAME', 
                        'CODE', 
                        'PRICE_VAL' => 'PRICE.VALUE', 
                        'GALLERY_VAL' => 'GALLERY.VALUE',
                        'SECTION_CODE' => 'IBLOCK_SECTION.CODE' // Тянем символьный код категории товара
                ],
                'filter' => [
                        '=BRAND.VALUE' => $brandXmlId, 
                        '!=ID' => $arResult['ID'], 
                        '=ACTIVE' => 'Y'
                ],
                'limit' => 4,
                'cache' => ['ttl' => 3600]
        ])->fetchAll();
       
        // Группируем множественное свойство GALLERY, чтобы избежать дублирования строк товаров
        $uniqueRelated = [];
        foreach ($related as $row) {
                if (!isset($uniqueRelated[$row['ID']])) {
                        $uniqueRelated[$row['ID']] = $row;
                        $uniqueRelated[$row['ID']]['GALLERY_IDS'] = [];
                }
                if ($row['GALLERY_VAL']) {
                        $uniqueRelated[$row['ID']]['GALLERY_IDS'][] = $row['GALLERY_VAL'];
                }
        }
        
        foreach($uniqueRelated as $key => $item):
       
                // 1. Получаем картинку товара
                $relatedImg = '/local/templates/main/images/no-photo.png';
                if (!empty($item['GALLERY_IDS'])) {
                        
                        // $relatedImg = CFile::GetPath(reset($item['GALLERY_IDS']));
                        $relatedImgId = reset($item['GALLERY_IDS']);
                        $arResized = CFile::ResizeImageGet(
                                $relatedImgId,
                                array("width" => 190, "height" => 215),
                                BX_RESIZE_IMAGE_PROPORTIONAL_ALT,
                                true
                        );  
                        $relatedImg = $arResized['src']; 
                }

                // 2. Строим правильный ЧПУ URL с категорией и кодом элемента
                // Берем маску пути из параметров комплексного компонента ($arParams['DETAIL_URL'])
                $sectionCode = !empty($item['SECTION_CODE']) ? $item['SECTION_CODE'] : 'all';
                $elementCode = !empty($item['CODE']) ? $item['CODE'] : $item['ID'];

                $url = str_replace(
                        ['#SECTION_CODE_PATH#', '#ELEMENT_CODE#'], 
                        [$sectionCode, $elementCode], 
                        $arParams['DETAIL_URL']
                ); 

                $arResult["RELATED"][$key]["NAME"] = $item["NAME"];
                $arResult["RELATED"][$key]["PRICE_VAL"] = $item["PRICE_VAL"];
                $arResult["RELATED"][$key]["URL"] = $url;
                $arResult["RELATED"][$key]["IMG"] = $relatedImg;

                ?>	
               
        <?endforeach;
     
}

			
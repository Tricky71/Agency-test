<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();

foreach($arResult["ITEMS"] as &$arItem):?>
    <?php $galleryFiles = $arItem['PROPERTIES']['GALLERY']['VALUE'];
        $img = '/local/templates/main/images/no-photo.png'; // Заглушка по умолчанию

        if (!empty($galleryFiles)) {
            // Если массив не пустой, берем ID самого первого загруженного файла
            $firstFileId = is_array($galleryFiles) ? reset($galleryFiles) : $galleryFiles;
            $fileArray = CFile::GetFileArray($firstFileId);
            if ($fileArray) {
              $imageId = $fileArray['ID'];
              $arResized = CFile::ResizeImageGet(
                $imageId,
                array("width" => 190, "height" => 215),
                BX_RESIZE_IMAGE_PROPORTIONAL_ALT,
                true
              );
              $img = $arResized['src'];
              
            }
        } 
        $arItem['PROPERTIES']['GALLERY']['RESIZED'] = $img;
        ?>
<?endforeach?>   
  
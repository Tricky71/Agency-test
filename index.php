<?
/**
 * Главная страница сайта.
 
 */
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Интернет-магазин «АвтоЗапчасти»");
?>

<div style="max-width: 800px; margin: 50px auto; padding: 30px; background: #f9f9f9; border: 1px solid #eee; border-radius: 8px; font-family: sans-serif; line-height: 1.6;">
    <h1 style="color: #333; margin-bottom: 20px;"></h1>
    <p style="font-size: 16px; color: #555;">
        Реализован каталог автозапчастей на базе редакции «Стандарт» с использованием D7 API, Highload-блоков для справочника брендов, кастомного AJAX-фильтра и Slick-слайдера на детальной карточке.
    </p>
    
    <div style="margin: 30px 0;">
        <a href="/catalog/" style="display: inline-block; padding: 12px 24px; background: #007bff; color: #fff; text-decoration: none; font-weight: bold; border-radius: 4px; transition: background 0.2s;">
            Перейти в каталог товаров ➡️
        </a>
    </div>

    <p style="font-size: 14px; color: #888; border-top: 1px solid #ddd; padding-top: 15px;">
        Подробная инструкция в файле <b>README.md</b> в корне репозитория.
    </p>
</div>

<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>
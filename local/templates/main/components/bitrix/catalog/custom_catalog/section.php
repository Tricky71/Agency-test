<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

$request = \Bitrix\Main\Context::getCurrent()->getRequest();

global $arrFilter;
$arrFilter = [];

// Формируем чистый D7-фильтр из входящего GET-запроса
if ($request->get('price_from')) $arrFilter['>=PROPERTY_PRICE'] = (float)$request->get('price_from');
if ($request->get('price_to')) $arrFilter['<=PROPERTY_PRICE'] = (float)$request->get('price_to');
if ($request->get('brand')) $arrFilter['PROPERTY_BRAND'] = htmlspecialcharsbx($request->get('brand'));
if ($request->get('in_stock') === 'Y') $arrFilter['>PROPERTY_STORE_AMOUNT'] = 0;

// Если пришел AJAX-запрос фильтрации — очищаем буфер вывода до компонента
if ($request->get('ajax') === 'y') {
    $APPLICATION->RestartBuffer();
}
?>
<div class="container">
  <div class="catalog-layout">
  
    <?if ($request->get('ajax') !== 'y'):?>
        <aside class="catalog-sidebar">
            <?include(__DIR__.'/include/filter.php');?>
        </aside>
    <?endif;?>

    <main class="catalog-main" id="catalog-ajax-container">
        <?
        $APPLICATION->IncludeComponent(
            "bitrix:catalog.section",
            "custom_section",
            array(
                "IBLOCK_TYPE" => $arParams["IBLOCK_TYPE"],
                "IBLOCK_ID" => $arParams["IBLOCK_ID"],
                "ELEMENT_SORT_FIELD" => $arParams["ELEMENT_SORT_FIELD"],
                "ELEMENT_SORT_ORDER" => $arParams["ELEMENT_SORT_ORDER"],
                "PROPERTY_CODE" => ["PRICE", "STORE_AMOUNT", "ARTICLE", "BRAND"],
                "FILTER_NAME" => "arrFilter",
                "CACHE_TYPE" => $arParams["CACHE_TYPE"],
                "CACHE_TIME" => $arParams["CACHE_TIME"],
                "CACHE_FILTER" => "Y",
                "CACHE_GROUPS" => $arParams["CACHE_GROUPS"],
                "PAGE_ELEMENT_COUNT" => $arParams["PAGE_ELEMENT_COUNT"],
                "SET_TITLE" => "N",
                "SECTION_ID" => $arResult["VARIABLES"]["SECTION_ID"],
                "SECTION_CODE" => $arResult["VARIABLES"]["SECTION_CODE"],
                "SECTION_URL" => $arResult["FOLDER"].$arResult["URL_TEMPLATES"]["section"],
                "DETAIL_URL" => $arResult["FOLDER"].$arResult["URL_TEMPLATES"]["element"],
                "DISPLAY_BOTTOM_PAGER" => $arParams["DISPLAY_BOTTOM_PAGER"],
                "PAGER_TEMPLATE" => $arParams["PAGER_TEMPLATE"]
            ),
            $component
        );
        ?>
    </main>
   
  </div>
</div>  
<?
// Завершаем работу скрипта, если это AJAX, чтобы не рендерить футер сайта
if ($request->get('ajax') === 'y') die();
?>
<script type='text/javascript'>
(function() {
    function initAjaxFilter() {
        var filterForm = document.getElementById('catalog-filter-form');
        var container = document.getElementById('catalog-ajax-container');

        if (!filterForm || !container) return;

        filterForm.onsubmit = function(e) {
            e.preventDefault(); // Блокируем перезагрузку страницы
            
            container.style.opacity = '0.4';

            var formData = new FormData(filterForm);
            formData.append('ajax', 'y');
            formData.append('_', Date.now());

            var params = new URLSearchParams(formData);
            var requestUrl = window.location.pathname + '?' + params.toString();

            fetch(requestUrl, {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(res) {
                if (!res.ok) throw new Error('Ошибка сети');
                return res.text();
            })
            .then(function(html) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                var newList = doc.getElementById('products-list');

                if (newList) {
                    container.innerHTML = '';
                    container.appendChild(newList);
                } else {
                    container.innerHTML = '<p class=\"empty-text\">Товары не найдены.</p>';
                }

                params.delete('ajax');
                params.delete('_');
                var cleanBrowserUrl = window.location.pathname + '?' + params.toString();
                window.history.pushState({ path: cleanBrowserUrl }, '', cleanBrowserUrl);
            })
            .catch(function(err) {
                console.error('AJAX Error:', err);
                container.innerHTML = '<p class=\"error-text\">Произошла ошибка при загрузке данных.</p>';
            })
            .finally(function() {
                container.style.opacity = '1';
            });
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAjaxFilter);
    } else {
        initAjaxFilter();
    }
})();
</script>

<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

use Bitrix\Highloadblock\HighloadBlockTable;
\Bitrix\Main\Loader::includeModule('highloadblock');

// Находим HL-блок брендов для построения <select>
$hlblock = HighloadBlockTable::getList(['filter' => ['=TABLE_NAME' => 'b_hl_brands']])->fetch();
$entityDataClass = HighloadBlockTable::compileEntity($hlblock)->getDataClass();

$brands = $entityDataClass::getList([
    'select' => ['UF_NAME', 'UF_XML_ID'],
    'order' => ['UF_NAME' => 'ASC'],
    'cache' => ['ttl' => 3600]
])->fetchAll();

$req = \Bitrix\Main\Context::getCurrent()->getRequest();
?>

<!-- Инлайновый перехват события onsubmit гарантирует, что страница никогда не перезагрузится -->
<form id="catalog-filter-form" class="pure-filter">
    <div class="filter-item">
        <label for="filter-brand">Бренд</label>
        <select name="brand" id="filter-brand">
            <option value="">Все бренды</option>
            <?foreach($brands as $brand):?>
                <option value="<?=$brand['UF_XML_ID']?>" <?=($req->get('brand') == $brand['UF_XML_ID'])?'selected':''?>>
                    <?=htmlspecialcharsbx($brand['UF_NAME'])?>
                </option>
            <?endforeach;?>
        </select>
    </div>

    <div class="filter-item">
        <label>Цена, ₽</label>
        <div class="range-inputs">
            <input type="number" name="price_from" placeholder="от" value="<?=htmlspecialcharsbx($req->get('price_from'))?>">
            <input type="number" name="price_to" placeholder="до" value="<?=htmlspecialcharsbx($req->get('price_to'))?>">
        </div>
    </div>

    <div class="filter-item checkbox">
        <input type="checkbox" id="in_stock" name="in_stock" value="Y" <?=($req->get('in_stock') == 'Y')?'checked':''?>>
        <label for="in_stock">Только в наличии</label>
    </div>

    <button type="submit" class="filter-submit-btn">Применить</button>
</form>


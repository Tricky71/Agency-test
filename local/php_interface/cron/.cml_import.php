<?php
if (php_sapi_name() !== 'cli') {
    die("Доступно только через CLI\n");
}

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../..');
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;
use Bitrix\Iblock\Elements\ElementCatalogTable;

Loader::includeModule('iblock');

$importFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/1c_import/import.xml';
$logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/cml_import.log';
$iblockId = 5; 

if (!file_exists($importFile)) {
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Ошибка: Файл выгрузки не найден.\n", FILE_APPEND);
    die("Файл не найден.\n");
}

$startTime = microtime(true);
$stats = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'errors' => []];
$importedXmlIds = [];

try {
    $reader = new XMLReader();
    $reader->open($importFile);

    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'Товар') {
            
            $node = new SimpleXMLElement($reader->readOuterXml());
            
            // Битрикс использует тег Ид как внешний XML_ID товара
            $xmlId = trim((string)$node->Ид);
            $name = trim((string)$node->Наименование);
            
            // Извлекаем ID основного раздела товара
            $sectionId = 0;
            if ($node->Группы->Ид) {
                $sectionId = (int)$node->Группы->Ид;
            }

            $price = 0;
            $amount = 0;
            $brandXmlId = '';
            $article = '';
            $compatibility = [];

            // Разбираем свойства по их точным цифровым ID из присланного XML
            if ($node->ЗначенияСвойств->ЗначенияСвойства) {
                foreach ($node->ЗначенияСвойств->ЗначенияСвойства as $prop) {
                    $propId = (int)$prop->Ид;
                    $propVal = trim((string)$prop->Значение);

                    if ($propId === 9) $brandXmlId = $propVal;      // Бренд
                    if ($propId === 10) $article = $propVal;        // Артикул
                    if ($propId === 11) $price = (float)$propVal;   // Цена
                    if ($propId === 12) $amount = (int)$propVal;    // Наличие
                    if ($propId === 14) $compatibility[] = (int)$propVal; // Совместимость
                }
            }

            if (empty($xmlId)) {
                $stats['errors'][] = "Пропущен товар без внешнего Ид: " . $name;
                continue;
            }

            $importedXmlIds[] = $xmlId;

            // Поиск в БД на чистом D7 ORM
            $existingProduct = ElementCatalogTable::getList([
                'select' => ['ID', 'NAME', 'ACTIVE', 'IBLOCK_SECTION_ID', 'PRICE_VAL' => 'PRICE.VALUE', 'STORE_VAL' => 'STORE_AMOUNT.VALUE', 'BRAND_VAL' => 'BRAND.VALUE', 'ARTICLE_VAL' => 'ARTICLE.VALUE'],
                'filter' => ['=XML_ID' => $xmlId, '=IBLOCK_ID' => $iblockId],
                'limit' => 1
            ])->fetch();

            if ($existingProduct) {
                $hasChanges = false;
                $updateFields = [];

                if ($existingProduct['NAME'] !== $name) {
                    $updateFields['NAME'] = $name;
                    $hasChanges = true;
                }
                if ($existingProduct['ACTIVE'] !== 'Y') {
                    $updateFields['ACTIVE'] = 'Y';
                    $hasChanges = true;
                }
                if ($existingProduct['IBLOCK_SECTION_ID'] != $sectionId && $sectionId > 0) {
                    $updateFields['IBLOCK_SECTION_ID'] = $sectionId;
                    $hasChanges = true;
                }

                // Сверяем значения полей, чтобы обновлять только изменённые
                if ($hasChanges || $existingProduct['PRICE_VAL'] != $price || $existingProduct['STORE_VAL'] != $amount || $existingProduct['BRAND_VAL'] != $brandXmlId || $existingProduct['ARTICLE_VAL'] !== $article) {
                    
                    $el = new CIBlockElement;
                    if (!empty($updateFields)) {
                        $el->Update($existingProduct['ID'], $updateFields);
                    }
                    
                    CIBlockElement::SetPropertyValuesEx($existingProduct['ID'], $iblockId, [
                        'PRICE' => $price,
                        'STORE_AMOUNT' => $amount,
                        'BRAND' => $brandXmlId,
                        'ARTICLE' => $article,
                        'COMPATIBILITY' => $compatibility
                    ]);

                    $stats['updated']++;
                }
            } else {
                // Создание нового товара
                $el = new CIBlockElement;
                $newId = $el->Add([
                    'IBLOCK_ID' => $iblockId,
                    'IBLOCK_SECTION_ID' => $sectionId,
                    'NAME' => $name,
                    'XML_ID' => $xmlId,
                    'ACTIVE' => 'Y',
                    'CODE' => CUtil::translit($name, 'ru', ['max_len' => 100, 'change_case' => 'L']), // Генерируем ЧПУ-код
                    'PROPERTY_VALUES' => [
                        'PRICE' => $price,
                        'STORE_AMOUNT' => $amount,
                        'BRAND' => $brandXmlId,
                        'ARTICLE' => $article,
                        'COMPATIBILITY' => $compatibility
                    ]
                ]);

                if ($newId) {
                    $stats['created']++;
                } else {
                    $stats['errors'][] = "Ошибка создания товара [{$xmlId}]: " . $el->LAST_ERROR;
                }
            }
            unset($node);
        }
    }
    $reader->close();

    // Деактивация отсутствующих в XML товаров
    if (!empty($importedXmlIds)) {
        $toDeactivate = ElementCatalogTable::getList([
            'select' => ['ID'],
            'filter' => [
                '=IBLOCK_ID' => $iblockId,
                '=ACTIVE' => 'Y',
                '!@XML_ID' => $importedXmlIds
            ]
        ]);

        $el = new CIBlockElement;
        while ($product = $toDeactivate->fetch()) {
            $el->Update($product['ID'], ['ACTIVE' => 'N']);
            $stats['deactivated']++;
        }
    }

} catch (Exception $e) {
    $stats['errors'][] = "Критический сбой: " . $e->getMessage();
}

$executionTime = round(microtime(true) - $startTime, 2);
$logMessage = sprintf(
    "[%s] Импорт завершен. Время: %s сек. Создано: %d, Обновлено: %d, Деактивировано: %d. Ошибок: %d\n",
    date('Y-m-d H:i:s'), $executionTime, $stats['created'], $stats['updated'], $stats['deactivated'], count($stats['errors'])
);

if (!empty($stats['errors'])) {
    $logMessage .= "--- Ошибки ---\n" . implode("\n", $stats['errors']) . "\n--------------\n";
}

file_put_contents($logFile, $logMessage, FILE_APPEND);
echo "Успешно! Статистика сохранена в /local/logs/cml_import.log\n";
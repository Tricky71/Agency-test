<?php
/**
 * Скрипт пакетного импорта 5000+ товаров CommerceML 2 на чистом API 1С-Битрикс (D7).
 * Реализована отказоустойчивость: падение одной записи не останавливает общий импорт.
 */

if (php_sapi_name() !== 'cli' && (!isset($_GET['secret_key']) || $_GET['secret_key'] !== 'my_super_password_123')) {
    @header('HTTP/1.0 403 Forbidden');
    die("Доступ запрещен.");
}

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

if (php_sapi_name() === 'cli') {
    $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../..');
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;
use Bitrix\Iblock\Elements\ElementCatalogTable;

if (!Loader::includeModule('iblock')) {
    die("Ошибка: Модуль инфоблоков не установлен.\n");
}

$importFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/1c_import/import.xml';
$logFile    = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/cml_import.log';
$iblockId   = 5; 
$baseXmlDir = dirname($importFile) . '/';

if (!file_exists($importFile)) {
    $errMessage = "[" . date('Y-m-d H:i:s') . "] Критическая ошибка: Файл выгрузки не найден: {$importFile}\n";
    file_put_contents($logFile, $errMessage, FILE_APPEND);
    die("Ошибка: Файл выгрузки не найден.\n");
}

$startTime = microtime(true);
$stats = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'errors' => []];
$importedXmlIds = [];

// Настройки пакетов для оптимизации памяти Garbage Collector
$itemsProcessed = 0;
$gcStep = 100; // Каждые 100 товаров принудительно очищаем память PHP

try {
    $reader = new XMLReader();
    $reader->open($importFile);

    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'Товар') {
            
            // Заворачиваем обработку ОДНОЙ записи в изолированный try-catch.
            // Если этот конкретный товар сломается — скрипт залогирует ошибку и пойдет к следующему товару.
            try {
                $node = new SimpleXMLElement($reader->readOuterXml());
                
                $xmlId = trim((string)$node->Ид);
                $name  = trim((string)$node->Наименование);
                
                $sectionId = 0;
                if ($node->Группы && $node->Группы->Ид) {
                    $sectionId = (int)$node->Группы->Ид;
                }

                // Защита: плохая запись без Идентификатора не должна ронять БД
                if (empty($xmlId)) {
                    throw new Exception("Критическая ошибка валидации XML: Отсутствует уникальный тег <Ид> для товара: '" . $name . "'");
                }

                $importedXmlIds[] = $xmlId;

                $brandXmlId     = '';
                $article        = '';
                $price          = 0;
                $amount         = 0;
                $compatibility  = [];
                $galleryPaths   = [];
                if ($node->ЗначенияСвойств && $node->ЗначенияСвойств->ЗначенияСвойства) {
                    foreach ($node->ЗначенияСвойств->ЗначенияСвойства as $prop) {
                        $propId  = (int)$prop->Ид;
                        $propVal = trim((string)$prop->Значение);

                        if (empty($propVal) && $propVal !== '0') continue;

                        switch ($propId) {
                            case 9:  $brandXmlId = $propVal; break;
                            case 10: $article = $propVal; break;
                            case 11: $price = (float)$propVal; break;
                            case 12: $amount = (int)$propVal; break;
                            case 13: $galleryPaths[] = $propVal; break;
                            case 14: $compatibility[] = (int)$propVal; break;
                        }
                    }
                }

                // Защита: если артикул пустой, кидаем контролируемое исключение
                if (empty($article)) {
                    throw new Exception("Товар '" . $name . "' пропущен: не заполнен обязательный Артикул (свойство Ид: 10)");
                }

                $arGalleryFiles    = [];
                $mainPictureArray  = false;

                foreach ($galleryPaths as $index => $relPath) {
                    $fullPath = $baseXmlDir . $relPath;
                    if (file_exists($fullPath) && is_file($fullPath)) {
                        $fileArray = CFile::MakeFileArray($fullPath);
                        if ($fileArray) {
                            $fileArray['description'] = '';
                            $arGalleryFiles[] = $fileArray;
                            if ($index === 0) $mainPictureArray = $fileArray;
                        }
                    }
                }

                // Высокопроизводительный поиск товара в БД на чистом D7 ORM
                $existingProduct = ElementCatalogTable::getList([
                    'select' => [
                        'ID', 'NAME', 'ACTIVE', 'IBLOCK_SECTION_ID', 
                        'PRICE_VAL'  => 'PRICE.VALUE', 
                        'STORE_VAL'  => 'STORE_AMOUNT.VALUE', 
                        'BRAND_VAL'  => 'BRAND.VALUE', 
                        'ARTICLE_VAL'=> 'ARTICLE.VALUE'
                    ],
                    'filter' => ['=XML_ID' => $xmlId, '=IBLOCK_ID' => $iblockId],
                    'limit' => 1
                ])->fetch();

                $el = new CIBlockElement;

                if ($existingProduct) {
                    $hasFieldsChanges = false;
                    $updateFields     = [];

                    if ($existingProduct['NAME'] !== $name) {
                        $updateFields['NAME'] = $name;
                        $hasFieldsChanges = true;
                    }
                    if ($existingProduct['ACTIVE'] !== 'Y') {
                        $updateFields['ACTIVE'] = 'Y';
                        $hasFieldsChanges = true;
                    }
                    if ($existingProduct['IBLOCK_SECTION_ID'] != $sectionId && $sectionId > 0) {
                        $updateFields['IBLOCK_SECTION_ID'] = $sectionId;
                        $hasFieldsChanges = true;
                    }
                    if ($mainPictureArray) {
                        $updateFields['PREVIEW_PICTURE'] = $mainPictureArray;
                        $updateFields['DETAIL_PICTURE']  = $mainPictureArray;
                        $hasFieldsChanges = true;
                    }

                    $hasPropsChanges = (
                        $existingProduct['PRICE_VAL'] != $price ||
                        $existingProduct['STORE_VAL'] != $amount ||
                        $existingProduct['BRAND_VAL'] != $brandXmlId ||
                        $existingProduct['ARTICLE_VAL'] !== $article ||
                        !empty($arGalleryFiles)
                    );

                    if ($hasFieldsChanges || $hasPropsChanges) {
                        if (!empty($updateFields)) {
                            if (!$el->Update($existingProduct['ID'], $updateFields)) {
                                throw new Exception("Ошибка обновления базовых полей: " . $el->LAST_ERROR);
                            }
                        }
                        
                        $arPropValues = [
                            'PRICE'         => $price,
                            'STORE_AMOUNT'  => $amount,
                            'BRAND'         => $brandXmlId,
                            'ARTICLE'       => $article,
                            'COMPATIBILITY' => $compatibility
                        ];
                        if (!empty($arGalleryFiles)) $arPropValues['GALLERY'] = $arGalleryFiles;

                        CIBlockElement::SetPropertyValuesEx($existingProduct['ID'], $iblockId, $arPropValues);
                        $stats['updated']++;
                    }
                } else {
                    $translitCode = CUtil::translit($name, 'ru', ['max_len' => 100, 'change_case' => 'L']);
                    $newId = $el->Add([
                        'IBLOCK_ID'         => $iblockId,
                        'IBLOCK_SECTION_ID' => $sectionId,
                        'NAME'              => $name,
                        'XML_ID'            => $xmlId,
                        'ACTIVE'            => 'Y',
                        'CODE'              => $translitCode . '-' . time(),
                        'PREVIEW_PICTURE'   => $mainPictureArray,
                        'DETAIL_PICTURE'    => $mainPictureArray,
                        'PROPERTY_VALUES'   => [
                            'PRICE'         => $price,
                            'STORE_AMOUNT'  => $amount,
                            'BRAND'         => $brandXmlId,
                            'ARTICLE'       => $article,
                            'COMPATIBILITY' => $compatibility,
                            'GALLERY'       => $arGalleryFiles
                        ]
                    ]);

                    if ($newId) {
                        $stats['created']++;
                    } else {
                        throw new Exception("Ошибка записи нового товара в БД: " . $el->LAST_ERROR);
                    }
                }

            } catch (Exception $itemException) {
                // ПЕРЕХВАТ ОШИБКИ ИТЕРАЦИИ: Скрипт фиксирует сбой по конкретному товару, 
                // записывает ошибку в массив статистики, но НЕ ПАДАЕТ и продолжает работу.
                $stats['errors'][] = "[Товар XML_ID: " . ($xmlId ?? 'Неизвестно') . "] " . $itemException->getMessage();
            }

            // ПАКЕТНАЯ ОЧИСТКА ПАМЯТИ (Каждые 100 позиций выгружаем мусор из RAM)
            $itemsProcessed++;
            if ($itemsProcessed % $gcStep === 0) {
                unset($node, $existingProduct, $arGalleryFiles, $mainPictureArray);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles(); // Принудительный сборщик мусора PHP
                }
            }
        }
    }
    $reader->close();
    // 9. ПАКЕТНАЯ ДЕАКТИВАЦИЯ: Безопасный перевод отсутствующих товаров в архив частями
    if (!empty($importedXmlIds)) {
        // Запрашиваем товары, которых не было в массиве импорта
        $toDeactivateList = ElementCatalogTable::getList([
            'select' => ['ID'],
            'filter' => [
                '=IBLOCK_ID' => $iblockId,
                '=ACTIVE'    => 'Y',
                '!@XML_ID'   => $importedXmlIds
            ]
        ]);

        $el = new CIBlockElement;
        $deactivateBatch = [];
        
        while ($product = $toDeactivateList->fetch()) {
            $deactivateBatch[] = $product['ID'];
            
            // Чтобы не вешать MySQL на 1000+ апдейтах одновременно, деактивируем пакетами по 50 штук
            if (count($deactivateBatch) >= 50) {
                foreach ($deactivateBatch as $id) {
                    $el->Update($id, ['ACTIVE' => 'N']);
                    $stats['deactivated']++;
                }
                $deactivateBatch = []; // Сброс пакета
            }
        }
        
        // Дописываем остатки из последнего неполного пакета деактивации
        if (!empty($deactivateBatch)) {
            foreach ($deactivateBatch as $id) {
                $el->Update($id, ['ACTIVE' => 'N']);
                $stats['deactivated']++;
            }
        }
    }

} catch (Exception $e) {
    $stats['errors'][] = "Критический системный сбой структуры XML: " . $e->getMessage();
}

// 10. Запись детализированного лога операций
$executionTime = round(microtime(true) - $startTime, 2);
$logMessage = sprintf(
    "[%s] СИНХРОНИЗАЦИЯ ЗАВЕРШЕНА. Время выполнения: %s сек. Обработано записей: %d. Создано: %d. Обновлено: %d. Деактивировано: %d. Ошибок: %d\n",
    date('Y-m-d H:i:s'), $executionTime, $itemsProcessed, $stats['created'], $stats['updated'], $stats['deactivated'], count($stats['errors'])
);

if (!empty($stats['errors'])) {
    $logMessage .= "--- ДЕТАЛЬНЫЙ СПИСОК ОШИБОК ДЛЯ АУДИТА 1С ---\n" . implode("\n", $stats['errors']) . "\n---------------------------------------------\n";
}

file_put_contents($logFile, $logMessage, FILE_APPEND);

if (php_sapi_name() === 'cli') {
    echo "Успешно! Статистика выполнения импорта записана в лог: /local/logs/cml_import.log\n";
} else {
    echo "<h1>Импорт завершен успешно!</h1><p>Результаты и ошибки сохранены в файл <b>/local/logs/cml_import.log</b></p>";
}

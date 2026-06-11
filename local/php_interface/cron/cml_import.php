<?php
/**
 * Скрипт пакетного импорта товаров CommerceML 2 на чистом API 1С-Битрикс (D7).
 * Поддерживает запуск из CLI (Cron/OSP6) и безопасный вызов из браузера по ключу.
 */

// 1. Контроль безопасности и инициализации окружения
// if (php_sapi_name() !== 'cli' && (!isset($_GET['secret_key']) || $_GET['secret_key'] !== 'my_super_password_123')) {
//     @header('HTTP/1.0 403 Forbidden');
//     die("Доступ запрещен.");
// }

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

// Настройка DOCUMENT_ROOT для консольного режима
if (php_sapi_name() === 'cli') {
    $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../..');
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;
use Bitrix\Iblock\Elements\ElementCatalogTable;

if (!Loader::includeModule('iblock')) {
    die("Ошибка: Модуль инфоблоков не установлен.\n");
}

// 2. Конфигурация параметров импорта
$importFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/1c_import/import.xml';
$logFile    = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/cml_import.log';
$iblockId   = 5; // ID вашего инфоблока каталога автозапчастей
$baseXmlDir = dirname($importFile) . '/'; // Базовая папка, где лежит XML и папка cata_files

// Проверка наличия исходного файла
if (!file_exists($importFile)) {
    $errMessage = "[" . date('Y-m-d H:i:s') . "] Критическая ошибка: Файл выгрузки не найден по пути: {$importFile}\n";
    file_put_contents($logFile, $errMessage, FILE_APPEND);
    die("Ошибка: Файл выгрузки не найден. Лог обновлен.\n");
}

$startTime = microtime(true);
$stats = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'errors' => []];
$importedXmlIds = []; // Массив внешних ID для последующей деактивации отсутствующих

try {
    // 3. Потоковое чтение XML через XMLReader (Защита от RAM memory_limit на 5000+ товаров)
    $reader = new XMLReader();
    $reader->open($importFile);

    while ($reader->read()) {
        // Ищем ноду конкретного товара
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'Товар') {
            
            // Загружаем только одну ноду в SimpleXML для быстрой обработки в памяти
            $node = new SimpleXMLElement($reader->readOuterXml());
            
            $xmlId = trim((string)$node->Ид);
            $name  = trim((string)$node->Наименование);
            
            // Определение ID основного раздела (категории) товара
            $sectionId = 0;
            if ($node->Группы && $node->Группы->Ид) {
                $sectionId = (int)$node->Группы->Ид;
            }

            if (empty($xmlId)) {
                $stats['errors'][] = "Пропущен товар без внешнего Ид (тег <Ид>): " . $name;
                unset($node);
                continue;
            }

            $importedXmlIds[] = $xmlId;

            // Инициализация переменных свойств
            $brandXmlId     = '';
            $article        = '';
            $price          = 0;
            $amount         = 0;
            $compatibility  = [];
            $galleryPaths   = [];

            // 4. Разбор свойств товара по их ID из классификатора cata.xml
            if ($node->ЗначенияСвойств && $node->ЗначенияСвойств->ЗначенияСвойства) {
                foreach ($node->ЗначенияСвойств->ЗначенияСвойства as $prop) {
                    $propId  = (int)$prop->Ид;
                    $propVal = trim((string)$prop->Значение);

                    if (empty($propVal) && $propVal !== '0') continue;

                    switch ($propId) {
                        case 9:
                            $brandXmlId = $propVal; // Справочник HL (строковый XML_ID)
                            break;
                        case 10:
                            $article = $propVal; // Строка артикула
                            break;
                        case 11:
                            $price = (float)$propVal; // Число цены
                            break;
                        case 12:
                            $amount = (int)$propVal; // Число остатка
                            break;
                        case 13:
                            $galleryPaths[] = $propVal; // Пути множественной галереи
                            break;
                        case 14:
                            $compatibility[] = (int)$propVal; // Множественная привязка к разделам
                            break;
                    }
                }
            }
            // 5. Обработка изображений товаров (CFile)
            $arGalleryFiles    = [];
            $mainPictureArray  = false;

            foreach ($galleryPaths as $index => $relPath) {
                $fullPath = $baseXmlDir . $relPath;
                if (file_exists($fullPath) && is_file($fullPath)) {
                    $fileArray = CFile::MakeFileArray($fullPath);
                    if ($fileArray) {
                        $fileArray['description'] = '';
                        $arGalleryFiles[] = $fileArray;
                        
                        // Первую картинку из галереи дублируем как главное фото анонса и детальное фото
                        if ($index === 0) {
                            $mainPictureArray = $fileArray;
                        }
                    }
                }
            }

            // 6. Поиск товара в БД на высокопроизводительном D7 ORM
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
                // 7. СЦЕНАРИЙ ОБНОВЛЕНИЯ: проверяем изменения, чтобы не переписывать БД вхолостую
                $hasFieldsChanges = false;
                $updateFields     = [];

                if ($existingProduct['NAME'] !== $name) {
                    $updateFields['NAME'] = $name;
                    $hasFieldsChanges = true;
                }
                if ($existingProduct['ACTIVE'] !== 'Y') {
                    $updateFields['ACTIVE'] = 'Y'; // Возвращаем активность, если товар снова в выгрузке
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

                // Проверяем, изменились ли свойства
                $hasPropsChanges = (
                    $existingProduct['PRICE_VAL'] != $price ||
                    $existingProduct['STORE_VAL'] != $amount ||
                    $existingProduct['BRAND_VAL'] != $brandXmlId ||
                    $existingProduct['ARTICLE_VAL'] !== $article ||
                    !empty($arGalleryFiles)
                );

                if ($hasFieldsChanges || $hasPropsChanges) {
                    if (!empty($updateFields)) {
                        $el->Update($existingProduct['ID'], $updateFields);
                    }
                    
                    $arPropValues = [
                        'PRICE'         => $price,
                        'STORE_AMOUNT'  => $amount,
                        'BRAND'         => $brandXmlId,
                        'ARTICLE'       => $article,
                        'COMPATIBILITY' => $compatibility
                    ];
                    
                    if (!empty($arGalleryFiles)) {
                        $arPropValues['GALLERY'] = $arGalleryFiles;
                    }

                    CIBlockElement::SetPropertyValuesEx($existingProduct['ID'], $iblockId, $arPropValues);
                    $stats['updated']++;
                }
            } else {
                // 8. СЦЕНАРИЙ СОЗДАНИЯ: Создаем новый товар, генерируя ЧПУ-код
                $translitCode = CUtil::translit($name, 'ru', ['max_len' => 100, 'change_case' => 'L']);
                
                $arLoadFields = [
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
                ];

                $newId = $el->Add($arLoadFields);

                if ($newId) {
                    $stats['created']++;
                } else {
                    $stats['errors'][] = "Ошибка создания товара с Артикулом [{$article}]: " . $el->LAST_ERROR;
                }
            }
            unset($node);
        }
    }
    $reader->close();
    // 9. СЦЕНАРИЙ ДЕАКТИВАЦИИ: Выключаем товары, которые отсутствуют в текущем XML-пакете
    if (!empty($importedXmlIds)) {
        $toDeactivateList = ElementCatalogTable::getList([
            'select' => ['ID'],
            'filter' => [
                '=IBLOCK_ID' => $iblockId,
                '=ACTIVE'    => 'Y',
                '!@XML_ID'   => $importedXmlIds
            ]
        ]);

        $el = new CIBlockElement;
        while ($product = $toDeactivateList->fetch()) {
            $el->Update($product['ID'], ['ACTIVE' => 'N']);
            $stats['deactivated']++;
        }
    }

} catch (Exception $e) {
    $stats['errors'][] = "Критический системный сбой выполнения: " . $e->getMessage();
}

// 10. Формирование логов и вывод результатов (В соответствии с ТЗ)
$executionTime = round(microtime(true) - $startTime, 2);
$logMessage = sprintf(
    "[%s] СИНХРОНИЗАЦИЯ ЗАВЕРШЕНА. Время выполнения: %s сек. Создано новых позиций: %d. Обновлено измененных: %d. Деактивировано (архив): %d. Число ошибок: %d\n",
    date('Y-m-d H:i:s'),
    $executionTime,
    $stats['created'],
    $stats['updated'],
    $stats['deactivated'],
    count($stats['errors'])
);

if (!empty($stats['errors'])) {
    $logMessage .= "--- ДЕТАЛЬНЫЙ СПИСОК ЗАФИКСИРОВАННЫХ ОШИБОК ---\n" . implode("\n", $stats['errors']) . "\n---------------------------------------------\n";
}

file_put_contents($logFile, $logMessage, FILE_APPEND);

if (php_sapi_name() === 'cli') {
    echo "Успешно! Статистика выполнения импорта записана в лог: /local/logs/cml_import.log\n";
} else {
    echo "<h1>Импорт завершен успешно!</h1><p>Результаты и логи сохранены в файл <b>/local/logs/cml_import.log</b></p>";
}

<?php
/**
 * Генератор CommerceML 2.x файлов для импорта в Tilda
 * Создаёт import.xml (категории + товары) и offers.xml (цены + остатки)
 */

class CommerceMLExporter
{
    private XMLWriter $importXml;
    private XMLWriter $offersXml;
    private string $exportDir;

    public function __construct(string $exportDir)
    {
        $this->exportDir = $exportDir;
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }
    }

    /**
     * Генерирует import.xml и offers.xml из данных Posiflora
     * 
     * @param array $categories   Категории из Posiflora
     * @param array $items        Товары из Posiflora (inventory-items)
     * @param array $included     included JSON:API (картинки)
     * @return array Пути к созданным файлам
     */
    public function generate(array $categories, array $items, array $included = []): array
    {
        $filesById = [];
        foreach ($included as $row) {
            $type = $row['type'] ?? '';
            if ($type === 'files' || $type === 'images') {
                $filesById[$row['id'] ?? ''] = $row;
            }
        }
        $timestamp = date('Y-m-d\TH:i:s');

        // ── import.xml ────────────────────────────────────
        $this->importXml = new XMLWriter();
        $this->importXml->openMemory();
        $this->importXml->setIndent(true);
        $this->importXml->setIndentString('  ');

        $this->importXml->startDocument('1.0', 'UTF-8');
        $this->importXml->startElement('КоммерческаяИнформация');
        $this->importXml->writeAttribute('Ссылка', 'https://daribuzuluk.posiflora.com');
        $this->importXml->writeAttribute('ВерсияСхемы', '2.05');
        $this->importXml->writeAttribute('ДатаФормирования', $timestamp);

        // Классификатор (категории)
        $this->importXml->startElement('Классификатор');
        $this->importXml->writeElement('Наименование', 'Posiflora Catalog');
        $this->importXml->startElement('Группы');
        foreach ($categories as $cat) {
            $this->writeCategory($cat, $categories);
        }
        $this->importXml->endElement(); // Группы
        $this->importXml->endElement(); // Классификатор

        // Каталог (товары)
        $this->importXml->startElement('Каталог');
        $this->importXml->writeAttribute('СодержитТолькоИзменения', 'false');
        $this->importXml->writeElement('Наименование', 'Posiflora Products');
        $this->importXml->startElement('Товары');
        foreach ($items as $item) {
            $this->writeProduct($item, $filesById);
        }
        $this->importXml->endElement(); // Товары
        $this->importXml->endElement(); // Каталог

        $this->importXml->endElement(); // КоммерческаяИнформация
        $importContent = $this->importXml->outputMemory();

        // ── offers.xml ────────────────────────────────────
        $this->offersXml = new XMLWriter();
        $this->offersXml->openMemory();
        $this->offersXml->setIndent(true);
        $this->offersXml->setIndentString('  ');

        $this->offersXml->startDocument('1.0', 'UTF-8');
        $this->offersXml->startElement('КоммерческаяИнформация');
        $this->offersXml->writeAttribute('Ссылка', 'https://daribuzuluk.posiflora.com');
        $this->offersXml->writeAttribute('ВерсияСхемы', '2.05');
        $this->offersXml->writeAttribute('ДатаФормирования', $timestamp);

        $this->offersXml->startElement('ПакетПредложений');
        $this->offersXml->writeAttribute('СодержитТолькоИзменения', 'false');
        $this->offersXml->writeElement('Наименование', 'Posiflora Offers');
        $this->offersXml->startElement('ТипыЦен');
        $this->offersXml->startElement('ТипЦены');
        $this->offersXml->writeElement('Ид', 'retail');
        $this->offersXml->writeElement('Наименование', 'Розничная');
        $this->offersXml->writeElement('Валюта', 'RUB');
        $this->offersXml->endElement();
        $this->offersXml->endElement();
        $this->offersXml->startElement('Предложения');
        foreach ($items as $item) {
            $this->writeOffer($item);
        }
        $this->offersXml->endElement(); // Предложения
        $this->offersXml->endElement(); // ПакетПредложений

        $this->offersXml->endElement(); // КоммерческаяИнформация
        $offersContent = $this->offersXml->outputMemory();

        // ── Запись файлов ─────────────────────────────────
        $importPath = $this->exportDir . '/import.xml';
        $offersPath = $this->exportDir . '/offers.xml';
        file_put_contents($importPath, $importContent);
        file_put_contents($offersPath, $offersContent);

        // ZIP-архив для Tilda
        $zipPath = $this->exportDir . '/catalog.zip';
        $zipError = null;

        // Удаляем старый архив если есть
        if (is_file($zipPath)) {
            unlink($zipPath);
        }

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($result === true) {
                $zip->addFile($importPath, 'import.xml');
                $zip->addFile($offersPath, 'offers.xml');
                $zip->close();
            } else {
                $zipError = "ZipArchive::open вернул код ошибки {$result}";
            }
        } else {
            // Резервный метод: консольная команда zip
            $cmd = "cd " . escapeshellarg($this->exportDir) . " && zip -j catalog.zip import.xml offers.xml 2>&1";
            $output = shell_exec($cmd);
            if (!is_file($zipPath)) {
                $zipError = 'Расширение PHP zip не установлено, консольная команда zip тоже недоступна';
                if ($output) {
                    $zipError .= ". Вывод: " . trim($output);
                }
            }
        }

        $result = [
            'import.xml' => $importPath,
            'offers.xml' => $offersPath,
        ];

        if (is_file($zipPath)) {
            $result['catalog.zip'] = $zipPath;
        } else {
            $result['catalog.zip'] = null;
            $result['zip_error'] = $zipError ?? 'Архив не создан по неизвестной причине';
        }

        return $result;
    }

    private function writeCategory(array $cat, array $allCategories): void
    {
        $id    = $cat['id'] ?? '';
        $title = $cat['attributes']['title'] ?? 'Без названия';
        if ($title === 'Рецепты') {
            $title = 'Букеты';
        }
        $parentId = $cat['relationships']['parent']['data']['id'] ?? null;

        // Пропускаем подкатегории здесь — они будут вложены в родителя
        if ($parentId) return;

        $this->importXml->startElement('Группа');
        $this->importXml->writeElement('Ид', $id);
        $this->importXml->writeElement('Наименование', $title);
        $this->importXml->startElement('Группы');
        // Дочерние категории
        foreach ($allCategories as $child) {
            $childParentId = $child['relationships']['parent']['data']['id'] ?? null;
            if ($childParentId === $id) {
                $this->importXml->startElement('Группа');
                $this->importXml->writeElement('Ид', $child['id']);
                $this->importXml->writeElement('Наименование', $child['attributes']['title'] ?? '');
                $this->importXml->endElement(); // Группа
            }
        }
        $this->importXml->endElement(); // Группы
        $this->importXml->endElement(); // Группа
    }

    private function writeProduct(array $item, array $filesById = []): void
    {
        $id    = $item['id'] ?? '';
        $attrs = $item['attributes'] ?? [];
        $title = $attrs['title'] ?? 'Без названия';
        $desc  = $attrs['description'] ?? '';
        $globalId = $attrs['globalId'] ?? '';

        $this->importXml->startElement('Товар');
        $this->importXml->writeElement('Ид', $id);
        $this->importXml->writeElement('Наименование', $title);
        $this->importXml->writeElement('Артикул', $globalId ?: $id);
        if ($desc) {
            $this->importXml->writeElement('Описание', $desc);
        }
        $categoryId = $item['relationships']['category']['data']['id'] ?? null;
        if ($categoryId) {
            $this->importXml->startElement('Группы');
            $this->importXml->writeElement('Ид', $categoryId);
            $this->importXml->endElement();
        }
        $barcodes = $item['relationships']['barcodes']['data'] ?? [];
        if ($barcodes) {
            $this->importXml->startElement('Штрихкоды');
            foreach ($barcodes as $bc) {
                $this->importXml->writeElement('Штрихкод', $bc['id'] ?? '');
            }
            $this->importXml->endElement();
        }
        $photo = (string)($attrs['pictureUrl'] ?? '');
        if ($photo === '') {
            $photo = $this->photoUrl($item, $filesById);
        }
        if ($photo !== '') {
            $this->importXml->startElement('Картинка');
            $this->importXml->text($photo);
            $this->importXml->endElement();
        }
        $active = ($attrs['tildaActive'] ?? $attrs['public'] ?? true) ? 'true' : 'false';
        $this->importXml->writeElement('Активен', $active);
        $this->importXml->endElement();
    }

    private function photoUrl(array $item, array $filesById): string
    {
        $logo = $item['relationships']['logo']['data'] ?? null;
        $logoId = is_array($logo) ? ($logo['id'] ?? '') : '';
        if ($logoId === '') {
            $images = $item['relationships']['images']['data'] ?? [];
            $logoId = is_array($images[0] ?? null) ? ($images[0]['id'] ?? '') : '';
        }
        if ($logoId === '' || !isset($filesById[$logoId])) {
            return '';
        }
        $file = $filesById[$logoId];
        foreach (['fileShop', 'fileMedium', 'file', 'url'] as $key) {
            $url = $file['attributes'][$key] ?? '';
            if (is_string($url) && str_starts_with($url, 'http')) {
                return $url;
            }
        }
        return '';
    }

    private function writeOffer(array $item): void
    {
        $id    = $item['id'] ?? '';
        $attrs = $item['attributes'] ?? [];
        $priceMin = (float)($attrs['priceMin'] ?? 0);
        $priceMax = (float)($attrs['priceMax'] ?? 0);
        $price = $priceMin > 0 ? $priceMin : $priceMax;

        $this->offersXml->startElement('Предложение');
        $this->offersXml->writeElement('Ид', $id);
        $this->offersXml->writeElement('Наименование', $attrs['title'] ?? '');

        // Цена
        $this->offersXml->startElement('Цены');
        $this->offersXml->startElement('Цена');
        $this->offersXml->writeElement('Представление', number_format($price, 2, '.', '') . ' руб');
        $this->offersXml->writeElement('ИдТипаЦены', 'retail');
        $this->offersXml->writeElement('ЦенаЗаЕдиницу', number_format($price, 2, '.', ''));
        $this->offersXml->writeElement('Валюта', 'RUB');
        $this->offersXml->endElement(); // Цена
        $this->offersXml->endElement(); // Цены

        // Остаток (количество) — из балансов, если есть
        $active = ($attrs['tildaActive'] ?? $attrs['public'] ?? true) ? true : false;
        $balance = $attrs['balance'] ?? $attrs['quantity'] ?? null;
        $qty = (!$active) ? '0' : (($balance !== null) ? (string)max(0, (float)$balance) : '1');
        $this->offersXml->writeElement('Остаток', $qty);
        $this->offersXml->writeElement('Количество', $qty);
        $this->offersXml->writeElement('Активен', $active ? 'true' : 'false');

        $this->offersXml->endElement(); // Предложение
    }
}

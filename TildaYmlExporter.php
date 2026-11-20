<?php
/**
 * YML + CSV для каталога Тильды (обход падающего CommerceML).
 * External ID = UUID товара в Posiflora — повторный импорт обновляет, а не дублирует.
 */

class TildaYmlExporter
{
    private string $exportDir;

    public function __construct(string $exportDir)
    {
        $this->exportDir = $exportDir;
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }
    }

    public function generate(array $categories, array $items, array $included = []): array
    {
        $filesById = [];
        foreach ($included as $row) {
            $type = $row['type'] ?? '';
            if ($type === 'files' || $type === 'images') {
                $filesById[$row['id']] = $row;
            }
        }

        $catTitles = [];
        foreach ($categories as $cat) {
            $catTitles[$cat['id'] ?? ''] = $cat['attributes']['title'] ?? '';
        }

        $ymlPath = $this->exportDir . '/catalog.yml';
        $csvPath = $this->exportDir . '/catalog.csv';

        $xw = new XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');
        $xw->startElement('yml_catalog');
        $xw->writeAttribute('date', date('Y-m-d H:i'));
        $xw->startElement('shop');
        $xw->writeElement('name', 'ДАРИ');
        $xw->writeElement('company', 'Цветочный бутик ДАРИ');
        $xw->writeElement('url', 'https://wake-earned-clock.tilda.ws');
        $xw->startElement('currencies');
        $xw->startElement('currency');
        $xw->writeAttribute('id', 'RUR');
        $xw->writeAttribute('rate', '1');
        $xw->endElement();
        $xw->endElement();

        $xw->startElement('categories');
        foreach ($categories as $cat) {
            $id = $cat['id'] ?? '';
            if ($id === '') {
                continue;
            }
            $xw->startElement('category');
            $xw->writeAttribute('id', $id);
            $parent = $cat['relationships']['parent']['data']['id'] ?? null;
            if ($parent) {
                $xw->writeAttribute('parentId', $parent);
            }
            $xw->text($cat['attributes']['title'] ?? 'Раздел');
            $xw->endElement();
        }
        $xw->endElement();

        $xw->startElement('offers');
        $csv = fopen($csvPath, 'w');
        fwrite($csv, "\xEF\xBB\xBF");
        fputcsv($csv, [
            'External ID', 'SKU', 'Title', 'Category', 'Price', 'Quantity',
            'Description', 'Photo', 'Brand',
        ], ';');

        foreach ($items as $item) {
            $id    = $item['id'] ?? '';
            $attrs = $item['attributes'] ?? [];
            $title = trim((string)($attrs['title'] ?? ''));
            if ($id === '' || $title === '') {
                continue;
            }
            $price = (float)($attrs['priceMin'] ?? 0);
            if ($price <= 0) {
                $price = (float)($attrs['priceMax'] ?? 0);
            }
            $qtyRaw = $attrs['balance'] ?? $attrs['quantity'] ?? null;
            $qty = ($qtyRaw !== null && $qtyRaw !== '') ? (string)max(0, (int)$qtyRaw) : '';
            $sku  = (string)($attrs['globalId'] ?? $id);
            $desc = (string)($attrs['description'] ?? '');
            $catId = $item['relationships']['category']['data']['id'] ?? '';
            $catTitle = $catTitles[$catId] ?? '';
            $photos = $attrs['pictureUrls'] ?? [];
            if (!is_array($photos) || $photos === []) {
                $one = (string)($attrs['pictureUrl'] ?? '');
                if ($one === '') {
                    $one = $this->photoUrl($item, $filesById);
                }
                $photos = $one !== '' ? [$one] : [];
            }

            $active = ($attrs['tildaActive'] ?? $attrs['public'] ?? true) ? true : false;
            $xw->startElement('offer');
            $xw->writeAttribute('id', $id);
            $xw->writeAttribute('available', $active ? 'true' : 'false');
            $xw->writeElement('name', $title);
            $xw->writeElement('price', number_format($price, 2, '.', ''));
            $xw->writeElement('currencyId', 'RUR');
            if ($catId !== '') {
                $xw->writeElement('categoryId', $catId);
            }
            $xw->writeElement('vendorCode', $sku);
            if ($qty !== '') {
                $xw->writeElement('count', $qty);
            }
            foreach ($photos as $photo) {
                if (is_string($photo) && $photo !== '') {
                    $xw->writeElement('picture', $photo);
                }
            }
            if ($desc !== '') {
                $xw->writeElement('description', $desc);
            }
            $xw->endElement();

            fputcsv($csv, [
                $id,
                $sku,
                $title,
                $catTitle,
                number_format($price, 2, '.', ''),
                $qty,
                $desc,
                $photos[0] ?? '',
                'ДАРИ',
            ], ';');
        }

        $xw->endElement();
        $xw->endElement();
        $xw->endElement();
        file_put_contents($ymlPath, $xw->outputMemory());
        fclose($csv);

        return ['catalog.yml' => $ymlPath, 'catalog.csv' => $csvPath];
    }

    private function photoUrl(array $item, array $filesById): string
    {
        $logo = $item['relationships']['logo']['data'] ?? null;
        $logoId = is_array($logo) ? ($logo['id'] ?? '') : '';
        if ($logoId === '') {
            $images = $item['relationships']['images']['data'] ?? [];
            $logoId = is_array($images[0] ?? null) ? ($images[0]['id'] ?? '') : '';
        }
        if ($logoId === '') {
            return '';
        }
        $file = $filesById[$logoId] ?? [];
        foreach (['fileShop', 'fileMedium', 'file', 'url', 'original', 'path', 'src'] as $key) {
            $url = $file['attributes'][$key] ?? '';
            if (is_string($url) && str_starts_with($url, 'http')) {
                return $url;
            }
        }
        return 'https://startergpt.ru/tilda/catalog-photo.php?id=' . rawurlencode($logoId);
    }
}

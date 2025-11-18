<?php

namespace App\Service;

use App\Document\Artefact;
use App\Document\YearRange;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ArtefactLoader implements LoaderInterface
{
    private const DATA_SET_URL = 'https://statics.belowthesurface.amsterdam/downloadbare-datasets/Downloadtabel_EN.csv';
    private const SUMMARY_INCLUDE_KEYS = [3, 5, 17, 18, 19, 20, 21, 26, 32, 33, 34, 37, 38, 39, 40, 41, 42, 43, 46, 47, 48, 49, 50, 51, 56, 57, 66, 67, 68, 74, 75, 80, 81, 82, 83, 84, 85, 86, 87, 88, 92, 93, 98, 104, 110, 111, 112, 113, 114, 115, 116, 117, 120, 126, 127, 133, 134, 135, 136, 137, 138, 139, 140, 141, 142, 143, 144, 145, 146, 150, 151, 152, 155, 156, 157, 162];
    private const KEY_YEAR_RANGE_START = 9;
    private const KEY_YEAR_RANGE_END = 10;
    private const KEY_FIND_ID = 0;

    private readonly HttpClientInterface $httpClient;
    public function __construct(private readonly DocumentManager $dm)
    {
        $this->httpClient = HttpClient::create();
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function load(?string $source, array $options = []): iterable
    {
        $schemaManager = $this->dm->getSchemaManager();
        $schemaManager->ensureIndexes();
        $schemaManager->createSearchIndexes();

        foreach ($this->loadDataSet($source ?? self::DATA_SET_URL) as $row) {
            $summary = implode('. ', array_filter(array_map(function ($key) use ($row) {
                return $row[$key] ?? '';
            }, self::SUMMARY_INCLUDE_KEYS)));

            $findId = $row[self::KEY_FIND_ID];
            $imageUrls = $this->findImageUrls($findId);

            $dateRange = null;
            if ($row[self::KEY_YEAR_RANGE_START ?? false] && $row[self::KEY_YEAR_RANGE_END] ?? false) {
                $summary .= sprintf(' Dated between %s and %s', $row[self::KEY_YEAR_RANGE_START], $row[self::KEY_YEAR_RANGE_END]);
                $dateRange = new YearRange(
                    (int) $row[self::KEY_YEAR_RANGE_START],
                    (int) $row[self::KEY_YEAR_RANGE_END]
                );
            }

            $artefact = new Artefact(
                findId: $findId,
                summary: $summary,
                dated: $dateRange,
                imageUrls: $imageUrls
            );

            $this->dm->persist($artefact);
            echo "Persisted artefact with find ID {$findId}\n";

            yield $artefact;
        }

        $this->dm->flush();
        echo "Persisted all artefacts.\n";
    }

    private function loadDataSet(string $source): \Generator
    {
        $stream = fopen($source, 'r');

        while (($row = fgetcsv($stream)) !== false) {
            // Only include artefacts displayed on the website, as these include images.
            if ($row && ($row[22] ?? '') === '1') {
                yield $row;
            }
        }

        fclose($stream);
    }

    /**
     * @throws TransportExceptionInterface
     *
     * @return string[]
     */
    private function findImageUrls(string $findId): array
    {
        $urls = [];

        for ($i = 1; $i < 3; ++$i) {
            $url = sprintf('https://statics.belowthesurface.amsterdam/vondst/600/%s(0%d).png', $findId, $i);
            $response = $this->httpClient->request('HEAD', $url);

            if ($response->getStatusCode() === 200) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}

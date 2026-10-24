<?php

namespace App\Verification;

/**
 * The frozen annotation sheet, read once and asked for one query at a time.
 *
 * The sheet is a CSV with query, rank, findId, summary, image and the verdict
 * column the annotator filled in. Verdicts are not read here — the scorer owns
 * the relevance policy — but the file and its hash identify the run.
 */
final readonly class Sheet
{
    /**
     * @param list<CandidateUnderTest> $candidates
     */
    private function __construct(
        private array $candidates,
    ) {
    }

    public static function fromCsv(string $path, string $base): self
    {
        $stream = fopen($path, 'r');
        if (false === $stream) {
            throw new \RuntimeException(sprintf('Cannot read the annotation sheet "%s".', $path));
        }

        $header = fgetcsv($stream);
        if (false === $header) {
            throw new \RuntimeException(sprintf('The annotation sheet "%s" has no header.', $path));
        }

        $candidates = [];
        while (($row = fgetcsv($stream)) !== false) {
            $candidates[] = self::candidateOf(array_combine($header, $row), $base);
        }
        fclose($stream);

        return new self($candidates);
    }

    /**
     * @return list<string>
     */
    public function queries(): array
    {
        return array_values(array_unique(array_map(
            static fn (CandidateUnderTest $candidate): string => $candidate->query,
            $this->candidates,
        )));
    }

    /**
     * @return list<CandidateUnderTest>
     */
    public function forQuery(string $query): array
    {
        return array_values(array_filter(
            $this->candidates,
            static fn (CandidateUnderTest $candidate): bool => $candidate->query === $query,
        ));
    }

    /**
     * @param array<string, string> $row
     */
    private static function candidateOf(array $row, string $base): CandidateUnderTest
    {
        $query = $row['query'];

        return new CandidateUnderTest(
            $query,
            $row['findId'],
            $row['summary'],
            $base . '/photos/' . $query . '.png',
            '' === trim($row['image']) ? '' : $base . '/' . ltrim($row['image'], '/'),
            (int) $row['rank'],
        );
    }
}

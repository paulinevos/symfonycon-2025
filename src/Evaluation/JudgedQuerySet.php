<?php

namespace App\Evaluation;

/**
 * The hand-annotated queries, read back from the sheet a human filled in.
 *
 * The sheet is one row per (query, candidate), in retrieval order, with a
 * verdict column. Blank counts as a match: an unmarked candidate was left
 * alone because it looked right.
 */
final readonly class JudgedQuerySet implements \IteratorAggregate, \Countable
{
    /**
     * @param list<JudgedQuery> $queries
     */
    private function __construct(private array $queries)
    {
    }

    public static function fromCsv(string $path): self
    {
        $rows = array_map('str_getcsv', file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
        $header = array_shift($rows);

        $byQuery = [];
        foreach ($rows as $row) {
            $record = array_combine($header, $row);
            $query = $record['query'];

            $byQuery[$query][] = [
                'findId' => $record['findId'],
                'summary' => $record['summary'],
                'verdict' => '' === trim($record['relevant (y/n)']) ? JudgedQuery::RELEVANT : trim($record['relevant (y/n)']),
            ];
        }

        return new self(array_map(
            static fn (string $query, array $candidates): JudgedQuery => new JudgedQuery($query, $candidates),
            array_keys($byQuery),
            array_values($byQuery),
        ));
    }

    /**
     * Queries that can actually score: at least one match, and at least one
     * that is not, so there is something to rank.
     *
     * @return list<JudgedQuery>
     */
    public function scorable(): array
    {
        return array_values(array_filter($this->queries, static function (JudgedQuery $query): bool {
            $verdicts = array_column($query->candidates, 'verdict');

            return in_array(JudgedQuery::RELEVANT, $verdicts, true)
                && count(array_unique($verdicts)) > 1;
        }));
    }

    /**
     * @return list<JudgedQuery>
     */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->queries);
    }

    public function count(): int
    {
        return count($this->queries);
    }
}

<?php

namespace ErDhruvMishra\SqliteFFI\Compat;

use ErDhruvMishra\SqliteFFI\PDO\SqlitePDO;
use PDO;
use TeamTNT\TNTSearch\Engines\SqliteEngine;
use TeamTNT\TNTSearch\Stemmer\NoStemmer;
use TeamTNT\TNTSearch\Support\Tokenizer;

/**
 * Drop-in replacement for TNTSearch's SqliteEngine that uses FFI-backed
 * SqlitePDO instead of native PDO.
 *
 * Usage in TNTSearch config:
 *   'engine' => \ErDhruvMishra\SqliteFFI\Compat\TntSearchEngine::class,
 */
class TntSearchEngine extends SqliteEngine
{
    #[\Override]
    public function createIndex(string $indexName)
    {
        $this->indexName = $indexName;

        $this->flushIndex($indexName);

        // FFI-backed PDO instead of native PDO
        $this->index = new SqlitePDO('sqlite:' . $this->config['storage'] . $indexName);
        $this->index->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($this->config['wal'] ?? false) {
            $this->index->exec("PRAGMA journal_mode=wal;");
        }

        $this->index->exec("CREATE TABLE IF NOT EXISTS wordlist (
                    id INTEGER PRIMARY KEY,
                    term TEXT UNIQUE COLLATE nocase,
                    num_hits INTEGER,
                    num_docs INTEGER)");

        $this->index->exec("CREATE UNIQUE INDEX 'main'.'index' ON wordlist ('term');");

        $this->index->exec("CREATE TABLE IF NOT EXISTS doclist (
                    term_id INTEGER,
                    doc_id INTEGER,
                    hit_count INTEGER)");

        $this->index->exec("CREATE TABLE IF NOT EXISTS fields (
                    id INTEGER PRIMARY KEY,
                    name TEXT)");

        $this->index->exec("CREATE TABLE IF NOT EXISTS hitlist (
                    term_id INTEGER,
                    doc_id INTEGER,
                    field_id INTEGER,
                    position INTEGER,
                    hit_count INTEGER)");

        $this->index->exec("CREATE TABLE IF NOT EXISTS info (
                    key TEXT,
                    value TEXT)");

        $infoStatement = $this->index->prepare("INSERT INTO info (`key`, `value`) VALUES (:key, :value);");
        $infoValues = [
            [':key' => 'total_documents', ':value' => 0],
            [':key' => 'stemmer', ':value' => NoStemmer::class],
            [':key' => 'tokenizer', ':value' => Tokenizer::class],
        ];

        foreach ($infoValues as $value) {
            $infoStatement->execute($value);
        }

        $this->index->exec("CREATE INDEX IF NOT EXISTS 'main'.'term_id_index' ON doclist ('term_id' COLLATE BINARY);");
        $this->index->exec("CREATE INDEX IF NOT EXISTS 'main'.'doc_id_index' ON doclist ('doc_id');");

        if (isset($this->config['stemmer'])) {
            $this->setStemmer(new $this->config['stemmer']);
        }

        if (isset($this->config['tokenizer'])) {
            $this->setTokenizer(new $this->config['tokenizer']);
        }

        // Initialize source database handle (MySQL/Postgres/etc.)
        if (!isset($this->dbh)) {
            $dbh = $this->createConnector($this->config)->connect($this->config);

            if ($dbh instanceof PDO) {
                $this->dbh = $dbh;
            }
        }

        return $this;
    }

    #[\Override]
    public function selectIndex(string $indexName)
    {
        $pathToIndex = $this->config['storage'] . $indexName;
        if (!file_exists($pathToIndex)) {
            throw new \TeamTNT\TNTSearch\Exceptions\IndexNotFoundException(
                "Index {$pathToIndex} does not exist",
                1
            );
        }
        // FFI-backed PDO instead of native PDO
        $this->index = new SqlitePDO('sqlite:' . $pathToIndex);
        $this->index->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
}

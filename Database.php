<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    private array $toFind = ["?", "{"];

    private array $parsers = [];

    private array $parsersKeys = [];
    private string $query = '';
    private int $curQueryPos = 0;
    private array $curParams = [];
    private ?\Generator $curParamGenerator = null;
    private bool $skip = false;
    private int $qLen = 0;

    public function __construct(mysqli $mysqli = null)
    {
//        $this->mysqli = $mysqli;
        $this->parsers = [
            '?d' => fn($p) => $this->d($p),
            '?f' => fn($p) => $this->f($p),
            '?a' => fn($p) => $this->a($p),
            '?#' => fn($p) => $this->hash($p),
            '{' => fn($p) => $this->bracket($p, '{', '}'),
            '?' => fn($p) => $this->def($p),
        ];
        $this->parsersKeys = array_keys($this->parsers);
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $this->query = $query;
        $this->curQueryPos = 0;
        $this->curParams = $args;
        $q = '';
        foreach ($this->parseQuery() as $block) {
            $q .= $block;
        }
        return $q;
    }

    public function skip(): \Closure
    {
        $t = $this;
        return function () use (&$t) {
            $t->skip = true;
            $t->curParamGenerator->next();
            return null;
        };
    }

    private function paramsYield(): \Generator
    {
        foreach ($this->curParams as $param) {
            yield $param;
        }
    }

    private function parseQuery(): \Generator
    {
        $this->qLen = strlen($this->query);
        $this->curParamGenerator = $this->paramsYield();
        for ($this->curQueryPos = 0; $this->curQueryPos < $this->qLen; $this->curQueryPos++) {

            $letter = $this->query[$this->curQueryPos];
            if (in_array($letter, $this->toFind)) {
                $result = $this->getResult($letter);
                if ($this->skip) {
                    $this->skip = false;
                    yield '';
                } else {
                    yield $result;
                }
                $this->curParamGenerator->next();
            } else {
                yield $letter;
            }
        }
    }


    private function d($param): int
    {
        return $this->toType($param, 'd');
    }

    private function f($param): float
    {
        return $this->toType($param, 'f');
    }

    private function a($param): string
    {
        if (!is_array($param)) {
            throw new Exception($param . ' is not array');
        }
        if (array_is_list($param)) {
            return implode(', ', array_map(fn($a) => $this->toType($a), $param));
        }
        $res = [];
        foreach ($param as $key => $value) {
            $res[] = $this->toKey($key) . ' = ' . $this->toType($value);
        }
        return implode(', ', $res);
    }

    private function hash($param): string
    {
        if (is_array($param)) {
            return implode(', ', array_map(fn($a) => $this->toKey($a), $param));
        }
        return $this->toKey($param);
    }

    private function def($param): string
    {
        if (is_array($param)) {
            var_dump($param);
            throw new Exception(implode(' ', $param) . ' is array');
        }
        return $this->toType($param);
    }

    private function bracket($param, $openTag, $closingTag): string
    {
        $subquery = '';
        $this->curQueryPos++;
        for (; $this->curQueryPos < $this->qLen; $this->curQueryPos++) {
            $letter = $this->query[$this->curQueryPos];
            if ($letter === $openTag) {
                throw new Exception('no recursion { } allowed');
            }
            if ($letter === $closingTag) {
                return $subquery;
            }
            if (in_array($letter, $this->toFind)) {
                $result = $this->getResult($letter);
                $subquery .= $result;
                $this->curParamGenerator->next();
            } else {
                $subquery .= $letter;
            }
        }
        return new Exception('closing tag not found');
    }

    private function toKey($param): string
    {
        return "`{$param}`";
    }

    private function toType($param, $type = ''): float|int|string
    {
        if ($param instanceof \Closure) {
            $param();
            $param = 0;
        }
        switch ($type) {
            case 'd':
                return intval($param);
            case 'f':
                return floatval($param);
            default:
                $t = gettype($param);
                if ($t === 'string') {
                    $param = addslashes($param);
                    return "'{$param}'";
                }
                if ($t === 'NULL') {
                    return $t;
                }
                if (in_array($t, ['boolean', 'double', 'integer'])) {
                    return $param;
                }
        }
        throw new Exception("wrong parameter", $param);
    }

    /**
     * @param string $letter
     * @return mixed
     */
    private function getResult(string $letter): string
    {
        $lastParser = $letter;
        if (
            isset($this->query[$this->curQueryPos + 1])
            && in_array($letter . $this->query[$this->curQueryPos + 1], $this->parsersKeys)
        ) {
            $lastParser = $letter . $this->query[$this->curQueryPos + 1];
            $this->curQueryPos++;
        }
        $curParam = $this->curParamGenerator->current();

        $result = $this->parsers[$lastParser]($curParam);
        return $result;
    }
}

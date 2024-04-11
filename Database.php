<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{

    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    const int TYPE_MAIN = 0;
    const int TYPE_BLOCK = 1;
    const string SEARCH_PATTERN = '/\?([dfa#]?)/';

    public function buildQuery(string $query, array $args = []): string
    {
        $blocks = $this->split($query);

        $blockArgs = [];
        $replaceCallback = function ($matches) use (&$args, &$blockArgs) {
            $paramType = $matches[1] ?? '';
            $param = array_shift($args);
            $blockArgs[] = $param;

            return match ($paramType) {
                'd' => $this->formatInt($param),
                'f' => $this->formatFloat($param),
                'a' => $this->formatArrayParam($param),
                '#' => $this->formatIdentifierParam($param),
                default => $this->escapeAndQuoteValue($param),
            };
        };
        $result = '';
        foreach ($blocks as $k => $block) {
            if (!array_key_exists(1, $block)) {
                continue;
            }

            $block[1] = preg_replace_callback(self::SEARCH_PATTERN, $replaceCallback, $block[1]);
            if ($block[0] === self::TYPE_BLOCK && in_array($this->skip(), $blockArgs, true)) {
                continue;
            }

            $blockArgs = [];
            $result .= $block[1];
        }

        return $result;
    }

    public function skip()
    {
        return "#skip#";
    }

    /**
     * Разбивает строку sql на блоки, вырезает '{}' и размечает блоки в фигурных скобках
     * [[type, sql_block],...]
     * где - type [self::TYPE_MAIN, self::TYPE_BLOCK]
     *
     * @param string $query
     * @return array
     */
    private function split(string $query): array
    {
        $result = [];
        $current = 0;
        $result[$current][0] = self::TYPE_MAIN;

        foreach (mb_str_split($query) as $symbol) {
            if ($symbol === '{') {
                $result[++$current][0] = self::TYPE_BLOCK;
                continue;
            }

            if ($symbol === '}') {
                $result[++$current][0] = self::TYPE_MAIN;
                continue;
            }

            if (!isset($result[$current][1])) {
                $result[$current][1] = $symbol;
            } else {
                $result[$current][1] .= $symbol;
            }
        }

        return $result;
    }

    private function escapeAndQuoteValue(?string $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_numeric($value)) {
            return $value;
        }

        return "'" . $value . "'";
    }

    private function escapeAndQuoteIdent(string $value): string
    {
        return "`" . $value . "`";
    }

    private function formatArrayParam(array $param): string
    {
        $result = [];
        foreach ($param as $key => $subParam) {
            $formatParam = $this->escapeAndQuoteValue($subParam);
            if (is_string($key)) {
                $formatIdent = $this->escapeAndQuoteIdent($key);
                $result[] = $formatIdent . ' = ' . $formatParam;
            } else {
                $result[] = $formatParam;
            }
        }

        return implode(', ', $result);
    }

    private function formatIdentifierParam($param): string
    {
        if (is_array($param)) {
            $formattedIdentifiers = [];
            foreach ($param as $identifier) {
                $formattedIdentifiers[] = $this->escapeAndQuoteIdent($identifier);
            }
            return implode(', ', $formattedIdentifiers);
        } else {
            return $this->escapeAndQuoteIdent($param);
        }
    }

    private function formatInt(mixed $param): string|int
    {
        if ($param === null) {
            return 'NULL';
        }
        return (int)$param;
    }

    private function formatFloat(mixed $param): string|float
    {
        if ($param === null) {
            return 'NULL';
        }

        return (float)$param;
    }
}
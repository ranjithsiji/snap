<?php

declare(strict_types=1);

namespace JuryTool\Infrastructure\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Exposes the database's RAND() to DQL.
 *
 * Used when dealing images out to jurors: shuffling in the database keeps
 * very large rounds from having to be loaded into PHP just to be reordered.
 */
class RandFunction extends FunctionNode
{
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'RAND()';
    }
}

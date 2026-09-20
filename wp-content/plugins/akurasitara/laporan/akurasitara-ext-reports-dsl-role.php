<?php
/** AkurasiTara Ext Reports - Universal Excel DSL Engine, Standalone Reference Value Tables & Role Management */
if (!defined('ABSPATH') && !defined('AKURASITARA_DSL_TESTING')) exit;

if (!class_exists('AkurasiTara_DSL_Engine')) {
    // =============================================================================
    // 1. TOKENS & LEXER
    // =============================================================================
    class AkurasiTara_DSL_Token {
        const T_NUMBER      = 'NUMBER';
        const T_STRING      = 'STRING';
        const T_IDENTIFIER  = 'IDENTIFIER';
        const T_SIGMA       = 'SIGMA';       // Σ, SIGMA, SUM_I
        const T_PLUS        = 'PLUS';        // +
        const T_MINUS       = 'MINUS';       // -
        const T_MUL         = 'MUL';         // *
        const T_DIV         = 'DIV';         // /
        const T_MOD         = 'MOD';         // % (modulo or percent operator)
        const T_POWER       = 'POWER';       // ^
        const T_LPAREN      = 'LPAREN';      // (
        const T_RPAREN      = 'RPAREN';      // )
        const T_LBRACKET    = 'LBRACKET';    // [
        const T_RBRACKET    = 'RBRACKET';    // ]
        const T_RANGE       = 'RANGE';       // .. or TO
        const T_COMMA       = 'COMMA';       // ,
        const T_EQ          = 'EQ';          // == or =
        const T_NEQ         = 'NEQ';         // != or <>
        const T_LT          = 'LT';          // <
        const T_LTE         = 'LTE';         // <=
        const T_GT          = 'GT';          // >
        const T_GTE         = 'GTE';         // >=
        const T_AND         = 'AND';         // && or AND
        const T_OR          = 'OR';          // || or OR
        const T_NOT         = 'NOT';         // ! or NOT
        const T_PERCENT_SYM = 'PERCENT_SYM'; // % suffix (e.g. 100%)
        const T_EOF         = 'EOF';

        public $type;
        public $value;
        public $pos;

        public function __construct($type, $value, $pos = 0) {
            $this->type  = $type;
            $this->value = $value;
            $this->pos   = $pos;
        }
    }

    class AkurasiTara_DSL_Lexer {
        private $input;
        private $len;
        private $pos;

        public function __construct($input) {
            $decoded = html_entity_decode((string)$input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->input = trim($decoded);
            if (strpos($this->input, '=') === 0) {
                $this->input = substr($this->input, 1);
            }
            // Normalize '* 100%' idiom (common in IKU formulas) to '* 100'
            $this->input = preg_replace('/\*\s*100\s*%/i', '* 100', $this->input);
            $this->len = mb_strlen($this->input, 'UTF-8');
            $this->pos = 0;
        }

        private function charAt($offset) {
            if ($offset >= $this->len) return null;
            return mb_substr($this->input, $offset, 1, 'UTF-8');
        }

        public function tokenize() {
            $tokens = array();
            while ($this->pos < $this->len) {
                $char = $this->charAt($this->pos);

                if (preg_match('/\s/u', $char)) {
                    $this->pos++;
                    continue;
                }

                if ($char === 'Σ' || $char === '∑') {
                    $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_SIGMA, 'Σ', $this->pos);
                    $this->pos++;
                    continue;
                }

                if (is_numeric($char) || ($char === '.' && is_numeric($this->charAt($this->pos + 1)))) {
                    $start = $this->pos;
                    $numStr = '';
                    $hasDot = false;
                    while ($this->pos < $this->len) {
                        $c = $this->charAt($this->pos);
                        if (is_numeric($c)) {
                            $numStr .= $c;
                            $this->pos++;
                        } elseif ($c === '.' && !$hasDot && is_numeric($this->charAt($this->pos + 1))) {
                            if ($this->charAt($this->pos + 1) === '.') {
                                break;
                            }
                            $hasDot = true;
                            $numStr .= $c;
                            $this->pos++;
                        } else {
                            break;
                        }
                    }

                    if ($this->charAt($this->pos) === '%') {
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_NUMBER, floatval($numStr), $start);
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_PERCENT_SYM, '%', $this->pos);
                        $this->pos++;
                    } else {
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_NUMBER, $hasDot ? floatval($numStr) : intval($numStr), $start);
                    }
                    continue;
                }

                if ($char === "'" || $char === '"') {
                    $quote = $char;
                    $start = $this->pos;
                    $this->pos++;
                    $strVal = '';
                    while ($this->pos < $this->len && $this->charAt($this->pos) !== $quote) {
                        $strVal .= $this->charAt($this->pos);
                        $this->pos++;
                    }
                    if ($this->pos < $this->len) $this->pos++;
                    $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_STRING, $strVal, $start);
                    continue;
                }

                if ($char === '.' && $this->charAt($this->pos + 1) === '.') {
                    $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_RANGE, '..', $this->pos);
                    $this->pos += 2;
                    continue;
                }

                if (preg_match('/[a-zA-Z_]/u', $char)) {
                    $start = $this->pos;
                    $ident = '';
                    while ($this->pos < $this->len) {
                        $c = $this->charAt($this->pos);
                        if (preg_match('/[a-zA-Z0-9_]/u', $c)) {
                            $ident .= $c;
                            $this->pos++;
                        } else {
                            break;
                        }
                    }

                    $upper = strtoupper($ident);
                    if ($upper === 'SIGMA' || $upper === 'SUM_I') {
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_SIGMA, $ident, $start);
                    } elseif ($upper === 'AND') {
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_AND, 'AND', $start);
                    } elseif ($upper === 'OR') {
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_OR, 'OR', $start);
                    } elseif ($upper === 'NOT') {
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_NOT, 'NOT', $start);
                    } elseif ($upper === 'TO') {
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_RANGE, '..', $start);
                    } else {
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_IDENTIFIER, $ident, $start);
                    }
                    continue;
                }

                $next = $this->charAt($this->pos + 1);
                if ($char === '=' && $next === '=') {
                    $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_EQ, '==', $this->pos);
                    $this->pos += 2;
                    continue;
                }
                if ($char === '!' && $next === '=') {
                    $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_NEQ, '!=', $this->pos);
                    $this->pos += 2;
                    continue;
                }
                if ($char === '<' && $next === '>') {
                    $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_NEQ, '!=', $this->pos);
                    $this->pos += 2;
                    continue;
                }
                if ($char === '<' && $next === '=') {
                    $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_LTE, '<=', $this->pos);
                    $this->pos += 2;
                    continue;
                }
                if ($char === '>' && $next === '=') {
                    $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_GTE, '>=', $this->pos);
                    $this->pos += 2;
                    continue;
                }
                if ($char === '&' && $next === '&') {
                    $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_AND, '&&', $this->pos);
                    $this->pos += 2;
                    continue;
                }
                if ($char === '|' && $next === '|') {
                    $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_OR, '||', $this->pos);
                    $this->pos += 2;
                    continue;
                }

                switch ($char) {
                    case '+':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_PLUS, '+', $this->pos);
                        break;
                    case '-':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_MINUS, '-', $this->pos);
                        break;
                    case '*':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_MUL, '*', $this->pos);
                        break;
                    case '/':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_DIV, '/', $this->pos);
                        break;
                    case '%':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_PERCENT_SYM, '%', $this->pos);
                        break;
                    case '^':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_POWER, '^', $this->pos);
                        break;
                    case '(':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_LPAREN, '(', $this->pos);
                        break;
                    case ')':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_RPAREN, ')', $this->pos);
                        break;
                    case '[':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_LBRACKET, '[', $this->pos);
                        break;
                    case ']':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_RBRACKET, ']', $this->pos);
                        break;
                    case ',':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_COMMA, ',', $this->pos);
                        break;
                    case '=':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_EQ, '==', $this->pos);
                        break;
                    case '<':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_LT, '<', $this->pos);
                        break;
                    case '>':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_GT, '>', $this->pos);
                        break;
                    case '!':
                        $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_NOT, '!', $this->pos);
                        break;
                }
                $this->pos++;
            }

            $tokens[] = new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_EOF, '', $this->pos);
            return $tokens;
        }
    }

    // =============================================================================
    // 2. ABSTRACT SYNTAX TREE (AST NODES)
    // =============================================================================
    abstract class AkurasiTara_DSL_ASTNode {
        abstract public function accept($evaluator);
    }

    class AkurasiTara_DSL_NumberNode extends AkurasiTara_DSL_ASTNode {
        public $value;
        public function __construct($value) {
            $this->value = $value;
        }
        public function accept($evaluator) {
            return $evaluator->visitNumber($this);
        }
    }

    class AkurasiTara_DSL_StringNode extends AkurasiTara_DSL_ASTNode {
        public $value;
        public function __construct($value) {
            $this->value = $value;
        }
        public function accept($evaluator) {
            return $evaluator->visitString($this);
        }
    }

    class AkurasiTara_DSL_VariableNode extends AkurasiTara_DSL_ASTNode {
        public $name;
        public $index_node;
        public function __construct($name, $index_node = null) {
            $this->name = $name;
            $this->index_node = $index_node;
        }
        public function accept($evaluator) {
            return $evaluator->visitVariable($this);
        }
    }

    class AkurasiTara_DSL_BinaryOpNode extends AkurasiTara_DSL_ASTNode {
        public $op;
        public $left;
        public $right;
        public function __construct($op, $left, $right) {
            $this->op    = $op;
            $this->left  = $left;
            $this->right = $right;
        }
        public function accept($evaluator) {
            return $evaluator->visitBinaryOp($this);
        }
    }

    class AkurasiTara_DSL_UnaryOpNode extends AkurasiTara_DSL_ASTNode {
        public $op;
        public $expr;
        public function __construct($op, $expr) {
            $this->op   = $op;
            $this->expr = $expr;
        }
        public function accept($evaluator) {
            return $evaluator->visitUnaryOp($this);
        }
    }

    class AkurasiTara_DSL_FunctionNode extends AkurasiTara_DSL_ASTNode {
        public $name;
        public $args;
        public function __construct($name, array $args = array()) {
            $this->name = strtoupper($name);
            $this->args = $args;
        }
        public function accept($evaluator) {
            return $evaluator->visitFunction($this);
        }
    }

    class AkurasiTara_DSL_SigmaNode extends AkurasiTara_DSL_ASTNode {
        public $var_name;
        public $start_node;
        public $end_node;
        public $body_node;
        public function __construct($var_name, $start_node, $end_node, $body_node) {
            $this->var_name   = $var_name;
            $this->start_node = $start_node;
            $this->end_node   = $end_node;
            $this->body_node  = $body_node;
        }
        public function accept($evaluator) {
            return $evaluator->visitSigma($this);
        }
    }

    // =============================================================================
    // 3. RECURSIVE DESCENT PARSER
    // =============================================================================
    class AkurasiTara_DSL_Parser {
        private $tokens;
        private $pos;
        private $curr;

        public function __construct(array $tokens) {
            $this->tokens = $tokens;
            $this->pos    = 0;
            $this->curr   = isset($tokens[0]) ? $tokens[0] : new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_EOF, '');
        }

        private function match($type) {
            if ($this->curr->type === $type) {
                $matched = $this->curr;
                $this->pos++;
                $this->curr = isset($this->tokens[$this->pos]) ? $this->tokens[$this->pos] : new AkurasiTara_DSL_Token(AkurasiTara_DSL_Token::T_EOF, '');
                return $matched;
            }
            return null;
        }

        private function expect($type, $errMsg = '') {
            $m = $this->match($type);
            if (!$m) {
                $got = $this->curr->type;
                throw new Exception($errMsg ? $errMsg : "Sintaks formula tidak valid: mengharapkan '{$type}', ditemukan '{$got}'.");
            }
            return $m;
        }

        public function parse() {
            if ($this->curr->type === AkurasiTara_DSL_Token::T_EOF) {
                return new AkurasiTara_DSL_NumberNode(0);
            }
            return $this->parseExpression();
        }

        private function parseExpression() {
            return $this->parseLogicalOr();
        }

        private function parseLogicalOr() {
            $left = $this->parseLogicalAnd();
            while ($this->match(AkurasiTara_DSL_Token::T_OR)) {
                $right = $this->parseLogicalAnd();
                $left  = new AkurasiTara_DSL_BinaryOpNode('OR', $left, $right);
            }
            return $left;
        }

        private function parseLogicalAnd() {
            $left = $this->parseEquality();
            while ($this->match(AkurasiTara_DSL_Token::T_AND)) {
                $right = $this->parseEquality();
                $left  = new AkurasiTara_DSL_BinaryOpNode('AND', $left, $right);
            }
            return $left;
        }

        private function parseEquality() {
            $left = $this->parseRelational();
            while (true) {
                if ($this->match(AkurasiTara_DSL_Token::T_EQ)) {
                    $right = $this->parseRelational();
                    $left  = new AkurasiTara_DSL_BinaryOpNode('==', $left, $right);
                } elseif ($this->match(AkurasiTara_DSL_Token::T_NEQ)) {
                    $right = $this->parseRelational();
                    $left  = new AkurasiTara_DSL_BinaryOpNode('!=', $left, $right);
                } else {
                    break;
                }
            }
            return $left;
        }

        private function parseRelational() {
            $left = $this->parseAdditive();
            while (true) {
                if ($this->match(AkurasiTara_DSL_Token::T_LTE)) {
                    $right = $this->parseAdditive();
                    $left  = new AkurasiTara_DSL_BinaryOpNode('<=', $left, $right);
                } elseif ($this->match(AkurasiTara_DSL_Token::T_GTE)) {
                    $right = $this->parseAdditive();
                    $left  = new AkurasiTara_DSL_BinaryOpNode('>=', $left, $right);
                } elseif ($this->match(AkurasiTara_DSL_Token::T_LT)) {
                    $right = $this->parseAdditive();
                    $left  = new AkurasiTara_DSL_BinaryOpNode('<', $left, $right);
                } elseif ($this->match(AkurasiTara_DSL_Token::T_GT)) {
                    $right = $this->parseAdditive();
                    $left  = new AkurasiTara_DSL_BinaryOpNode('>', $left, $right);
                } else {
                    break;
                }
            }
            return $left;
        }

        private function parseAdditive() {
            $left = $this->parseMultiplicative();
            while (true) {
                if ($this->match(AkurasiTara_DSL_Token::T_PLUS)) {
                    $right = $this->parseMultiplicative();
                    $left  = new AkurasiTara_DSL_BinaryOpNode('+', $left, $right);
                } elseif ($this->match(AkurasiTara_DSL_Token::T_MINUS)) {
                    $right = $this->parseMultiplicative();
                    $left  = new AkurasiTara_DSL_BinaryOpNode('-', $left, $right);
                } else {
                    break;
                }
            }
            return $left;
        }

        private function parseMultiplicative() {
            $left = $this->parsePower();
            while (true) {
                if ($this->match(AkurasiTara_DSL_Token::T_MUL)) {
                    $right = $this->parsePower();
                    $left  = new AkurasiTara_DSL_BinaryOpNode('*', $left, $right);
                } elseif ($this->match(AkurasiTara_DSL_Token::T_DIV)) {
                    $right = $this->parsePower();
                    $left  = new AkurasiTara_DSL_BinaryOpNode('/', $left, $right);
                } elseif ($this->match(AkurasiTara_DSL_Token::T_MOD)) {
                    $right = $this->parsePower();
                    $left  = new AkurasiTara_DSL_BinaryOpNode('%', $left, $right);
                } else {
                    break;
                }
            }
            return $left;
        }

        private function parsePower() {
            $left = $this->parseUnary();
            while ($this->match(AkurasiTara_DSL_Token::T_POWER)) {
                $right = $this->parseUnary();
                $left  = new AkurasiTara_DSL_BinaryOpNode('^', $left, $right);
            }
            return $left;
        }

        private function parseUnary() {
            if ($this->match(AkurasiTara_DSL_Token::T_MINUS)) {
                $expr = $this->parseUnary();
                return new AkurasiTara_DSL_UnaryOpNode('-', $expr);
            }
            if ($this->match(AkurasiTara_DSL_Token::T_PLUS)) {
                return $this->parseUnary();
            }
            if ($this->match(AkurasiTara_DSL_Token::T_NOT)) {
                $expr = $this->parseUnary();
                return new AkurasiTara_DSL_UnaryOpNode('NOT', $expr);
            }
            return $this->parsePostfix();
        }

        private function parsePostfix() {
            $expr = $this->parsePrimary();
            if ($this->match(AkurasiTara_DSL_Token::T_PERCENT_SYM)) {
                $expr = new AkurasiTara_DSL_BinaryOpNode('/', $expr, new AkurasiTara_DSL_NumberNode(100));
            }
            return $expr;
        }

        private function parsePrimary() {
            if ($tok = $this->match(AkurasiTara_DSL_Token::T_NUMBER)) {
                return new AkurasiTara_DSL_NumberNode($tok->value);
            }

            if ($tok = $this->match(AkurasiTara_DSL_Token::T_STRING)) {
                return new AkurasiTara_DSL_StringNode($tok->value);
            }

            if ($this->match(AkurasiTara_DSL_Token::T_LPAREN)) {
                $expr = $this->parseExpression();
                $this->expect(AkurasiTara_DSL_Token::T_RPAREN, "Tanda kurung buka '(' tidak memiliki pasangan kurung tutup ')'.");
                return $expr;
            }

            if ($this->match(AkurasiTara_DSL_Token::T_SIGMA)) {
                return $this->parseSigmaConstruct();
            }

            if ($tok = $this->match(AkurasiTara_DSL_Token::T_IDENTIFIER)) {
                $name = $tok->value;

                if ($this->match(AkurasiTara_DSL_Token::T_LPAREN)) {
                    $args = array();
                    if (!$this->match(AkurasiTara_DSL_Token::T_RPAREN)) {
                        while (true) {
                            $args[] = $this->parseExpression();
                            if ($this->match(AkurasiTara_DSL_Token::T_COMMA)) {
                                continue;
                            }
                            break;
                        }
                        $this->expect(AkurasiTara_DSL_Token::T_RPAREN, "Fungsi '{$name}()' kekurangan kurung tutup ')'.");
                    }
                    return new AkurasiTara_DSL_FunctionNode($name, $args);
                }

                if ($this->match(AkurasiTara_DSL_Token::T_LBRACKET)) {
                    $indexNode = $this->parseExpression();
                    $this->expect(AkurasiTara_DSL_Token::T_RBRACKET, "Variabel array '{$name}[' kekurangan kurung siku tutup ']'.");
                    return new AkurasiTara_DSL_VariableNode($name, $indexNode);
                }

                // Notasi subscript matematika (contoh: n_i, k_i, n_1) hanya untuk variabel tunggal 1 huruf
                if (preg_match('/^([a-zA-Z])_([a-zA-Z0-9]+)$/', $name, $m)) {
                    $base = $m[1];
                    $idxStr = $m[2];
                    $idxNode = is_numeric($idxStr) ? new AkurasiTara_DSL_NumberNode(intval($idxStr)) : new AkurasiTara_DSL_VariableNode($idxStr);
                    return new AkurasiTara_DSL_VariableNode($base, $idxNode);
                }

                return new AkurasiTara_DSL_VariableNode($name);
            }

            $got = $this->curr->type;
            throw new Exception("Ekspresi formula tidak valid di dekat token '{$got}' (posisi {$this->curr->pos}).");
        }

        private function parseSigmaConstruct() {
            $this->expect(AkurasiTara_DSL_Token::T_LPAREN, "Notasi Sigma membutuhkan kurung buka '(' setelah simbol Σ.");

            $loopVar = 'i';
            $startNode = new AkurasiTara_DSL_NumberNode(1);
            $endNode = new AkurasiTara_DSL_VariableNode('l');
            $bodyNode = null;

            if ($this->curr->type === AkurasiTara_DSL_Token::T_IDENTIFIER && isset($this->tokens[$this->pos + 1]) && $this->tokens[$this->pos + 1]->type === AkurasiTara_DSL_Token::T_EQ) {
                $loopVarTok = $this->match(AkurasiTara_DSL_Token::T_IDENTIFIER);
                $loopVar = $loopVarTok->value;
                $this->expect(AkurasiTara_DSL_Token::T_EQ);
                $startNode = $this->parseExpression();
                $this->expect(AkurasiTara_DSL_Token::T_RANGE, "Notasi Sigma membutuhkan range '..' (contoh: {$loopVar}=1..l).");
                $endNode = $this->parseExpression();

                if ($this->match(AkurasiTara_DSL_Token::T_COMMA)) {
                    $bodyNode = $this->parseExpression();
                    $this->expect(AkurasiTara_DSL_Token::T_RPAREN, "Notasi Sigma kekurangan kurung tutup ')'.");
                    return new AkurasiTara_DSL_SigmaNode($loopVar, $startNode, $endNode, $bodyNode);
                }

                $this->expect(AkurasiTara_DSL_Token::T_RPAREN, "Notasi Sigma 'Σ({$loopVar}=...)' kekurangan kurung tutup ')'.");
                $bodyNode = $this->parseMultiplicative();
                return new AkurasiTara_DSL_SigmaNode($loopVar, $startNode, $endNode, $bodyNode);
            }

            if ($this->curr->type === AkurasiTara_DSL_Token::T_NUMBER && isset($this->tokens[$this->pos + 1]) && $this->tokens[$this->pos + 1]->type === AkurasiTara_DSL_Token::T_RANGE) {
                $startNode = $this->parseExpression();
                $this->expect(AkurasiTara_DSL_Token::T_RANGE);
                $endNode = $this->parseExpression();
                if ($this->match(AkurasiTara_DSL_Token::T_COMMA)) {
                    $bodyNode = $this->parseExpression();
                    $this->expect(AkurasiTara_DSL_Token::T_RPAREN);
                    return new AkurasiTara_DSL_SigmaNode($loopVar, $startNode, $endNode, $bodyNode);
                }
                $this->expect(AkurasiTara_DSL_Token::T_RPAREN);
                $bodyNode = $this->parseMultiplicative();
                return new AkurasiTara_DSL_SigmaNode($loopVar, $startNode, $endNode, $bodyNode);
            }

            $bodyNode = $this->parseExpression();
            $this->expect(AkurasiTara_DSL_Token::T_RPAREN, "Notasi Sigma kekurangan kurung tutup ')'.");
            return new AkurasiTara_DSL_SigmaNode($loopVar, $startNode, $endNode, $bodyNode);
        }
    }

    // =============================================================================
    // 4. CONTEXT & VARIABLE RESOLVER
    // =============================================================================
    class AkurasiTara_DSL_Context {
        public $variables       = array();
        public $survey_values   = array();
        public $questions       = array();
        public $active_tables   = array();
        public $local_scopes    = array();
        public $custom_n        = array();
        public $custom_k        = array();

        public function __construct(array $params = array()) {
            if (isset($params['variables']))     $this->variables     = $params['variables'];
            if (isset($params['survey_values'])) $this->survey_values = $params['survey_values'];
            if (isset($params['questions']))     $this->questions     = $params['questions'];
            if (isset($params['active_tables'])) $this->active_tables = $params['active_tables'];
            if (isset($params['n']))             $this->custom_n      = (array)$params['n'];
            if (isset($params['k']))             $this->custom_k      = (array)$params['k'];

            $this->init_built_in_variables();
        }

        private function init_built_in_variables() {
            if (!isset($this->variables['t'])) {
                $this->variables['t'] = !empty($this->survey_values) ? count($this->survey_values) : (isset($this->variables['total_respondents']) ? $this->variables['total_respondents'] : 0);
            }
            if (!isset($this->variables['N'])) {
                $this->variables['N'] = isset($this->variables['total_populasi']) ? $this->variables['total_populasi'] : 500;
            }
            if (!isset($this->variables['e'])) {
                $this->variables['e'] = isset($this->variables['galat']) ? $this->variables['galat'] : 0.023;
            }

            if (!isset($this->variables['l'])) {
                if (!empty($this->custom_n)) {
                    $this->variables['l'] = count($this->custom_n);
                } elseif (!empty($this->active_tables)) {
                    $first_table = reset($this->active_tables);
                    $items = isset($first_table['items']) ? $first_table['items'] : (isset($first_table['data']) ? $first_table['data'] : array());
                    $this->variables['l'] = !empty($items) ? count($items) : 3;
                } else {
                    $this->variables['l'] = 3;
                }
            }
        }

        public function push_local($var_name, $val) {
            $this->local_scopes[$var_name] = $val;
        }

        public function pop_local($var_name) {
            unset($this->local_scopes[$var_name]);
        }

        public function get_variable($name, $index = null) {
            $upper = strtoupper(trim($name));
            $lower = strtolower(trim($name));

            if ($index === null && isset($this->local_scopes[$name])) {
                return $this->local_scopes[$name];
            }
            if ($index === null && isset($this->local_scopes[$lower])) {
                return $this->local_scopes[$lower];
            }

            if ($index !== null) {
                $idx = is_numeric($index) ? intval($index) : (isset($this->local_scopes[$index]) ? intval($this->local_scopes[$index]) : 1);

                if ($lower === 'n') {
                    if (isset($this->custom_n[$idx])) return $this->custom_n[$idx];
                    if (isset($this->custom_n[$idx - 1])) return $this->custom_n[$idx - 1];
                    return $this->resolve_category_n($idx);
                }

                if ($lower === 'k' || $lower === 'w') {
                    if (isset($this->custom_k[$idx])) return $this->custom_k[$idx];
                    if (isset($this->custom_k[$idx - 1])) return $this->custom_k[$idx - 1];
                    return $this->resolve_category_k($idx);
                }

                if ($lower === 'q' || $lower === 't' || $lower === 'p') {
                    return $this->get_column_aggregation('AVG', 'T' . $idx);
                }
            }

            if (isset($this->variables[$name]))  return $this->variables[$name];
            if (isset($this->variables[$lower])) return $this->variables[$lower];
            if (isset($this->variables[$upper])) return $this->variables[$upper];

            if (preg_match('/^(SUM|AVG|AVERAGE|COUNT|MIN|MAX)_(.+)$/i', $name, $m)) {
                $func = strtoupper($m[1]);
                $col  = strtoupper($m[2]);
                return $this->get_column_aggregation($func, $col);
            }

            if (preg_match('/^(Q|T|P)(\d+)$/i', $name, $m)) {
                return $this->get_column_aggregation('AVG', 'T' . $m[2]);
            }

            return 0;
        }

        private function resolve_category_n($idx_1_based) {
            if (empty($this->active_tables) || empty($this->survey_values)) {
                $mock = array(1 => 20, 2 => 30, 3 => 50);
                return isset($mock[$idx_1_based]) ? $mock[$idx_1_based] : 0;
            }

            $table = reset($this->active_tables);
            $items = isset($table['items']) ? array_values($table['items']) : array();
            $target_idx = $idx_1_based - 1;
            if (!isset($items[$target_idx])) return 0;

            $target_key = strtolower(trim($items[$target_idx]['key'] ?? ''));
            $target_q   = strtoupper($table['question_code'] ?? 'T4');
            if (strpos($target_q, 'P') === 0) $target_q = 'T' . substr($target_q, 1);
            $qid        = $this->resolve_qid_from_code($target_q);

            $cnt = 0;
            foreach ($this->survey_values as $r) {
                $ans = ($qid > 0 && isset($r['answers'][$qid])) ? $r['answers'][$qid] : '';
                $ans_arr = is_array($ans) ? $ans : array($ans);
                foreach ($ans_arr as $a) {
                    if (strcasecmp(trim($a), $target_key) === 0) {
                        $cnt++;
                        break;
                    }
                }
            }
            return $cnt;
        }

        private function resolve_category_k($idx_1_based) {
            if (empty($this->active_tables)) {
                $mock = array(1 => 1.0, 2 => 0.8, 3 => 0.6);
                return isset($mock[$idx_1_based]) ? $mock[$idx_1_based] : 0;
            }

            $table = reset($this->active_tables);
            $items = isset($table['items']) ? array_values($table['items']) : array();
            $target_idx = $idx_1_based - 1;
            if (!isset($items[$target_idx])) return 0;

            $val = $items[$target_idx]['raw_val'] ?? ($items[$target_idx]['val'] ?? 0);
            if (is_numeric($val)) return floatval($val);
            $clean = preg_replace('/[^0-9\.\-]/', '', (string)$val);
            return is_numeric($clean) && $clean !== '' ? floatval($clean) : 0;
        }

        public function resolve_qid_from_code($code) {
            $c = strtoupper(trim($code));
            if (empty($this->questions)) {
                if (preg_match('/^(Q|T|P)(\d+)$/i', $c, $m)) return intval($m[2]);
                return 0;
            }
            foreach ($this->questions as $idx => $q) {
                $tCode = 'T' . ($idx + 1);
                $pCode = 'P' . ($idx + 1);
                $qCode = 'Q' . intval($q->id);
                if ($c === $tCode || $c === $pCode || $c === $qCode || $c === (string)$q->id) {
                    return intval($q->id);
                }
            }
            if (preg_match('/^(Q|T|P)(\d+)$/i', $c, $m)) {
                $num = intval($m[2]);
                if (isset($this->questions[$num - 1])) {
                    return intval($this->questions[$num - 1]->id);
                }
            }
            return 0;
        }

        public function get_column_values($col_code) {
            $c = strtoupper(trim($col_code));
            $vals = array();

            if (empty($this->survey_values)) return array();

            $ref_idx = count($this->questions);
            if (!empty($this->active_tables)) {
                foreach ($this->active_tables as $tid => $tconf) {
                    $ref_idx++;
                    $codeKey = 'T' . $ref_idx;
                    if ($c === $codeKey || strtoupper($tconf['title']) === $c) {
                        $target_q = strtoupper($tconf['question_code'] ?? 'T4');
                        $t_qid = $this->resolve_qid_from_code($target_q);
                        $r_data = $tconf['data'] ?? array();
                        foreach ($this->survey_values as $r) {
                            $raw = ($t_qid > 0 && isset($r['answers'][$t_qid])) ? (is_array($r['answers'][$t_qid]) ? implode(' ', $r['answers'][$t_qid]) : (string)$r['answers'][$t_qid]) : '';
                            $num = 0;
                            if (isset($r_data[$raw]) && is_numeric($r_data[$raw])) {
                                $num = floatval($r_data[$raw]);
                            } else {
                                foreach ($r_data as $k => $v) {
                                    if (strcasecmp(trim($k), trim($raw)) === 0 && is_numeric($v)) {
                                        $num = floatval($v);
                                        break;
                                    }
                                }
                            }
                            $vals[] = $num;
                        }
                        return $vals;
                    }
                }
            }

            $qid = $this->resolve_qid_from_code($c);
            foreach ($this->survey_values as $r) {
                if ($qid > 0 && isset($r['answers'][$qid])) {
                    $ans = is_array($r['answers'][$qid]) ? implode(' ', $r['answers'][$qid]) : (string)$r['answers'][$qid];
                    if (preg_match('/([\d\.]+)\s*juta/i', $ans, $m)) {
                        $vals[] = floatval($m[1]) * 1000000;
                    } elseif (preg_match('/(\d+)\s*bulan/i', $ans, $m)) {
                        $vals[] = floatval($m[1]);
                    } else {
                        $cleaned = preg_replace('/[^0-9\.\-]/', '', $ans);
                        $vals[] = ($cleaned !== '' && is_numeric($cleaned)) ? floatval($cleaned) : 0;
                    }
                } else {
                    $vals[] = 0;
                }
            }
            return $vals;
        }

        public function get_column_aggregation($func, $col_code) {
            $vals = $this->get_column_values($col_code);
            if (empty($vals)) {
                if (isset($this->variables[$col_code])) return floatval($this->variables[$col_code]);
                return 0;
            }

            switch (strtoupper($func)) {
                case 'SUM':
                    return array_sum($vals);
                case 'AVG':
                case 'AVERAGE':
                    return !empty($vals) ? (array_sum($vals) / count($vals)) : 0;
                case 'COUNT':
                    return count($vals);
                case 'MIN':
                    return !empty($vals) ? min($vals) : 0;
                case 'MAX':
                    return !empty($vals) ? max($vals) : 0;
                default:
                    return array_sum($vals);
            }
        }
    }

    // =============================================================================
    // 5. AST EVALUATOR (SAFE EXECUTION WITHOUT EVAL)
    // =============================================================================
    class AkurasiTara_DSL_Evaluator {
        private $context;

        public function __construct(AkurasiTara_DSL_Context $context) {
            $this->context = $context;
        }

        public function evaluate(AkurasiTara_DSL_ASTNode $node) {
            return $node->accept($this);
        }

        public function visitNumber(AkurasiTara_DSL_NumberNode $node) {
            return $node->value;
        }

        public function visitString(AkurasiTara_DSL_StringNode $node) {
            return $node->value;
        }

        public function visitVariable(AkurasiTara_DSL_VariableNode $node) {
            $idxVal = null;
            if ($node->index_node !== null) {
                $idxVal = $this->evaluate($node->index_node);
            }
            return $this->context->get_variable($node->name, $idxVal);
        }

        public function visitUnaryOp(AkurasiTara_DSL_UnaryOpNode $node) {
            $val = $this->evaluate($node->expr);
            switch ($node->op) {
                case '-':
                    return -$val;
                case '+':
                    return +$val;
                case 'NOT':
                    return !$val ? 1 : 0;
                default:
                    return $val;
            }
        }

        public function visitBinaryOp(AkurasiTara_DSL_BinaryOpNode $node) {
            $left  = $this->evaluate($node->left);
            $right = $this->evaluate($node->right);

            switch ($node->op) {
                case '+':
                    return $left + $right;
                case '-':
                    return $left - $right;
                case '*':
                    return $left * $right;
                case '/':
                    if ($right == 0) return 0;
                    return $left / $right;
                case '%':
                    if ($right == 0) return 0;
                    return fmod(floatval($left), floatval($right));
                case '^':
                    return pow($left, $right);
                case '==':
                    return ($left == $right) ? 1 : 0;
                case '!=':
                    return ($left != $right) ? 1 : 0;
                case '<':
                    return ($left < $right) ? 1 : 0;
                case '<=':
                    return ($left <= $right) ? 1 : 0;
                case '>':
                    return ($left > $right) ? 1 : 0;
                case '>=':
                    return ($left >= $right) ? 1 : 0;
                case 'AND':
                    return ($left && $right) ? 1 : 0;
                case 'OR':
                    return ($left || $right) ? 1 : 0;
                default:
                    return 0;
            }
        }

        public function visitFunction(AkurasiTara_DSL_FunctionNode $node) {
            $name = $node->name;
            $args = $node->args;

            switch ($name) {
                case 'ROUND':
                    $val  = isset($args[0]) ? floatval($this->evaluate($args[0])) : 0;
                    $prec = isset($args[1]) ? intval($this->evaluate($args[1])) : 0;
                    return round($val, $prec);

                case 'ABS':
                    $val = isset($args[0]) ? floatval($this->evaluate($args[0])) : 0;
                    return abs($val);

                case 'SQRT':
                    $val = isset($args[0]) ? floatval($this->evaluate($args[0])) : 0;
                    return $val >= 0 ? sqrt($val) : 0;

                case 'FLOOR':
                    $val = isset($args[0]) ? floatval($this->evaluate($args[0])) : 0;
                    return floor($val);

                case 'CEIL':
                case 'CEILING':
                    $val = isset($args[0]) ? floatval($this->evaluate($args[0])) : 0;
                    return ceil($val);

                case 'POW':
                case 'POWER':
                    $base = isset($args[0]) ? floatval($this->evaluate($args[0])) : 0;
                    $exp  = isset($args[1]) ? floatval($this->evaluate($args[1])) : 0;
                    return pow($base, $exp);

                case 'IF':
                    $cond = isset($args[0]) ? $this->evaluate($args[0]) : 0;
                    if ($cond) {
                        return isset($args[1]) ? $this->evaluate($args[1]) : 1;
                    } else {
                        return isset($args[2]) ? $this->evaluate($args[2]) : 0;
                    }

                case 'SUM':
                    if (empty($args)) return 0;
                    $sum = 0;
                    foreach ($args as $a) {
                        $hasCol = false;
                        if ($a instanceof AkurasiTara_DSL_VariableNode && preg_match('/^(Q|T|P)(\d+)$/i', $a->name)) {
                            $colVals = $this->context->get_column_values($a->name);
                            if (!empty($colVals)) {
                                $sum += array_sum($colVals);
                                $hasCol = true;
                            }
                        }
                        if (!$hasCol) {
                            $sum += floatval($this->evaluate($a));
                        }
                    }
                    return $sum;

                case 'AVG':
                case 'AVERAGE':
                    if (empty($args)) return 0;
                    $total = 0;
                    $cnt = 0;
                    foreach ($args as $a) {
                        $hasCol = false;
                        if ($a instanceof AkurasiTara_DSL_VariableNode && preg_match('/^(Q|T|P)(\d+)$/i', $a->name)) {
                            $colVals = $this->context->get_column_values($a->name);
                            if (!empty($colVals)) {
                                $total += array_sum($colVals);
                                $cnt   += count($colVals);
                                $hasCol = true;
                            }
                        }
                        if (!$hasCol) {
                            $total += floatval($this->evaluate($a));
                            $cnt++;
                        }
                    }
                    return $cnt > 0 ? ($total / $cnt) : 0;

                case 'MIN':
                    if (empty($args)) return 0;
                    $vals = array();
                    foreach ($args as $a) {
                        $hasCol = false;
                        if ($a instanceof AkurasiTara_DSL_VariableNode && preg_match('/^(Q|T|P)(\d+)$/i', $a->name)) {
                            $colVals = $this->context->get_column_values($a->name);
                            if (!empty($colVals)) {
                                $vals = array_merge($vals, $colVals);
                                $hasCol = true;
                            }
                        }
                        if (!$hasCol) {
                            $vals[] = floatval($this->evaluate($a));
                        }
                    }
                    return !empty($vals) ? min($vals) : 0;

                case 'MAX':
                    if (empty($args)) return 0;
                    $vals = array();
                    foreach ($args as $a) {
                        $hasCol = false;
                        if ($a instanceof AkurasiTara_DSL_VariableNode && preg_match('/^(Q|T|P)(\d+)$/i', $a->name)) {
                            $colVals = $this->context->get_column_values($a->name);
                            if (!empty($colVals)) {
                                $vals = array_merge($vals, $colVals);
                                $hasCol = true;
                            }
                        }
                        if (!$hasCol) {
                            $vals[] = floatval($this->evaluate($a));
                        }
                    }
                    return !empty($vals) ? max($vals) : 0;

                case 'COUNT':
                    if (empty($args)) {
                        return $this->context->get_variable('t');
                    }
                    $cnt = 0;
                    foreach ($args as $a) {
                        if ($a instanceof AkurasiTara_DSL_VariableNode && preg_match('/^(Q|T|P)(\d+)$/i', $a->name)) {
                            $cnt += $this->context->get_column_aggregation('COUNT', $a->name);
                        } else {
                            $cnt++;
                        }
                    }
                    return $cnt;

                case 'COUNTIF':
                    if (count($args) < 2) return 0;
                    $colNode = $args[0];
                    $targetNode = $args[1];
                    $colName = ($colNode instanceof AkurasiTara_DSL_VariableNode) ? $colNode->name : (string)$this->evaluate($colNode);
                    $targetVal = $this->evaluate($targetNode);

                    $qid = $this->context->resolve_qid_from_code($colName);
                    $count = 0;
                    foreach ($this->context->survey_values as $r) {
                        $ans = ($qid > 0 && isset($r['answers'][$qid])) ? $r['answers'][$qid] : '';
                        $ans_arr = is_array($ans) ? $ans : array($ans);
                        foreach ($ans_arr as $a) {
                            if (strcasecmp(trim($a), trim((string)$targetVal)) === 0) {
                                $count++;
                                break;
                            }
                        }
                    }
                    return $count;

                case 'PERCENT':
                    $countIf = $this->visitFunction(new AkurasiTara_DSL_FunctionNode('COUNTIF', $args));
                    $tot = $this->context->get_variable('t');
                    return $tot > 0 ? (($countIf / $tot) * 100) : 0;

                default:
                    throw new Exception("Fungsi '{$name}()' tidak dikenali dalam engine DSL.");
            }
        }

        public function visitSigma(AkurasiTara_DSL_SigmaNode $node) {
            $start = intval($this->evaluate($node->start_node));
            $end   = intval($this->evaluate($node->end_node));

            if ($start > $end) {
                return 0;
            }

            $totalSum = 0;
            $varName  = $node->var_name;

            for ($i = $start; $i <= $end; $i++) {
                $this->context->push_local($varName, $i);
                $iterResult = $this->evaluate($node->body_node);
                $totalSum += floatval($iterResult);
                $this->context->pop_local($varName);
            }

            return $totalSum;
        }
    }

    // =============================================================================
    // 6. MAIN HIGH-LEVEL FACADE (AkurasiTara_DSL_Engine)
    // =============================================================================
    class AkurasiTara_DSL_Engine {
        public static function execute($formula, array $contextParams = array()) {
            $rawFormula = trim(html_entity_decode((string)$formula, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($rawFormula === '') {
                return array('success' => true, 'value' => 0, 'formatted' => '0', 'error' => '');
            }

            $hasPercentSuffix = (strpos($rawFormula, '%') !== false || stripos($rawFormula, 'PERCENT(') !== false);

            try {
                $lexer = new AkurasiTara_DSL_Lexer($rawFormula);
                $tokens = $lexer->tokenize();

                $parser = new AkurasiTara_DSL_Parser($tokens);
                $ast = $parser->parse();

                $context = new AkurasiTara_DSL_Context($contextParams);
                $evaluator = new AkurasiTara_DSL_Evaluator($context);

                $rawResult = $evaluator->evaluate($ast);

                if (!is_numeric($rawResult) || is_nan($rawResult) || is_infinite($rawResult)) {
                    $rawResult = 0;
                }

                $formatted = '';
                if ($hasPercentSuffix) {
                    $formatted = number_format(floatval($rawResult), 2, ',', '.') . '%';
                } elseif (is_numeric($rawResult) && floor($rawResult) == $rawResult && strpos($rawFormula, '/') === false && stripos($rawFormula, 'ROUND') === false && stripos($rawFormula, 'AVG') === false) {
                    $formatted = (string)intval($rawResult);
                } else {
                    $formatted = number_format(floatval($rawResult), 2, ',', '.');
                }

                return array(
                    'success'   => true,
                    'value'     => $rawResult,
                    'formatted' => $formatted,
                    'error'     => ''
                );
            } catch (Exception $e) {
                return array(
                    'success'   => false,
                    'value'     => 0,
                    'formatted' => 'Error: ' . $e->getMessage(),
                    'error'     => $e->getMessage()
                );
            }
        }

        public static function validate($formula) {
            $raw = trim(html_entity_decode((string)$formula, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($raw === '') {
                return array('valid' => true, 'message' => 'Formula kosong');
            }
            try {
                $lexer = new AkurasiTara_DSL_Lexer($raw);
                $tokens = $lexer->tokenize();
                $parser = new AkurasiTara_DSL_Parser($tokens);
                $ast = $parser->parse();
                return array('valid' => true, 'message' => 'Formula valid');
            } catch (Exception $e) {
                return array('valid' => false, 'message' => $e->getMessage());
            }
        }
    }
}

class AkurasiTara_Ext_Reports_DSL {
    const SLUG_REPORT = 'akurasitara_ext_reports';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_akurasitara_save_ump_table', array($this, 'ajax_save_ump_table'));
        add_action('wp_ajax_akurasitara_switch_ref_table', array($this, 'ajax_switch_ref_table'));
        add_action('wp_ajax_akurasitara_toggle_ref_table', array($this, 'ajax_toggle_ref_table'));
        add_action('wp_ajax_akurasitara_delete_ref_table', array($this, 'ajax_delete_ref_table'));
        add_action('admin_post_at_ext_report_export', array($this, 'handle_export'));
        add_action('admin_init', array($this, 'handle_form_actions'));
        add_filter('admin_footer_text', array($this, 'filter_admin_footer'), 9999);
        add_filter('update_footer', array($this, 'filter_admin_footer'), 9999);
    }

    private function db() { global $wpdb; return $wpdb; }
    private function tables() {
        $p = $this->db()->prefix . 'akurasitara_';
        return (object) array(
            'runs'             => $p . 'runs',
            'surveys'          => $p . 'surveys',
            'questions'        => $p . 'questions',
            'answers'          => $p . 'answers',
            'responses'        => $p . 'responses',
            'users'            => $p . 'users',
            'user_identity'    => $p . 'user_identity',
            'id_elements'      => $p . 'id_elements',
            'unit_heads'       => $p . 'unit_heads',
            'data_processors'  => $p . 'data_processors',
            'reports'          => $p . 'reports',
            'report_sections'  => $p . 'report_sections',
            'units'            => $p . 'units'
        );
    }

    private function ensure_reports_tables() {
        $db = $this->db(); $t = $this->tables();
        $db->query("CREATE TABLE IF NOT EXISTS {$t->reports} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            run_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            question_ids TEXT NULL,
            formula TEXT NULL,
            calc_method VARCHAR(50) DEFAULT 'dsl',
            settings_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) DEFAULT CHARSET=utf8mb4;");

        $db->query("CREATE TABLE IF NOT EXISTS {$t->report_sections} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT(20) UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            formula TEXT NOT NULL,
            sort_order INT(11) DEFAULT 1,
            show_in_table INT(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY report_id (report_id)
        ) DEFAULT CHARSET=utf8mb4;");

        $cols = $db->get_results("SHOW COLUMNS FROM {$t->report_sections} LIKE 'show_in_table'");
        if (empty($cols)) {
            $db->query("ALTER TABLE {$t->report_sections} ADD COLUMN show_in_table INT(1) DEFAULT 1;");
        }

        $col_settings = $db->get_results("SHOW COLUMNS FROM {$t->reports} LIKE 'settings_json'");
        if (empty($col_settings)) {
            $db->query("ALTER TABLE {$t->reports} ADD COLUMN settings_json LONGTEXT NULL;");
        }

        // Otomatis bersihkan entitas HTML lama (&lt; dan &gt;) yang merusak parsing formula
        $db->query("UPDATE {$t->report_sections} SET formula = REPLACE(REPLACE(formula, '&lt;', '<'), '&gt;', '>') WHERE formula LIKE '%&lt;%' OR formula LIKE '%&gt;%'");
        $db->query("UPDATE {$t->reports} SET formula = REPLACE(REPLACE(formula, '&lt;', '<'), '&gt;', '>') WHERE formula LIKE '%&lt;%' OR formula LIKE '%&gt;%'");
    }

    public function filter_admin_footer($text) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos($page, 'akurasitara') !== false) {
            return '';
        }
        return $text;
    }

    public function add_admin_menu() {
        add_submenu_page('akurasitara', 'Laporan DSL & Acuan', 'Laporan DSL & Acuan', 'read', self::SLUG_REPORT, array($this, 'page_reports'));
    }

    public function can_manage_reports() {
        if (!is_user_logged_in()) return false;
        if (current_user_can('administrator') || current_user_can('manage_options')) return true;
        $uid = get_current_user_id(); $t = $this->tables(); $db = $this->db();
        return ($db->get_var($db->prepare("SELECT unit_id FROM {$t->unit_heads} WHERE wp_user_id=%d LIMIT 1", $uid)) || $db->get_var($db->prepare("SELECT unit_id FROM {$t->data_processors} WHERE wp_user_id=%d LIMIT 1", $uid))) ? true : false;
    }

    public function all_runs() {
        $t = $this->tables();
        return (array) $this->db()->get_results("SELECT r.*, COALESCE(s.title, r.run_name, CONCAT('Run #', r.id)) AS survey_title, u.name AS unit_name FROM {$t->runs} r LEFT JOIN {$t->surveys} s ON r.survey_id=s.id LEFT JOIN {$t->units} u ON r.unit_id=u.id ORDER BY r.id DESC");
    }

    public function run_questions($run_id) {
        $t = $this->tables(); $db = $this->db();
        $run_id = intval($run_id);
        if ($run_id <= 0) return array();

        $run = $db->get_row($db->prepare("SELECT survey_id, id FROM {$t->runs} WHERE id=%d", $run_id));
        $sid = intval($run->survey_id ?? 0);

        // 1. Coba cari pertanyaan berdasarkan survey_id pada run
        $qs = array();
        if ($sid > 0) {
            $qs = (array) $db->get_results($db->prepare("SELECT * FROM {$t->questions} WHERE survey_id=%d ORDER BY sort_order ASC, id ASC", $sid));
        }

        // 2. Fallback: jika kosong, coba cari dari survey_id = run_id
        if (empty($qs)) {
            $qs = (array) $db->get_results($db->prepare("SELECT * FROM {$t->questions} WHERE survey_id=%d ORDER BY sort_order ASC, id ASC", $run_id));
        }

        // 3. Fallback: jika masih kosong, cari semua question_id yang ada di jawaban responden run ini
        if (empty($qs)) {
            $q_ids = $db->get_col($db->prepare("SELECT DISTINCT a.question_id FROM {$t->answers} a JOIN {$t->responses} r ON a.response_id=r.id WHERE r.run_id=%d AND a.question_id > 0", $run_id));
            if (!empty($q_ids)) {
                $in_q = implode(',', array_map('intval', $q_ids));
                $qs = (array) $db->get_results("SELECT * FROM {$t->questions} WHERE id IN ($in_q) ORDER BY sort_order ASC, id ASC");
            }
        }
        return $qs;
    }

    public function question_code($run_id, $qid) {
        foreach ($this->run_questions($run_id) as $idx => $q) {
            if (intval($q->id) === intval($qid)) return 'T' . ($idx + 1);
        }
        return 'T' . $qid;
    }

    // Helper perapian kata / kriteria
    public function clean_criteria_text($str) {
        $s = trim((string)$str);
        if ($s === '' || $s === '-') return '';
        $s = preg_replace('/^(kabupaten|kab\.|kab|kota|provinsi|prov\.)\s+/i', '', $s);
        $s = trim($s);
        return ucwords(strtolower($s));
    }

    private function render_th($h, $is_ref = false) {
        if ($h === 'Hasil') return '';
        if (preg_match('/^(T\d+|P\d+|Q\d+)\s*\((.*)\)$/s', $h, $m)) {
            $code = strtoupper($m[1]);
            $num = preg_replace('/[^0-9]/', '', $code);
            $display_badge = 'T' . $num . ' = TABEL ' . $num;
            $badge_bg = $is_ref ? '#16a34a' : '#0284c7';
            return '<th style="vertical-align:top;min-width:140px;max-width:240px;padding:8px 10px"><span style="display:inline-block;background:' . $badge_bg . ';color:#fff;font-size:11px;font-weight:700;padding:3px 8px;border-radius:4px;margin-bottom:4px;letter-spacing:0.5px">' . esc_html($display_badge) . '</span><div style="font-size:11px;color:#334155;font-weight:500;line-height:1.3;white-space:normal">' . esc_html($m[2]) . '</div></th>';
        }
        $w = ($h === 'No') ? 'style="width:45px;vertical-align:top;padding:8px 10px"' : 'style="min-width:110px;vertical-align:top;padding:8px 10px"';
        return '<th ' . $w . '><span style="font-weight:700;color:#0f172a;font-size:12px">' . esc_html($h) . '</span></th>';
    }

    // =========================================================================
    // CRUD TABEL ACUAN VALUE MANDIRI (TIPE DATA: CURRENCY, NUMBER, STRING, BOOLEAN, PERCENTAGE) - MULTI-AKTIF
    // =========================================================================
    public function get_ref_tables_data() {
        $saved = get_option('akurasitara_saved_ref_tables', null);
        if (is_array($saved) && isset($saved['tables']) && is_array($saved['tables'])) {
            // Normalisasi struktur multi-aktif
            if (!isset($saved['active_ids']) || !is_array($saved['active_ids'])) {
                $saved['active_ids'] = array();
                if (isset($saved['active_id']) && $saved['active_id'] !== 'none' && isset($saved['tables'][$saved['active_id']])) {
                    $saved['active_ids'][] = $saved['active_id'];
                }
            }
            if (empty($saved['editing_id']) || !isset($saved['tables'][$saved['editing_id']])) {
                $saved['editing_id'] = !empty($saved['active_ids']) ? reset($saved['active_ids']) : (!empty($saved['tables']) ? array_key_first($saved['tables']) : 'none');
            }
            return $saved;
        }
        $def = array(
            'active_ids' => array(),
            'editing_id' => 'none',
            'tables'     => array()
        );
        update_option('akurasitara_saved_ref_tables', $def);
        return $def;
    }

    public function get_active_ref_table_ids() {
        $d = $this->get_ref_tables_data();
        $ids = array();
        if (isset($d['active_ids']) && is_array($d['active_ids'])) {
            foreach ($d['active_ids'] as $aid) {
                if (isset($d['tables'][$aid])) $ids[] = $aid;
            }
        } elseif (isset($d['active_id']) && $d['active_id'] !== 'none' && isset($d['tables'][$d['active_id']])) {
            $ids[] = $d['active_id'];
        }
        return array_values(array_unique($ids));
    }

    public function get_active_ref_tables() {
        $d = $this->get_ref_tables_data();
        $active_ids = $this->get_active_ref_table_ids();
        $actives = array();
        foreach ($active_ids as $aid) {
            if (!empty($d['tables'][$aid])) {
                $t = $d['tables'][$aid];
                if (isset($t['question_code']) && strpos($t['question_code'], 'P') === 0) {
                    $t['question_code'] = 'T' . substr($t['question_code'], 1);
                }
                if (empty($t['data_type'])) {
                    $t['data_type'] = 'currency';
                }
                $actives[$aid] = $t;
            }
        }
        return $actives;
    }

    public function get_editing_ref_table() {
        $d = $this->get_ref_tables_data();
        $eid = $d['editing_id'] ?? '';
        if ($eid && isset($d['tables'][$eid])) {
            $t = $d['tables'][$eid];
            if (isset($t['question_code']) && strpos($t['question_code'], 'P') === 0) {
                $t['question_code'] = 'T' . substr($t['question_code'], 1);
            }
            if (empty($t['data_type'])) {
                $t['data_type'] = 'currency';
            }
            return $t;
        }
        $actives = $this->get_active_ref_tables();
        if (!empty($actives)) return reset($actives);
        if (!empty($d['tables'])) return reset($d['tables']);
        return array('id' => 'none', 'title' => 'Tanpa Tabel Acuan', 'question_code' => '', 'data_type' => 'string', 'items' => array(), 'data' => array());
    }

    public function get_ump_settings($table_id = null) {
        $d = $this->get_ref_tables_data();
        if ($table_id && isset($d['tables'][$table_id])) {
            $t = $d['tables'][$table_id];
            if (isset($t['question_code']) && strpos($t['question_code'], 'P') === 0) {
                $t['question_code'] = 'T' . substr($t['question_code'], 1);
            }
            if (empty($t['data_type'])) {
                $t['data_type'] = 'currency';
            }
            return $t;
        }
        return $this->get_editing_ref_table();
    }

    public function get_normalized_ref_items($table_conf) {
        if (!empty($table_conf['items']) && is_array($table_conf['items'])) {
            return $table_conf['items'];
        }
        $items = array();
        if (!empty($table_conf['data']) && is_array($table_conf['data'])) {
            foreach ($table_conf['data'] as $k => $v) {
                $clean_k = $this->clean_criteria_text($k) ?: (string)$k;
                $items[] = array('key' => $clean_k, 'val' => (string)$v, 'raw_val' => $v);
            }
        }
        return $items;
    }

    // LOOKUP ACUAN (CASE-INSENSITIVE, SUBSTRING, CLEAN-WORD AWARE)
    public function lookup_ref_val($key, $data = array(), $default = 0) {
        if (empty($data) || !is_array($data)) return $default;
        $k_trim = trim((string)$key);
        if ($k_trim === '') return $default;

        $parse_num = function($v) {
            if (is_numeric($v)) return floatval($v);
            $v_str = trim((string)$v);
            $clean = preg_replace('/[^0-9,\.]/', '', $v_str);
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
            if (is_numeric($clean) && $clean !== '') return floatval($clean);
            return null;
        };

        // 1. Exact match
        if (isset($data[$k_trim])) {
            $num = $parse_num($data[$k_trim]);
            if ($num !== null) return $num;
        }

        // 2. Cleaned word match
        $clean_k = $this->clean_criteria_text($k_trim);
        if ($clean_k !== '' && isset($data[$clean_k])) {
            $num = $parse_num($data[$clean_k]);
            if ($num !== null) return $num;
        }

        // 3. Case-insensitive match
        $k_lower = strtolower($k_trim);
        $clean_k_lower = strtolower($clean_k);
        foreach ($data as $dk => $dv) {
            $dk_lower = strtolower(trim($dk));
            if ($dk_lower === $k_lower || ($clean_k_lower !== '' && $dk_lower === $clean_k_lower)) {
                $num = $parse_num($dv);
                if ($num !== null) return $num;
            }
        }

        // 4. Substring match
        foreach ($data as $dk => $dv) {
            $dk_lower = strtolower(trim($dk));
            if ($dk_lower !== '' && (strpos($k_lower, $dk_lower) !== false || strpos($dk_lower, $k_lower) !== false || ($clean_k_lower !== '' && strpos($clean_k_lower, $dk_lower) !== false))) {
                $num = $parse_num($dv);
                if ($num !== null) return $num;
            }
        }
        return $default;
    }

    public function lookup_ref_raw($key, $data = array()) {
        if (empty($data) || !is_array($data)) return '';
        $k_trim = trim((string)$key);
        if ($k_trim === '') return '';
        if (isset($data[$k_trim])) return (string)$data[$k_trim];

        $clean_k = $this->clean_criteria_text($k_trim);
        if ($clean_k !== '' && isset($data[$clean_k])) return (string)$data[$clean_k];

        $k_lower = strtolower($k_trim);
        $clean_k_lower = strtolower($clean_k);
        foreach ($data as $dk => $dv) {
            $dk_lower = strtolower(trim($dk));
            if ($dk_lower === $k_lower || ($clean_k_lower !== '' && $dk_lower === $clean_k_lower)) return (string)$dv;
        }
        foreach ($data as $dk => $dv) {
            $dk_lower = strtolower(trim($dk));
            if ($dk_lower !== '' && (strpos($k_lower, $dk_lower) !== false || strpos($dk_lower, $k_lower) !== false || ($clean_k_lower !== '' && strpos($clean_k_lower, $dk_lower) !== false))) return (string)$dv;
        }
        return '';
    }

    public function lookup_ref_item_info($key, $items = array()) {
        if (empty($items) || !is_array($items)) return null;
        $k_lower = strtolower(trim((string)$key));
        $clean_k_lower = strtolower($this->clean_criteria_text($key));
        if ($k_lower === '') return null;

        foreach ($items as $it) {
            $it_k = strtolower(trim($it['key'] ?? ''));
            if ($it_k === $k_lower || ($clean_k_lower !== '' && $it_k === $clean_k_lower)) return $it;
        }
        foreach ($items as $it) {
            $it_k = strtolower(trim($it['key'] ?? ''));
            if ($it_k !== '' && (strpos($k_lower, $it_k) !== false || strpos($it_k, $k_lower) !== false || ($clean_k_lower !== '' && strpos($clean_k_lower, $it_k) !== false))) return $it;
        }
        return null;
    }

    /**
     * Normalisasi identifier tipe data lama ke tipe standar baru.
     * Menjaga backward compatibility data tersimpan sebelum pembaruan.
     *
     * Mapping:
     *   float / double / number → decimal
     *   int                    → integer
     *   char / character       → string
     *   array / object / null  → string
     */
    public function normalize_datatype($data_type) {
        $dtype = strtolower(trim((string)$data_type));
        $map = array(
            'float'     => 'decimal',
            'double'    => 'decimal',
            'number'    => 'decimal',
            'int'       => 'integer',
            'char'      => 'string',
            'character' => 'string',
            'array'     => 'string',
            'object'    => 'string',
            'null'      => 'string',
        );
        return $map[$dtype] ?? $dtype;
    }

    /**
     * Mengembalikan label UI profesional untuk sebuah tipe data.
     */
    public function get_datatype_label($data_type) {
        $dtype = $this->normalize_datatype($data_type);
        $labels = array(
            'currency'   => 'Currency — Nominal Mata Uang (Rp)',
            'integer'    => 'Integer — Bilangan Bulat',
            'decimal'    => 'Decimal — Bilangan Desimal',
            'percentage' => 'Percentage — Persentase (%)',
            'string'     => 'String — Teks',
            'boolean'    => 'Boolean — Benar / Salah',
            'date'       => 'Date — Tanggal',
            'time'       => 'Time — Waktu',
            'datetime'   => 'DateTime — Tanggal dan Waktu',
        );
        return $labels[$dtype] ?? ucfirst($dtype);
    }

    /**
     * Format nilai acuan untuk tampilan di tabel.
     * Nilai numerik (currency, percentage, decimal, integer) tetap
     * disimpan sebagai angka di DB; fungsi ini hanya memformat tampilan.
     */
    public function format_ref_cell_value($raw_val, $num_val = 0, $data_type = 'currency') {
        $dtype = $this->normalize_datatype($data_type);
        $raw_str = trim((string)$raw_val);

        // Boolean
        if ($dtype === 'boolean') {
            $truthy = in_array(strtolower($raw_str), array('1', 'true', 'ya', 'yes', 'y'), true) || $num_val == 1;
            return $truthy ? 'True' : 'False';
        }

        // Currency — tampilan Rp, DSL pakai angka
        if ($dtype === 'currency') {
            $n = is_numeric($raw_str) ? floatval($raw_str) : floatval($num_val);
            if ($n != 0) return 'Rp ' . number_format($n, 0, ',', '.');
            return $raw_str !== '' ? $raw_str : '-';
        }

        // Integer
        if ($dtype === 'integer') {
            if (is_numeric($raw_str)) return (string)intval($raw_str);
            if ($num_val != 0) return (string)intval($num_val);
            return $raw_str !== '' ? $raw_str : '0';
        }

        // Decimal
        if ($dtype === 'decimal') {
            if (is_numeric($raw_str)) {
                $f = floatval($raw_str);
                return (floor($f) == $f) ? number_format($f, 0, '.', '') : rtrim(number_format($f, 6, '.', ''), '0');
            }
            if ($num_val != 0) return rtrim(number_format(floatval($num_val), 6, '.', ''), '0');
            return $raw_str !== '' ? $raw_str : '0';
        }

        // Percentage — tampilan %, DSL pakai angka
        if ($dtype === 'percentage') {
            $clean = rtrim($raw_str, '%');
            if (is_numeric($clean)) return rtrim(rtrim(number_format(floatval($clean), 2, '.', ''), '0'), '.') . '%';
            if ($num_val != 0) return rtrim(rtrim(number_format(floatval($num_val), 2, '.', ''), '0'), '.') . '%';
            return $raw_str !== '' ? $raw_str : '-';
        }

        // Date — format tampilan d-m-Y, simpan Y-m-d
        if ($dtype === 'date') {
            if ($raw_str !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw_str)) {
                return date('d-m-Y', strtotime($raw_str));
            }
            return $raw_str !== '' ? $raw_str : '-';
        }

        // Time
        if ($dtype === 'time') {
            return $raw_str !== '' ? $raw_str : '-';
        }

        // DateTime — format tampilan d-m-Y H:i, simpan Y-m-d H:i:s
        if ($dtype === 'datetime') {
            if ($raw_str !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw_str)) {
                return date('d-m-Y H:i', strtotime($raw_str));
            }
            return $raw_str !== '' ? $raw_str : '-';
        }

        // String / fallback
        return $raw_str !== '' ? $raw_str : ($num_val != 0 ? (string)$num_val : '-');
    }

    public function ajax_save_ump_table() {
        if (!$this->can_manage_reports() || !check_ajax_referer('at_ext_save_report', 'nonce', false)) wp_send_json_error();
        $d = $this->get_ref_tables_data();
        $tid = sanitize_key($_POST['table_id'] ?? '');
        $is_new = !empty($_POST['is_new']);
        if (!$tid && !$is_new && !empty($d['editing_id']) && $d['editing_id'] !== 'none') $tid = $d['editing_id'];
        if (!$tid || $is_new) $tid = 'ref_' . uniqid();

        $items_raw = json_decode(wp_unslash($_POST['ump_items'] ?? '[]'), true);
        $data_type_raw = sanitize_key($_POST['data_type'] ?? 'currency');
        // Normalisasi tipe lama → tipe standar baru (backward compat)
        $data_type = $this->normalize_datatype($data_type_raw);
        // Pastikan hanya tipe valid yang diterima
        $valid_types = array('currency', 'integer', 'decimal', 'percentage', 'string', 'boolean', 'date', 'time', 'datetime');
        if (!in_array($data_type, $valid_types, true)) $data_type = 'currency';

        $clean_items = array();
        $clean_data = array();

        if (is_array($items_raw)) {
            foreach ($items_raw as $it) {
                $raw_k = trim($it['key'] ?? '');
                $k = sanitize_text_field($raw_k);
                if ($k === '') continue;
                $v = sanitize_text_field(trim($it['val'] ?? ''));

                $raw_val = $v;
                if ($v !== '') {
                    if ($data_type === 'currency') {
                        // Strip Rp, spasi, dan separator ribuan titik; ganti koma desimal jadi titik
                        $clean = preg_replace('/[^0-9,\.\-]/', '', $v);
                        // Deteksi format rupiah: jika ada titik lebih dari satu atau pola .000 → strip titik = ribuan
                        if (substr_count($clean, '.') > 1 || preg_match('/\.\d{3}($|[^\d])/', $clean)) {
                            $clean = str_replace('.', '', $clean);
                            $clean = str_replace(',', '.', $clean);
                        } elseif (strpos($clean, ',') !== false) {
                            $clean = str_replace('.', '', $clean);
                            $clean = str_replace(',', '.', $clean);
                        }
                        if (is_numeric($clean) && $clean !== '') {
                            $raw_val = floatval($clean);
                        }
                    } elseif ($data_type === 'decimal') {
                        // Desimal murni — strip semua kecuali angka, titik, minus
                        $clean = preg_replace('/[^0-9\.\-]/', '', $v);
                        if (is_numeric($clean) && $clean !== '') {
                            $raw_val = floatval($clean);
                        }
                    } elseif ($data_type === 'percentage') {
                        // Strip simbol %, simpan sebagai float
                        $clean = rtrim(trim($v), '%');
                        $clean = preg_replace('/[^0-9\.\-]/', '', $clean);
                        if (is_numeric($clean) && $clean !== '') {
                            $raw_val = floatval($clean);
                        }
                    } elseif ($data_type === 'integer') {
                        $clean = preg_replace('/[^0-9\-]/', '', $v);
                        if (is_numeric($clean) && $clean !== '') {
                            $raw_val = intval($clean);
                            $v = (string)$raw_val;
                        }
                    } elseif ($data_type === 'boolean') {
                        $raw_val = (in_array(strtolower($v), array('1', 'true', 'ya', 'yes', 'y'), true)) ? 1 : 0;
                        $v = ($raw_val === 1) ? 'True' : 'False';
                    } elseif ($data_type === 'date') {
                        // Normalisasi ke Y-m-d (input bisa Y-m-d dari date picker atau d-m-Y dari pengguna)
                        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $v, $m)) {
                            $raw_val = $m[3] . '-' . $m[2] . '-' . $m[1]; // d-m-Y → Y-m-d
                        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                            $raw_val = $v; // sudah Y-m-d
                        }
                    } elseif ($data_type === 'time') {
                        // Simpan apa adanya (format H:i atau H:i:s)
                        $raw_val = $v;
                    } elseif ($data_type === 'datetime') {
                        // Normalisasi datetime-local ke Y-m-d H:i:s
                        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', $v, $m)) {
                            $raw_val = $m[1] . ' ' . $m[2] . ':00';
                        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $v)) {
                            $raw_val = $v;
                        }
                    }
                }

                $clean_items[] = array('key' => $k, 'val' => $v, 'raw_val' => $raw_val);
                if ($v !== '') {
                    $clean_data[$k] = $raw_val;
                }
            }
        }

        $qcode = sanitize_text_field(wp_unslash($_POST['ref_question_code'] ?? 'T4'));
        if (strpos($qcode, 'P') === 0) $qcode = 'T' . substr($qcode, 1);

        $d['tables'][$tid] = array(
            'id'            => $tid,
            'title'         => sanitize_text_field(wp_unslash($_POST['ref_column_title'] ?? 'Tabel Acuan')),
            'question_code' => $qcode,
            'data_type'     => $data_type,
            'items'         => $clean_items,
            'data'          => $clean_data
        );
        $d['editing_id'] = $tid;
        if (!isset($d['active_ids']) || !is_array($d['active_ids'])) $d['active_ids'] = array();
        if (!in_array($tid, $d['active_ids'], true)) {
            $d['active_ids'][] = $tid;
        }
        $d['active_id'] = $tid;
        update_option('akurasitara_saved_ref_tables', $d);
        wp_send_json_success(array('message' => 'Tabel Acuan Berhasil Disimpan.', 'all_data' => $d, 'table_id' => $tid));
    }

    public function ajax_switch_ref_table() {
        if (!$this->can_manage_reports() || !check_ajax_referer('at_ext_save_report', 'nonce', false)) wp_send_json_error();
        $tid = sanitize_key($_POST['table_id'] ?? '');
        $d = $this->get_ref_tables_data();
        if ($tid && isset($d['tables'][$tid])) {
            $d['editing_id'] = $tid;
            update_option('akurasitara_saved_ref_tables', $d);
            wp_send_json_success(array('editing_id' => $tid));
        }
        wp_send_json_error();
    }

    public function ajax_toggle_ref_table() {
        if (!$this->can_manage_reports() || !check_ajax_referer('at_ext_save_report', 'nonce', false)) wp_send_json_error();
        $tid = sanitize_key($_POST['table_id'] ?? '');
        $action_type = sanitize_key($_POST['toggle_action'] ?? '');
        $d = $this->get_ref_tables_data();
        if (!isset($d['active_ids']) || !is_array($d['active_ids'])) $d['active_ids'] = array();

        if ($action_type === 'enable_all') {
            $d['active_ids'] = array_keys($d['tables'] ?? array());
        } elseif ($action_type === 'disable_all') {
            $d['active_ids'] = array();
        } elseif ($tid && isset($d['tables'][$tid])) {
            if (in_array($tid, $d['active_ids'], true)) {
                $d['active_ids'] = array_values(array_diff($d['active_ids'], array($tid)));
            } else {
                $d['active_ids'][] = $tid;
            }
        }
        update_option('akurasitara_saved_ref_tables', $d);
        wp_send_json_success(array('active_ids' => $d['active_ids']));
    }

    public function ajax_delete_ref_table() {
        if (!$this->can_manage_reports() || !check_ajax_referer('at_ext_save_report', 'nonce', false)) wp_send_json_error();
        $tid = sanitize_key($_POST['table_id'] ?? '');
        $d = $this->get_ref_tables_data();
        if (isset($d['tables'][$tid])) unset($d['tables'][$tid]);
        if (!isset($d['active_ids']) || !is_array($d['active_ids'])) $d['active_ids'] = array();
        $d['active_ids'] = array_values(array_diff($d['active_ids'], array($tid)));
        if (($d['editing_id'] ?? '') === $tid) {
            $d['editing_id'] = !empty($d['active_ids']) ? reset($d['active_ids']) : (!empty($d['tables']) ? array_key_first($d['tables']) : 'none');
        }
        $d['active_id'] = !empty($d['active_ids']) ? reset($d['active_ids']) : 'none';
        update_option('akurasitara_saved_ref_tables', $d);
        wp_send_json_success();
    }

    // =========================================================================
    // AUTO EVALUATOR MATRIKS IKU 1 (POIN B, C, D, E, F)
    // =========================================================================
    public function evaluate_iku1_respondent_category($answers_str, $ump_settings = array(), $p3_val = 0, $p4_val = 0) {
        $str = strtolower(trim((string)$answers_str));
        $is_layak = false;
        if ($p3_val > 0 && $p4_val > 0) {
            $is_layak = ($p3_val >= ($p4_val * 1.2));
        } else {
            $is_layak = (strpos($str, 'layak') !== false || strpos($str, '> 1') !== false || strpos($str, '3 juta') !== false || strpos($str, '4 juta') !== false || strpos($str, '5 juta') !== false);
        }

        // Poin E / F: Sebelum Lulus
        if (strpos($str, 'sebelum') !== false) {
            return $is_layak ? array('cat' => 'Poin E1/F1 (Sebelum Lulus Layak)', 'k' => 1.0) : array('cat' => 'Poin E2/F2 (Sebelum Lulus Std)', 'k' => 0.6);
        }

        // Poin C1: Founder / Co-Founder
        if (strpos($str, 'founder') !== false || strpos($str, 'wirausaha') !== false) {
            if (strpos($str, '< 6') !== false || strpos($str, '1 bulan') !== false || strpos($str, '2 bulan') !== false) {
                return $is_layak ? array('cat' => 'Poin C1.a (Founder Fast Layak)', 'k' => 1.2) : array('cat' => 'Poin C1.c (Founder Fast UMP)', 'k' => 0.8);
            }
            return $is_layak ? array('cat' => 'Poin C1.b (Founder Std Layak)', 'k' => 1.0) : array('cat' => 'Poin C1.d (Founder Std UMP)', 'k' => 0.6);
        }

        // Poin C2: Freelancer
        if (strpos($str, 'freelance') !== false || strpos($str, 'lepas') !== false) {
            if (strpos($str, '< 6') !== false || strpos($str, '1 bulan') !== false || strpos($str, '2 bulan') !== false) {
                return $is_layak ? array('cat' => 'Poin C2.a (Freelance Fast Layak)', 'k' => 0.5) : array('cat' => 'Poin C2.c (Freelance Fast UMP)', 'k' => 0.3);
            }
            return $is_layak ? array('cat' => 'Poin C2.b (Freelance Std Layak)', 'k' => 0.4) : array('cat' => 'Poin C2.d (Freelance Std UMP)', 'k' => 0.2);
        }

        // Poin D: Studi Lanjut
        if (strpos($str, 'studi') !== false || strpos($str, 's2') !== false || strpos($str, 'kuliah') !== false) {
            return array('cat' => 'Poin D1 (Studi Lanjut <12 Bln)', 'k' => 0.6);
        }

        // Poin B: Bekerja (Default)
        if (strpos($str, '< 6') !== false || strpos($str, '1 bulan') !== false || strpos($str, '2 bulan') !== false) {
            return $is_layak ? array('cat' => 'Poin B1 (Bekerja Fast Layak)', 'k' => 1.0) : array('cat' => 'Poin B3 (Bekerja Std UMP)', 'k' => 0.6);
        }
        if (strpos($str, '6-12') !== false || strpos($str, '8 bulan') !== false || strpos($str, '9 bulan') !== false) {
            return $is_layak ? array('cat' => 'Poin B2 (Bekerja Std Layak)', 'k' => 0.8) : array('cat' => 'Poin B3 (Bekerja Std UMP)', 'k' => 0.6);
        }

        return $is_layak ? array('cat' => 'Poin B1 (Bekerja Fast Layak)', 'k' => 1.0) : array('cat' => 'Poin B3 (Bekerja Std UMP)', 'k' => 0.6);
    }

    // =========================================================================
    // EVALUATOR SMART RESPONDEN - MULTI-AKTIF
    // =========================================================================
    public function evaluate_single_respondent_column($formula, $res_data, $questions = array(), $active_tables = null) {
        $f = trim((string)$formula); $f = ltrim($f, '=');
        if ($f === '') return '-';

        if ($active_tables === null) {
            $active_tables = $this->get_active_ref_tables();
        } elseif (is_array($active_tables) && isset($active_tables['id'])) {
            $active_tables = ($active_tables['id'] !== 'none') ? array($active_tables['id'] => $active_tables) : array();
        }

        $qid_map = array();
        if (!empty($questions)) {
            foreach ($questions as $idx => $q) {
                $code_t = 'T' . ($idx + 1);
                $code_p = 'P' . ($idx + 1);
                $qid_map[$code_t] = intval($q->id);
                $qid_map[$code_p] = intval($q->id);
            }
        }

        // 1. Ekstrak Status Pekerjaan (T1 / P1)
        $p1_val = 0;
        $t1_qid = $qid_map['T1'] ?? ($qid_map['P1'] ?? 0);
        $p1_text = ($t1_qid > 0 && isset($res_data['answers'][$t1_qid])) ? (is_array($res_data['answers'][$t1_qid]) ? implode(' ', $res_data['answers'][$t1_qid]) : (string)$res_data['answers'][$t1_qid]) : '';
        if (is_numeric(trim($p1_text))) {
            $p1_val = floatval(trim($p1_text));
        } else {
            $clean_p1 = preg_replace('/[^0-9\.]/', '', $p1_text);
            $p1_val = ($clean_p1 !== '' && is_numeric($clean_p1)) ? floatval($clean_p1) : 0;
        }

        // 2. Ekstrak Masa Tunggu dalam Bulan (T2 / P2)
        $p2_val = 0;
        $t2_qid = $qid_map['T2'] ?? ($qid_map['P2'] ?? 0);
        $p2_text = ($t2_qid > 0 && isset($res_data['answers'][$t2_qid])) ? (is_array($res_data['answers'][$t2_qid]) ? implode(' ', $res_data['answers'][$t2_qid]) : (string)$res_data['answers'][$t2_qid]) : '';
        if (strpos(strtolower($p2_text), 'sebelum') !== false) {
            $p2_val = 0;
        } else {
            if (preg_match('/(\d+)\s*bulan/i', $p2_text, $m)) $p2_val = floatval($m[1]);
            elseif (preg_match('/(\d+)\s*tahun/i', $p2_text, $m)) $p2_val = floatval($m[1]) * 12;
            else {
                $clean_p2 = preg_replace('/[^0-9\.]/', '', $p2_text);
                $p2_val = ($clean_p2 !== '' && is_numeric($clean_p2)) ? floatval($clean_p2) : 0;
            }
        }

        // 3. Ekstrak Gaji Nominal Rupiah (T3 / P3)
        $p3_val = 0;
        $t3_qid = $qid_map['T3'] ?? ($qid_map['P3'] ?? 0);
        $p3_text = ($t3_qid > 0 && isset($res_data['answers'][$t3_qid])) ? (is_array($res_data['answers'][$t3_qid]) ? implode(' ', $res_data['answers'][$t3_qid]) : (string)$res_data['answers'][$t3_qid]) : '';
        if (preg_match('/([\d\.,]+)\s*juta/i', $p3_text, $m)) {
            $p3_val = floatval(str_replace(',', '.', $m[1])) * 1000000;
        } else {
            $clean_p3 = preg_replace('/[^\d\.,]/', '', $p3_text);
            if (substr_count($clean_p3, '.') > 1 || preg_match('/\.\d{3}$/', $clean_p3)) {
                $clean_p3 = str_replace('.', '', $clean_p3);
                $clean_p3 = str_replace(',', '.', $clean_p3);
            } elseif (strpos($clean_p3, ',') !== false) {
                $clean_p3 = str_replace('.', '', $clean_p3);
                $clean_p3 = str_replace(',', '.', $clean_p3);
            }
            $raw_p3 = floatval($clean_p3);
            $p3_val = ($raw_p3 > 0 && $raw_p3 < 100) ? ($raw_p3 * 1000000) : $raw_p3;
        }

        $f_upper = strtoupper($f);

        // Single direct reference table column request (e.g. formula is just "T15" or "UMP")
        if (!empty($active_tables)) {
            $ref_col_check = count($questions);
            foreach ($active_tables as $tid => $tconf) {
                $ref_col_check++;
                $col_code = 'T' . $ref_col_check;
                $table_title_clean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $tconf['title'] ?? ''));
                if ($f_upper === $col_code || ($table_title_clean && $f_upper === $table_title_clean)) {
                    $target_q = $tconf['question_code'] ?? 'T4';
                    if (is_string($target_q) && strpos($target_q, 'P') === 0) $target_q = 'T' . substr($target_q, 1);
                    $target_qid = is_numeric($target_q) ? intval($target_q) : ($qid_map[$target_q] ?? 0);
                    $ans_t = ($target_qid > 0 && isset($res_data['answers'][$target_qid])) ? (is_array($res_data['answers'][$target_qid]) ? implode(' ', $res_data['answers'][$target_qid]) : (string)$res_data['answers'][$target_qid]) : '';
                    $ref_val = $this->lookup_ref_val($ans_t, $tconf['data'] ?? array(), 0);
                    $ref_raw = $this->lookup_ref_raw($ans_t, $tconf['data'] ?? array());
                    $raw_for_fmt = $ref_raw !== '' ? $ref_raw : ($ref_val !== 0 ? $ref_val : '');
                    return $this->format_ref_cell_value($raw_for_fmt, $ref_val, $tconf['data_type'] ?? 'currency');
                }
            }
        }

        $primary_p4_val = 0;

        // Bangun konteks variabel responden
        $resp_vars = array(
            'T1'          => $p1_val,
            'P1'          => $p1_val,
            'T2'          => $p2_val,
            'P2'          => $p2_val,
            'T3'          => $p3_val,
            'P3'          => $p3_val,
        );

        for ($i = 1; $i <= 30; $i++) {
            $qid_curr = $qid_map['T' . $i] ?? ($qid_map['P' . $i] ?? 0);
            if ($qid_curr > 0 && isset($res_data['answers'][$qid_curr])) {
                $raw_ans_t = is_array($res_data['answers'][$qid_curr]) ? implode(' ', $res_data['answers'][$qid_curr]) : (string)$res_data['answers'][$qid_curr];
                $num_val = floatval(preg_replace('/[^0-9\.]/', '', $raw_ans_t));
                if (!isset($resp_vars['T' . $i])) $resp_vars['T' . $i] = $num_val;
                if (!isset($resp_vars['P' . $i])) $resp_vars['P' . $i] = $num_val;
            }
        }

        if (!empty($active_tables)) {
            $ref_col_num = count($questions);
            foreach ($active_tables as $tid => $tconf) {
                $ref_col_num++;
                $col_code = 'T' . $ref_col_num;
                $target_q = $tconf['question_code'] ?? 'T4';
                if (is_string($target_q) && strpos($target_q, 'P') === 0) $target_q = 'T' . substr($target_q, 1);
                $target_qid = is_numeric($target_q) ? intval($target_q) : ($qid_map[$target_q] ?? 0);
                $ans_t = ($target_qid > 0 && isset($res_data['answers'][$target_qid])) ? (is_array($res_data['answers'][$target_qid]) ? implode(' ', $res_data['answers'][$target_qid]) : (string)$res_data['answers'][$target_qid]) : '';
                $ref_val = $this->lookup_ref_val($ans_t, $tconf['data'] ?? array(), 0);
                $resp_vars[$col_code] = $ref_val;
                $table_title_clean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $tconf['title'] ?? ''));
                if ($table_title_clean) $resp_vars[$table_title_clean] = $ref_val;

                if ($primary_p4_val === 0 && $ref_val > 0) {
                    $primary_p4_val = $ref_val;
                }
            }
        }

        // Fallback jika belum terisi dari tabel acuan
        if ($primary_p4_val === 0) {
            $t4_qid = $qid_map['T4'] ?? ($qid_map['P4'] ?? 0);
            $p4_text = ($t4_qid > 0 && isset($res_data['answers'][$t4_qid])) ? (is_array($res_data['answers'][$t4_qid]) ? implode(' ', $res_data['answers'][$t4_qid]) : (string)$res_data['answers'][$t4_qid]) : '';
            $p4_val = floatval(preg_replace('/[^0-9\.]/', '', $p4_text));
            if ($p4_val > 0 && $p4_val < 100) $p4_val *= 1000000;
            $primary_p4_val = $p4_val;
        }

        $resp_vars['T4']  = $primary_p4_val;
        $resp_vars['P4']  = $primary_p4_val;
        $resp_vars['T14'] = $primary_p4_val; // Kompatibilitas mundur untuk formula T14

        // Evaluasi formula menggunakan AST DSL Engine mandiri
        $dsl_eval = AkurasiTara_DSL_Engine::execute($f, array('variables' => $resp_vars));
        $res = $dsl_eval['value'];

        if ($res >= 10000) {
            return 'Rp ' . number_format(floatval($res), 0, ',', '.');
        }

        return number_format(floatval($res), 2, ',', '.');
    }

    // =========================================================================
    // MESIN FORMULA DSL EXCEL UNIVERSAL - MULTI-AKTIF (MENDUKUNG MANUAL & RUMUS)
    // =========================================================================
    public function evaluate_dsl_formula($formula, $survey_values, $ump_data = array(), $questions = array(), $run_id = 0, $active_tables = null) {
        $f = trim((string)$formula);
        if ($f === '') return '-';

        // Jika input adalah teks / angka manual murni tanpa operator dan fungsi DSL (misal: "50", "396", "80%", "Target 100")
        if (!preg_match('/[=+\-*\/()<>!^]/', $f) && !preg_match('/\b(COUNT|SUM|AVG|AVERAGE|MIN|MAX|IF|PERCENT|COUNTIF|ROUND|ABS|SQRT|FLOOR|CEIL|SIGMA|SUM_I)\b/i', $f) && strpos($f, 'Σ') === false) {
            if (strpos($f, '%') !== false) {
                $num = floatval(preg_replace('/[^0-9\.]/', '', $f));
                return number_format($num, 2, ',', '.') . '%';
            } elseif (is_numeric(preg_replace('/[^0-9\.]/', '', $f)) && strpos($f, '.') === false) {
                return intval(preg_replace('/[^0-9]/', '', $f));
            }
            return esc_html($f);
        }

        if ($active_tables === null) {
            $active_tables = $this->get_active_ref_tables();
        } elseif (is_array($active_tables) && isset($active_tables['id'])) {
            $active_tables = ($active_tables['id'] !== 'none') ? array($active_tables['id'] => $active_tables) : array();
        }

        $contextParams = array(
            'survey_values' => $survey_values,
            'questions'     => $questions,
            'active_tables' => $active_tables,
            'variables'     => array(
                'total_respondents' => !empty($survey_values) ? count($survey_values) : 0,
                't'                 => !empty($survey_values) ? count($survey_values) : 0,
            )
        );

        $res = AkurasiTara_DSL_Engine::execute($f, $contextParams);
        if (!$res['success']) {
            return esc_html($res['formatted']);
        }

        if (strtoupper($f) === 'COUNT()' || (is_numeric($res['value']) && floor($res['value']) == $res['value'] && strpos($f, '/') === false && stripos($f, '%') === false && stripos($f, 'ROUND') === false && stripos($f, 'AVG') === false)) {
            return intval($res['value']) . (strtoupper($f) === 'COUNT()' ? ' Responden' : '');
        }

        return $res['formatted'];
    }

    // =========================================================================
    // KOLEKSI DATA RESPONDEN & JAWABAN
    public function collect_values($run_id, $question_ids = array()) {
        $run_id = intval($run_id);
        if ($run_id <= 0) return array();
        $t = $this->tables(); $db = $this->db();

        $responses = (array) $db->get_results($db->prepare("
            SELECT r.id, r.user_id, r.fill_status, COALESCE(u.username, '') AS username 
            FROM {$t->responses} r 
            LEFT JOIN {$t->users} u ON r.user_id=u.id 
            WHERE r.run_id=%d 
            ORDER BY r.id ASC
        ", $run_id));

        if (empty($responses)) return array();

        $user_ids = array_values(array_filter(array_unique(array_map('intval', array_column($responses, 'user_id')))));
        $user_names = array();
        if (!empty($user_ids)) {
            $in_u = implode(',', $user_ids);
            $idents = (array) $db->get_results("
                SELECT ui.user_id, ui.value_long, ie.field_label, ie.field_key 
                FROM {$t->user_identity} ui 
                JOIN {$t->id_elements} ie ON ui.element_id=ie.id 
                WHERE ui.user_id IN ($in_u) AND (LOWER(ie.field_label) LIKE '%nama%' OR LOWER(ie.field_key) LIKE '%name%')
                ORDER BY ui.id ASC
            ");
            foreach ($idents as $id_row) {
                $uid = intval($id_row->user_id);
                $val = trim((string)$id_row->value_long);
                if ($val !== '' && !isset($user_names[$uid])) {
                    $user_names[$uid] = $val;
                }
            }
        }

        $resp_ids = array_map('intval', array_column($responses, 'id'));
        $in_r = implode(',', $resp_ids);

        $ans_sql = "SELECT response_id, question_id, answer_text, file_url FROM {$t->answers} WHERE response_id IN ($in_r)";
        if (!empty($question_ids)) {
            $ans_sql .= " AND question_id IN (" . implode(',', array_map('intval', $question_ids)) . ")";
        }
        $ans_sql .= " ORDER BY id ASC";
        $answers = (array) $db->get_results($ans_sql);

        $ans_map = array();
        foreach ($answers as $a) {
            $rid = intval($a->response_id);
            $qid = intval($a->question_id);
            if (!isset($ans_map[$rid])) $ans_map[$rid] = array();
            if (!isset($ans_map[$rid][$qid])) $ans_map[$rid][$qid] = array();
            $ans_text = trim((string)$a->answer_text);
            if ($ans_text === '' && !empty($a->file_url)) $ans_text = 'Bukti: ' . basename($a->file_url);
            $ans_map[$rid][$qid][] = $ans_text;
        }

        $res = array();
        foreach ($responses as $r) {
            $rid = intval($r->id);
            $uid = intval($r->user_id);
            $full_name = '';
            if ($uid > 0 && isset($user_names[$uid]) && $user_names[$uid] !== '') {
                $full_name = $user_names[$uid];
            } elseif (!empty($r->username)) {
                $full_name = $r->username;
            } else {
                $full_name = 'Responden #' . $rid;
            }

            $res[$rid] = array(
                'id'        => $rid,
                'user_id'   => $uid,
                'name'      => $full_name,
                'status'    => $r->fill_status ?? 'completed',
                'answers'   => $ans_map[$rid] ?? array()
            );
        }
        return $res;
    }

    public function build_detail_table_data($run_id, $survey_values, $question_ids_survey, $active_tables = null) {
        $questions = $this->run_questions($run_id);
        $q_map = array();
        $qid_map = array();
        foreach ($questions as $q_i => $q_o) {
            $q_map[intval($q_o->id)] = trim(strip_tags($q_o->question_text));
            $qid_map['T' . ($q_i + 1)] = intval($q_o->id);
            $qid_map['P' . ($q_i + 1)] = intval($q_o->id);
        }

        $headers = array('No', 'Nama');
        foreach ($question_ids_survey as $qid) {
            $code = $this->question_code($run_id, $qid);
            $num = preg_replace('/[^0-9]/', '', $code);
            $q_text = $q_map[intval($qid)] ?? ('Pertanyaan ' . $num);
            $headers[] = $code . ' (' . $q_text . ')';
        }

        // TAMBAHKAN MULTI TABEL ACUAN SEBAGAI KOLOM BARU Tn+1, Tn+2, dst.
        if ($active_tables === null) {
            $active_tables = $this->get_active_ref_tables();
        } elseif (is_array($active_tables) && isset($active_tables['id'])) {
            $active_tables = ($active_tables['id'] !== 'none') ? array($active_tables['id'] => $active_tables) : array();
        }

        $ref_cols_info = array();
        $next_col_num = count($question_ids_survey);

        foreach ($active_tables as $tid => $tconf) {
            if (empty($tconf) || !is_array($tconf)) continue;
            $next_col_num++;
            $col_code = 'T' . $next_col_num;
            $col_title = !empty($tconf['title']) ? $tconf['title'] : ('Tabel Acuan ' . $next_col_num);
            $target_q = $tconf['question_code'] ?? 'T4';
            if (strpos($target_q, 'P') === 0) $target_q = 'T' . substr($target_q, 1);
            $target_qid = $qid_map[$target_q] ?? 0;
            $dtype = ucfirst($tconf['data_type'] ?? 'currency');

            $headers[] = $col_code . ' (' . $col_title . ' — Acuan ' . $target_q . ')';
            $ref_col_idx = count($headers) - 1;

            $ref_cols_info[$tid] = array(
                'table_id'     => $tid,
                'code'         => $col_code,
                'col_idx'      => $ref_col_idx,
                'title'        => $col_title,
                'target_q'     => $target_q,
                'target_qid'   => $target_qid,
                'dtype'        => $dtype,
                'data_type'    => strtolower($dtype),
                'items'        => $tconf['items'] ?? array(),
                'data'         => $tconf['data'] ?? array()
            );
        }

        $ref_col_indices = array_column($ref_cols_info, 'col_idx');
        $primary_ref_code = !empty($ref_cols_info) ? reset($ref_cols_info)['code'] : '';
        $primary_ref_idx = !empty($ref_col_indices) ? reset($ref_col_indices) : null;

        $rows = array();
        $idx = 1;
        foreach ($survey_values as $res) {
            $row = array($idx++, $res['name']);
            foreach ($question_ids_survey as $qid) {
                $row[] = isset($res['answers'][$qid]) ? (is_array($res['answers'][$qid]) ? implode(' | ', $res['answers'][$qid]) : (string)$res['answers'][$qid]) : '-';
            }

            // ISI NILAI KOLOM UNTUK SETIAP TABEL ACUAN AKTIF
            foreach ($ref_cols_info as $rci) {
                $t_qid = $rci['target_qid'];
                $raw_ans = ($t_qid > 0 && isset($res['answers'][$t_qid])) ? (is_array($res['answers'][$t_qid]) ? implode(' | ', $res['answers'][$t_qid]) : (string)$res['answers'][$t_qid]) : '';
                $it_info = $this->lookup_ref_item_info($raw_ans, $rci['items']);
                $ref_num = $this->lookup_ref_val($raw_ans, $rci['data'], 0);
                $ref_raw = $this->lookup_ref_raw($raw_ans, $rci['data']);
                $val_for_fmt = ($it_info && trim((string)$it_info['val']) !== '') ? (string)$it_info['val'] : $ref_raw;

                $cell_val = '-';
                if ($val_for_fmt !== '' || $ref_num > 0 || in_array($rci['data_type'], array('null', 'boolean'), true)) {
                    $cell_val = $this->format_ref_cell_value($val_for_fmt, $ref_num, $rci['data_type']);
                }
                $row[] = $cell_val;
            }

            $rows[] = $row;
        }
        return array(
            'headers'         => $headers,
            'rows'            => $rows,
            'ref_cols'        => $ref_cols_info,
            'ref_col_indices' => $ref_col_indices,
            'ref_col_idx'     => $primary_ref_idx,
            'ref_col_code'    => $primary_ref_code
        );
    }

    public function handle_form_actions() {
        if (!isset($_POST['at_action']) || $_POST['at_action'] !== 'save_report' || !$this->can_manage_reports() || !check_admin_referer('at_ext_save_report')) return;
        $this->ensure_reports_tables();
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? '')); $run_id = intval($_POST['run_id'] ?? 0); $report_id = intval($_POST['id'] ?? 0);
        if (!$name || $run_id <= 0) wp_die('Nama laporan dan survey wajib diisi.');
        $t = $this->tables(); $db = $this->db();
        $q_ids = array_column($this->run_questions($run_id), 'id');
        $secs = $_POST['sections'] ?? array();
        $clean_first_f = !empty($secs[0]['formula']) ? trim(html_entity_decode(wp_strip_all_tags(wp_unslash($_POST['formula'] ?? ($secs[0]['formula'] ?? 'COUNT()'))), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : 'COUNT()';
        $data = array(
            'name'          => $name,
            'run_id'        => $run_id,
            'question_ids'  => json_encode($q_ids),
            'formula'       => $clean_first_f,
            'calc_method'   => 'dsl',
            'updated_at'    => current_time('mysql')
        );

        if ($report_id > 0) {
            $db->update($t->reports, $data, array('id' => $report_id));
        } else {
            $data['created_at'] = current_time('mysql');
            $res = $db->insert($t->reports, $data);
            if ($res === false) {
                $db->query($db->prepare("INSERT INTO {$t->reports} (name, run_id, formula, calc_method, created_at, updated_at) VALUES (%s, %d, %s, %s, %s, %s)", $name, $run_id, $clean_first_f, 'dsl', current_time('mysql'), current_time('mysql')));
                $report_id = $db->insert_id;
            } else { $report_id = $db->insert_id; }
        }

        if ($report_id > 0) {
            $db->delete($t->report_sections, array('report_id' => $report_id));
            if (is_array($secs)) {
                $s_idx = 1;
                foreach ($secs as $k => $s) {
                    if (empty($s['title']) && empty($s['formula'])) continue;
                    $is_pct_key = (strpos((string)$k, 'p_') === 0);
                    $show_in_tbl = (!$is_pct_key && !empty($s['show_in_table'])) ? 1 : 0;
                    $clean_sec_formula = trim(html_entity_decode(wp_strip_all_tags(wp_unslash($s['formula'] ?? 'COUNT()')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $db->insert($t->report_sections, array(
                        'report_id'     => $report_id,
                        'title'         => sanitize_text_field(wp_unslash($s['title'] ?? ('DSL #' . $s_idx))),
                        'formula'       => $clean_sec_formula,
                        'show_in_table' => $show_in_tbl,
                        'sort_order'    => $s_idx++
                    ));
                }
            }
        }
        wp_redirect(admin_url('admin.php?page=' . self::SLUG_REPORT . '&message=saved')); exit;
    }

    public function handle_export() {
        if (!$this->can_manage_reports()) wp_die('Akses ditolak.');
        check_admin_referer('at_ext_report_export');
        $run_id = intval($_GET['run_id'] ?? 1); $q_ids = array_column($this->run_questions($run_id), 'id');
        $active_tables = $this->get_active_ref_tables();
        $detail = $this->build_detail_table_data($run_id, $this->collect_values($run_id, $q_ids), $q_ids, $active_tables);
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=Laporan_Export_' . date('Ymd_His') . '.csv');
        $out = fopen('php://output', 'w'); fputcsv($out, $detail['headers']);
        foreach ($detail['rows'] as $r) fputcsv($out, $r);
        fclose($out); exit;
    }

    // =========================================================================
    // ADMIN VIEWS
    // =========================================================================
    public function page_reports() {
        if (!$this->can_manage_reports()) { echo '<div class="notice notice-error"><p>Akses ditolak.</p></div>'; return; }
        $this->ensure_reports_tables();
        $mode = sanitize_key($_GET['mode'] ?? '');
        $action = sanitize_key($_GET['action'] ?? '');
        $view_id = intval($_GET['id'] ?? 0);
        $edit_id = intval($_GET['edit'] ?? 0);
        $del_id = intval($_GET['delete'] ?? 0);
        $run_param = intval($_GET['run_id'] ?? 0);

        if ($del_id > 0 && check_admin_referer('at_ext_delete_report_' . $del_id)) {
            $t = $this->tables(); $this->db()->delete($t->reports, array('id' => $del_id)); $this->db()->delete($t->report_sections, array('report_id' => $del_id));
            echo '<div class="notice notice-success"><p>Laporan dihapus.</p></div>';
        }

        if (isset($_GET['message']) && $_GET['message'] === 'saved') {
            echo '<div class="notice notice-success is-dismissible" style="padding:12px 16px;margin:15px 0"><p style="margin:0;font-size:14px;font-weight:bold;color:#15803d">✅ Laporan Berhasil Disimpan!</p></div>';
        }

        echo '<style>
        #wpfooter{display:none!important;position:static!important;visibility:hidden!important;height:0!important;max-height:0!important;margin:0!important;padding:0!important;overflow:hidden!important;pointer-events:none!important;z-index:-1!important}
        #wpbody-content{padding-bottom:60px!important;float:none!important}
        .at-card{position:relative;z-index:2}
        #at_add_ref_btn,.at-enable-all-btn,.at-disable-all-btn,.at-toggle-active-btn,.at-switch-editing-btn,.at-del-ref-btn,#at_add_count_sec_btn,#at_add_pct_sec_btn,#at_save_ref_btn,#at_add_manual_row_btn,#at_import_q_choices_btn,#at_apply_bulk_val_btn{position:relative;z-index:10;cursor:pointer}
        </style>';

        echo '<div class="wrap" style="max-width:1250px;margin:20px auto">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;background:#fff;padding:16px 20px;border-radius:10px;border:1px solid #e2e8f0"><h1 style="margin:0;font-size:20px;color:#0f172a">📊 Laporan DSL & Tabel Acuan Nilai</h1><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG_REPORT . '&action=create_report')) . '">+ Buat Laporan Baru</a></div>';

        // MODE VIEW LAPORAN
        if ($mode === 'view' && $view_id > 0) {
            $this->render_report_view($view_id);
        }
        // MODE EDIT / FORM LAPORAN / PILIH RUN
        elseif ($action === 'create_report' || $mode === 'edit' || $edit_id > 0 || $run_param > 0) {
            $edit_report = ($edit_id > 0) ? $this->db()->get_row($this->db()->prepare("SELECT * FROM {$this->tables()->reports} WHERE id=%d", $edit_id)) : null;
            $selected_run = intval($_GET['run_id'] ?? ($edit_report->run_id ?? 0));
            echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:24px;margin-bottom:24px">';
            $this->render_report_form($edit_report, $selected_run);
            echo '</div>';
        }

        $this->render_reports_list();
        echo '</div>';
    }

    // LIST LAPORAN TERSIMPAN
    private function render_reports_list() {
        $this->ensure_reports_tables();
        $t = $this->tables();
        $reports = (array) $this->db()->get_results("SELECT * FROM {$t->reports} ORDER BY id DESC");
        echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px"><h3 style="margin:0 0 14px 0">Daftar Laporan Tersimpan</h3>';
        if (empty($reports)) { echo '<p style="color:#64748b;margin:0">Belum ada laporan tersimpan.</p></div>'; return; }
        echo '<table class="widefat striped" style="border:none"><thead><tr><th>ID</th><th>Judul Laporan</th><th>Survey Terkait</th><th>Tanggal Dibuat</th><th style="text-align:right">Aksi</th></tr></thead><tbody>';
        foreach ($reports as $r) {
            $run_id = intval($r->run_id);
            $run_info = $this->db()->get_row($this->db()->prepare("SELECT r.*, s.title AS survey_title, u.name AS unit_name FROM {$t->runs} r LEFT JOIN {$t->surveys} s ON r.survey_id=s.id LEFT JOIN {$t->units} u ON r.unit_id=u.id WHERE r.id=%d", $run_id));
            $s_name = $run_info ? (($run_info->survey_title ?: 'Survey') . ' — ' . ($run_info->unit_name ?: 'Unit') . ' (Run #' . $run_id . ')') : ('Run #' . $run_id);

            $del_url = wp_nonce_url(admin_url('admin.php?page=' . self::SLUG_REPORT . '&delete=' . intval($r->id)), 'at_ext_delete_report_' . intval($r->id));
            $edit_url = admin_url('admin.php?page=' . self::SLUG_REPORT . '&mode=edit&edit=' . intval($r->id) . '&run_id=' . $run_id);
            $view_url = admin_url('admin.php?page=' . self::SLUG_REPORT . '&mode=view&id=' . intval($r->id));

            echo '<tr><td>#' . intval($r->id) . '</td><td><strong style="color:#0284c7;font-size:14px">' . esc_html($r->name) . '</strong></td><td><span style="background:#f1f5f9;color:#334155;padding:3px 8px;border-radius:6px;font-size:12px">' . esc_html($s_name) . '</span></td><td>' . esc_html(date('d M Y H:i', strtotime($r->created_at ?? current_time('mysql')))) . '</td><td style="text-align:right"><a class="button button-primary button-small" href="' . esc_url($view_url) . '">👁️ Lihat Hasil Laporan</a> <a class="button button-small" href="' . esc_url($edit_url) . '">✏️ Edit</a> <a class="button button-small" href="' . esc_url($del_url) . '" onclick="return confirm(\'Hapus laporan ini?\')" style="color:#d63638">🗑️ Hapus</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    // MODE VIEW HASIL LAPORAN
    private function render_report_view($report_id) {
        $t = $this->tables(); $db = $this->db();
        $report = $db->get_row($db->prepare("SELECT * FROM {$t->reports} WHERE id=%d", $report_id));
        if (!$report) { echo '<div class="notice notice-error"><p>Laporan tidak ditemukan.</p></div>'; return; }

        $run_id = intval($report->run_id);
        $run_info = $db->get_row($db->prepare("SELECT r.*, s.title AS survey_title, u.name AS unit_name FROM {$t->runs} r LEFT JOIN {$t->surveys} s ON r.survey_id=s.id LEFT JOIN {$t->units} u ON r.unit_id=u.id WHERE r.id=%d", $run_id));
        $s_name = $run_info ? (($run_info->survey_title ?: 'Survey') . ' — ' . ($run_info->unit_name ?: '-')) : ('Run #' . $run_id);

        $questions = $this->run_questions($run_id);
        $q_ids = array_column($questions, 'id');
        $survey_values = $this->collect_values($run_id, $q_ids);
        $active_tables = $this->get_active_ref_tables();
        $detail_data = $this->build_detail_table_data($run_id, $survey_values, $q_ids, $active_tables);
        $sections = (array) $db->get_results($db->prepare("SELECT * FROM {$t->report_sections} WHERE report_id=%d ORDER BY sort_order ASC, id ASC", $report_id));

        $calc_cols = array_filter($sections, function($sec){
            $f = $sec->formula ?? '';
            $t = $sec->title ?? '';
            $is_pct = (strpos($f, '%') !== false || stripos($f, 'PERCENT') !== false || stripos($t, 'persen') !== false || stripos($t, 'iku') !== false || (strpos($f, '/') !== false && strpos($f, '*') !== false));
            return !empty($sec->show_in_table) && !$is_pct;
        });

        echo '<div style="background:#fff;border:1px solid #0284c7;border-radius:10px;padding:24px;margin-bottom:24px">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;border-bottom:2px solid #e0f2fe;padding-bottom:14px;flex-wrap:wrap;gap:12px">';
        echo '<div><h2 style="margin:0;color:#0369a1;font-size:22px">📊 ' . esc_html($report->name) . '</h2><p style="margin:6px 0 0 0;color:#64748b;font-size:13px">Survey Terkait: <strong>' . esc_html($s_name) . ' (Run #' . $run_id . ')</strong></p></div>';
        echo '<div style="display:flex;gap:8px;align-items:center"><a class="button button-secondary" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG_REPORT)) . '">← Kembali ke Daftar</a> <a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=at_ext_report_export&run_id=' . $run_id), 'at_ext_report_export')) . '">📥 Export CSV</a></div>';
        echo '</div>';

        // 1. Ringkasan Hasil Perhitungan Formula
        if (!empty($sections)) {
            echo '<div style="background:#f8fafc;border:1px solid #bae6fd;border-top:3px solid #0284c7;border-radius:8px;padding:16px;margin-bottom:20px">';
            echo '<h3 style="margin:0 0 12px 0;font-size:15px;color:#0369a1">📋 Ringkasan Hasil Perhitungan Formula</h3>';
            echo '<table class="widefat striped" style="border:none;background:#fff;border-radius:6px;overflow:hidden"><thead><tr><th style="width:40%">Nama Metrik / Indikator</th><th style="width:40%">Formula DSL</th><th style="text-align:right;width:20%">Hasil Perhitungan</th></tr></thead><tbody>';
            foreach ($sections as $sec) {
                $res_val = $this->evaluate_dsl_formula($sec->formula, $survey_values, array(), $questions, $run_id, $active_tables);
                echo '<tr>';
                echo '<td><strong style="color:#0f172a">' . esc_html($sec->title) . '</strong></td>';
                echo '<td><code style="background:#f1f5f9;color:#334155;padding:2px 6px;border-radius:4px">' . esc_html($sec->formula) . '</code></td>';
                echo '<td style="text-align:right"><span style="background:#f0fdf4;color:#166534;font-weight:bold;padding:4px 10px;border-radius:6px;font-size:13px">' . esc_html($res_val) . '</span></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        // 2. Tabel Rincian Data Responden & Jawaban (Langsung tampil & dapat di-scroll secara proporsional)
        echo '<div style="margin-top:16px">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px"><h3 style="margin:0;font-size:15px;color:#0f172a">📋 Tabel Data Responden & Jawaban</h3><span style="font-size:12px;background:#f1f5f9;color:#334155;padding:3px 8px;border-radius:6px">' . count($detail_data['rows']) . ' Responden</span></div>';
        echo '<div class="at-table-scroll-wrap"><table class="at-table"><thead><tr>';
        foreach ($detail_data['headers'] as $h_idx => $h) {
            $is_ref_h = (!empty($detail_data['ref_col_indices']) && in_array($h_idx, $detail_data['ref_col_indices'], true));
            echo $this->render_th($h, $is_ref_h);
        }
        if (!empty($calc_cols)) {
            foreach ($calc_cols as $cc) {
                echo '<th style="background:#dcfce7;color:#166534;vertical-align:top;min-width:130px;padding:8px 10px"><span style="display:inline-block;background:#166534;color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;margin-bottom:4px">📊 HASIL</span><div style="font-size:11px;font-weight:700;line-height:1.3;white-space:normal">' . esc_html($cc->title) . '</div></th>';
            }
        }
        echo '</tr></thead><tbody>';

        if (!empty($detail_data['rows'])) {
            $row_idx = 0;
            $survey_keys = array_keys($survey_values);
            foreach ($detail_data['rows'] as $row_data) {
                $curr_rid = $survey_keys[$row_idx] ?? 0;
                $r_data = $survey_values[$curr_rid] ?? array();
                $row_idx++;
                echo '<tr>';
                foreach ($row_data as $c_idx => $c_val) {
                    if ($c_idx === 0) {
                        echo '<td>' . esc_html($c_val) . '</td>';
                    } elseif ($c_idx === 1) {
                        echo '<td><strong>' . esc_html($c_val) . '</strong></td>';
                    } elseif (!empty($detail_data['ref_col_indices']) && in_array($c_idx, $detail_data['ref_col_indices'], true)) {
                        echo '<td style="background:#f0fdf4;font-weight:bold;color:#166534"><span style="background:#dcfce7;padding:3px 8px;border-radius:6px">' . esc_html($c_val) . '</span></td>';
                    } else {
                        echo '<td>' . esc_html($c_val) . '</td>';
                    }
                }
                if (!empty($calc_cols)) {
                    foreach ($calc_cols as $cc) {
                        $val = $this->evaluate_single_respondent_column($cc->formula, $r_data, $questions, $active_tables);
                        echo '<td style="font-weight:bold;color:#166534;background:#f0fdf4"><span style="background:#dcfce7;padding:3px 8px;border-radius:6px">' . esc_html($val) . '</span></td>';
                    }
                }
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="' . (count($detail_data['headers']) + count($calc_cols)) . '" style="text-align:center;color:#64748b;padding:16px">Belum ada data responden untuk survey ini.</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '</div>';
        echo '</div>';
        ?>
    }

    private function render_report_form($edit = null, $selected_run = 0) {
        $runs = $this->all_runs();
        if ($edit && !$selected_run) $selected_run = intval($edit->run_id);
        $is_edit = !empty($edit) && !empty($edit->id);
        $questions = $selected_run ? $this->run_questions($selected_run) : array();
        $report_name = isset($_GET['name']) ? sanitize_text_field(wp_unslash($_GET['name'])) : ($edit->name ?? '');

        ?>
        <script>
        window.atChangeRunSurvey = function(runId){
            if(!runId || runId === "" || runId === "0") return;
            var nameInp = document.querySelector('input[name="name"]');
            var nameVal = nameInp && nameInp.value ? encodeURIComponent(nameInp.value) : "";
            var pageUrl = "<?php echo esc_url_raw(admin_url('admin.php?page=' . self::SLUG_REPORT)); ?>";
            var isEditId = <?php echo intval($edit->id ?? 0); ?>;
            var target = pageUrl;
            if(isEditId > 0){
                target += "&mode=edit&edit=" + isEditId + "&run_id=" + encodeURIComponent(runId);
            } else {
                target += "&action=create_report&run_id=" + encodeURIComponent(runId);
            }
            if(nameVal) target += "&name=" + nameVal;
            window.location.href = target;
        };
        </script>
        <?php

        echo '<style>
        #wpfooter{display:none!important;position:static!important;visibility:hidden!important;height:0!important;max-height:0!important;margin:0!important;padding:0!important;overflow:hidden!important;pointer-events:none!important;z-index:-1!important}
        #wpbody-content{padding-bottom:60px!important;float:none!important}
        .at-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.03);position:relative;z-index:2}
        .at-title{font-size:15px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:10px;margin:0 0 14px 0}
        .at-num{background:#0284c7;color:#fff;min-width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
        #at_add_ref_btn,.at-enable-all-btn,.at-disable-all-btn,.at-toggle-active-btn,.at-switch-editing-btn,.at-del-ref-btn,#at_add_count_sec_btn,#at_add_pct_sec_btn,#at_save_ref_btn,#at_add_manual_row_btn,#at_import_q_choices_btn,#at_apply_bulk_val_btn{position:relative;z-index:10;cursor:pointer}
        
        /* Container scroll proporsional untuk 5 baris data responden */
        .at-table-scroll-wrap{max-height:330px;overflow-y:auto;overflow-x:auto;border:1px solid #cbd5e1;border-radius:8px;background:#fff;position:relative}
        .at-table-scroll-wrap::-webkit-scrollbar{width:8px;height:8px}
        .at-table-scroll-wrap::-webkit-scrollbar-track{background:#f1f5f9;border-radius:4px}
        .at-table-scroll-wrap::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:4px}
        .at-table-scroll-wrap::-webkit-scrollbar-thumb:hover{background:#94a3b8}

        .at-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px;margin:0}
        .at-table thead th{position:sticky;top:0;background:#f8fafc;color:#334155;font-weight:600;padding:8px 10px;text-align:left;border-bottom:2px solid #cbd5e1;border-right:1px solid #e2e8f0;z-index:10;box-shadow:0 2px 3px -1px rgba(0,0,0,0.08)}
        .at-table td{padding:8px 10px;border-bottom:1px solid #f1f5f9;border-right:1px solid #f8fafc;font-size:12px;background:#fff}
        .at-table tbody tr:nth-child(even) td{background-color:#fafafa}
        .at-table tbody tr:hover td{background-color:#f0f9ff}
        .at-pill{display:inline-block;background:#e0f2fe;color:#0369a1;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;margin-right:4px;margin-bottom:4px;user-select:none}
        .at-pill:hover{background:#bae6fd}
        .at-quick-bar{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
        </style>';

        echo '<form method="post" id="at_main_report_form" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG_REPORT . ($is_edit ? '&mode=edit&edit=' . intval($edit->id) : ''))) . '">';
        wp_nonce_field('at_ext_save_report');
        echo '<input type="hidden" name="at_action" value="save_report">';
        if ($is_edit) echo '<input type="hidden" name="id" value="' . intval($edit->id) . '">';

        // STEP 1
        echo '<div class="at-card"><h3 class="at-title"><span class="at-num">1</span> Pengaturan Utama Laporan</h3>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">';
        echo '<div><label style="font-weight:600;font-size:13px">Nama Laporan:</label><input type="text" name="name" value="' . esc_attr($report_name) . '" required placeholder="Contoh: Laporan IKU 1 2026" style="width:100%"></div>';
        echo '<div><label style="font-weight:600;font-size:13px">Pilih Pelaksanaan Survey:</label><div style="display:flex;gap:8px;align-items:center;margin-top:4px"><select name="run_id" id="at_run_id" required style="width:100%" onchange="window.atChangeRunSurvey(this.value)"><option value="">-- Pilih Survey --</option>';
        foreach ($runs as $r) echo '<option value="' . intval($r->id) . '" ' . selected($selected_run, intval($r->id), false) . '>' . esc_html(($r->survey_title ?: 'Survey') . ' — ' . ($r->unit_name ?: '-') . ' (Run #' . $r->id . ')') . '</option>';
        echo '</select><button type="button" class="button button-primary" onclick="window.atChangeRunSurvey(document.getElementById(\'at_run_id\').value)" style="white-space:nowrap;font-weight:600">⚡ Muat Data Survey</button></div></div></div></div>';

        if (!$selected_run) {
            echo '<div class="at-card" style="text-align:center;padding:28px;background:#f8fafc;border:1px dashed #cbd5e1"><p style="margin:0;color:#64748b;font-size:14px">👉 Silakan Pilih Pelaksanaan Survey di atas lalu klik <strong>⚡ Muat Data Survey</strong> untuk menampilkan data responden & tabel perhitungan.</p></div>';
        } else {
            $q_ids = array_column($questions, 'id');
            $survey_values = $this->collect_values($selected_run, $q_ids);
            $all_ref = $this->get_ref_tables_data();
            $active_ids = $this->get_active_ref_table_ids();
            $active_tables = $this->get_active_ref_tables();
            $editing_table = $this->get_editing_ref_table();
            $editing_id = $editing_table['id'] ?? 'none';
            $detail_data = $this->build_detail_table_data($selected_run, $survey_values, $q_ids, $active_tables);
            $existing_sections = $is_edit ? (array)$this->db()->get_results($this->db()->prepare("SELECT * FROM {$this->tables()->report_sections} WHERE report_id=%d ORDER BY sort_order ASC, id ASC", $edit->id)) : array();

            // EKSTRAKSI SELURUH PILIHAN / JAWABAN KRITERIA DARI SEMUA SUMBER
            $q_choices_map = array();
            foreach ($questions as $q) {
                $code = $this->question_code($selected_run, $q->id);
                $choices = array();

                // 1. Dari options_csv
                if (!empty($q->options_csv)) {
                    $raw_opts = preg_split('/[,\r\n;]+/', $q->options_csv);
                    foreach ($raw_opts as $p) {
                        $p = trim($p);
                        if ($p !== '') {
                            $parts = explode('|', $p);
                            $clean_c = $this->clean_criteria_text($parts[0]) ?: trim($parts[0]);
                            if ($clean_c !== '') $choices[] = $clean_c;
                        }
                    }
                }

                // 2. Dari query database tabel answers langsung
                $db_answers = (array) $this->db()->get_col($this->db()->prepare("
                    SELECT DISTINCT a.answer_text 
                    FROM {$this->tables()->answers} a 
                    JOIN {$this->tables()->responses} r ON a.response_id=r.id 
                    WHERE a.question_id=%d AND r.run_id=%d AND TRIM(COALESCE(a.answer_text, '')) <> ''
                ", $q->id, $selected_run));

                if (!empty($db_answers)) {
                    foreach ($db_answers as $ans_item) {
                        $ans_item = trim((string)$ans_item);
                        if ($ans_item !== '' && $ans_item !== '-') {
                            $split_ans = preg_split('/[,\r\n;\|]+/', $ans_item);
                            foreach ($split_ans as $sa) {
                                $sa = trim($sa);
                                if ($sa !== '' && $sa !== '-') {
                                    $clean_c = $this->clean_criteria_text($sa) ?: $sa;
                                    if ($clean_c !== '') $choices[] = $clean_c;
                                }
                            }
                        }
                    }
                }

                // 3. Dari survey_values yang sudah dikoleksi
                foreach ($survey_values as $res_data) {
                    if (isset($res_data['answers'][$q->id])) {
                        $raw_ans_val = $res_data['answers'][$q->id];
                        $ans_arr = is_array($raw_ans_val) ? $raw_ans_val : array($raw_ans_val);
                        foreach ($ans_arr as $a) {
                            $a = trim($a);
                            if ($a !== '' && $a !== '-') {
                                $clean_c = $this->clean_criteria_text($a) ?: $a;
                                if ($clean_c !== '') $choices[] = $clean_c;
                            }
                        }
                    }
                }

                $choices = array_values(array_unique(array_filter($choices)));
                // Fallback jika kosong untuk kota / tempat kerja
                if (empty($choices) && (stripos($q->question_text, 'lokasi') !== false || stripos($q->question_text, 'tempat') !== false || stripos($q->question_text, 'kota') !== false || $code === 'T4')) {
                    $choices = array('Banyuwangi', 'Jember', 'Bali', 'Malang', 'Surabaya', 'Jakarta');
                }
                $q_choices_map[$code] = $choices;
            }

            // STEP 2: TABEL 1 (DATA SURVEY RESPONDEN LENGKAP DENGAN MULTI KOLOM TABEL ACUAN)
            $calc_cols = array_filter($existing_sections, function($sec){ return !empty($sec->show_in_table); });

            echo '<div class="at-card"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px"><h3 class="at-title" style="margin:0"><span class="at-num">2</span> TABEL 1: Data Responden Survey (Lengkap dengan Kolom Tabel Acuan Nilai)</h3><span style="font-size:12px;color:#0284c7;background:#e0f2fe;padding:4px 10px;border-radius:12px;font-weight:600">' . count($detail_data['rows']) . ' Responden Terkumpul</span></div>';
            echo '<div class="at-table-scroll-wrap"><table class="at-table"><thead><tr>';
            foreach ($detail_data['headers'] as $h_idx => $h) {
                $is_ref_h = (!empty($detail_data['ref_col_indices']) && in_array($h_idx, $detail_data['ref_col_indices'], true));
                echo $this->render_th($h, $is_ref_h);
            }
            if (!empty($calc_cols)) {
                foreach ($calc_cols as $cc) {
                    echo '<th style="background:#dcfce7;color:#166534;vertical-align:top;min-width:130px;padding:8px 10px"><span style="display:inline-block;background:#166534;color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;margin-bottom:4px">📊 HASIL</span><div style="font-size:11px;font-weight:700;line-height:1.3;white-space:normal">' . esc_html($cc->title) . '</div></th>';
                }
            }
            echo '</tr></thead><tbody>';
            if (!empty($detail_data['rows'])) {
                $row_idx = 0;
                $survey_keys = array_keys($survey_values);
                foreach ($detail_data['rows'] as $row_data) {
                    $curr_rid = $survey_keys[$row_idx] ?? 0;
                    $r_data = $survey_values[$curr_rid] ?? array();
                    $row_idx++;
                    echo '<tr>';
                    foreach ($row_data as $c_idx => $c_val) {
                        if ($c_idx === 0) {
                            echo '<td>' . esc_html($c_val) . '</td>';
                        } elseif ($c_idx === 1) {
                            echo '<td><strong>' . esc_html($c_val) . '</strong></td>';
                        } elseif (!empty($detail_data['ref_col_indices']) && in_array($c_idx, $detail_data['ref_col_indices'], true)) {
                            echo '<td style="background:#f0fdf4;font-weight:bold;color:#166534"><span style="background:#dcfce7;padding:3px 8px;border-radius:6px">' . esc_html($c_val) . '</span></td>';
                        } else {
                            echo '<td>' . esc_html($c_val) . '</td>';
                        }
                    }
                    if (!empty($calc_cols)) {
                        foreach ($calc_cols as $cc) {
                            $val = $this->evaluate_single_respondent_column($cc->formula, $r_data, $questions, $active_tables);
                            echo '<td style="font-weight:bold;color:#166534;background:#f0fdf4"><span style="background:#dcfce7;padding:3px 8px;border-radius:6px">' . esc_html($val) . '</span></td>';
                        }
                    }
                    echo '</tr>';
                }
            } else { echo '<tr><td colspan="' . (count($detail_data['headers']) + count($calc_cols)) . '" style="text-align:center;color:#64748b;padding:16px">Belum ada data responden untuk survey ini.</td></tr>'; }
            echo '</tbody></table></div></div>';

            // STEP 3: TABEL 2 (TABEL ACUAN VALUE & NILAI MANDIRI - MULTI-AKTIF)
            echo '<div class="at-card"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px">';
            echo '<h3 class="at-title" style="margin:0"><span class="at-num">3</span> TABEL 2: Tabel Acuan Value & Nilai Mandiri (Multi-Aktif Sekaligus)</h3>';
            echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
            echo '<button type="button" class="button at-enable-all-btn" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0;font-weight:600">⚡ Aktifkan Semua</button>';
            echo '<button type="button" class="button at-disable-all-btn" style="background:#fff1f2;color:#9f1239;border-color:#fecdd3;font-weight:600">🚫 Nonaktifkan Semua</button>';
            echo '<button type="button" class="button button-primary" id="at_add_ref_btn">➕ Buat Tabel Baru</button>';
            echo '</div></div>';

            echo '<p style="color:#64748b;font-size:12px;margin:0 0 12px 0">Anda dapat mengaktifkan <strong>lebih dari satu tabel acuan sekaligus (multi-aktif)</strong>. Setiap tabel aktif akan otomatis muncul sebagai kolom baru (contoh: <code>T5</code>, <code>T6</code>, dst.) pada Tabel Responden di atas dan dapat digunakan dalam rumus DSL.</p>';

            echo '<table class="at-table" style="margin-bottom:16px"><thead><tr><th>Nama Tabel Acuan</th><th>Target Kolom Acuan</th><th>Tipe Data</th><th>Status Multi-Aktif</th><th style="text-align:right">Aksi Edit & Hapus</th></tr></thead><tbody>';

            if (!empty($all_ref['tables'])) {
                foreach ($all_ref['tables'] as $tid => $tconf) {
                    $is_act = in_array($tid, $active_ids, true);
                    $is_currently_editing = ($tid === $editing_id);
                    $display_target = $tconf['question_code'] ?? 'T4';
                    if (strpos($display_target, 'P') === 0) $display_target = 'T' . substr($display_target, 1);
                    $dt_label = $this->get_datatype_label($tconf['data_type'] ?? 'currency');

                    $status_badge = $is_act
                        ? '<button type="button" class="button button-small at-toggle-active-btn" data-id="' . esc_attr($tid) . '" style="background:#dcfce7;color:#166534;border-color:#86efac;font-weight:700">🟢 Aktif (Digunakan)</button>'
                        : '<button type="button" class="button button-small at-toggle-active-btn" data-id="' . esc_attr($tid) . '" style="background:#f1f5f9;color:#64748b;font-weight:600">⚪ Nonaktif (Klik Aktifkan)</button>';

                    $edit_btn = $is_currently_editing
                        ? '<span style="background:#e0f2fe;color:#0369a1;font-size:11px;padding:3px 8px;border-radius:6px;font-weight:700;margin-right:6px">✏️ Sedang Diedit</span>'
                        : '<button type="button" class="button button-small at-switch-editing-btn" data-id="' . esc_attr($tid) . '" style="margin-right:6px">✏️ Edit Isian</button>';

                    echo '<tr style="' . ($is_currently_editing ? 'background:#f0f9ff' : '') . '">';
                    echo '<td><strong>' . esc_html($tconf['title']) . '</strong> ' . ($is_currently_editing ? '<span style="color:#0284c7;font-size:11px">(sedang dibuka)</span>' : '') . '</td>';
                    echo '<td><code style="background:#e0f2fe;color:#0369a1;padding:2px 6px;border-radius:4px">' . esc_html($display_target) . '</code></td>';
                    echo '<td><code style="font-size:11px">' . esc_html($dt_label) . '</code></td>';
                    echo '<td>' . $status_badge . '</td>';
                    echo '<td style="text-align:right">' . $edit_btn . '<button type="button" class="button button-small at-del-ref-btn" data-id="' . esc_attr($tid) . '" style="color:#d63638">🗑️ Hapus</button></td>';
                    echo '</tr>';
                }
            } else {
                echo '<tr><td colspan="5" style="text-align:center;color:#64748b;padding:12px">Belum ada tabel acuan tersimpan. Klik <strong>➕ Buat Tabel Baru</strong> untuk membuat tabel acuan nilai.</td></tr>';
            }
            echo '</tbody></table>';

            // PANEL FORM EDITOR TABEL ACUAN YANG SEDANG DIEDIT
            if ($editing_id !== 'none' && !empty($editing_table) && isset($editing_table['id'])) {
                $saved_qcode = $editing_table['question_code'] ?? 'T4';
                if (strpos($saved_qcode, 'P') === 0) $saved_qcode = 'T' . substr($saved_qcode, 1);
                // Normalisasi tipe lama agar selected() bekerja dengan benar
                $saved_datatype = $this->normalize_datatype($editing_table['data_type'] ?? 'currency');
                $ref_items = $this->get_normalized_ref_items($editing_table);

                echo '<div style="border:2px solid #bae6fd;background:#f8fafc;border-radius:8px;padding:16px">';
                echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px"><h4 style="margin:0;color:#0f172a">📝 Mengedit Isian Tabel: <u>' . esc_html($editing_table['title']) . '</u></h4><div style="display:flex;gap:8px"><button type="button" class="button button-primary" id="at_import_q_choices_btn" style="background:#0284c7;border-color:#0369a1;font-weight:600">📥 Muat & Rapikan Opsi Pertanyaan</button> <button type="button" class="button" id="at_add_manual_row_btn" style="background:#e0f2fe;color:#0369a1;border-color:#bae6fd;font-weight:600">➕ Tambah Baris Manual</button> <button type="button" class="button button-primary" id="at_save_ref_btn" style="background:#16a34a;border-color:#15803d;font-weight:700">💾 Simpan Perubahan Tabel</button></div></div>';
                
                // HEADER FORM TABEL ACUAN (TIPE DATA TERLENGKAP)
                echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:14px">';
                echo '<div><label style="font-weight:600;font-size:12px">Nama Tabel:</label><br><input type="text" id="at_ref_title" value="' . esc_attr($editing_table['title']) . '" style="width:100%"></div>';
                echo '<div><label style="font-weight:600;font-size:12px">Target Kolom Acuan (Tabel Responden):</label><br><select id="at_ref_qselect" style="width:100%">';
                foreach ($questions as $q) {
                    $code = $this->question_code($selected_run, $q->id);
                    $num = preg_replace('/[^0-9]/', '', $code);
                    echo '<option value="' . esc_attr($code) . '" ' . selected($saved_qcode, $code, false) . '>' . esc_html($code . ' = TABEL ' . $num . ' — ' . strip_tags($q->question_text)) . '</option>';
                }
                echo '</select></div>';
                echo '<div><label style="font-weight:600;font-size:12px">Tipe Data Nilai:</label><br><select id="at_ref_datatype" style="width:100%">';
                echo '<option value="currency" '     . selected($saved_datatype, 'currency',   false) . '>Currency — Nominal Mata Uang (Rp 3.000.000)</option>';
                echo '<option value="integer" '     . selected($saved_datatype, 'integer',    false) . '>Integer — Bilangan Bulat (10, -5, 100)</option>';
                echo '<option value="decimal" '     . selected($saved_datatype, 'decimal',    false) . '>Decimal — Bilangan Desimal (3.14, 10.5, 3.75)</option>';
                echo '<option value="percentage" '  . selected($saved_datatype, 'percentage', false) . '>Percentage — Persentase (75, 85.5)</option>';
                echo '<option value="string" '      . selected($saved_datatype, 'string',     false) . '>String — Teks ("Banyuwangi", "Poliwangi")</option>';
                echo '<option value="boolean" '     . selected($saved_datatype, 'boolean',    false) . '>Boolean — Benar / Salah (True / False)</option>';
                echo '<option value="date" '        . selected($saved_datatype, 'date',       false) . '>Date — Tanggal (2025-01-15)</option>';
                echo '<option value="time" '        . selected($saved_datatype, 'time',       false) . '>Time — Waktu (08:30)</option>';
                echo '<option value="datetime" '    . selected($saved_datatype, 'datetime',   false) . '>DateTime — Tanggal dan Waktu (2025-01-15 08:30)</option>';
                echo '</select></div>';
                echo '</div>';

                // PANEL CEPAT
                echo '<div class="at-quick-bar">';
                echo '<span style="font-weight:700;font-size:12px;color:#166534">⚡ Terapkan 1 Nilai ke Semua Baris:</span>';
                echo '<div id="at_bulk_val_box" style="display:inline-block"></div>';
                echo '<button type="button" class="button" id="at_apply_bulk_val_btn" style="background:#16a34a;color:#fff;border-color:#15803d;font-weight:600">⚡ Terapkan ke Semua Baris</button>';
                echo '</div>';

                // TABEL BERSIH
                echo '<div style="max-height:280px;overflow-y:auto;overflow-x:auto;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:6px"><table class="at-table" style="background:#fff;margin:0"><thead><tr><th style="width:50%">Kriteria / Pilihan Kategori (Key)</th><th style="width:40%">Input Nilai / Value Acuan</th><th style="width:10%;text-align:right">Aksi</th></tr></thead><tbody id="at_ref_items_tbody">';

                if (!empty($ref_items)) {
                    foreach ($ref_items as $it) {
                        $k = $it['key'] ?? '';
                        $v = $it['val'] ?? '';
                        echo '<tr class="at-ref-row">';
                        echo '<td><input type="text" class="at-ref-key-inp" value="' . esc_attr($k) . '" placeholder="Contoh: Banyuwangi" style="width:100%"></td>';
                        echo '<td class="at-val-cell"><input type="text" class="at-ref-val-inp" value="' . esc_attr($v) . '" placeholder="Nilai acuan" style="width:100%"></td>';
                        echo '<td style="text-align:right"><button type="button" class="button button-small at-del-item-btn" style="color:#d63638">🗑️</button></td>';
                        echo '</tr>';
                    }
                }
                echo '</tbody></table></div></div>';
            }
            echo '</div>'; // Tutup Section 3

            // STEP 4: PERHITUNGAN & DUA CRUD MANDIRI (JUMLAH RESPONDEN & PERSENTASE IKU)
            $tot_resp = !empty($detail_data['rows']) ? count($detail_data['rows']) : 0;
            $first_ref_code = !empty($detail_data['ref_cols']) ? reset($detail_data['ref_cols'])['code'] : 'T4';
            $first_ref_title = !empty($detail_data['ref_cols']) ? reset($detail_data['ref_cols'])['title'] : 'Tabel Acuan';

            $count_sections = array();
            $pct_sections = array();

            if (!empty($existing_sections)) {
                foreach ($existing_sections as $sec) {
                    $sec_formula = $sec->formula ?? '';
                    $sec_title = $sec->title ?? '';
                    $is_pct = (strpos($sec_formula, '%') !== false || stripos($sec_formula, 'PERCENT') !== false || stripos($sec_title, 'persen') !== false || stripos($sec_title, 'iku') !== false || (strpos($sec_formula, '/') !== false && strpos($sec_formula, '*') !== false));
                    if ($is_pct) {
                        $pct_sections[] = $sec;
                    } else {
                        $count_sections[] = $sec;
                    }
                }
            }

            echo '<div class="at-card" style="margin-top:20px">';
            echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px">';
            echo '<h3 class="at-title" style="margin:0"><span class="at-num">4</span> Perhitungan & Persentase Capaian</h3>';
            echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
            echo '<button type="button" class="button button-primary" id="at_apply_iku1_package_btn" style="background:#0284c7;border-color:#0369a1;font-weight:600">✨ Terapkan Paket Lengkap Formula IKU 1</button>';
            echo '<button type="button" class="button" id="at_apply_count_tpl_btn">Template Responden</button>';
            echo '<button type="button" class="button" id="at_apply_pct_tpl_btn">Template Persentase</button>';
            echo '</div></div>';

            // PANEL BANTUAN VARIABEL (SIMPEL, RINGKAS & ELEGAN)
            echo '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:16px">';
            echo '<div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:8px">💡 Bantuan Variabel & Rumus Cepat (Klik pil untuk memasukkan ke kolom formula yang aktif):</div>';

            // Baris Survey
            echo '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px">';
            echo '<span style="font-size:11px;font-weight:700;color:#0369a1;min-width:90px">📋 Survey:</span>';
            foreach ($questions as $q_i => $q_obj) {
                $code = $this->question_code($selected_run, $q_obj->id);
                $num = preg_replace('/[^0-9]/', '', $code);
                $q_short = wp_trim_words(strip_tags($q_obj->question_text), 3, '..');
                echo '<span class="at-pill" data-insert="' . esc_attr($code) . '" title="' . esc_attr($q_obj->question_text) . '">' . esc_html($code . ' (' . $q_short . ')') . '</span>';
            }
            echo '</div>';

            // Baris Tabel Acuan
            if (!empty($detail_data['ref_cols'])) {
                echo '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px">';
                echo '<span style="font-size:11px;font-weight:700;color:#166534;min-width:90px">🏷️ Tabel Acuan:</span>';
                foreach ($detail_data['ref_cols'] as $rci) {
                    $c_code = $rci['code'];
                    $c_title = $rci['title'];
                    echo '<span class="at-pill" data-insert="' . esc_attr($c_code) . '" style="background:#dcfce7;color:#166534;font-weight:600">+ ' . esc_html($c_code . ' (' . $c_title . ')') . '</span>';
                    echo '<span class="at-pill" data-insert="SUM(' . esc_attr($c_code) . ')" style="background:#dcfce7;color:#166534;font-weight:600">+ SUM(' . esc_html($c_code) . ')</span>';
                }
                echo '</div>';
            }

            // Baris Formula Bantuan & Sigma
            echo '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">';
            echo '<span style="font-size:11px;font-weight:700;color:#7c3aed;min-width:90px">⚡ Rumus & Sigma:</span>';
            echo '<span class="at-pill" data-insert="COUNT()" style="background:#fef3c7;color:#92400e;font-weight:bold">+ COUNT()</span>';
            echo '<span class="at-pill" data-insert="ROUND((Σ(i=1..l)(n_i*k_i)/t)*100, 2)" style="background:#fdf4ff;color:#a21caf;font-weight:bold">+ ROUND((Σ(i=1..l)(n_i*k_i)/t)*100, 2)</span>';
            echo '<span class="at-pill" data-insert="Σ(i=1..l) n_i * k_i" style="background:#fdf4ff;color:#a21caf;font-weight:bold">+ Σ(i=1..l) n_i*k_i</span>';
            echo '<span class="at-pill" data-insert="ROUND((Q1 / Q2) * 100, 2)" style="background:#f1f5f9;color:#334155;font-weight:600">+ ROUND((Q1/Q2)*100, 2)</span>';
            if (!empty($first_ref_code)) {
                echo '<span class="at-pill" data-insert="SUM(' . esc_attr($first_ref_code) . ')" style="background:#fef3c7;color:#92400e;font-weight:bold">+ SUM(' . esc_html($first_ref_code) . ')</span>';
                echo '<span class="at-pill" data-insert="=SUM(' . esc_attr($first_ref_code) . ') / COUNT() * 100%" style="background:#ede9fe;color:#6d28d9;font-weight:bold">+ =SUM(' . esc_html($first_ref_code) . ')/COUNT()*100%</span>';
            }
            echo '<span class="at-pill" data-insert="COUNTIF(T1, \'Bekerja\')" style="background:#f8fafc;color:#475569">+ COUNTIF(T1, \'Bekerja\')</span>';
            echo '<span class="at-pill" data-insert="IF(t > 0, 100, 0)" style="background:#f8fafc;color:#475569">+ IF(t > 0, 100, 0)</span>';
            echo '</div>';
            echo '</div>';

            // =========================================================================
            // SEKSI 1: REKAPITULASI RESPONDEN & AKUMULASI BOBOT NILAI
            // =========================================================================
            echo '<div style="background:#fff;border:1px solid #cbd5e1;border-left:4px solid #0284c7;border-radius:8px;padding:16px;margin-bottom:20px">';
            echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">';
            echo '<div><h4 style="margin:0;font-size:14px;color:#0369a1;display:flex;align-items:center;gap:6px"><span>👥</span> Rekapitulasi Responden & Akumulasi Bobot Nilai</h4><p style="margin:2px 0 0 0;font-size:11px;color:#64748b">Kelola seksi hitung jumlah responden, kriteria tertentu, atau akumulasi bobot responden (bisa diisi angka manual misal: <code>50</code>, <code>100</code> atau rumus <code>COUNT()</code>, <code>SUM(' . esc_html($first_ref_code) . ')</code>).</p></div>';
            echo '<div><button type="button" class="button button-primary" id="at_add_count_sec_btn" style="background:#0284c7;border-color:#0369a1;font-weight:600;font-size:12px">➕ Tambah Baris Responden</button></div>';
            echo '</div>';

            echo '<table class="at-table"><thead><tr><th style="width:30%">Nama Metrik / Kriteria Responden</th><th style="width:40%">Nilai Manual / Rumus DSL</th><th style="width:12%;text-align:center">Tampilkan</th><th style="width:13%">Hasil Live</th><th style="width:5%;text-align:right">Aksi</th></tr></thead><tbody id="at_dsl_count_tbody">';

            if (!empty($count_sections)) {
                foreach ($count_sections as $c_idx => $sec) {
                    $sec_title = $sec->title ?? 'Jumlah Responden';
                    $sec_formula = $sec->formula ?? 'COUNT()';
                    $is_shown = !isset($sec->show_in_table) || !empty($sec->show_in_table);
                    $live_result = $this->evaluate_dsl_formula($sec_formula, $survey_values, array(), $questions, $selected_run, $active_tables);
                    echo '<tr class="at-dsl-sec-row">';
                    echo '<td><input type="text" name="sections[c_' . $c_idx . '][title]" value="' . esc_attr($sec_title) . '" required style="width:100%" placeholder="Nama Metrik (misal: Total Responden)"></td>';
                    echo '<td><input type="text" name="sections[c_' . $c_idx . '][formula]" class="at-dsl-formula-inp" value="' . esc_attr($sec_formula) . '" required style="width:100%;font-family:monospace;font-weight:bold" placeholder="Manual: 50 atau Rumus: COUNT()"></td>';
                    echo '<td style="text-align:center"><label><input type="checkbox" name="sections[c_' . $c_idx . '][show_in_table]" value="1" ' . checked($is_shown, true, false) . '> Ya</label></td>';
                    echo '<td><span style="background:#f0f9ff;color:#0369a1;font-weight:700;padding:3px 8px;border-radius:6px;font-size:11px">' . esc_html($live_result) . '</span></td>';
                    echo '<td style="text-align:right"><button type="button" class="button button-small at-del-sec-btn" style="color:#d63638">🗑️</button></td>';
                    echo '</tr>';
                }
            } else {
                echo '<tr id="at_count_empty_row"><td colspan="5" style="text-align:center;color:#64748b;padding:12px">Belum ada baris responden. Klik tombol <strong>➕ Tambah Baris Responden</strong> di atas untuk menambahkan.</td></tr>';
            }
            echo '</tbody></table></div>';

            // =========================================================================
            // SEKSI 2: PERSENTASE CAPAIAN IKU & AMBANG BATAS KUOTA LULUSAN (SLOVIN)
            // =========================================================================
            echo '<div style="background:#fff;border:1px solid #cbd5e1;border-left:4px solid #16a34a;border-radius:8px;padding:16px;margin-bottom:16px">';
            echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">';
            echo '<div><h4 style="margin:0;font-size:14px;color:#166534;display:flex;align-items:center;gap:6px"><span>🎯</span> Persentase Capaian IKU & Ambang Batas Kuota Lulusan (Slovin)</h4><p style="margin:2px 0 0 0;font-size:11px;color:#64748b">Kelola persentase capaian (bisa manual misal: <code>85%</code> atau rumus <code>=SUM(' . esc_html($first_ref_code) . ') / COUNT() * 100%</code>) dan atur toleransi galat.</p></div>';
            echo '<div><button type="button" class="button button-primary" id="at_add_pct_sec_btn" style="background:#16a34a;border-color:#15803d;font-weight:600;font-size:12px">➕ Tambah Baris Persentase</button></div>';
            echo '</div>';

            // SETTING GALAT & JUMLAH LULUSAN SLOVIN (FLEKSIBEL DIATUR MANUAL)
            echo '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 14px;margin-bottom:12px">';
            echo '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">';
            echo '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">';
            echo '<div style="display:flex;align-items:center;gap:6px"><span style="font-weight:700;color:#166534;font-size:12px">🎯 Slovin:</span> <label style="font-size:12px;color:#334155">Total Lulusan (N):</label> <input type="number" id="at_pop_n" value="500" min="1" style="width:80px;padding:3px 6px"></div>';
            echo '<div style="display:flex;align-items:center;gap:6px"><label style="font-size:12px;color:#334155">Tingkat Galat (e):</label> <input type="number" step="0.1" id="at_galat_e" value="2.3" min="0.1" max="50" style="width:65px;padding:3px 6px"> <span style="font-size:12px;color:#334155">%</span></div>';
            echo '</div>';
            echo '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">';
            echo '<span style="font-size:12px;color:#334155">Min. Kuota: <strong id="at_slovin_nmin" style="color:#0f172a">396 Responden</strong></span>';
            echo '<span style="font-size:12px;color:#334155">Terkumpul (t): <strong style="color:#0284c7">' . intval($tot_resp) . ' Responden</strong></span>';
            echo '<div id="at_slovin_status"><span style="background:#dcfce7;color:#166534;font-weight:700;padding:3px 8px;border-radius:10px;font-size:11px">🟢 KUOTA MEMENUHI</span></div>';
            echo '</div>';
            echo '</div></div>';

            echo '<table class="at-table"><thead><tr><th style="width:35%">Nama Indikator / Metrik Persentase</th><th style="width:45%">Nilai Manual (%) / Rumus Persentase</th><th style="width:15%">Hasil Live</th><th style="width:5%;text-align:right">Aksi</th></tr></thead><tbody id="at_dsl_pct_tbody">';

            if (!empty($pct_sections)) {
                foreach ($pct_sections as $p_idx => $sec) {
                    $sec_title = $sec->title ?? 'Persentase Capaian IKU 1 (%)';
                    $sec_formula = $sec->formula ?? ('=SUM(' . $first_ref_code . ') / COUNT() * 100%');
                    $live_result = $this->evaluate_dsl_formula($sec_formula, $survey_values, array(), $questions, $selected_run, $active_tables);
                    echo '<tr class="at-dsl-sec-row">';
                    echo '<td><input type="text" name="sections[p_' . $p_idx . '][title]" value="' . esc_attr($sec_title) . '" required style="width:100%" placeholder="Nama Metrik (misal: Persentase Capaian IKU 1)"></td>';
                    echo '<td><input type="text" name="sections[p_' . $p_idx . '][formula]" class="at-dsl-formula-inp" value="' . esc_attr($sec_formula) . '" required style="width:100%;font-family:monospace;font-weight:bold" placeholder="Manual: 85% atau Rumus: =SUM(' . esc_attr($first_ref_code) . ')/COUNT()*100%"></td>';
                    echo '<input type="hidden" name="sections[p_' . $p_idx . '][show_in_table]" value="0">';
                    echo '<td><span style="background:#f0fdf4;color:#166534;font-weight:700;padding:3px 8px;border-radius:6px;font-size:11px">' . esc_html($live_result) . '</span></td>';
                    echo '<td style="text-align:right"><button type="button" class="button button-small at-del-sec-btn" style="color:#d63638">🗑️</button></td>';
                    echo '</tr>';
                }
            } else {
                echo '<tr id="at_pct_empty_row"><td colspan="4" style="text-align:center;color:#64748b;padding:12px">Belum ada baris persentase. Klik tombol <strong>➕ Tambah Baris Persentase</strong> di atas untuk menambahkan.</td></tr>';
            }
            echo '</tbody></table></div>';
            echo '</div></div>';
        }

        echo '<div style="display:flex;justify-content:flex-end;margin-top:20px"><button class="button button-primary button-large">' . ($is_edit ? 'Update Laporan' : 'Simpan Laporan') . '</button></div></form>';

        ?>
        <script>
        (function(){
            var pageUrl = "<?php echo esc_url_raw(admin_url('admin.php?page=' . self::SLUG_REPORT)); ?>";
            var ajaxUrl = "<?php echo esc_url_raw(admin_url('admin-ajax.php')); ?>";
            var nonce = "<?php echo esc_js(wp_create_nonce('at_ext_save_report')); ?>";
            var qChoicesMap = <?php echo json_encode($q_choices_map ?? array()); ?>;
            var totResp = <?php echo intval($tot_resp ?? 0); ?>;
            var activeFormulaInp = null;

            // MEMORI PERMANEN NILAI ACUAN
            window.atValuesMemory = {};
            var activeSavedItems = <?php echo json_encode($ref_items ?? array()); ?>;
            if(activeSavedItems && Array.isArray(activeSavedItems)){
                activeSavedItems.forEach(function(it){
                    if(it.key){
                        window.atValuesMemory[it.key.toLowerCase().trim()] = (it.val !== undefined && it.val !== null) ? String(it.val) : "";
                    }
                });
            }

            function captureCurrentTableValues(){
                var rows = document.querySelectorAll("#at_ref_items_tbody tr.at-ref-row");
                rows.forEach(function(tr){
                    var kInp = tr.querySelector(".at-ref-key-inp");
                    var vInp = tr.querySelector(".at-ref-val-inp");
                    if(kInp && vInp){
                        var k = kInp.value.trim().toLowerCase();
                        if(k) window.atValuesMemory[k] = vInp.value;
                    }
                });
            }

            function createValInputHTML(type, val){
                val = (val !== undefined && val !== null) ? String(val) : "";
                var safeVal = val.replace(/"/g, '&quot;');

                if(type === "boolean"){
                    var isTrue = (val === "1" || val.toLowerCase() === "true" || val.toLowerCase() === "ya");
                    return '<select class="at-ref-val-inp" style="width:100%">' +
                           '<option value=""' + (val === "" ? ' selected' : '') + '>-- Pilih Status --</option>' +
                           '<option value="1"' + (isTrue && val !== "" ? ' selected' : '') + '>True (Ya / Cocok)</option>' +
                           '<option value="0"' + (!isTrue && val !== "" ? ' selected' : '') + '>False (Tidak / Gugur)</option>' +
                           '</select>';

                } else if(type === "currency"){
                    return '<input type="text" class="at-ref-val-inp" value="' + safeVal + '" placeholder="Nominal tanpa Rp, misal: 3000000 atau 3.000.000" style="width:100%">';

                } else if(type === "integer"){
                    return '<input type="number" step="1" class="at-ref-val-inp" value="' + safeVal + '" placeholder="Bilangan bulat, misal: 10, -5, 100" style="width:100%">';

                } else if(type === "decimal"){
                    return '<input type="number" step="any" class="at-ref-val-inp" value="' + safeVal + '" placeholder="Bilangan desimal, misal: 3.14, 10.5, 3.75" style="width:100%">';

                } else if(type === "percentage"){
                    return '<input type="number" step="any" min="0" max="100" class="at-ref-val-inp" value="' + safeVal + '" placeholder="Angka persentase, misal: 75 atau 85.5" style="width:100%">';

                } else if(type === "date"){
                    return '<input type="date" class="at-ref-val-inp" value="' + safeVal + '" style="width:100%">';

                } else if(type === "time"){
                    return '<input type="time" class="at-ref-val-inp" value="' + safeVal + '" style="width:100%">';

                } else if(type === "datetime"){
                    // datetime-local pakai format YYYY-MM-DDTHH:mm
                    var dtVal = safeVal.replace(/ /, 'T').replace(/:00$/, '').substring(0, 16);
                    return '<input type="datetime-local" class="at-ref-val-inp" value="' + dtVal + '" style="width:100%">';

                } else {
                    // String / fallback
                    return '<input type="text" class="at-ref-val-inp" value="' + safeVal + '" placeholder="Teks, misal: Banyuwangi, Poliwangi" style="width:100%">';
                }
            }

            function syncAllValueControls(){
                var dtSelect = document.getElementById("at_ref_datatype");
                if(!dtSelect) return;
                var currentType = dtSelect.value || "currency";

                // Update bulk-apply input sesuai tipe
                var bulkBox = document.getElementById("at_bulk_val_box");
                if(bulkBox){
                    if(currentType === "boolean"){
                        bulkBox.innerHTML = '<select id="at_bulk_val_inp" style="width:200px;font-size:12px;padding:4px 8px"><option value="1">True (Ya / Cocok)</option><option value="0">False (Tidak / Gugur)</option></select>';
                    } else if(currentType === "currency"){
                        bulkBox.innerHTML = '<input type="text" id="at_bulk_val_inp" placeholder="Nominal tanpa Rp, misal: 3000000" style="width:240px;padding:4px 8px;font-size:12px">';
                    } else if(currentType === "integer"){
                        bulkBox.innerHTML = '<input type="number" step="1" id="at_bulk_val_inp" placeholder="Bilangan bulat, misal: 10, 100" style="width:240px;padding:4px 8px;font-size:12px">';
                    } else if(currentType === "decimal"){
                        bulkBox.innerHTML = '<input type="number" step="any" id="at_bulk_val_inp" placeholder="Desimal, misal: 3.14, 10.5" style="width:240px;padding:4px 8px;font-size:12px">';
                    } else if(currentType === "percentage"){
                        bulkBox.innerHTML = '<input type="number" step="any" min="0" max="100" id="at_bulk_val_inp" placeholder="Angka %, misal: 75 atau 85.5" style="width:240px;padding:4px 8px;font-size:12px">';
                    } else if(currentType === "date"){
                        bulkBox.innerHTML = '<input type="date" id="at_bulk_val_inp" style="width:200px;padding:4px 8px;font-size:12px">';
                    } else if(currentType === "time"){
                        bulkBox.innerHTML = '<input type="time" id="at_bulk_val_inp" style="width:160px;padding:4px 8px;font-size:12px">';
                    } else if(currentType === "datetime"){
                        bulkBox.innerHTML = '<input type="datetime-local" id="at_bulk_val_inp" style="width:220px;padding:4px 8px;font-size:12px">';
                    } else {
                        bulkBox.innerHTML = '<input type="text" id="at_bulk_val_inp" placeholder="Teks, misal: Banyuwangi" style="width:240px;padding:4px 8px;font-size:12px">';
                    }
                }

                // Update input di setiap baris tabel
                var rows = document.querySelectorAll("#at_ref_items_tbody tr.at-ref-row");
                rows.forEach(function(tr){
                    var valCell = tr.querySelector(".at-val-cell");
                    if(!valCell) return;
                    var existingInp = valCell.querySelector(".at-ref-val-inp");
                    var currVal = existingInp ? existingInp.value : "";
                    valCell.innerHTML = createValInputHTML(currentType, currVal);
                });
            }

            document.addEventListener("change", function(e){
                if(e.target && e.target.id === "at_ref_datatype"){
                    syncAllValueControls();
                }
            });
            syncAllValueControls();

            function populateRowsForQuestion(qcode){
                captureCurrentTableValues();
                var choices = qChoicesMap[qcode] || [];
                var tbody = document.getElementById("at_ref_items_tbody");
                if(!tbody) return;
                if(choices.length === 0){
                    choices = ["Banyuwangi", "Jember", "Bali", "Malang", "Surabaya"];
                }
                tbody.innerHTML = "";
                var dtSelect = document.getElementById("at_ref_datatype");
                var currentType = dtSelect ? dtSelect.value : "currency";
                
                choices.forEach(function(cText){
                    var cleanKey = cText.toLowerCase().trim();
                    var rememberedVal = (window.atValuesMemory[cleanKey] !== undefined) ? window.atValuesMemory[cleanKey] : "";
                    
                    var tr = document.createElement("tr"); tr.className = "at-ref-row";
                    tr.innerHTML = '<td><input type="text" class="at-ref-key-inp" value="' + cText.replace(/"/g, '&quot;') + '" style="width:100%"></td>' +
                                   '<td class="at-val-cell">' + createValInputHTML(currentType, rememberedVal) + '</td>' +
                                   '<td style="text-align:right"><button type="button" class="button button-small at-del-item-btn" style="color:#d63638">🗑️</button></td>';
                    tbody.appendChild(tr);
                });
                syncAllValueControls();
            }

            document.addEventListener("change", function(e){
                if(e.target && e.target.id === "at_ref_qselect"){
                    var qcode = e.target.value;
                    populateRowsForQuestion(qcode);
                }
            });

            function calcSlovin(){
                var popEl = document.getElementById("at_pop_n");
                var galatEl = document.getElementById("at_galat_e");
                var nminEl = document.getElementById("at_slovin_nmin");
                var statusEl = document.getElementById("at_slovin_status");
                if(!popEl || !nminEl || !statusEl) return;

                var N = parseInt(popEl.value) || 0;
                var ePct = galatEl ? (parseFloat(galatEl.value) || 2.3) : 2.3;
                if(N <= 0 || ePct <= 0){
                    nminEl.innerText = "0 Responden";
                    statusEl.innerHTML = '<span style="background:#f1f5f9;color:#475569;font-weight:700;padding:3px 8px;border-radius:10px;font-size:11px">⚪ Masukkan N & Galat</span>';
                    return;
                }
                var eDec = ePct / 100;
                var nMin = Math.ceil(N / (1 + (N * (eDec * eDec))));
                nminEl.innerText = nMin + " Responden";

                if(totResp >= nMin){
                    statusEl.innerHTML = '<span style="background:#dcfce7;color:#166534;font-weight:700;padding:3px 8px;border-radius:10px;font-size:11px">🟢 KUOTA MEMENUHI (' + totResp + ' / ' + nMin + ')</span>';
                } else {
                    var sisa = nMin - totResp;
                    statusEl.innerHTML = '<span style="background:#fee2e2;color:#991b1b;font-weight:700;padding:3px 8px;border-radius:10px;font-size:11px">🔴 KURANG ' + sisa + ' RESPONDEN (' + totResp + ' / ' + nMin + ')</span>';
                }
            }

            document.addEventListener("input", function(e){
                if(e.target && (e.target.id === "at_pop_n" || e.target.id === "at_galat_e")) calcSlovin();
            });
            calcSlovin();

            document.addEventListener("focusin", function(e){
                if(e.target && e.target.classList.contains("at-dsl-formula-inp")) activeFormulaInp = e.target;
            });

            var mainForm = document.getElementById("at_main_report_form");
            if(mainForm){
                mainForm.addEventListener("submit", function(e){
                    var formulaInps = document.querySelectorAll(".at-dsl-formula-inp");
                    for(var i=0; i<formulaInps.length; i++){
                        var fInp = formulaInps[i];
                        var row = fInp.closest("tr");
                        var tInp = row ? row.querySelector('input[name*="[title]"]') : null;
                        var fVal = fInp.value.trim();
                        var title = tInp ? tInp.value : ("Baris " + (i+1));
                        var openC = (fVal.match(/\(/g) || []).length;
                        var closeC = (fVal.match(/\)/g) || []).length;
                        if(openC !== closeC){
                            alert("⚠️ ALERTA FORMULA DSL SINTAKS SALAH:\n\nPada seksi '" + title + "', jumlah tanda kurung buka '(' dan tutup ')' tidak seimbang!");
                            fInp.focus(); e.preventDefault(); return false;
                        }
                    }
                });
            }

            document.addEventListener("click", function(e){
                if(!e.target) return;
                var t = e.target;
                var refTarget = "<?php echo esc_js($first_ref_code); ?>";
                var refTitle = "<?php echo esc_js($first_ref_title); ?>";

                // 1. SISIP PAKET LENGKAP IKU 1 KE 2 CRUD SEKALIGUS
                var applyIku1Btn = t.closest("#at_apply_iku1_package_btn");
                if(applyIku1Btn){
                    var countTbody = document.getElementById("at_dsl_count_tbody");
                    var pctTbody = document.getElementById("at_dsl_pct_tbody");
                    if(countTbody) countTbody.innerHTML = "";
                    if(pctTbody) pctTbody.innerHTML = "";

                    // Isi CRUD 1
                    if(countTbody){
                        var tr1 = document.createElement("tr"); tr1.className = "at-dsl-sec-row";
                        tr1.innerHTML = '<td><input type="text" name="sections[c_0][title]" value="Jumlah Responden Terkumpul (Total Responden)" required style="width:100%"></td>' +
                                        '<td><input type="text" name="sections[c_0][formula]" class="at-dsl-formula-inp" value="COUNT()" required style="width:100%;font-family:monospace;font-weight:bold"></td>' +
                                        '<td style="text-align:center"><label><input type="checkbox" name="sections[c_0][show_in_table]" value="1" checked> Ya</label></td>' +
                                        '<td><span style="background:#f0f9ff;color:#0369a1;font-weight:700;padding:4px 8px;border-radius:6px;font-size:12px">Live Sync</span></td>' +
                                        '<td style="text-align:right"><button type="button" class="button button-small at-del-sec-btn" style="color:#d63638">🗑️</button></td>';
                        countTbody.appendChild(tr1);

                        var tr2 = document.createElement("tr"); tr2.className = "at-dsl-sec-row";
                        tr2.innerHTML = '<td><input type="text" name="sections[c_1][title]" value="Total Bobot Responden Memenuhi Syarat (' + refTarget + ' — ' + refTitle + ')" required style="width:100%"></td>' +
                                        '<td><input type="text" name="sections[c_1][formula]" class="at-dsl-formula-inp" value="SUM(' + refTarget + ')" required style="width:100%;font-family:monospace;font-weight:bold"></td>' +
                                        '<td style="text-align:center"><label><input type="checkbox" name="sections[c_1][show_in_table]" value="1" checked> Ya</label></td>' +
                                        '<td><span style="background:#f0f9ff;color:#0369a1;font-weight:700;padding:4px 8px;border-radius:6px;font-size:12px">Live Sync</span></td>' +
                                        '<td style="text-align:right"><button type="button" class="button button-small at-del-sec-btn" style="color:#d63638">🗑️</button></td>';
                        countTbody.appendChild(tr2);
                    }

                    // Isi CRUD 2
                    if(pctTbody){
                        var tr3 = document.createElement("tr"); tr3.className = "at-dsl-sec-row";
                        tr3.innerHTML = '<td><input type="text" name="sections[p_0][title]" value="Persentase Capaian IKU 1 (%)" required style="width:100%"></td>' +
                                        '<td><input type="text" name="sections[p_0][formula]" class="at-dsl-formula-inp" value="=SUM(' + refTarget + ') / COUNT() * 100%" required style="width:100%;font-family:monospace;font-weight:bold"></td>' +
                                        '<td style="text-align:center"><label><input type="checkbox" name="sections[p_0][show_in_table]" value="1" checked> Ya</label></td>' +
                                        '<td><span style="background:#f0fdf4;color:#166534;font-weight:700;padding:4px 8px;border-radius:6px;font-size:12px">Live Sync</span></td>' +
                                        '<td style="text-align:right"><button type="button" class="button button-small at-del-sec-btn" style="color:#d63638">🗑️</button></td>';
                        pctTbody.appendChild(tr3);
                    }
                    return;
                }

                // 2. SISIP TEMPLATE CRUD 1 (JUMLAH RESPONDEN)
                var applyCountTplBtn = t.closest("#at_apply_count_tpl_btn");
                if(applyCountTplBtn){
                    var countTbody = document.getElementById("at_dsl_count_tbody");
                    if(!countTbody) return;
                    countTbody.innerHTML = "";

                    var tr1 = document.createElement("tr"); tr1.className = "at-dsl-sec-row";
                    tr1.innerHTML = '<td><input type="text" name="sections[c_0][title]" value="Jumlah Responden Terkumpul (Total Responden)" required style="width:100%"></td>' +
                                    '<td><input type="text" name="sections[c_0][formula]" class="at-dsl-formula-inp" value="COUNT()" required style="width:100%;font-family:monospace;font-weight:bold"></td>' +
                                    '<td style="text-align:center"><label><input type="checkbox" name="sections[c_0][show_in_table]" value="1" checked> Ya</label></td>' +
                                    '<td><span style="background:#f0f9ff;color:#0369a1;font-weight:700;padding:4px 8px;border-radius:6px;font-size:12px">Live Sync</span></td>' +
                                    '<td style="text-align:right"><button type="button" class="button button-small at-del-sec-btn" style="color:#d63638">🗑️</button></td>';
                    countTbody.appendChild(tr1);

                    var tr2 = document.createElement("tr"); tr2.className = "at-dsl-sec-row";
                    tr2.innerHTML = '<td><input type="text" name="sections[c_1][title]" value="Total Bobot Responden Memenuhi Syarat (' + refTarget + ' — ' + refTitle + ')" required style="width:100%"></td>' +
                                    '<td><input type="text" name="sections[c_1][formula]" class="at-dsl-formula-inp" value="SUM(' + refTarget + ')" required style="width:100%;font-family:monospace;font-weight:bold"></td>' +
                                    '<td style="text-align:center"><label><input type="checkbox" name="sections[c_1][show_in_table]" value="1" checked> Ya</label></td>' +
                                    '<td><span style="background:#f0f9ff;color:#0369a1;font-weight:700;padding:4px 8px;border-radius:6px;font-size:12px">Live Sync</span></td>' +
                                    '<td style="text-align:right"><button type="button" class="button button-small at-del-sec-btn" style="color:#d63638">🗑️</button></td>';
                    countTbody.appendChild(tr2);
                    return;
                }

                // 3. SISIP TEMPLATE CRUD 2 (PERSENTASE IKU)
                var applyPctTplBtn = t.closest("#at_apply_pct_tpl_btn");
                if(applyPctTplBtn){
                    var pctTbody = document.getElementById("at_dsl_pct_tbody");
                    if(!pctTbody) return;
                    pctTbody.innerHTML = "";

                    var tr1 = document.createElement("tr"); tr1.className = "at-dsl-sec-row";
                    tr1.innerHTML = '<td><input type="text" name="sections[p_0][title]" value="Persentase Capaian IKU 1 (%)" required style="width:100%"></td>' +
                                    '<td><input type="text" name="sections[p_0][formula]" class="at-dsl-formula-inp" value="=SUM(' + refTarget + ') / COUNT() * 100%" required style="width:100%;font-family:monospace;font-weight:bold"></td>' +
                                    '<td style="text-align:center"><label><input type="checkbox" name="sections[p_0][show_in_table]" value="1" checked> Ya</label></td>' +
                                    '<td><span style="background:#f0fdf4;color:#166534;font-weight:700;padding:4px 8px;border-radius:6px;font-size:12px">Live Sync</span></td>' +
                                    '<td style="text-align:right"><button type="button" class="button button-small at-del-sec-btn" style="color:#d63638">🗑️</button></td>';
                    pctTbody.appendChild(tr1);
                    return;
                }

                // 4. TAMBAH BARIS KE CRUD 1 (JUMLAH RESPONDEN)
                var addCountSecBtn = t.closest("#at_add_count_sec_btn");
                if(addCountSecBtn){
                    var tbody = document.getElementById("at_dsl_count_tbody"); if(!tbody) return;
                    var emptyRow = document.getElementById("at_count_empty_row");
                    if(emptyRow) emptyRow.remove();
                    var uid = "c_" + Date.now() + "_" + Math.floor(Math.random()*1000);
                    var tr = document.createElement("tr"); tr.className = "at-dsl-sec-row";
                    tr.innerHTML = '<td><input type="text" name="sections[' + uid + '][title]" value="" required style="width:100%" placeholder="Nama Metrik (misal: Total Responden)"></td>' +
                                   '<td><input type="text" name="sections[' + uid + '][formula]" class="at-dsl-formula-inp" value="COUNT()" required style="width:100%;font-family:monospace;font-weight:bold" placeholder="Manual: 50 atau Rumus: COUNT()"></td>' +
                                   '<td style="text-align:center"><label><input type="checkbox" name="sections[' + uid + '][show_in_table]" value="1" checked> Ya</label></td>' +
                                   '<td><span style="background:#f0f9ff;color:#0369a1;font-weight:700;padding:4px 8px;border-radius:6px;font-size:12px">Live Sync</span></td>' +
                                   '<td style="text-align:right"><button type="button" class="button button-small at-del-sec-btn" style="color:#d63638">🗑️</button></td>';
                    tbody.appendChild(tr);
                    return;
                }

                // 5. TAMBAH BARIS KE SEKSI 2 (PERSENTASE IKU)
                var addPctSecBtn = t.closest("#at_add_pct_sec_btn");
                if(addPctSecBtn){
                    var tbody = document.getElementById("at_dsl_pct_tbody"); if(!tbody) return;
                    var emptyRow = document.getElementById("at_pct_empty_row");
                    if(emptyRow) emptyRow.remove();
                    var uid = "p_" + Date.now() + "_" + Math.floor(Math.random()*1000);
                    var tr = document.createElement("tr"); tr.className = "at-dsl-sec-row";
                    tr.innerHTML = '<td><input type="text" name="sections[' + uid + '][title]" value="" required style="width:100%" placeholder="Nama Metrik (misal: Persentase Capaian IKU 1)"></td>' +
                                   '<td><input type="text" name="sections[' + uid + '][formula]" class="at-dsl-formula-inp" value="" required style="width:100%;font-family:monospace;font-weight:bold" placeholder="Manual: 85% atau Rumus: =SUM(T14)/COUNT()*100%"></td>' +
                                   '<input type="hidden" name="sections[' + uid + '][show_in_table]" value="0">' +
                                   '<td><span style="background:#f0fdf4;color:#166534;font-weight:700;padding:4px 8px;border-radius:6px;font-size:12px">Live Sync</span></td>' +
                                   '<td style="text-align:right"><button type="button" class="button button-small at-del-sec-btn" style="color:#d63638">🗑️</button></td>';
                    tbody.appendChild(tr);
                    return;
                }

                var applyBulkValBtn = t.closest("#at_apply_bulk_val_btn");
                if(applyBulkValBtn){
                    var bulkInp = document.getElementById("at_bulk_val_inp");
                    var bulkVal = bulkInp ? bulkInp.value.trim() : "";
                    if(bulkVal === ""){ if(bulkInp) bulkInp.focus(); return; }
                    var valInps = document.querySelectorAll("#at_ref_items_tbody tr.at-ref-row .at-ref-val-inp");
                    valInps.forEach(function(inp){
                        inp.value = bulkVal;
                        var row = inp.closest("tr");
                        if(row){
                            var kInp = row.querySelector(".at-ref-key-inp");
                            if(kInp && kInp.value.trim()){
                                window.atValuesMemory[kInp.value.toLowerCase().trim()] = bulkVal;
                            }
                        }
                    });
                    return;
                }

                var pillBtn = t.closest(".at-pill");
                if(pillBtn){
                    var toInsert = pillBtn.getAttribute("data-insert");
                    if(!activeFormulaInp){
                        var inps = document.querySelectorAll(".at-dsl-formula-inp");
                        if(inps.length) activeFormulaInp = inps[inps.length - 1];
                    }
                    if(activeFormulaInp){
                        activeFormulaInp.value = (activeFormulaInp.value ? activeFormulaInp.value + " " : "") + toInsert;
                        activeFormulaInp.focus();
                    }
                    return;
                }

                var delItemBtn = t.closest(".at-del-item-btn") || t.closest(".at-del-sec-btn");
                if(delItemBtn){
                    var row = delItemBtn.closest("tr");
                    if(row) row.remove();
                    return;
                }

                var addDslSecBtn = t.closest("#at_add_dsl_sec_btn");
                if(addDslSecBtn){
                    var tbody = document.getElementById("at_dsl_sec_tbody"); if(!tbody) return;
                    var idx = tbody.querySelectorAll("tr").length; var tr = document.createElement("tr"); tr.className = "at-dsl-sec-row";
                    var refTarget = "<?php echo esc_js($first_ref_code); ?>";
                    tr.innerHTML = '<td><input type="text" name="sections[' + idx + '][title]" value="Kolom Bobot ' + (idx + 1) + '" required style="width:100%" placeholder="Nama Kolom"></td>' +
                                   '<td><input type="text" name="sections[' + idx + '][formula]" class="at-dsl-formula-inp" value="SUM(' + refTarget + ')" required style="width:100%;font-family:monospace;font-weight:bold" placeholder="Contoh: SUM(' + refTarget + ') atau IF(T2<6, 1.0, 0.6)"></td>' +
                                   '<td style="text-align:center"><label><input type="checkbox" name="sections[' + idx + '][show_in_table]" value="1" checked> Ya</label></td>' +
                                   '<td><span style="background:#f0f9ff;color:#0369a1;font-weight:700;padding:4px 8px;border-radius:6px;font-size:12px">Pending</span></td>' +
                                   '<td style="text-align:right"><button type="button" class="button button-small at-del-sec-btn" style="color:#d63638">🗑️</button></td>';
                    tbody.appendChild(tr);
                    return;
                }

                var addManualRowBtn = t.closest("#at_add_manual_row_btn");
                if(addManualRowBtn){
                    var tbody = document.getElementById("at_ref_items_tbody"); if(!tbody) return;
                    var dtSelect = document.getElementById("at_ref_datatype");
                    var currentType = dtSelect ? dtSelect.value : "currency";
                    var tr = document.createElement("tr"); tr.className = "at-ref-row";
                    tr.innerHTML = '<td><input type="text" class="at-ref-key-inp" placeholder="Kriteria / Kategori (misal: Banyuwangi)" style="width:100%"></td>' +
                                   '<td class="at-val-cell">' + createValInputHTML(currentType, "") + '</td>' +
                                   '<td style="text-align:right"><button type="button" class="button button-small at-del-item-btn" style="color:#d63638">🗑️</button></td>';
                    tbody.appendChild(tr);
                    return;
                }

                var importQChoicesBtn = t.closest("#at_import_q_choices_btn");
                if(importQChoicesBtn){
                    var qselect = document.getElementById("at_ref_qselect");
                    if(qselect) populateRowsForQuestion(qselect.value);
                    return;
                }

                var addRefBtn = t.closest("#at_add_ref_btn");
                if(addRefBtn){
                    var title = prompt("Nama tabel acuan baru:");
                    if(!title || !title.trim()) return;
                    var qselect = document.getElementById("at_ref_qselect");
                    var targetQ = qselect ? qselect.value : "T4";
                    var fd = new FormData();
                    fd.append("action", "akurasitara_save_ump_table");
                    fd.append("nonce", nonce);
                    fd.append("ref_column_title", title.trim());
                    fd.append("ref_question_code", targetQ);
                    fd.append("is_new", "1");
                    fetch(ajaxUrl, { method: "POST", body: fd }).then(function(){ window.location.reload(); });
                    return;
                }

                var toggleActiveBtn = t.closest(".at-toggle-active-btn");
                if(toggleActiveBtn){
                    var tid = toggleActiveBtn.getAttribute("data-id");
                    var fd = new FormData();
                    fd.append("action", "akurasitara_toggle_ref_table");
                    fd.append("nonce", nonce);
                    fd.append("table_id", tid);
                    fetch(ajaxUrl, { method: "POST", body: fd }).then(function(){ window.location.reload(); });
                    return;
                }

                var enableAllBtn = t.closest(".at-enable-all-btn");
                if(enableAllBtn){
                    var fd = new FormData();
                    fd.append("action", "akurasitara_toggle_ref_table");
                    fd.append("nonce", nonce);
                    fd.append("toggle_action", "enable_all");
                    fetch(ajaxUrl, { method: "POST", body: fd }).then(function(){ window.location.reload(); });
                    return;
                }

                var disableAllBtn = t.closest(".at-disable-all-btn");
                if(disableAllBtn){
                    var fd = new FormData();
                    fd.append("action", "akurasitara_toggle_ref_table");
                    fd.append("nonce", nonce);
                    fd.append("toggle_action", "disable_all");
                    fetch(ajaxUrl, { method: "POST", body: fd }).then(function(){ window.location.reload(); });
                    return;
                }

                var switchEditBtn = t.closest(".at-switch-editing-btn");
                if(switchEditBtn){
                    var id = switchEditBtn.getAttribute("data-id");
                    var fd = new FormData();
                    fd.append("action", "akurasitara_switch_ref_table");
                    fd.append("nonce", nonce);
                    fd.append("table_id", id);
                    fetch(ajaxUrl, { method: "POST", body: fd }).then(function(){ window.location.reload(); });
                    return;
                }

                var delRefBtn = t.closest(".at-del-ref-btn");
                if(delRefBtn){
                    var id = delRefBtn.getAttribute("data-id");
                    if(confirm("Hapus Tabel Acuan ini secara permanen?")){
                        var fd = new FormData();
                        fd.append("action", "akurasitara_delete_ref_table");
                        fd.append("nonce", nonce);
                        fd.append("table_id", id);
                        fetch(ajaxUrl, { method: "POST", body: fd }).then(function(){ window.location.reload(); });
                    }
                    return;
                }

                var saveRefBtn = t.closest("#at_save_ref_btn");
                if(saveRefBtn){
                    captureCurrentTableValues();
                    var rows = document.querySelectorAll("#at_ref_items_tbody tr.at-ref-row");
                    var items = [];
                    rows.forEach(function(tr){
                        var kInp = tr.querySelector(".at-ref-key-inp");
                        var vInp = tr.querySelector(".at-ref-val-inp");
                        if(kInp && vInp){
                            var k = kInp.value.trim();
                            var v = vInp.value.trim();
                            if(k) items.push({ key: k, val: v });
                        }
                    });
                    var editingId = "<?php echo esc_js($editing_id); ?>";
                    var fd = new FormData();
                    fd.append("action", "akurasitara_save_ump_table");
                    fd.append("nonce", nonce);
                    fd.append("table_id", editingId);
                    fd.append("ref_column_title", document.getElementById("at_ref_title").value.trim());
                    fd.append("ref_question_code", document.getElementById("at_ref_qselect").value);
                    fd.append("data_type", document.getElementById("at_ref_datatype").value);
                    fd.append("ump_items", JSON.stringify(items));
                    fetch(ajaxUrl, { method: "POST", body: fd }).then(function(r){ return r.json(); }).then(function(res){
                        alert(res.data && res.data.message ? res.data.message : "Tabel Acuan Berhasil Disimpan.");
                        window.location.reload();
                    });
                    return;
                }
            });
        })();
        </script>
        <?php
    }
}

new AkurasiTara_Ext_Reports_DSL();

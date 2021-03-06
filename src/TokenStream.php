<?php declare(strict_types=1);

namespace Yay;

use
    InvalidArgumentException
;

/*
macro ·unsafe { $this->jump(···args) } >> { ($this->current = ···args) }

macro ·unsafe { $this->current() } >>  {
    ($this->current ? $this->current->token : null)
}

macro ·unsafe { $this->step() } >> {
    {
        if (null !== $this->current)
            $this->current = $this->current->next;
        else
            $this->current = $this->first;
    }
}

macro ·unsafe { $this->skip(···args) } >> {
    while (null !== ($t = $this->current()) && $t->is(···args)) $this->step();
}

macro ·unsafe { T_VARIABLE·subject->isEmpty() } >> {
    (null === T_VARIABLE·subject->first && null === T_VARIABLE·subject->last)
}
*/

class TokenStream {

    const
        SKIPPABLE = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]
    ;

    protected
        $first,
        $current,
        $last
    ;

    private function __construct() {}

    function __toString() : string {
        $tokens = [];
        $node = $this->first;
        while($node) {
            $tokens[] = $node->token;
            $node = $node->next;
        }

        return implode('', $tokens);
    }

    function __clone() {
        $node = $this->first;
        $first = $last = new Node(clone $node->token);

        while($node = $node->next) {
            $last->next = new Node(clone $node->token);
            $last->next->previous = $last;
            $last = $last->next;
        }

        $this->first = $first;
        $this->last = $last;
        $this->reset();
    }

    function each(callable $callback) {
        $node = $this->first;
        while($node) {
            $callback($node->token);
            $node = $node->next;
        }
    }

    function index() /* : Node|null */ { return $this->current; }

    function jump($node) /* : void */ { $this->current = $node; }

    function reset() /* : void */ { $this->jump($this->first); }

    function current() /* : Token|null */ {
        return $this->current ? $this->current->token : null;
    }

    function step() /* : Token|null */ {
        if (null !== $this->current)
            $this->current = $this->current->next;
        else
            $this->current = $this->first;

        return $this->current();
    }

    function back() /* : Token|null */ {
        if (null !== $this->current)
            $this->current = $this->current->previous;
        else
            $this->current = $this->last;

        return $this->current();
    }

    function skip(int ...$types) /* : Token|null */ {
        while (null !== ($t = $this->current()) && $t->is(...$types)) $this->step();

        return $this->current();
    }

    function unskip(int ...$types) /* : Token|null */ {
        while (null !== ($t = $this->back()) && $t->is(...$types));
        $this->step();

        return $this->current();
    }

    function next() /* : Token|null */ {
        $this->step();
        $this->skip(...self::SKIPPABLE);

        return $this->current();
    }

    function last() : Token {
        return $this->last->token;
    }

    function first() : Token {
        return $this->first->token;
    }

    function trim() {
        while (null !== $this->first && $this->first->token->is(T_WHITESPACE)) $this->shift();
        while (null !== $this->last && $this->last->token->is(T_WHITESPACE)) $this->pop();
    }

    function extract(Node $from, Node $to = null) {
        if (null === $from->previous) {
            $from->previous = new Node(new Token(T_WHITESPACE, '', $from->token->line()));
            $from->previous->next = $from;
            $this->first = $from->previous;
        }

        $this->jump($from->previous);

        while ($from !== $to) {
            if (null === $from->previous)
               $this->first = $from->next;
           else
               $from->previous->next = $from->next;

           if (null === $from->next)
               $this->last = $from->previous;
           else
               $from->next->previous = $from->previous;

            $from = $from->next;
        }
    }

    function inject(self $tstream) {
        if (null === $tstream->first && null === $tstream->last) return;

        if (! $this->isEmpty()){
            if (null !== $this->current) {
                $next = $this->current->next;
                $this->current->next = $tstream->first;
                $tstream->first->previous = $this->current;
                if (null !== $next) {
                    $tstream->last->next = $next;
                    $next->previous = $tstream->last;
                }
                else {
                    $this->last = $tstream->last;
                }
            }
            else {
                $this->first->previous = $tstream->last;
                $tstream->last->next = $this->first;
                $this->first = $tstream->first;
            }
        }
        else {
            $this->first = $tstream->first;
            $this->last = $tstream->last;
            $this->current = null;
        }
    }

    function push(Token $token) {
        $node = new Node($token);

        if (null !== $this->last) {
            $node->previous = $this->last;
            $this->last->next = $node;
            $this->last = $this->last->next;
        }
        else $this->current = $this->first = $this->last = $node;
    }

    function shift() {
        if (null === $this->first)
            throw new YayException("Empty token stream.");

        $this->first = $this->first->next;

        if (null !== $this->first)
            $this->first->previous = null;
        else
            $this->last = null;
    }

    function isEmpty() : bool {
        return (null === $this->first && null === $this->last);
    }

    private function pop() {
        $this->last = $this->last->previous;

        if (null !== $this->last)
            $this->last->next = null;
        else
            $this->first = null;
    }

    static function fromSource(string $source) : self {
        $line = 0;
        $tokens = token_get_all($source);

        foreach ($tokens as $i => $token) // normalize line numbers
            if(is_array($token))
                $line = $token[2];
            else
                $tokens[$i] = [$token, $token, $line];

        return self::fromSequence(...$tokens);
    }

    static function fromSourceWithoutOpenTag(string $source) : self {
        $ts = self::fromSource('<?php ' . $source);
        $ts->shift();

        return $ts;
    }

    static function fromSequence(...$tokens) : self {
        foreach ($tokens as $i => $t)
            $tokens[$i] = ($t instanceof Token) ? clone $t : new Token(...$t);

        return self::fromSlice($tokens);
    }

    static function fromSlice(array $tokens) : self {
        if (! $tokens)
            throw new InvalidArgumentException("Empty token slice.");

        $ts = self::fromEmpty();
        foreach ($tokens as $token) $ts->push($token);

        return $ts;
    }

    static function fromEmpty() : self { return new self; }
}

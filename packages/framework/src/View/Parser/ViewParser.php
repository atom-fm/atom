<?php

declare(strict_types=1);

namespace Atom\View\Parser;

use Atom\View\Ast\AttributeNode;
use Atom\View\Ast\ElementNode;
use Atom\View\Ast\ExpressionNode;
use Atom\View\Ast\FragmentNode;
use Atom\View\Ast\ForEachNode;
use Atom\View\Ast\IfBranchNode;
use Atom\View\Ast\IfNode;
use Atom\View\Ast\RawTextNode;
use Atom\View\Ast\TemplateNode;
use Atom\View\Ast\TextNode;
use Atom\View\Ast\ViewNodeInterface;
use Atom\View\Parser\Token\ViewToken;
use Atom\View\Parser\Token\ViewTokenType;

final class ViewParser
{
    /** @var ViewToken[] */
    private array $tokens = [];
    private int $position = 0;

    public function __construct(private readonly ViewTokenizer $tokenizer = new ViewTokenizer())
    {
    }

    public function parse(string $source): TemplateNode
    {
        return $this->parseTokens($this->tokenizer->tokenize($source));
    }

    /**
     * @param ViewToken[] $tokens
     */
    public function parseTokens(array $tokens): TemplateNode
    {
        $this->tokens = array_values($tokens);
        $this->position = 0;

        return new TemplateNode($this->parseChildren());
    }

    /**
     * @return ViewNodeInterface[]
     * @param string[] $untilDirectives
     */
    private function parseChildren(?string $untilTag = null, array $untilDirectives = []): array
    {
        $nodes = [];

        while (!$this->eof()) {
            $token = $this->current();

            if ($token->type === ViewTokenType::EndTag) {
                if ($untilTag === null) {
                    throw new ViewParseException("Unexpected closing tag '{$token->value}'.");
                }

                if (strcasecmp($token->value, $untilTag) !== 0) {
                    throw new ViewParseException("Expected closing tag '{$untilTag}', got '{$token->value}'.");
                }

                $this->position++;
                return $this->groupConditionalAttributes($nodes);
            }

            if ($token->type === ViewTokenType::Directive && in_array(strtolower($token->value), $untilDirectives, true)) {
                return $this->groupConditionalAttributes($nodes);
            }

            if ($token->type === ViewTokenType::Comment) {
                $this->position++;
                continue;
            }

            $nodes[] = $this->parseNode();
        }

        if ($untilTag !== null) {
            throw new ViewParseException("Missing closing tag for '{$untilTag}'.");
        }

        return $this->groupConditionalAttributes($nodes);
    }

    /**
     * @param ViewNodeInterface[] $nodes
     * @return ViewNodeInterface[]
     */
    private function groupConditionalAttributes(array $nodes): array
    {
        $result = [];
        $active = null;
        $hasElse = false;
        foreach ($nodes as $node) {
            $directive = null;
            $attributes = [];
            if ($node instanceof ElementNode) {
                foreach ($node->attributes as $attribute) {
                    if ($attribute instanceof AttributeNode && in_array($attribute->name, [":if", ":else-if", ":else", ":foreach", ":empty"], true)) {
                        if ($directive !== null) {
                            throw new ViewParseException("An element may have only one conditional attribute.");
                        }
                        $directive = $attribute;
                    } else {
                        $attributes[] = $attribute;
                    }
                }
            }
            if ($directive === null) {
                $result[] = $node;
                if (!$node instanceof TextNode || trim($node->text) !== "") {
                    $active = null;
                }
                continue;
            }
            if (!$node instanceof ElementNode) {
                continue;
            }
            $element = new ElementNode($node->name, $attributes, $node->children, $node->selfClosing);
            if (!in_array($directive->name, [":else", ":empty"], true) && (!$directive->value instanceof ExpressionNode || trim($directive->value->expression) === "")) {
                throw new ViewParseException("Conditional attribute requires an expression.");
            }
            if ($directive->name === ":foreach") {
                [$source, $key, $value] = $this->parseForEachArgument($directive->value->expression);
                $result[] = new ForEachNode($source, $value, $key, [$element]);
                $active = array_key_last($result);
                $hasElse = false;
                continue;
            }
            if ($directive->name === ":empty") {
                if ($active === null || $hasElse || !$result[$active] instanceof ForEachNode || $directive->value !== true) {
                    throw new ViewParseException("Bare ':empty' requires a preceding :foreach sibling.");
                }
                while (array_key_last($result) !== $active) {
                    array_pop($result);
                }
                $loop = $result[$active];
                if (!$loop instanceof ForEachNode) {
                    throw new ViewParseException("Expected a loop before :empty.");
                }
                $result[$active] = new ForEachNode($loop->source, $loop->value, $loop->key, $loop->children, [$element]);
                $hasElse = true;
                continue;
            }
            if ($directive->name === ":if") {
                $result[] = new IfNode([new IfBranchNode($directive->value->expression, [$element])]);
                $active = array_key_last($result);
                $hasElse = false;
                continue;
            }
            if ($active === null || $hasElse || !$result[$active] instanceof IfNode) {
                throw new ViewParseException("Conditional branch requires a preceding :if or :else-if sibling.");
            }
            while (array_key_last($result) !== $active) {
                array_pop($result);
            }
            $previous = $result[$active];
            assert($previous instanceof IfNode);
            if ($directive->name === ":else") {
                if ($directive->value !== true) {
                    throw new ViewParseException("Attribute ':else' does not accept a value.");
                }
                $result[$active] = new IfNode($previous->branches, [$element]);
                $hasElse = true;
            } else {
                $result[$active] = new IfNode([...$previous->branches, new IfBranchNode($directive->value->expression, [$element])]);
            }
        }
        return $result;
    }

    private function parseNode(): ViewNodeInterface
    {
        $token = $this->current();

        return match ($token->type) {
            ViewTokenType::Text => $this->parseText($token),
            ViewTokenType::Expression => $this->parseExpression($token),
            ViewTokenType::RawText => $this->parseRawText($token),
            ViewTokenType::StartTag => $this->parseElement($token),
            ViewTokenType::Directive => $this->parseDirective($token),
            default => throw new ViewParseException("Unexpected token {$token->type->name}."),
        };
    }

    private function parseText(ViewToken $token): TextNode
    {
        $this->position++;
        return new TextNode($token->value);
    }

    private function parseExpression(ViewToken $token): ExpressionNode
    {
        $this->position++;
        return new ExpressionNode($token->value);
    }

    private function parseRawText(ViewToken $token): RawTextNode
    {
        $this->position++;
        return new RawTextNode($token->value);
    }

    private function parseElement(ViewToken $token): ElementNode
    {
        $this->position++;

        if ($token->selfClosing) {
            return new ElementNode($token->value, $token->attributes, selfClosing: true);
        }

        $children = $this->parseChildren($token->value);

        return new ElementNode(
            $token->value,
            $token->attributes,
            $this->parseFragments($token->value, $children)
        );
    }

    /**
     * @param ViewNodeInterface[] $children
     * @return ViewNodeInterface[]
     */
    private function parseFragments(string $owner, array $children): array
    {
        foreach ($children as $index => $child) {
            if ($child instanceof ElementNode && $this->isFragmentElement($owner, $child)) {
                $children[$index] = $this->toFragment($owner, $child);
            } else {
                $this->rejectConditionalFragment($owner, $child);
            }
        }

        return $children;
    }

    private function rejectConditionalFragment(string $owner, ViewNodeInterface $node, bool $insideDirective = false): void
    {
        if ($insideDirective && $node instanceof ElementNode && $this->isFragmentElement($owner, $node)) {
            throw new ViewParseException("Named fragment <{$node->name}> must be a direct child of <{$owner}>. Move the directive inside the fragment.");
        }

        if ($node instanceof IfNode) {
            foreach ($node->branches as $branch) {
                foreach ($branch->children as $child) {
                    $this->rejectConditionalFragment($owner, $child, true);
                }
            }
            foreach ($node->else as $child) {
                $this->rejectConditionalFragment($owner, $child, true);
            }
        } elseif ($node instanceof ForEachNode) {
            foreach ([...$node->children, ...$node->empty] as $child) {
                $this->rejectConditionalFragment($owner, $child, true);
            }
        }
    }

    private function isFragmentElement(string $owner, ElementNode $node): bool
    {
        return $node->name === "Fragment"
            || (str_starts_with($node->name, $owner . ".")
                && strlen($node->name) > strlen($owner) + 1);
    }

    private function toFragment(string $owner, ElementNode $node): FragmentNode
    {
        if ($node->name === "Fragment") {
            $attribute = $node->attributes[0] ?? null;
            if (count($node->attributes) !== 1 || !$attribute instanceof AttributeNode
                || $attribute->name !== "name" || !is_string($attribute->value)
                || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $attribute->value) !== 1
            ) {
                throw new ViewParseException("A named Fragment requires one literal name attribute.");
            }

            return new FragmentNode($owner, $attribute->value, [], $node->children, $node->selfClosing);
        }

        return new FragmentNode(
            $owner,
            substr($node->name, strlen($owner) + 1),
            $node->attributes,
            $node->children,
            $node->selfClosing
        );
    }

    private function parseDirective(ViewToken $token): ViewNodeInterface
    {
        return match (strtolower($token->value)) {
            "if" => $this->parseIf($token),
            "foreach" => $this->parseForEach($token),
            "elseif", "else", "endif", "empty", "endforeach" => throw new ViewParseException("Unexpected directive '@{$token->value}'."),
            default => throw new ViewParseException("Unknown directive '@{$token->value}'."),
        };
    }

    private function parseIf(ViewToken $token): IfNode
    {
        if ($token->argument === null || $token->argument === "") {
            throw new ViewParseException("Directive '@if' requires a condition.");
        }

        $branches = [];
        $this->position++;
        $branches[] = new IfBranchNode(
            $token->argument,
            $this->parseChildren(untilDirectives: ["elseif", "else", "endif"])
        );
        $else = [];

        while (!$this->eof() && $this->isCurrentDirective("elseif")) {
            $elseif = $this->current();
            if ($elseif->argument === null || $elseif->argument === "") {
                throw new ViewParseException("Directive '@elseif' requires a condition.");
            }

            $this->position++;
            $branches[] = new IfBranchNode(
                $elseif->argument,
                $this->parseChildren(untilDirectives: ["elseif", "else", "endif"])
            );
        }

        if (!$this->eof() && $this->isCurrentDirective("else")) {
            $this->position++;
            $else = $this->parseChildren(untilDirectives: ["endif"]);
        }

        if ($this->eof() || !$this->isCurrentDirective("endif")) {
            throw new ViewParseException("Missing '@endif' for '@if'.");
        }

        $this->position++;

        return new IfNode($branches, $else);
    }

    private function parseForEach(ViewToken $token): ForEachNode
    {
        if ($token->argument === null || $token->argument === "") {
            throw new ViewParseException("Directive '@foreach' requires an expression.");
        }

        [$source, $key, $value] = $this->parseForEachArgument($token->argument);

        $this->position++;
        $children = $this->parseChildren(untilDirectives: ["empty", "endforeach"]);
        $empty = [];
        if (!$this->eof() && $this->isCurrentDirective("empty")) {
            $this->position++;
            $empty = $this->parseChildren(untilDirectives: ["endforeach"]);
        }

        if ($this->eof() || !$this->isCurrentDirective("endforeach")) {
            throw new ViewParseException("Missing '@endforeach' for '@foreach'.");
        }

        $this->position++;

        return new ForEachNode($source, $value, $key, $children, $empty);
    }

    /**
     * @return array{0: string, 1: ?string, 2: string}
     */
    private function parseForEachArgument(string $argument): array
    {
        $parts = preg_split('/\s+as\s+/i', $argument, 2);
        if ($parts === false || count($parts) !== 2) {
            throw new ViewParseException("Directive '@foreach' must use 'source as value'.");
        }

        $source = trim($parts[0]);
        $target = trim($parts[1]);

        if ($source === "" || $target === "") {
            throw new ViewParseException("Directive '@foreach' must use 'source as value'.");
        }

        if (str_contains($target, "=>")) {
            [$key, $value] = array_map("trim", explode("=>", $target, 2));

            if (!$this->isVariableName($key) || !$this->isVariableName($value)) {
                throw new ViewParseException("Directive '@foreach' key and value must be variable names.");
            }

            return [$source, $key, $value];
        }

        if (!$this->isVariableName($target)) {
            throw new ViewParseException("Directive '@foreach' value must be a variable name.");
        }

        return [$source, null, $target];
    }

    private function isVariableName(string $value): bool
    {
        return preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
    }

    private function isCurrentDirective(string $name): bool
    {
        if ($this->eof()) {
            return false;
        }

        $token = $this->current();

        return $token->type === ViewTokenType::Directive
            && strcasecmp($token->value, $name) === 0;
    }

    private function current(): ViewToken
    {
        return $this->tokens[$this->position] ?? throw new ViewParseException("Unexpected end of template.");
    }

    private function eof(): bool
    {
        return $this->position >= count($this->tokens);
    }
}

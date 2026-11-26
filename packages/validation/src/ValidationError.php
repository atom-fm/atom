<?php

declare(strict_types=1);

namespace Atom\Validation;

use JsonSerializable;

final readonly class ValidationError implements JsonSerializable
{
    public string $message;
    private string $messageTemplate;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public string $field,
        string $message,
        public string $code,
        public array $parameters = []
    ) {
        $this->messageTemplate = $message;
        $replacements = [];
        foreach ($parameters as $name => $value) {
            if (is_scalar($value)) {
                $replacements["{{$name}}"] = (string) $value;
            }
        }
        $this->message = strtr($message, $replacements);
    }

    public function forField(string $field): self
    {
        return new self($field, $this->messageTemplate, $this->code, $this->parameters);
    }

    /**
     * @return array{field: string, message: string, code: string, parameters: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return [
            "field" => $this->field,
            "message" => $this->message,
            "code" => $this->code,
            "parameters" => $this->parameters,
        ];
    }
}


<?php

declare(strict_types=1);

namespace Atom\View\Render;

use Throwable;

final readonly class PhpExpressionEvaluator implements ExpressionEvaluatorInterface
{
    public function evaluate(string $expression, ViewContext $context): mixed
    {
        try {
            $variables = $context->variables();
            $thisObject = $variables["this"] ?? null;
            unset($variables["this"]);

            $evaluate = function (array $variables, string $expression): mixed {
                // Remove the parameters from scope unless the template supplies those names.
                return eval(
                    'unset($expression); extract($variables, EXTR_OVERWRITE); '
                    . (array_key_exists("variables", $variables) ? "" : 'unset($variables); ')
                    . "return {$expression};"
                );
            };

            if (is_object($thisObject)) {
                $evaluate = $evaluate->bindTo($thisObject, $thisObject::class);
            }

            return $evaluate($variables, $expression);
        } catch (Throwable $exception) {
            throw new ViewRenderException(
                "Failed to evaluate expression '{$expression}': {$exception->getMessage()}",
                previous: $exception
            );
        }
    }
}

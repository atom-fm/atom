<?php

declare(strict_types=1);

namespace Atom\Di\Exception;

/** A resolution failure that must propagate unchanged, even for nullable parameters. */
abstract class UnrecoverableDependencyException extends DependencyResolutionException
{
}

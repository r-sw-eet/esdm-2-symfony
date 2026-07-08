<?php

declare(strict_types=1);

namespace Esdm\Generator\Adapter;

use Esdm\Generator\Model\Model;

/**
 * A generation target: one framework + database + event-sourcing library combo
 * (e.g. symfony-patchlevel-postgres). Adapters are the *only* place that knows
 * about a concrete stack; everything upstream is framework-agnostic.
 */
interface Adapter
{
    /** Stable target id selected on the CLI with --target. */
    public function name(): string;

    public function description(): string;

    /** Short stack slug — the subdirectory each target writes into under `generated/`. */
    public function slug(): string;

    /** @param array<string, mixed> $options */
    public function generate(Model $model, array $options): GeneratedProject;
}

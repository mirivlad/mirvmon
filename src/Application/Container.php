<?php

declare(strict_types=1);

namespace App\Application;

use Closure;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class Container implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $definitions = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    public function set(string $id, mixed $value): void
    {
        $this->definitions[$id] = $value;
        unset($this->resolved[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        if (!$this->has($id)) {
            throw new RuntimeException(sprintf('Container entry "%s" was not found.', $id));
        }

        $definition = $this->definitions[$id];
        $this->resolved[$id] = $definition instanceof Closure
            ? $definition($this)
            : $definition;

        return $this->resolved[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->definitions);
    }
}

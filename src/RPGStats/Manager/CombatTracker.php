<?php

namespace RPGStats\Manager;

/**
 * Marca quem levou dano recentemente, para segurar a regeneração durante a luta.
 *
 * É estado de runtime puro: nada disso vai para o disco.
 */
class CombatTracker
{
    /** @var array<string, int> nome minúsculo => tick do último dano */
    private array $lastDamage = [];

    public function mark(string $name, int $tick): void
    {
        $this->lastDamage[strtolower($name)] = $tick;
    }

    public function isInCombat(string $name, int $currentTick, int $delayTicks): bool
    {
        if ($delayTicks <= 0) {
            return false;
        }

        $last = $this->lastDamage[strtolower($name)] ?? null;

        return $last !== null && ($currentTick - $last) < $delayTicks;
    }

    public function forget(string $name): void
    {
        unset($this->lastDamage[strtolower($name)]);
    }
}

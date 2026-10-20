<?php

namespace RPGStats\Modifier;

/**
 * Bônus vindo de uma classe, de um rank ou de um título.
 *
 * Os três funcionam igual: somam pontos em atributos e multiplicam valores derivados.
 * A única diferença é a cardinalidade — classe e rank são um por jogador; títulos são
 * acumuláveis, e todos os que o jogador tem valem ao mesmo tempo.
 */
class Modifier
{
    /** Chaves aceitas em "multipliers". */
    public const MULTIPLIERS = [
        "health",
        "damage",
        "defense",
        "speed",
        "mana_max",
        "mana_regen",
        "health_regen",
        "xp_gain",
        "atk_speed",
    ];

    public function __construct(
        private string $id,
        public string $name,
        public string $description,
        /** @var array<string, int> */
        public array $attributes,
        /** @var array<string, float> */
        public array $multipliers,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getAttributeBonus(string $stat): int
    {
        return $this->attributes[$stat] ?? 0;
    }

    public function getMultiplier(string $key): float
    {
        return $this->multipliers[$key] ?? 1.0;
    }

    /**
     * Resumo dos bônus para mostrar no chat.
     *
     * @return string[]
     */
    public function describeBonuses(): array
    {
        $lines = [];

        foreach ($this->attributes as $stat => $value) {
            if ($value === 0) {
                continue;
            }
            $signal = $value > 0 ? "§a+" : "§c";
            $lines[] = "§7" . strtoupper($stat) . ": {$signal}{$value}";
        }

        foreach ($this->multipliers as $key => $value) {
            if ($value === 1.0) {
                continue;
            }
            $percent = (int) round(($value - 1) * 100);
            $signal = $percent > 0 ? "§a+" : "§c";
            $lines[] = "§7" . $key . ": {$signal}{$percent}%";
        }

        return $lines;
    }
}

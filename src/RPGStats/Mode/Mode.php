<?php

namespace RPGStats\Mode;

/**
 * Um modo: uma transformação que o jogador liga, paga em mana e desliga.
 *
 * A diferença para um título é o custo contínuo. Título é bônus permanente; modo é bônus
 * alugado — enquanto houver mana no poço, e não mais que isso.
 *
 * `group` dá exclusividade: dois modos do mesmo grupo não ficam ligados juntos. É o que
 * impede empilhar três transformações e somar todos os bônus.
 */
class Mode
{
    public function __construct(
        private string $id,
        public string $name,
        public string $description,
        public string $group,
        public string $permission,
        public int $requiredLevel,
        /** @var array<string, int> bônus somados aos atributos */
        public array $attributes,
        /** @var array<string, float> multiplicadores compostos */
        public array $multipliers,
        /** Mana consumida a cada ciclo do ModeTickTask. */
        public int $manaPerCycle,
        /** @var array<int, array{effect: string, amplifier: int}> efeitos de poção aplicados */
        public array $effects,
        /** Segundos até desligar sozinho; 0 = dura enquanto houver mana. */
        public int $duration,
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

    /** @return string[] resumo dos bônus, para o /modo info */
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
            $lines[] = "§7{$key}: {$signal}{$percent}%";
        }

        return $lines;
    }
}

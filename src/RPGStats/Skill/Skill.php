<?php

namespace RPGStats\Skill;

/**
 * Uma habilidade upável descrita no habilidades.yml.
 *
 * Os números que mudam com o nível — custo de mana, recarga e potência — são listas
 * indexadas por nível, não fórmulas. Isso deixa a curva visível no arquivo e permite um
 * salto de balanceamento no nível 5 sem inventar uma equação para isso.
 *
 * Quando a lista é mais curta que o nível pedido, vale o último valor dela. É o que faz
 * `custo-mana: [10]` significar "10 em todos os níveis" sem repetir dez vezes.
 */
class Skill
{
    /** Tipos que o SkillManager sabe executar. */
    public const TYPE_DASH = "dash";
    public const TYPE_TELEPORT = "teleport";
    public const TYPE_CRITICO = "critico";

    public const TYPES = [self::TYPE_DASH, self::TYPE_TELEPORT, self::TYPE_CRITICO];

    public function __construct(
        private string $id,
        public string $name,
        public string $description,
        public string $type,
        public int $maxLevel,
        /** @var int[] custo em mana, por nível */
        public array $manaCost,
        /** @var float[] recarga em segundos, por nível */
        public array $cooldown,
        /** @var float[] potência: força do impulso, distância do teleporte, % de crítico */
        public array $power,
        /** @var int[] custo em pontos para subir PARA aquele nível */
        public array $upgradeCost,
        public int $requiredLevel,
        public string $permission,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Valor da lista no nível pedido, repetindo o último quando a lista acaba.
     *
     * @param array<int, int|float> $values
     */
    private function at(array $values, int $level): int|float
    {
        if ($values === []) {
            return 0;
        }

        $index = max(1, $level) - 1;

        return $values[$index] ?? $values[array_key_last($values)];
    }

    public function getManaCost(int $level): int
    {
        return max(0, (int) $this->at($this->manaCost, $level));
    }

    /** Recarga em ticks, que é a unidade com que o servidor conta o tempo. */
    public function getCooldownTicks(int $level): int
    {
        return max(0, (int) round((float) $this->at($this->cooldown, $level) * 20));
    }

    public function getCooldownSeconds(int $level): float
    {
        return max(0.0, (float) $this->at($this->cooldown, $level));
    }

    public function getPower(int $level): float
    {
        return (float) $this->at($this->power, $level);
    }

    /** Pontos para ir do nível atual para o próximo. */
    public function getUpgradeCost(int $targetLevel): int
    {
        return max(0, (int) $this->at($this->upgradeCost, $targetLevel));
    }
}

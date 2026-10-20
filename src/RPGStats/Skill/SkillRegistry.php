<?php

namespace RPGStats\Skill;

use pocketmine\utils\Config;
use RPGStats\RPGStatsPlugin;

/** Carrega o habilidades.yml e serve as habilidades por id. */
class SkillRegistry
{
    /** @var array<string, Skill> */
    private array $skills = [];

    public function __construct(private RPGStatsPlugin $plugin)
    {
        $this->load();
    }

    private function load(): void
    {
        $config = new Config($this->plugin->getDataFolder() . "habilidades.yml", Config::YAML);
        $logger = $this->plugin->getLogger();

        foreach ($config->getAll() as $rawId => $entry) {
            $id = strtolower((string) $rawId);

            if (!is_array($entry)) {
                $logger->warning("[habilidades.yml] '{$id}' ignorada: o formato esperado é uma lista de chaves.");
                continue;
            }

            $type = strtolower((string) ($entry["tipo"] ?? ""));

            // Sem um tipo que o SkillManager saiba executar, a habilidade não faria nada
            // no jogo — melhor recusar aqui do que aparecer no menu e não responder.
            if (!in_array($type, Skill::TYPES, true)) {
                $logger->warning(
                    "[habilidades.yml] '{$id}' tem tipo '{$type}', que não existe. "
                    . "Use um destes: " . implode(", ", Skill::TYPES) . "."
                );
                continue;
            }

            $maxLevel = max(1, (int) ($entry["nivel-maximo"] ?? 1));

            $this->skills[$id] = new Skill(
                $id,
                (string) ($entry["nome"] ?? ucfirst($id)),
                (string) ($entry["descricao"] ?? ""),
                $type,
                $maxLevel,
                $this->numbers($entry["custo-mana"] ?? []),
                $this->numbers($entry["recarga"] ?? []),
                $this->numbers($entry["potencia"] ?? []),
                $this->numbers($entry["custo-pontos"] ?? []),
                max(1, (int) ($entry["nivel-requerido"] ?? 1)),
                (string) ($entry["permissao"] ?? ""),
            );
        }

        $logger->info(count($this->skills) . " habilidade(s) carregada(s).");
    }

    /**
     * Aceita tanto um número solto quanto uma lista deles.
     *
     * `custo-mana: 10` e `custo-mana: [10]` querem dizer a mesma coisa, e escrever o
     * escalar é o caminho natural para quem tem um custo fixo.
     *
     * @return array<int, int|float>
     */
    private function numbers(mixed $raw): array
    {
        $values = [];

        foreach ((is_array($raw) ? $raw : [$raw]) as $value) {
            if (is_numeric($value)) {
                $values[] = $value + 0;
            }
        }

        return $values;
    }

    public function get(string $id): ?Skill
    {
        return $this->skills[strtolower($id)] ?? null;
    }

    public function exists(string $id): bool
    {
        return isset($this->skills[strtolower($id)]);
    }

    /** @return array<string, Skill> */
    public function all(): array
    {
        return $this->skills;
    }
}
